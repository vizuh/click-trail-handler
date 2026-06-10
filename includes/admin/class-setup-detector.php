<?php
/**
 * Setup Detector
 *
 * Detects the active WordPress environment for the Easy Mode setup wizard.
 * Consolidates all environment-probing logic into one place so the wizard,
 * the setup checklist, and future onboarding surfaces share a single source
 * of truth.
 *
 * All checks are synchronous PHP constant / class / option reads — no HTTP
 * requests, no database queries beyond `get_option('active_plugins')`.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Setup_Detector
 *
 * Usage:
 *   $env = Setup_Detector::run();
 *   // $env['forms']       — array of all supported form plugins with active flag
 *   // $env['woocommerce'] — bool
 *   // $env['caching']     — array of detected caching/CDN labels
 *   // $env['cmp']         — array of detected CMP plugins (key, label, cmp_source)
 *   // $env['call_tracking'] — array of detected call-tracking tool labels
 */
class Setup_Detector {

	/**
	 * Run the full environment detection and return a structured result.
	 *
	 * @return array<string,mixed>
	 */
	public static function run(): array {
		$forms         = static::detect_forms();
		$caching       = static::detect_caching();
		$cmp           = static::detect_cmp();
		$call_tracking = static::detect_call_tracking();

		return array(
			'forms'             => $forms,
			'woocommerce'       => static::detect_woocommerce(),
			'caching'           => $caching,
			'cmp'               => $cmp,
			'call_tracking'     => $call_tracking,
			// Convenience booleans for template conditionals.
			'has_active_forms'  => ! empty(
				array_filter( $forms, static fn( $f ) => $f['active'] )
			),
			'has_caching'       => ! empty( $caching ),
			'has_cmp'           => ! empty( $cmp ),
			'has_call_tracking' => ! empty( $call_tracking ),
		);
	}

	// -----------------------------------------------------------------------
	// Form plugins
	// -----------------------------------------------------------------------

	/**
	 * Detect supported form plugins.
	 *
	 * Returns every supported builder with an `active` flag so the wizard can
	 * show a full checklist rather than only the detected ones.
	 *
	 * Detection mirrors each adapter's own `is_active()` method exactly so the
	 * wizard and the runtime are always in sync.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function detect_forms(): array {
		return array(
			array(
				'key'    => 'cf7',
				'label'  => 'Contact Form 7',
				'active' => class_exists( 'WPCF7' ),
			),
			array(
				'key'    => 'gf',
				'label'  => 'Gravity Forms',
				'active' => class_exists( 'GFForms' ),
			),
			array(
				'key'    => 'wpforms',
				'label'  => 'WPForms',
				'active' => class_exists( 'WPForms' ),
			),
			array(
				'key'    => 'elementor',
				'label'  => 'Elementor Forms',
				'active' => defined( 'ELEMENTOR_PRO_VERSION' ) || class_exists( '\ElementorPro\Modules\Forms\Module' ),
			),
			array(
				'key'    => 'fluent',
				'label'  => 'Fluent Forms',
				'active' => defined( 'FLUENTFORM' ) || class_exists( '\FluentForm\Framework\Foundation\Application' ),
			),
			array(
				'key'    => 'ninja',
				'label'  => 'Ninja Forms',
				'active' => class_exists( 'Ninja_Forms' ),
			),
		);
	}

	/**
	 * Return only the active form plugin labels — useful for wizard copy.
	 *
	 * @return array<int,string>
	 */
	public static function active_form_labels(): array {
		return array_values(
			array_map(
				static fn( $f ) => $f['label'],
				array_filter(
					static::detect_forms(),
					static fn( $f ) => $f['active']
				)
			)
		);
	}

	// -----------------------------------------------------------------------
	// WooCommerce
	// -----------------------------------------------------------------------

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	public static function detect_woocommerce(): bool {
		return class_exists( 'WooCommerce' );
	}

	// -----------------------------------------------------------------------
	// Caching and CDN
	// -----------------------------------------------------------------------

	/**
	 * Detect active caching, optimisation, and CDN layers.
	 *
	 * Returns an array of label strings for detected tools — same logic as
	 * `detect_cache_conflict_labels()` in the diagnostics trait, kept in sync.
	 *
	 * @return array<int,string>
	 */
	public static function detect_caching(): array {
		$found = array();

		if ( defined( 'WP_ROCKET_VERSION' ) ) {
			$found[] = 'WP Rocket';
		}
		if ( defined( 'LSCWP_V' ) ) {
			$found[] = 'LiteSpeed Cache';
		}
		if ( defined( 'WPCACHEHOME' ) ) {
			$found[] = 'WP Super Cache';
		}
		if ( defined( 'AUTOPTIMIZE_PLUGIN_VERSION' ) ) {
			$found[] = 'Autoptimize';
		}
		if ( defined( 'SiteGround_Optimizer\VERSION' ) || defined( 'SITEGROUND_OPTIMIZER_VERSION' ) ) {
			$found[] = 'SiteGround Optimizer';
		}
		if ( defined( 'W3TC_VERSION' ) ) {
			$found[] = 'W3 Total Cache';
		}

		$wpe_api_key = filter_input( INPUT_SERVER, 'WPE_APIKEY', FILTER_UNSAFE_RAW );
		if ( defined( 'WPE_APIKEY' ) || ! empty( $wpe_api_key ) ) {
			$found[] = 'WP Engine';
		}

		$cf_ray = filter_input( INPUT_SERVER, 'HTTP_CF_RAY', FILTER_UNSAFE_RAW );
		if ( ! empty( $cf_ray ) ) {
			$found[] = 'Cloudflare';
		}

		return array_values( array_unique( $found ) );
	}

