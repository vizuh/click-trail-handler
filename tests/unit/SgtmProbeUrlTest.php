<?php
/**
 * sGTM preview probe: admin-posted URLs are validated and fetched with the SSRF-safe HTTP API.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

namespace {
	require_once dirname( __DIR__, 2 ) . '/includes/admin/traits/trait-admin-diagnostics-ajax.php';

	use CLICUTCL\Admin\Admin_Diagnostics_Ajax_Trait;
	use PHPUnit\Framework\TestCase;

	if ( ! function_exists( 'wp_http_validate_url' ) ) {
		/**
		 * Minimal mirror of core: http(s) only, a host, no user info, no private/loopback IP literals.
		 *
		 * @param string $url URL.
		 * @return string|false
		 */
		function wp_http_validate_url( $url ) {
			$parts = parse_url( (string) $url );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
				return false;
			}
			if ( ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) ) {
				return false;
			}
			if ( filter_var( $parts['host'], FILTER_VALIDATE_IP ) && ! filter_var( $parts['host'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}
			return (string) $url;
		}
	}
	if ( ! function_exists( 'wp_safe_remote_request' ) ) {
		function wp_safe_remote_request( $url, $args = array() ) {
			$GLOBALS['clicktrail_test_http'][] = array( 'fn' => 'wp_safe_remote_request', 'url' => $url, 'args' => $args );
			return array( 'response' => array( 'code' => 204 ) );
		}
	}
	if ( ! function_exists( 'wp_remote_request' ) ) {
		function wp_remote_request( $url, $args = array() ) {
			$GLOBALS['clicktrail_test_http'][] = array( 'fn' => 'wp_remote_request', 'url' => $url, 'args' => $args );
			return array( 'response' => array( 'code' => 200 ) );
		}
	}
	if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
		function wp_remote_retrieve_response_code( $response ) {
			return $response['response']['code'] ?? 0;
		}
	}
	if ( ! function_exists( 'esc_url_raw' ) ) {
		function esc_url_raw( $url ) {
			return (string) $url;
		}
	}

	/**
	 * Class SgtmProbeUrlTest
	 */
	final class SgtmProbeUrlTest extends TestCase {
		/**
		 * @var object
		 */
		private $host;

		protected function setUp(): void {
			$GLOBALS['clicktrail_test_http'] = array();
			$this->host                      = new class {
				use Admin_Diagnostics_Ajax_Trait;
			};
		}

		private function call( string $method, ...$args ) {
			$reflection = new ReflectionMethod( $this->host, $method );
			return $reflection->invoke( $this->host, ...$args );
		}

		/**
		 * @dataProvider rejectedUrls
		 */
		public function test_unsafe_or_non_http_urls_are_rejected( string $url ): void {
			$this->assertFalse( $this->call( 'is_safe_sgtm_probe_url', $url ), $url );
		}

		/**
		 * @return array<string,array{string}>
		 */
		public static function rejectedUrls(): array {
			return array(
				'empty'            => array( '' ),
				'ftp'              => array( 'ftp://example.com/x' ),
				'file'             => array( 'file:///etc/passwd' ),
				'javascript'       => array( 'javascript:alert(1)' ),
				'gopher'           => array( 'gopher://example.com/' ),
				'loopback'         => array( 'http://127.0.0.1/admin' ),
				'private'          => array( 'http://10.0.0.5/' ),
				'link local'       => array( 'http://169.254.169.254/latest/meta-data/' ),
				'user info'        => array( 'https://user:pw@example.com/' ),
				'scheme relative'  => array( '//example.com/x' ),
				'no host'          => array( 'https:///path' ),
				'not a url'        => array( 'not a url' ),
				'mixed case ftp'   => array( 'FTP://example.com/' ),
			);
		}

		/**
		 * @dataProvider acceptedUrls
		 */
		public function test_public_http_and_https_urls_are_accepted( string $url ): void {
			$this->assertTrue( $this->call( 'is_safe_sgtm_probe_url', $url ), $url );
		}

		/**
		 * @return array<string,array{string}>
		 */
		public static function acceptedUrls(): array {
			return array(
				'https'          => array( 'https://sgtm.example.com/gtm.js?id=GTM-ABC' ),
				'http'           => array( 'http://sgtm.example.com/' ),
				'public ip'      => array( 'https://8.8.8.8/collect' ),
				'uppercase http' => array( 'HTTPS://SGTM.EXAMPLE.COM/' ),
			);
		}

		public function test_a_rejected_url_is_reported_and_never_requested(): void {
			$check = $this->call( 'run_sgtm_http_preview_check', 'loader_probe', 'Loader probe', 'http://169.254.169.254/latest/meta-data/', 'Could not reach.' );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_http'] );
			$this->assertSame( 'attention', $check['status'] );
			$this->assertSame( 'loader_probe', $check['key'] );
			$this->assertStringContainsString( 'public http', $check['detail'] );
		}

		public function test_a_valid_url_uses_the_safe_request_function_with_unsafe_urls_rejected(): void {
			$check = $this->call( 'run_sgtm_http_preview_check', 'collector_probe', 'Collector probe', 'https://sgtm.example.com/', 'Could not reach.' );
			$this->assertCount( 1, $GLOBALS['clicktrail_test_http'] );
			$this->assertSame( 'wp_safe_remote_request', $GLOBALS['clicktrail_test_http'][0]['fn'] );
			$this->assertTrue( $GLOBALS['clicktrail_test_http'][0]['args']['reject_unsafe_urls'] );
			$this->assertSame( 'ready', $check['status'] );
		}
	}
}
