<?php

/**
 * Sanitize attribution data recursively.
 *
 * @param mixed $data The raw attribution data.
 * @return array|string Sanitized data.
 */
function clicktrail_sanitize_attribution_data( $data ) {
        if ( is_array( $data ) ) {
                foreach ( $data as $key => $value ) {
                        $data[ $key ] = clicktrail_sanitize_attribution_data( $value );
                }

                return $data;
        }

        if ( is_scalar( $data ) ) {
                return sanitize_text_field( (string) $data );
        }

        return '';
}

/**
 * Retrieve the current attribution data from the cookie.
 *
 * @return array|null The attribution data array or null if not found.
 */
function clicktrail_get_attribution() {
        if ( isset( $_COOKIE['ct_attribution'] ) ) {
                $raw_cookie_value = wp_unslash( $_COOKIE['ct_attribution'] );
                $data             = json_decode( $raw_cookie_value, true );

                if ( json_last_error() === JSON_ERROR_NONE ) {
                        return clicktrail_sanitize_attribution_data( $data );
                }
        }

        return null;
}

/**
 * Retrieve a specific field from the attribution data.
 *
 * @param string $type "first_touch" or "last_touch".
 * @param string $field The field key (e.g., "utm_source").
 * @return string|null The value or null.
 */
function clicktrail_get_attribution_field( $type, $field ) {
	$data = clicktrail_get_attribution();
	if ( $data && isset( $data[ $type ] ) && isset( $data[ $type ][ $field ] ) ) {
		return $data[ $type ][ $field ];
	}
	return null;
}
