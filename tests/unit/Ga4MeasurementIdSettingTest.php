<?php
/**
 * GA4 measurement ID setting: sanitised on save, validated again where it is localized to the
 * attribution script, which pins the `_ga_<ID>` session cookie read (#135).
 *
 * @package ClickTrail
 */

declare(strict_types=1);

namespace {
	if ( ! defined( 'CLICUTCL_DIR' ) ) {
		define( 'CLICUTCL_DIR', dirname( __DIR__, 2 ) . '/' );
	}
	require_once dirname( __DIR__, 2 ) . '/includes/Core/class-cookie-scope.php';
	require_once dirname( __DIR__, 2 ) . '/includes/settings/class-attribution-settings.php';
	require_once dirname( __DIR__, 2 ) . '/includes/admin/class-admin.php';
	require_once dirname( __DIR__, 2 ) . '/includes/class-clicutcl-core.php';

	use CLICUTCL\Admin\Admin;
	use CLICUTCL\Plugin;
	use CLICUTCL\Settings\Attribution_Settings;
	use PHPUnit\Framework\TestCase;

	final class Ga4MeasurementIdSettingTest extends TestCase {
		protected function setUp(): void {
			$GLOBALS['clicktrail_test_current_user_can'] = true;
			$GLOBALS['clicktrail_test_home_url']         = 'https://www.example.com';
		}

		protected function tearDown(): void {
			unset(
				$GLOBALS['clicktrail_test_options'],
				$GLOBALS['clicktrail_test_home_url'],
				$GLOBALS['clicktrail_test_current_user_can']
			);
		}

		private function admin(): Admin {
			return ( new \ReflectionClass( Admin::class ) )->newInstanceWithoutConstructor();
		}

		/**
		 * @param array<string,mixed> $options Attribution settings.
		 * @return array<string,mixed>
		 */
		private function script_config( array $options ): array {
			$plugin = ( new \ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
			$method = new \ReflectionMethod( Plugin::class, 'build_attribution_config' );
			$method->setAccessible( true );

			return $method->invoke(
				$plugin,
				$options,
				array(
					'cookie_name'     => 'ct_consent',
					'require_consent' => false,
				),
				90,
				false,
				'',
				'',
				false,
				array(
					'domain' => '',
					'reason' => 'host_scope',
				)
			);
		}

		public function test_default_is_empty_and_the_script_stays_unpinned(): void {
			$settings = $this->admin()->sanitize_settings( array( 'cookie_days' => 45 ) );

			$this->assertSame( '', $settings['ga4_measurement_id'] );
			$this->assertSame( array(), $this->script_config( $settings )['ga4MeasurementId'] );
		}

		public function test_ids_are_uppercased_deduplicated_and_invalid_entries_dropped(): void {
			$settings = $this->admin()->sanitize_settings(
				array( 'ga4_measurement_id' => ' g-abc123 , G-ABC123, UA-1234-1, G-, G-XYZ789 ,not an id' )
			);

			$this->assertSame( 'G-ABC123,G-XYZ789', $settings['ga4_measurement_id'] );
		}

		public function test_a_value_with_no_valid_id_clears_the_setting(): void {
			$GLOBALS['clicktrail_test_options']['clicutcl_attribution_settings'] = array( 'ga4_measurement_id' => 'G-ABC123' );

			$settings = $this->admin()->sanitize_settings( array( 'ga4_measurement_id' => 'ABC123' ) );

			$this->assertSame( '', $settings['ga4_measurement_id'] );
		}

		public function test_a_save_without_the_key_keeps_the_stored_id(): void {
			$GLOBALS['clicktrail_test_options']['clicutcl_attribution_settings'] = array( 'ga4_measurement_id' => 'G-ABC123' );

			$settings = $this->admin()->sanitize_settings( array( 'cookie_days' => 45 ) );

			$this->assertSame( 'G-ABC123', $settings['ga4_measurement_id'] );
		}

		public function test_the_setup_wizard_value_reaches_the_script_config(): void {
			$this->assertSame(
				array( 'G-ABC123' ),
				$this->script_config( array( 'ga4_measurement_id' => 'G-ABC123' ) )['ga4MeasurementId']
			);
		}

		public function test_the_script_config_revalidates_what_it_localizes(): void {
			$config = $this->script_config( array( 'ga4_measurement_id' => 'G-ABC123,"><script>,g-xyz789,UA-1-1' ) );

			$this->assertSame( array( 'G-ABC123', 'G-XYZ789' ), $config['ga4MeasurementId'] );
			$this->assertSame( array(), $this->script_config( array( 'ga4_measurement_id' => array( 'G-ABC123' ) ) )['ga4MeasurementId'] );
		}

		public function test_ids_helper_returns_an_empty_list_for_missing_settings(): void {
			$this->assertSame( array(), Attribution_Settings::ga4_measurement_ids( array() ) );
		}
	}
}
