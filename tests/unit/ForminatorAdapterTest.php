<?php
/**
 * Forminator: render, submit-time refresh and entry logging of ClickTrail-marked Hidden fields (#170).
 *
 * Forminator names a Hidden input after its field ID (`hidden-1`), so a Hidden field is ClickTrail's when its
 * custom CSS class has a `ct_<key>` token, its label is exactly `ct_<key>`, or its default value is
 * "Custom value" set to exactly `ct_<key>`. Render goes through `forminator_field_hidden_field_value`,
 * submit through `forminator_prepared_data`, logging through `forminator_custom_form_submit_before_set_fields`.
 * Hook names and signatures were read from Forminator 1.58.4.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

namespace CLICUTCL\Server_Side {
	if ( ! class_exists( Dispatcher::class, false ) ) {
		/**
		 * Counting Dispatcher double; the real one needs the full server-side stack.
		 */
		class Dispatcher {
			/**
			 * Record a form submission dispatch.
			 *
			 * @param string $platform    Platform.
			 * @param mixed  $form_id     Form ID.
			 * @param array  $attribution Attribution.
			 * @param array  $context     Context.
			 * @return void
			 */
			public static function dispatch_form_submission( $platform, $form_id, $attribution, $context = array() ) {
				$GLOBALS['clicktrail_test_dispatches'][] = array( $platform, $form_id, $attribution, $context );
			}
		}
	}
}

namespace {
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-decision-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-resolver-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-snapshot-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-consent.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-dedup-store.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Core/class-attribution-provider.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/interface-form-adapter.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-claim-lock.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-abstract-form-adapter.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-forminator-adapter.php';

	use CLICUTCL\Integrations\Forms\Forminator_Adapter;
	use PHPUnit\Framework\TestCase;

	/**
	 * Forminator_Form_Field_Model double: only `to_formatted_array()`.
	 */
	final class ClickTrailForminatorField {
		private $settings;

		public function __construct( array $settings ) {
			$this->settings = $settings;
		}

		public function to_formatted_array() {
			return $this->settings;
		}
	}

	/**
	 * Forminator_Form_Model double: only `get_fields()`.
	 */
	final class ClickTrailForminatorModule {
		private $fields;

		public function __construct( array $fields ) {
			$this->fields = $fields;
		}

		public function get_fields() {
			return $this->fields;
		}
	}

	if ( ! function_exists( 'forminator_replace_variables' ) ) {
		/**
		 * Forminator's variable replacement, reduced to two variables.
		 *
		 * @param string $content Content.
		 * @return string
		 */
		function forminator_replace_variables( $content, $id = false, $entry = null ) {
			return str_replace( array( '{user_ip}', '{site_url}' ), array( '203.0.113.9', 'https://example.test' ), (string) $content );
		}
	}

	/**
	 * Forminator_Form_Entry_Model double: records `set_fields()` rows, answers `get_meta()` from them.
	 *
	 * Like the real model, `set_fields()` on an entry that is being updated (`updating_entry`, an abandoned entry
	 * completed by the submit) first deletes every meta row of the entry.
	 */
	final class ClickTrailForminatorEntry {
		public $entry_id       = 0;
		public $status         = 'active';
		public $updating_entry = false;
		public $rows           = array();
		public $writes         = 0;

		public function __construct( int $entry_id = 0, string $status = 'active' ) {
			$this->entry_id = $entry_id;
			$this->status   = $status;
		}

		public function set_fields( $meta_array, $entry_date = '' ) {
			if ( $this->updating_entry ) {
				$this->rows = array();
			}
			foreach ( $meta_array as $meta ) {
				$this->rows[ $meta['name'] ] = $meta['value'];
				++$this->writes;
			}
			return $this->entry_id > 0;
		}

		public function get_meta( $meta_key, $default_value = false, $db = false ) {
			return array_key_exists( $meta_key, $this->rows ) ? $this->rows[ $meta_key ] : $default_value;
		}
	}

	/**
	 * Records `$wpdb->insert()` calls.
	 */
	final class ClickTrailForminatorWpdb {
		public $prefix  = 'wp_';
		public $options = 'wp_options';
		public $rows    = array();

		public function insert( $table, $data ) {
			$this->rows[] = array( 'table' => $table, 'data' => $data );
			return 1;
		}

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
	 * The adapter with the identity lookup stubbed (it needs the full request stack).
	 */
	final class ClickTrailForminatorTestAdapter extends Forminator_Adapter {
		protected function resolve_identity_payload( $identity_input = array() ) {
			return array();
		}
	}

