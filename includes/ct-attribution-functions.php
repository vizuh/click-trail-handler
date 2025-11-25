<?php

/**
 * Sanitize flat attribution data.
 *
 * @param mixed $data The raw attribution data.
 * @return array Sanitized data.
 */
function clicktrail_sanitize_attribution_data( $data ) {
        if ( ! is_array( $data ) ) {
                return array();
        }

        $sanitized = array();

        foreach ( $data as $key => $value ) {
                $sanitized_key = sanitize_key( $key );
                if ( '' === $sanitized_key ) {
                        continue;
                }

                if ( 'session_count' === $sanitized_key ) {
                        $sanitized[ $sanitized_key ] = absint( $value );
                        continue;
                }

                $sanitized[ $sanitized_key ] = sanitize_text_field( (string) $value );
        }

        return $sanitized;
}

/**
 * Retrieve the current attribution data from the cookie.
 *
 * @return array|null The attribution data array or null if not found.
 */
function clicktrail_get_attribution() {
        $keys = array( 'ct_attribution', 'attribution' );

        foreach ( $keys as $key ) {
                if ( isset( $_COOKIE[ $key ] ) ) {
                        $raw_cookie_value = sanitize_text_field( wp_unslash( $_COOKIE[ $key ] ) );
                        $data             = json_decode( $raw_cookie_value, true );

                        if ( json_last_error() === JSON_ERROR_NONE ) {
                                return clicktrail_sanitize_attribution_data( $data );
                        }
                }
        }
        return null;
}

/**
 * Retrieve a specific field from the attribution data.
 *
 * @param string $type 'first_touch' or 'last_touch'.
 * @param string $field The field key (e.g., 'source').
 * @return string|null The value or null.
 */
function clicktrail_get_attribution_field( $type, $field ) {
        $data = clicktrail_get_attribution();
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
