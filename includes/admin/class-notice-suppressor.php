<?php
/**
 * Suppress third-party admin notices on ClickTrail admin screens.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes admin notices fired by other plugins and themes on ClickTrail's
 * own admin screens (setup wizard, settings, logs, diagnostics).
 *
 * Multi-hook: `current_screen` and `in_admin_header` so notices registered
 * after `admin_init` still get cleared. A defensive CSS rule hides any
 * notice markup that bypasses the standard `admin_notices` actions
 * (in_admin_header injection, JS-injected ads). ClickTrail's own notices
 * survive by carrying the `clicutcl-notice` CSS class.
 */
class Notice_Suppressor {

	/**
	 * Register hooks. Idempotent.
	 */
	public static function init(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		add_action( 'current_screen', array( __CLASS__, 'suppress' ), 1 );
		add_action( 'in_admin_header', array( __CLASS__, 'suppress' ), 1 );
		add_action( 'admin_print_styles', array( __CLASS__, 'emit_css' ), 999 );
	}

	/**
	 * Drop every callback on the four admin-notices actions when the current
	 * request is on a ClickTrail screen.
	 */
	public static function suppress(): void {
		if ( ! self::is_clicutcl_screen() ) {
			return;
		}
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
	}

	/**
	 * Emit defensive CSS that hides any notice markup that slipped past the
	 * action removals. ClickTrail-owned notices opt in via `.clicutcl-notice`.
	 */
	public static function emit_css(): void {
		if ( ! self::is_clicutcl_screen() ) {
			return;
		}
		echo "<style id='clicutcl-suppress-notices'>"
			. '#wpbody-content > .notice:not(.clicutcl-notice),'
			. '#wpbody-content > .notice-info:not(.clicutcl-notice),'
			. '#wpbody-content > .notice-warning:not(.clicutcl-notice),'
			. '#wpbody-content > .notice-error:not(.clicutcl-notice),'
			. '#wpbody-content > .notice-success:not(.clicutcl-notice),'
			. '#wpbody-content > .updated:not(.clicutcl-notice),'
			. '#wpbody-content > .error:not(.clicutcl-notice),'
			. '#wpbody-content > .update-nag:not(.clicutcl-notice)'
			. '{display:none!important}'
			. '</style>';
	}

	/**
	 * Detect ClickTrail admin screens by screen-ID substring. Covers the
	 * top-level menu, every submenu, and the hidden wizard page.
	 */
	private static function is_clicutcl_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		if ( ! $screen instanceof \WP_Screen ) {
			return false;
		}
		return false !== strpos( (string) $screen->id, 'clicutcl' );
	}
}
