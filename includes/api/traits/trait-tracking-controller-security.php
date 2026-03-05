<?php
/**
 * Security and transport helpers for Tracking_Controller.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Api;

use CLICUTCL\Tracking\Settings as Tracking_Settings;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Tracking_Controller_Security_Trait {
	/**
	 * Verify wp_rest nonce.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	private function verify_rest_nonce( WP_REST_Request $request ): bool {
		$nonce = $request->get_header( 'x_wp_nonce' );
		if ( ! $nonce ) {
			$nonce = $request->get_header( 'x-wp-nonce' );
		}
		if ( ! $nonce ) {
			$nonce = $request->get_param( '_wpnonce' );
		}

		if ( ! $nonce ) {
			return false;
		}

		return (bool) wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * Basic per-IP rate limiter.
	 *
	 * @param string $scope Scope key.
	 * @return true|WP_Error
	 */
	private function check_rate_limit( string $scope ) {
		$scope          = sanitize_key( $scope );
		$settings       = Tracking_Settings::get();
		$window_default = isset( $settings['security']['rate_limit_window'] ) ? (int) $settings['security']['rate_limit_window'] : self::RATE_WINDOW_DEFAULT;
		$limit_default  = isset( $settings['security']['rate_limit_limit'] ) ? (int) $settings['security']['rate_limit_limit'] : self::RATE_LIMIT_DEFAULT;
		$window         = (int) apply_filters( 'clicutcl_v2_rate_window', $window_default, $scope );
		$limit          = (int) apply_filters( 'clicutcl_v2_rate_limit', $limit_default, $scope );
		$window         = max( 5, min( 3600, $window ) );
		$limit          = max( 1, min( 2000, $limit ) );

		$ip  = $this->get_client_ip();
		$key = 'clicutcl_v2_rl_' . md5( $scope . '|' . $ip );
		$hit = (int) get_transient( $key );
		if ( $hit >= $limit ) {
			return new WP_Error( 'rate_limited', 'Too many requests', array( 'status' => 429 ) );
		}

		set_transient( $key, $hit + 1, $window );
		return true;
	}

	/**
	 * Optional replay-limit keyed by token nonce and client IP.
	 *
	 * @param array $claims Verified token claims.
	 * @return true|WP_Error
	 */
	private function check_token_nonce_limit( array $claims ) {
		$nonce = isset( $claims['nonce'] ) ? sanitize_text_field( (string) $claims['nonce'] ) : '';
		if ( '' === $nonce ) {
			return true;
		}

		$settings_limit = Tracking_Settings::get()['security']['token_nonce_limit'] ?? 0;
		$limit          = (int) apply_filters( 'clicutcl_v2_token_nonce_limit', (int) $settings_limit );
		$limit          = max( 0, min( 5000, $limit ) );
		if ( 0 === $limit ) {
			return true;
		}

		$ttl = isset( $claims['exp'] ) ? max( 60, absint( $claims['exp'] ) - time() ) : HOUR_IN_SECONDS;
		$ip  = $this->get_client_ip();
		$key = 'clicutcl_v2_nonce_' . md5( $nonce . '|' . $ip );
		$hit = (int) get_transient( $key );
		if ( $hit >= $limit ) {
			return new WP_Error( 'nonce_replay_limited', 'Too many requests for token nonce', array( 'status' => 429 ) );
		}

		set_transient( $key, $hit + 1, $ttl );
		return true;
	}

	/**
	 * Resolve client IP (best-effort).
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		$remote = $this->get_remote_addr();
		if ( ! $this->is_trusted_proxy( $remote ) ) {
			return $remote;
		}

		$cf_ip = filter_input( INPUT_SERVER, 'HTTP_CF_CONNECTING_IP', FILTER_UNSAFE_RAW );
		$cf_ip = $cf_ip ? sanitize_text_field( (string) $cf_ip ) : '';
		if ( filter_var( $cf_ip, FILTER_VALIDATE_IP ) ) {
			return $cf_ip;
		}

		$xff = filter_input( INPUT_SERVER, 'HTTP_X_FORWARDED_FOR', FILTER_UNSAFE_RAW );
		if ( $xff ) {
			$parts    = array_map( 'trim', explode( ',', (string) $xff ) );
			$fallback = '';
			foreach ( $parts as $candidate ) {
				$candidate = sanitize_text_field( (string) $candidate );
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					if ( '' === $fallback ) {
						$fallback = $candidate;
					}
					if ( ! $this->is_trusted_proxy( $candidate ) ) {
						return $candidate;
					}
				}
			}
			if ( '' !== $fallback ) {
				return $fallback;
			}
		}

		return $remote;
	}

	/**
	 * Resolve trusted proxy CIDRs/IPs from settings and filters.
	 *
	 * @return array
	 */
	private function get_trusted_proxies(): array {
		$settings = Tracking_Settings::get();
		$list     = $settings['security']['trusted_proxies'] ?? array();
		$list     = apply_filters( 'clicutcl_v2_trusted_proxies', $list );
		$list     = apply_filters( 'clicutcl_trusted_proxies', $list );
		if ( is_string( $list ) ) {
			$list = preg_split( '/[\r\n,\s]+/', $list );
		}
		if ( ! is_array( $list ) ) {
			return array();
		}

		$trusted = array();
		foreach ( $list as $entry ) {
			$entry = trim( sanitize_text_field( (string) $entry ) );
			if ( '' === $entry ) {
				continue;
			}

			if ( filter_var( $entry, FILTER_VALIDATE_IP ) ) {
				$trusted[] = $entry;
				continue;
			}

			if ( preg_match( '/^[0-9a-f:.]+\/\d{1,3}$/i', $entry ) ) {
				$trusted[] = $entry;
			}
		}

		return array_values( array_unique( $trusted ) );
	}

	/**
	 * Get direct remote address.
	 *
	 * @return string
	 */
	private function get_remote_addr(): string {
		$remote = filter_input( INPUT_SERVER, 'REMOTE_ADDR', FILTER_UNSAFE_RAW );
		$remote = $remote ? sanitize_text_field( (string) $remote ) : '';
		if ( filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return $remote;
		}

		return '0.0.0.0';
	}

	/**
	 * Check whether remote IP is a trusted proxy.
	 *
	 * @param string $remote Remote IP.
	 * @return bool
	 */
	private function is_trusted_proxy( string $remote ): bool {
		if ( ! filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		foreach ( $this->get_trusted_proxies() as $cidr ) {
			if ( $this->ip_matches_cidr( $remote, $cidr ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Match IP against CIDR or exact IP (IPv4/IPv6).
	 *
	 * @param string $ip   IP.
	 * @param string $cidr CIDR or IP.
	 * @return bool
	 */
	private function ip_matches_cidr( string $ip, string $cidr ): bool {
		if ( false === strpos( $cidr, '/' ) ) {
			return $ip === $cidr;
		}

		list( $subnet, $mask_bits ) = explode( '/', $cidr, 2 );
		$mask_bits = (int) $mask_bits;

		$ip_bin     = @inet_pton( $ip );
		$subnet_bin = @inet_pton( $subnet );
		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}

		$max_bits = 8 * strlen( $ip_bin );
		if ( $mask_bits < 0 || $mask_bits > $max_bits ) {
			return false;
		}

		$bytes = (int) floor( $mask_bits / 8 );
		$bits  = $mask_bits % 8;

		if ( $bytes > 0 && substr( $ip_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) {
			return false;
		}

		if ( 0 === $bits ) {
			return true;
		}

		$mask = ( 0xFF << ( 8 - $bits ) ) & 0xFF;
		return ( ord( $ip_bin[ $bytes ] ) & $mask ) === ( ord( $subnet_bin[ $bytes ] ) & $mask );
	}
}
