<?php
/**
 * Server re-issue of the attribution cookie (Safari ITP).
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Core;

use CLICUTCL\Settings\Attribution_Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Echoes the request's own, validated `attribution` cookie back as an HTTP `Set-Cookie`.
 *
 * A cookie created with `document.cookie` is shortened or purged by Safari ITP; the same cookie set in an
 * HTTP response is not. This class never creates data: the value is the one the browser just sent, byte for
 * byte, and it is only emitted from the REST route `POST /clicutcl/v2/cookie-refresh`. A page response must
 * never carry it (a response that sets a cookie is not cached by CDNs).
 */
class Attribution_Cookie_Reissue {

	/**
	 * Cookie name; the script writes the same name (`CONFIG.cookieName`).
	 */
	public const COOKIE_NAME = 'attribution';

	/**
	 * Largest encodeURIComponent()-encoded value; mirrors MAX_COOKIE_VALUE_BYTES in clicutcl-attribution.js.
	 */
	public const MAX_ENCODED_BYTES = 3800;

	/**
	 * Most keys a stored attribution object carries.
	 */
	private const MAX_KEYS = 128;

	/**
	 * Longest key, as sanitizeKey() in the script.
	 */
	private const MAX_KEY_LENGTH = 64;

	/**
	 * Longest string value, as sanitizeValue() in the script.
	 */
	private const MAX_VALUE_LENGTH = 256;

	/**
	 * Validate a raw cookie value against the schema the script writes.
	 *
	 * @param string $raw Decoded cookie value.
	 * @return string Empty when valid, otherwise a reason code.
	 */
	public static function inspect( string $raw ): string {
		if ( '' === $raw ) {
			return 'empty';
		}
		if ( strlen( self::encode( $raw ) ) > self::MAX_ENCODED_BYTES ) {
			return 'too_large';
		}

		$data = json_decode( $raw, false, 4 );
		if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			return 'not_json';
		}
		if ( ! is_object( $data ) ) {
			return 'not_object';
		}

		$fields = get_object_vars( $data );
		if ( array() === $fields ) {
			return 'not_object';
		}
		if ( count( $fields ) > self::MAX_KEYS ) {
			return 'too_many_keys';
		}

		foreach ( $fields as $key => $value ) {
			if ( 1 !== preg_match( '/^[a-z0-9_]{1,' . self::MAX_KEY_LENGTH . '}$/', (string) $key ) ) {
				return 'bad_key';
			}
			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				continue;
			}
			if ( ! is_string( $value ) || mb_strlen( $value, 'UTF-8' ) > self::MAX_VALUE_LENGTH || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
				return 'bad_value';
			}
			// The script never writes an unsubstituted ad-platform macro such as {{campaign.name}}.
			if ( 1 === preg_match( '/^\{\{.+\}\}$/', $value ) ) {
				return 'bad_value';
			}
		}

		return '';
	}

	/**
	 * Encode like JavaScript encodeURIComponent(), so the cookie bytes equal what the script writes.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function encode( string $value ): string {
		return strtr(
			rawurlencode( $value ),
			array(
				'%21' => '!',
				'%27' => "'",
				'%28' => '(',
				'%29' => ')',
				'%2A' => '*',
			)
		);
	}

	/**
	 * Build the `Set-Cookie` header line.
	 *
	 * Same name, path, `SameSite` and `Domain` as the script writer; never HttpOnly (the script must read and expire it).
	 *
	 * @param string $raw     Validated raw cookie value.
	 * @param int    $max_age Lifetime in seconds.
	 * @param string $domain  Cookie domain (empty means host-only).
	 * @param bool   $secure  Whether to add `Secure`.
	 * @return string
	 */
	public static function build_header( string $raw, int $max_age, string $domain, bool $secure ): string {
		// Checked here, at the sink: the domain comes from settings and the request host.
		if ( '' !== $domain && 1 !== preg_match( '/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $domain ) ) {
			$domain = '';
		}

		$line = 'Set-Cookie: ' . self::COOKIE_NAME . '=' . self::encode( $raw ) . '; Max-Age=' . max( 1, $max_age ) . '; Path=/';
		if ( '' !== $domain ) {
			$line .= '; Domain=' . $domain;
		}
		$line .= '; SameSite=Lax';
		if ( $secure ) {
			$line .= '; Secure';
		}

		return $line;
	}

	/**
	 * Validate the request cookie and, when valid, append the `Set-Cookie` header to this response.
	 *
	 * Consent is checked by the route before this runs. Only the REST route calls this method.
	 *
	 * The server cannot see whether the script's `domain=` probe fell back to host-only, so the script reports the domain it
	 * actually applied (`$domain_applied`). `Domain` is sent only when that equals the configured scope domain and covers the
	 * request host; anything else (empty, absent, garbage) gets a host-only cookie, which replaces the script's host-only cookie
	 * instead of sitting next to it as a second same-name cookie.
	 *
	 * @param string $request_host   Host header of the request (decides whether the cookie domain covers it).
	 * @param mixed  $domain_applied Domain the script wrote with (`''` for host-only), as sent by the client.
	 * @param mixed  $expires_in     Seconds the script's own cookie has left (a cookie restored from the mirror), or null for the full retention.
	 * @return array{issued:bool,reason:string}
	 */
	public static function issue( string $request_host, $domain_applied = null, $expires_in = null ): array {
		if ( ! isset( $_COOKIE[ self::COOKIE_NAME ] ) || ! is_string( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return self::result( false, 'no_cookie' );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated against a strict schema by inspect() and only echoed back encoded.
		$raw    = wp_unslash( $_COOKIE[ self::COOKIE_NAME ] );
		$reason = self::inspect( $raw );
		if ( '' !== $reason ) {
			return self::result( false, $reason );
		}

		$options = Attribution_Settings::get_all();
		$days    = isset( $options['cookie_days'] ) ? absint( $options['cookie_days'] ) : 90;
		if ( $days < 1 ) {
			return self::result( false, 'retention_disabled' );
		}

		if ( headers_sent() ) {
			return self::result( false, 'headers_sent' );
		}

		// The script's own rule: the localized domain applies only when it covers the host being served.
		$domain = Cookie_Scope::resolve_for_site( $options )['domain'];
		if ( ! Cookie_Scope::covers( $domain, $request_host ) || ! is_string( $domain_applied ) || Cookie_Scope::normalize_host( $domain_applied ) !== $domain ) {
			$domain = '';
		}

		// Never longer than the retention; a restored cookie keeps what the mirror had left.
		$max_age = $days * DAY_IN_SECONDS;
		if ( ( is_int( $expires_in ) || is_float( $expires_in ) || ( is_string( $expires_in ) && is_numeric( $expires_in ) ) ) && (int) $expires_in >= 1 ) {
			$max_age = min( $max_age, (int) $expires_in );
		}
		$secure = is_ssl() || 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );

		header( 'Cache-Control: no-store, private', true );
		// Append, never replace: another plugin's Set-Cookie on this response must survive.
		header( self::build_header( $raw, $max_age, $domain, $secure ), false );

		return self::result( true, 'ok' );
	}

	/**
	 * Result shape.
	 *
	 * @param bool   $issued Whether a header was emitted.
	 * @param string $reason Reason code.
	 * @return array{issued:bool,reason:string}
	 */
	private static function result( bool $issued, string $reason ): array {
		return array(
			'issued' => $issued,
			'reason' => $reason,
		);
	}
}
