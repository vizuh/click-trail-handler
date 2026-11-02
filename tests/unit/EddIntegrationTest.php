<?php
/**
 * Easy Digital Downloads: consented order attribution and one confirmation-page purchase (#173).
 *
 * @package ClickTrail
 */

declare(strict_types=1);

namespace {
	require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-adapter-result.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-decision-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-resolver-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-snapshot-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-consent.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Core/class-attribution-provider.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-claim-lock.php';
	require_once dirname( __DIR__, 2 ) . '/includes/privacy/class-woo-order-privacy.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/class-edd.php';
	require_once dirname( __DIR__ ) . '/helpers/edd-functions.php';

	use CLICUTCL\Integrations\Edd;
	use PHPUnit\Framework\TestCase;

	if ( ! function_exists( 'current_time' ) ) {
		function current_time( $type ) {
			return 'mysql' === $type ? '2026-10-31 00:00:00' : time();
		}
	}

	if ( ! function_exists( 'wp_parse_args' ) ) {
		function wp_parse_args( $args, $defaults = array() ) {
			return array_merge( (array) $defaults, (array) $args );
		}
	}

	/**
	 * Minimal `$wpdb` double for the row-conditional lock delete.
	 */
	final class EddTest_Wpdb {
		public string $options = 'wp_options';

		public function delete( $table, $where ) {
			$name = (string) $where['option_name'];
			if ( ! isset( $GLOBALS['clicktrail_test_options'][ $name ] ) || (string) $GLOBALS['clicktrail_test_options'][ $name ] !== (string) $where['option_value'] ) {
				return 0;
			}
			unset( $GLOBALS['clicktrail_test_options'][ $name ] );
			return 1;
		}
	}

	/**
	 * Shape of `EDD\Orders\Order` the integration reads.
	 */
	final class EddTest_Order {
		public int $id                = 4815;
		public string $type           = 'sale';
		public string $status         = 'complete';
		public string $email          = 'buyer@example.com';
		public string $ip             = '203.0.113.9';
		public string $currency       = 'EUR';
		public string $gateway        = 'manual';
		public int $customer_id       = 7;
		public int $user_id           = 0;
		public float $subtotal        = 100.0;
		public float $tax             = 23.0;
		public float $discount        = 10.0;
		public float $total           = 113.0;
		public string $date_created   = '2026-10-31 10:00:00';
		public string $order_number   = '';

		public function get_number() {
			return 'EDD-' . $this->id;
		}

		public function get_items() {
			return array(
				(object) array(
					'product_id'   => 21,
					'product_name' => 'Pro Plugin — Single Site',
					'price_id'     => 1,
					'quantity'     => 2,
					'amount'       => 50.0,
					'subtotal'     => 100.0,
					'tax'          => 23.0,
					'discount'     => 10.0,
					'total'        => 113.0,
				),
			);
		}

		public function get_discounts() {
			return array( (object) array( 'description' => 'SPRING10' ) );
		}

		public function get_address() {
			return (object) array( 'country' => 'PT' );
		}
	}

