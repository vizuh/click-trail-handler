<?php
/**
 * Dedup_Store hit/miss stats are buffered per request and flushed at shutdown,
 * so a dedup check never writes the shared `clicutcl_v2_dedup_stats` row
 * itself (F9). Dedup decisions and the get_stats() shape stay unchanged.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-dedup-store.php';

use CLICUTCL\Tracking\Dedup_Store;
use PHPUnit\Framework\TestCase;

/**
 * Class DedupStoreTest
 */
final class DedupStoreTest extends TestCase {

	private const STATS_KEY = 'clicutcl_v2_dedup_stats';

	protected function setUp(): void {
		$GLOBALS['clicktrail_test_transients'] = array();
		// Drop whatever an earlier test left buffered in this process.
		if ( method_exists( Dedup_Store::class, 'flush_stats' ) ) {
			Dedup_Store::flush_stats();
		}
		$GLOBALS['clicktrail_test_transients'] = array();
	}

	protected function tearDown(): void {
		$this->setUp();
	}

	public function test_a_check_does_not_write_the_shared_stats_row(): void {
		Dedup_Store::is_duplicate( 'ingest', 'page_view', 'evt_1' );
		Dedup_Store::is_duplicate( 'meta', 'page_view', 'evt_1' );
		Dedup_Store::is_duplicate( 'google', 'page_view', 'evt_1' );

		$this->assertArrayNotHasKey( self::STATS_KEY, $GLOBALS['clicktrail_test_transients'] );
	}

	public function test_get_stats_reports_checks_not_yet_flushed(): void {
		Dedup_Store::mark( 'ingest', 'lead', 'evt_seen', DAY_IN_SECONDS );
		Dedup_Store::is_duplicate( 'ingest', 'lead', 'evt_seen' );
		Dedup_Store::is_duplicate( 'ingest', 'lead', 'evt_new' );
		Dedup_Store::is_duplicate( 'meta', 'lead', 'evt_new' );

		$stats = Dedup_Store::get_stats();

		$this->assertSame( array( 'total_checks', 'hits', 'misses', 'by_destination', 'updated_at' ), array_keys( $stats ) );
		$this->assertSame( 3, $stats['total_checks'] );
		$this->assertSame( 1, $stats['hits'] );
		$this->assertSame( 2, $stats['misses'] );
		$this->assertSame(
			array(
				'ingest' => array(
					'hits'   => 1,
					'misses' => 1,
				),
				'meta'   => array(
					'hits'   => 0,
					'misses' => 1,
				),
			),
			$stats['by_destination']
		);
		$this->assertGreaterThan( 0, $stats['updated_at'] );
	}

	public function test_flush_persists_the_aggregated_stats_and_merges_with_the_stored_row(): void {
		$GLOBALS['clicktrail_test_transients'][ self::STATS_KEY ] = array(
			'total_checks'   => 10,
			'hits'           => 4,
			'misses'         => 6,
			'by_destination' => array(
				'ingest' => array(
					'hits'   => 4,
					'misses' => 6,
				),
			),
			'updated_at'     => 1,
		);
		Dedup_Store::mark( 'ingest', 'lead', 'evt_seen', DAY_IN_SECONDS );
		Dedup_Store::is_duplicate( 'ingest', 'lead', 'evt_seen' );
		Dedup_Store::is_duplicate( 'google', 'lead', 'evt_new' );

		Dedup_Store::flush_stats();
		$stored = $GLOBALS['clicktrail_test_transients'][ self::STATS_KEY ];

		$this->assertSame( 12, $stored['total_checks'] );
		$this->assertSame( 5, $stored['hits'] );
		$this->assertSame( 7, $stored['misses'] );
		$this->assertSame(
			array(
				'hits'   => 5,
				'misses' => 6,
			),
			$stored['by_destination']['ingest']
		);
		$this->assertSame(
			array(
				'hits'   => 0,
				'misses' => 1,
			),
			$stored['by_destination']['google']
		);
		$this->assertGreaterThan( 1, $stored['updated_at'] );
		// Nothing pending after the flush: get_stats() is the stored row.
		$this->assertSame( $stored, Dedup_Store::get_stats() );
	}

	public function test_flushes_are_throttled_so_the_row_is_written_at_most_once_per_window(): void {
		Dedup_Store::is_duplicate( 'ingest', 'lead', 'evt_a' );
		Dedup_Store::flush_stats();
		$after_first = $GLOBALS['clicktrail_test_transients'][ self::STATS_KEY ];

		// A later request inside the same window: its deltas are not written.
		Dedup_Store::is_duplicate( 'ingest', 'lead', 'evt_b' );
		Dedup_Store::flush_stats();

		$this->assertSame( $after_first, $GLOBALS['clicktrail_test_transients'][ self::STATS_KEY ] );
		$this->assertSame( 1, Dedup_Store::get_stats()['total_checks'] );
	}

	public function test_checks_excluded_from_stats_are_not_buffered(): void {
		Dedup_Store::is_duplicate( 'form_submission', 'gravityforms', 'fp_1', false );
		Dedup_Store::flush_stats();

		$this->assertArrayNotHasKey( self::STATS_KEY, $GLOBALS['clicktrail_test_transients'] );
		$this->assertSame( 0, Dedup_Store::get_stats()['total_checks'] );
	}

	public function test_dedup_decisions_are_unchanged(): void {
		$this->assertFalse( Dedup_Store::is_duplicate( 'ingest', 'lead', 'evt_1' ) );
		Dedup_Store::mark( 'ingest', 'lead', 'evt_1', DAY_IN_SECONDS );

		$this->assertTrue( Dedup_Store::is_duplicate( 'ingest', 'lead', 'evt_1' ) );
		$this->assertFalse( Dedup_Store::is_duplicate( 'ingest', 'lead', 'evt_2' ) );
		$this->assertFalse( Dedup_Store::is_duplicate( 'meta', 'lead', 'evt_1' ) );
	}
}
