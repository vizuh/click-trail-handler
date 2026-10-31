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
use CLICUTCL\Tracking\Dedup_Store;
use CLICUTCL\Tracking\Identity_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Abstract_Form_Adapter
 */
abstract class Abstract_Form_Adapter implements Form_Adapter_Interface {

	/**
	 * Width in seconds of the time bucket in the best-effort dedupe key used when a
	 * provider supplies no entry ID.
	 */
	const DEDUPE_BUCKET_SECONDS = 10;

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
	 * Whether a submit-time refresh or render fill may touch provider data.
	 *
	 * Submission-time consent is authoritative: the same gate `log_submission()` applies.
	 *
	 * @return bool
	 */
	protected function refresh_allowed() {
		return $this->should_populate() && ! ( Consent::is_required() && ! Consent::marketing_allowed() );
	}

	/**
	 * Log submission to ClickTrail events table, once per entry.
	 *
	 * Dedupe: a repeat call for the same `(provider, form_id, entry_id)` writes no second
	 * `form_submission` row and dispatches no second event; it returns the first call's
	 * `event_id` with `duplicate` set. The `event_id` is derived from the dedupe key, so it is
	 * the same on every call for an entry. When the provider supplies no entry ID (CF7,
	 * Elementor), the key is `(provider, form_id, sha1 of the ct_* values and the submitter's
	 * email/phone, 10 s time bucket)`. That is best-effort: a repeat that crosses a bucket
	 * boundary, or arrives with different posted ct_* values, counts as a new submission.
	 *
	 * @param string          $platform       Platform name.
	 * @param mixed           $form_id        Form ID.
	 * @param array           $attribution    Attribution data associated with submission.
	 * @param array           $identity_input Optional raw identity input candidates.
	 * @param int|string|null $entry_id       Provider entry ID, when the provider has one.
	 * @return array Empty when consent or empty attribution stopped the log; otherwise
	 *               `event_id` (string) and `duplicate` (bool).
	 */
	protected function log_submission( $platform, $form_id, $attribution, $identity_input = array(), $entry_id = null ) {
		global $wpdb;

		// Submission-time consent is authoritative. Do not persist or dispatch
		// stale/client-posted attribution after marketing consent is withdrawn.
		if ( ! $this->should_populate() || ( Consent::is_required() && ! Consent::marketing_allowed() ) ) {
			return array();
		}

		if ( empty( $attribution ) ) {
			return array(); // Don't log empty attribution events? Maybe log them anyway but data is empty.
		}

		$fingerprint = $this->submission_fingerprint( $platform, $form_id, $attribution, $identity_input, $entry_id );
		$event_id    = 'form_' . substr( $fingerprint, 0, 32 );

		// Dedup_Store::check_and_mark() is a transient read-then-write, so two truly simultaneous
		// requests can both pass; sequential repeats (double click, retried AJAX) cannot.
		if ( Dedup_Store::check_and_mark( 'form_submission', (string) $platform, $fingerprint, DAY_IN_SECONDS ) ) {
			return array(
				'event_id'  => $event_id,
				'duplicate' => true,
			);
		}

		$table_name = $wpdb->prefix . 'clicutcl_events';

		$identity = $this->resolve_identity_payload( $identity_input );

		$event_data = array(
			'event_id'    => $event_id,
			'platform'    => $platform,
			'form_id'     => $form_id,
			'attribution' => $attribution,
		);

		if ( ! empty( $identity ) ) {
			// Persist an IP-anonymized copy in the diagnostic log; the full identity below
			// still flows to server-side dispatch where the raw IP aids CAPI match quality.
			$event_data['identity'] = $this->anonymize_identity_for_log( $identity );
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

		return array(
			'event_id'  => $event_id,
			'duplicate' => false,
		);
	}

	/**
	 * Current Unix time for the best-effort dedupe bucket. Overridable for tests.
	 *
	 * @return int
	 */
	protected function submission_time() {
		return time();
	}

	/**
	 * Dedupe fingerprint for one form submission.
	 *
	 * @param string          $platform       Platform name.
	 * @param mixed           $form_id        Form ID.
	 * @param array           $attribution    Attribution data.
	 * @param array           $identity_input Raw identity input candidates.
	 * @param int|string|null $entry_id       Provider entry ID, when the provider has one.
	 * @return string 40-character sha1.
	 */
	private function submission_fingerprint( $platform, $form_id, $attribution, $identity_input, $entry_id ) {
		$entry_id = is_scalar( $entry_id ) ? trim( (string) $entry_id ) : '';
		$scope    = (int) get_current_blog_id() . '|' . $platform . '|' . ( is_scalar( $form_id ) ? (string) $form_id : '' );

		if ( '' !== $entry_id && '0' !== $entry_id ) {
			return sha1( $scope . '|entry|' . $entry_id );
		}

		$candidates = $this->extract_identity_candidates( is_array( $identity_input ) ? $identity_input : array() );
		$values     = array_merge( (array) $attribution, array_intersect_key( $candidates, array_flip( array( 'email', 'phone' ) ) ) );
		ksort( $values );
		$bucket = (int) floor( $this->submission_time() / self::DEDUPE_BUCKET_SECONDS );

		return sha1( $scope . '|best_effort|' . $bucket . '|' . wp_json_encode( $values ) );
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
	 * Return an identity payload with the IP anonymized, for at-rest logging.
	 *
	 * Zeroes the last octet (IPv4) / last 80 bits (IPv6) via WordPress core so the
	 * diagnostic events table does not retain a full personal IP address.
	 *
	 * @param array $identity Resolved identity payload.
	 * @return array
	 */
	protected function anonymize_identity_for_log( $identity ) {
		$identity = is_array( $identity ) ? $identity : array();
		if ( ! empty( $identity['ip_address'] ) ) {
			$identity['ip_address'] = wp_privacy_anonymize_ip( (string) $identity['ip_address'] );
		}
		return $identity;
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

			$key_name = strtolower( sanitize_key( (string) $key ) );
			$string   = trim( (string) $value );

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

	/**
	 * Submit-time value for a ct_* field: a cookie key that is present and non-empty wins.
	 *
	 * @param string $field_name Provider field name, e.g. `ct_gclid`.
	 * @param array  $payload    Attribution payload from the cookie sent with the submit.
	 * @return string|null Cookie value, or null when the field is not a ct_* field or the cookie lacks the key.
	 */
	protected function cookie_value_for( $field_name, $payload ) {
		$field_name = (string) $field_name;
		if ( 0 !== strpos( $field_name, $this->field_prefix ) ) {
			return null;
		}
		$key = substr( $field_name, strlen( $this->field_prefix ) );
		if ( ! isset( $payload[ $key ] ) || '' === (string) $payload[ $key ] ) {
			return null;
		}
		return (string) $payload[ $key ];
	}
}