	/**
	 * Class EddIntegrationTest
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class EddIntegrationTest extends TestCase {
		private const ORDER_ID = 4815;
		private const LOCK_KEY = 'clicutcl_edd_purchase_lock_4815';

		protected function setUp(): void {
			// Declared per test process: a file-level Identity_Resolver double would collide with
			// suites that load the real class during discovery.
			require_once dirname( __DIR__ ) . '/helpers/edd-doubles.php';

			// Process-local doubles: kept out of the file scope so other tests never see them.
			if ( ! function_exists( 'is_admin' ) ) {
				function is_admin() {
					return (bool) ( $GLOBALS['clicktrail_test_is_admin'] ?? false );
				}
			}
			if ( ! function_exists( 'wp_doing_ajax' ) ) {
				function wp_doing_ajax() {
					return (bool) ( $GLOBALS['clicktrail_test_doing_ajax'] ?? false );
				}
			}
			if ( ! function_exists( 'wp_doing_cron' ) ) {
				function wp_doing_cron() {
					return (bool) ( $GLOBALS['clicktrail_test_doing_cron'] ?? false );
				}
			}
			if ( ! function_exists( 'wp_slash' ) ) {
				function wp_slash( $value ) {
					return is_string( $value ) ? addslashes( $value ) : $value;
				}
			}

			$_COOKIE = array(
				'ct_consent_state' => wp_json_encode( array( 'marketing' => true ) ),
				'attribution'      => wp_json_encode(
					array(
						'ft_source'    => 'google',
						'ft_medium'    => 'cpc',
						'lt_source'    => 'newsletter',
						'lt_medium'    => 'email',
						'lt_gclid'     => 'GCLID-1',
						'unlisted_key' => 'must-not-persist',
					)
				),
				'ct_visitor_id'    => 'visitor-1',
			);

			$GLOBALS['wpdb']                             = new EddTest_Wpdb();
			$GLOBALS['clicktrail_test_dispatches']       = array();
			$GLOBALS['clicktrail_test_filters']          = array();
			$GLOBALS['clicktrail_test_options']          = array();
			$GLOBALS['clicktrail_test_cache']            = array();
			$GLOBALS['clicktrail_test_ext_object_cache'] = false;
			$GLOBALS['clicktrail_test_edd_meta']         = array();
			$GLOBALS['clicktrail_test_is_admin']         = false;
			$GLOBALS['clicktrail_test_doing_ajax']       = false;
			$GLOBALS['clicktrail_test_doing_cron']       = false;
		}

		protected function tearDown(): void {
			$_COOKIE = array();
			unset(
				$GLOBALS['wpdb'],
				$GLOBALS['clicktrail_test_dispatches'],
				$GLOBALS['clicktrail_test_dispatch_fails'],
				$GLOBALS['clicktrail_test_filters'],
				$GLOBALS['clicktrail_test_options'],
				$GLOBALS['clicktrail_test_cache'],
				$GLOBALS['clicktrail_test_ext_object_cache'],
				$GLOBALS['clicktrail_test_edd_meta'],
				$GLOBALS['clicktrail_test_is_admin'],
				$GLOBALS['clicktrail_test_doing_ajax'],
				$GLOBALS['clicktrail_test_doing_cron'],
				$GLOBALS['clicktrail_test_identity_input']
			);
		}

		/**
		 * Run the receipt hook and return what it printed.
		 *
		 * @param EddTest_Order|null $order Order double.
		 * @return string
		 */
		private function receipt( ?EddTest_Order $order = null ): string {
			ob_start();
			try {
				( new Edd() )->push_purchase_event( $order ?? new EddTest_Order(), array() );
				return (string) ob_get_contents();
			} finally {
				ob_end_clean();
			}
		}

		private function meta(): array {
			return $GLOBALS['clicktrail_test_edd_meta'][ self::ORDER_ID ] ?? array();
		}

		private function decode_push( string $output ): array {
			$this->assertSame( 1, preg_match( '/window\.dataLayer\.push\((.*)\);/s', $output, $match ), 'One dataLayer push expected.' );
			$decoded = json_decode( $match[1], true );
			$this->assertIsArray( $decoded );
			return $decoded;
		}

