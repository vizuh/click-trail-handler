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
		} elseif ( 'hubspot' === $provider && '' !== trim( (string) $request->get_header( 'x-hubspot-signature-v3' ) ) ) {
			// HubSpot v3: base64 HMAC-SHA256 of method + decoded URI + body + millisecond timestamp.
			// When the v3 header is present it is authoritative: a failing v3 never falls back to v1.
			$timestamp = trim( (string) $request->get_header( 'x-hubspot-request-timestamp' ) );
			$signature = trim( (string) $request->get_header( 'x-hubspot-signature-v3' ) );
			if ( ! ctype_digit( $timestamp ) || abs( (int) floor( microtime( true ) * 1000 ) - (int) $timestamp ) > $max * 1000 ) {
				return new WP_Error( 'webhook_timestamp_invalid', 'Invalid or expired webhook timestamp', array( 'status' => 401 ) );
			}
			// Accept the first candidate URI whose HMAC matches; each still requires the secret, and
			// the timestamp window and replay cache apply as for a single URI.
			$expected = '';
			foreach ( self::hubspot_request_uris( $request ) as $uri ) {
				$candidate = self::hubspot_v3_signature( $secret, (string) $request->get_method(), $uri, $body, $timestamp );
				if ( hash_equals( $candidate, $signature ) ) {
					$expected = $candidate;
					break;
				}
			}
		} elseif ( 'hubspot' === $provider ) {
			// Legacy v1 (only when no v3 header): sha256(secret . body). It carries no timestamp,
			// so only the replay cache below and event-ID dedup protect it.
			$signature = trim( (string) $request->get_header( 'x-hubspot-signature' ) );
			$expected  = hash( 'sha256', $secret . $body );
		} elseif ( 'calendly' === $provider && '' !== trim( (string) $request->get_header( 'calendly-webhook-signature' ) ) ) {
			// Native Calendly subscription: "t=<unix>,v1=<hex hmac-sha256 of t.body>".
			// Requests without this header keep using the ClickTrail relay scheme below.
			$parts = array();
			foreach ( explode( ',', (string) $request->get_header( 'calendly-webhook-signature' ) ) as $pair ) {
				$pair = explode( '=', trim( $pair ), 2 );
				if ( 2 === count( $pair ) ) {
					$parts[ $pair[0] ] = $pair[1];
				}
			}
			$timestamp = (string) ( $parts['t'] ?? '' );
			$signature = (string) ( $parts['v1'] ?? '' );
			if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > $max ) {
				return new WP_Error( 'webhook_timestamp_invalid', 'Invalid or expired webhook timestamp', array( 'status' => 401 ) );
			}
			$expected = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
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

	/**
	 * HubSpot v3 signature: base64(HMAC-SHA256(secret, METHOD + URI + body + timestamp)).
	 *
	 * @param string $secret    App client secret.
	 * @param string $method    HTTP method.
	 * @param string $uri       Full request URL with HubSpot's listed characters decoded.
	 * @param string $body      Raw request body.
	 * @param string $timestamp X-HubSpot-Request-Timestamp (milliseconds).
	 * @return string
	 */
	public static function hubspot_v3_signature( string $secret, string $method, string $uri, string $body, string $timestamp ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HubSpot v3 requires a base64-encoded HMAC.
		return base64_encode( hash_hmac( 'sha256', strtoupper( $method ) . $uri . $body . $timestamp, $secret, true ) );
	}

	/**
	 * Candidate URLs HubSpot may have signed: https:// + Host header + raw request URI, and
	 * https:// + the home_url() host + raw request URI when a proxy rewrote Host. HubSpot only
	 * delivers to HTTPS target URLs, so the scheme is fixed rather than read from is_ssl(), which
	 * is false behind a TLS-terminating proxy. X-Forwarded-* headers are not read. A proxy that
	 * rewrites the path needs the clicutcl_hubspot_v3_request_uris filter.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string[]
	 */
	private static function hubspot_request_uris( WP_REST_Request $request ): array {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- HMAC input only; sanitizing would alter the signed bytes.
		$host = isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$home      = wp_parse_url( home_url() );
		$home_host = is_array( $home ) && ! empty( $home['host'] ) ? $home['host'] . ( ! empty( $home['port'] ) ? ':' . $home['port'] : '' ) : '';

		$uris = array();
		if ( '' !== $path ) {
			foreach ( array( $host, $home_host ) as $candidate_host ) {
				if ( '' !== $candidate_host ) {
					$uris[] = 'https://' . $candidate_host . $path;
				}
			}
		}

		/**
		 * Filter the URLs tried for HubSpot v3 verification, for proxies that rewrite the path.
		 * Supply the public target URL configured in HubSpot. Each still has to match the HMAC.
		 *
		 * @param string[]        $uris    Candidate URLs.
		 * @param WP_REST_Request $request Request.
		 */
		$uris = apply_filters( 'clicutcl_hubspot_v3_request_uris', $uris, $request );
		$uris = is_array( $uris ) ? array_filter( $uris, static fn( $uri ) => is_string( $uri ) && '' !== $uri ) : array();

		return array_values(
			array_unique(
				array_map(
					static fn( string $uri ): string => str_ireplace(
						array( '%3A', '%2F', '%3F', '%40', '%21', '%24', '%27', '%28', '%29', '%2A', '%2C', '%3B' ),
						array( ':', '/', '?', '@', '!', '$', "'", '(', ')', '*', ',', ';' ),
						$uri
					),
					$uris
				)
			)
		);
	}
}
