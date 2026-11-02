<?php
/**
 * Easy Digital Downloads order metadata privacy lifecycle (#173).
 *
 * @package ClickTrail
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/includes/Core/class-attribution-provider.php';
require_once dirname( __DIR__, 2 ) . '/includes/privacy/class-woo-order-privacy.php';
require_once dirname( __DIR__, 2 ) . '/includes/privacy/class-edd-order-privacy.php';
require_once dirname( __DIR__ ) . '/helpers/edd-functions.php';

use CLICUTCL\Privacy\Edd_Order_Privacy;
use PHPUnit\Framework\TestCase;

/**
 * Class EddOrderPrivacyTest
 */
final class EddOrderPrivacyTest extends TestCase {
	private const EMAIL = 'customer@example.test';

	protected function setUp(): void {
		$GLOBALS['clicktrail_test_edd_meta']          = array();
		$GLOBALS['clicktrail_test_edd_orders']        = array();
		$GLOBALS['clicktrail_test_edd_order_queries'] = array();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['clicktrail_test_edd_meta'],
			$GLOBALS['clicktrail_test_edd_orders'],
			$GLOBALS['clicktrail_test_edd_order_queries'],
			$GLOBALS['clicktrail_test_edd_delete_fails']
		);
	}

	/**
	 * Register an order row and its meta.
	 *
	 * @param int                 $id           Order ID.
	 * @param array<string,mixed> $meta         Order meta.
	 * @param string              $date_created UTC creation date.
	 * @param string              $email        Billing email.
	 */
	private function add_order( int $id, array $meta, string $date_created = '2026-10-01 00:00:00', string $email = self::EMAIL ): void {
		$GLOBALS['clicktrail_test_edd_orders'][] = (object) array(
			'id'           => $id,
			'email'        => $email,
			'date_created' => $date_created,
		);
		$GLOBALS['clicktrail_test_edd_meta'][ $id ] = $meta;
	}

	public function test_export_reads_only_clicktrail_managed_meta_of_the_subject(): void {
		$this->add_order(
			42,
			array(
				'_clicutcl_ft_source'     => 'google',
				'_clicutcl_consent'       => '{"marketing":true}',
				'_edd_phone'              => '+351000000',
				'_clicutcl_other_plugin'  => 'unrelated',
			)
		);
		$this->add_order( 43, array( '_clicutcl_gclid' => 'someone-else' ), '2026-10-01 00:00:00', 'other@example.test' );

		$result = ( new Edd_Order_Privacy() )->export_order_data( self::EMAIL );

		$this->assertCount( 2, $result['items'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 'clicutcl_edd_orders', $result['items'][0]['group_id'] );
		$this->assertSame( 'clicutcl-edd-order-42-_clicutcl_ft_source', $result['items'][0]['item_id'] );
		$serialized = serialize( $result );
		$this->assertStringNotContainsString( '+351000000', $serialized );
		$this->assertStringNotContainsString( 'unrelated', $serialized );
		$this->assertStringNotContainsString( 'someone-else', $serialized );
	}

	public function test_export_pages_by_offset(): void {
		for ( $id = 1; $id <= Edd_Order_Privacy::PAGE_SIZE + 1; ++$id ) {
			$this->add_order( $id, array( '_clicutcl_session_id' => 's' . $id ) );
		}

		$first  = ( new Edd_Order_Privacy() )->export_order_data( self::EMAIL, 1 );
		$second = ( new Edd_Order_Privacy() )->export_order_data( self::EMAIL, 2 );

		$this->assertFalse( $first['done'] );
		$this->assertTrue( $second['done'] );
		$this->assertCount( 1, $second['items'] );
		$this->assertSame( Edd_Order_Privacy::PAGE_SIZE, $GLOBALS['clicktrail_test_edd_order_queries'][1]['offset'] );
	}

	public function test_erase_removes_managed_meta_and_preserves_everything_else(): void {
		$this->add_order(
			43,
			array(
				'_clicutcl_consent'       => '{}',
				'_clicutcl_tracking_sent' => 'yes',
				'_clicutcl_lt_gclid'      => 'G',
				'_edd_phone'              => '+351000000',
				'tax_rate'                => '23',
			)
		);

		$result = ( new Edd_Order_Privacy() )->erase_order_data( self::EMAIL );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertSame(
			array(
				'_edd_phone' => '+351000000',
				'tax_rate'   => '23',
			),
			$GLOBALS['clicktrail_test_edd_meta'][43]
		);
		$this->assertArrayHasKey( 'meta_query', $GLOBALS['clicktrail_test_edd_order_queries'][0], 'Erase only visits orders that carry managed meta.' );
	}

	public function test_erase_reports_retained_meta_when_a_delete_fails(): void {
		$this->add_order( 44, array( '_clicutcl_gclid' => 'G' ) );
		$GLOBALS['clicktrail_test_edd_delete_fails'] = true;

		$result = ( new Edd_Order_Privacy() )->erase_order_data( self::EMAIL );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertNotEmpty( $result['messages'] );
		$this->assertSame( array( '_clicutcl_gclid' => 'G' ), $GLOBALS['clicktrail_test_edd_meta'][44] );
	}

	public function test_erase_rereads_page_one_to_reach_orders_after_prior_deletion(): void {
		for ( $id = 1; $id <= Edd_Order_Privacy::PAGE_SIZE + 1; ++$id ) {
			$this->add_order( $id, array( '_clicutcl_session_id' => 's' . $id ) );
		}

		$first = ( new Edd_Order_Privacy() )->erase_order_data( self::EMAIL, 1 );
		$next  = ( new Edd_Order_Privacy() )->erase_order_data( self::EMAIL, 2 );

		$this->assertFalse( $first['done'] );
		$this->assertTrue( $next['done'] );
		$this->assertSame( array(), $GLOBALS['clicktrail_test_edd_meta'][ Edd_Order_Privacy::PAGE_SIZE + 1 ] );
	}

	public function test_retention_is_bounded_date_filtered_and_reports_more_expired_orders(): void {
		$this->add_order( 1, array( '_clicutcl_session_id' => 'one' ), '2026-01-01 00:00:00' );
		$this->add_order( 2, array( '_clicutcl_trail_id' => 'two' ), '2026-01-02 00:00:00' );
		$this->add_order( 3, array( '_clicutcl_gclid' => 'three' ), '2026-01-03 00:00:00' );
		$this->add_order( 4, array( '_clicutcl_gclid' => 'recent' ), gmdate( 'Y-m-d H:i:s' ) );

		$result = ( new Edd_Order_Privacy() )->purge_expired_order_metadata( 90, 2 );

		$this->assertSame( 2, $result['processed'] );
		$this->assertSame( 2, $result['removed'] );
		$this->assertTrue( $result['remaining'] );
		$this->assertSame( array(), $GLOBALS['clicktrail_test_edd_meta'][1] );
		$this->assertSame( array(), $GLOBALS['clicktrail_test_edd_meta'][2] );
		$this->assertNotEmpty( $GLOBALS['clicktrail_test_edd_meta'][3] );
		$this->assertNotEmpty( $GLOBALS['clicktrail_test_edd_meta'][4], 'Orders inside the retention window keep their meta.' );
	}

	public function test_purge_all_clears_every_order_across_batches_and_flags_failures(): void {
		for ( $id = 1; $id <= 5; ++$id ) {
			$this->add_order( $id, array( '_clicutcl_gclid' => 'G' . $id, 'keep' => 'me' ) );
		}

		$result = ( new Edd_Order_Privacy() )->purge_all_order_metadata( 2 );

		$this->assertSame( 5, $result['removed'] );
		$this->assertSame( 0, $result['failed'] );
		$this->assertFalse( $result['remaining'] );
		$this->assertSame( array( 'keep' => 'me' ), $GLOBALS['clicktrail_test_edd_meta'][5] );

		$this->add_order( 9, array( '_clicutcl_gclid' => 'stuck' ) );
		$GLOBALS['clicktrail_test_edd_delete_fails'] = true;

		$failed = ( new Edd_Order_Privacy() )->purge_all_order_metadata( 2 );

		$this->assertSame( 1, $failed['failed'] );
		$this->assertTrue( $failed['remaining'], 'A failed order must not loop forever and must be reported.' );
	}

	public function test_managed_meta_query_is_one_key_clause_so_it_never_hits_the_61_table_join_limit(): void {
		// WP_Meta_Query joins the meta table once per clause. The Woo allowlist has 130+ OR'd keys,
		// which MariaDB rejects ("can only use 61 tables in a join") on a real EDD order query.
		$query = Edd_Order_Privacy::managed_meta_query();

		$this->assertCount( 1, $query );
		$this->assertSame( 'REGEXP', $query[0]['compare_key'] );
		$pattern = '/' . str_replace( '/', '\\/', $query[0]['key'] ) . '/';

		foreach ( array( '_clicutcl_consent', '_clicutcl_tracking_sent', '_clicutcl_gclid', '_clicutcl_lt_gclid', '_clicutcl_ft_landing_page', '_clicutcl_visitor_id', '_clicutcl_session_count', '_clicutcl_woo_trace_snapshot' ) as $key ) {
			$this->assertSame( 1, preg_match( $pattern, $key ), $key . ' must be found by the query.' );
		}
		foreach ( array( '_edd_phone', 'tax_rate', '_clicutcl_other_plugin_state', 'x_clicutcl_consent', '_clicutcl_consent_extra' ) as $key ) {
			$this->assertSame( 0, preg_match( $pattern, $key ), $key . ' must not be found by the query.' );
		}
	}

	public function test_lifecycle_is_wired_into_every_privacy_entry_point(): void {
		$root = dirname( __DIR__, 2 );
		foreach ( array( 'includes/privacy/class-privacy-handler.php', 'includes/utils/class-cleanup.php', 'includes/admin/traits/trait-admin-diagnostics-ajax.php', 'uninstall.php' ) as $file ) {
			$this->assertStringContainsString( 'Edd_Order_Privacy', (string) file_get_contents( $root . '/' . $file ), $file . ' must run the EDD lifecycle.' );
		}
		$uninstall = (string) file_get_contents( $root . '/uninstall.php' );
		$this->assertStringContainsString( "function_exists( 'edd_get_orders' )", $uninstall );
	}
}