	/**
	 * Class ForminatorAdapterTest
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class ForminatorAdapterTest extends TestCase {
		/**
		 * Row recorder.
		 *
		 * @var ClickTrailForminatorWpdb
		 */
		private $db;

		protected function setUp(): void {
			// Namespaced add_action()/add_filter() recorders; other test files declare the same functions, so load them only in this separate process.
			require_once dirname( __DIR__ ) . '/helpers/hook-recorder-forms-namespace.php';
			require_once dirname( __DIR__ ) . '/helpers/consent-mode-settings-stub.php';
			$_COOKIE = array(
				'attribution' => json_encode(
					array(
						'gclid'     => 'abc',
						'ft_source' => 'google',
					)
				),
			);
			$_POST                                           = array();
			$GLOBALS['clicktrail_test_consent_required']     = false;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = false;
			$GLOBALS['clicktrail_test_filters']              = array();
			$GLOBALS['clicktrail_test_transients']           = array();
			$GLOBALS['clicktrail_test_dispatches']           = array();
			$GLOBALS['clicktrail_test_actions']              = array();
			$GLOBALS['ct_forms_registered_hooks']            = array();
			$this->db                                        = new ClickTrailForminatorWpdb();
			$GLOBALS['wpdb']                                 = $this->db;
		}

		protected function tearDown(): void {
			$GLOBALS['clicktrail_test_consent_required']     = false;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = false;
		}

		private function opt_in_to_server_render(): void {
			$GLOBALS['clicktrail_test_filters']['clicutcl_render_server_attribution_values'] = static fn() => true;
		}

		private function block_consent(): void {
			$GLOBALS['clicktrail_test_consent_required']     = true;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = true;
			$_COOKIE['production_consent']                   = 'denied';
		}

		private static function hidden( string $id, array $extra = array() ): array {
			return array_merge(
				array(
					'element_id'    => $id,
					'type'          => 'hidden',
					'field_label'   => 'Hidden',
					'default_value' => 'user_ip',
				),
				$extra
			);
		}

		/**
		 * A form: a text field, one Hidden field per marker style, an unmapped ct_ field and an unmarked one.
		 */
		private static function module(): ClickTrailForminatorModule {
			return new ClickTrailForminatorModule(
				array(
					new ClickTrailForminatorField( array( 'element_id' => 'text-1', 'type' => 'text', 'field_label' => 'ct_gclid', 'custom-class' => 'ct_gclid' ) ),
					new ClickTrailForminatorField( self::hidden( 'hidden-1', array( 'custom-class' => 'forminator-tracking ct_gclid' ) ) ),
					new ClickTrailForminatorField( self::hidden( 'hidden-2', array( 'field_label' => 'ct_lt_term' ) ) ),
					new ClickTrailForminatorField( self::hidden( 'hidden-3', array( 'default_value' => 'custom_value', 'custom_value' => 'ct_ft_source' ) ) ),
					new ClickTrailForminatorField( self::hidden( 'hidden-4', array( 'custom-class' => 'ct_company', 'field_label' => 'ct_company' ) ) ),
					new ClickTrailForminatorField( self::hidden( 'hidden-5', array( 'field_label' => 'Visitor IP' ) ) ),
					new ClickTrailForminatorField( self::hidden( 'hidden-6', array( 'field_label' => 'ct_gclid note', 'custom-class' => 'ct_utm_term_typo' ) ) ),
				)
			);
		}

		private static function posted(): array {
			return array(
				'text-1'   => 'Jonas',
				'hidden-1' => 'stale-from-render',
				'hidden-2' => 'x',
				'hidden-3' => 'ct_ft_source',
				'hidden-4' => 'Acme',
				'hidden-5' => '203.0.113.9',
				'hidden-6' => 'note',
			);
		}

		public function test_render_leaves_every_marked_field_empty_by_default(): void {
			$adapter = new Forminator_Adapter();

			$this->assertSame( '', $adapter->render_field_value( '203.0.113.9', 'user_ip', self::hidden( 'hidden-1', array( 'custom-class' => 'ct_gclid' ) ) ), 'Cached HTML must not carry the requesting visitor\'s cookie (#152).' );
			$this->assertSame( '', $adapter->render_field_value( 'Visitor IP', 'custom_value', self::hidden( 'hidden-3', array( 'default_value' => 'custom_value', 'custom_value' => 'ct_gclid' ) ) ), 'A Custom value marker is not rendered as the literal ct_ name.' );
			$this->assertSame( '', $adapter->render_field_value( 'x', 'user_ip', self::hidden( 'hidden-2', array( 'field_label' => 'ct_lt_term' ) ) ) );
		}

