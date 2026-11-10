<?php
/**
 * Queue::process() end to end against an in-memory $wpdb (audit F3): lock, time budget,
 * exhaustion into the dead-letter state, the consent-stall signal, and the retention purge.
 *
 * Every test runs in its own process: the doubles declare namespaced stand-ins that other
 * test files declare differently.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

use CLICUTCL\Server_Side\Dispatcher;
use CLICUTCL\Server_Side\Queue;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class QueueProcessTest extends TestCase {

	private const LOCK = 'clicutcl_queue_lock';

	private function boot(): \Delivery_Wpdb {
		if ( ! defined( 'CLICUTCL_DIR' ) ) {
			define( 'CLICUTCL_DIR', dirname( __DIR__, 2 ) . '/' );
		}
		require_once dirname( __DIR__ ) . '/helpers/delivery-resilience-doubles.php';
		foreach ( array(
			'Consent/class-decision-v1.php',
			'Consent/class-resolver-v1.php',
			'Consent/class-snapshot-v1.php',
			'server-side/class-consent.php',
			'server-side/class-event.php',
			'server-side/class-adapter-interface.php',
			'server-side/class-adapter-result.php',
			'server-side/class-generic-collector-adapter.php',
			'support/class-feature-registry.php',
			'tracking/class-claim-lock.php',
			'server-side/class-dispatcher.php',
			'server-side/class-queue.php',
		) as $file ) {
			require_once CLICUTCL_DIR . 'includes/' . $file;
		}
		return ct_delivery_boot();
	}

	private function seed( \Delivery_Wpdb $wpdb, int $count, int $attempts = 0 ): void {
		for ( $i = 1; $i <= $count; $i++ ) {
			ct_queue_row( $wpdb, 'q-' . $i, $attempts );
		}
	}

	private function failure_codes(): array {
		Dispatcher::flush_failure_telemetry();
		$codes = array();
		foreach ( Dispatcher::get_failure_telemetry() as $bucket ) {
			foreach ( $bucket['codes'] as $code => $count ) {
				$codes[ $code ] = ( $codes[ $code ] ?? 0 ) + $count;
			}
		}
		return $codes;
	}

	public function test_process_drains_more_than_ten_due_rows_in_one_run(): void {
		$wpdb                        = $this->boot();
		$GLOBALS['ct_http']['status'] = 200;
		$this->seed( $wpdb, 25 );

		Queue::process();

		$this->assertSame( 25, $GLOBALS['ct_http']['attempts'] );
		$this->assertSame( array(), $wpdb->rows );
		$this->assertArrayNotHasKey( self::LOCK, $GLOBALS['clicktrail_test_options'], 'Lock is released after the run.' );
	}

	public function test_process_stops_at_the_time_budget(): void {
		$wpdb                          = $this->boot();
		$GLOBALS['ct_http']['status']   = 200;
		$GLOBALS['ct_http']['delay_us'] = 300000;
		$GLOBALS['clicktrail_test_filters']['clicutcl_queue_time_budget'] = static fn() => 1;
		$this->seed( $wpdb, 20 );

		$started = microtime( true );
		Queue::process();
		$elapsed = microtime( true ) - $started;

		$this->assertGreaterThanOrEqual( 1, $GLOBALS['ct_http']['attempts'] );
		$this->assertLessThanOrEqual( 5, $GLOBALS['ct_http']['attempts'], 'A 1s budget at 0.3s per send stops after a handful.' );
		$this->assertCount( 20 - $GLOBALS['ct_http']['attempts'], $wpdb->rows, 'Unprocessed rows stay queued for the next run.' );
		$this->assertLessThan( 2.5, $elapsed );
		$this->assertArrayNotHasKey( self::LOCK, $GLOBALS['clicktrail_test_options'] );
	}

	public function test_time_budget_is_capped_below_the_lock_ttl(): void {
		$wpdb = $this->boot();
		$GLOBALS['ct_http']['status'] = 200;
		$GLOBALS['clicktrail_test_filters']['clicutcl_queue_time_budget'] = static fn() => 10 * Queue::LOCK_TTL;

		$this->assertLessThanOrEqual( Queue::LOCK_TTL / 2, Queue::get_time_budget() );
		$this->seed( $wpdb, 3 );
		Queue::process();
		$this->assertSame( 3, $GLOBALS['ct_http']['attempts'] );
	}

	public function test_rows_that_stay_due_are_sent_once_per_run_not_spun_on(): void {
		$wpdb               = $this->boot();
		$this->seed( $wpdb, 12 );
		$wpdb->fail_updates = true;

		$started = microtime( true );
		Queue::process();

		$this->assertSame( 12, $GLOBALS['ct_http']['attempts'], 'Each due row is attempted once per run even when its UPDATE fails.' );
		$this->assertLessThan( 5, microtime( true ) - $started, 'Must not loop until the budget on rows that stay due.' );
		$this->assertArrayNotHasKey( self::LOCK, $GLOBALS['clicktrail_test_options'] );
	}

	public function test_failed_send_backs_off_and_last_attempt_moves_row_to_dead_letter(): void {
		$wpdb  = $this->boot();
		$fresh = ct_queue_row( $wpdb, 'fresh', 0 );
		$last  = ct_queue_row( $wpdb, 'last', Queue::MAX_ATTEMPTS - 1 );

		Queue::process();

		$this->assertSame( 2, $GLOBALS['ct_http']['attempts'] );
		$this->assertSame( 'pending', $wpdb->rows[ $fresh ]['status'] );
		$this->assertSame( 1, $wpdb->rows[ $fresh ]['attempts'] );
		$this->assertGreaterThan( gmdate( 'Y-m-d H:i:s' ), $wpdb->rows[ $fresh ]['next_attempt_at'] );
		$this->assertSame( 'failed', $wpdb->rows[ $last ]['status'] );
		$this->assertSame( Queue::MAX_ATTEMPTS, $wpdb->rows[ $last ]['attempts'] );
	}

	public function test_fresh_lock_held_by_another_worker_blocks_the_run(): void {
		$wpdb = $this->boot();
		$this->seed( $wpdb, 3 );
		$held                                          = (string) ( time() - 10 );
		$GLOBALS['clicktrail_test_options'][ self::LOCK ] = $held;

		Queue::process();

		$this->assertSame( 0, $GLOBALS['ct_http']['attempts'] );
		$this->assertSame( $held, $GLOBALS['clicktrail_test_options'][ self::LOCK ], "Another worker's lock is never released." );
	}

	public function test_stale_lock_is_taken_over_and_released(): void {
		$wpdb                        = $this->boot();
		$GLOBALS['ct_http']['status'] = 200;
		$this->seed( $wpdb, 3 );
		$GLOBALS['clicktrail_test_options'][ self::LOCK ] = (string) ( time() - Queue::LOCK_TTL - 5 );

		Queue::process();

		$this->assertSame( 3, $GLOBALS['ct_http']['attempts'] );
		$this->assertArrayNotHasKey( self::LOCK, $GLOBALS['clicktrail_test_options'] );
	}

	public function test_lock_is_released_when_the_transport_throws(): void {
		$wpdb                       = $this->boot();
		$GLOBALS['ct_http']['throw'] = true;
		$this->seed( $wpdb, 2 );

		try {
			Queue::process();
			$this->fail( 'Exception should propagate.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'transport exploded', $e->getMessage() );
		}

		$this->assertArrayNotHasKey( self::LOCK, $GLOBALS['clicktrail_test_options'] );
	}

	public function test_consent_stall_keeps_rows_pending_and_is_counted_once_per_run(): void {
		$wpdb                               = $this->boot();
		$GLOBALS['ct_queue_consent_strict'] = true;
		$this->seed( $wpdb, 3 );

		Queue::process();

		$this->assertSame( 0, $GLOBALS['ct_http']['attempts'] );
		foreach ( $wpdb->rows as $row ) {
			$this->assertSame( 'pending', $row['status'] );
			$this->assertSame( 0, $row['attempts'], 'An unresolved consent authority does not consume attempts.' );
			$this->assertSame( 'consent_unresolved', $row['last_error'] );
		}
		$this->assertSame( 1, $this->failure_codes()['queue_consent_unresolved'] ?? 0 );
	}

	public function test_cleanup_counts_pending_rows_it_expires(): void {
		$wpdb = $this->boot();
		require_once CLICUTCL_DIR . 'includes/tracking/class-claim-lock.php';
		require_once CLICUTCL_DIR . 'includes/utils/class-cleanup.php';
		$GLOBALS['ct_http']['status'] = 200;
		ct_queue_row( $wpdb, 'old-1', 0, 10 );
		ct_queue_row( $wpdb, 'old-2', 2, 10 );
		ct_queue_row( $wpdb, 'old-3', 1, 10 );
		$dead_letter = ct_queue_row( $wpdb, 'dead-1', 5, 10, 'failed' );
		$fresh       = ct_queue_row( $wpdb, 'fresh-1', 0, 1 );

		( new \CLICUTCL\Utils\Cleanup() )->run_cleanup();

		$this->assertSame( array( $dead_letter, $fresh ), array_keys( $wpdb->rows ), 'Expired pending rows go; dead-letter keeps its longer retention.' );
		$expired = array_values(
			array_filter( $GLOBALS['clicktrail_test_actions'], static fn( $a ) => 'clicutcl_queue_pending_expired' === $a['tag'] )
		);
		$this->assertCount( 1, $expired );
		$this->assertSame( array( 3 ), $expired[0]['args'] );
		$this->assertSame( 3, $this->failure_codes()['queue_expired_pending'] ?? 0 );
	}

	public function test_cleanup_without_expired_pending_rows_reports_nothing(): void {
		$wpdb = $this->boot();
		require_once CLICUTCL_DIR . 'includes/tracking/class-claim-lock.php';
		require_once CLICUTCL_DIR . 'includes/utils/class-cleanup.php';
		ct_queue_row( $wpdb, 'fresh-1', 0, 1 );

		( new \CLICUTCL\Utils\Cleanup() )->run_cleanup();

		$this->assertCount( 1, $wpdb->rows );
		$this->assertSame( array(), array_filter( $GLOBALS['clicktrail_test_actions'], static fn( $a ) => 'clicutcl_queue_pending_expired' === $a['tag'] ) );
		$this->assertArrayNotHasKey( 'queue_expired_pending', $this->failure_codes() );
	}

	public function test_cleanup_on_a_pre_status_schema_counts_every_expired_row(): void {
		$wpdb             = $this->boot();
		$wpdb->has_status = false;
		require_once CLICUTCL_DIR . 'includes/tracking/class-claim-lock.php';
		require_once CLICUTCL_DIR . 'includes/utils/class-cleanup.php';
		ct_queue_row( $wpdb, 'old-1', 0, 10 );
		ct_queue_row( $wpdb, 'old-2', 0, 10 );
		ct_queue_row( $wpdb, 'fresh-1', 0, 1 );

		( new \CLICUTCL\Utils\Cleanup() )->run_cleanup();

		$this->assertCount( 1, $wpdb->rows );
		$this->assertSame( 2, $this->failure_codes()['queue_expired_pending'] ?? 0 );
	}
}
