<?php

/**
 * Sanitize flat attribution data arrays from cookies or request payloads.
 *
 * @param array $data Raw attribution data.
 * @return array Sanitized attribution data.
 */
function clicktrail_sanitize_attribution_data( $data ) {
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

        $sanitized[ $meta_key ] = sanitize_text_field( wp_unslash( $value ) );
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
            $cookie_value = sanitize_text_field( wp_unslash( $_COOKIE[ $key ] ) );
            $data         = json_decode( $cookie_value, true );
            if ( is_array( $data ) && json_last_error() === JSON_ERROR_NONE ) {
                return clicktrail_sanitize_attribution_data( $data );
            }
        }
    }

    return null;
}

/**
 * Retrieve a specific field from the attribution data.
 *
 * @param string $type "first_touch" or "last_touch".
 * @param string $field The field key (e.g., "source").
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
