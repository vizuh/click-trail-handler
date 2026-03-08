<?php
/**
 * Abstract Form Adapter
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Integrations\Forms;

use CLICUTCL\Core\Attribution_Provider;
use CLICUTCL\Server_Side\Consent;
use CLICUTCL\Server_Side\Dispatcher;
use CLICUTCL\Server_Side\Event;
use CLICUTCL\Tracking\Identity_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Abstract_Form_Adapter
 */
abstract class Abstract_Form_Adapter implements Form_Adapter_Interface {

	/**
	 * Field prefix for ClickTrail fields.
	 *
	 * @var string
	 */
	protected $field_prefix = 'ct_';

	/**
	 * Get attribution payload from core provider.
	 *
	 * @return array
	 */
	protected function get_attribution_payload() {
		return Attribution_Provider::get_payload();
	}

	/**
	 * Check if methods should populate.
	 *
	 * @return bool
	 */
	protected function should_populate() {
		return Attribution_Provider::should_populate();
	}

	/**
	 * Log submission to ClickTrail events table.
	 *
	 * @param string $platform       Platform name.
	 * @param mixed  $form_id        Form ID.
	 * @param array  $attribution    Attribution data associated with submission.
	 * @param array  $identity_input Optional raw identity input candidates.
	 * @return void
	 */
	protected function log_submission( $platform, $form_id, $attribution, $identity_input = array() ) {
		global $wpdb;

		if ( empty( $attribution ) ) {
			return; // Don't log empty attribution events? Maybe log them anyway but data is empty.
		}

		$table_name = $wpdb->prefix . 'clicutcl_events';

		$event_id = Event::generate_id( 'form' );
		$identity = $this->resolve_identity_payload( $identity_input );
		
		$event_data = array(
			'event_id'   => $event_id,
			'platform'    => $platform,
			'form_id'     => $form_id,
			'attribution' => $attribution,
		);

		if ( ! empty( $identity ) ) {
			$event_data['identity'] = $identity;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Intentional insert into custom plugin table.
			$table_name,
			array(
				'event_type' => 'form_submission',
				'event_data' => wp_json_encode( $event_data ),
			)
		);

		$context = array(
			'event_id' => $event_id,
			'identity' => $identity,
		);

		$session = Attribution_Provider::get_session();
		if ( ! empty( $session['session_id'] ) ) {
			$context['session_id'] = $session['session_id'];
		}

		Dispatcher::dispatch_form_submission(
			$platform,
			$form_id,
			$attribution,
			$context
		);
	}

	/**
	 * Resolve identity payload for server-side delivery.
	 *
	 * @param array $identity_input Raw submitted form data or candidate identity fields.
	 * @return array
	 */
	protected function resolve_identity_payload( $identity_input = array() ) {
		$identity_input = is_array( $identity_input ) ? $identity_input : array();
		$candidates     = $this->extract_identity_candidates( $identity_input );

		if ( isset( $identity_input['email'] ) && is_scalar( $identity_input['email'] ) ) {
			$email = (string) $identity_input['email'];
			if ( is_email( $email ) ) {
				$candidates['email'] = sanitize_email( $email );
			}
		}

		if ( isset( $identity_input['phone'] ) && is_scalar( $identity_input['phone'] ) && $this->looks_like_phone( $identity_input['phone'] ) ) {
			$candidates['phone'] = sanitize_text_field( (string) $identity_input['phone'] );
		}

		if ( empty( $candidates['ip'] ) && isset( $_SERVER['REMOTE_ADDR'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by Identity_Resolver.
			$candidates['ip'] = wp_unslash( $_SERVER['REMOTE_ADDR'] );
		}

		if ( empty( $candidates['user_agent'] ) && isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Identity_Resolver.
			$candidates['user_agent'] = wp_unslash( $_SERVER['HTTP_USER_AGENT'] );
		}

		$resolver = new Identity_Resolver();

		return $resolver->resolve(
			$candidates,
			array(
				'marketing_allowed' => Consent::marketing_allowed(),
				'include_ip_ua'     => true,
			)
		);
	}

	/**
	 * Extract likely email/phone candidates from nested form payloads.
	 *
	 * @param array $payload Raw payload.
	 * @return array
	 */
	protected function extract_identity_candidates( $payload ) {
		$payload = is_array( $payload ) ? $payload : array();
		$out     = array();

		foreach ( $payload as $key => $value ) {
			if ( ! empty( $out['email'] ) && ! empty( $out['phone'] ) ) {
				break;
			}

			if ( is_array( $value ) ) {
				$out = array_merge( $out, $this->extract_identity_candidates( $value ) );
				continue;
			}

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$key_name  = strtolower( sanitize_key( (string) $key ) );
			$string    = trim( (string) $value );

			if ( '' === $string ) {
				continue;
			}

			if ( empty( $out['email'] ) && $this->is_email_candidate( $key_name, $string ) ) {
				$out['email'] = sanitize_email( $string );
			}

			if ( empty( $out['phone'] ) && $this->is_phone_candidate( $key_name, $string ) ) {
				$out['phone'] = sanitize_text_field( $string );
			}
		}

		return $out;
	}

	/**
	 * Determine whether a value looks like an email candidate.
	 *
	 * @param string $key   Field key.
	 * @param string $value Field value.
	 * @return bool
	 */
	protected function is_email_candidate( $key, $value ) {
		return ( false !== strpos( $key, 'email' ) || false !== strpos( $key, 'mail' ) ) && is_email( $value );
	}

	/**
	 * Determine whether a value looks like a phone candidate.
	 *
	 * @param string $key   Field key.
	 * @param string $value Field value.
	 * @return bool
	 */
	protected function is_phone_candidate( $key, $value ) {
		$hints = array( 'phone', 'tel', 'mobile', 'cell', 'whatsapp' );
		foreach ( $hints as $hint ) {
			if ( false !== strpos( $key, $hint ) ) {
				return $this->looks_like_phone( $value );
			}
		}

		return false;
	}

	/**
	 * Check whether a scalar resembles a phone number.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	protected function looks_like_phone( $value ) {
		$normalized = preg_replace( '/[^0-9+]/', '', (string) $value );
		return is_string( $normalized ) && (bool) preg_match( '/^\+?[0-9]{7,20}$/', $normalized );
	}

	/**
	 * Get the field name with prefix.
	 *
	 * @param string $key Original key (e.g., ft_source).
	 * @return string Prefixed key (e.g., ct_ft_source).
	 */
	protected function get_field_name( $key ) {
		return $this->field_prefix . $key;
	}
}
