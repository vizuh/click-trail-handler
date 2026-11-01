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

	class WPCF7_ContactForm {
		public static $current;
		public $props = array();
		public $id    = 5;

		public static function get_current() {
			return self::$current;
		}

		public function id() {
			return $this->id;
		}

		public function prop( $name ) {
			return $this->props[ $name ] ?? null;
		}
	}

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

		private function form( string $form, array $mail = array(), array $mail_2 = array() ): void {
			$f              = new \WPCF7_ContactForm();
			$f->props       = array( 'form' => $form, 'mail' => $mail, 'mail_2' => $mail_2 );
			\WPCF7_ContactForm::$current = $f;
		}

		private function ct_fields( array $fields ): array {
			return array_filter( $fields, static fn( $k ) => 0 === strpos( (string) $k, 'ct_' ), ARRAY_FILTER_USE_KEY );
		}

		public function test_form_without_ct_references_gets_no_ct_field(): void {
			$this->form( '[text* your-name] [submit]', array( 'body' => '[your-name]', 'subject' => 'Hi' ) );

			$fields = ( new CF7_Adapter() )->add_hidden_fields( array( '_wpcf7_version' => '6.0' ) );

			$this->assertSame( array( '_wpcf7_version' => '6.0' ), $fields );
		}

		public function test_without_a_current_form_nothing_is_rendered(): void {
			\WPCF7_ContactForm::$current = null;
			$this->assertSame( array(), ( new CF7_Adapter() )->add_hidden_fields( array() ) );
		}

		public function test_hidden_tag_renders_only_that_key_empty_by_default(): void {
			$this->form( '[hidden ct_gclid] [text your-name]' );

			$fields = ( new CF7_Adapter() )->add_hidden_fields( array() );

			$this->assertSame( array( 'ct_gclid' => '' ), $this->ct_fields( $fields ), 'Cookie present but the render filter is off: empty.' );
		}

		public function test_mail_tags_in_mail_and_mail_2_render_their_keys(): void {
			$this->form(
				'[text your-name]',
				array( 'body' => 'Source: [ct_fbclid]', 'subject' => 's' ),
				array( 'additional_headers' => 'X-Lt: [_raw_ct_lt_source]' )
			);

			$fields = ( new CF7_Adapter() )->add_hidden_fields( array() );

			$this->assertSame( array( 'ct_fbclid', 'ct_lt_source' ), array_keys( $this->ct_fields( $fields ) ) );
		}

		public function test_unmapped_ct_reference_is_not_rendered(): void {
			$this->form( '[text ct_company] [hidden ct_gclid]', array( 'body' => '[ct_company]' ) );

			$fields = ( new CF7_Adapter() )->add_hidden_fields( array() );

			$this->assertSame( array( 'ct_gclid' ), array_keys( $this->ct_fields( $fields ) ) );
		}

		public function test_render_carries_the_cookie_only_for_referenced_keys_when_opted_in(): void {
			$this->form( '[hidden ct_gclid] [hidden ct_fbclid]' );
			$GLOBALS['clicktrail_test_filters']['clicutcl_render_server_attribution_values'] = static fn() => true;

			$fields = $this->ct_fields( ( new CF7_Adapter() )->add_hidden_fields( array() ) );

			$this->assertSame( array( 'ct_gclid' => 'new', 'ct_fbclid' => '' ), $fields );
		}

		public function test_landing_page_flow_fills_referenced_keys_at_submit_and_mail_tag_resolves(): void {
			$this->form( '[hidden ct_gclid] [text your-name]', array( 'body' => 'g=[ct_gclid] s=[ct_lt_source]' ) );
			$cookie  = $_COOKIE;
			$_COOKIE = array();
			$adapter = new CF7_Adapter();

			$posted = array_merge( array( 'your-name' => 'Jonas' ), $adapter->add_hidden_fields( array() ) );
			$this->assertSame( array( 'ct_gclid' => '', 'ct_lt_source' => '' ), $this->ct_fields( $posted ) );

			$_COOKIE = $cookie;
			$out     = $adapter->refresh_posted_data( $posted );

			$this->assertSame( 'new', $out['ct_gclid'] );
			$this->assertSame( 'google', $out['ct_lt_source'] );
			$this->assertArrayNotHasKey( 'ct_fbclid', $out, 'Unreferenced keys never appear.' );
			// CF7 resolves [ct_gclid] from posted data.
			$this->assertSame( 'g=new s=google', preg_replace_callback( '/\[(\w+)\]/', static fn( $m ) => $out[ $m[1] ] ?? $m[0], 'g=[ct_gclid] s=[ct_lt_source]' ) );
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
			$this->form( '[hidden ct_gclid] [hidden ct_lt_source]' );
			$fields = $this->ct_fields( ( new CF7_Adapter() )->add_hidden_fields( array() ) );

			$this->assertSame( array( 'ct_gclid' => '', 'ct_lt_source' => '' ), $fields, "Must not carry the requesting visitor's value." );
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
