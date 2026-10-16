<?php
/** Queue drain throughput in an isolated PHP process with storage/HTTP spies. */
final class QueueDrainTest extends \PHPUnit\Framework\TestCase {
	public function test_queue_drain_policy(): void {
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/queue-drain-check.php' ) . ' 2>&1', $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$this->assertSame( array( 'queue drain checks passed' ), $output );
	}
}
