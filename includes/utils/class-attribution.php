<?php
/**
 * Attribution Utils
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Attribution
 */
class Attribution {

	/**
	 * Retrieve the current attribution data from the cookie.
	 *
	 * @return array|null The attribution data array or null if not found.
	 */
	public static function get() {
		$keys = array( 'ct_attribution', 'attribution' );

		foreach ( $keys as $key ) {
			if ( filter_input( INPUT_COOKIE, $key, FILTER_DEFAULT ) ) {
				// Don't sanitize JSON string - decode first, then sanitize the data
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON string is decoded and then sanitized.
				$cookie_value = wp_unslash( filter_input( INPUT_COOKIE, $key, FILTER_DEFAULT ) );
				$data         = json_decode( $cookie_value, true );
				if ( is_array( $data ) && JSON_ERROR_NONE === json_last_error() ) {
					return self::sanitize( $data );
				}
			}
		}

		return null;
	}

	/**
	 * Sanitize flat attribution data arrays from cookies or request payloads.
	 *
	 * @param array $data Raw attribution data.
	 * @return array Sanitized attribution data.
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

			$sanitized[ $meta_key ] = sanitize_text_field( $value );
		}

		return $sanitized;
	}

	/**
	 * Retrieve a specific field from the attribution data.
	 *
	 * @param string $type "first_touch" or "last_touch".
	 * @param string $field The field key (e.g., "source").
	 * @return string|null The value or null.
	 */
	public static function get_field( $type, $field ) {
		$data = self::get();
		if ( ! $data ) {
			return null;
		}

		$prefix = 'first_touch' === $type ? 'ft_' : 'lt_';
		$key    = $prefix . $field;

		if ( isset( $data[ $key ] ) ) {
			return $data[ $key ];
		}

		return null;
	}
}