		public function test_render_fills_a_marked_field_from_the_cookie_only_when_the_filter_opts_in(): void {
			$this->opt_in_to_server_render();
			$adapter = new Forminator_Adapter();

			$this->assertSame( 'abc', $adapter->render_field_value( '', 'user_ip', self::hidden( 'hidden-1', array( 'custom-class' => 'ct_gclid' ) ) ) );
			$this->assertSame( 'google', $adapter->render_field_value( '', 'user_ip', self::hidden( 'hidden-2', array( 'field_label' => 'ct_ft_source' ) ) ) );
			$this->assertSame( '', $adapter->render_field_value( 'old', 'user_ip', self::hidden( 'hidden-3', array( 'custom-class' => 'ct_lt_term' ) ) ), 'A key the cookie lacks is blank.' );
		}

		public function test_render_with_the_filter_on_still_blanks_when_consent_is_blocked_or_there_is_no_cookie(): void {
			$this->opt_in_to_server_render();
			$field   = self::hidden( 'hidden-1', array( 'custom-class' => 'ct_gclid' ) );
			$adapter = new Forminator_Adapter();

			$_COOKIE = array();
			$this->assertSame( '', $adapter->render_field_value( 'old', 'user_ip', $field ) );

			$_COOKIE = array( 'attribution' => json_encode( array( 'gclid' => 'abc' ) ) );
			$this->block_consent();
			$this->assertSame( '', $adapter->render_field_value( 'old', 'user_ip', $field ) );
		}

		public function test_render_leaves_unmarked_and_unmapped_fields_alone(): void {
			$this->opt_in_to_server_render();
			$adapter = new Forminator_Adapter();

			$this->assertSame( '203.0.113.9', $adapter->render_field_value( '203.0.113.9', 'user_ip', self::hidden( 'hidden-5', array( 'field_label' => 'Visitor IP' ) ) ) );
			$this->assertSame( 'Acme', $adapter->render_field_value( 'Acme', 'user_ip', self::hidden( 'hidden-4', array( 'custom-class' => 'ct_company', 'field_label' => 'ct_company' ) ) ), 'Only keys from get_field_mapping() count.' );
			$this->assertSame( 'note', $adapter->render_field_value( 'note', 'user_ip', self::hidden( 'hidden-6', array( 'field_label' => 'ct_gclid note' ) ) ), 'A label that merely starts with a key is not a marker.' );
			$this->assertSame( 'keep', $adapter->render_field_value( 'keep', 'custom_value', self::hidden( 'hidden-7', array( 'default_value' => 'user_ip', 'custom_value' => 'ct_gclid' ) ) ), 'A stale custom value does not mark a field whose default is something else.' );
			$this->assertSame( 'keep', $adapter->render_field_value( 'keep', '', array() ) );
		}

		public function test_submit_takes_cookie_keys_and_blanks_marked_fields_the_cookie_lacks(): void {
			$out = ( new Forminator_Adapter() )->refresh_prepared_data( self::posted(), self::module() );

			$this->assertSame( 'abc', $out['hidden-1'], 'CSS-marked field: the cookie replaces the posted value.' );
			$this->assertSame( '', $out['hidden-2'], 'A key the cookie lacks is blank (#152).' );
			$this->assertSame( 'google', $out['hidden-3'], 'Custom-value-marked field: filled from the cookie, not left as the literal ct_ name.' );
			$this->assertSame( 'Jonas', $out['text-1'] );
			$this->assertSame( 'Acme', $out['hidden-4'], 'An unmapped ct_ field is never touched.' );
			$this->assertSame( '203.0.113.9', $out['hidden-5'] );
			$this->assertSame( 'note', $out['hidden-6'] );
		}

		public function test_submit_adds_a_marked_field_the_request_did_not_carry(): void {
			$out = ( new Forminator_Adapter() )->refresh_prepared_data( array( 'text-1' => 'Jonas' ), self::module() );

			$this->assertSame( 'abc', $out['hidden-1'], 'An empty field Forminator would drop is filled so it reaches the entry.' );
			$this->assertArrayNotHasKey( 'hidden-4', $out );
		}

