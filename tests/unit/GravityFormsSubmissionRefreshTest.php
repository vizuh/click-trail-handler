<?php
/**
 * Gravity Forms landing-page submission regression test.
 *
 * A form submitted on the landing page was rendered before the browser wrote
 * the attribution cookie, so its ct_* dynamic-population inputs post empty.
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
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-gravity-forms-adapter.php';

	use CLICUTCL\Integrations\Forms\Gravity_Forms_Adapter;
	use PHPUnit\Framework\TestCase;

	if ( ! function_exists( 'wp_slash' ) ) {
		function wp_slash( $value ) {
			return is_string( $value ) ? addslashes( $value ) : $value;
		}
	}

	/**
	 * Class GravityFormsSubmissionRefreshTest
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class GravityFormsSubmissionRefreshTest extends TestCase {
		protected function setUp(): void {
			require_once dirname( __DIR__ ) . '/helpers/consent-mode-settings-stub.php';
			$_COOKIE = array(
				'attribution' => json_encode(
					array(
						'ft_source' => 'test62',
						'lt_source' => 'test62',
						'lt_campaign' => "o'brien",
						'fbclid'    => 'TEST_fbclid_062',
					)
				),
			);
			// What the landing-page render posted: empty ClickTrail inputs.
			$_POST = array(
				'input_1' => 'Jonas',
				'input_5' => '',
				'input_6' => '',
				'input_7' => '',
				'input_8' => 'stale-from-render',
				'input_9' => '',
			);
			$GLOBALS['clicktrail_test_consent_required']     = false;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = false;
		}

		private function form(): array {
			$field = static function ( int $id, string $name, bool $prepopulate = true ) {
				return (object) array(
					'id'                => $id,
					'type'              => 'hidden',
					'allowsPrepopulate' => $prepopulate,
					'inputName'         => $name,
				);
			};

			return array(
				'id'     => 1,
				'fields' => array(
					$field( 1, '', false ),
					$field( 5, 'ct_ft_source' ),
					$field( 6, 'ct_fbclid' ),
					$field( 7, 'ct_lt_campaign' ),
					$field( 8, 'ct_lt_source' ),
					$field( 9, 'ct_gclid' ),
				),
			);
		}

		public function test_landing_page_submission_takes_values_from_the_submission_cookie(): void {
			( new Gravity_Forms_Adapter() )->refresh_submitted_fields( $this->form() );

			$this->assertSame( 'Jonas', $_POST['input_1'] );
			$this->assertSame( 'test62', $_POST['input_5'] );
			$this->assertSame( 'TEST_fbclid_062', $_POST['input_6'] );
			$this->assertSame( "o\\'brien", $_POST['input_7'], 'Values are slashed like the rest of $_POST.' );
			$this->assertSame( 'test62', $_POST['input_8'], 'A stale rendered value is replaced by the current touch.' );
			$this->assertSame( '', $_POST['input_9'], 'A key the cookie lacks is blank.' );
		}

		public function test_visitor_a_values_with_visitor_b_cookie_lacking_two_keys_are_blanked(): void {
			$_COOKIE = array( 'attribution' => json_encode( array( 'fbclid' => 'B1' ) ) );
			$_POST   = array(
				'input_1' => 'Jonas',
				'input_5' => 'A-ft',
				'input_6' => 'A-fb',
				'input_7' => 'A-camp',
				'input_8' => 'A-lt',
				'input_9' => 'A-gclid',
			);

			( new Gravity_Forms_Adapter() )->refresh_submitted_fields( $this->form() );

			$this->assertSame( 'Jonas', $_POST['input_1'] );
			$this->assertSame( 'B1', $_POST['input_6'] );
			foreach ( array( 'input_5', 'input_7', 'input_8', 'input_9' ) as $key ) {
				$this->assertSame( '', $_POST[ $key ], "$key must not keep visitor A's value." );
			}
		}

		public function test_no_cookie_blanks_every_ct_input_even_with_a_js_marker_posted(): void {
			$_COOKIE                = array();
			$_POST['input_5']       = 'A-ft';
			$_POST['ct_fill_source'] = 'js';

			( new Gravity_Forms_Adapter() )->refresh_submitted_fields( $this->form() );

			$this->assertSame( '', $_POST['input_5'] );
			$this->assertSame( '', $_POST['input_8'], 'GF inputs are not named ct_*, so the marker never applies.' );
			$this->assertSame( 'Jonas', $_POST['input_1'] );
		}

		public function test_withdrawn_consent_blanks_every_posted_ct_input(): void {
			$GLOBALS['clicktrail_test_consent_required']     = true;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = true;
			$_COOKIE['production_consent']                   = 'denied';
			$_POST['input_5']                                = 'A-ft';

			( new Gravity_Forms_Adapter() )->refresh_submitted_fields( $this->form() );

			$this->assertSame( '', $_POST['input_5'] );
			$this->assertSame( '', $_POST['input_8'], 'The stale rendered value is blanked.' );
			$this->assertSame( 'Jonas', $_POST['input_1'] );
		}

		public function test_render_returns_gravity_forms_own_value_by_default_and_the_cookie_with_the_filter(): void {
			$adapter = new Gravity_Forms_Adapter();
			$field   = (object) array( 'formId' => 0 );

			$this->assertSame( 'gf-default', $adapter->populate_fields_dynamic( 'gf-default', $field, 'ct_ft_source' ) );

			$GLOBALS['clicktrail_test_filters']['clicutcl_render_server_attribution_values'] = static fn() => true;
			$this->assertSame( 'test62', $adapter->populate_fields_dynamic( 'gf-default', $field, 'ct_ft_source' ) );
		}
	}
}
