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
			$_COOKIE                                 = array();
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

			$_COOKIE['ct_event_login'] = '{"event":"login","event_id":"evt_1"}';
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
			$logger->process_event_cookies();
			$this->assertCount( 3, $GLOBALS['clicktrail_test_setcookie'] );

			$before = count( $GLOBALS['clicktrail_test_setcookie'] );
			ob_start();
			$logger->render_server_events();
			$this->assertSame( '', ob_get_clean() );
			$this->assertCount( $before, $GLOBALS['clicktrail_test_setcookie'] );
		}
	}
}