		public function test_integration_is_registered_before_edd_handles_the_checkout_post(): void {
			// EDD builds the order on init:10 (edd_post_actions); the plugin's own init runs at 20.
			$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/clicutcl.php' );

			$this->assertStringContainsString( "add_action( 'plugins_loaded', 'clicutcl_init_edd' );", $source );
			$this->assertStringContainsString( "class_exists( 'Easy_Digital_Downloads' )", $source );
			$this->assertStringNotContainsString( 'Integrations\\Edd', (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-clicutcl-core.php' ), 'Registering from the init:20 bootstrap would miss the checkout POST.' );
		}

		public function test_built_order_stores_consent_snapshot_and_only_known_attribution_keys(): void {
			( new Edd() )->save_order_attribution( self::ORDER_ID, array() );

			$meta = $this->meta();
			$this->assertArrayHasKey( Edd::CONSENT_META_KEY, $meta );
			$this->assertTrue( json_decode( $meta[ Edd::CONSENT_META_KEY ], true )['marketing'] );
			$this->assertSame( 'google', $meta['_clicutcl_ft_source'] );
			$this->assertSame( 'cpc', $meta['_clicutcl_ft_medium'] );
			$this->assertSame( 'newsletter', $meta['_clicutcl_lt_source'] );
			$this->assertSame( 'GCLID-1', $meta['_clicutcl_lt_gclid'] );
			$this->assertSame( 'GCLID-1', $meta['_clicutcl_gclid'], 'The canonical click ID follows the last touch.' );
			$this->assertSame( 'visitor-1', $meta['_clicutcl_visitor_id'] );
			$this->assertArrayNotHasKey( '_clicutcl_unlisted_key', $meta, 'Keys the privacy lifecycle cannot erase are never written.' );
			foreach ( array_keys( $meta ) as $key ) {
				$this->assertTrue( \CLICUTCL\Privacy\Woo_Order_Privacy::is_managed_meta_key( (string) $key ), $key . ' must be covered by export/erase/retention.' );
			}
		}

		public function test_stored_values_survive_the_slash_round_trip_of_core_metadata(): void {
			$_COOKIE['attribution'] = wp_json_encode( array( 'lt_landing_page' => '/p?q=a\\b' ) );

			( new Edd() )->save_order_attribution( self::ORDER_ID, array() );

			$this->assertSame( '/p?q=a\\b', $this->meta()['_clicutcl_lt_landing_page'] );
		}

		public function test_denied_consent_keeps_the_snapshot_but_drops_attribution(): void {
			require_once dirname( __DIR__ ) . '/helpers/consent-mode-settings-stub.php';
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = true;
			$GLOBALS['clicktrail_test_consent_required']     = true;
			$_COOKIE['production_consent']                   = 'denied';

			( new Edd() )->save_order_attribution( self::ORDER_ID, array() );

			$this->assertSame( array( Edd::CONSENT_META_KEY ), array_keys( $this->meta() ) );
			$this->assertFalse( json_decode( $this->meta()[ Edd::CONSENT_META_KEY ], true )['marketing'] );
		}

		public function test_cron_and_admin_page_requests_write_nothing(): void {
			$GLOBALS['clicktrail_test_doing_cron'] = true;
			( new Edd() )->save_order_attribution( self::ORDER_ID, array() );
			$this->assertSame( array(), $this->meta(), 'Renewals created by cron carry no visitor.' );

			$GLOBALS['clicktrail_test_doing_cron'] = false;
			$GLOBALS['clicktrail_test_is_admin']   = true;
			( new Edd() )->save_order_attribution( self::ORDER_ID, array() );
			$this->assertSame( array(), $this->meta(), 'An admin screen request is not the buyer.' );
		}

		public function test_admin_ajax_checkout_is_a_customer_request(): void {
			$GLOBALS['clicktrail_test_is_admin']   = true;
			$GLOBALS['clicktrail_test_doing_ajax'] = true;

			( new Edd() )->save_order_attribution( self::ORDER_ID, array() );

			$this->assertSame( 'GCLID-1', $this->meta()['_clicutcl_gclid'], 'EDD processes checkout through admin-ajax.php.' );
		}

		public function test_receipt_pushes_one_purchase_with_stable_event_id_and_saved_attribution(): void {
			( new Edd() )->save_order_attribution( self::ORDER_ID, array() );
			$_COOKIE['attribution'] = '';

			$output = $this->receipt();
			$push   = $this->decode_push( $output );

			$this->assertSame( 'purchase', $push['event'] );
			$this->assertSame( 'purchase_edd_4815', $push['event_id'] );
			$this->assertSame( 'EDD-4815', $push['ecommerce']['transaction_id'] );
			$this->assertEquals( 113.0, $push['ecommerce']['value'] );
			$this->assertSame( 'EUR', $push['ecommerce']['currency'] );
			$this->assertSame( 'SPRING10', $push['ecommerce']['discount_codes'][0] );
			$this->assertSame( 21, $push['ecommerce']['items'][0]['item_id'] );
			$this->assertEquals( 50.0, $push['ecommerce']['items'][0]['price'] );
			$this->assertSame( 2, $push['ecommerce']['items'][0]['quantity'] );
			$this->assertSame( 'GCLID-1', $push['gclid'], 'Saved order attribution, not the viewer cookie, is flattened into the push.' );
			$this->assertSame( 'newsletter', $push['lt_source'] );

			$this->assertCount( 1, $GLOBALS['clicktrail_test_dispatches'] );
			$dispatch = $GLOBALS['clicktrail_test_dispatches'][0];
			$this->assertSame( 'purchase', $dispatch['event_name'] );
			$this->assertSame( 'purchase_edd_4815', $dispatch['event_id'] );
			$this->assertSame( 4815, $dispatch['order_id'] );
			$this->assertSame( 'EDD-4815', $dispatch['transaction_id'] );
			$this->assertSame( 'google', $dispatch['attribution']['first_touch']['source'] );
			$this->assertSame( 'newsletter', $dispatch['attribution']['last_touch']['source'] );
			$this->assertTrue( $dispatch['consent']['marketing'], 'The checkout consent snapshot travels with the event.' );
			$this->assertSame( 'yes', $this->meta()[ Edd::SENT_META_KEY ] );
			$this->assertArrayNotHasKey( self::LOCK_KEY, $GLOBALS['clicktrail_test_options'], 'Lock released after the marker is saved.' );
		}

		public function test_reloading_the_receipt_does_not_fire_a_second_event(): void {
			( new Edd() )->save_order_attribution( self::ORDER_ID, array() );

			$first  = $this->receipt();
			$second = $this->receipt();

			$this->assertStringContainsString( 'window.dataLayer.push(', $first );
			$this->assertSame( '', $second );
			$this->assertCount( 1, $GLOBALS['clicktrail_test_dispatches'] );
		}

		public function test_withdrawn_marketing_consent_blocks_the_receipt_purchase(): void {
			require_once dirname( __DIR__ ) . '/helpers/consent-mode-settings-stub.php';
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = true;
			$GLOBALS['clicktrail_test_consent_required']     = true;
			$_COOKIE['production_consent']                   = 'denied';

			$this->assertSame( '', $this->receipt() );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_dispatches'] );
			$this->assertArrayNotHasKey( Edd::SENT_META_KEY, $this->meta(), 'A blocked view must not burn the one-shot marker.' );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_options'], 'No lock is taken without consent.' );
		}

		public function test_unpaid_and_refund_orders_do_not_fire(): void {
			$pending         = new EddTest_Order();
			$pending->status = 'pending';
			$this->assertSame( '', $this->receipt( $pending ) );

			$refunded         = new EddTest_Order();
			$refunded->status = 'refunded';
			$this->assertSame( '', $this->receipt( $refunded ) );

			$refund_row       = new EddTest_Order();
			$refund_row->type = 'refund';
			$this->assertSame( '', $this->receipt( $refund_row ) );

			$this->assertSame( array(), $GLOBALS['clicktrail_test_dispatches'] );
			$this->assertArrayNotHasKey( Edd::SENT_META_KEY, $this->meta() );
		}

		public function test_a_pending_order_fires_once_after_it_completes(): void {
			$order         = new EddTest_Order();
			$order->status = 'pending';
			$this->assertSame( '', $this->receipt( $order ) );

			$order->status = 'complete';
			$this->assertStringContainsString( 'window.dataLayer.push(', $this->receipt( $order ) );
			$this->assertCount( 1, $GLOBALS['clicktrail_test_dispatches'] );
		}

		public function test_racing_receipt_loads_dispatch_once(): void {
			$reentered = false;
			$GLOBALS['clicktrail_test_filters']['clicutcl_edd_purchase_payload'] = function ( $payload ) use ( &$reentered ) {
				if ( ! $reentered ) {
					$reentered = true;
					$this->assertSame( '', $this->receipt(), 'The rival request loses the lock.' );
				}
				return $payload;
			};

			$output = $this->receipt();

			$this->assertSame( 1, substr_count( $output, 'window.dataLayer.push(' ) );
			$this->assertCount( 1, $GLOBALS['clicktrail_test_dispatches'] );
			$this->assertSame( 'yes', $this->meta()[ Edd::SENT_META_KEY ] );
		}

		public function test_preexisting_marker_returns_before_taking_a_lock(): void {
			$GLOBALS['clicktrail_test_edd_meta'][ self::ORDER_ID ][ Edd::SENT_META_KEY ] = 'yes';

			$this->assertSame( '', $this->receipt() );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_options'], 'The marker is checked before any lock is taken.' );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_dispatches'] );
		}

