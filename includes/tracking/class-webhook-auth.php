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
	 * Verify the provider's native webhook signature.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $secret  Shared secret.
	 * @param string          $provider Provider key.
	 * @return true|WP_Error
	 */
	public static function verify_request( WP_REST_Request $request, string $secret, string $provider = '' ) {
		$secret = trim( $secret );
		if ( '' === $secret ) {
			return new WP_Error( 'webhook_secret_missing', 'Webhook secret is not configured', array( 'status' => 401 ) );
		}

		$provider     = sanitize_key( $provider );
		$body         = (string) $request->get_body();
		$settings_max = class_exists( Settings::class ) ? ( Settings::get()['security']['webhook_replay_window'] ?? 300 ) : 300;
		$max          = (int) apply_filters( 'clicutcl_webhook_replay_window', (int) $settings_max );
		$max          = max( 60, min( 3600, $max ) );
		$signature    = '';

		if ( 'typeform' === $provider ) {
			$signature = trim( (string) $request->get_header( 'typeform-signature' ) );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Typeform requires a base64-encoded HMAC.
			$expected = 'sha256=' . base64_encode( hash_hmac( 'sha256', $body, $secret, true ) );
		} elseif ( 'hubspot' === $provider ) {
			$signature = trim( (string) $request->get_header( 'x-hubspot-signature' ) );
			$expected  = hash( 'sha256', $secret . $body );
		} else {
			$timestamp = trim( (string) $request->get_header( 'x-clicutcl-timestamp' ) );
			$signature = trim( (string) $request->get_header( 'x-clicutcl-signature' ) );
			if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > $max ) {
				return new WP_Error( 'webhook_timestamp_invalid', 'Invalid or expired webhook timestamp', array( 'status' => 401 ) );
			}
			$expected = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
		}

		if ( '' === $signature || ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'webhook_signature_invalid', 'Invalid webhook signature', array( 'status' => 401 ) );
		}

		$enforce_replay = (bool) apply_filters( 'clicutcl_webhook_replay_protection', true, $request );
		if ( $enforce_replay ) {
			$replay_key = 'clicutcl_wh_replay_' . md5( $provider . '|' . $signature . '|' . $request->get_route() );

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
