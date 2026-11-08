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

	public function test_an_untrusted_peer_ignores_all_forwarding_headers_even_with_the_cf_filter_on(): void {
		$this->trust_cf( true );
		$this->assertSame( '198.51.100.77', $this->resolve( '198.51.100.77', '203.0.113.9', '6.6.6.6' ) );
		$this->trust_cf( false );
		$this->assertSame( '198.51.100.77', $this->resolve( '198.51.100.77', '203.0.113.9', '6.6.6.6' ) );
	}

	public function test_ipv6_entries_are_resolved_and_trusted_ranges_skipped(): void {
		$this->assertSame( '2001:db8:1::7', $this->resolve( '10.0.0.1', '2001:db8:ffff::1, 2001:db8:1::7, 2001:db8:aaaa::9' ) );
	}

	public function test_whitespace_around_hops_is_trimmed(): void {
		$this->assertSame( '203.0.113.9', $this->resolve( '10.0.0.1', "  198.51.100.1 ,\t203.0.113.9 ,  10.0.0.2 " ) );
	}

	// ----- CF-Connecting-IP is opt-in (clicutcl_trust_cf_connecting_ip) -----

	public function test_cf_connecting_ip_is_ignored_by_default_even_when_x_forwarded_for_is_absent(): void {
		$this->assertSame( '10.0.0.1', $this->resolve( '10.0.0.1', '', '203.0.113.50' ) );
	}

	public function test_a_forged_cf_header_cannot_override_x_forwarded_for_by_default(): void {
		$this->assertSame( '203.0.113.9', $this->resolve( '10.0.0.1', '203.0.113.9', '6.6.6.6' ) );
	}

	public function test_cloudflare_behind_a_local_proxy_shows_the_edge_ip_by_default(): void {
		// Only the local proxy is trusted: XFF is `client, cf-edge`, so the rightmost untrusted hop is the edge.
		$this->trust_only( array( '127.0.0.1' ) );
		$this->assertSame( '172.71.0.9', $this->resolve( '127.0.0.1', '203.0.113.9, 172.71.0.9', '203.0.113.9' ) );
	}

	public function test_opting_in_prefers_a_valid_cf_connecting_ip_behind_a_trusted_proxy(): void {
		$this->trust_only( array( '127.0.0.1' ) );
		$this->trust_cf( true );
		$this->assertSame( '203.0.113.9', $this->resolve( '127.0.0.1', '203.0.113.9, 172.71.0.9', '203.0.113.9' ) );
	}

	public function test_opting_in_with_a_missing_or_invalid_cf_header_falls_back_to_x_forwarded_for(): void {
		$this->trust_cf( true );
		$this->assertSame( '203.0.113.9', $this->resolve( '10.0.0.1', '198.51.100.1, 203.0.113.9', '' ) );
		$this->assertSame( '203.0.113.9', $this->resolve( '10.0.0.1', '198.51.100.1, 203.0.113.9', 'not-an-ip' ) );
		$this->assertSame( '10.0.0.1', $this->resolve( '10.0.0.1', '', 'not-an-ip' ) );
	}

	// ----- Infrastructure-appended hops: never skip into client-controlled entries -----

	public function test_a_port_suffix_on_the_last_hop_is_stripped(): void {
		$this->assertSame( '1.2.3.4', $this->resolve( '10.0.0.1', 'spoof, 1.2.3.4:5678' ) );
	}

	public function test_bracketed_ipv6_with_and_without_a_port_is_unwrapped(): void {
		$this->assertSame( '2001:db8::1', $this->resolve( '10.0.0.1', 'spoof, [2001:db8::1]:443' ) );
		$this->assertSame( '2001:db8::1', $this->resolve( '10.0.0.1', 'spoof, [2001:db8::1]' ) );
	}

	public function test_an_unreadable_last_hop_stops_the_walk_at_the_trusted_peer(): void {
		$this->assertSame( '10.0.0.1', $this->resolve( '10.0.0.1', 'spoof, unknown' ) );
		$this->assertSame( '10.0.0.1', $this->resolve( '10.0.0.1', '198.51.100.1, _hidden' ) );
		$this->assertSame( '10.0.0.1', $this->resolve( '10.0.0.1', '198.51.100.1, 1.2.3.4:99999x' ) );
	}

	public function test_an_unreadable_hop_behind_trusted_hops_still_stops_the_walk(): void {
		$this->assertSame( '10.0.0.1', $this->resolve( '10.0.0.1', 'spoof, unknown, 10.0.0.2' ) );
	}

	public function test_empty_hops_stop_the_walk(): void {
		$this->assertSame( '10.0.0.1', $this->resolve( '10.0.0.1', '198.51.100.1, ' ) );
		$this->assertSame( '10.0.0.1', $this->resolve( '10.0.0.1', '198.51.100.1,, 10.0.0.2' ) );
		$this->assertSame( '10.0.0.1', $this->resolve( '10.0.0.1', ',' ) );
		$this->assertSame( '10.0.0.1', $this->resolve( '10.0.0.1', '' ) );
	}

	public function test_a_trusted_hop_with_a_port_is_still_recognised_as_trusted(): void {
		$this->assertSame( '203.0.113.9', $this->resolve( '10.0.0.1', '203.0.113.9, 10.0.0.2:8443' ) );
	}

	/**
	 * Toggle the CF-Connecting-IP opt-in.
	 *
	 * @param bool $on Opt in.
	 */
	private function trust_cf( bool $on ): void {
		$GLOBALS['clicktrail_test_filters']['clicutcl_trust_cf_connecting_ip'] = static fn() => $on;
	}

	/**
	 * Replace the trusted-proxy list.
	 *
	 * @param string[] $list Trusted proxies.
	 */
	private function trust_only( array $list ): void {
		$GLOBALS['clicktrail_test_filters']['clicutcl_trusted_proxies'] = static fn() => $list;
	}
}
