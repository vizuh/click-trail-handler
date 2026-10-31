<?php
/**
 * Thank-you purchase dispatch lock: two concurrent loads dispatch once (#148).
 *
 * @package ClickTrail
 */

declare(strict_types=1);

namespace CLICUTCL\Server_Side {
	if ( ! class_exists( Dispatcher::class, false ) ) {
		/**
		 * Counting Dispatcher double; the real one needs the full server-side stack.
		 */
		class Dispatcher {
			/**
			 * Record a commerce dispatch and report success unless a test forces an error.
			 *
			 * @param array $payload Purchase payload.
			 * @return Adapter_Result
			 */
			public static function dispatch_commerce_event( $payload ) {
				$GLOBALS['clicktrail_test_dispatches'][] = $payload;
				if ( ! empty( $GLOBALS['clicktrail_test_dispatch_fails'] ) ) {
					return Adapter_Result::error( 500, 'collector_down' );
				}
				return Adapter_Result::success( 200, 'sent' );
			}

			/**
			 * Adapter key used in trace snapshots.
			 *
			 * @return string
			 */
			public static function get_adapter_key() {
				return 'generic';
			}
		}
	}
}

namespace CLICUTCL\Tracking {
	if ( ! class_exists( Identity_Resolver::class, false ) ) {
		/**
		 * Identity double: the thank-you path only needs an array back.
		 */
		class Identity_Resolver {
			/**
			 * Return an empty identity payload.
			 *
			 * @param array $input   Identity input.
			 * @param array $options Resolver options.
			 * @return array
			 */
			public function resolve( array $input, array $options = array() ): array {
				return array();
			}
		}
	}
}

namespace CLICUTCL\Modules\GTM {
	if ( ! class_exists( GTM_Settings::class, false ) ) {
		/**
		 * GTM settings double: classic (non-enhanced) Woo dataLayer contract.
		 */
		class GTM_Settings {
			/**
			 * Return empty settings.
			 *
			 * @return array
			 */
			public function get(): array {
				return array();
			}
		}
	}
}

namespace {
	require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-adapter-result.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-decision-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-resolver-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-snapshot-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-consent.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Core/class-attribution-provider.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-canonicaleventinterfacev2.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-eventv2.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-event-translator-v1-to-v2.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/class-woocommerce.php';

	use CLICUTCL\Integrations\WooCommerce;
	use PHPUnit\Framework\TestCase;

	if ( ! function_exists( 'wc_get_order' ) ) {
		/**
		 * Load a fresh order double from the shared test "database", like a second request would.
		 *
		 * @param int $order_id Order ID.
		 * @return object|null
		 */
		function wc_get_order( $order_id ) {
			if ( ! isset( $GLOBALS['clicktrail_test_woo_db'][ $order_id ] ) ) {
				return null;
			}
			return new ClickTrail_Test_Order( (int) $order_id );
		}
	}

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

	if ( ! function_exists( 'get_post_meta' ) ) {
		function get_post_meta( ...$args ) {
			++$GLOBALS['clicktrail_test_post_meta_calls'];
			return '';
		}
	}

	if ( ! function_exists( 'update_post_meta' ) ) {
		function update_post_meta( ...$args ) {
			++$GLOBALS['clicktrail_test_post_meta_calls'];
			return true;
		}
	}

	/**
	 * Minimal `$wpdb` double for the row-conditional lock delete.
	 *
	 * `delete()` removes the option only when both the name and the exact stored
	 * value match, returning the affected-row count like the real method.
	 */
	final class ClickTrail_Test_Wpdb {
		public string $options = 'wp_options';

		/**
		 * Recorded where-clauses of every delete() call.
		 *
		 * @var array<int,array<string,string>>
		 */
		public array $deletes = array();

		/**
		 * Optional hook run before each delete(), to interleave a rival request.
		 *
		 * @var callable|null
		 */
		public $before_delete = null;

		public function delete( $table, $where ) {
			if ( is_callable( $this->before_delete ) ) {
				( $this->before_delete )();
			}
			$this->deletes[] = $where;

			$name = (string) $where['option_name'];
			if ( ! isset( $GLOBALS['clicktrail_test_options'][ $name ] ) || (string) $GLOBALS['clicktrail_test_options'][ $name ] !== (string) $where['option_value'] ) {
				return 0;
			}
			unset( $GLOBALS['clicktrail_test_options'][ $name ] );
			return 1;
		}
	}

	/**
	 * Minimal WC_Order double backed by a shared per-ID "database" array.
	 *
	 * Meta reads come from the instance snapshot taken at load; `save()` writes
	 * the instance back, mirroring how two requests each hydrate their own
	 * order object from storage. Only the order object API is exposed: posts
	 * storage and HPOS look identical to the integration.
	 */
	final class ClickTrail_Test_Order {
		/**
		 * Order ID.
		 *
		 * @var int
		 */
		private int $id;

