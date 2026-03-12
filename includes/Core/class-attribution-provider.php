<?php
/**
 * Attribution Provider
 *
 * Checks consent and retrieves attribution data for forms and other integrations.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Core;

use CLICUTCL\Settings\Attribution_Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Attribution_Provider
 */
class Attribution_Provider {

	/**
	 * Legacy-to-canonical attribution field aliases.
	 *
	 * @var array<string,string>
	 */
	private const FIELD_ALIASES = array(
		'_fbc'                 => 'fbc',
		'_fbp'                 => 'fbp',
		'_ttp'                 => 'ttp',
		'sc_click_id'          => 'sccid',
		'ft_sc_click_id'       => 'ft_sccid',
		'lt_sc_click_id'       => 'lt_sccid',
		'first_touch_timestamp' => 'ft_touch_timestamp',
		'last_touch_timestamp'  => 'lt_touch_timestamp',
		'first_landing_page'    => 'ft_landing_page',
		'last_landing_page'     => 'lt_landing_page',
	);

	/**
	 * Touch-level campaign fields.
	 *
	 * @var string[]
	 */
	private const TOUCH_FIELDS = array(
		'source',
		'medium',
		'campaign',
		'term',
		'content',
		'utm_id',
		'utm_source_platform',
		'utm_creative_format',
		'utm_marketing_tactic',
	);

	/**
	 * Canonical click ID fields.
	 *
	 * @var string[]
	 */
	private const CLICK_ID_FIELDS = array(
		'gclid',
		'fbclid',
		'msclkid',
		'ttclid',
		'wbraid',
		'gbraid',
		'twclid',
		'li_fat_id',
		'sccid',
		'epik',
	);

	/**
	 * Legacy click ID aliases that we still read and populate.
	 *
	 * @var string[]
	 */
	private const CLICK_ID_FIELD_ALIASES = array(
		'sc_click_id',
	);

	/**
	 * Browser-side platform identifiers kept at the top level.
	 *
	 * @var string[]
	 */
	private const BROWSER_IDENTIFIER_FIELDS = array(
		'fbc',
		'fbp',
		'ttp',
		'li_gc',
		'ga_client_id',
		'ga_session_id',
		'ga_session_number',
	);

