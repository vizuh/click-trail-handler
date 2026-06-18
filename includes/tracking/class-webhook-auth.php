<?php
/**
 * Webhook auth utilities.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Tracking;

use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Webhook_Auth
 */
class Webhook_Auth {
	/**
	 * Verify webhook signature and timestamp.
	 *
	 * Expected signature: hex(HMAC_SHA256("timestamp.raw_body", secret)).
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $secret  Shared secret.
	 * @return true|WP_Error
	 */
	public static function verify_request( WP_REST_Request $request, string $secret ) {
		$secret = trim( $secret );
		if ( '' === $secret ) {
			return new WP_Error( 'webhook_secret_missing', 'Webhook secret is not configured', array( 'status' => 401 ) );
		}

		// Do not run signature/timestamp through sanitize_text_field: it would mutate the
		// attacker-controlled value before the constant-time compare. Trim only, then
		// validate the exact expected shape (digits / lowercase hex) before hash_equals.
		$timestamp = trim( (string) $request->get_header( 'x-clicutcl-timestamp' ) );
		$signature = trim( (string) $request->get_header( 'x-clicutcl-signature' ) );

		if ( '' === $timestamp || '' === $signature ) {
			return new WP_Error( 'webhook_signature_missing', 'Missing webhook signature headers', array( 'status' => 401 ) );
		}

		if ( ! ctype_digit( $timestamp ) ) {
			return new WP_Error( 'webhook_timestamp_invalid', 'Invalid webhook timestamp', array( 'status' => 401 ) );
		}

		// Expected signature is hex(HMAC_SHA256(...)) — reject anything not 64 lowercase hex chars.
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $signature ) ) {
			return new WP_Error( 'webhook_signature_invalid', 'Invalid webhook signature', array( 'status' => 401 ) );
		}

		$drift        = abs( time() - (int) $timestamp );
		$settings_max = Settings::get()['security']['webhook_replay_window'] ?? 300;
		$max          = (int) apply_filters( 'clicutcl_webhook_replay_window', (int) $settings_max );
		$max          = max( 60, min( 3600, $max ) );

		if ( $drift > $max ) {
			return new WP_Error( 'webhook_timestamp_expired', 'Webhook timestamp expired', array( 'status' => 401 ) );
		}

		$body     = (string) $request->get_body();
		$message  = $timestamp . '.' . $body;
		$expected = hash_hmac( 'sha256', $message, $secret );

		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'webhook_signature_invalid', 'Invalid webhook signature', array( 'status' => 401 ) );
		}

		$enforce_replay = (bool) apply_filters( 'clicutcl_webhook_replay_protection', true, $request );
		if ( $enforce_replay ) {
			$replay_key = 'clicutcl_wh_replay_' . md5( $timestamp . '|' . $signature . '|' . $request->get_route() );

			if ( wp_using_ext_object_cache() ) {
				// Persistent cache present: wp_cache_add() is an atomic claim that returns
				// false if the key already exists, closing the check-then-set race.
				if ( ! wp_cache_add( $replay_key, 1, 'clicutcl_webhook', $max ) ) {
					return new WP_Error( 'webhook_replay_detected', 'Webhook replay detected', array( 'status' => 409 ) );
				}
			} else {
				// No persistent object cache: transients are DB-backed (durable, not evicted).
				if ( get_transient( $replay_key ) ) {
					return new WP_Error( 'webhook_replay_detected', 'Webhook replay detected', array( 'status' => 409 ) );
				}
				set_transient( $replay_key, 1, $max );
			}
		}

		return true;
	}
}
