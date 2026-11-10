<?php
/**
 * Event cookies must be cleared before output, not on wp_head (#181).
 *
 * @package ClickTrail
 */

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions, Squiz.Commenting

namespace CLICUTCL\Modules\Events {
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['clicktrail_test_events_hooks'][ $tag ][] = array( $callback, $priority );
	}

	function setcookie( $name, $value = '', $options = array() ) {
		$GLOBALS['clicktrail_test_setcookie'][] = array( $name, $value, $options );
		return true;
	}

	function is_ssl() {
		return false;
	}

	function nocache_headers() {
		$GLOBALS['clicktrail_test_nocache'] = ( $GLOBALS['clicktrail_test_nocache'] ?? 0 ) + 1;
	}
}

namespace {
	use CLICUTCL\Core\Context;
	use CLICUTCL\Modules\Events\Events_Logger;
	use PHPUnit\Framework\TestCase;

	if ( ! defined( 'COOKIEPATH' ) ) {
		define( 'COOKIEPATH', '/' );
	}
	if ( ! defined( 'COOKIE_DOMAIN' ) ) {
		define( 'COOKIE_DOMAIN', '' );
	}

	/**
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	class EventsLoggerCookieHeadersTest extends TestCase {
		protected function setUp(): void {
			parent::setUp();
			require_once dirname( __DIR__, 2 ) . '/includes/Core/class-context.php';
			require_once dirname( __DIR__, 2 ) . '/includes/Core/Storage/class-option-cache.php';
			require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-decision-v1.php';
			require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-resolver-v1.php';
			require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-snapshot-v1.php';
			require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-consent.php';
			require_once dirname( __DIR__, 2 ) . '/includes/settings/class-attribution-settings.php';
			require_once dirname( __DIR__, 2 ) . '/includes/Modules/Events/class-events-logger.php';
			$GLOBALS['clicktrail_test_events_hooks'] = array();
			$GLOBALS['clicktrail_test_setcookie']    = array();
			$GLOBALS['clicktrail_test_nocache']      = 0;
			$_COOKIE                                 = array();
		}

		/**
		 * The follow-up cookie format, computed independently of the logger: base64url(json) . '.' . HMAC-SHA256 hex.
		 */
		private function signed( array $data, string $salt = 'test-salt-auth' ): string {
			$b64 = rtrim( strtr( base64_encode( json_encode( $data ) ), '+/', '-_' ), '=' );
			return $b64 . '.' . hash_hmac( 'sha256', $b64, $salt );
		}

		private function decode_signed( string $value ): array {
			$parts = explode( '.', $value );
			$this->assertCount( 2, $parts, 'cookie value is <payload>.<signature>' );
			$this->assertSame( hash_hmac( 'sha256', $parts[0], 'test-salt-auth' ), $parts[1] );
			return json_decode( base64_decode( strtr( $parts[0], '-_', '+/' ) ), true );
		}

		private function process_and_render( Events_Logger $logger ): string {
			$logger->process_event_cookies();
			ob_start();
			$logger->render_server_events();
			return ob_get_clean();
		}

		private function assert_rejected( string $cookie_name, string $value ): void {
			$this->require_consent( false );
			$_COOKIE[ $cookie_name ] = $value;

			$this->assertSame( '', $this->process_and_render( $this->logger() ) );
			$this->assertSame( array( $cookie_name ), array_column( $GLOBALS['clicktrail_test_setcookie'], 0 ), 'a rejected cookie is still expired' );
			$this->assertSame( 0, $GLOBALS['clicktrail_test_nocache'] );
			$this->assertFalse( defined( 'DONOTCACHEPAGE' ) );
		}

		/**
		 * F11: a forged, unsigned follow-up cookie (any event name) must not reach window.clicutclServerEvents / dataLayer.
		 */
		public function test_a_forged_unsigned_cookie_renders_nothing(): void {
			$this->assert_rejected( 'ct_event_login', '{"event":"lead","event_id":"evt_forged","params":{"value":999}}' );
		}