	// -----------------------------------------------------------------------
	// Consent Management Platforms
	// -----------------------------------------------------------------------

	/**
	 * Detect active CMP plugins.
	 *
	 * Returns an array of CMP descriptors. Each entry includes:
	 *  - key        — the internal ClickTrail cmp_source key
	 *  - label      — human-readable plugin name
	 *  - cmp_source — the value to write to Consent_Mode_Settings cmp_source
	 *
	 * At most one entry will typically be present; multiple entries are
	 * possible on misconfigured sites.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function detect_cmp(): array {
		$found          = array();
		$active_plugins = (array) get_option( 'active_plugins', array() );

		// CookieYes / Cookie Law Info (two successive plugin slugs, same vendor).
		$cookieyes_slugs = array( 'cookieyes-gdpr-cookie-consent', 'cookie-law-info' );
		foreach ( $active_plugins as $plugin_path ) {
			$plugin_dir = strtolower( (string) explode( '/', (string) $plugin_path )[0] );
			if ( in_array( $plugin_dir, $cookieyes_slugs, true ) ) {
				$found[] = array(
					'key'        => 'cookieyes',
					'label'      => 'CookieYes',
					'cmp_source' => 'plugin',
				);
				break;
			}
		}

		// Cookiebot (Usercentrics).
		if ( defined( 'COOKIEBOT_VERSION' ) || class_exists( 'Cookiebot_WP' ) ) {
			$found[] = array(
				'key'        => 'cookiebot',
				'label'      => 'Cookiebot',
				'cmp_source' => 'cookiebot',
			);
		}

		// OneTrust — no PHP constant; detect by active plugin slug.
		foreach ( $active_plugins as $plugin_path ) {
			$plugin_dir = strtolower( (string) explode( '/', (string) $plugin_path )[0] );
			if ( str_contains( $plugin_dir, 'onetrust' ) ) {
				$found[] = array(
					'key'        => 'onetrust',
					'label'      => 'OneTrust',
					'cmp_source' => 'onetrust',
				);
				break;
			}
		}

		// Complianz.
		if ( defined( 'CMPLZ_VERSION' ) || class_exists( 'CMPLZ_GDPR' ) ) {
			$found[] = array(
				'key'        => 'complianz',
				'label'      => 'Complianz',
				'cmp_source' => 'complianz',
			);
		}

		// Deduplicate by cmp_source key in case a plugin matches on multiple paths.
		$seen   = array();
		$unique = array();
		foreach ( $found as $entry ) {
			if ( ! isset( $seen[ $entry['cmp_source'] ] ) ) {
				$seen[ $entry['cmp_source'] ] = true;
				$unique[]                     = $entry;
			}
		}

		return $unique;
	}

	/**
	 * Return the best cmp_source value to pre-fill in Consent_Mode_Settings.
	 *
	 * When exactly one CMP is detected, return its source key so the wizard can
	 * auto-select it. Falls back to 'auto' when zero or multiple CMPs are found.
	 *
	 * @return string
	 */
	public static function suggested_cmp_source(): string {
		$detected = static::detect_cmp();
		if ( 1 === count( $detected ) ) {
			return $detected[0]['cmp_source'];
		}
		return 'auto';
	}

	// -----------------------------------------------------------------------
	// Call tracking tools
	// -----------------------------------------------------------------------

	/**
	 * Detect active call-tracking tools.
	 *
	 * Returns an array of label strings — same logic as
	 * `detect_call_tracking_labels()` in the diagnostics trait.
	 *
	 * @return array<int,string>
	 */
	public static function detect_call_tracking(): array {
		$found          = array();
		$active_plugins = (array) get_option( 'active_plugins', array() );

		$known_slugs = array(
			'callrail'               => 'CallRail',
			'call-tracking-metrics'  => 'CallTrackingMetrics',
			'whatconverts'           => 'WhatConverts',
			'retreaver'              => 'Retreaver',
			'infinity-call-tracking' => 'Infinity',
		);

		foreach ( $active_plugins as $plugin_path ) {
			$plugin_path_lower = strtolower( (string) $plugin_path );
			foreach ( $known_slugs as $slug => $label ) {
				if ( str_contains( $plugin_path_lower, $slug ) ) {
					$found[] = $label;
				}
			}
		}

		if ( defined( 'CALLRAIL_PLUGIN_VERSION' ) ) {
			$found[] = 'CallRail';
		}
		if ( defined( 'CTM_PLUGIN_VERSION' ) ) {
			$found[] = 'CallTrackingMetrics';
		}

		return array_values( array_unique( $found ) );
	}
}