		public function test_marker_written_between_the_first_check_and_the_lock_claim_wins(): void {
			// Request A finished and released its lock after B's first marker read but before B's claim.
			$edd = new class() extends Edd {
				public int $checks = 0;

				protected function is_purchase_sent( int $order_id ): bool {
					++$this->checks;
					return $this->checks > 1;
				}
			};

			ob_start();
			try {
				$edd->push_purchase_event( new EddTest_Order(), array() );
				$this->assertSame( '', ob_get_contents() );
			} finally {
				ob_end_clean();
			}

			$this->assertSame( 2, $edd->checks );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_dispatches'] );
			$this->assertArrayNotHasKey( self::LOCK_KEY, $GLOBALS['clicktrail_test_options'], 'The lock is released on the early return.' );
		}

		public function test_lock_held_by_another_request_skips_without_marking(): void {
			$rival = 'other-request|' . time();
			$GLOBALS['clicktrail_test_options'][ self::LOCK_KEY ] = $rival;

			$this->assertSame( '', $this->receipt() );

			$this->assertSame( array(), $GLOBALS['clicktrail_test_dispatches'] );
			$this->assertArrayNotHasKey( Edd::SENT_META_KEY, $this->meta() );
			$this->assertSame( $rival, $GLOBALS['clicktrail_test_options'][ self::LOCK_KEY ] );
		}

		public function test_failed_dispatch_stays_retryable_and_releases_the_lock(): void {
			$GLOBALS['clicktrail_test_dispatch_fails'] = true;

			$this->receipt();

			$this->assertCount( 1, $GLOBALS['clicktrail_test_dispatches'] );
			$this->assertArrayNotHasKey( Edd::SENT_META_KEY, $this->meta() );
			$this->assertArrayNotHasKey( self::LOCK_KEY, $GLOBALS['clicktrail_test_options'] );
		}

		public function test_payload_filter_can_adjust_the_dispatch(): void {
			$GLOBALS['clicktrail_test_filters']['clicutcl_edd_purchase_payload'] = static function ( $payload ) {
				$payload['value'] = 1.5;
				return $payload;
			};

			$this->receipt();

			$this->assertSame( 1.5, $GLOBALS['clicktrail_test_dispatches'][0]['value'] );
		}
	}
}