		public function test_visitor_a_values_with_visitor_b_cookie_lacking_keys_are_blanked_and_no_cookie_blanks_all(): void {
			$_COOKIE = array( 'attribution' => json_encode( array( 'gclid' => 'B1' ) ) );
			$out     = ( new Forminator_Adapter() )->refresh_prepared_data( self::posted(), self::module() );
			$this->assertSame( 'B1', $out['hidden-1'] );
			$this->assertSame( '', $out['hidden-2'] );
			$this->assertSame( '', $out['hidden-3'] );

			$_COOKIE = array();
			$out     = ( new Forminator_Adapter() )->refresh_prepared_data( self::posted(), self::module() );
			foreach ( array( 'hidden-1', 'hidden-2', 'hidden-3' ) as $id ) {
				$this->assertSame( '', $out[ $id ], "$id is blank without a cookie: Forminator inputs carry no Injector marker." );
			}
			$this->assertSame( 'Jonas', $out['text-1'] );
		}

		public function test_denied_consent_blanks_every_marked_field_before_forminator_stores_or_mails_it(): void {
			$this->block_consent();
			$out = ( new Forminator_Adapter() )->refresh_prepared_data( self::posted(), self::module() );

			foreach ( array( 'hidden-1', 'hidden-2', 'hidden-3' ) as $id ) {
				$this->assertSame( '', $out[ $id ] );
			}
			$this->assertSame( 'Jonas', $out['text-1'] );
			$this->assertSame( 'Acme', $out['hidden-4'] );
		}

		public function test_the_posted_fill_marker_does_not_keep_a_value_without_a_cookie(): void {
			$_COOKIE = array();
			$posted  = self::posted() + array( 'ct_fill_source' => 'js' );
			$out     = ( new Forminator_Adapter() )->refresh_prepared_data( $posted, self::module() );

			$this->assertSame( '', $out['hidden-1'], 'Forminator ignores undeclared posted fields; the marker cannot vouch for a Hidden field.' );
		}

		public function test_refresh_passes_through_anything_it_cannot_read(): void {
			$adapter = new Forminator_Adapter();

			$this->assertSame( 'x', $adapter->refresh_prepared_data( 'x', self::module() ) );
			$this->assertSame( self::posted(), $adapter->refresh_prepared_data( self::posted(), null ) );
			$this->assertSame( self::posted(), $adapter->refresh_prepared_data( self::posted(), new ClickTrailForminatorModule( array() ) ) );
		}

		public function test_refresh_is_idempotent(): void {
			$adapter = new Forminator_Adapter();
			$once    = $adapter->refresh_prepared_data( self::posted(), self::module() );

			$this->assertSame( $once, $adapter->refresh_prepared_data( $once, self::module() ) );
		}

		public function test_entry_fields_of_a_marked_field_stop_inheriting_forminators_default_value(): void {
			$item   = static function ( string $name, array $settings ): array {
				return array( 'name' => $name, 'value' => 'v', 'field_type' => $settings['type'], 'field_array' => $settings );
			};
			$custom = self::hidden( 'hidden-1', array( 'default_value' => 'custom_value', 'custom_value' => 'static', 'custom-class' => 'ct_gclid' ) );
			$embed  = self::hidden( 'hidden-2', array( 'default_value' => 'embed_url', 'field_label' => 'ct_lt_source' ) );
			$own    = self::hidden( 'hidden-3', array( 'default_value' => 'user_ip', 'custom-class' => 'ct_fbclid' ) );
			$plain  = self::hidden( 'hidden-4', array( 'default_value' => 'custom_value', 'custom_value' => 'static' ) );
			$text   = array( 'element_id' => 'text-1', 'type' => 'text', 'field_label' => 'ct_gclid', 'default_value' => 'custom_value' );
			$data   = array( $item( 'hidden-1', $custom ), $item( 'hidden-2', $embed ), $item( 'hidden-3', $own ), $item( 'hidden-4', $plain ), $item( 'text-1', $text ), array( 'name' => '_forminator_user_ip', 'value' => '1.2.3.4' ) );

			$out = ( new Forminator_Adapter() )->protect_entry_fields( $data, 7 );

			$this->assertSame( 'query', $out[0]['field_array']['default_value'], 'Custom value would overwrite the refreshed value after this filter.' );
			$this->assertSame( 'query', $out[1]['field_array']['default_value'], 'Embed URL would overwrite the refreshed value after this filter.' );
			$this->assertSame( 'user_ip', $out[2]['field_array']['default_value'], 'Other defaults keep the submitted value already.' );
			$this->assertSame( 'custom_value', $out[3]['field_array']['default_value'], 'An unmarked Hidden field is Forminator\'s.' );
			$this->assertSame( 'custom_value', $out[4]['field_array']['default_value'], 'Only Hidden fields.' );
			$this->assertSame( 'v', $out[0]['value'], 'The value is not touched here.' );
			$this->assertSame( $data[5], $out[5] );
			$this->assertSame( 'x', ( new Forminator_Adapter() )->protect_entry_fields( 'x', 7 ) );
		}

