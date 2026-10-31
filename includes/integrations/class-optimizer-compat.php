<?php
/**
 * Optimizer compatibility layer.
 *
 * Keeps cache, minify, combine, defer and delay-JS plugins away from the four
 * ClickTrail front-end scripts so the landing touch is recorded on page load,
 * not after the first interaction. Registered from core bootstrap on every
 * site; it does not depend on any form plugin being active.
 *
 * Tag attributes (each credited to the vendor that reads it, as of 2026-10-31):
 *   - `nowprocket`              → WP Rocket Delay JavaScript execution
 *   - `data-no-optimize="1"`    → LiteSpeed Cache JS minify / combine / defer
 *   - `data-no-defer="1"`       → LiteSpeed Cache JS defer / delay
 *   - `data-noptimize="1"`      → Autoptimize (tag is neither merged nor moved)
 *   - `data-cfasync="false"`    → Cloudflare Rocket Loader (LiteSpeed skips it too)
 *
 * PHP filters: WP Rocket, LiteSpeed Cache, Autoptimize, Perfmatters and
 * SiteGround Speed Optimizer each expose an exclusion list; the callbacks are
 * registered unconditionally and never run when the vendor is absent.
 * FlyingPress documents no attribute or filter; its exclusion field is a
 * documentation-only step. NitroPack stays in `NitroPack_Compat`.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Optimizer_Compat
 */
class Optimizer_Compat {

	/**
	 * Front-end script handles registered by ClickTrail.
	 *
	 * @var string[]
	 */
	public const HANDLES = array(
		'clicutcl-consent-bridge-js',
		'clicutcl-attribution-js',
		'clicutcl-consent-js',
		'clicutcl-events-js',
	);

	/**
	 * Exclusion attributes in `wp_script_attributes` shape: `true` prints bare.
	 *
	 * @var array<string, string|true>
	 */
	public const ATTRIBUTES = array(
		'nowprocket'       => true,
		'data-no-optimize' => '1',
		'data-no-defer'    => '1',
		'data-noptimize'   => '1',
		'data-cfasync'     => 'false',
	);

	/**
	 * Plugin-relative path prefix; relative so minified copies and CDN rewrites still match.
	 *
	 * @var string
	 */
	private const PATH_PREFIX = 'plugins/click-trail-handler/assets/js/';

	/**
	 * Register the tag filters and every verified vendor filter.
	 *
	 * @return void
	 */
	public static function register(): void {
		// Primary: WordPress 6.5+ builds the src tag through wp_get_script_tag().
		add_filter( 'wp_script_attributes', array( static::class, 'add_attributes' ) );
		// Fallback and idempotency guard on the printed string.
		add_filter( 'script_loader_tag', array( static::class, 'mark_tag' ), 10, 2 );

		// WP Rocket: delay JS (whole tag), defer JS (src URL), minify/combine (URL path).
		add_filter( 'rocket_delay_js_exclusions', array( static::class, 'exclude_paths' ) );
		add_filter( 'rocket_exclude_defer_js', array( static::class, 'exclude_paths' ) );
		add_filter( 'rocket_exclude_js', array( static::class, 'exclude_paths' ) );

		// LiteSpeed Cache: JS excludes, deferred/delayed excludes, guest mode excludes.
		add_filter( 'litespeed_optm_js_exc', array( static::class, 'exclude_paths' ) );
		add_filter( 'litespeed_optm_js_defer_exc', array( static::class, 'exclude_paths' ) );
		add_filter( 'litespeed_optm_gm_js_exc', array( static::class, 'exclude_paths' ) );

		// Autoptimize: comma-separated substrings.
		add_filter( 'autoptimize_filter_js_exclude', array( static::class, 'exclude_csv' ) );

		// Perfmatters: keyword arrays.
		add_filter( 'perfmatters_delay_js_exclusions', array( static::class, 'exclude_files' ) );
		add_filter( 'perfmatters_defer_js_exclusions', array( static::class, 'exclude_files' ) );
		add_filter( 'perfmatters_minify_js_exclusions', array( static::class, 'exclude_files' ) );

		// SiteGround Speed Optimizer: script handles.
		add_filter( 'sgo_js_minify_exclude', array( static::class, 'exclude_handles' ) );
		add_filter( 'sgo_javascript_combine_exclude', array( static::class, 'exclude_handles' ) );
		add_filter( 'sgo_js_async_exclude', array( static::class, 'exclude_handles' ) );
	}

