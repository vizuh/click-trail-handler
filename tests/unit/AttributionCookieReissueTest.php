<?php
/**
 * Attribution cookie server re-issue (specs/009-itp-cookie-reissue, #141): the route's gates, the exact
 * Set-Cookie shape, the value schema, the Domain parity with the JS writer, and the absence of any
 * Set-Cookie outside the REST route.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

namespace CLICUTCL\Core {
	/**
	 * Record header() calls; the class under test calls it unqualified from this namespace.
	 */
	function header( $line, $replace = true ) {
		$GLOBALS['clicktrail_test_headers'][] = array( $line, $replace );
	}

	function headers_sent() {
		return (bool) ( $GLOBALS['clicktrail_test_headers_sent'] ?? false );
	}

	function is_ssl() {
		return (bool) ( $GLOBALS['clicktrail_test_is_ssl'] ?? false );
	}
}

namespace {
	if ( ! defined( 'CLICUTCL_DIR' ) ) {
		define( 'CLICUTCL_DIR', dirname( __DIR__, 2 ) . '/' );
	}
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}

	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-decision-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-resolver-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-snapshot-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-consent.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Core/Storage/class-option-cache.php';
	require_once dirname( __DIR__, 2 ) . '/includes/settings/class-attribution-settings.php';
	require_once dirname( __DIR__, 2 ) . '/includes/support/class-feature-registry.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-settings.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-auth.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-webhook-auth.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Core/class-cookie-scope.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Core/class-attribution-cookie-reissue.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Core/class-attribution-provider.php';
	require_once dirname( __DIR__, 2 ) . '/includes/database/class-whatsapp-ref-store.php';
	require_once dirname( __DIR__, 2 ) . '/includes/api/class-tracking-controller.php';

	if ( ! class_exists( 'WP_REST_Server' ) ) {
		class WP_REST_Server {
			public const READABLE  = 'GET';
			public const CREATABLE = 'POST';
		}
	}
	if ( ! function_exists( 'register_rest_route' ) ) {
		function register_rest_route( $namespace, $route, $args = array() ) {
			$GLOBALS['clicktrail_test_routes'][ $route ] = $args;
			return true;
		}
	}

	use CLICUTCL\Api\Tracking_Controller;
	use CLICUTCL\Core\Attribution_Cookie_Reissue;
	use CLICUTCL\Core\Cookie_Scope;
	use CLICUTCL\Core\Storage\Option_Cache;
	use CLICUTCL\Tracking\Auth;
	use PHPUnit\Framework\TestCase;

	/**
	 * Class AttributionCookieReissueTest
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class AttributionCookieReissueTest extends TestCase {
		private const COOKIE = '{"ft_source":"google","ft_medium":"cpc","gclid":"G 1+x","lt_campaign":"Br\u00e1s\u00edlia 100%","session_number":3,"consent":true}';

		protected function setUp(): void {
			require_once dirname( __DIR__ ) . '/helpers/consent-mode-settings-stub.php';
			$_COOKIE                                         = array( 'attribution' => self::COOKIE );
			$GLOBALS['clicktrail_test_headers']              = array();
			$GLOBALS['clicktrail_test_headers_sent']         = false;
			$GLOBALS['clicktrail_test_is_ssl']               = false;
			$GLOBALS['clicktrail_test_home_url']             = 'http://www.example.com';
			$GLOBALS['clicktrail_test_transients']           = array();
			$GLOBALS['clicktrail_test_filters']              = array();
			$GLOBALS['clicktrail_test_consent_required']     = false;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = false;
			Option_Cache::set( 'clicutcl_tracking_v2', array() );
			$this->settings( array() );
		}

		private function settings( array $overrides ): void {
			Option_Cache::set(
				'clicutcl_attribution_settings',
				array_merge(
					array(
						'enable_attribution'        => 1,
						'attribution_cookie_reissue' => 1,
						'cookie_days'               => 90,
					),
					$overrides
				)
			);
		}

		private function request( ?string $token = null, string $host = 'www.example.com', string $body = '' ): \WP_REST_Request {
			$token = $token ?? Auth::mint_client_token();
			return new \WP_REST_Request(
				array(
					'X-Clicutcl-Token' => $token,
					'Host'             => $host,
				),
				'' === $body ? (string) json_encode( array( 'token' => $token ) ) : $body,
				'/clicutcl/v2/cookie-refresh'
			);
		}

		private function require_consent(): void {
			$this->settings( array( 'require_consent' => 1 ) );
			$GLOBALS['clicktrail_test_consent_required']     = true;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = true;
		}

		private function refresh( ?\WP_REST_Request $request = null ) {
			return ( new Tracking_Controller() )->refresh_attribution_cookie( $request ?? $this->request() );
		}

		/**
		 * @return string[] Raw Set-Cookie lines emitted through header().
		 */
		private function set_cookies(): array {
			$lines = array();
			foreach ( $GLOBALS['clicktrail_test_headers'] as $header ) {
				if ( 0 === stripos( $header[0], 'Set-Cookie:' ) ) {
					$lines[] = $header[0];
					$this->assertFalse( $header[1], 'Set-Cookie must append, never replace another cookie header' );
				}
			}
			return $lines;
		}

		// ----- Gates -----

		public function test_route_is_off_unless_the_setting_is_on(): void {
			$this->settings( array( 'attribution_cookie_reissue' => 0 ) );
			$result = ( new Tracking_Controller() )->cookie_refresh_permissions_check( $this->request() );
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'cookie_reissue_disabled', $result->get_error_code() );

			Option_Cache::set( 'clicutcl_attribution_settings', array( 'enable_attribution' => 1 ) );
			$this->assertSame( 'cookie_reissue_disabled', ( new Tracking_Controller() )->cookie_refresh_permissions_check( $this->request() )->get_error_code(), 'absent key means off' );

			$this->settings( array( 'enable_attribution' => 0 ) );
			$this->assertSame( 'cookie_reissue_disabled', ( new Tracking_Controller() )->cookie_refresh_permissions_check( $this->request() )->get_error_code(), 'attribution off means off' );
		}

		public function test_route_needs_the_client_token(): void {
			$controller = new Tracking_Controller();
			$missing    = $controller->cookie_refresh_permissions_check( new \WP_REST_Request( array(), '{}', '/clicutcl/v2/cookie-refresh' ) );
			$this->assertSame( 'invalid_token', $missing->get_error_code() );
			$bad = $controller->cookie_refresh_permissions_check( $this->request( 'garbage' ) );
			$this->assertSame( 'invalid_token', $bad->get_error_code() );
			$this->assertTrue( $controller->cookie_refresh_permissions_check( $this->request() ) );
		}

		public function test_route_rejects_an_oversized_body_and_is_rate_limited(): void {
			$big = ( new Tracking_Controller() )->cookie_refresh_permissions_check( $this->request( null, 'www.example.com', (string) json_encode( array( 'pad' => str_repeat( 'x', 3000 ) ) ) ) );
			$this->assertSame( 'payload_too_large', $big->get_error_code() );

			$GLOBALS['clicktrail_test_filters']['clicutcl_v2_rate_limit'] = static fn() => 1;
			$controller = new Tracking_Controller();
			$this->assertTrue( $controller->cookie_refresh_permissions_check( $this->request() ) );
			$this->assertSame( 'rate_limited', $controller->cookie_refresh_permissions_check( $this->request() )->get_error_code() );
		}

		public function test_consent_comes_from_the_request_cookie_not_the_body(): void {
			$this->require_consent();
			$controller = new Tracking_Controller();
			$body       = (string) json_encode( array( 'consent' => array( 'marketing' => true ) ) );

			$none = $controller->cookie_refresh_permissions_check( $this->request( null, 'www.example.com', $body ) );
			$this->assertSame( 'consent_required', $none->get_error_code(), 'no consent cookie' );

			$_COOKIE['production_consent'] = 'denied';
			$denied = $controller->cookie_refresh_permissions_check( $this->request( null, 'www.example.com', $body ) );
			$this->assertSame( 'consent_required', $denied->get_error_code(), 'a denied cookie wins over a body claim' );

			$_COOKIE['production_consent'] = 'granted';
			$this->assertTrue( $controller->cookie_refresh_permissions_check( $this->request() ) );
			$this->assertSame( array(), $this->set_cookies(), 'the permission check never emits' );
		}

		public function test_no_consent_gate_when_consent_is_not_required(): void {
			$this->settings( array( 'require_consent' => 0 ) );
			$this->assertTrue( ( new Tracking_Controller() )->cookie_refresh_permissions_check( $this->request() ) );
			$result = $this->refresh();
			$this->assertTrue( $result['reissued'] );
		}

		// ----- Header shape -----

		public function test_reissue_echoes_the_same_value_with_the_exact_attributes_over_http_host_scope(): void {
			$result = $this->refresh();

			$this->assertSame( array( 'success' => true, 'reissued' => true ), $result );
			$lines = $this->set_cookies();
			$this->assertCount( 1, $lines );
			$this->assertSame( 'Set-Cookie: attribution=' . rawurlencode( self::COOKIE ) . '; Max-Age=7776000; Path=/; SameSite=Lax', $lines[0] );
			$this->assertSame( 90 * 86400, 7776000 );
			$this->assertStringNotContainsString( 'HttpOnly', $lines[0] );
			$this->assertStringNotContainsString( 'Domain', $lines[0] );
			$this->assertStringNotContainsString( 'Secure', $lines[0] );
			// JS reads it back with decodeURIComponent: the raw value must survive the round trip untouched.
			preg_match( '/^Set-Cookie: attribution=([^;]*);/', $lines[0], $m );
			$this->assertSame( self::COOKIE, rawurldecode( $m[1] ) );
			$this->assertStringNotContainsString( '+', $m[1], 'spaces are %20 like encodeURIComponent, never +' );
			$cache = array_values( array_filter( $GLOBALS['clicktrail_test_headers'], static fn( $h ) => 0 === stripos( $h[0], 'Cache-Control:' ) ) );
			$this->assertCount( 1, $cache );
			$this->assertSame( 'Cache-Control: no-store, private', $cache[0][0] );
		}

		public function test_max_age_is_cookie_days_times_a_day(): void {
			$this->settings( array( 'cookie_days' => 30 ) );
			$this->refresh();
			$this->assertStringContainsString( '; Max-Age=2592000;', $this->set_cookies()[0] );
		}

		public function test_secure_is_set_over_https_by_the_request_or_the_site_url(): void {
			$GLOBALS['clicktrail_test_is_ssl'] = true;
			$this->refresh();
			$this->assertStringEndsWith( '; SameSite=Lax; Secure', $this->set_cookies()[0] );

			$GLOBALS['clicktrail_test_headers'] = array();
			$GLOBALS['clicktrail_test_is_ssl']  = false;
			$GLOBALS['clicktrail_test_home_url'] = 'https://www.example.com';
			$this->refresh();
			$this->assertStringEndsWith( '; SameSite=Lax; Secure', $this->set_cookies()[0], 'TLS terminated by a proxy: the https site URL still marks it Secure' );
		}

		/**
		 * @return array<string,array{0:string,1:string,2:string,3:string}>
		 */
		public static function scopes(): array {
			return array(
				'host'                 => array( 'host', '', 'www.example.com', '' ),
				'registrable'          => array( 'registrable', '', 'www.example.com', 'example.com' ),
				'custom'               => array( 'custom', 'example.com', 'www.example.com', 'example.com' ),
				'registrable on apex'  => array( 'registrable', '', 'example.com', 'example.com' ),
			);
		}

		/**
		 * The Domain attribute is the very value localized to the browser as `cookieDomain` (one resolver), so the HTTP cookie
		 * replaces the script cookie instead of sitting next to it (#154 duplicate-name hazard).
		 *
		 * @dataProvider scopes
		 */
		public function test_domain_attribute_is_the_cookie_scope_domain_the_script_writes( string $scope, string $custom, string $host, string $expected ): void {
			$GLOBALS['clicktrail_test_home_url'] = 'http://' . $host;
			$settings = array(
				'enable_attribution'         => 1,
				'attribution_cookie_reissue' => 1,
				'cookie_days'                => 90,
				'cookie_scope'               => $scope,
				'cookie_custom_domain'       => $custom,
			);
			Option_Cache::set( 'clicutcl_attribution_settings', $settings );

			$this->assertSame( $expected, Cookie_Scope::resolve_for_site( $settings )['domain'] );
			$this->refresh( $this->request( null, $host ) );

			$line = $this->set_cookies()[0];
			if ( '' === $expected ) {
				$this->assertStringNotContainsString( 'Domain=', $line );
			} else {
				$this->assertStringContainsString( '; Path=/; Domain=' . $expected . '; SameSite=Lax', $line );
			}
			$this->assertStringContainsString( 'attribution=', $line, 'the cookie name is the script cookie name' );
		}

		public function test_a_request_host_the_domain_does_not_cover_gets_a_host_only_cookie_like_the_script(): void {
			$this->settings( array( 'cookie_scope' => 'registrable' ) );
			$this->refresh( $this->request( null, 'preview.cdn.test' ) );
			$this->assertStringNotContainsString( 'Domain=', $this->set_cookies()[0] );

			$GLOBALS['clicktrail_test_headers'] = array();
			$this->refresh( $this->request( null, 'badexample.com' ) );
			$this->assertStringNotContainsString( 'Domain=', $this->set_cookies()[0], 'a lookalike suffix does not cover' );

			$GLOBALS['clicktrail_test_headers'] = array();
			$this->refresh( $this->request( null, 'WWW.Example.com:8088' ) );
			$this->assertStringContainsString( 'Domain=example.com', $this->set_cookies()[0], 'case and port are normalised' );
		}

		// ----- No header -----

		public function test_no_cookie_in_the_request_sets_nothing(): void {
			unset( $_COOKIE['attribution'] );
			$_COOKIE['ct_attribution'] = self::COOKIE; // The legacy name is never re-issued.
			$result = $this->refresh();

			$this->assertSame( array( 'success' => true, 'reissued' => false, 'reason' => 'no_cookie' ), $result );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_headers'] );
		}

		public function test_retention_zero_sets_nothing(): void {
			$this->settings( array( 'cookie_days' => 0 ) );
			$result = $this->refresh();
			$this->assertFalse( $result['reissued'] );
			$this->assertSame( 'retention_disabled', $result['reason'] );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_headers'] );
		}

		public function test_headers_already_sent_sets_nothing(): void {
			$GLOBALS['clicktrail_test_headers_sent'] = true;
			$result = $this->refresh();
			$this->assertFalse( $result['reissued'] );
			$this->assertSame( 'headers_sent', $result['reason'] );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_headers'] );
		}

		/**
		 * @return array<string,array{0:string,1:string}>
		 */
		public static function invalid_values(): array {
			return array(
				'empty'                 => array( '', 'empty' ),
				'not json'              => array( 'gclid=G1', 'not_json' ),
				'truncated json'        => array( '{"gclid":"G1"', 'not_json' ),
				'json scalar'           => array( '"gclid"', 'not_object' ),
				'json list'             => array( '["a","b"]', 'not_object' ),
				'empty object'          => array( '{}', 'not_object' ),
				'nested object'         => array( '{"a":{"b":"c"}}', 'bad_value' ),
				'nested array'          => array( '{"a":["b"]}', 'bad_value' ),
				'null value'            => array( '{"a":null}', 'bad_value' ),
				'upper case key'        => array( '{"ScCid":"x"}', 'bad_key' ),
				'dash key'              => array( '{"a-b":"x"}', 'bad_key' ),
				'long key'              => array( '{"' . '__KEY65__' . '":"x"}', 'bad_key' ),
				'long value'            => array( '{"a":"' . '__VALUE257__' . '"}', 'bad_value' ),
				'control character'     => array( "{\"a\":\"x\\u0000y\"}", 'bad_value' ),
				'unsubstituted macro'   => array( '{"lt_campaign":"{{campaign.name}}"}', 'bad_value' ),
				'newline in value'      => array( "{\"a\":\"x\\ny\"}", 'bad_value' ),
			);
		}

		/**
		 * @dataProvider invalid_values
		 */
		public function test_a_value_that_fails_the_schema_sets_nothing( string $value, string $reason ): void {
			$value = str_replace( array( '__KEY65__', '__VALUE257__' ), array( str_repeat( 'k', 65 ), str_repeat( 'v', 257 ) ), $value );
			$this->assertSame( $reason, Attribution_Cookie_Reissue::inspect( $value ) );

			$_COOKIE['attribution'] = $value;
			$result                 = $this->refresh();
			$this->assertFalse( $result['reissued'] );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_headers'] );
		}

		public function test_size_caps(): void {
			$ok = array();
			for ( $i = 0; $i < 128; $i++ ) {
				$ok[ 'k' . $i ] = 'v';
			}
			$this->assertSame( '', Attribution_Cookie_Reissue::inspect( (string) json_encode( $ok ) ) );
			$ok['k128'] = 'v';
			$this->assertSame( 'too_many_keys', Attribution_Cookie_Reissue::inspect( (string) json_encode( $ok ) ) );

			// Encoded size is what the script caps (3800 after encodeURIComponent), not the raw size.
			$fits = array();
			for ( $i = 0; $i < 12; $i++ ) {
				$fits[ 'k' . $i ] = str_repeat( 'a', 256 );
			}
			$this->assertSame( '', Attribution_Cookie_Reissue::inspect( (string) json_encode( $fits ) ), 'about 3100 encoded bytes' );
			$too_big = array();
			for ( $i = 0; $i < 16; $i++ ) {
				$too_big[ 'k' . $i ] = str_repeat( ' ', 1 ) . str_repeat( 'a', 254 ) . ' ';
			}
			$this->assertGreaterThan( 3800, strlen( rawurlencode( (string) json_encode( $too_big ) ) ) );
			$this->assertSame( 'too_large', Attribution_Cookie_Reissue::inspect( (string) json_encode( $too_big ) ) );
		}

		public function test_accepted_value_shapes(): void {
			foreach ( array( '{"a":"x"}', '{"a":1}', '{"a":1.5}', '{"a":true}', '{"a":false}', '{"a":""}', '{"ft_source":"google","session_number":2}', self::COOKIE ) as $ok ) {
				$this->assertSame( '', Attribution_Cookie_Reissue::inspect( $ok ), $ok );
			}
		}

		// ----- Never on a page response -----

		public function test_only_the_controller_route_can_emit(): void {
			$root     = dirname( __DIR__, 2 );
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/includes', \FilesystemIterator::SKIP_DOTS ) );
			$users    = array();
			foreach ( $iterator as $file ) {
				if ( 'php' !== $file->getExtension() ) {
					continue;
				}
				$source = (string) file_get_contents( $file->getPathname() );
				if ( false !== strpos( $source, 'Attribution_Cookie_Reissue::issue' ) ) {
					$users[] = str_replace( $root . '/', '', $file->getPathname() );
				}
			}
			$this->assertSame( array( 'includes/api/class-tracking-controller.php' ), $users, 'no page-render path may call the emitter' );
		}

		public function test_the_other_routes_and_readers_never_emit_a_cookie_header(): void {
			$GLOBALS['clicktrail_test_headers'] = array();
			\CLICUTCL\Core\Attribution_Provider::get_payload();
			$this->assertSame( array(), $GLOBALS['clicktrail_test_headers'] );

			$controller = new Tracking_Controller();
			$controller->create_attribution_token( $this->request( null, 'www.example.com', (string) json_encode( array( 'data' => array( 'gclid' => 'G1' ) ) ) ) );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_headers'] );
		}

		public function test_route_is_registered_as_post_only(): void {
			$GLOBALS['clicktrail_test_routes'] = array();
			( new Tracking_Controller() )->register_routes();
			$route = $GLOBALS['clicktrail_test_routes']['/cookie-refresh'] ?? null;
			$this->assertNotNull( $route, 'cookie-refresh is registered' );
			$this->assertSame( 'POST', $route[0]['methods'] );
			$this->assertSame( 'refresh_attribution_cookie', $route[0]['callback'][1] );
			$this->assertSame( 'cookie_refresh_permissions_check', $route[0]['permission_callback'][1] );
		}
	}
}
