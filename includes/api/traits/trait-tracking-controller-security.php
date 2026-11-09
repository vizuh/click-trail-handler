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

		if ( wp_using_ext_object_cache() ) {
			// Persistent cache present: seed then atomically increment, so every
			// concurrent caller gets a distinct, correctly-serialized count instead
			// of racing on a plain read-then-write.
			wp_cache_add( $key, 0, 'clicutcl_ratelimit', $window );
			$count = wp_cache_incr( $key, 1, 'clicutcl_ratelimit' );
			if ( false === $count ) {
				// Key evicted between the add and the incr: treat as first hit of a new window.
				wp_cache_add( $key, 1, 'clicutcl_ratelimit', $window );
				$count = 1;
			}
			if ( $count > $limit ) {
				return new WP_Error( 'rate_limited', 'Too many requests', array( 'status' => 429 ) );
			}
			return true;
		}

		// No persistent object cache: best-effort, non-atomic transient fallback.
		$hit = (int) get_transient( $key );
		if ( $hit >= $limit ) {
			return new WP_Error( 'rate_limited', 'Too many requests', array( 'status' => 429 ) );
		}

		set_transient( $key, $hit + 1, $window );
		return true;
	}

	/**
	 * Replay limit keyed by token nonce, client IP and request session.
	 *
	 * The token nonce is minted into page HTML, so a page cache hands the same nonce to every
	 * visitor of that page. A cap keyed only on (nonce, IP) would therefore starve every visitor
	 * behind one IP. The counter is keyed on (nonce, IP, session id from the request body) and
	 * lives in a fixed window of at most one hour, never the token lifetime. The per-IP rate
	 * limiter (check_rate_limit) stays the primary abuse control.
	 *
	 * Without a session/visitor id in the body the request falls into a shared per-(nonce, IP)
	 * bucket with a ten times larger allowance.
	 *
	 * The setting `token_nonce_limit` (0 = built-in default) and the filter
	 * `clicutcl_v2_token_nonce_limit` (0 = disabled) set the allowance per window.
	 *
	 * @param array                $claims        Verified token claims.
	 * @param int                  $default_limit Allowance per window when the setting is 0/unset (0 disables).
	 * @param WP_REST_Request|null $request       Request, used to read the session id.
	 * @param string               $scope         Route scope, so routes do not share a counter.
	 * @return true|WP_Error
	 */
	private function check_token_nonce_limit( array $claims, int $default_limit = 0, ?WP_REST_Request $request = null, string $scope = '' ) {
		$nonce = isset( $claims['nonce'] ) ? sanitize_text_field( (string) $claims['nonce'] ) : '';
		if ( '' === $nonce ) {
			return true;
		}

		$settings_limit = (int) ( Tracking_Settings::get()['security']['token_nonce_limit'] ?? 0 );
		if ( $settings_limit < 1 ) {
			$settings_limit = $default_limit;
		}
		$limit = (int) apply_filters( 'clicutcl_v2_token_nonce_limit', $settings_limit );
		$limit = max( 0, min( 5000, $limit ) );
		if ( 0 === $limit ) {
			return true;
		}

		$session = $request ? $this->extract_request_session_id( $request ) : '';
		if ( '' === $session ) {
			$limit = min( 5000, $limit * 10 );
		}

		$window = HOUR_IN_SECONDS;
		if ( isset( $claims['exp'] ) ) {
			$window = max( 60, min( HOUR_IN_SECONDS, absint( $claims['exp'] ) - time() ) );
		}

		$ip    = $this->get_client_ip();
		$key   = 'clicutcl_v2_nonce_' . md5( $scope . '|' . $nonce . '|' . $ip . '|' . $session );
		$state = get_transient( $key );
		$now   = time();
		if ( ! is_array( $state ) || ! isset( $state['n'], $state['s'] ) || $now - (int) $state['s'] >= $window ) {
			$state = array(
				'n' => 0,
				's' => $now,
			);
		}

		if ( (int) $state['n'] >= $limit ) {
			return new WP_Error( 'nonce_replay_limited', 'Too many requests for token nonce', array( 'status' => 429 ) );
		}

		$state['n'] = (int) $state['n'] + 1;
		set_transient( $key, $state, max( 1, $window - ( $now - (int) $state['s'] ) ) );
		return true;
	}

	/**
	 * Best-effort session (or visitor) id from the JSON body, bounded and sanitized.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private function extract_request_session_id( WP_REST_Request $request ): string {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return '';
		}

		$candidates = array( $body );
		if ( isset( $body['event'] ) && is_array( $body['event'] ) ) {
			$candidates[] = $body['event'];
		}
		if ( isset( $body['events'] ) && is_array( $body['events'] ) && isset( $body['events'][0] ) && is_array( $body['events'][0] ) ) {
			$candidates[] = $body['events'][0];
		}

		foreach ( $candidates as $candidate ) {
			foreach ( array( 'session_id', 'visitor_id' ) as $field ) {
				if ( isset( $candidate[ $field ] ) && is_scalar( $candidate[ $field ] ) ) {
					$value = substr( sanitize_text_field( (string) $candidate[ $field ] ), 0, 128 );
					if ( '' !== $value ) {
						return $value;
					}
				}
			}
		}

		return '';
	}

	/**
	 * Count a gate rejection in failure telemetry (fixed error code only, bounded cardinality).
	 *
	 * @param WP_Error $error Gate error.
	 * @return void
	 */
	private function record_gate_rejection( WP_Error $error ): void {
		$code = $error->get_error_code();
		if ( in_array( $code, array( 'nonce_replay_limited', 'rate_limited', 'token_expired', 'invalid_signature' ), true )
			&& is_callable( array( \CLICUTCL\Server_Side\Dispatcher::class, 'record_failure' ) ) ) {
			\CLICUTCL\Server_Side\Dispatcher::record_failure( $code );
		}
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

		$xff   = filter_input( INPUT_SERVER, 'HTTP_X_FORWARDED_FOR', FILTER_UNSAFE_RAW );
		$cf_ip = filter_input( INPUT_SERVER, 'HTTP_CF_CONNECTING_IP', FILTER_UNSAFE_RAW );

		return $this->client_ip_for_peer( $remote, is_string( $xff ) ? $xff : '', is_string( $cf_ip ) ? $cf_ip : '' );
	}

	/**
	 * Decide the client IP from the direct peer and the forwarding headers.
	 *
	 * Forwarding headers are read only when the direct peer is a trusted proxy.
	 *
	 * - Opt-in `clicutcl_trust_cf_connecting_ip` (default false): a valid `CF-Connecting-IP` wins.
	 *   It is meaningful only when every hop in front of WordPress is Cloudflare or a proxy that
	 *   overwrites it; any other trusted proxy passes a client-sent copy through, so it is never
	 *   read unless the operator says so.
	 * - Otherwise `X-Forwarded-For` is walked from the right (the end appended by infrastructure).
	 *   Each hop is normalised (`:port` stripped, `[v6]` unwrapped); a trusted proxy is skipped and
	 *   the first untrusted IP is the client. A hop that is still not an IP (`unknown`, an
	 *   obfuscated token, an empty part) stops the walk at the direct peer: everything to its left
	 *   is client-controlled and must never be reached by skipping over it.
	 * - Anything else resolves to the direct peer.
	 *
	 * @param string $remote Direct peer (`REMOTE_ADDR`).
	 * @param string $xff    `X-Forwarded-For` header value.
	 * @param string $cf_ip  `CF-Connecting-IP` header value.
	 * @return string
	 */
	private function client_ip_for_peer( string $remote, string $xff, string $cf_ip ): string {
		if ( ! $this->is_trusted_proxy( $remote ) ) {
			return $remote;
		}

		/**
		 * Whether to trust `CF-Connecting-IP` when the direct peer is a trusted proxy.
		 *
		 * Enable only when the site is reachable exclusively through Cloudflare (so the header is
		 * always set or overwritten by Cloudflare). Left off, the header is ignored because a visitor
		 * can send it through any other trusted proxy.
		 *
		 * @since 1.13.0
		 *
		 * @param bool $trust Default false.
		 */
		if ( (bool) apply_filters( 'clicutcl_trust_cf_connecting_ip', false ) ) {
			$cf_ip = sanitize_text_field( $cf_ip );
			if ( filter_var( $cf_ip, FILTER_VALIDATE_IP ) ) {
				return $cf_ip;
			}
		}

		if ( '' === $xff ) {
			return $remote;
		}

		foreach ( array_reverse( explode( ',', $xff ) ) as $hop ) {
			$hop = $this->normalize_forwarded_hop( $hop );
			if ( '' === $hop ) {
				return $remote;
			}
			if ( ! $this->is_trusted_proxy( $hop ) ) {
				return $hop;
			}
		}

		return $remote;
	}

	/**
	 * Normalise one `X-Forwarded-For` hop to a bare IP.
	 *
	 * Strips a `:port` from IPv4 and unwraps `[v6]` / `[v6]:port` (IIS/ARR and some load balancers
	 * append the peer with its port).
	 *
	 * @param string $hop Raw hop.
	 * @return string The IP, or '' when the hop is not one.
	 */
	private function normalize_forwarded_hop( string $hop ): string {
		$hop = sanitize_text_field( trim( $hop ) );

		if ( 1 === preg_match( '/^\[([0-9a-f:.]+)\](?::\d{1,5})?$/i', $hop, $m ) ) {
			$hop = $m[1];
		} elseif ( 1 === preg_match( '/^(\d{1,3}(?:\.\d{1,3}){3}):\d{1,5}$/', $hop, $m ) ) {
			$hop = $m[1];
		}

		return false !== filter_var( $hop, FILTER_VALIDATE_IP ) ? $hop : '';
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
		$mask_bits                  = (int) $mask_bits;

		$ip_bin     = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Suppress warning on malformed IP input; false return is checked below.
		$subnet_bin = @inet_pton( $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Suppress warning on malformed subnet input; false return is checked below.
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
