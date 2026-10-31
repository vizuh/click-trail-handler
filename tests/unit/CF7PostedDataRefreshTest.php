<?php
/**
 * Contact Form 7 submit-time refresh of posted ct_* data (#142).
 *
 * CF7 renders the ct_* hidden inputs from the cookie the page request carried,
 * so a landing-page submit posts empty or previous-touch values. The
 * `wpcf7_posted_data` filter rewrites them from the cookie sent with the submit,
 * before CF7 builds mail and before `wpcf7_mail_sent`.
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
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-cf7-adapter.php';

	use CLICUTCL\Integrations\Forms\CF7_Adapter;
	use PHPUnit\Framework\TestCase;

	/**
	 * Class CF7PostedDataRefreshTest
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class CF7PostedDataRefreshTest extends TestCase {
		protected function setUp(): void {
			require_once dirname( __DIR__ ) . '/helpers/consent-mode-settings-stub.php';
			$_COOKIE = array(
				'attribution' => json_encode(
					array(
						'gclid'       => 'new',
						'lt_source'   => 'google',
						'lt_campaign' => "o'brien",
					)
				),
			);
			$GLOBALS['clicktrail_test_consent_required']     = false;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = false;
			$GLOBALS['clicktrail_test_filters']              = array();
		}

		private function posted(): array {
			return array(
				'your-name'      => 'Jonas',
				'ct_gclid'       => 'old',
				'ct_ft_term'    => 'x',
				'ct_fbclid'      => 'stale-from-render',
				'ct_lt_campaign' => '',
			);
		}

		public function test_cookie_wins_and_keys_missing_from_it_are_blanked(): void {
			$out = ( new CF7_Adapter() )->refresh_posted_data( $this->posted() );

			$this->assertSame( 'Jonas', $out['your-name'] );
			$this->assertSame( 'new', $out['ct_gclid'], 'A non-empty cookie key replaces the posted value.' );
			$this->assertSame( '', $out['ct_ft_term'], 'Keys missing from the cookie are blanked: they may be another visitor\'s (#152).' );
			$this->assertSame( '', $out['ct_fbclid'], 'A stale or foreign value is blanked when the cookie lacks the key.' );
			$this->assertSame( "o'brien", $out['ct_lt_campaign'], 'Posted data is already unslashed, so the value is not slashed.' );
		}

		public function test_keys_the_form_did_not_post_are_not_added(): void {
			$out = ( new CF7_Adapter() )->refresh_posted_data( array( 'your-name' => 'Jonas', 'ct_gclid' => '' ) );

			$this->assertSame( array( 'your-name' => 'Jonas', 'ct_gclid' => 'new' ), $out, 'Cookie keys the form does not carry (lt_source, lt_campaign) stay out.' );
		}

		public function test_render_emits_every_mapped_key_empty_when_the_cookie_is_absent(): void {
			$_COOKIE = array();

			$fields = ( new CF7_Adapter() )->add_hidden_fields( array( '_wpcf7_version' => '6.0' ) );

			$this->assertSame( '6.0', $fields['_wpcf7_version'] );
			foreach ( \CLICUTCL\Core\Attribution_Provider::get_field_mapping() as $key ) {
				$this->assertSame( '', $fields[ 'ct_' . $key ], "ct_{$key} is rendered empty." );
			}
		}

		public function test_landing_page_flow_renders_empty_fields_and_fills_them_at_submit(): void {
			$cookie  = $_COOKIE;
			$_COOKIE = array();
			$adapter = new CF7_Adapter();

			// Render: the cookie does not exist yet, CF7 posts what was rendered.
			$posted = array_merge( array( 'your-name' => 'Jonas' ), $adapter->add_hidden_fields( array() ) );
			$this->assertSame( '', $posted['ct_gclid'] );

			// Submit: the browser wrote the cookie in between.
			$_COOKIE = $cookie;
			$out     = $adapter->refresh_posted_data( $posted );

			$this->assertSame( 'new', $out['ct_gclid'] );
			$this->assertSame( 'google', $out['ct_lt_source'] );
			$this->assertSame( "o'brien", $out['ct_lt_campaign'] );
			$this->assertSame( '', $out['ct_fbclid'], 'Rendered keys the cookie lacks stay empty.' );
		}

		public function test_denied_consent_blanks_every_posted_ct_value(): void {
			$GLOBALS['clicktrail_test_consent_required']     = true;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = true;
			$_COOKIE['production_consent']                   = 'denied';

			$out = ( new CF7_Adapter() )->refresh_posted_data( $this->posted() );

			$this->assertSame( 'Jonas', $out['your-name'] );
			foreach ( array( 'ct_gclid', 'ct_ft_term', 'ct_fbclid', 'ct_lt_campaign' ) as $name ) {
				$this->assertSame( '', $out[ $name ], "$name is blanked before CF7 mails or stores it." );
			}
		}

		public function test_visitor_a_values_with_visitor_b_cookie_lacking_two_keys_are_blanked(): void {
			$_COOKIE = array( 'attribution' => json_encode( array( 'gclid' => 'B1' ) ) );
			$out     = ( new CF7_Adapter() )->refresh_posted_data(
				array(
					'ct_gclid'     => 'A1',
					'ct_ft_source' => 'A2',
					'ct_lt_source' => 'A3',
				)
			);

			$this->assertSame( array( 'ct_gclid' => 'B1', 'ct_ft_source' => '', 'ct_lt_source' => '' ), $out );
		}

		public function test_no_cookie_with_the_js_marker_keeps_posted_values_and_strips_the_marker(): void {
			$_COOKIE = array();
			$out     = ( new CF7_Adapter() )->refresh_posted_data( array( 'ct_gclid' => 'A1', 'ct_fill_source' => 'js' ) );

			$this->assertSame( array( 'ct_gclid' => 'A1' ), $out );
		}

		public function test_no_cookie_without_the_marker_blanks_every_posted_value(): void {
			$_COOKIE = array();
			$out     = ( new CF7_Adapter() )->refresh_posted_data( array( 'ct_gclid' => 'A1', 'ct_ft_source' => 'A2' ) );

			$this->assertSame( array( 'ct_gclid' => '', 'ct_ft_source' => '' ), $out );
		}

		public function test_a_malformed_cookie_counts_as_absent(): void {
			$_COOKIE = array( 'attribution' => '{not json' );
			$out     = ( new CF7_Adapter() )->refresh_posted_data( array( 'ct_gclid' => 'A1' ) );

			$this->assertSame( array( 'ct_gclid' => '' ), $out );
		}

		public function test_render_is_empty_by_default_even_with_a_cookie(): void {
			$fields = ( new CF7_Adapter() )->add_hidden_fields( array() );

			foreach ( \CLICUTCL\Core\Attribution_Provider::get_field_mapping() as $key ) {
				$this->assertSame( '', $fields[ 'ct_' . $key ], "ct_{$key} must not carry the requesting visitor's value." );
			}
		}

		public function test_render_carries_the_cookie_when_the_filter_opts_in(): void {
			$GLOBALS['clicktrail_test_filters']['clicutcl_render_server_attribution_values'] = static fn() => true;
			$fields = ( new CF7_Adapter() )->add_hidden_fields( array() );

			$this->assertSame( 'new', $fields['ct_gclid'] );
			$this->assertSame( 'google', $fields['ct_lt_source'] );
			$this->assertSame( '', $fields['ct_fbclid'] );
		}

		public function test_non_array_input_passes_through(): void {
			$this->assertNull( ( new CF7_Adapter() )->refresh_posted_data( null ) );
		}

		public function test_an_unmapped_ct_field_is_never_touched(): void {
			$cookie = $_COOKIE;
			$run    = function ( string $state ): void {
				$out = ( new CF7_Adapter() )->refresh_posted_data( array( 'ct_company' => 'Acme', 'ct_gclid' => 'A1' ) );
				$this->assertSame( 'Acme', $out['ct_company'], "ct_company is the visitor's own field ($state)." );
			};
			$this->in_each_state( $run );
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
