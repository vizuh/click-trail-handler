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

		// Submission-time consent is authoritative. Do not persist or dispatch
		// stale/client-posted attribution after marketing consent is withdrawn.
		if ( ! $this->should_populate() || ( Consent::is_required() && ! Consent::marketing_allowed() ) ) {
			return;
		}

		if ( empty( $attribution ) ) {
			return; // Don't log empty attribution events? Maybe log them anyway but data is empty.
		}

		$table_name = $wpdb->prefix . 'clicutcl_events';

		$event_id = Event::generate_id( 'form' );
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

	/**
	 * Hidden input the browser Injector writes into a form it filled.
	 *
	 * @var string
	 */
	const FILL_MARKER_FIELD = 'ct_fill_source';

	/**
	 * Whether the server may write the requesting visitor's attribution into rendered form HTML.
	 *
	 * Off by default: a page cache that does not vary on the attribution cookie would serve
	 * visitor A's values to visitor B (#152).
	 *
	 * @return bool
	 */
	protected function render_server_values_enabled() {
		return (bool) apply_filters( 'clicutcl_render_server_attribution_values', false, $this->get_platform_name() );
	}

	/**
	 * Attribution values to write into rendered form HTML.
	 *
	 * @return array Cookie payload when the filter opts in and population is allowed, otherwise every mapped key empty.
	 */
	protected function get_render_payload() {
		if ( $this->render_server_values_enabled() && $this->should_populate() ) {
			$payload = $this->get_attribution_payload();
			if ( is_array( $payload ) ) {
				return array_merge( array_fill_keys( Attribution_Provider::get_field_mapping(), '' ), $payload );
			}
		}
		return array_fill_keys( Attribution_Provider::get_field_mapping(), '' );
	}

	/**
	 * Whether a posted value set carries the Injector's JS fill marker.
	 *
	 * @param mixed $marker Posted `ct_fill_source` value.
	 * @return bool
	 */
	protected function has_fill_marker( $marker ) {
		return is_scalar( $marker ) && 'js' === (string) $marker;
	}

	/**
	 * Attribution key for a posted field name, or null when it is not ClickTrail's.
	 *
	 * Only `ct_<key>` names whose key is in `Attribution_Provider::get_field_mapping()` belong to
	 * ClickTrail; a visitor-typed field such as `ct_company` must never be rewritten or blanked.
	 *
	 * @param mixed $name Posted field name.
	 * @return string|null
	 */
	protected function mapped_key( $name ) {
		$name = (string) $name;
		if ( 0 !== strpos( $name, $this->field_prefix ) ) {
			return null;
		}
		$key = substr( $name, strlen( $this->field_prefix ) );
		return in_array( $key, Attribution_Provider::get_field_mapping(), true ) ? $key : null;
	}

	/**
	 * Resolve the submit-time attribution set for posted ct_* values.
	 *
	 * Consent blocked: every key blank. Readable cookie present: the cookie is authoritative and
	 * keys it lacks are blank. Cookie absent: posted values survive only with the JS fill marker.
	 *
	 * @param array $posted Posted values keyed by mapped attribution key (without the `ct_` prefix).
	 * @param bool  $marker Whether the form posted `ct_fill_source=js`.
	 * @return array Value per mapped key; '' for blanked keys.
	 */
	protected function resolve_submitted_attribution( array $posted, $marker ) {
		$keys = Attribution_Provider::get_field_mapping();
		$out  = array_fill_keys( $keys, '' );

		if ( ! $this->refresh_allowed() ) {
			return $out;
		}

		$payload = $this->get_attribution_payload();
		if ( ! empty( $payload ) && is_array( $payload ) ) {
			foreach ( $keys as $key ) {
				$value       = $this->cookie_value_for( $this->get_field_name( $key ), $payload );
				$out[ $key ] = null === $value ? '' : sanitize_text_field( $value );
			}
			return $out;
		}

		if ( $marker ) {
			foreach ( $posted as $key => $value ) {
				if ( ! isset( $out[ $key ] ) ) {
					continue;
				}
				$out[ $key ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
			}
		}

		return $out;
	}
}
