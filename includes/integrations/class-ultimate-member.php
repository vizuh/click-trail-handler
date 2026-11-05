<?php
/**
 * Ultimate Member Integration
 *
 * Stores the consented attribution payload on a user registered through an Ultimate Member
 * registration form and dispatches one server-side `sign_up` event for it.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Integrations;

use CLICUTCL\Core\Attribution_Provider;
use CLICUTCL\Server_Side\Consent;
use CLICUTCL\Server_Side\Dispatcher;
use CLICUTCL\Server_Side\Event;
use CLICUTCL\Tracking\Claim_Lock;
use CLICUTCL\Tracking\Identity_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ultimate_Member
 *
 * Registration forms are server-rendered and post to the page itself, so the attribution cookie
 * travels with the registration request. Nothing is read from `$_POST`: no hidden fields, no
 * client-supplied attribution, and the submitted password is never touched.
 */
class Ultimate_Member {

	/**
	 * Prefix of every user meta key this integration writes (and the privacy handler manages).
	 */
	public const META_PREFIX = '_clicutcl_';

	/**
	 * User meta marker set once the `sign_up` event was handed to the dispatcher.
	 */
	public const SENT_META_KEY = '_clicutcl_um_signup_sent';

	/**
	 * Prefix for the per-user dispatch claim (object cache key or option name).
	 */
	public const LOCK_PREFIX = 'clicutcl_um_signup_lock_';

	/**
	 * Prefix of the deterministic event ID.
	 */
	public const EVENT_ID_PREFIX = 'um_registration_';

	/**
	 * Platform label carried by the event.
	 */
	public const PLATFORM = 'ultimate-member';

	/**
	 * Seconds a claim may be held before it is considered abandoned.
	 */
	private const LOCK_TTL = 30;

	/**
	 * Whether an Ultimate Member registration form submit is being processed in this request.
	 *
	 * @var bool
	 */
	private $registration_in_flight = false;

	/**
	 * Whether Ultimate Member is active.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return defined( 'UM_VERSION' ) && function_exists( 'UM' );
	}

	/**
	 * Deterministic event ID for one registered user.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function event_id( int $user_id ): string {
		return self::EVENT_ID_PREFIX . $user_id;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		// `UM()->form()->form_init()` fires `um_submit_form_{mode}` after its validation hook; UM's own handler
		// (priority 10) then inserts the user, so priority 5 runs before `wp_insert_user()` and core `user_register`.
		add_action( 'um_submit_form_register', array( $this, 'mark_registration_in_flight' ), 5, 0 );
		// Fires after UM stored the submitted details and extra data (`um_after_insert_user()`); priority 10
		// runs before UM's own redirect handler at 100.
		add_action( 'um_registration_complete', array( $this, 'on_registration_complete' ), 10, 3 );
		// Core `user_register` (Events_Logger) fires inside `wp_insert_user()`, before UM's hook above.
		add_filter( 'clicutcl_signup_event_id', array( $this, 'filter_signup_event_id' ), 10, 2 );
	}

	/**
	 * Remember that the current request is an Ultimate Member registration submit.
	 *
	 * @return void
	 */
	public function mark_registration_in_flight(): void {
		$this->registration_in_flight = true;
	}

	/**
	 * Give the core `sign_up` event of an Ultimate Member registration this integration's event ID.
	 *
	 * Precedence: the core `user_register` handler (`Events_Logger::log_signup_event()`) keeps emitting the
	 * browser `sign_up` (the `ct_event_signup` follow-up cookie) for every registration, including UM's.
	 * For a UM registration it carries the same ID the server-side event below is dispatched with, so the
	 * browser push and the server delivery are one event to a destination that deduplicates on event ID,
	 * and ClickTrail's own dedupe (`Dedup_Store`, event name + ID) sees one.
	 *
	 * @param string $event_id Event ID the core handler generated.
	 * @param int    $user_id  Registered user ID.
	 * @return string
	 */
	public function filter_signup_event_id( $event_id, $user_id = 0 ) {
		if ( $this->registration_in_flight && absint( $user_id ) > 0 ) {
			return self::event_id( absint( $user_id ) );
		}

		return $event_id;
	}

