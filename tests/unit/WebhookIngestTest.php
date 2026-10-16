<?php
/** Webhook ingest and rate-limit behaviour in an isolated PHP process with a stubbed Dispatcher. */
final class WebhookIngestTest extends \PHPUnit\Framework\TestCase {
	public function test_webhook_ingest_policy(): void {
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/webhook-ingest-check.php' ) . ' 2>&1', $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$this->assertSame( array( 'webhook ingest checks passed' ), $output );
	}
}