		/**
		 * In-memory meta snapshot.
		 *
		 * @var array<string,mixed>
		 */
		private array $meta;

		/**
		 * Hydrate from the shared database.
		 *
		 * @param int $id Order ID.
		 */
		public function __construct( int $id ) {
			$this->id   = $id;
			$this->meta = $GLOBALS['clicktrail_test_woo_db'][ $id ];
		}

		public function get_id() {
			return $this->id;
		}

		public function get_meta( $key, $single = true ) {
			return $this->meta[ $key ] ?? '';
		}

		public function update_meta_data( $key, $value ): void {
			$this->meta[ (string) $key ] = $value;
		}

		public function save(): void {
			$GLOBALS['clicktrail_test_woo_db'][ $this->id ] = $this->meta;
			++$GLOBALS['clicktrail_test_woo_saves'];
		}

		public function get_meta_data(): array {
			$out = array();
			foreach ( $this->meta as $key => $value ) {
				$out[] = (object) array(
					'key'   => $key,
					'value' => $value,
				);
			}
			return $out;
		}

		public function get_order_number() {
			return (string) $this->id;
		}

		public function get_total() {
			return 99.5;
		}

		public function get_currency() {
			return 'EUR';
		}

		public function get_coupon_codes() {
			return array();
		}

		public function get_status() {
			return 'processing';
		}

		public function get_items() {
			return array();
		}

		public function get_billing_email() {
			return 'buyer@example.com';
		}

		public function get_billing_country() {
			return 'PT';
		}

		public function get_billing_phone() {
			return '';
		}
	}

	/**
	 * Class WooCommercePurchaseDispatchLockTest
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class WooCommercePurchaseDispatchLockTest extends TestCase {
		private const ORDER_ID = 4815;
		public const LOCK_KEY  = 'clicutcl_purchase_lock_4815';

		protected function setUp(): void {
			$_COOKIE = array( 'ct_consent_state' => wp_json_encode( array( 'marketing' => true ) ) );
			$GLOBALS['wpdb']                             = new ClickTrail_Test_Wpdb();
			$GLOBALS['clicktrail_test_dispatches']       = array();
			$GLOBALS['clicktrail_test_filters']          = array();
			$GLOBALS['clicktrail_test_options']          = array();
			$GLOBALS['clicktrail_test_cache']            = array();
			$GLOBALS['clicktrail_test_ext_object_cache'] = false;
			$GLOBALS['clicktrail_test_post_meta_calls']  = 0;
			$GLOBALS['clicktrail_test_woo_saves']        = 0;
			$GLOBALS['clicktrail_test_woo_db']           = array(
				self::ORDER_ID => array( '_clicutcl_lt_source' => 'google' ),
			);
		}

		protected function tearDown(): void {
			$_COOKIE = array();
			unset(
				$GLOBALS['wpdb'],
				$GLOBALS['clicktrail_test_dispatches'],
				$GLOBALS['clicktrail_test_filters'],
				$GLOBALS['clicktrail_test_options'],
				$GLOBALS['clicktrail_test_cache'],
				$GLOBALS['clicktrail_test_ext_object_cache'],
				$GLOBALS['clicktrail_test_post_meta_calls'],
				$GLOBALS['clicktrail_test_woo_saves'],
				$GLOBALS['clicktrail_test_woo_db']
			);
		}

		/**
		 * Re-enter the thank-you hook from inside the first call's dispatch path,
		 * after the marker check but before the marker is saved. Returns the output
		 * produced by both calls together.
		 *
		 * @return string
		 */
		private function run_racing_thank_you_loads(): string {
			$reentered = false;

			$GLOBALS['clicktrail_test_filters']['clicutcl_woocommerce_purchase_payload'] = static function ( $payload ) use ( &$reentered ) {
				if ( ! $reentered ) {
					$reentered = true;
					( new WooCommerce() )->push_purchase_event( self::ORDER_ID );
				}
				return $payload;
			};

			ob_start();
			try {
				( new WooCommerce() )->push_purchase_event( self::ORDER_ID );
				return (string) ob_get_contents();
			} finally {
				ob_end_clean();
			}
		}

		public function test_racing_loads_dispatch_once_with_option_lock_fallback(): void {
			$output = $this->run_racing_thank_you_loads();

			$this->assertCount( 1, $GLOBALS['clicktrail_test_dispatches'], 'Exactly one server dispatch.' );
			$this->assertSame( 1, substr_count( $output, 'window.dataLayer.push(' ), 'Exactly one dataLayer purchase push.' );
			$this->assertSame( 'yes', $GLOBALS['clicktrail_test_woo_db'][ self::ORDER_ID ]['_clicutcl_tracking_sent'] );
			$this->assertArrayNotHasKey( 'clicutcl_purchase_lock_' . self::ORDER_ID, $GLOBALS['clicktrail_test_options'], 'Option lock released after the marker is saved.' );
			$this->assertSame( 0, $GLOBALS['clicktrail_test_post_meta_calls'], 'Guard uses the order object API only (posts storage and HPOS identical).' );
		}

