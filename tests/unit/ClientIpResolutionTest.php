<?php
/**
 * Client IP resolution behind trusted proxies (rightmost-untrusted `X-Forwarded-For`).
 *
 * `Tracking_Controller::get_client_ip()` reads the SAPI environment, so the header-driven
 * decision is `client_ip_for_peer( $remote, $xff, $cf )`, exercised here by reflection.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

if ( ! defined( 'CLICUTCL_DIR' ) ) {
	define( 'CLICUTCL_DIR', dirname( __DIR__, 2 ) . '/' );
}

require_once dirname( __DIR__, 2 ) . '/includes/Core/Storage/class-option-cache.php';
require_once dirname( __DIR__, 2 ) . '/includes/support/class-feature-registry.php';
require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-settings.php';
require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-auth.php';
require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-webhook-auth.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/class-tracking-controller.php';

use CLICUTCL\Api\Tracking_Controller;
use CLICUTCL\Core\Storage\Option_Cache;
use PHPUnit\Framework\TestCase;

/**
 * Class ClientIpResolutionTest
 */
final class ClientIpResolutionTest extends TestCase {

	protected function setUp(): void {
		Option_Cache::set( 'clicutcl_tracking_v2', array() );
		$GLOBALS['clicktrail_test_filters'] = array(
			'clicutcl_trusted_proxies' => static fn() => array( '10.0.0.0/8', '172.16.0.5', '2001:db8:aaaa::/48' ),
		);
	}

	protected function tearDown(): void {
		$GLOBALS['clicktrail_test_filters'] = array();
		Option_Cache::set( 'clicutcl_tracking_v2', array() );
	}

	/**
	 * Call the private resolver.
	 *
	 * @param string $remote Direct peer (REMOTE_ADDR).
	 * @param string $xff    X-Forwarded-For header value.
	 * @param string $cf     CF-Connecting-IP header value.
	 * @return string
	 */
	private function resolve( string $remote, string $xff, string $cf = '' ): string {
		$method = new ReflectionMethod( Tracking_Controller::class, 'client_ip_for_peer' );
		$method->setAccessible( true );
		return (string) $method->invoke( new Tracking_Controller(), $remote, $xff, $cf );
	}

	public function test_a_spoofed_left_entry_loses_to_the_real_client(): void {
		$this->assertSame( '203.0.113.9', $this->resolve( '10.0.0.1', '198.51.100.1, 203.0.113.9' ) );
	}

	public function test_a_single_client_followed_by_a_trusted_proxy_resolves_to_the_client(): void {
		$this->assertSame( '203.0.113.9', $this->resolve( '10.0.0.1', '203.0.113.9, 10.0.0.2' ) );
	}

	public function test_a_chain_of_trusted_proxies_is_skipped_from_the_right(): void {
		$this->assertSame( '203.0.113.9', $this->resolve( '10.0.0.1', '1.1.1.1, 203.0.113.9, 10.0.0.7, 172.16.0.5' ) );
	}

	public function test_an_all_trusted_chain_falls_back_to_the_remote_peer(): void {
		$this->assertSame( '10.0.0.1', $this->resolve( '10.0.0.1', '10.0.0.2, 172.16.0.5' ) );
	}

	public function test_an_all_trusted_chain_falls_back_to_a_valid_cf_header_before_the_peer(): void {
		$this->assertSame( '203.0.113.50', $this->resolve( '10.0.0.1', '10.0.0.2', '203.0.113.50' ) );
	}

	public function test_a_forged_cf_header_cannot_override_x_forwarded_for(): void {
		// Peer is a trusted proxy that is not Cloudflare, so the client may have sent CF-Connecting-IP itself.
		$this->assertSame( '203.0.113.9', $this->resolve( '10.0.0.1', '203.0.113.9', '6.6.6.6' ) );
	}

	public function test_a_cf_header_is_used_when_x_forwarded_for_is_absent(): void {
		$this->assertSame( '203.0.113.50', $this->resolve( '10.0.0.1', '', '203.0.113.50' ) );
	}

	public function test_an_invalid_cf_header_falls_back_to_the_peer(): void {
		$this->assertSame( '10.0.0.1', $this->resolve( '10.0.0.1', '', 'not-an-ip' ) );
		$this->assertSame( '10.0.0.1', $this->resolve( '10.0.0.1', '', '' ) );
	}

	public function test_an_untrusted_peer_ignores_both_headers(): void {
		$method = new ReflectionMethod( Tracking_Controller::class, 'is_trusted_proxy' );
		$method->setAccessible( true );
		$this->assertFalse( $method->invoke( new Tracking_Controller(), '198.51.100.77' ) );
		$this->assertSame( '198.51.100.77', $this->resolve( '198.51.100.77', '203.0.113.9', '6.6.6.6' ) );
	}

	public function test_ipv6_entries_are_resolved_and_trusted_ranges_skipped(): void {
		$this->assertSame( '2001:db8:1::7', $this->resolve( '10.0.0.1', '2001:db8:ffff::1, 2001:db8:1::7, 2001:db8:aaaa::9' ) );
	}

	public function test_whitespace_and_invalid_entries_are_ignored(): void {
		$this->assertSame( '203.0.113.9', $this->resolve( '10.0.0.1', "  bogus , 203.0.113.9 ,  10.0.0.2 ,, unknown" ) );
		$this->assertSame( '10.0.0.1', $this->resolve( '10.0.0.1', 'bogus, , unknown' ) );
	}
}
