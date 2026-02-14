<?php
/**
 * Tracking auth token helper (same-site signed token).
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Tracking;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Auth
 */
class Auth {
	/**
	 * Default token TTL in seconds.
	 */
	private const TOKEN_TTL = 7 * DAY_IN_SECONDS;

	/**
	 * Mint short-lived client token for same-site event intake.
	 *
	 * @return string
	 */
	public static function mint_client_token(): string {
		$now = time();
		$settings_ttl = Settings::get()['security']['token_ttl_seconds'] ?? self::TOKEN_TTL;
		$ttl = (int) apply_filters( 'clicutcl_v2_token_ttl', (int) $settings_ttl );
		$ttl = max( 60, min( 7 * DAY_IN_SECONDS, $ttl ) );
		$home_host = self::current_host();

		$claims = array(
			'v'     => 2,
			'iat'   => $now,
			'exp'   => $now + $ttl,
			'site'  => home_url(),
			'host'  => $home_host,
			'blog'  => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1,
			'nonce' => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'ct_', true ),
		);

		$json = wp_json_encode( $claims );
		if ( ! is_string( $json ) || '' === $json ) {
			return '';
		}

		$payload = self::base64url_encode( $json );
		$sig     = self::base64url_encode( hash_hmac( 'sha256', $payload, self::signing_key(), true ) );

		return $payload . '.' . $sig;
	}

	/**
	 * Verify client token and return claims.
	 *
	 * @param string $token Token.
	 * @return array|WP_Error
	 */
	public static function verify_client_token( string $token ) {
		$token = trim( $token );
		if ( '' === $token || false === strpos( $token, '.' ) ) {
			return new WP_Error( 'invalid_token', 'Invalid token', array( 'status' => 401 ) );
		}

		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) ) {
			return new WP_Error( 'invalid_token', 'Invalid token', array( 'status' => 401 ) );
		}

		list( $payload_b64, $sig_b64 ) = $parts;

		$expected_sig = self::base64url_encode( hash_hmac( 'sha256', $payload_b64, self::signing_key(), true ) );
		if ( ! hash_equals( $expected_sig, $sig_b64 ) ) {
			return new WP_Error( 'invalid_signature', 'Invalid token signature', array( 'status' => 401 ) );
		}

		$payload = self::base64url_decode( $payload_b64 );
		$claims  = json_decode( $payload, true );
		if ( ! is_array( $claims ) ) {
			return new WP_Error( 'invalid_token', 'Invalid token payload', array( 'status' => 401 ) );
		}

		$now = time();
		if ( empty( $claims['exp'] ) || (int) $claims['exp'] < $now ) {
			return new WP_Error( 'token_expired', 'Token expired', array( 'status' => 401 ) );
		}

		$token_host = '';
		if ( ! empty( $claims['host'] ) ) {
			$token_host = sanitize_text_field( (string) $claims['host'] );
		} elseif ( ! empty( $claims['site'] ) ) {
			$token_host = (string) wp_parse_url( (string) $claims['site'], PHP_URL_HOST );
		}

		if ( ! self::is_allowed_host( $token_host ) ) {
			return new WP_Error( 'token_site_mismatch', 'Token site mismatch', array( 'status' => 401 ) );
		}

		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		if ( isset( $claims['blog'] ) && (int) $claims['blog'] !== $blog ) {
			return new WP_Error( 'token_blog_mismatch', 'Token blog mismatch', array( 'status' => 401 ) );
		}

		return $claims;
	}

	/**
	 * Get token signing key.
	 *
	 * @return string
	 */
	private static function signing_key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ) );
	}

	/**
	 * Resolve current site host.
	 *
	 * @return string
	 */
	private static function current_host(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return strtolower( sanitize_text_field( $host ) );
	}

	/**
	 * Validate host against current site policy.
	 *
	 * @param string $token_host Host from token claim.
	 * @return bool
	 */
	private static function is_allowed_host( string $token_host ): bool {
		$token_host = strtolower( trim( $token_host ) );
		if ( '' === $token_host ) {
			return false;
		}

		$current_host = self::current_host();
		if ( '' === $current_host ) {
			return false;
		}

		if ( $token_host === $current_host ) {
			return true;
		}

		$allow_subdomains = (bool) apply_filters( 'clicutcl_v2_allow_subdomain_tokens', true, $token_host, $current_host );
		if ( ! $allow_subdomains ) {
			return false;
		}

		// Accept both directions for subdomain/root host sharing.
		if ( str_ends_with( $token_host, '.' . $current_host ) || str_ends_with( $current_host, '.' . $token_host ) ) {
			return true;
		}

		$allowed_hosts = apply_filters( 'clicutcl_v2_allowed_token_hosts', array(), $current_host );
		if ( class_exists( 'CLICUTCL\\Tracking\\Settings' ) ) {
			$settings_allowed = Settings::get()['security']['allowed_token_hosts'] ?? array();
			if ( is_array( $settings_allowed ) ) {
				$allowed_hosts = array_merge( $settings_allowed, is_array( $allowed_hosts ) ? $allowed_hosts : array() );
			}
		}
		if ( is_string( $allowed_hosts ) ) {
			$allowed_hosts = preg_split( '/[\r\n,\s]+/', $allowed_hosts );
		}
		if ( is_array( $allowed_hosts ) ) {
			foreach ( $allowed_hosts as $host ) {
				$host = strtolower( trim( sanitize_text_field( (string) $host ) ) );
				if ( '' === $host ) {
					continue;
				}
				if ( $token_host === $host ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Base64URL encode.
	 *
	 * @param string $data Data.
	 * @return string
	 */
	private static function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Base64URL decode.
	 *
	 * @param string $data Data.
	 * @return string
	 */
	private static function base64url_decode( string $data ): string {
		$remainder = strlen( $data ) % 4;
		if ( $remainder > 0 ) {
			$data .= str_repeat( '=', 4 - $remainder );
		}

		$decoded = base64_decode( strtr( $data, '-_', '+/' ), true );
		return false === $decoded ? '' : $decoded;
	}
}
