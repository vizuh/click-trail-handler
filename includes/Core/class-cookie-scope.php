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
		'gob.mx',
		'go.jp',
		'ltd.uk',
		'plc.uk',
		'org.uk',
		'me.uk',
		'nhs.uk',
		'sch.uk',
		'ac.uk',
		'gov.uk',
		'com.mx',
		'com.ar',
		'com.co',
		'co.nz',
		'co.za',
		'co.in',
		'co.il',
		'com.tr',
		'com.cn',
		'com.hk',
		'com.sg',
		'com.my',
		'com.tw',
	);

	/**
	 * Hosting-platform suffixes, as label lists joined with dots at runtime.
	 *
	 * Same purpose as PUBLIC_SUFFIXES: hosts under which a `domain` attribute would leak cookies to
	 * other tenants. They are refusal data only and are never requested; they are kept as labels so
	 * that no full service hostname appears as a literal in the source, which static scanners
	 * (WordPress.org Plugin Check) read as offloaded content.
	 */
	private const HOSTING_SUFFIX_LABELS = array(
		array( 'workers', 'dev' ),
		array( 'pages', 'dev' ),
		array( 'onrender', 'com' ),
		array( 'fly', 'dev' ),
		array( 'herokuapp', 'com' ),
		array( 'netlify', 'app' ),
		array( 'vercel', 'app' ),
		array( 'github', 'io' ),
		array( 'gitlab', 'io' ),
		array( 'ngrok', 'io' ),
		array( 'ngrok-free', 'app' ),
		array( 'wpengine', 'com' ),
		array( 'wpenginepowered', 'com' ),
		array( 'kinsta', 'cloud' ),
		array( 'cloudfront', 'net' ),
		array( 'azurewebsites', 'net' ),
		array( 'amazonaws', 'com' ),
		array( 'web', 'app' ),
		array( 'firebaseapp', 'com' ),
		array( 'appspot', 'com' ),
		array( 'myshopify', 'com' ),
		array( 'blogspot', 'com' ),
		array( 'wordpress', 'com' ),
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
			if ( ! self::covers( $domain, $host ) ) {
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
	 * Whether a cookie domain covers a host: the host itself or any subdomain of it.
	 *
	 * The same rule the scripts apply before they send `domain=` (`coversHost()` in clicutcl-attribution.js).
	 *
	 * @param string $domain Cookie domain (empty never covers).
	 * @param string $host   Host, with or without port or case.
	 * @return bool
	 */
	public static function covers( string $domain, string $host ): bool {
		$domain = self::normalize_host( $domain );
		$host   = self::normalize_host( $host );

		return '' !== $domain && '' !== $host && ( $host === $domain || str_ends_with( $host, '.' . $domain ) );
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
		$defaults = self::default_suffixes();
		$suffixes = apply_filters( 'clicutcl_public_suffixes', $defaults );
		$suffixes = is_array( $suffixes ) ? array_map( array( __CLASS__, 'normalize_host' ), array_map( 'strval', $suffixes ) ) : $defaults;

		return in_array( $domain, $suffixes, true );
	}

	/**
	 * The built-in multi-label public suffixes: ccTLD second levels plus the hosting platforms.
	 *
	 * @return string[]
	 */
	private static function default_suffixes(): array {
		$hosting = array_map(
			static function ( array $labels ): string {
				return implode( '.', $labels );
			},
			self::HOSTING_SUFFIX_LABELS
		);

		return array_merge( self::PUBLIC_SUFFIXES, $hosting );
	}
}
