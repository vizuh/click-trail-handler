<?php
/**
 * Runtime, diagnostics and transport helpers for Log_Controller.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Api;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Log_Controller_Runtime_Trait {
	/**
	 * DB readiness check with in-request memoization.
	 *
	 * @return bool
	 */
	private function db_ready() {
		if ( null !== self::$db_ready_mem ) {
			return self::$db_ready_mem;
		}

		$stored = get_option( self::DB_READY_OPTION, null );
		if ( null === $stored ) {
			$stored = get_option( 'clicutcl_db_ready', null );
		}
		$now    = time();
		if ( null === $stored ) {
			$ready = $this->table_exists_fast();
			$this->persist_db_ready( $ready, $now );
			self::$db_ready_mem = $ready;
			return $ready;
		}

		$ready      = (int) $stored === 1;
		$checked_at = (int) get_option( self::DB_READY_CHECKED_AT_OPTION, 0 );
		if ( ! $checked_at ) {
			$checked_at = (int) get_option( 'clicutcl_db_ready_checked_at', 0 );
		}

		if ( ! $ready && ( $now - $checked_at ) > DAY_IN_SECONDS ) {
			$ready = $this->table_exists_fast();
			$this->persist_db_ready( $ready, $now );
		}

		self::$db_ready_mem = $ready;
		return $ready;
	}

	/**
	 * Persist DB readiness flags without autoload.
	 *
	 * @param bool $ready DB readiness.
	 * @param int  $checked_at Timestamp.
	 * @return void
	 */
	private function persist_db_ready( $ready, $checked_at ) {
		update_option( self::DB_READY_OPTION, $ready ? 1 : 0, false );
		update_option( self::DB_READY_CHECKED_AT_OPTION, absint( $checked_at ), false );
	}

	/**
	 * Fast table existence check for events table.
	 *
	 * @return bool
	 */
	private function table_exists_fast() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'clicutcl_events';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		return is_string( $found ) && $found === $table_name;
	}

	/**
	 * Deduplicate event IDs via transient.
	 *
	 * @param string $event_id Event ID.
	 * @return bool
	 */
	private function is_duplicate_event( $event_id ) {
		$key = 'clicutcl_evt_' . md5( $event_id );
		if ( get_transient( $key ) ) {
			return true;
		}
		set_transient( $key, 1, 300 );
		return false;
	}

	/**
	 * Rate limit requests by IP + UA.
	 *
	 * @param string $bucket Bucket name.
	 * @return true|\WP_Error
	 */
	private function check_rate_limit( $bucket ) {
		$bucket = sanitize_key( $bucket );

		$rate = apply_filters(
			'clicutcl_rate_limit',
			array(
				'limit'  => self::RATE_LIMIT_DEFAULT,
				'window' => self::RATE_WINDOW_DEFAULT,
			),
			$bucket
		);

		$limit  = isset( $rate['limit'] ) ? absint( $rate['limit'] ) : self::RATE_LIMIT_DEFAULT;
		$window = isset( $rate['window'] ) ? absint( $rate['window'] ) : self::RATE_WINDOW_DEFAULT;

		if ( $limit < 1 || $window < 1 ) {
			return true;
		}

		$fingerprint = $this->get_client_fingerprint();
		$key         = 'clicutcl_rl_' . $bucket . '_' . md5( $fingerprint );
		$state       = get_transient( $key );

		if ( ! is_array( $state ) ) {
			$state = array(
				'count' => 0,
				'start' => time(),
			);
		}

		if ( ( time() - (int) $state['start'] ) > $window ) {
			$state = array(
				'count' => 0,
				'start' => time(),
			);
		}

		$state['count']++;
		set_transient( $key, $state, $window );

		if ( $state['count'] > $limit ) {
			return new WP_Error( 'rate_limited', 'Too many requests', array( 'status' => 429 ) );
		}

		return true;
	}

	/**
	 * Build a simple fingerprint for rate limiting.
	 *
	 * @return string
	 */
	private function get_client_fingerprint() {
		$ip = $this->get_client_ip();
		$ua = filter_input( INPUT_SERVER, 'HTTP_USER_AGENT', FILTER_UNSAFE_RAW );
		$ua = $ua ? sanitize_text_field( (string) $ua ) : '';

		return $ip . '|' . $ua;
	}

	/**
	 * Best-effort client IP.
	 *
	 * @return string
	 */
	private function get_client_ip() {
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
			$parts = array_map( 'trim', explode( ',', (string) $xff ) );
			foreach ( $parts as $candidate ) {
				$candidate = sanitize_text_field( (string) $candidate );
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					return $candidate;
				}
			}
		}

		return $remote;
	}

	/**
	 * Direct remote address.
	 *
	 * @return string
	 */
	private function get_remote_addr() {
		$remote = filter_input( INPUT_SERVER, 'REMOTE_ADDR', FILTER_UNSAFE_RAW );
		$remote = $remote ? sanitize_text_field( (string) $remote ) : '';
		if ( filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return $remote;
		}

		return '0.0.0.0';
	}

	/**
	 * Resolve trusted proxy CIDRs/IPs.
	 *
	 * @return array
	 */
	private function get_trusted_proxies() {
		$list = apply_filters( 'clicutcl_trusted_proxies', array() );
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
	 * Check if remote IP belongs to a trusted proxy.
	 *
	 * @param string $remote Remote IP.
	 * @return bool
	 */
	private function is_trusted_proxy( $remote ) {
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
	 * IP in CIDR matcher supporting IPv4/IPv6.
	 *
	 * @param string $ip IP address.
	 * @param string $cidr CIDR or exact IP.
	 * @return bool
	 */
	private function ip_matches_cidr( $ip, $cidr ) {
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

	/**
	 * Record attempt in debug transient ring buffer.
	 *
	 * @param string $status Status (accepted/rejected/error).
	 * @param string $reason Reason code.
	 * @param array  $context Safe context.
	 * @return void
	 */
	private function record_attempt( $status, $reason, $context = array() ) {
		if ( ! $this->is_debug_enabled() ) {
			return;
		}

		$entry = array(
			'time'           => time(),
			'status'         => sanitize_key( $status ),
			'reason'         => sanitize_key( $reason ),
			'event_id'       => isset( $context['event_id'] ) ? sanitize_text_field( (string) $context['event_id'] ) : '',
			'wa_target_type' => isset( $context['wa_target_type'] ) ? sanitize_text_field( (string) $context['wa_target_type'] ) : '',
			'wa_target_path' => isset( $context['wa_target_path'] ) ? sanitize_text_field( (string) $context['wa_target_path'] ) : '',
			'page_path'      => isset( $context['page_path'] ) ? sanitize_text_field( (string) $context['page_path'] ) : '',
		);

		$attempts = get_transient( self::ATTEMPTS_TRANSIENT );
		if ( ! is_array( $attempts ) ) {
			$legacy   = get_option( 'clicutcl_attempts', array() );
			$attempts = is_array( $legacy ) ? $legacy : array();
		}

		$max = (int) apply_filters( 'clicutcl_diag_attempt_buffer_size', 20 );
		$max = max( 1, min( 200, $max ) );

		array_unshift( $attempts, $entry );
		$attempts = array_slice( $attempts, 0, $max );

		$ttl = (int) apply_filters( 'clicutcl_diag_buffer_ttl', 6 * HOUR_IN_SECONDS );
		$ttl = max( HOUR_IN_SECONDS, $ttl );
		set_transient( self::ATTEMPTS_TRANSIENT, $attempts, $ttl );
	}

	/**
	 * Check if debug logging is enabled.
	 *
	 * @return bool
	 */
	private function is_debug_enabled() {
		$until = get_transient( 'clicutcl_debug_until' );
		return $until && (int) $until > time();
	}

	/**
	 * Persist last error for diagnostics.
	 *
	 * @param string $code Error code.
	 * @param string $message Error message.
	 * @return void
	 */
	private function record_last_error( $code, $message ) {
		$entry = array(
			'code'    => sanitize_key( $code ),
			'message' => sanitize_text_field( $message ),
			'time'    => time(),
		);

		$existing = get_transient( self::LAST_ERROR_TRANSIENT );
		if (
			is_array( $existing ) &&
			( $existing['code'] ?? '' ) === $entry['code'] &&
			( $existing['message'] ?? '' ) === $entry['message'] &&
			( (int) ( $existing['time'] ?? 0 ) + 30 ) > time()
		) {
			return;
		}

		$ttl = (int) apply_filters( 'clicutcl_diag_last_error_ttl', DAY_IN_SECONDS );
		$ttl = max( 300, $ttl );
		set_transient( self::LAST_ERROR_TRANSIENT, $entry, $ttl );
	}
}
