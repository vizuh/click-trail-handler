<?php
/**
 * Ultimate Member registration attribution (#174).
 *
 * @package ClickTrail
 */

declare(strict_types=1);

namespace CLICUTCL\Integrations {
	/**
	 * Hook recorders for init(); the bootstrap stubs are global no-ops.
	 */
	if ( ! function_exists( __NAMESPACE__ . '\\add_action' ) ) {
		function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
			$GLOBALS['clicktrail_test_um_hooks'][] = array( 'action', $tag, $callback[1], $priority, $accepted_args );
			return true;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\add_filter' ) ) {
		function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
			$GLOBALS['clicktrail_test_um_hooks'][] = array( 'filter', $tag, $callback[1], $priority, $accepted_args );
			return true;
		}
	}
}

namespace CLICUTCL\Server_Side {
	if ( ! class_exists( Dispatcher::class, false ) ) {
		/**
		 * Counting Dispatcher double; the real one needs the full server-side stack.
		 */
		class Dispatcher {
			/**
			 * Record a dispatched event and report success unless a test forces another outcome.
			 *
			 * @param Event $event Event.
			 * @return Adapter_Result
			 */
			public static function dispatch( Event $event ) {
				$GLOBALS['clicktrail_test_dispatches'][] = $event->to_array();
				if ( ! empty( $GLOBALS['clicktrail_test_dispatch_throws'] ) ) {
					throw new \RuntimeException( 'collector_down' );
				}
				if ( ! empty( $GLOBALS['clicktrail_test_dispatch_fails'] ) ) {
					return Adapter_Result::error( 500, 'collector_down' );
				}
				return Adapter_Result::success( 200, 'sent' );
			}
		}
	}
}

namespace CLICUTCL\Tracking {
	if ( ! class_exists( Identity_Resolver::class, false ) ) {
		/**
		 * Identity double: echoes the input it was given and the consent flag it was told about.
		 */
		class Identity_Resolver {
			/**
			 * Return the resolver input so tests can see what was passed.
			 *
			 * @param array $input   Identity input.
			 * @param array $options Resolver options.
			 * @return array
			 */
			public function resolve( array $input, array $options = array() ): array {
				return array(
					'input'   => $input,
					'options' => $options,
				);
			}
		}
	}
}

namespace {
	require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-adapter-result.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-decision-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-resolver-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-snapshot-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-consent.php';
	require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-event.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Core/class-attribution-provider.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-claim-lock.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/class-ultimate-member.php';
	require_once dirname( __DIR__ ) . '/helpers/user-meta-stubs.php';

	use CLICUTCL\Integrations\Ultimate_Member;
	use PHPUnit\Framework\TestCase;

	/**
	 * Row-conditional delete like the options table: only when name and exact value match (Claim_Lock release).
	 */
	final class ClickTrailUmWpdbStub {
		use \Claim_Options_Wpdb;

		public $options = 'wp_options';

		public function delete( $table, $where ) {
			$name = (string) $where['option_name'];
			if ( ! isset( $GLOBALS['clicktrail_test_options'][ $name ] ) || (string) $GLOBALS['clicktrail_test_options'][ $name ] !== (string) $where['option_value'] ) {
				return 0;
			}
			unset( $GLOBALS['clicktrail_test_options'][ $name ] );
			return 1;
		}
	}

