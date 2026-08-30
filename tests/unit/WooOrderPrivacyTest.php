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
		unset( $GLOBALS['clicktrail_test_wc_orders'] );
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
		$this->assertSame( array( array( 'key' => '_shipping_city', 'value' => 'Porto' ) ), $order->meta );
		$this->assertSame( 1, $order->saves );
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

	public function test_retention_query_contains_exact_keys_and_managed_prefix_filters(): void {
		$query = Woo_Order_Privacy::managed_meta_query();
		$this->assertSame( 'OR', $query['relation'] );

		$prefixes = array_column( array_filter( $query, 'is_array' ), 'key' );
		$this->assertContains( '_clicutcl_ft_', $prefixes );
		$this->assertContains( '_clicutcl_lt_', $prefixes );
		$this->assertContains( '_clicutcl_woo_milestone_sent_', $prefixes );
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