		public function test_a_cookie_with_a_wrong_signature_renders_nothing(): void {
			$this->assert_rejected( 'ct_event_login', $this->signed( array( 'event' => 'login', 'event_id' => 'evt_bad' ), 'attacker-salt' ) );
		}

		public function test_a_signed_cookie_with_a_disallowed_event_name_renders_nothing(): void {
			$this->assert_rejected( 'ct_event_login', $this->signed( array( 'event' => 'lead', 'event_id' => 'evt_lead' ) ) );
		}

		public function test_a_signed_event_in_another_events_cookie_renders_nothing(): void {
			$this->assert_rejected( 'ct_event_login', $this->signed( array( 'event' => 'sign_up', 'event_id' => 'evt_swap' ) ) );
		}

		public function test_a_valid_signed_cookie_renders_once_clears_and_marks_the_response_uncacheable(): void {
			$this->require_consent( false );
			$logger                      = $this->logger();
			$_COOKIE['ct_event_comment'] = $this->signed( array( 'event' => 'comment_submit', 'event_id' => 'evt_ok', 'params' => array( 'comment_id' => 7 ) ) );

			$out = $this->process_and_render( $logger );

			$this->assertSame( 1, substr_count( $out, '"event":"comment_submit"' ) );
			$this->assertSame( 1, substr_count( $out, 'evt_ok' ) );
			$this->assertSame( array( 'ct_event_comment' ), array_column( $GLOBALS['clicktrail_test_setcookie'], 0 ) );
			$this->assertSame( '', $GLOBALS['clicktrail_test_setcookie'][0][1] );
			$this->assertSame( 1, $GLOBALS['clicktrail_test_nocache'] );
			$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );

			ob_start();
			$logger->render_server_events();
			$this->assertSame( '', ob_get_clean(), 'rendered once' );
		}

		public function test_a_cookie_the_logger_writes_is_signed_and_read_back(): void {
			$this->require_consent( false );
			require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-event.php';
			$this->logger()->log_login_event( 'alice', (object) array( 'ID' => 5 ) );

			$this->assertSame( 'ct_event_login', $GLOBALS['clicktrail_test_setcookie'][0][0] );
			$value = $GLOBALS['clicktrail_test_setcookie'][0][1];
			$this->assertSame( 'login', $this->decode_signed( $value )['event'] );
			$this->assertTrue( $GLOBALS['clicktrail_test_setcookie'][0][2]['httponly'] );

			$GLOBALS['clicktrail_test_setcookie'] = array();
			$_COOKIE['ct_event_login']            = $value;
			$this->assertStringContainsString( '"event":"login"', $this->process_and_render( $this->logger() ) );
		}

		public function test_no_cache_signal_without_a_pending_event_cookie(): void {
			$this->require_consent( false );
			$this->assertSame( '', $this->process_and_render( $this->logger() ) );
			$this->assertSame( 0, $GLOBALS['clicktrail_test_nocache'] );
			$this->assertFalse( defined( 'DONOTCACHEPAGE' ) );
		}

		private function require_consent( bool $required ): void {
			$GLOBALS['clicktrail_test_options'] = array( 'clicutcl_attribution_settings' => array( 'require_consent' => $required ) );
		}

		private function logger(): Events_Logger {
			$logger = new Events_Logger( new Context( '/tmp/x.php' ) );
			$logger->register();
			return $logger;
		}

		private function callbacks( string $hook ): array {
			return array_map( static fn( $h ) => $h[0][1], $GLOBALS['clicktrail_test_events_hooks'][ $hook ] ?? array() );
		}

		public function test_clearing_is_hooked_before_output_and_wp_head_sets_no_cookie(): void {
			$this->require_consent( false );
			$logger = $this->logger();
			$this->assertContains( 'process_event_cookies', $this->callbacks( 'send_headers' ) );

			$_COOKIE['ct_event_login'] = $this->signed( array( 'event' => 'login', 'event_id' => 'evt_1' ) );
			$logger->process_event_cookies();
			$cleared = count( $GLOBALS['clicktrail_test_setcookie'] );
			$this->assertSame( 1, $cleared );

			ob_start();
			$logger->render_server_events();
			$out = ob_get_clean();
			$this->assertSame( $cleared, count( $GLOBALS['clicktrail_test_setcookie'] ), 'wp_head must not call setcookie()' );
			$this->assertStringContainsString( 'evt_1', $out );
		}

		public function test_denied_consent_clears_all_and_prints_nothing(): void {
			$this->require_consent( true );
			$logger = $this->logger();
			$_COOKIE['ct_event_login'] = '{"event":"login","event_id":"evt_1"}';
			$_COOKIE['ct_event_comment'] = '{"event":"comment_submit","event_id":"evt_2"}';
			$logger->process_event_cookies();
			$this->assertSame( array( 'ct_event_login', 'ct_event_comment' ), array_column( $GLOBALS['clicktrail_test_setcookie'], 0 ) );

			$before = count( $GLOBALS['clicktrail_test_setcookie'] );
			ob_start();
			$logger->render_server_events();
			$this->assertSame( '', ob_get_clean() );
			$this->assertCount( $before, $GLOBALS['clicktrail_test_setcookie'] );
		}

		/**
		 * A first-time visitor (no ct_event_* cookie) must get no Set-Cookie header: it makes CDNs skip the edge cache (#164).
		 */
		public function test_no_set_cookie_without_a_pending_event_cookie(): void {
			foreach ( array( true, false ) as $required ) {
				$GLOBALS['clicktrail_test_setcookie'] = array();
				$this->require_consent( $required );
				$this->logger()->process_event_cookies();
				$this->assertSame( array(), $GLOBALS['clicktrail_test_setcookie'], 'require_consent=' . var_export( $required, true ) );
			}
		}

		/**
		 * One sign_up identity for an Ultimate Member registration: the core `user_register` cookie event and the
		 * UM handler's server-side event share one event ID, a plain registration keeps a random one (#174).
		 */
		private function um_signup_event_id(): string {
			$this->assertCount( 1, $GLOBALS['clicktrail_test_setcookie'] );
			$this->assertSame( 'ct_event_signup', $GLOBALS['clicktrail_test_setcookie'][0][0] );
			$payload = $this->decode_signed( $GLOBALS['clicktrail_test_setcookie'][0][1] );
			$this->assertSame( 'sign_up', $payload['event'] );
			return $payload['event_id'];
		}

		private function um_integration(): \CLICUTCL\Integrations\Ultimate_Member {
			require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-event.php';
			require_once dirname( __DIR__, 2 ) . '/includes/integrations/class-ultimate-member.php';
			$um = new \CLICUTCL\Integrations\Ultimate_Member();
			$GLOBALS['clicktrail_test_filters']['clicutcl_signup_event_id'] = array( $um, 'filter_signup_event_id' );
			return $um;
		}

		public function test_a_plain_wordpress_registration_keeps_a_random_signup_event_id(): void {
			$this->require_consent( false );
			$this->um_integration();

			$this->logger()->log_signup_event( 42 );

			$this->assertStringStartsWith( 'signup_', $this->um_signup_event_id() );
		}

		public function test_an_um_registration_gets_the_event_id_the_um_handler_dispatches(): void {
			$this->require_consent( false );
			$um = $this->um_integration();

			$um->mark_registration_in_flight();
			$this->logger()->log_signup_event( 42 );

			$this->assertSame( \CLICUTCL\Integrations\Ultimate_Member::event_id( 42 ), $this->um_signup_event_id() );
		}

		public function test_denied_consent_sets_no_signup_cookie_for_an_um_registration(): void {
			$this->require_consent( true );
			$um = $this->um_integration();

			$um->mark_registration_in_flight();
			$this->logger()->log_signup_event( 42 );

			$this->assertSame( array(), $GLOBALS['clicktrail_test_setcookie'] );
		}
	}
}
