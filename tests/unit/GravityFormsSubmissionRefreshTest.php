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
			$this->assertSame( '', $_POST['input_9'], 'Keys missing from the cookie keep the posted value.' );
		}

		public function test_withdrawn_consent_leaves_posted_values_alone(): void {
			$GLOBALS['clicktrail_test_consent_required']     = true;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = true;
			$_COOKIE['production_consent']                   = 'denied';

			( new Gravity_Forms_Adapter() )->refresh_submitted_fields( $this->form() );

			$this->assertSame( '', $_POST['input_5'] );
			$this->assertSame( 'stale-from-render', $_POST['input_8'] );
		}
	}
}
