<?php
/**
 * Fluent Forms submit-time refresh of ct_* response data (#142).
 *
 * `fluentform/insert_response_data` (and its legacy alias) rewrites each ct_<key>
 * the form carries from the cookie sent with the submit, per key, replacing the
 * old "cookie only when every posted value is empty" fallback.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

namespace {
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-decision-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-resolver-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-snapshot-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-consent.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Core/class-attribution-provider.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/interface-form-adapter.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-abstract-form-adapter.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-fluent-forms-adapter.php';

	use CLICUTCL\Integrations\Forms\Fluent_Forms_Adapter;
	use PHPUnit\Framework\TestCase;

	/**
	 * Records what reaches ClickTrail's own log; Fluent meta writes need wpFluent(), absent here.
	 */
	final class ClickTrailFluentRefreshLogSpy extends Fluent_Forms_Adapter {
		public $logged = array();

		protected function log_submission( $platform, $form_id, $attribution, $identity_input = array(), $entry_id = null ) {
			$this->logged[] = array( $form_id, $attribution );
			return array();
		}
	}

	/**
	 * Class FluentFormsSubmissionRefreshTest
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class FluentFormsSubmissionRefreshTest extends TestCase {
		protected function setUp(): void {
			require_once dirname( __DIR__ ) . '/helpers/consent-mode-settings-stub.php';
			$_COOKIE = array(
				'attribution' => json_encode(
					array(
						'gclid'     => 'new',
						'ft_source' => 'google',
						'lt_source' => 'meta',
					)
				),
			);
			$GLOBALS['clicktrail_test_consent_required']     = false;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = false;
			$GLOBALS['clicktrail_test_filters']              = array();
		}

		private function form_data(): array {
			return array(
				'names'        => array( 'first_name' => 'Jonas' ),
				'ct_gclid'     => 'old',
				'ct_ft_term'  => 'x',
				'ct_fbclid'    => 'stale-from-render',
				'ct_ft_source' => '',
				'ct_lt_source' => 'google',
			);
		}

		public function test_cookie_wins_per_key_and_keys_missing_from_it_are_blanked(): void {
			$out = ( new Fluent_Forms_Adapter() )->refresh_response_data( $this->form_data(), 7, array() );

			$this->assertSame( array( 'first_name' => 'Jonas' ), $out['names'] );
			$this->assertSame( 'new', $out['ct_gclid'] );
			$this->assertSame( '', $out['ct_ft_term'], 'Keys missing from the cookie are blanked (#152).' );
			$this->assertSame( '', $out['ct_fbclid'] );
			$this->assertSame( 'google', $out['ct_ft_source'], 'An empty posted value is filled.' );
			$this->assertSame( 'meta', $out['ct_lt_source'], 'A non-empty posted value is replaced too (per key, not only when all are empty).' );
			$this->assertArrayNotHasKey( 'ct_lt_medium', $out, 'No field the form does not carry is added.' );
		}

		public function test_all_empty_posted_values_are_filled_per_key_when_the_form_carries_ct_fields(): void {
			$posted = array(
				'ct_gclid'  => '',
				'ct_fbclid' => '',
			);
			$adapter = new ClickTrailFluentRefreshLogSpy();

			$out = $adapter->refresh_response_data( $posted, 7, array() );
			$this->assertSame( array( 'ct_gclid' => 'new', 'ct_fbclid' => '' ), $out );

			$adapter->on_submission( 42, $out, (object) array( 'id' => 7 ) );
			$this->assertSame( array( array( 7, array( 'gclid' => 'new' ) ) ), $adapter->logged, 'The log holds the per-key result, not the whole cookie.' );
		}

		public function test_form_without_ct_fields_logs_the_cookie_payload_and_writes_no_provider_field(): void {
			$posted  = array( 'names' => array( 'first_name' => 'Jonas' ), 'email' => 'j@example.com' );
			$adapter = new ClickTrailFluentRefreshLogSpy();

			$this->assertSame( $posted, $adapter->refresh_response_data( $posted, 7, array() ) );

			$adapter->on_submission( 42, $posted, (object) array( 'id' => 7 ) );
			$this->assertSame(
				array( array( 7, array( 'gclid' => 'new', 'ft_source' => 'google', 'lt_source' => 'meta' ) ) ),
				$adapter->logged
			);
		}

		public function test_running_both_hook_aliases_is_idempotent(): void {
			$adapter = new Fluent_Forms_Adapter();
			$once    = $adapter->refresh_response_data( $this->form_data(), 7, array() );

			$this->assertSame( $once, $adapter->refresh_response_data( $once, 7, array() ) );
		}

		public function test_visitor_a_values_with_visitor_b_cookie_lacking_two_keys_are_blanked(): void {
			$_COOKIE = array( 'attribution' => json_encode( array( 'gclid' => 'B1' ) ) );
			$adapter = new ClickTrailFluentRefreshLogSpy();
			$posted  = array( 'ct_gclid' => 'A1', 'ct_ft_source' => 'A2', 'ct_lt_source' => 'A3' );

			$out = $adapter->refresh_response_data( $posted, 7, array() );
			$this->assertSame( array( 'ct_gclid' => 'B1', 'ct_ft_source' => '', 'ct_lt_source' => '' ), $out );

			$adapter->on_submission( 42, $posted, (object) array( 'id' => 7 ) );
			$this->assertSame( array( array( 7, array( 'gclid' => 'B1' ) ) ), $adapter->logged, 'log_submission() gets the post-rule set, not A\'s values.' );
		}

		public function test_no_cookie_keeps_posted_values_only_with_the_marker_and_strips_it(): void {
			$_COOKIE = array();
			$adapter = new ClickTrailFluentRefreshLogSpy();

			$marked = $adapter->refresh_response_data( array( 'ct_gclid' => 'A1', 'ct_fill_source' => 'js' ), 7, array() );
			$this->assertSame( array( 'ct_gclid' => 'A1' ), $marked );

			$plain = $adapter->refresh_response_data( array( 'ct_gclid' => 'A1' ), 7, array() );
			$this->assertSame( array( 'ct_gclid' => '' ), $plain );

			$adapter->on_submission( 42, array( 'ct_gclid' => 'A1' ), (object) array( 'id' => 7 ) );
			$this->assertSame( array(), $adapter->logged, 'An unmarked, cookie-less submit logs nothing.' );
		}

		public function test_denied_consent_blanks_every_ct_value_and_logs_nothing(): void {
			$GLOBALS['clicktrail_test_consent_required']     = true;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = true;
			$_COOKIE['production_consent']                   = 'denied';
			$adapter                                         = new ClickTrailFluentRefreshLogSpy();

			$out = $adapter->refresh_response_data( $this->form_data(), 7, array() );
			foreach ( array( 'ct_gclid', 'ct_ft_term', 'ct_fbclid', 'ct_ft_source', 'ct_lt_source' ) as $name ) {
				$this->assertSame( '', $out[ $name ], "$name is blanked before Fluent Forms stores it." );
			}
			$this->assertSame( array( 'first_name' => 'Jonas' ), $out['names'] );

			$adapter->on_submission( 42, $this->form_data(), (object) array( 'id' => 7 ) );
			$this->assertSame( array(), $adapter->logged );
		}

		public function test_an_unmapped_ct_field_is_never_touched(): void {
			$this->in_each_state(
				function ( string $state ): void {
					$out = ( new Fluent_Forms_Adapter() )->refresh_response_data( array( 'ct_company' => 'Acme', 'ct_gclid' => 'A1' ), 7, array() );
					$this->assertSame( 'Acme', $out['ct_company'], "ct_company is the visitor's own field ($state)." );
				}
			);
		}

		public function test_a_cookieless_marker_filled_submit_is_logged_although_fluent_strips_the_marker(): void {
			$_COOKIE = array();
			$adapter = new ClickTrailFluentRefreshLogSpy();
			// Fluent drops the undeclared marker before the filter; it only survives in the raw AJAX request.
			$_POST = array( 'data' => 'ct_gclid=A1&ct_fill_source=js' );

			$refreshed = $adapter->refresh_response_data( array( 'ct_gclid' => 'A1' ), 7, array() );
			$this->assertSame( array( 'ct_gclid' => 'A1' ), $refreshed );

			// submission_inserted receives the refreshed copy, with no marker and no $_POST.
			$_POST = array();
			$adapter->on_submission( 42, $refreshed, (object) array( 'id' => 7 ) );
			$this->assertSame( array( array( 7, array( 'gclid' => 'A1' ) ) ), $adapter->logged );
		}

		public function test_a_cookieless_unmarked_submit_is_blanked_and_not_logged(): void {
			$_COOKIE = array();
			$_POST   = array( 'data' => 'ct_gclid=A1' );
			$adapter = new ClickTrailFluentRefreshLogSpy();

			$refreshed = $adapter->refresh_response_data( array( 'ct_gclid' => 'A1' ), 7, array() );
			$adapter->on_submission( 42, $refreshed, (object) array( 'id' => 7 ) );

			$this->assertSame( array( 'ct_gclid' => '' ), $refreshed );
			$this->assertSame( array(), $adapter->logged );
		}

		/**
		 * Run $check in each state: valid cookie, consent blocked, no cookie.
		 */
		private function in_each_state( callable $check ): void {
			$check( 'valid cookie' );

			$_COOKIE = array();
			$check( 'no cookie' );

			$GLOBALS['clicktrail_test_consent_required']     = true;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = true;
			$_COOKIE['production_consent']                   = 'denied';
			$check( 'consent blocked' );
		}
	}
}
