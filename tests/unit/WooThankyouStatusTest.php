<?php
/** WooCommerce thank-you purchase gating by order status, in an isolated PHP process. */
final class WooThankyouStatusTest extends \PHPUnit\Framework\TestCase {
	public function test_thankyou_status_policy(): void {
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/woo-thankyou-status-check.php' ) . ' 2>&1', $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$this->assertSame( array( 'woo thankyou status checks passed' ), $output );
	}
}
