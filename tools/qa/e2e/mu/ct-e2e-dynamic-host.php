<?php
/**
 * E2E only: answer home/siteurl with the request's own Host while option `ct_e2e_dynamic_host` is truthy.
 *
 * Lets one pod serve `www.example.test` and `example.test` as the same site (cookie-scope scenario, #154)
 * without WordPress redirecting to the canonical host or CF7 posting cross-origin. Never deploy.
 *
 * @package ClickTrail
 */

$ct_e2e_dynamic_host = static function ( $url ) {
	if ( empty( $_SERVER['HTTP_HOST'] ) || ! get_option( 'ct_e2e_dynamic_host' ) ) {
		return $url;
	}
	return 'http://' . preg_replace( '/[^a-z0-9.:-]/i', '', (string) $_SERVER['HTTP_HOST'] );
};
add_filter( 'option_home', $ct_e2e_dynamic_host );
add_filter( 'option_siteurl', $ct_e2e_dynamic_host );
