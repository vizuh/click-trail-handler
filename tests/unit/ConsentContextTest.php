<?php
/** Dispatcher consent gate by request context, in an isolated PHP process. */
final class ConsentContextTest extends \PHPUnit\Framework\TestCase {
	public function test_consent_context_policy(): void {
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/consent-context-check.php' ) . ' 2>&1', $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$this->assertSame( array( 'consent context checks passed' ), $output );
	}
}
