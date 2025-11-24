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
        $cookie_value = wp_unslash( $_COOKIE['ct_attribution'] );
        $data         = json_decode( $cookie_value, true );
        if ( is_array( $data ) && json_last_error() === JSON_ERROR_NONE ) {
            return clicktrail_sanitize_attribution_data( $data );
        }
    }

    return null;
}

/**
 * Sanitize attribution data arrays from cookies or request payloads.
 *
 * @param array $data Raw attribution data.
 * @return array Sanitized attribution data.
 */
function clicktrail_sanitize_attribution_data( $data ) {
    $sanitized = array();

    foreach ( array( 'first_touch', 'last_touch' ) as $touch_type ) {
        if ( ! empty( $data[ $touch_type ] ) && is_array( $data[ $touch_type ] ) ) {
            foreach ( $data[ $touch_type ] as $key => $value ) {
                $meta_key = sanitize_key( $key );
                if ( '' === $meta_key ) {
                    continue;
                }

                $sanitized[ $touch_type ][ $meta_key ] = sanitize_text_field( wp_unslash( $value ) );
            }
        }
    }

    if ( isset( $data['landing_page'] ) ) {
        $sanitized['landing_page'] = esc_url_raw( wp_unslash( $data['landing_page'] ) );
    }

    if ( isset( $data['session_count'] ) ) {
        $sanitized['session_count'] = absint( $data['session_count'] );
    }

    return $sanitized;
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
