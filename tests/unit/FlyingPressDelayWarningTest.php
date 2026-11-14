<?php
/**
 * FlyingPress Delay JS warning (#165): one shared check feeds Site Health and
 * the Diagnostics conflict scan. FlyingPress has no public source or filter for
 * Delay JS, so the check keys on "FlyingPress active" and names the keywords.
 *
 * @package ClickTrail
 */

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions, Squiz.Commenting

namespace {
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/class-optimizer-compat.php';
	require_once dirname( __DIR__, 2 ) . '/includes/admin/class-setup-detector.php';
	require_once dirname( __DIR__, 2 ) . '/includes/admin/class-sitehealth.php';

	use CLICUTCL\Admin\Setup_Detector;
	use CLICUTCL\Admin\SiteHealth;
	use PHPUnit\Framework\TestCase;

	if ( ! function_exists( 'esc_html' ) ) {
		function esc_html( $text ) {
			return htmlspecialchars( (string) $text, ENT_QUOTES );
		}
	}

	final class FlyingPressDelayWarningTest extends TestCase {

		protected function setUp(): void {
			$GLOBALS['clicktrail_test_options'] = array();
		}

		protected function tearDown(): void {
			$GLOBALS['clicktrail_test_options'] = array();
		}

		private function activate( array $plugins ): void {
			$GLOBALS['clicktrail_test_options']['active_plugins'] = $plugins;
		}

		public function test_no_warning_when_flyingpress_is_inactive(): void {
			$this->activate( array( 'wp-rocket/wp-rocket.php', 'click-trail-handler/clicutcl.php' ) );

			$this->assertFalse( Setup_Detector::detect_flyingpress() );
			$this->assertSame( '', Setup_Detector::flyingpress_delay_warning() );
			$this->assertSame( 'good', ( new SiteHealth() )->test_flyingpress_delay()['status'] );
		}

		public function test_no_warning_without_any_active_plugins_option(): void {
			$this->assertSame( '', Setup_Detector::flyingpress_delay_warning() );
		}

		public function test_lookalike_plugin_folder_is_not_flyingpress(): void {
			$this->activate( array( 'flying-press-helper/flying-press-helper.php' ) );

			$this->assertFalse( Setup_Detector::detect_flyingpress() );
		}

		public function test_warning_names_every_keyword_when_flyingpress_is_active(): void {
			$this->activate( array( 'flying-press/flying-press.php' ) );

			$warning = Setup_Detector::flyingpress_delay_warning();

			$this->assertTrue( Setup_Detector::detect_flyingpress() );
			$this->assertStringContainsString( 'FlyingPress', $warning );
			$this->assertStringContainsString( 'clicutcl', $warning );
			$this->assertStringContainsString( 'clicktrail-consent-mode', $warning );
		}

		public function test_site_health_reports_a_recommended_result_with_the_keywords(): void {
			$this->activate( array( 'flying-press/flying-press.php' ) );

			$result = ( new SiteHealth() )->test_flyingpress_delay();

			$this->assertSame( 'recommended', $result['status'] );
			$this->assertStringContainsString( 'clicutcl', $result['description'] );
			$this->assertStringContainsString( 'clicktrail-consent-mode', $result['description'] );
		}

		public function test_site_health_registers_the_flyingpress_test(): void {
			$site_health = new SiteHealth();
			$tests       = $site_health->add_tests( array() );

			$this->assertSame(
				array( $site_health, 'test_flyingpress_delay' ),
				$tests['direct']['clicutcl_flyingpress_delay']['test']
			);
		}
	}
}