		public function test_registered_hooks_are_the_current_forminator_names(): void {
			( new Forminator_Adapter() )->register_hooks();

			$this->assertSame(
				array(
					'forminator_field_hidden_field_value',
					'forminator_prepared_data',
					'forminator_custom_form_submit_field_data',
					'forminator_custom_form_submit_before_set_fields',
					'forminator_after_handle_form',
				),
				$GLOBALS['ct_forms_registered_hooks']
			);
		}

		public function test_the_adapter_is_inert_until_forminator_is_active(): void {
			$adapter = new Forminator_Adapter();
			$this->assertFalse( $adapter->is_active() );

			define( 'FORMINATOR_VERSION', '1.58.4' );
			$this->assertTrue( $adapter->is_active() );
		}

		public function test_a_submission_is_logged_with_the_entry_id_and_the_ids_are_written_to_the_entry(): void {
			$adapter = new ClickTrailForminatorTestAdapter();
			$entry   = new ClickTrailForminatorEntry( 88 );
			$data    = array( array( 'name' => 'email-1', 'value' => 'j@example.com' ) );

			$adapter->on_submission( $entry, 7, $data );

			$this->assertCount( 1, $this->db->rows );
			$logged = json_decode( $this->db->rows[0]['data']['event_data'], true );
			$this->assertSame( 'forminator', $logged['platform'] );
			$this->assertSame( 7, $logged['form_id'] );
			$this->assertSame( '88', (string) $logged['entry_id'] );
			$this->assertSame( array( 'gclid' => 'abc', 'ft_source' => 'google' ), $logged['attribution'] );
			$this->assertSame( 'abc', $entry->rows['ct_gclid'] );
			$this->assertSame( 'google', $entry->rows['ct_ft_source'] );
			$this->assertSame( $logged['event_id'], $entry->rows['ct_event_id'] );
			$this->assertSame( $logged['lead_id'], $entry->rows['ct_lead_id'] );
			$this->assertCount( 1, $GLOBALS['clicktrail_test_dispatches'] );
		}

		public function test_an_abandoned_entry_being_completed_keeps_the_meta_after_forminator_stores_its_fields(): void {
			$adapter                = new ClickTrailForminatorTestAdapter();
			$entry                  = new ClickTrailForminatorEntry( 88 );
			$entry->updating_entry  = true;

			$adapter->on_submission( $entry, 7, array() );
			$this->assertCount( 1, $this->db->rows, 'The lead is logged at once.' );
			$this->assertSame( array(), $entry->rows, 'No set_fields() while Forminator is about to delete the entry meta.' );

			// Forminator's own set_fields() right after the action: deletes every row of the entry, then stores the fields.
			$entry->set_fields( array( array( 'name' => 'text-1', 'value' => 'Jonas' ) ) );
			$this->assertSame( array( 'text-1' => 'Jonas' ), $entry->rows );

			$adapter->flush_deferred_meta( $entry );

			$logged = json_decode( $this->db->rows[0]['data']['event_data'], true );
			$this->assertSame( 'Jonas', $entry->rows['text-1'], 'The write adds rows; it does not delete Forminator\'s.' );
			$this->assertSame( 'abc', $entry->rows['ct_gclid'] );
			$this->assertSame( $logged['event_id'], $entry->rows['ct_event_id'] );
			$this->assertSame( $logged['lead_id'], $entry->rows['ct_lead_id'] );
			$this->assertTrue( $entry->updating_entry, 'The model flag is restored.' );

			$writes = $entry->writes;
			$adapter->flush_deferred_meta( $entry );
			$this->assertSame( $writes, $entry->writes, 'A second flush has nothing left to write.' );
		}

