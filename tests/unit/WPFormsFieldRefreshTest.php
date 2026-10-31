<?php
/**
 * WPForms: render fill and submit-time refresh of ct_* hidden fields (#142, #151).
 *
 * WPForms never fires `wpforms_field_value_*`, so the old registrations left every
 * hidden field empty. A field is a ClickTrail field when its CSS class list has a
 * `ct_<key>` token or its label is exactly `ct_<key>`. Render fills through
 * `wpforms_field_properties`; submit refreshes through `wpforms_process_filter`.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

namespace CLICUTCL\Integrations\Forms {
	// Other adapter tests stub this too; in the isolated child process only this file is loaded.
	if ( ! function_exists( __NAMESPACE__ . '\add_filter' ) ) {
		function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
			$GLOBALS['ct_wpforms_filters'][] = $tag;
			return true;
		}
	}
}

namespace {
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-decision-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-resolver-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-snapshot-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-consent.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Core/class-attribution-provider.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/interface-form-adapter.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-abstract-form-adapter.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-wpforms-adapter.php';

	use CLICUTCL\Integrations\Forms\WPForms_Adapter;
	use PHPUnit\Framework\TestCase;

	/**
	 * Class WPFormsFieldRefreshTest
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class WPFormsFieldRefreshTest extends TestCase {
		protected function setUp(): void {
			require_once dirname( __DIR__ ) . '/helpers/consent-mode-settings-stub.php';
			$_COOKIE = array(
				'attribution' => json_encode(
					array(
						'gclid'     => 'abc',
						'ft_source' => 'google',
					)
				),
			);
			$GLOBALS['clicktrail_test_consent_required']     = false;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = false;
			$GLOBALS['ct_wpforms_filters']                   = array();
		}

		private static function properties( string $value = '' ): array {
			return array(
				'inputs' => array(
					'primary' => array(
						'attr' => array(
							'name'  => 'wpforms[fields][3]',
							'value' => $value,
						),
					),
				),
			);
		}

		private static function form_data(): array {
			return array(
				'id'     => 9,
				'fields' => array(
					1 => array( 'id' => 1, 'type' => 'text', 'label' => 'Name', 'css' => '' ),
					3 => array( 'id' => 3, 'type' => 'hidden', 'label' => 'Google click', 'css' => 'wpf-tracking ct_gclid' ),
					4 => array( 'id' => 4, 'type' => 'hidden', 'label' => 'ct_lt_term', 'css' => '' ),
					5 => array( 'id' => 5, 'type' => 'hidden', 'label' => 'ct_ft_source', 'css' => '' ),
				),
			);
		}

		private static function fields(): array {
			return array(
				1 => array( 'name' => 'Name', 'value' => 'Jonas', 'id' => 1, 'type' => 'text' ),
				3 => array( 'name' => 'Google click', 'value' => 'old', 'id' => 3, 'type' => 'hidden' ),
				4 => array( 'name' => 'ct_lt_term', 'value' => 'x', 'id' => 4, 'type' => 'hidden' ),
				5 => array( 'name' => 'ct_ft_source', 'value' => '', 'id' => 5, 'type' => 'hidden' ),
			);
		}

		public function test_render_fills_a_hidden_field_marked_by_css_class(): void {
			$field = array( 'id' => 3, 'type' => 'hidden', 'label' => 'Google click', 'css' => 'wpf-tracking ct_gclid' );

			$out = ( new WPForms_Adapter() )->render_field_default( self::properties(), $field, self::form_data() );

			$this->assertSame( 'abc', $out['inputs']['primary']['attr']['value'] );
		}

		public function test_render_fills_a_hidden_field_marked_by_label_only(): void {
			$field = array( 'id' => 5, 'type' => 'hidden', 'label' => 'ct_ft_source' );

			$out = ( new WPForms_Adapter() )->render_field_default( self::properties(), $field, self::form_data() );

			$this->assertSame( 'google', $out['inputs']['primary']['attr']['value'], 'Label-marked fields keep working.' );
		}

		public function test_render_leaves_unmarked_and_cookie_missing_fields_alone(): void {
			$adapter = new WPForms_Adapter();
			$plain   = array( 'id' => 1, 'type' => 'hidden', 'label' => 'Token', 'css' => 'nothing' );
			$missing = array( 'id' => 4, 'type' => 'hidden', 'label' => 'ct_lt_term', 'css' => '' );
			$text    = array( 'id' => 2, 'type' => 'text', 'label' => 'ct_gclid', 'css' => '' );

			$this->assertSame( self::properties( 'keep' ), $adapter->render_field_default( self::properties( 'keep' ), $plain, self::form_data() ) );
			$this->assertSame( self::properties( 'keep' ), $adapter->render_field_default( self::properties( 'keep' ), $missing, self::form_data() ) );
			$this->assertSame( self::properties( 'keep' ), $adapter->render_field_default( self::properties( 'keep' ), $text, self::form_data() ), 'Only hidden fields are filled.' );
		}

		public function test_a_label_that_merely_starts_with_a_ct_key_is_not_a_marker(): void {
			$adapter = new WPForms_Adapter();
			$note    = array( 'id' => 6, 'type' => 'hidden', 'label' => 'ct_gclid note', 'css' => '' );
			$unknown = array( 'id' => 7, 'type' => 'hidden', 'label' => 'ct_utm_term', 'css' => '' );
			$fields  = array( 6 => array( 'name' => 'ct_gclid note', 'value' => 'old', 'id' => 6, 'type' => 'hidden' ) );

			$this->assertSame( self::properties( 'keep' ), $adapter->render_field_default( self::properties( 'keep' ), $note, self::form_data() ) );
			$this->assertSame( self::properties( 'keep' ), $adapter->render_field_default( self::properties( 'keep' ), $unknown, self::form_data() ), 'Only keys from get_field_mapping() count.' );
			$this->assertSame( $fields, $adapter->refresh_fields( $fields, array(), array( 'id' => 9, 'fields' => array( 6 => $note ) ) ) );
		}

		public function test_submit_refreshes_marked_fields_from_the_cookie_per_key(): void {
			$out = ( new WPForms_Adapter() )->refresh_fields( self::fields(), array(), self::form_data() );

			$this->assertSame( 'Jonas', $out[1]['value'] );
			$this->assertSame( 'abc', $out[3]['value'], 'CSS-marked field: a non-empty cookie key replaces the posted value.' );
			$this->assertSame( 'x', $out[4]['value'], 'Keys missing from the cookie keep the posted value.' );
			$this->assertSame( 'google', $out[5]['value'], 'Label-marked field: an empty posted value is filled.' );
		}

		public function test_submit_falls_back_to_the_processed_field_name_when_form_data_lacks_the_field(): void {
			$out = ( new WPForms_Adapter() )->refresh_fields( self::fields(), array(), array( 'id' => 9 ) );

			$this->assertSame( 'old', $out[3]['value'], 'A CSS-only marker is unknown without form_data.' );
			$this->assertSame( 'google', $out[5]['value'], 'The processed field name is the label.' );
		}

		public function test_denied_consent_returns_both_inputs_unchanged(): void {
			$GLOBALS['clicktrail_test_consent_required']     = true;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = true;
			$_COOKIE['production_consent']                   = 'denied';
			$adapter                                         = new WPForms_Adapter();
			$field                                           = array( 'id' => 3, 'type' => 'hidden', 'label' => 'Google click', 'css' => 'ct_gclid' );

			$this->assertSame( self::properties(), $adapter->render_field_default( self::properties(), $field, self::form_data() ) );
			$this->assertSame( self::fields(), $adapter->refresh_fields( self::fields(), array(), self::form_data() ) );
		}

		public function test_no_wpforms_field_value_filter_is_registered(): void {
			( new WPForms_Adapter() )->register_hooks();

			$this->assertSame( array(), preg_grep( '/^wpforms_field_value_/', $GLOBALS['ct_wpforms_filters'] ) );
			$this->assertContains( 'wpforms_field_properties', $GLOBALS['ct_wpforms_filters'] );
			$this->assertContains( 'wpforms_process_filter', $GLOBALS['ct_wpforms_filters'] );
		}
	}
}
