<?php
/**
 * Elementor Pro forms: render fill and submit-time refresh of ct_* fields (#142).
 *
 * Render: `elementor_pro/forms/render/item/hidden` sets a hidden item whose
 * `custom_id` is `ct_<key>` from the cookie. Submit: `elementor_pro/forms/process`
 * (after validation, before the actions) rewrites each `ct_<key>` record field
 * from the cookie sent with the submit via `Form_Record::update_field()`.
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
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-elementor-forms-adapter.php';

	use CLICUTCL\Integrations\Forms\Elementor_Forms_Adapter;
	use PHPUnit\Framework\TestCase;

	/**
	 * Minimal Form_Record double: `get( 'fields' )` and `update_field()` as in Elementor Pro 4.1.1.
	 */
	final class ClickTrailElementorRecordDouble {
		public $fields;
		public $updates = array();

		public function __construct( array $fields ) {
			$this->fields = $fields;
		}

		public function get( $property ) {
			return 'fields' === $property ? $this->fields : null;
		}

		public function update_field( $field_id, $property, $value ) {
			if ( ! isset( $this->fields[ $field_id ][ $property ] ) ) {
				return;
			}
			$this->updates[]                            = array( $field_id, $property, $value );
			$this->fields[ $field_id ][ $property ] = $value;
		}
	}

	/**
	 * Minimal Form widget double for the render attribute API.
	 */
	final class ClickTrailElementorFormDouble {
		public $attributes = array();

		public function set_render_attribute( $element, $key = null, $value = null ) {
			$this->attributes[ $element ][ $key ] = array( $value );
			return $this;
		}
	}

	final class ClickTrailElementorLogSpy extends Elementor_Forms_Adapter {
		public $logged = array();

		protected function log_submission( $platform, $form_id, $attribution, $identity_input = array() ) {
			$this->logged[] = array( $form_id, $attribution );
		}
	}

	/**
	 * Class ElementorFormsSubmissionRefreshTest
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class ElementorFormsSubmissionRefreshTest extends TestCase {
		protected function setUp(): void {
			require_once dirname( __DIR__ ) . '/helpers/consent-mode-settings-stub.php';
			$_COOKIE = array(
				'attribution' => json_encode(
					array(
						'gclid'     => 'new',
						'ft_source' => 'google',
					)
				),
			);
			$GLOBALS['clicktrail_test_consent_required']     = false;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = false;
			$GLOBALS['clicktrail_test_filters']              = array();
		}

		private function opt_in_to_server_render(): void {
			$GLOBALS['clicktrail_test_filters']['clicutcl_render_server_attribution_values'] = static fn() => true;
		}

		private static function field( string $id, string $value, string $type = 'hidden' ): array {
			return array(
				'id'        => $id,
				'type'      => $type,
				'title'     => $id,
				'value'     => $value,
				'raw_value' => $value,
				'required'  => false,
			);
		}

		private function record(): ClickTrailElementorRecordDouble {
			return new ClickTrailElementorRecordDouble(
				array(
					'name'         => self::field( 'name', 'Jonas', 'text' ),
					'ct_gclid'     => self::field( 'ct_gclid', 'old' ),
					'ct_ft_term'  => self::field( 'ct_ft_term', 'x' ),
					'ct_ft_source' => self::field( 'ct_ft_source', '' ),
				)
			);
		}

		public function test_render_leaves_the_item_untouched_by_default(): void {
			$form = new ClickTrailElementorFormDouble();
			$item = array( 'custom_id' => 'ct_gclid', 'field_type' => 'hidden', 'field_value' => '' );

			$this->assertSame( $item, ( new Elementor_Forms_Adapter() )->render_hidden_field( $item, 3, $form ), 'Cached HTML must not carry the requesting visitor\'s cookie (#152).' );
			$this->assertSame( array(), $form->attributes );
		}

		public function test_render_fill_sets_the_item_value_and_replaces_the_value_attribute(): void {
			$this->opt_in_to_server_render();
			$form = new ClickTrailElementorFormDouble();
			$form->set_render_attribute( 'input3', 'value', 'rendered-earlier' );
			$item = array(
				'custom_id'   => 'ct_gclid',
				'field_type'  => 'hidden',
				'field_value' => 'rendered-earlier',
			);

			$out = ( new Elementor_Forms_Adapter() )->render_hidden_field( $item, 3, $form );

			$this->assertSame( 'new', $out['field_value'] );
			$this->assertSame( array( 'new' ), $form->attributes['input3']['value'], 'Replaced, not appended.' );
		}

		public function test_render_fill_leaves_items_the_cookie_lacks_and_non_ct_items_alone(): void {
			$this->opt_in_to_server_render();
			$form    = new ClickTrailElementorFormDouble();
			$adapter = new Elementor_Forms_Adapter();
			$missing = array( 'custom_id' => 'ct_ft_term', 'field_type' => 'hidden', 'field_value' => 'x' );
			$other   = array( 'custom_id' => 'token', 'field_type' => 'hidden', 'field_value' => 't' );

			$this->assertSame( $missing, $adapter->render_hidden_field( $missing, 0, $form ) );
			$this->assertSame( $other, $adapter->render_hidden_field( $other, 1, $form ) );
			$this->assertSame( array(), $form->attributes );
		}

		public function test_process_takes_cookie_keys_and_blanks_the_ones_the_cookie_lacks(): void {
			$record = $this->record();

			( new Elementor_Forms_Adapter() )->refresh_record( $record, null );

			$this->assertSame(
				array(
					array( 'ct_gclid', 'value', 'new' ),
					array( 'ct_ft_term', 'value', '' ),
					array( 'ct_ft_source', 'value', 'google' ),
				),
				$record->updates
			);
			$this->assertSame( '', $record->fields['ct_ft_term']['value'], 'A key the cookie lacks may be another visitor\'s value (#152).' );
			$this->assertSame( 'Jonas', $record->fields['name']['value'] );
		}

		public function test_all_empty_posted_values_are_filled_per_key_and_logged(): void {
			$record = new ClickTrailElementorRecordDouble(
				array(
					'ct_gclid'  => self::field( 'ct_gclid', '' ),
					'ct_fbclid' => self::field( 'ct_fbclid', '' ),
				)
			);
			$adapter = new ClickTrailElementorLogSpy();

			$adapter->refresh_record( $record, null );
			$this->assertSame( array( array( 'ct_gclid', 'value', 'new' ), array( 'ct_fbclid', 'value', '' ) ), $record->updates );

			$adapter->on_submission( $record, null );
			$this->assertSame( array( array( 'elementor_form', array( 'gclid' => 'new' ) ) ), $adapter->logged, 'The log holds the per-key result, not the whole cookie.' );
		}

		public function test_form_without_ct_fields_logs_the_cookie_payload_and_updates_nothing(): void {
			$record  = new ClickTrailElementorRecordDouble( array( 'name' => self::field( 'name', 'Jonas', 'text' ) ) );
			$adapter = new ClickTrailElementorLogSpy();

			$adapter->refresh_record( $record, null );
			$this->assertSame( array(), $record->updates );

			$adapter->on_submission( $record, null );
			$this->assertSame( array( array( 'elementor_form', array( 'gclid' => 'new', 'ft_source' => 'google' ) ) ), $adapter->logged );
		}

		public function test_denied_consent_blanks_ct_fields_at_process_and_leaves_render_alone(): void {
			$GLOBALS['clicktrail_test_consent_required']     = true;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = true;
			$_COOKIE['production_consent']                   = 'denied';
			$adapter                                         = new Elementor_Forms_Adapter();
			$form                                            = new ClickTrailElementorFormDouble();
			$item                                            = array( 'custom_id' => 'ct_gclid', 'field_type' => 'hidden', 'field_value' => '' );
			$record                                          = $this->record();

			$this->assertSame( $item, $adapter->render_hidden_field( $item, 0, $form ) );
			$this->assertSame( array(), $form->attributes );

			$adapter->refresh_record( $record, null );
			foreach ( array( 'ct_gclid', 'ct_ft_term', 'ct_ft_source' ) as $id ) {
				$this->assertSame( '', $record->fields[ $id ]['value'], "$id is blanked before the form actions run." );
			}
			$this->assertSame( 'Jonas', $record->fields['name']['value'] );
		}

		public function test_an_unmapped_ct_field_is_never_touched(): void {
			$this->in_each_state(
				function ( string $state ): void {
					$record = new ClickTrailElementorRecordDouble(
						array(
							'ct_company' => self::field( 'ct_company', 'Acme', 'text' ),
							'ct_gclid'   => self::field( 'ct_gclid', 'A1' ),
						)
					);
					( new Elementor_Forms_Adapter() )->refresh_record( $record, null );
					$this->assertSame( 'Acme', $record->fields['ct_company']['value'], "ct_company is the visitor's own field ($state)." );
				}
			);
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
