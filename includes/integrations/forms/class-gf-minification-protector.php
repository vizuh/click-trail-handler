<?php
/**
 * Gravity Forms Minification Protector
 *
 * Adds exclusion data attributes to ClickTrail script tags so common cache /
 * minify / delay-JS plugins do not break attribution.
 *
 * Coverage map (verified against each plugin's own docs):
 *   - `data-no-optimize="1"`     → LiteSpeed Cache
 *   - `data-noptimize="1"`       → Autoptimize
 *   - `data-cfasync="false"`     → Cloudflare Rocket Loader, Autoptimize
 *   - `data-no-defer="1"`        → WP Rocket (delay-JS exclusion)
 *   - `data-no-minify="1"`       → WP Rocket (minify exclusion)
 *
 * The previous attribute set used names that neither WP Rocket nor LiteSpeed
 * actually read; this class corrects that (I-2) and uses regex-based injection
 * after the opening `<script` token (M-1) instead of `str_replace(' src=')`.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Integrations\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Gf_Minification_Protector
 */
class Gf_Minification_Protector {

	/**
	 * Exclusion attributes injected after the opening `<script` token.
	 *
	 * @var string
	 */
	private const EXCLUSION_ATTRS = 'data-no-optimize="1" data-noptimize="1" data-cfasync="false" data-no-defer="1" data-no-minify="1"';

	/**
	 * Script handles registered by ClickTrail that need minification protection.
	 *
	 * @var string[]
	 */
	private const CT_HANDLES = array(
		'clicutcl-attribution-js',
		'clicutcl-consent-bridge-js',
		'clicutcl-consent-js',
	);

	/**
	 * Constructor — self-registers the script_loader_tag filter.
	 */
	public function __construct() {
		add_filter( 'script_loader_tag', array( $this, 'add_attrs' ), 10, 2 );
	}

	/**
	 * Inject exclusion attributes into ClickTrail script tags.
	 *
	 * @param string $tag    The full `<script>` HTML.
	 * @param string $handle Registered script handle.
	 * @return string Modified tag, or original if not a ClickTrail handle.
	 */
	public function add_attrs( $tag, $handle ) {
		if ( ! is_string( $tag ) || '' === $tag ) {
			return $tag;
		}

		if ( ! in_array( $handle, self::CT_HANDLES, true ) ) {
			return $tag;
		}

		// Idempotency guard.
		if ( false !== strpos( $tag, 'data-noptimize' ) ) {
			return $tag;
		}

		// Inject attrs immediately after the opening `<script` token. Robust
		// to leading/trailing whitespace and any attribute order, unlike the
		// previous `str_replace(' src=', ...)` approach.
		$replaced = preg_replace(
			'/(<script\b)/',
			'$1 ' . self::EXCLUSION_ATTRS,
			$tag,
			1
		);

		return null === $replaced ? $tag : $replaced;
	}
}
