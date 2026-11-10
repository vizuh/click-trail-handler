<?php
/**
 * WooCommerce order metadata privacy lifecycle contract tests.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/includes/Core/class-attribution-provider.php';
require_once dirname( __DIR__, 2 ) . '/includes/privacy/class-woo-order-privacy.php';


use CLICUTCL\Privacy\Woo_Order_Privacy;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wc_get_orders' ) ) {
	function wc_get_orders( $args = array() ) {
		$orders = $GLOBALS['clicktrail_test_wc_orders'] ?? array();
		if ( isset( $args['meta_query'] ) ) {
			// MySQL/MariaDB allow 61 tables per join and every meta clause costs one join (EXISTS never shares one).
			// The real failure surfaces as a database error and an empty result, so mimic that.
			$joins = count( array_filter( $args['meta_query'], 'is_array' ) );
			if ( $joins > 61 ) {
				$GLOBALS['clicktrail_test_wc_join_error'] = $joins;
				return array();
			}
			$orders = array_values( array_filter( $orders, static function ( $order ): bool {
				return ! empty( CLICUTCL\Privacy\Woo_Order_Privacy::managed_meta( $order->get_meta_data() ) );
			} ) );
		}
		$page  = max( 1, (int) ( $args['page'] ?? 1 ) );
		$limit = max( 1, (int) ( $args['limit'] ?? count( $orders ) ) );
		return array_slice( $orders, ( $page - 1 ) * $limit, $limit );
	}
}

/** Minimal order double for lifecycle tests. */
final class ClickTrail_Test_Woo_Order {
	/** @var int */
	public $id;
	/** @var array<int,array{key:string,value:mixed}> */
	public $meta;
	/** @var int */
	public $saves = 0;

	public function __construct( int $id, array $meta ) {
		$this->id   = $id;
		$this->meta = $meta;
	}

	public function get_id(): int {
		return $this->id;
	}

	public function get_meta_data(): array {
		return $this->meta;
	}

	public function delete_meta_data( string $key ): void {
		$this->meta = array_values( array_filter( $this->meta, static function ( array $meta ) use ( $key ): bool {
			return $meta['key'] !== $key;
		} ) );
	}

	public function save(): int {
		++$this->saves;
		return $this->id;
	}
}

/**
 * Class WooOrderPrivacyTest
 */
final class WooOrderPrivacyTest extends TestCase {

	public function test_inventory_covers_all_written_woo_surfaces_without_touching_unrelated_meta(): void {
		$managed = Woo_Order_Privacy::managed_meta(
			array(
				array( 'key' => '_clicutcl_ft_source', 'value' => 'google' ),
				array( 'key' => '_clicutcl_lt_gclid', 'value' => 'click-id' ),
				array( 'key' => '_clicutcl_consent', 'value' => '{"marketing":true}' ),
				array( 'key' => '_clicutcl_woo_trace_snapshot', 'value' => array( 'payload' => array() ) ),
				array( 'key' => '_clicutcl_visitor_id', 'value' => 'visitor' ),
				array( 'key' => '_clicutcl_session_id', 'value' => 'session' ),
				array( 'key' => '_clicutcl_tracking_sent', 'value' => 'yes' ),
				array( 'key' => '_clicutcl_woo_milestone_sent_order_paid', 'value' => '2026-01-01 00:00:00' ),
				array( 'key' => '_billing_email', 'value' => 'customer@example.test' ),
			)
		);

		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/woo-privacy/order-meta-lifecycle-v1.json' ), true );
		$this->assertSame( $fixture['managed_keys'], array_column( $managed, 'key' ) );
		$this->assertNotContains( $fixture['unmanaged_keys'][0], array_column( $managed, 'key' ) );
	}

	/**
	 * @dataProvider managedKeyProvider
	 */
	public function test_managed_key_allowlist_accepts_written_keys_and_rejects_unrelated_keys( string $key, bool $expected ): void {
		$this->assertSame( $expected, Woo_Order_Privacy::is_managed_meta_key( $key ) );
	}