	/**
	 * Class UltimateMemberRegistrationTest
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class UltimateMemberRegistrationTest extends TestCase {
		private const USER_ID = 42;

		protected function setUp(): void {
			require_once dirname( __DIR__ ) . '/helpers/consent-mode-settings-stub.php';
			$_COOKIE = array();
			$_POST   = array();
			$GLOBALS['clicktrail_test_consent_required']     = false;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = false;
			$GLOBALS['clicktrail_test_dispatches']           = array();
			$GLOBALS['clicktrail_test_dispatch_fails']       = false;
			$GLOBALS['clicktrail_test_dispatch_throws']      = false;
			$GLOBALS['clicktrail_test_user_meta']            = array();
			$GLOBALS['clicktrail_test_options']              = array();
			$GLOBALS['clicktrail_test_is_admin']             = false;
			$GLOBALS['wpdb']                                 = new ClickTrailUmWpdbStub();
			$GLOBALS['clicktrail_test_users']                = array(
				self::USER_ID => (object) array(
					'ID'         => self::USER_ID,
					'user_email' => 'new.member@example.test',
				),
			);
		}

		private function attribution_cookie(): void {
			$_COOKIE['attribution']   = json_encode(
				array(
					'ft_source'   => 'test',
					'lt_source'   => 'test',
					'lt_medium'   => 'cpc',
					'gclid'       => 'GCLID-1',
					'session_count' => '3',
					'not_allowed' => 'must-not-be-stored',
				)
			);
			$_COOKIE['ct_visitor_id'] = 'visitor-1';
			$_COOKIE['ct_session']    = json_encode( array( 'session_id' => 'sess-1', 'session_number' => 2 ) );
		}

		private function require_consent( ?string $state ): void {
			$GLOBALS['clicktrail_test_consent_required']     = true;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = true;
			if ( null !== $state ) {
				$_COOKIE['production_consent'] = $state;
			}
		}

		private function register_user( int $user_id = self::USER_ID, ?array $form_data = array( 'form_id' => 7, 'mode' => 'register' ) ): void {
			( new Ultimate_Member() )->on_registration_complete(
				$user_id,
				array(
					'form_id'       => '7',
					'user_email'    => 'new.member@example.test',
					'user_password' => 'super-secret-password',
				),
				$form_data
			);
		}

		private function meta( string $key = '' ) {
			$all = $GLOBALS['clicktrail_test_user_meta'][ self::USER_ID ] ?? array();
			return '' === $key ? $all : ( $all[ $key ] ?? null );
		}

		public function test_registration_with_attribution_stores_allowlisted_user_meta_and_dispatches_one_sign_up(): void {
			$this->attribution_cookie();

			$this->register_user();

			$this->assertSame( 'GCLID-1', $this->meta( '_clicutcl_gclid' ) );
			$this->assertSame( 'test', $this->meta( '_clicutcl_ft_source' ) );
			$this->assertSame( 'test', $this->meta( '_clicutcl_lt_source' ) );
			$this->assertSame( 'cpc', $this->meta( '_clicutcl_lt_medium' ) );
			$this->assertSame( 'visitor-1', $this->meta( '_clicutcl_visitor_id' ) );
			$this->assertSame( 'sess-1', $this->meta( '_clicutcl_session_id' ) );
			$this->assertSame( 3, $this->meta( '_clicutcl_session_count' ) );
			$this->assertArrayNotHasKey( '_clicutcl_not_allowed', $this->meta() );

			$this->assertCount( 1, $GLOBALS['clicktrail_test_dispatches'] );
			$event = $GLOBALS['clicktrail_test_dispatches'][0];
			$this->assertSame( 'sign_up', $event['event_name'] );
			$this->assertSame( 'um_registration_42', $event['event_id'] );
			$this->assertSame( 'ultimate-member', $event['form']['platform'] );
			$this->assertSame( '7', $event['form']['id'] );
			$this->assertSame( 'GCLID-1', $event['attribution']['gclid'] );
			$this->assertSame( 'new.member@example.test', $event['identity']['input']['email'] );
		}

		public function test_no_password_or_posted_value_is_ever_read_into_meta_or_the_event(): void {
			$this->attribution_cookie();
			$_POST = array(
				'ct_gclid'      => 'POSTED-GCLID',
				'user_password' => 'super-secret-password',
			);

			$this->register_user();

			$this->assertSame( 'GCLID-1', $this->meta( '_clicutcl_gclid' ), 'only the cookie sent with the request is a source' );
			$this->assertStringNotContainsString( 'super-secret-password', (string) json_encode( $this->meta() ) );
			$this->assertStringNotContainsString( 'super-secret-password', (string) json_encode( $GLOBALS['clicktrail_test_dispatches'] ) );
			$this->assertStringNotContainsString( 'POSTED-GCLID', (string) json_encode( $GLOBALS['clicktrail_test_dispatches'] ) );
		}

		public function test_denied_consent_stores_nothing_and_dispatches_nothing(): void {
			$this->attribution_cookie();
			$this->require_consent( 'denied' );

			$this->register_user();

			$this->assertSame( array(), $this->meta() );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_dispatches'] );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_options'], 'a gated registration takes no claim' );
		}

		public function test_consent_required_but_not_yet_decided_stores_nothing(): void {
			$this->attribution_cookie();
			$this->require_consent( null );

			$this->register_user();

			$this->assertSame( array(), $this->meta() );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_dispatches'] );
		}

		public function test_granted_consent_stores_and_dispatches(): void {
			$this->attribution_cookie();
			$this->require_consent( 'granted' );

			$this->register_user();

			$this->assertSame( 'GCLID-1', $this->meta( '_clicutcl_gclid' ) );
			$this->assertCount( 1, $GLOBALS['clicktrail_test_dispatches'] );
		}

		public function test_registration_without_an_attribution_cookie_still_counts_as_a_sign_up_but_stores_no_attribution(): void {
			$this->register_user();

			$this->assertCount( 1, $GLOBALS['clicktrail_test_dispatches'] );
			$this->assertSame( array( '_clicutcl_um_signup_sent' ), array_keys( $this->meta() ) );
		}

		public function test_the_hook_firing_twice_for_one_user_dispatches_once(): void {
			$this->attribution_cookie();

			$this->register_user();
			$this->register_user();

			$this->assertCount( 1, $GLOBALS['clicktrail_test_dispatches'] );
		}

		public function test_a_held_claim_makes_the_second_caller_a_no_op(): void {
			$this->attribution_cookie();
			$claim = \CLICUTCL\Tracking\Claim_Lock::acquire( Ultimate_Member::LOCK_PREFIX . self::USER_ID, 30 );
			$this->assertNotSame( '', $claim );

			$this->register_user();

			$this->assertSame( array(), $GLOBALS['clicktrail_test_dispatches'] );
			$this->assertNull( $this->meta( '_clicutcl_gclid' ) );
			$this->assertArrayHasKey( Ultimate_Member::LOCK_PREFIX . self::USER_ID, $GLOBALS['clicktrail_test_options'], 'the loser must not release the winner claim' );
		}

		public function test_the_claim_is_released_after_the_dispatch(): void {
			$this->attribution_cookie();

			$this->register_user();

			$this->assertArrayNotHasKey( Ultimate_Member::LOCK_PREFIX . self::USER_ID, $GLOBALS['clicktrail_test_options'] );
		}

		public function test_a_dispatch_exception_releases_the_claim_and_leaves_no_sent_marker(): void {
			$this->attribution_cookie();
			$GLOBALS['clicktrail_test_dispatch_throws'] = true;

			try {
				$this->register_user();
				$this->fail( 'the dispatch exception must propagate' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'collector_down', $e->getMessage() );
			}

			$this->assertArrayNotHasKey( Ultimate_Member::LOCK_PREFIX . self::USER_ID, $GLOBALS['clicktrail_test_options'] );
			$this->assertNull( $this->meta( Ultimate_Member::SENT_META_KEY ) );
		}

		public function test_a_failed_dispatch_leaves_no_sent_marker_so_a_retry_can_send(): void {
			$this->attribution_cookie();
			$GLOBALS['clicktrail_test_dispatch_fails'] = true;

			$this->register_user();
			$this->assertNull( $this->meta( Ultimate_Member::SENT_META_KEY ) );

			$GLOBALS['clicktrail_test_dispatch_fails'] = false;
			$this->register_user();
			$this->assertCount( 2, $GLOBALS['clicktrail_test_dispatches'] );
			$this->assertSame( 'yes', $this->meta( Ultimate_Member::SENT_META_KEY ) );
		}

		public function test_a_user_created_from_wp_admin_is_not_a_visitor_registration(): void {
			$this->attribution_cookie();

			$this->register_user( self::USER_ID, null );

			$GLOBALS['clicktrail_test_is_admin'] = true;
			$this->register_user();

			$this->assertSame( array(), $this->meta() );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_dispatches'] );
		}

		public function test_an_invalid_user_id_does_nothing(): void {
			$this->attribution_cookie();

			( new Ultimate_Member() )->on_registration_complete( 0, array(), array( 'form_id' => 7 ) );
			( new Ultimate_Member() )->on_registration_complete( new \WP_Error( 'x' ), array(), array( 'form_id' => 7 ) );

			$this->assertSame( array(), $GLOBALS['clicktrail_test_dispatches'] );
		}

		public function test_signup_event_id_is_aligned_only_while_an_um_registration_is_in_flight(): void {
			$um = new Ultimate_Member();

			$this->assertSame( 'signup_random', $um->filter_signup_event_id( 'signup_random', self::USER_ID ) );

			$um->mark_registration_in_flight();
			$this->assertSame( 'um_registration_42', $um->filter_signup_event_id( 'signup_random', self::USER_ID ) );

			$um->on_registration_complete( self::USER_ID, array(), array( 'form_id' => 7 ) );
			$this->assertSame( 'signup_random', $um->filter_signup_event_id( 'signup_random', self::USER_ID ), 'the flag clears once UM registration completed' );
		}

		public function test_event_id_helper_is_deterministic(): void {
			$this->assertSame( 'um_registration_42', Ultimate_Member::event_id( 42 ) );
		}

		public function test_is_active_follows_the_um_constants(): void {
			$this->assertFalse( Ultimate_Member::is_active() );
		}

		public function test_is_active_needs_the_um_version_constant_and_the_um_function(): void {
			define( 'UM_VERSION', '2.14.1' );
			$this->assertFalse( Ultimate_Member::is_active() );
			eval( 'function UM() { return null; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares UM()'s function in a separate process.
			$this->assertTrue( Ultimate_Member::is_active() );
		}

		/**
		 * Hook names and argument counts verified against Ultimate Member 2.14.1 source
		 * (`includes/core/class-form.php`, `um-actions-register.php`).
		 */
		public function test_init_registers_the_um_hooks_and_the_core_signup_filter(): void {
			$GLOBALS['clicktrail_test_um_hooks'] = array();

			( new Ultimate_Member() )->init();

			$this->assertSame(
				array(
					array( 'action', 'um_submit_form_register', 'mark_registration_in_flight', 5, 0 ),
					array( 'action', 'um_registration_complete', 'on_registration_complete', 10, 3 ),
					array( 'filter', 'clicutcl_signup_event_id', 'filter_signup_event_id', 10, 2 ),
				),
				$GLOBALS['clicktrail_test_um_hooks']
			);
		}
	}
}
