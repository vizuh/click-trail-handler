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

		$timestamp = $request->get_header( 'x-clicutcl-timestamp' );
		$signature = $request->get_header( 'x-clicutcl-signature' );
		$timestamp = sanitize_text_field( (string) $timestamp );
		$signature = sanitize_text_field( (string) $signature );

		if ( '' === $timestamp || '' === $signature ) {
			return new WP_Error( 'webhook_signature_missing', 'Missing webhook signature headers', array( 'status' => 401 ) );
		}

		if ( ! ctype_digit( $timestamp ) ) {
			return new WP_Error( 'webhook_timestamp_invalid', 'Invalid webhook timestamp', array( 'status' => 401 ) );
		}

		$drift = abs( time() - (int) $timestamp );
		$settings_max = Settings::get()['security']['webhook_replay_window'] ?? 300;
		$max   = (int) apply_filters( 'clicutcl_webhook_replay_window', (int) $settings_max );
		$max   = max( 60, min( 3600, $max ) );

		if ( $drift > $max ) {
			return new WP_Error( 'webhook_timestamp_expired', 'Webhook timestamp expired', array( 'status' => 401 ) );
		}

		$body      = (string) $request->get_body();
		$message   = $timestamp . '.' . $body;
		$expected  = hash_hmac( 'sha256', $message, $secret );

		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'webhook_signature_invalid', 'Invalid webhook signature', array( 'status' => 401 ) );
		}

		return true;
	}
}
