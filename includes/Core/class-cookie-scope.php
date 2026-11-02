<?php
/**
 * Cookie scope resolver (host-only vs registrable domain).
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the `domain` attribute every browser cookie writer should use.
 *
 * One answer, computed in PHP, localized to all front-end scripts as
 * `cookieDomain`. An empty domain means host-only (today's behaviour).
 */
class Cookie_Scope {

	public const SCOPE_HOST        = 'host';
	public const SCOPE_REGISTRABLE = 'registrable';
	public const SCOPE_CUSTOM      = 'custom';

	/**
	 * Second-level labels that make a three-label registrable domain
	 * (port of getRegistrableDomain() in clicutcl-attribution.js).
	 */
	private const SECOND_LEVEL_LABELS = array( 'co', 'com', 'net', 'org', 'gov', 'edu', 'ac', 'ne', 'or', 'me' );

	/**
	 * Multi-label suffixes under which a `domain` attribute would leak cookies to sibling tenants.
	 */
	private const PUBLIC_SUFFIXES = array(
		'github.io',
		'gitlab.io',
		'herokuapp.com',
		'netlify.app',
		'vercel.app',
		'pages.dev',
		'wpengine.com',
		'wpenginepowered.com',
		'kinsta.cloud',
		'cloudfront.net',
		'azurewebsites.net',
		'amazonaws.com',
		'web.app',
		'firebaseapp.com',
		'appspot.com',
		'myshopify.com',
		'blogspot.com',
		'wordpress.com',
	);

	/**
	 * Allowed scope values.
	 *
	 * @return string[]
	 */
	public static function scopes(): array {
		return array( self::SCOPE_HOST, self::SCOPE_REGISTRABLE, self::SCOPE_CUSTOM );
	}

	/**
	 * Normalize a host or domain: lowercase, no port, no leading or trailing dot.
	 *
	 * @param string $host Raw host.
	 * @return string
	 */
	public static function normalize_host( string $host ): string {
		$host = strtolower( trim( $host ) );
		if ( '[' !== substr( $host, 0, 1 ) ) {
			$host = (string) preg_replace( '/:\d+$/', '', $host );
		}
		return trim( $host, '.' );
	}

	/**
	 * Resolve the cookie domain.
	 *
	 * @param string $scope  host|registrable|custom.
	 * @param string $custom Custom domain (used for `custom`).
	 * @param string $host   Site host (from home_url()).
	 * @return array{domain:string,reason:string}
	 */
	public static function resolve( string $scope, string $custom, string $host ): array {
		if ( self::SCOPE_REGISTRABLE !== $scope && self::SCOPE_CUSTOM !== $scope ) {
			return array(
				'domain' => '',
				'reason' => 'host_scope',
			);
		}

		$host = self::normalize_host( $host );
		if ( '' === $host || false !== strpos( $host, ':' ) || false !== strpos( $host, '[' ) || false !== filter_var( $host, FILTER_VALIDATE_IP ) || false === strpos( $host, '.' ) ) {
			return array(
				'domain' => '',
				'reason' => 'ip_or_single_label',
			);
		}

		if ( self::SCOPE_CUSTOM === $scope ) {
			$domain = self::normalize_host( $custom );
			$covers = '' !== $domain && ( $host === $domain || str_ends_with( $host, '.' . $domain ) );
			if ( ! $covers ) {
				return array(
					'domain' => '',
					'reason' => 'custom_not_suffix',
				);
			}
			if ( false === strpos( $domain, '.' ) || self::is_public_suffix( $domain ) ) {
				return array(
					'domain' => '',
					'reason' => 'public_suffix',
				);
			}
			return array(
				'domain' => $domain,
				'reason' => 'ok',
			);
		}

		$domain = self::registrable_domain( $host );
		if ( self::is_public_suffix( $domain ) ) {
			return array(
				'domain' => '',
				'reason' => 'public_suffix',
			);
		}

		return array(
			'domain' => $domain,
			'reason' => 'ok',
		);
	}

	/**
	 * Resolve from the stored attribution settings and the current site host.
	 *
	 * @param array $options clicutcl_attribution_settings.
	 * @return array{domain:string,reason:string}
	 */
	public static function resolve_for_site( array $options ): array {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		return self::resolve(
			isset( $options['cookie_scope'] ) ? (string) $options['cookie_scope'] : self::SCOPE_HOST,
			isset( $options['cookie_custom_domain'] ) ? (string) $options['cookie_custom_domain'] : '',
			$host
		);
	}

	/**
	 * Registrable domain of a host (port of the decorator heuristic).
	 *
	 * @param string $host Normalized host.
	 * @return string
	 */
	public static function registrable_domain( string $host ): string {
		$parts = explode( '.', $host );
		$count = count( $parts );
		if ( $count <= 2 ) {
			return $host;
		}
		if ( in_array( $parts[ $count - 2 ], self::SECOND_LEVEL_LABELS, true ) ) {
			return implode( '.', array_slice( $parts, -3 ) );
		}
		return implode( '.', array_slice( $parts, -2 ) );
	}

	/**
	 * Whether a domain is a public suffix a cookie must never be scoped to.
	 *
	 * @param string $domain Normalized domain.
	 * @return bool
	 */
	public static function is_public_suffix( string $domain ): bool {
		if ( false === strpos( $domain, '.' ) ) {
			return true;
		}

		$parts = explode( '.', $domain );
		if ( 2 === count( $parts ) && in_array( $parts[0], self::SECOND_LEVEL_LABELS, true ) ) {
			return true; // co.uk, com.br, org.au ...
		}

		/**
		 * Filter the multi-label public suffixes ClickTrail refuses to scope cookies to.
		 *
		 * @since 1.13.0
		 *
		 * @param string[] $suffixes Lowercase suffixes, e.g. `github.io`.
		 */
		$suffixes = apply_filters( 'clicutcl_public_suffixes', self::PUBLIC_SUFFIXES );
		$suffixes = is_array( $suffixes ) ? array_map( array( __CLASS__, 'normalize_host' ), array_map( 'strval', $suffixes ) ) : self::PUBLIC_SUFFIXES;

		return in_array( $domain, $suffixes, true );
	}
}
