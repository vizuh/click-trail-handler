<?php
/** Queue processing in an isolated PHP process with storage/HTTP spies. */
final class QueueProcessingTest extends \PHPUnit\Framework\TestCase {
	public function test_queue_processing_policy(): void {
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/queue-processing-check.php' ) . ' 2>&1', $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$this->assertSame( array( 'queue processing checks passed' ), $output );
	}
}
