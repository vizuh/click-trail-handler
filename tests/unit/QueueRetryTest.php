<?php
/**
 * Queue retry semantics: backoff interval calculation and dead-letter
 * threshold, the pure-logic slice of class-queue.php reachable without a
 * live $wpdb/DB harness.
 *
 * Pure helper checks; QueueProcessingTest additionally exercises process_row()
 * and its failure mutations with storage/HTTP spies in an isolated process.
 * Real database locking and scheduler behavior remain separate runtime checks.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-decision-v1.php';
require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-resolver-v1.php';
require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-snapshot-v1.php';
require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-queue.php';

use CLICUTCL\Consent\Snapshot_V1;
use CLICUTCL\Server_Side\Queue;
use PHPUnit\Framework\TestCase;

/**
 * Class QueueRetryTest
 */
final class QueueRetryTest extends TestCase {

	/**
	 * Invoke the private static backoff calculator via reflection -- it is
	 * pure (no $wpdb access), so this is the only way to exercise it without
	 * a live DB harness.
	 *
	 * @param int $attempt Attempt count.
	 * @return int
	 */
	private function backoff_seconds( int $attempt ): int {
		$method = new \ReflectionMethod( Queue::class, 'get_backoff_seconds' );
		$method->setAccessible( true );
		return $method->invoke( null, $attempt );
	}

	public function test_backoff_doubles_with_each_attempt(): void {
		$this->assertSame( 120, $this->backoff_seconds( 1 ) );
		$this->assertSame( 240, $this->backoff_seconds( 2 ) );
		$this->assertSame( 480, $this->backoff_seconds( 3 ) );
		$this->assertSame( 960, $this->backoff_seconds( 4 ) );
	}

	public function test_backoff_caps_at_one_hour(): void {
		$this->assertSame( 3600, $this->backoff_seconds( 6 ) );
		$this->assertSame( 3600, $this->backoff_seconds( 20 ) );
	}

	public function test_backoff_floor_is_sixty_seconds_at_zero_attempts(): void {
		$this->assertSame( 60, $this->backoff_seconds( 0 ) );
	}

	public function test_backoff_treats_negative_attempts_as_absolute_value(): void {
		// get_backoff_seconds() runs the attempt count through absint(), so a
		// negative input is not clamped to the floor -- it is treated as its
		// magnitude. Attempt -3 behaves identically to attempt 3.
		$this->assertSame( $this->backoff_seconds( 3 ), $this->backoff_seconds( -3 ) );
	}

	public function test_max_attempts_threshold_matches_backoff_growth(): void {
		// update_row_failure() dead-letters a row once $attempts >= MAX_ATTEMPTS.
		// Confirm the backoff schedule actually reaches that many distinct,
		// increasing retry windows before the row would be dropped -- a
		// regression here (e.g. MAX_ATTEMPTS raised without the backoff table
		// being reviewed) would silently change the retry/dead-letter shape.
		$delays = array();
		for ( $attempt = 1; $attempt < Queue::MAX_ATTEMPTS; $attempt++ ) {
			$delays[] = $this->backoff_seconds( $attempt );
		}

		$this->assertSame( array( 120, 240, 480, 960 ), $delays );
		$this->assertSame( $delays, array_unique( $delays ), 'Backoff windows must strictly increase, not repeat.' );
	}

	// ------------------------------------------------------------------
	// redact_retry_payload() -- covered lightly in BoundarySecurityTest;
	// this adds the case where transport identity is entirely absent, so
	// the redaction is a genuine no-op rather than silently succeeding on
	// missing keys.
	// ------------------------------------------------------------------

	public function test_redact_retry_payload_is_noop_when_no_identity_present(): void {
		$payload = array( 'event_name' => 'lead', 'meta' => array() );

		$result = Queue::redact_retry_payload( $payload );

		$this->assertSame( $payload, $result );
	}

	public function test_required_consent_grant_allows_a_queued_retry(): void {
		$grant = Snapshot_V1::capture( array( 'marketing' => true ), true, 1000 );

		$this->assertTrue( Queue::consent_allows_retry( $grant, true ) );
	}

	public function test_required_consent_withdrawal_blocks_a_queued_retry(): void {
		$withdrawal = Snapshot_V1::capture( array( 'marketing' => false ), true, 1001 );

		$this->assertFalse( Queue::consent_allows_retry( $withdrawal, true ) );
	}

	public function test_required_retry_fails_closed_for_absent_or_malformed_consent(): void {
		$this->assertFalse( Queue::consent_allows_retry( array(), true ) );
		$this->assertFalse( Queue::consent_allows_retry( '{not-json', true ) );
		$this->assertFalse(
			Queue::consent_allows_retry(
				array(
					'schema_version' => 1,
					'decision'       => 'maybe',
					'basis'          => 'cmp',
				),
				true
			)
		);
	}

	public function test_not_required_retry_does_not_need_a_consent_snapshot(): void {
		$this->assertTrue( Queue::consent_allows_retry( array(), false ) );
	}
}