	/**
	 * @return array<string,array{string,bool}>
	 */
	public static function managedKeyProvider(): array {
		return array(
			'first touch raw field' => array( '_clicutcl_ft_referrer', true ),
			'last touch click id' => array( '_clicutcl_lt_fbclid', true ),
			'attribution mapping field' => array( '_clicutcl_gclid', true ),
			'consent snapshot' => array( '_clicutcl_consent', true ),
			'trace payload' => array( '_clicutcl_woo_trace_snapshot', true ),
			'delivery marker' => array( '_clicutcl_tracking_sent', true ),
			'milestone marker' => array( '_clicutcl_woo_milestone_sent_order_refunded', true ),
			'order email is unrelated' => array( '_billing_email', false ),
			'lookalike plugin key is unrelated' => array( '_clicutcl_other_plugin_state', false ),
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['clicktrail_test_wc_orders'], $GLOBALS['clicktrail_test_wc_join_error'] );
	}

	public function test_export_reads_only_allowlisted_order_meta(): void {
		$order = new ClickTrail_Test_Woo_Order(
			42,
			array(
				array( 'key' => '_clicutcl_ft_source', 'value' => 'google' ),
				array( 'key' => '_clicutcl_woo_trace_snapshot', 'value' => array( 'event_name' => 'purchase' ) ),
				array( 'key' => '_billing_email', 'value' => 'customer@example.test' ),
			)
		);
		$GLOBALS['clicktrail_test_wc_orders'] = array( $order );

		$result = ( new Woo_Order_Privacy() )->export_order_data( 'customer@example.test' );

		$this->assertCount( 2, $result['items'] );
		$this->assertTrue( $result['done'] );
		$this->assertStringNotContainsString( '_billing_email', serialize( $result ) );
	}

	public function test_erase_removes_managed_meta_and_preserves_unrelated_meta(): void {
		$order = new ClickTrail_Test_Woo_Order(
			43,
			array(
				array( 'key' => '_clicutcl_consent', 'value' => '{}' ),
				array( 'key' => '_clicutcl_tracking_sent', 'value' => 'yes' ),
				array( 'key' => '_clicutcl_woo_milestone_sent_order_paid', 'value' => 'now' ),
				array( 'key' => '_shipping_city', 'value' => 'Porto' ),
			)
		);
		$GLOBALS['clicktrail_test_wc_orders'] = array( $order );

		$result = ( new Woo_Order_Privacy() )->erase_order_data( 'customer@example.test' );

		$this->assertTrue( $result['items_removed'] );
		// The purchase-sent and milestone markers hold no personal data and stop a second push, so they stay.
		$this->assertSame(
			array(
				array( 'key' => '_clicutcl_tracking_sent', 'value' => 'yes' ),
				array( 'key' => '_clicutcl_woo_milestone_sent_order_paid', 'value' => 'now' ),
				array( 'key' => '_shipping_city', 'value' => 'Porto' ),
			),
			$order->meta
		);
		$this->assertSame( 1, $order->saves );
		$this->assertArrayNotHasKey( 'clicktrail_test_wc_join_error', $GLOBALS );
	}

	public function test_retention_is_bounded_and_reports_more_expired_orders(): void {
		$orders = array(
			new ClickTrail_Test_Woo_Order( 1, array( array( 'key' => '_clicutcl_session_id', 'value' => 'one' ) ) ),
			new ClickTrail_Test_Woo_Order( 2, array( array( 'key' => '_clicutcl_trail_id', 'value' => 'two' ) ) ),
			new ClickTrail_Test_Woo_Order( 3, array( array( 'key' => '_clicutcl_gclid', 'value' => 'three' ) ) ),
		);
		$GLOBALS['clicktrail_test_wc_orders'] = $orders;

		$result = ( new Woo_Order_Privacy() )->purge_expired_order_metadata( 90, 2 );

		$this->assertSame( 2, $result['processed'] );
		$this->assertSame( 2, $result['removed'] );
		$this->assertTrue( $result['remaining'] );
		$this->assertEmpty( $orders[0]->meta );
		$this->assertEmpty( $orders[1]->meta );
		$this->assertNotEmpty( $orders[2]->meta );
	}

