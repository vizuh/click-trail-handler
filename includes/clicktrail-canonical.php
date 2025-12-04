<?php
/**
 * Canonical helpers to keep tracking parameters out of canonical URLs.
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

/**
 * Strip known tracking parameters from a URL.
 *
 * @param string $url Raw URL.
 * @return string URL without tracking parameters.
 */
function clicutcl_strip_tracking_params_from_url( $url ) {
        if ( ! $url ) {
                return $url;
        }

        $parts = wp_parse_url( $url );
        if ( ! $parts ) {
                return $url;
        }

        $base = ( isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '' )
                . ( isset( $parts['host'] ) ? $parts['host'] : '' )
                . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' )
                . ( isset( $parts['path'] ) ? $parts['path'] : '' );

        if ( empty( $parts['query'] ) ) {
                return $base;
        }

        parse_str( $parts['query'], $query_vars );

        $tracking_keys = array(
                'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
                'gclid', 'fbclid', 'msclkid', 'wbraid', 'gbraid',
                'campaign_id', 'adgroup_id', 'ad_id', 'target_id',
        );

        foreach ( $tracking_keys as $key ) {
                unset( $query_vars[ $key ] );
        }

        $query = http_build_query( $query_vars );
        if ( $query ) {
                $base .= '?' . $query;
        }

        return $base;
}

/**
 * Clean Yoast SEO canonical URLs.
 *
 * @param string $canonical Original canonical URL.
 * @return string Filtered canonical URL.
 */
function clicutcl_clean_yoast_canonical( $canonical ) {
        if ( ! $canonical ) {
                return $canonical;
        }

        return clicutcl_strip_tracking_params_from_url( $canonical );
}
add_filter( 'wpseo_canonical', 'clicutcl_clean_yoast_canonical', 20 );

/**
 * Clean core WordPress canonical URLs.
 *
 * @param string      $canonical Original canonical URL.
 * @param int|WP_Post $post      Current post object or ID.
 * @return string Filtered canonical URL.
 */
function clicutcl_clean_core_canonical( $canonical, $post ) {
        if ( ! $canonical ) {
                return $canonical;
        }

        return clicutcl_strip_tracking_params_from_url( $canonical );
}
add_filter( 'get_canonical_url', 'clicutcl_clean_core_canonical', 20, 2 );