	/**
	 * Retrieve the current attribution payload (flattened).
	 *
	 * @return array The attribution data array, empty if none or no consent.
	 */
	public static function get_payload() {
		if ( ! self::should_populate() ) {
			return array();
		}

		$keys = array( 'ct_attribution', 'attribution' );
		$data = array();

		foreach ( $keys as $key ) {
			if ( isset( $_COOKIE[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON string is decoded and then sanitized.
				$cookie_value = wp_unslash( $_COOKIE[ $key ] );
				$decoded      = json_decode( $cookie_value, true );
				if ( is_array( $decoded ) && JSON_ERROR_NONE === json_last_error() ) {
					$data = $decoded;
					break;
				}
			}
		}

		if ( empty( $data ) ) {
			return array();
		}

		return self::sanitize( $data );
	}

	/**
	 * Check if attribution fields should be populated based on consent settings.
	 *
	 * @return bool
	 */
	public static function should_populate() {
		$options         = Attribution_Settings::get_all();
		$require_consent = isset( $options['require_consent'] ) ? (bool) $options['require_consent'] : true; // Default to true for safety
		$cookie_name     = 'ct_consent';

		if ( class_exists( 'CLICUTCL\\Modules\\Consent_Mode\\Consent_Mode_Settings' ) ) {
			$consent_settings = new \CLICUTCL\Modules\Consent_Mode\Consent_Mode_Settings();
			if ( $consent_settings->is_consent_mode_enabled() ) {
				$require_consent = $consent_settings->is_consent_required_for_request();
				$cookie_name     = $consent_settings->get_cookie_name();
			}
		}

		if ( ! $require_consent ) {
			return true;
		}

		// Check consent cookie
		if ( isset( $_COOKIE[ $cookie_name ] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$consent_json = wp_unslash( $_COOKIE[ $cookie_name ] );
			$normalized   = strtolower( trim( (string) $consent_json ) );
			if ( in_array( $normalized, array( 'granted', '1', 'true', 'yes' ), true ) ) {
				return true;
			}
			if ( in_array( $normalized, array( 'denied', '0', 'false', 'no' ), true ) ) {
				return false;
			}
			$consent      = json_decode( $consent_json, true );

			return isset( $consent['marketing'] ) && $consent['marketing'];
		}

		return false; // Consent required but not found
	}

	/**
	 * Sanitize attribution data.
	 *
	 * @param array $data Raw data.
	 * @return array Sanitized data.
	 */
	public static function sanitize( $data ) {
		if ( ! is_array( $data ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $data as $key => $value ) {
			$meta_key = self::canonicalize_field_key( $key );
			if ( '' === $meta_key ) {
				continue;
			}

			if ( array_key_exists( $meta_key, $sanitized ) && $meta_key !== sanitize_key( $key ) ) {
				continue;
			}

			if ( in_array( $meta_key, array( 'session_count', 'session_number' ), true ) ) {
				$sanitized[ $meta_key ] = absint( $value );
				continue;
			}
			
			// Handle simple values only, no nested arrays expected in flattened payload
			if ( is_scalar( $value ) ) {
				$sanitized[ $meta_key ] = sanitize_text_field( $value );
			}
		}

		$sanitized = self::normalize_click_ids( $sanitized );

		if ( ! empty( $sanitized['sccid'] ) ) {
			$sanitized['sc_click_id'] = $sanitized['sccid'];
		}
		if ( ! empty( $sanitized['ft_sccid'] ) ) {
			$sanitized['ft_sc_click_id'] = $sanitized['ft_sccid'];
		}
		if ( ! empty( $sanitized['lt_sccid'] ) ) {
			$sanitized['lt_sc_click_id'] = $sanitized['lt_sccid'];
		}

		return $sanitized;
	}

	/**
	 * Get field list for mapping.
	 *
	 * @return array Array of field keys to look for.
	 */
	public static function get_field_mapping() {
		$fields = array();

		foreach ( self::TOUCH_FIELDS as $field ) {
			$fields[] = 'ft_' . $field;
			$fields[] = 'lt_' . $field;
		}

		foreach ( self::CLICK_ID_FIELDS as $field ) {
			$fields[] = 'ft_' . $field;
			$fields[] = 'lt_' . $field;
		}

		foreach ( self::CLICK_ID_FIELD_ALIASES as $field ) {
			$fields[] = 'ft_' . $field;
			$fields[] = 'lt_' . $field;
		}

		return array_merge(
			$fields,
			self::CLICK_ID_FIELDS,
			self::CLICK_ID_FIELD_ALIASES,
			self::BROWSER_IDENTIFIER_FIELDS,
			array(
				'ft_landing_page',
				'lt_landing_page',
				'ft_touch_timestamp',
				'lt_touch_timestamp',
				'ft_referrer',
				'lt_referrer',
				'session_count',
				'session_number',
			)
		);
	}

	/**
	 * Retrieve the current session state from the ct_session cookie.
	 *
	 * @return array Session data (session_id, session_number, session_started_at, last_activity_at), empty if unavailable.
	 */
	public static function get_session() {
		if ( ! self::should_populate() ) {
			return array();
		}

		if ( ! isset( $_COOKIE['ct_session'] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded and sanitized below.
		$raw     = wp_unslash( $_COOKIE['ct_session'] );
		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) || JSON_ERROR_NONE !== json_last_error() ) {
			return array();
		}

		$sanitized = array();

		if ( ! empty( $decoded['session_id'] ) && is_scalar( $decoded['session_id'] ) ) {
			$sanitized['session_id'] = sanitize_text_field( (string) $decoded['session_id'] );
		}

		if ( isset( $decoded['session_number'] ) ) {
			$sanitized['session_number'] = absint( $decoded['session_number'] );
			$sanitized['session_count']  = $sanitized['session_number']; // backward compat
		}

		if ( isset( $decoded['session_started_at'] ) && is_numeric( $decoded['session_started_at'] ) ) {
			$sanitized['session_started_at'] = absint( $decoded['session_started_at'] );
		}

		if ( isset( $decoded['last_activity_at'] ) && is_numeric( $decoded['last_activity_at'] ) ) {
			$sanitized['last_activity_at'] = absint( $decoded['last_activity_at'] );
		}

		return $sanitized;
	}

	/**
	 * Get touch-level campaign fields without prefixes.
	 *
	 * @return string[]
	 */
	public static function get_touch_fields() {
		return self::TOUCH_FIELDS;
	}

	/**
	 * Get canonical click ID fields without prefixes.
	 *
	 * @return string[]
	 */
	public static function get_click_id_fields() {
		return self::CLICK_ID_FIELDS;
	}

	/**
	 * Get browser identifier fields that stay at the top level.
	 *
	 * @return string[]
	 */
	public static function get_browser_identifier_fields() {
		return self::BROWSER_IDENTIFIER_FIELDS;
	}

	/**
	 * Get field alias mapping for legacy compatibility.
	 *
	 * @return array<string,string>
	 */
	public static function get_field_alias_mapping() {
		return self::FIELD_ALIASES;
	}

	/**
	 * Normalize a raw field key to the canonical attribution schema.
	 *
	 * @param string $key Raw field key.
	 * @return string
	 */
	private static function canonicalize_field_key( $key ) {
		$meta_key = sanitize_key( $key );
		if ( '' === $meta_key ) {
			return '';
		}

		return self::FIELD_ALIASES[ $meta_key ] ?? $meta_key;
	}

	/**
	 * Fill canonical top-level click IDs from last-touch / first-touch copies.
	 *
	 * @param array $data Sanitized attribution data.
	 * @return array
	 */
	private static function normalize_click_ids( array $data ) {
		foreach ( self::CLICK_ID_FIELDS as $field ) {
			if ( empty( $data[ $field ] ) ) {
				if ( ! empty( $data[ 'lt_' . $field ] ) ) {
					$data[ $field ] = $data[ 'lt_' . $field ];
				} elseif ( ! empty( $data[ 'ft_' . $field ] ) ) {
					$data[ $field ] = $data[ 'ft_' . $field ];
				}
			}
		}

		return $data;
	}
}