	/**
	 * Add the exclusion attributes to the keyed attribute array of a ClickTrail src tag.
	 *
	 * Keyed array: an attribute another filter already set wins and cannot duplicate.
	 *
	 * @param array $attributes Attributes `wp_get_script_tag()` will print.
	 * @return array
	 */
	public static function add_attributes( array $attributes ): array {
		$id = $attributes['id'] ?? '';
		if ( ! is_string( $id ) || ! str_ends_with( $id, '-js' ) ) {
			return $attributes;
		}

		if ( ! in_array( substr( $id, 0, -3 ), self::HANDLES, true ) ) {
			return $attributes;
		}

		return $attributes + self::ATTRIBUTES;
	}

	/**
	 * Insert any exclusion attribute still missing from the ClickTrail src tag.
	 *
	 * Targets the `<script` token carrying `id="{handle}-js"` (or, without an
	 * id, the first token carrying `src=`) so inline before/after tags printed
	 * for the same handle are left alone. Each attribute is inserted at most
	 * once; a second pass over the result is a no-op.
	 *
	 * @param string $tag    Full HTML printed for the handle.
	 * @param string $handle Registered script handle.
	 * @return string
	 */
	public static function mark_tag( string $tag, string $handle ): string {
		if ( '' === $tag || ! in_array( $handle, self::HANDLES, true ) ) {
			return $tag;
		}

		$pattern = '/<script\b[^>]*\bid=(["\'])' . preg_quote( $handle, '/' ) . '-js\1[^>]*>/';
		if ( ! preg_match( $pattern, $tag, $match, PREG_OFFSET_CAPTURE ) ) {
			$pattern = '/<script\b[^>]*\bsrc=[^>]*>/';
			if ( ! preg_match( $pattern, $tag, $match, PREG_OFFSET_CAPTURE ) ) {
				return $tag;
			}
		}

		list( $token, $offset ) = $match[0];

		$missing = array();
		foreach ( self::ATTRIBUTES as $name => $value ) {
			if ( preg_match( '/\s' . preg_quote( $name, '/' ) . '(?=[\s=\/>])/', $token ) ) {
				continue;
			}
			$missing[] = true === $value ? $name : $name . '="' . $value . '"';
		}

		if ( ! $missing ) {
			return $tag;
		}

		$marked = substr_replace( $token, '<script ' . implode( ' ', $missing ), 0, strlen( '<script' ) );

		return substr_replace( $tag, $marked, $offset, strlen( $token ) );
	}

	/**
	 * WP Rocket and LiteSpeed: plugin-relative URL paths.
	 *
	 * @param string[] $excludes Existing exclusions.
	 * @return string[]
	 */
	public static function exclude_paths( array $excludes ): array {
		return self::append( $excludes, self::PATH_PREFIX );
	}

	/**
	 * Perfmatters: file names.
	 *
	 * @param string[] $excludes Existing exclusions.
	 * @return string[]
	 */
	public static function exclude_files( array $excludes ): array {
		return self::append( $excludes, '' );
	}

	/**
	 * SiteGround Speed Optimizer: script handles.
	 *
	 * @param string[] $excludes Existing exclusions.
	 * @return string[]
	 */
	public static function exclude_handles( array $excludes ): array {
		foreach ( self::HANDLES as $handle ) {
			if ( ! in_array( $handle, $excludes, true ) ) {
				$excludes[] = $handle;
			}
		}

		return $excludes;
	}

	/**
	 * Autoptimize: comma-separated file names.
	 *
	 * @param string $csv Existing exclusions.
	 * @return string
	 */
	public static function exclude_csv( string $csv ): string {
		$excludes = array_filter( array_map( 'trim', explode( ',', $csv ) ), 'strlen' );

		return implode( ',', self::append( array_values( $excludes ), '' ) );
	}

	/**
	 * Append the four ClickTrail file names, with the given prefix, when absent.
	 *
	 * @param string[] $excludes Existing entries.
	 * @param string   $prefix   Prefix before each file name.
	 * @return string[]
	 */
	private static function append( array $excludes, string $prefix ): array {
		foreach ( self::HANDLES as $handle ) {
			$entry = $prefix . substr( $handle, 0, -3 ) . '.js';
			if ( ! in_array( $entry, $excludes, true ) ) {
				$excludes[] = $entry;
			}
		}

		return $excludes;
	}
}