	public function test_uninstall_wires_order_purge_inside_preserve_gate(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );
		$this->assertStringContainsString( 'purge_all_order_metadata', $source );
		$this->assertStringContainsString( 'if ( ! $clicutcl_preserve_data )', $source );
		$this->assertStringContainsString( 'clicutcl_uninstall_order_meta_incomplete', $source );
	}


	public function test_erase_completes_when_a_full_page_has_no_managed_metadata(): void {
		$orders = array();
		for ( $id = 1; $id <= Woo_Order_Privacy::PAGE_SIZE; ++$id ) {
			$orders[] = new ClickTrail_Test_Woo_Order( $id, array( array( 'key' => '_shipping_city', 'value' => 'Porto' ) ) );
		}
		$GLOBALS['clicktrail_test_wc_orders'] = $orders;

		$result = ( new Woo_Order_Privacy() )->erase_order_data( 'customer@example.test' );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_managed_meta_query_is_one_key_clause_so_it_never_hits_the_61_table_join_limit(): void {
		$query = Woo_Order_Privacy::managed_meta_query();
		$this->assertCount( 1, $query );
		$this->assertSame( 'REGEXP', $query[0]['compare_key'] );
		$this->assertArrayNotHasKey( 'relation', $query );

		$regexp = '/' . str_replace( '/', '\\/', $query[0]['key'] ) . '/';
		$match  = static function ( string $key ) use ( $regexp ): bool {
			return 1 === preg_match( $regexp, $key );
		};
		$this->assertTrue( $match( '_clicutcl_gclid' ) );
		$this->assertTrue( $match( '_clicutcl_ft_source' ) );
		$this->assertTrue( $match( '_clicutcl_lt_medium' ) );
		$this->assertTrue( $match( '_clicutcl_woo_trace_snapshot' ) );
		$this->assertFalse( $match( '_clicutcl_tracking_sent' ), 'The sent marker is not a managed key unless asked for.' );
		$this->assertFalse( $match( '_clicutcl_woo_milestone_sent_order_paid' ), 'Milestone markers are not managed keys unless asked for.' );
		$this->assertFalse( $match( 'x_clicutcl_gclid' ), 'Exact keys are anchored at both ends.' );
		$this->assertFalse( $match( '_clicutcl_gclid_extra' ), 'Exact keys are anchored at both ends.' );
		$this->assertFalse( $match( '_clicutcl_other_plugin_state' ) );
		$this->assertFalse( $match( '_billing_email' ) );

		$with_marker = Woo_Order_Privacy::managed_meta_query( true );
		$this->assertSame( 1, preg_match( '/' . str_replace( '/', '\\/', $with_marker[0]['key'] ) . '/', '_clicutcl_tracking_sent' ) );
		$this->assertSame( 1, preg_match( '/' . str_replace( '/', '\\/', $with_marker[0]['key'] ) . '/', '_clicutcl_woo_milestone_sent_order_paid' ) );
	}

	public function test_lifecycle_fixture_markers_survive_every_cleanup_but_uninstall(): void {
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/woo-privacy/order-meta-lifecycle-v1.json' ), true );
		$meta    = array();
		foreach ( array_merge( $fixture['managed_keys'], $fixture['unmanaged_keys'] ) as $key ) {
			$meta[] = array( 'key' => $key, 'value' => 'v' );
		}
		$keep = array_merge( $fixture['preserved_keys'], $fixture['unmanaged_keys'] );

		$cleanups = array(
			'erase'      => static function ( Woo_Order_Privacy $privacy ): void {
				$privacy->erase_order_data( 'customer@example.test' );
			},
			'retention'  => static function ( Woo_Order_Privacy $privacy ): void {
				$privacy->purge_expired_order_metadata( 90 );
			},
			'diagnostics' => static function ( Woo_Order_Privacy $privacy ): void {
				$privacy->purge_all_order_metadata();
			},
		);
		foreach ( $cleanups as $name => $cleanup ) {
			$order                                = new ClickTrail_Test_Woo_Order( 11, $meta );
			$GLOBALS['clicktrail_test_wc_orders'] = array( $order );
			$cleanup( new Woo_Order_Privacy() );
			$this->assertSame( $keep, array_column( $order->meta, 'key' ), $name );
		}

		$order                                = new ClickTrail_Test_Woo_Order( 12, $meta );
		$GLOBALS['clicktrail_test_wc_orders'] = array( $order );
		( new Woo_Order_Privacy() )->purge_all_order_metadata( Woo_Order_Privacy::RETENTION_BATCH_SIZE, true );
		$this->assertSame( $fixture['unmanaged_keys'], array_column( $order->meta, 'key' ), 'uninstall' );
	}

	public function test_every_allowlisted_key_is_matched_by_the_query_pattern(): void {
		$families = Woo_Order_Privacy::managed_key_families();
		$this->assertGreaterThan( 61, count( $families['exact'] ) + count( $families['prefixes'] ), 'The allowlist is what overflowed the join limit.' );
		$query  = Woo_Order_Privacy::managed_meta_query( true );
		$regexp = '/' . str_replace( '/', '\\/', $query[0]['key'] ) . '/';
		foreach ( $families['exact'] as $key ) {
			$this->assertSame( 1, preg_match( $regexp, $key ), $key );
			$this->assertTrue( Woo_Order_Privacy::is_managed_meta_key( $key ), $key );
		}
		foreach ( $families['prefixes'] as $prefix ) {
			$this->assertSame( 1, preg_match( $regexp, $prefix . 'anything' ), $prefix );
		}
	}

	public function test_erase_and_retention_still_process_orders_under_a_join_limit(): void {
		$order = new ClickTrail_Test_Woo_Order( 7, array( array( 'key' => '_clicutcl_gclid', 'value' => 'g' ), array( 'key' => '_clicutcl_tracking_sent', 'value' => 'yes' ) ) );
		$GLOBALS['clicktrail_test_wc_orders'] = array( $order );

		$retention = ( new Woo_Order_Privacy() )->purge_expired_order_metadata( 90 );
		$this->assertSame( 1, $retention['removed'] );
		$this->assertArrayNotHasKey( 'clicktrail_test_wc_join_error', $GLOBALS );
		$this->assertSame( array( array( 'key' => '_clicutcl_tracking_sent', 'value' => 'yes' ) ), $order->meta );

		$order2 = new ClickTrail_Test_Woo_Order( 8, array( array( 'key' => '_clicutcl_gclid', 'value' => 'g' ) ) );
		$GLOBALS['clicktrail_test_wc_orders'] = array( $order2 );
		$erase = ( new Woo_Order_Privacy() )->erase_order_data( 'customer@example.test' );
		$this->assertTrue( $erase['items_removed'] );
		$this->assertArrayNotHasKey( 'clicktrail_test_wc_join_error', $GLOBALS );
		$this->assertEmpty( $order2->meta );
	}

	public function test_export_lists_the_sent_marker_but_retention_and_purge_keep_it_until_uninstall(): void {
		$meta  = array( array( 'key' => '_clicutcl_gclid', 'value' => 'g' ), array( 'key' => '_clicutcl_tracking_sent', 'value' => 'yes' ) );
		$order = new ClickTrail_Test_Woo_Order( 9, $meta );
		$GLOBALS['clicktrail_test_wc_orders'] = array( $order );

		$export = ( new Woo_Order_Privacy() )->export_order_data( 'customer@example.test' );
		$this->assertContains( 'clicutcl-woo-order-9-_clicutcl_tracking_sent', array_column( $export['items'], 'item_id' ) );

		$default = ( new Woo_Order_Privacy() )->purge_all_order_metadata();
		$this->assertSame( 1, $default['removed'] );
		$this->assertSame( array( array( 'key' => '_clicutcl_tracking_sent', 'value' => 'yes' ) ), $order->meta );

		$again = ( new Woo_Order_Privacy() )->purge_all_order_metadata();
		$this->assertSame( 0, $again['removed'], 'A marker-only order has nothing left to remove.' );

		$uninstall = ( new Woo_Order_Privacy() )->purge_all_order_metadata( Woo_Order_Privacy::RETENTION_BATCH_SIZE, true );
		$this->assertSame( 1, $uninstall['removed'] );
		$this->assertEmpty( $order->meta );
	}

	public function test_uninstall_removes_the_sent_marker(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );
		$this->assertMatchesRegularExpression( '/Woo_Order_Privacy\(\) \)->purge_all_order_metadata\( [^,]+, true \)/', $source );
	}

	public function test_erase_retries_page_one_to_reach_orders_after_prior_deletion(): void {
		$orders = array();
		for ( $id = 1; $id <= Woo_Order_Privacy::PAGE_SIZE + 1; ++$id ) {
			$orders[] = new ClickTrail_Test_Woo_Order( $id, array( array( 'key' => '_clicutcl_session_id', 'value' => 'session-' . $id ) ) );
		}
		$GLOBALS['clicktrail_test_wc_orders'] = $orders;

		$first = ( new Woo_Order_Privacy() )->erase_order_data( 'customer@example.test', 1 );
		$next  = ( new Woo_Order_Privacy() )->erase_order_data( 'customer@example.test', 2 );

		$this->assertFalse( $first['done'] );
		$this->assertTrue( $next['done'] );
		$this->assertEmpty( $orders[ Woo_Order_Privacy::PAGE_SIZE ]->meta );
	}

}
