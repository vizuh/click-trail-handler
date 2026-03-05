<?php
/**
 * Attribution Provider
 *
 * Checks consent and retrieves attribution data for forms and other integrations.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Attribution_Provider
 */
class Attribution_Provider {

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
		$options         = get_option( 'clicutcl_attribution_settings', array() );
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
			$meta_key = sanitize_key( $key );
			if ( '' === $meta_key ) {
				continue;
			}

			if ( 'session_count' === $meta_key ) {
				$sanitized[ $meta_key ] = absint( $value );
				continue;
			}
			
			// Handle simple values only, no nested arrays expected in flattened payload
			if ( is_scalar( $value ) ) {
				$sanitized[ $meta_key ] = sanitize_text_field( $value );
			}
		}

		return $sanitized;
	}

	/**
	 * Get field list for mapping.
	 *
	 * @return array Array of field keys to look for.
	 */
	public static function get_field_mapping() {
		return array(
			// UTMs
			'ft_source', 'ft_medium', 'ft_campaign', 'ft_term', 'ft_content',
			'lt_source', 'lt_medium', 'lt_campaign', 'lt_term', 'lt_content',
			
			// Click IDs
			'ft_gclid', 'ft_wbraid', 'ft_gbraid', 'ft_fbclid', 'ft_msclkid',
			'ft_ttclid', 'ft_twclid', 'ft_li_fat_id', 'ft_sccid', 'ft_sc_click_id', 'ft_epik',
			'lt_gclid', 'lt_wbraid', 'lt_gbraid', 'lt_fbclid', 'lt_msclkid',
			'lt_ttclid', 'lt_twclid', 'lt_li_fat_id', 'lt_sccid', 'lt_sc_click_id', 'lt_epik',
			
			// Metadata
			'ft_landing_page', 'lt_landing_page',
			'first_touch_timestamp', 'last_touch_timestamp',
			'ft_referrer', 'lt_referrer',
			'session_count'
		);
	}
}
