<?php
/**
 * Unified admin settings save: the JSON payload is decoded before any sanitising, and an
 * undecodable or missing payload is rejected with HTTP 400 instead of saving an empty config.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

namespace {
	use PHPUnit\Framework\TestCase;

	/**
	 * Runs in separate processes: the real Dispatcher and Settings classes are needed, while many
	 * other unit tests declare namespace-level Dispatcher doubles in the shared process.
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class AdminSettingsSaveTest extends TestCase {
		private const SECRET = 'a%2Fb<c>d  e';
		private const URL    = 'https://x.test/c?x=a%2Fb';

		protected function setUp(): void {
			require_once __DIR__ . '/AdminSettingsSaveStubs.php';

			$GLOBALS['clicktrail_test_current_user_can']    = true;
			$GLOBALS['clicktrail_test_nonce_ok']            = true;
			$GLOBALS['clicktrail_test_strip_octets']        = true;
			$GLOBALS['clicktrail_test_strip_tags']          = true;
			$GLOBALS['clicktrail_test_real_unslash']        = true;
			$GLOBALS['clicktrail_test_options']             = array();
			$GLOBALS['clicktrail_test_update_option_calls'] = array();
			$_POST                                          = array();
			// The response rebuilds the settings view, which probes the queue table: report it missing.
			$GLOBALS['wpdb'] = new class() {
				public $prefix = 'wp_';

				public function prepare( $query, ...$args ) {
					return $query;
				}

				public function get_var( $query ) {
					return null;
				}
			};
		}

		protected function tearDown(): void {
			unset(
				$GLOBALS['clicktrail_test_current_user_can'],
				$GLOBALS['clicktrail_test_nonce_ok'],
				$GLOBALS['clicktrail_test_strip_octets'],
				$GLOBALS['clicktrail_test_strip_tags'],
				$GLOBALS['clicktrail_test_real_unslash'],
				$GLOBALS['clicktrail_test_options'],
				$GLOBALS['clicktrail_test_update_option_calls'],
				$GLOBALS['wpdb']
			);
			$_POST = array();
		}

		private function admin(): \CLICUTCL\Admin\Admin {
			return ( new \ReflectionClass( \CLICUTCL\Admin\Admin::class ) )->newInstanceWithoutConstructor();
		}

		/**
		 * Post a payload the way WordPress delivers it: slashed once by wp_magic_quotes.
		 *
		 * @param array<string,mixed> $payload Settings payload.
		 */
		private function post_settings( array $payload ): void {
			$_POST['settings'] = addslashes( (string) json_encode( $payload ) );
			$_POST['nonce']    = 'n';
		}

		private function call_handler(): \AdminSettingsSaveResponse {
			try {
				$this->admin()->ajax_save_admin_settings();
			} catch ( \AdminSettingsSaveResponse $response ) {
				return $response;
			}
			$this->fail( 'Handler did not respond.' );
		}

		public function test_secret_and_url_round_trip_byte_for_byte(): void {
			$this->post_settings(
				array(
					'forms'    => array(
						'providers' => array(
							'hubspot' => array(
								'enabled' => 1,
								'secret'  => self::SECRET,
							),
						),
					),
					'delivery' => array(
						'server' => array(
							'enabled'      => 1,
							'endpoint_url' => self::URL,
						),
					),
				)
			);

			$response = $this->call_handler();

			$this->assertTrue( $response->ok );
			$tracking = $GLOBALS['clicktrail_test_options']['clicutcl_tracking_v2'] ?? null;
			$this->assertIsArray( $tracking );
			$this->assertSame( self::SECRET, $tracking['external_forms']['providers']['hubspot']['secret'] );
			$server = $GLOBALS['clicktrail_test_options']['clicutcl_server_side'] ?? null;
			$this->assertIsArray( $server );
			$this->assertSame( self::URL, $server['endpoint_url'] );
		}

		public function test_undecodable_payload_is_rejected_with_400_and_touches_nothing(): void {
			$_POST['settings'] = 'not-json';
			$_POST['nonce']    = 'n';

			$response = $this->call_handler();

			$this->assertFalse( $response->ok );
			$this->assertSame( 400, $response->status );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_update_option_calls'] );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_options'] );
		}

		public function test_missing_payload_is_rejected_with_400_and_touches_nothing(): void {
			$_POST['nonce'] = 'n';

			$response = $this->call_handler();

			$this->assertFalse( $response->ok );
			$this->assertSame( 400, $response->status );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_update_option_calls'] );
		}

		public function test_non_array_json_is_rejected_with_400(): void {
			$_POST['settings'] = '"just a string"';
			$_POST['nonce']    = 'n';

			$response = $this->call_handler();

			$this->assertFalse( $response->ok );
			$this->assertSame( 400, $response->status );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_update_option_calls'] );
		}

		public function test_capability_failure_still_returns_403(): void {
			$GLOBALS['clicktrail_test_current_user_can'] = false;
			$this->post_settings( array() );

			$response = $this->call_handler();

			$this->assertFalse( $response->ok );
			$this->assertSame( 403, $response->status );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_update_option_calls'] );
		}

		public function test_nonce_failure_still_aborts_before_saving(): void {
			$GLOBALS['clicktrail_test_nonce_ok'] = false;
			$this->post_settings( array() );

			try {
				$this->admin()->ajax_save_admin_settings();
				$this->fail( 'Nonce failure must abort.' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'nonce_failed', $e->getMessage() );
			}
			$this->assertSame( array(), $GLOBALS['clicktrail_test_update_option_calls'] );
		}
	}
}
