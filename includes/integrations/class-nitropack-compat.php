<?php
/**
 * NitroPack compatibility layer.
 *
 * Prevents NitroPack's "Postpone JS" feature from deferring ClickTrail
 * attribution scripts until user interaction. Without this, UTMs and click IDs
 * are not captured on pages where the user navigates away before interacting.
 *
 * Approach:
 *   1. Hook `nitropack_js_url_exclude` — NitroPack's own filter for URL-based
 *      script exclusions. Safe no-op if NitroPack does not define it.
 *   2. Hook `script_loader_tag` to mark ClickTrail script tags with
 *      `data-nitropack-exclude="true"`, which NitroPack 2.x respects as an
 *      inline exclusion signal.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class NitroPack_Compat
 */
class NitroPack_Compat {

	/**
	 * Script handles managed by ClickTrail that must not be postponed.
	 *
	 * @var string[]
	 */
	private const SCRIPT_HANDLES = array(
		'clicutcl-attribution-js',
		'clicutcl-consent-bridge-js',
		'clicutcl-consent-js',
		'clicutcl-events-js',
	);

	/**
	 * Register hooks — only when NitroPack is active.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! defined( 'NITROPACK_VERSION' ) ) {
			return;
		}

		// Filter 1: NitroPack's own URL exclusion list.
		add_filter( 'nitropack_js_url_exclude', array( static::class, 'exclude_script_urls' ) );

		// Filter 2: HTML-level marker on the script tag.
		add_filter( 'script_loader_tag', array( static::class, 'mark_script_tags' ), 10, 2 );
	}

	/**
	 * Add ClickTrail script URLs to NitroPack's JS exclusion list.
	 *
	 * @param string[] $excludes Existing exclusion patterns.
	 * @return string[]
	 */
	public static function exclude_script_urls( array $excludes ): array {
		$plugin_url = defined( 'CLICUTCL_URL' ) ? CLICUTCL_URL : '';
		if ( ! $plugin_url ) {
			return $excludes;
		}

		$paths = array(
			'assets/js/clicutcl-attribution.js',
			'assets/js/clicutcl-consent-bridge.js',
			'assets/js/clicutcl-consent.js',
			'assets/js/clicutcl-events.js',
		);

		foreach ( $paths as $path ) {
			$excludes[] = $plugin_url . $path;
		}

		return $excludes;
	}

	/**
	 * Add `data-nitropack-exclude` attribute to ClickTrail script tags.
	 *
	 * NitroPack 2.x respects this attribute as an inline exclusion signal,
	 * independent of the URL-based exclusion filter above.
	 *
	 * @param string $tag    The full `<script>` HTML tag.
	 * @param string $handle The script handle registered with WordPress.
	 * @return string
	 */
	public static function mark_script_tags( string $tag, string $handle ): string {
		if ( ! in_array( $handle, self::SCRIPT_HANDLES, true ) ) {
			return $tag;
		}

		// Already marked — avoid double injection.
		if ( str_contains( $tag, 'data-nitropack-exclude' ) ) {
			return $tag;
		}

		return str_replace( '<script ', '<script data-nitropack-exclude="true" ', $tag );
	}
}
