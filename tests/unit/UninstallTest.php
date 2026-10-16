<?php
/** Uninstall cleanup across a multisite network, in an isolated PHP process. */
final class UninstallTest extends \PHPUnit\Framework\TestCase {
	public function test_uninstall_cleans_every_site(): void {
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/uninstall-check.php' ) . ' 2>&1', $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$this->assertSame( array( 'uninstall checks passed' ), $output );
	}
}