	/**
	 * Handle a completed Ultimate Member registration.
	 *
	 * @param int|\WP_Error $user_id   New user ID.
	 * @param array         $args      Submitted form data (contains the password; never read here).
	 * @param array|null    $form_data UM form data; null when the user was added from wp-admin.
	 * @return void
	 */
	public function on_registration_complete( $user_id, $args = array(), $form_data = null ): void {
		$this->registration_in_flight = false;

		if ( is_wp_error( $user_id ) ) {
			return;
		}

		$user_id = absint( $user_id );
		// UM tells a visitor registration from a wp-admin "Add user" by a null form or is_admin().
		if ( $user_id < 1 || null === $form_data || is_admin() ) {
			return;
		}

		// Registration-time consent is authoritative: same gate as the form adapters' submit-time refresh.
		if ( ! Attribution_Provider::should_populate() || ( Consent::is_required() && ! Consent::marketing_allowed() ) ) {
			return;
		}

		$claim = Claim_Lock::acquire( self::LOCK_PREFIX . $user_id, self::LOCK_TTL );
		if ( '' === $claim ) {
			return;
		}

		try {
			if ( get_user_meta( $user_id, self::SENT_META_KEY, true ) ) {
				return;
			}

			$attribution = $this->store_attribution( $user_id );
			$result      = Dispatcher::dispatch( $this->build_event( $user_id, $attribution, $this->form_id( $form_data ) ) );

			if ( $this->should_mark_sent( $result ) ) {
				update_user_meta( $user_id, self::SENT_META_KEY, 'yes' );
			}
		} finally {
			Claim_Lock::release( self::LOCK_PREFIX . $user_id, $claim );
		}
	}

	/**
	 * Write the allowlisted attribution keys from the request cookie to the user.
	 *
	 * @param int $user_id User ID.
	 * @return array The allowlisted attribution that was stored (flat payload), empty when there was none.
	 */
	private function store_attribution( int $user_id ): array {
		$payload = Attribution_Provider::get_payload();
		if ( empty( $payload ) || ! is_array( $payload ) ) {
			return array();
		}

		$allowed = array_flip( Attribution_Provider::get_field_mapping() );
		$stored  = array();

		foreach ( $payload as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || ! isset( $allowed[ $key ] ) || ! is_scalar( $value ) ) {
				continue;
			}

			$value = in_array( $key, array( 'session_count', 'session_number' ), true )
				? absint( $value )
				: substr( sanitize_text_field( (string) $value ), 0, 255 );
			if ( '' === $value ) {
				continue;
			}

			update_user_meta( $user_id, self::META_PREFIX . $key, $value );
			$stored[ $key ] = $value;
		}

		return $stored;
	}

	/**
	 * Build the `sign_up` event for a new user.
	 *
	 * @param int    $user_id     User ID.
	 * @param array  $attribution Stored attribution (flat).
	 * @param string $form_id     UM form ID.
	 * @return Event
	 */
	private function build_event( int $user_id, array $attribution, string $form_id ): Event {
		$meta = array(
			'site_id'        => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
			'plugin_version' => defined( 'CLICUTCL_VERSION' ) ? CLICUTCL_VERSION : '',
		);

		$session = Attribution_Provider::get_session();
		if ( ! empty( $session['session_id'] ) ) {
			$meta['session_id'] = $session['session_id'];
		}

		$data = array(
			'event_name'  => 'sign_up',
			'event_id'    => self::event_id( $user_id ),
			'timestamp'   => time(),
			'source'      => 'server',
			'form'        => array(
				'platform' => self::PLATFORM,
				'id'       => $form_id,
			),
			'attribution' => $attribution,
			'identity'    => $this->resolve_identity( $user_id ),
			'meta'        => $meta,
		);

		$consent = Consent::get_state();
		if ( ! empty( $consent ) ) {
			$data['consent'] = $consent;
		}

		return new Event( $data );
	}

	/**
	 * Resolve the identity for server-side delivery from the new user and the registering request.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	private function resolve_identity( int $user_id ): array {
		$input = array();
		$user  = get_userdata( $user_id );
		if ( $user && ! empty( $user->user_email ) ) {
			$input['email'] = sanitize_email( (string) $user->user_email );
		}

		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by Identity_Resolver.
			$input['ip'] = wp_unslash( $_SERVER['REMOTE_ADDR'] );
		}
		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Identity_Resolver.
			$input['user_agent'] = wp_unslash( $_SERVER['HTTP_USER_AGENT'] );
		}

		return ( new Identity_Resolver() )->resolve(
			$input,
			array(
				'marketing_allowed' => Consent::marketing_allowed(),
				'include_ip_ua'     => true,
			)
		);
	}

	/**
	 * UM form ID from the form data UM read from its own form post.
	 *
	 * @param mixed $form_data UM form data.
	 * @return string
	 */
	private function form_id( $form_data ): string {
		return is_array( $form_data ) && isset( $form_data['form_id'] ) && is_scalar( $form_data['form_id'] )
			? (string) absint( $form_data['form_id'] )
			: '';
	}

	/**
	 * Whether the dispatch result means the event is handled and must not be sent again.
	 *
	 * A skipped dispatch (server-side delivery off, duplicate) and a queued retry count as handled;
	 * an unqueued failure does not, so a repeat of the hook can still send.
	 *
	 * @param mixed $result Dispatch result.
	 * @return bool
	 */
	private function should_mark_sent( $result ): bool {
		if ( ! is_object( $result ) ) {
			return false;
		}

		if ( ! empty( $result->skipped ) || ! empty( $result->success ) ) {
			return true;
		}

		$meta = isset( $result->meta ) && is_array( $result->meta ) ? $result->meta : array();
		return ! empty( $meta['queued'] );
	}
}
