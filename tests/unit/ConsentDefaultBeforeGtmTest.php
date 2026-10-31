<?php
/**
 * Consent Mode default must print before the GTM bootstrap on `wp_head` (#147).
 *
 * @package ClickTrail
 */

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions, Squiz.Commenting

namespace CLICUTCL\Modules\Consent_Mode {
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['clicktrail_test_head_hooks'][ $tag ][] = array( $callback, $priority );
	}

	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		return true;
	}

	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
}

namespace CLICUTCL\Modules\GTM {
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['clicktrail_test_head_hooks'][ $tag ][] = array( $callback, $priority );
	}

	function esc_html( $text ) {
		return $text;
	}

	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}

	function esc_js( $text ) {
		return $text;
	}
}

namespace CLICUTCL\Core {
	function plugin_basename( $file ) {
		return 'click-trail-handler/clicutcl.php';
	}
}

namespace {
	use CLICUTCL\Core\Context;
	use CLICUTCL\Modules\Consent_Mode\Consent_Mode;
	use CLICUTCL\Modules\GTM\Web_Tag;
	use PHPUnit\Framework\TestCase;

	/**
	 * The consent default snippet must be registered and rendered strictly before the GTM bootstrap.
	 *
	 * Settings stubs are loaded in setUp and each test runs in its own process so the
	 * `Consent_Mode_Settings` stub never leaks into tests that expect the class absent.
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class ConsentDefaultBeforeGtmTest extends TestCase {
		protected function setUp(): void {
			require_once dirname( __DIR__ ) . '/helpers/consent-mode-settings-stub.php';
			require_once dirname( __DIR__ ) . '/helpers/gtm-settings-stub.php';
			require_once dirname( __DIR__, 2 ) . '/includes/Core/class-context.php';
			require_once dirname( __DIR__, 2 ) . '/includes/Modules/consent-mode/class-consent-mode.php';
			require_once dirname( __DIR__, 2 ) . '/includes/Modules/GTM/class-web-tag.php';

			$GLOBALS['clicktrail_test_head_hooks']           = array();
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = true;
			$GLOBALS['clicktrail_test_consent_mode']         = 'strict';
			$GLOBALS['clicktrail_test_consent_regions']      = array();
			$GLOBALS['clicktrail_test_gtm_container_id']     = 'GTM-TEST123';
		}

		protected function tearDown(): void {
			$GLOBALS['clicktrail_test_head_hooks']           = array();
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = false;
			unset( $GLOBALS['clicktrail_test_consent_mode'], $GLOBALS['clicktrail_test_consent_regions'], $GLOBALS['clicktrail_test_gtm_container_id'] );
		}

		public function test_head_priority_constants_order_consent_before_gtm(): void {
			$this->assertLessThan( Web_Tag::HEAD_PRIORITY, Consent_Mode::HEAD_PRIORITY );
		}

		public function test_registered_wp_head_priorities_order_consent_before_gtm(): void {
			$this->register_modules_gtm_first();

			$priorities = $this->wp_head_priorities_by_class();

			$this->assertArrayHasKey( Consent_Mode::class, $priorities );
			$this->assertArrayHasKey( Web_Tag::class, $priorities );
			$this->assertLessThan( $priorities[ Web_Tag::class ], $priorities[ Consent_Mode::class ] );
		}

		public function test_wp_head_output_prints_consent_default_before_gtm_bootstrap(): void {
			$this->register_modules_gtm_first();

			$html = $this->run_wp_head_in_priority_order();

			$consent_at = strpos( $html, 'id="clicktrail-consent-mode"' );
			$gtm_at     = strpos( $html, 'googletagmanager.com/gtm.js' );

			$this->assertNotFalse( $consent_at, 'Consent default snippet missing from wp_head output.' );
			$this->assertNotFalse( $gtm_at, 'GTM bootstrap missing from wp_head output.' );
			$this->assertLessThan( $gtm_at, $consent_at, 'Consent default must print before the GTM bootstrap.' );
			$this->assertSame( 1, substr_count( $html, "gtag('consent', 'default'" ), 'Consent default must print exactly once per wp_head run.' );
		}

		public function test_disabled_consent_mode_registers_no_wp_head_snippet(): void {
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = false;

			$this->register_modules_gtm_first();

			$this->assertArrayNotHasKey( Consent_Mode::class, $this->wp_head_priorities_by_class() );
			$this->assertStringNotContainsString( 'clicktrail-consent-mode', $this->run_wp_head_in_priority_order() );
		}

		/**
		 * Registers GTM before Consent Mode so registration order alone would put GTM first.
		 */
		private function register_modules_gtm_first(): void {
			$context = new Context( '/srv/click-trail-handler/clicutcl.php' );
			( new Web_Tag( $context ) )->register();
			( new Consent_Mode( $context ) )->register();
		}

		/**
		 * @return array<string,int> Registered `wp_head` priority keyed by callback class.
		 */
		private function wp_head_priorities_by_class(): array {
			$priorities = array();
			foreach ( $GLOBALS['clicktrail_test_head_hooks']['wp_head'] ?? array() as $hook ) {
				$priorities[ get_class( $hook[0][0] ) ] = $hook[1];
			}
			return $priorities;
		}

		/**
		 * Mirrors WP_Hook: callbacks run by ascending priority, registration order within a priority.
		 */
		private function run_wp_head_in_priority_order(): string {
			$hooks = $GLOBALS['clicktrail_test_head_hooks']['wp_head'] ?? array();
			usort(
				$hooks,
				static function ( array $a, array $b ): int {
					return $a[1] <=> $b[1];
				}
			);

			ob_start();
			foreach ( $hooks as $hook ) {
				call_user_func( $hook[0] );
			}
			return (string) ob_get_clean();
		}
	}
}