		public function test_a_repeat_for_an_entry_being_updated_still_writes_once_and_logs_once(): void {
			$adapter               = new ClickTrailForminatorTestAdapter();
			$entry                 = new ClickTrailForminatorEntry( 88 );
			$entry->updating_entry = true;

			$adapter->on_submission( $entry, 7, array() );
			$adapter->on_submission( $entry, 7, array() );
			$entry->set_fields( array( array( 'name' => 'text-1', 'value' => 'Jonas' ) ) );
			$adapter->flush_deferred_meta( $entry );

			$this->assertCount( 1, $this->db->rows );
			$this->assertSame( 'abc', $entry->rows['ct_gclid'], 'The first call (the one that logged) decides what is written, not the duplicate.' );
			$this->assertArrayHasKey( 'ct_event_id', $entry->rows );
		}

		public function test_a_value_forminator_would_rewrite_is_left_out_of_the_field_not_stored_changed(): void {
			$_COOKIE = array( 'attribution' => json_encode( array( 'gclid' => 'user_ip', 'ft_source' => '{keyword}', 'lt_term' => 'plain' ) ) );

			$out = ( new Forminator_Adapter() )->refresh_prepared_data( self::posted(), self::module() );

			$this->assertSame( '', $out['hidden-1'], 'A bare variable name would be stored as the visitor IP.' );
			$this->assertSame( '', $out['hidden-3'], 'Braces at either end would be stripped.' );
			$this->assertSame( 'plain', $out['hidden-2'], 'An ordinary value is untouched.' );
		}

		public function test_a_repeat_of_the_same_entry_logs_and_writes_once(): void {
			$adapter = new ClickTrailForminatorTestAdapter();
			$entry   = new ClickTrailForminatorEntry( 88 );

			$adapter->on_submission( $entry, 7, array() );
			$writes = $entry->writes;
			$adapter->on_submission( $entry, 7, array() );

			$this->assertCount( 1, $this->db->rows );
			$this->assertSame( $writes, $entry->writes );
		}

		public function test_a_repeat_writes_only_an_id_the_first_call_died_before_writing(): void {
			$adapter = new ClickTrailForminatorTestAdapter();
			$entry   = new ClickTrailForminatorEntry( 88 );

			$adapter->on_submission( $entry, 7, array() );
			unset( $entry->rows['ct_lead_id'] );
			$adapter->on_submission( $entry, 7, array() );

			$this->assertArrayHasKey( 'ct_lead_id', $entry->rows );
			$this->assertCount( 1, $this->db->rows );
		}

		public function test_drafts_abandoned_forms_and_spam_are_not_submissions(): void {
			$adapter = new ClickTrailForminatorTestAdapter();
			foreach ( array( 'draft', 'abandoned', 'spam' ) as $status ) {
				$entry = new ClickTrailForminatorEntry( 90, $status );
				$adapter->on_submission( $entry, 7, array() );
				$this->assertSame( array(), $entry->rows, $status );
			}
			$this->assertSame( array(), $this->db->rows );

			$adapter->on_submission( new ClickTrailForminatorEntry( 91, 'pending' ), 7, array() );
			$this->assertCount( 1, $this->db->rows, 'A pending (payment) entry is a submission.' );
		}

		public function test_denied_consent_or_no_cookie_logs_and_writes_nothing(): void {
			$adapter = new ClickTrailForminatorTestAdapter();

			$entry = new ClickTrailForminatorEntry( 88 );
			$_COOKIE = array();
			$adapter->on_submission( $entry, 7, array() );
			$this->assertSame( array(), $entry->rows );

			$_COOKIE = array( 'attribution' => json_encode( array( 'gclid' => 'abc' ) ) );
			$this->block_consent();
			$adapter->on_submission( $entry, 7, array() );

			$this->assertSame( array(), $entry->rows );
			$this->assertSame( array(), $this->db->rows );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_dispatches'] );
		}

		public function test_without_a_stored_entry_the_submission_is_still_logged_but_no_meta_is_written(): void {
			$adapter = new ClickTrailForminatorTestAdapter();
			$entry   = new ClickTrailForminatorEntry( 0 );

			$adapter->on_submission( $entry, 7, array() );

			$this->assertCount( 1, $this->db->rows );
			$logged = json_decode( $this->db->rows[0]['data']['event_data'], true );
			$this->assertArrayNotHasKey( 'entry_id', $logged );
			$this->assertSame( 0, $entry->writes );
		}

		public function test_a_non_entry_argument_is_ignored(): void {
			( new ClickTrailForminatorTestAdapter() )->on_submission( null, 7, array() );

			$this->assertSame( array(), $this->db->rows );
		}
	}
}