		public function test_racing_loads_dispatch_once_with_object_cache_lock(): void {
			$GLOBALS['clicktrail_test_ext_object_cache'] = true;

			$output = $this->run_racing_thank_you_loads();

			$this->assertCount( 1, $GLOBALS['clicktrail_test_dispatches'], 'Exactly one server dispatch.' );
			$this->assertSame( 1, substr_count( $output, 'window.dataLayer.push(' ), 'Exactly one dataLayer purchase push.' );
			$this->assertSame( 'yes', $GLOBALS['clicktrail_test_woo_db'][ self::ORDER_ID ]['_clicutcl_tracking_sent'] );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_options'], 'No option row is written when a persistent object cache holds the lock.' );
			$this->assertArrayNotHasKey( 'clicutcl_purchase_lock_' . self::ORDER_ID, $GLOBALS['clicktrail_test_cache']['clicutcl'] ?? array(), 'Cache lock released after the marker is saved.' );
			$this->assertSame( 0, $GLOBALS['clicktrail_test_post_meta_calls'] );
		}

		public function test_preexisting_marker_returns_before_taking_a_lock(): void {
			$GLOBALS['clicktrail_test_woo_db'][ self::ORDER_ID ]['_clicutcl_tracking_sent'] = 'yes';

			ob_start();
			try {
				( new WooCommerce() )->push_purchase_event( self::ORDER_ID );
				$this->assertSame( '', ob_get_contents() );
			} finally {
				ob_end_clean();
			}

			$this->assertSame( array(), $GLOBALS['clicktrail_test_dispatches'] );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_options'] );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_cache'] );
			$this->assertSame( 0, $GLOBALS['clicktrail_test_woo_saves'] );
		}

		public function test_failed_dispatch_releases_lock_and_leaves_marker_unset_for_retry(): void {
			$GLOBALS['clicktrail_test_dispatch_fails'] = true;

			ob_start();
			try {
				( new WooCommerce() )->push_purchase_event( self::ORDER_ID );
			} finally {
				ob_end_clean();
				unset( $GLOBALS['clicktrail_test_dispatch_fails'] );
			}

			$this->assertCount( 1, $GLOBALS['clicktrail_test_dispatches'] );
			$this->assertArrayNotHasKey( '_clicutcl_tracking_sent', $GLOBALS['clicktrail_test_woo_db'][ self::ORDER_ID ], 'A failed dispatch must stay retryable on reload.' );
			$this->assertArrayNotHasKey( 'clicutcl_purchase_lock_' . self::ORDER_ID, $GLOBALS['clicktrail_test_options'], 'Lock released even when the dispatch did not mark the order.' );
		}

		public function test_lock_held_by_another_request_skips_dispatch_without_marking(): void {
			$rival = 'other-request|' . time();

			$GLOBALS['clicktrail_test_options'][ self::LOCK_KEY ] = $rival;

			ob_start();
			try {
				( new WooCommerce() )->push_purchase_event( self::ORDER_ID );
				$this->assertSame( '', ob_get_contents() );
			} finally {
				ob_end_clean();
			}

			$this->assertSame( array(), $GLOBALS['clicktrail_test_dispatches'] );
			$this->assertArrayNotHasKey( '_clicutcl_tracking_sent', $GLOBALS['clicktrail_test_woo_db'][ self::ORDER_ID ] );
			$this->assertSame( $rival, $GLOBALS['clicktrail_test_options'][ self::LOCK_KEY ], 'The loser must not release a lock it does not own.' );
			$this->assertSame( array(), $GLOBALS['wpdb']->deletes, 'A fresh lock is never deleted by a loser.' );
		}

		public function test_stale_option_lock_is_taken_over(): void {
			$stale = 'dead-request|' . ( time() - 31 );

			$GLOBALS['clicktrail_test_options'][ self::LOCK_KEY ] = $stale;

			ob_start();
			try {
				( new WooCommerce() )->push_purchase_event( self::ORDER_ID );
				$this->assertSame( 1, substr_count( (string) ob_get_contents(), 'window.dataLayer.push(' ) );
			} finally {
				ob_end_clean();
			}

			$this->assertCount( 1, $GLOBALS['clicktrail_test_dispatches'] );
			$this->assertSame( 'yes', $GLOBALS['clicktrail_test_woo_db'][ self::ORDER_ID ]['_clicutcl_tracking_sent'] );
			$this->assertArrayNotHasKey( self::LOCK_KEY, $GLOBALS['clicktrail_test_options'] );

			$deleted_values = array_column( $GLOBALS['wpdb']->deletes, 'option_value' );
			$this->assertCount( 2, $deleted_values, 'One conditional delete for the takeover, one for the owner release.' );
			$this->assertSame( $stale, $deleted_values[0], 'Takeover deletes exactly the stale value it read.' );
			$this->assertMatchesRegularExpression( '/^uuid-[^|]+\|\d+$/', $deleted_values[1], 'Release deletes the value this request stored.' );
		}

		public function test_stale_takeover_loses_when_the_row_changed_first(): void {
			$rival = 'rival-request|' . time();

			$GLOBALS['clicktrail_test_options'][ self::LOCK_KEY ] = 'dead-request|' . ( time() - 31 );
			// Another late arrival re-claims between our read and our conditional delete.
			$GLOBALS['wpdb']->before_delete = static function () use ( $rival ): void {
				$GLOBALS['clicktrail_test_options'][ WooCommercePurchaseDispatchLockTest::LOCK_KEY ] = $rival;
			};

			ob_start();
			try {
				( new WooCommerce() )->push_purchase_event( self::ORDER_ID );
				$this->assertSame( '', ob_get_contents() );
			} finally {
				ob_end_clean();
			}

			$this->assertSame( array(), $GLOBALS['clicktrail_test_dispatches'] );
			$this->assertArrayNotHasKey( '_clicutcl_tracking_sent', $GLOBALS['clicktrail_test_woo_db'][ self::ORDER_ID ] );
			$this->assertSame( $rival, $GLOBALS['clicktrail_test_options'][ self::LOCK_KEY ], 'The rival keeps its lock.' );
			$this->assertCount( 1, $GLOBALS['wpdb']->deletes, 'The loser does not attempt an owner release.' );
		}

		public function test_expired_option_owner_does_not_release_the_successor_lock(): void {
			$successor = 'successor|' . time();

			$GLOBALS['clicktrail_test_options'][ self::LOCK_KEY ] = $successor;

			$release = new ReflectionMethod( WooCommerce::class, 'release_purchase_lock' );
			$release->setAccessible( true );
			$release->invoke( new WooCommerce(), self::ORDER_ID, 'expired-owner|' . ( time() - 40 ) );

			$this->assertSame( $successor, $GLOBALS['clicktrail_test_options'][ self::LOCK_KEY ] );

			$release->invoke( new WooCommerce(), self::ORDER_ID, $successor );
			$this->assertArrayNotHasKey( self::LOCK_KEY, $GLOBALS['clicktrail_test_options'], 'The owner itself still releases.' );
		}

		public function test_expired_cache_owner_does_not_release_the_successor_lock(): void {
			$GLOBALS['clicktrail_test_ext_object_cache']               = true;
			$GLOBALS['clicktrail_test_cache']['clicutcl'][ self::LOCK_KEY ] = 'successor-token';

			$release = new ReflectionMethod( WooCommerce::class, 'release_purchase_lock' );
			$release->setAccessible( true );
			$release->invoke( new WooCommerce(), self::ORDER_ID, 'expired-token' );

			$this->assertSame( 'successor-token', $GLOBALS['clicktrail_test_cache']['clicutcl'][ self::LOCK_KEY ] );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_options'], 'Cache path never touches the options table.' );

			$release->invoke( new WooCommerce(), self::ORDER_ID, 'successor-token' );
			$this->assertArrayNotHasKey( self::LOCK_KEY, $GLOBALS['clicktrail_test_cache']['clicutcl'] );
		}

		public function test_acquire_returns_a_request_unique_value(): void {
			$acquire = new ReflectionMethod( WooCommerce::class, 'acquire_purchase_lock' );
			$acquire->setAccessible( true );

			$first = $acquire->invoke( new WooCommerce(), self::ORDER_ID );
			$this->assertNotSame( '', $first );
			$this->assertSame( $first, $GLOBALS['clicktrail_test_options'][ self::LOCK_KEY ] );
			$this->assertSame( '', $acquire->invoke( new WooCommerce(), self::ORDER_ID ), 'A fresh lock is not re-claimable.' );

			$GLOBALS['clicktrail_test_ext_object_cache'] = true;
			$cached                                       = $acquire->invoke( new WooCommerce(), self::ORDER_ID );
			$this->assertNotSame( '', $cached );
			$this->assertNotSame( $first, $cached );
			$this->assertSame( $cached, $GLOBALS['clicktrail_test_cache']['clicutcl'][ self::LOCK_KEY ] );
		}
	}
}
