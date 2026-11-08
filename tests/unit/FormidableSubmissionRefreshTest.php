<?php
/**
 * Formidable Forms: render fill, submit-time refresh and entry log of ct_* hidden fields (#169).
 *
 * A hidden field is ClickTrail's when its field key is `ct_<key>` (Formidable appends a number to keep
 * keys unique) or its label is exactly `ct_<key>`, for a key in `Attribution_Provider::get_field_mapping()`.
 * `frm_pre_create_entry` refreshes `item_meta` before the entry is stored; `frm_after_create_entry`
 * logs the stored entry and writes the IDs as entry meta.
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
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-formidable-adapter.php';

	use CLICUTCL\Core\Attribution_Provider;
	use CLICUTCL\Integrations\Forms\Formidable_Adapter;
	use PHPUnit\Framework\TestCase;

	if ( ! function_exists( 'wp_slash' ) ) {
		function wp_slash( $value ) {
			return is_string( $value ) ? addslashes( $value ) : $value;
		}
	}

	if ( ! function_exists( 'is_admin' ) ) {
		function is_admin() {
			return ! empty( $GLOBALS['clicktrail_test_is_admin'] );
		}
	}

	if ( ! function_exists( 'wp_doing_ajax' ) ) {
		function wp_doing_ajax() {
			return ! empty( $GLOBALS['clicktrail_test_doing_ajax'] );
		}
	}

	if ( ! function_exists( 'maybe_unserialize' ) ) {
		function maybe_unserialize( $value ) {
			return is_string( $value ) && preg_match( '/^[aOsib]:/', $value ) ? unserialize( $value ) : $value; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		}
	}

	/**
	 * Records `$wpdb->insert()` calls and supports the claim-lock delete.
	 */
	final class ClickTrailFormidableWpdb {
		use \Claim_Options_Wpdb;

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
	 * Adapter that records the identity input instead of resolving it (the resolver needs the full stack).
	 */
	final class ClickTrailFormidableAdapter extends Formidable_Adapter {
		public $identity_inputs = array();

		protected function resolve_identity_payload( $identity_input = array() ) {
			$this->identity_inputs[] = $identity_input;
			return array();
		}
	}

	/**
	 * Class FormidableSubmissionRefreshTest
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class FormidableSubmissionRefreshTest extends TestCase {
		/**
		 * Row recorder.
		 *
		 * @var ClickTrailFormidableWpdb
		 */
		private $db;

		protected function setUp(): void {
			require_once dirname( __DIR__ ) . '/helpers/consent-mode-settings-stub.php';
			$_COOKIE = array(
				'attribution' => json_encode(
					array(
						'gclid'      => 'abc',
						'ft_source'  => 'google',
						'lt_source'  => 'newsletter',
						'lt_content' => 'banner-a',
					)
				),
			);
			$_POST                                           = array();
			$GLOBALS['clicktrail_test_consent_required']     = false;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = false;
			$GLOBALS['clicktrail_test_filters']              = array();
			$GLOBALS['clicktrail_test_actions']              = array();
			$GLOBALS['clicktrail_test_dispatches']           = array();
			$GLOBALS['clicktrail_test_transients']           = array();
			$GLOBALS['clicktrail_test_is_admin']             = false;
			$GLOBALS['clicktrail_test_doing_ajax']           = false;
			$this->db                                        = new ClickTrailFormidableWpdb();
			$GLOBALS['wpdb']                                 = $this->db;
			// Formidable "active" for every test except the one that proves the adapter is inert without it.
			if ( 'test_adapter_is_inert_without_formidable' !== $this->getName() ) {
				require_once dirname( __DIR__ ) . '/helpers/formidable-doubles.php';
			}
		}

		/**
		 * Load the Formidable doubles (Formidable "active") with the given fields for form 9.
		 *
		 * @param array<int,object> $fields Fields.
		 */
		private function formidable( array $fields = array() ): void {
			FrmField::$forms = array( 9 => $fields );
		}

		private function opt_in_to_server_render(): void {
			$GLOBALS['clicktrail_test_filters']['clicutcl_render_server_attribution_values'] = static fn() => true;
		}

		private function block_consent(): void {
			$GLOBALS['clicktrail_test_consent_required']     = true;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = true;
			$_COOKIE['production_consent']                   = 'denied';
		}

		/**
		 * The fixture form: a name field, an email field, and hidden fields marked three ways.
		 *
		 * @return array<int,object>
		 */
		private static function form_fields(): array {
			return array(
				1 => ct_frm_field( 1, 'text', 'name1', 'name' ),
				2 => ct_frm_field( 2, 'email', 'email1', 'your_email' ),
				3 => ct_frm_field( 3, 'hidden', 'ct_gclid', 'Google click' ),
				4 => ct_frm_field( 4, 'hidden', 'ct_lt_term', 'Term' ),
				5 => ct_frm_field( 5, 'hidden', 'k5ab9', 'ct_ft_source' ),
				6 => ct_frm_field( 6, 'hidden', 'ct_lt_source2', 'Last source' ),
				7 => ct_frm_field( 7, 'hidden', 'ct_lt_content', 'Content' ),
			);
		}

		private static function item_meta(): array {
			return array(
				1 => 'Jonas',
				2 => 'jonas@example.com',
				3 => 'old',
				4 => 'x',
				5 => '',
				6 => 'stale',
				7 => 'stale',
			);
		}

		private static function values( ?array $item_meta = null ): array {
			return array(
				'form_id'   => '9',
				'item_key'  => 'abc',
				'item_meta' => null === $item_meta ? self::item_meta() : $item_meta,
			);
		}

		/**
		 * Actions recorded for the logged hook.
		 */
		private function logged_actions(): array {
			return array_values(
				array_filter(
					$GLOBALS['clicktrail_test_actions'],
					static function ( $action ) {
						return 'clicutcl_form_submission_logged' === $action['tag'];
					}
				)
			);
		}

		/**
		 * Meta pairs stored on an entry (field_id 0 rows), merged.
		 */
		private function entry_meta( int $entry_id ): array {
			$meta = array();
			foreach ( FrmEntryMeta::get_entry_meta_info( $entry_id ) as $row ) {
				if ( 0 === (int) $row->field_id ) {
					$meta = array_merge( $meta, unserialize( $row->meta_value ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
				}
			}
			return $meta;
		}

		private function store_entry( int $entry_id, array $overrides = array() ): void {
			FrmEntry::$entries[ $entry_id ] = (object) array_merge(
				array(
					'id'       => $entry_id,
					'form_id'  => 9,
					'is_draft' => 0,
					'metas'    => array(
						1 => 'Jonas',
						2 => 'jonas@example.com',
					),
				),
				$overrides
			);
		}

		public function test_adapter_is_inert_without_formidable(): void {
			$this->assertFalse( ( new Formidable_Adapter() )->is_active() );
		}

		public function test_adapter_is_active_with_formidable(): void {
			$this->formidable();

			$this->assertTrue( ( new Formidable_Adapter() )->is_active() );
			$this->assertSame( 'Formidable Forms', ( new Formidable_Adapter() )->get_platform_name() );
		}

		public function test_hooks_are_the_three_current_formidable_names(): void {
			require_once dirname( __DIR__ ) . '/helpers/hook-recorder-forms-namespace.php';
			$GLOBALS['ct_forms_registered_hooks'] = array();

			( new Formidable_Adapter() )->register_hooks();

			$this->assertSame(
				array( 'frm_get_default_value', 'frm_pre_create_entry', 'frm_after_create_entry' ),
				$GLOBALS['ct_forms_registered_hooks']
			);
		}

		public function test_render_leaves_the_default_alone_even_with_a_cookie(): void {
			$this->formidable( self::form_fields() );
			$adapter = new Formidable_Adapter();

			$this->assertSame( '', $adapter->render_field_default( '', self::form_fields()[3], true, false ), 'Cached HTML must not carry the requesting visitor\'s cookie (#152).' );
			$this->assertSame( 'keep', $adapter->render_field_default( 'keep', self::form_fields()[3], true, false ) );
		}

		public function test_render_fills_marked_hidden_fields_only_when_the_filter_opts_in(): void {
			$this->formidable( self::form_fields() );
			$this->opt_in_to_server_render();
			$fields  = self::form_fields();
			$adapter = new Formidable_Adapter();

			$this->assertSame( 'abc', $adapter->render_field_default( '', $fields[3], true, false ), 'Field key ct_gclid.' );
			$this->assertSame( 'google', $adapter->render_field_default( '', $fields[5], true, false ), 'Label ct_ft_source with an unrelated field key.' );
			$this->assertSame( 'newsletter', $adapter->render_field_default( '', $fields[6], true, false ), 'Field key with the numeric suffix Formidable appends.' );
			$this->assertSame( 'banner-a', $adapter->render_field_default( '', $fields[7], true, false ), 'utm_content: ct_lt_content.' );
		}

		public function test_render_leaves_unmarked_placeholder_and_cookie_less_fields_alone(): void {
			$this->formidable( self::form_fields() );
			$this->opt_in_to_server_render();
			$fields  = self::form_fields();
			$adapter = new Formidable_Adapter();
			$text    = ct_frm_field( 8, 'text', 'ct_gclid', 'ct_gclid' );
			$note    = ct_frm_field( 9, 'hidden', 'note', 'ct_gclid note' );
			$unknown = ct_frm_field( 10, 'hidden', 'ct_company', 'ct_company' );

			$this->assertSame( 'keep', $adapter->render_field_default( 'keep', $fields[4], true, false ), 'The cookie lacks lt_term.' );
			$this->assertSame( 'keep', $adapter->render_field_default( 'keep', $fields[1], true, false ), 'Unmarked field.' );
			$this->assertSame( 'keep', $adapter->render_field_default( 'keep', $text, true, false ), 'Only hidden fields are filled.' );
			$this->assertSame( 'keep', $adapter->render_field_default( 'keep', $note, true, false ), 'A label that merely starts with a key is not a marker.' );
			$this->assertSame( 'keep', $adapter->render_field_default( 'keep', $unknown, true, false ), 'Only keys from get_field_mapping() count.' );
			$this->assertSame( 'keep', $adapter->render_field_default( 'keep', $fields[3], false, false ), 'Placeholders and field HTML are not values.' );
			$this->assertSame( 'keep', $adapter->render_field_default( 'keep', null ), 'No field.' );
		}

		public function test_render_is_blank_when_consent_is_blocked(): void {
			$this->formidable( self::form_fields() );
			$this->opt_in_to_server_render();
			$this->block_consent();

			$this->assertSame( '', ( new Formidable_Adapter() )->render_field_default( '', self::form_fields()[3], true, false ) );
		}

		public function test_submit_takes_cookie_keys_and_blanks_marked_fields_the_cookie_lacks(): void {
			$this->formidable( self::form_fields() );

			$out = ( new Formidable_Adapter() )->refresh_entry_values( self::values() )['item_meta'];

			$this->assertSame( 'Jonas', $out[1] );
			$this->assertSame( 'jonas@example.com', $out[2] );
			$this->assertSame( 'abc', $out[3], 'A non-empty cookie key replaces the posted value.' );
			$this->assertSame( '', $out[4], 'A key the cookie lacks is blank (#152).' );
			$this->assertSame( 'google', $out[5], 'Label-marked field: an empty posted value is filled.' );
			$this->assertSame( 'newsletter', $out[6], 'Suffixed field key.' );
			$this->assertSame( 'banner-a', $out[7], 'utm_content reaches the entry (the HandL pitfall).' );
		}

		public function test_every_mapped_key_is_refreshed_including_the_utm_content_keys(): void {
			// The payload normalizes the legacy sc_click_id aliases to the canonical `sccid` keys.
			$keys   = array_values( array_diff( Attribution_Provider::get_field_mapping(), array( 'sc_click_id', 'ft_sc_click_id', 'lt_sc_click_id' ) ) );
			$cookie = array();
			$fields = array();
			$posted = array();
			foreach ( $keys as $index => $key ) {
				$cookie[ $key ]          = 'v_' . $key;
				$fields[ 100 + $index ]  = ct_frm_field( 100 + $index, 'hidden', 'ct_' . $key, 'Field ' . $index );
				$posted[ 100 + $index ]  = 'stale';
			}
			$_COOKIE = array( 'attribution' => json_encode( $cookie ) );
			$this->formidable( $fields );
			$this->assertContains( 'ft_content', $keys );
			$this->assertContains( 'lt_content', $keys );

			$payload = Attribution_Provider::get_payload();
			$this->assertSame( 'v_ft_content', $payload['ft_content'] );
			$this->assertSame( 'v_lt_content', $payload['lt_content'] );

			$out = ( new Formidable_Adapter() )->refresh_entry_values( self::values( $posted ) )['item_meta'];

			// Every mapped key is rewritten from the sanitized cookie payload (the payload derives some keys,
			// e.g. the top-level `gclid`, from the touch keys); a key the payload lacks is blank.
			foreach ( $keys as $index => $key ) {
				$this->assertSame( isset( $payload[ $key ] ) ? (string) $payload[ $key ] : '', $out[ 100 + $index ], "ct_$key is refreshed from the cookie payload." );
			}
			$this->assertSame( 'v_ft_content', $out[ 100 + array_search( 'ft_content', $keys, true ) ] );
			$this->assertSame( 'v_lt_content', $out[ 100 + array_search( 'lt_content', $keys, true ) ] );
		}

		public function test_item_meta_keyed_by_field_key_is_refreshed_too(): void {
			$this->formidable( self::form_fields() );

			$out = ( new Formidable_Adapter() )->refresh_entry_values( self::values( array( 'ct_gclid' => 'old', 'name1' => 'Jonas' ) ) )['item_meta'];

			$this->assertSame( 'abc', $out['ct_gclid'] );
			$this->assertSame( 'Jonas', $out['name1'] );
		}

		public function test_the_written_value_is_slashed_like_formidable_posts_it(): void {
			$_COOKIE = array( 'attribution' => json_encode( array( 'gclid' => "o'brien" ) ) );
			$this->formidable( self::form_fields() );

			$out = ( new Formidable_Adapter() )->refresh_entry_values( self::values() )['item_meta'];

			$this->assertSame( "o\\'brien", $out[3], 'Formidable stores item_meta slashed and unslashes on read.' );
		}

		public function test_stale_values_with_a_cookie_that_lacks_keys_are_blanked_and_no_cookie_blanks_all(): void {
			$this->formidable( self::form_fields() );
			$_COOKIE = array( 'attribution' => json_encode( array( 'gclid' => 'B1' ) ) );
			$out     = ( new Formidable_Adapter() )->refresh_entry_values( self::values() )['item_meta'];
			$this->assertSame( 'B1', $out[3] );
			$this->assertSame( '', $out[5] );
			$this->assertSame( '', $out[6] );

			$_COOKIE = array();
			$out     = ( new Formidable_Adapter() )->refresh_entry_values( self::values() )['item_meta'];
			foreach ( array( 3, 4, 5, 6, 7 ) as $id ) {
				$this->assertSame( '', $out[ $id ], "field $id is blank without a cookie: Formidable inputs carry no JS fill marker." );
			}
			$this->assertSame( 'Jonas', $out[1] );
		}

		public function test_blocked_consent_blanks_every_marked_field_before_formidable_stores_it(): void {
			$this->formidable( self::form_fields() );
			$this->block_consent();

			$out = ( new Formidable_Adapter() )->refresh_entry_values( self::values() )['item_meta'];

			$this->assertSame( 'Jonas', $out[1] );
			foreach ( array( 3, 4, 5, 6, 7 ) as $id ) {
				$this->assertSame( '', $out[ $id ], "field $id is blanked." );
			}
		}

		public function test_an_unmapped_ct_field_and_a_non_hidden_field_are_never_touched(): void {
			$fields = array(
				8  => ct_frm_field( 8, 'hidden', 'ct_company', 'Company' ),
				9  => ct_frm_field( 9, 'hidden', 'k9', 'ct_company' ),
				10 => ct_frm_field( 10, 'text', 'ct_gclid', 'ct_gclid' ),
				11 => ct_frm_field( 11, 'hidden', 'k11', 'ct_gclid note' ),
			);
			$this->formidable( $fields );
			$posted = array( 8 => 'Acme', 9 => 'Acme2', 10 => 'typed', 11 => 'note' );

			foreach ( array( 'valid cookie', 'no cookie', 'consent blocked' ) as $state ) {
				if ( 'no cookie' === $state ) {
					$_COOKIE = array();
				}
				if ( 'consent blocked' === $state ) {
					$this->block_consent();
				}
				$this->assertSame( $posted, ( new Formidable_Adapter() )->refresh_entry_values( self::values( $posted ) )['item_meta'], "Left alone ($state)." );
			}
		}

		public function test_a_marked_field_the_form_did_not_post_is_not_added(): void {
			$this->formidable( self::form_fields() );

			$out = ( new Formidable_Adapter() )->refresh_entry_values( self::values( array( 1 => 'Jonas', 3 => 'old' ) ) )['item_meta'];

			$this->assertSame( array( 1 => 'Jonas', 3 => 'abc' ), $out );
		}

		public function test_forms_without_marked_fields_and_odd_input_pass_through(): void {
			$this->formidable( array( 1 => ct_frm_field( 1, 'text', 'name1', 'Name' ) ) );
			$adapter = new Formidable_Adapter();
			$values  = self::values( array( 1 => 'Jonas' ) );

			$this->assertSame( $values, $adapter->refresh_entry_values( $values ) );
			$this->assertSame( 'x', $adapter->refresh_entry_values( 'x' ) );
			$this->assertSame( array( 'form_id' => 9 ), $adapter->refresh_entry_values( array( 'form_id' => 9 ) ) );
			$this->assertSame( array( 'form_id' => 99, 'item_meta' => array( 3 => 'old' ) ), $adapter->refresh_entry_values( array( 'form_id' => 99, 'item_meta' => array( 3 => 'old' ) ) ), 'A form Formidable does not know.' );
		}

		public function test_an_admin_screen_entry_is_not_refreshed_or_logged(): void {
			$this->formidable( self::form_fields() );
			$this->store_entry( 501 );
			$GLOBALS['clicktrail_test_is_admin'] = true;
			$adapter                             = new Formidable_Adapter();

			$this->assertSame( self::values(), $adapter->refresh_entry_values( self::values() ) );
			$adapter->on_submission( 501, 9, array() );
			$this->assertSame( array(), $this->db->rows );

			$GLOBALS['clicktrail_test_doing_ajax'] = true; // Formidable submits through admin-ajax.php.
			$this->assertSame( 'abc', $adapter->refresh_entry_values( self::values() )['item_meta'][3] );
		}

		public function test_on_submission_logs_once_with_the_entry_id_and_writes_the_ids_as_entry_meta(): void {
			$this->formidable( self::form_fields() );
			$this->store_entry( 501 );
			$adapter = new ClickTrailFormidableAdapter();

			$adapter->on_submission( 501, 9, array( 'is_child' => false ) );
			$adapter->on_submission( 501, 9, array( 'is_child' => false ) );

			$this->assertCount( 1, $this->db->rows, 'One form_submission row per entry.' );
			$this->assertCount( 1, $GLOBALS['clicktrail_test_dispatches'] );
			$data = json_decode( $this->db->rows[0]['data']['event_data'], true );
			$this->assertSame( 'formidable', $data['platform'] );
			$this->assertSame( 9, $data['form_id'] );
			$this->assertSame( '501', (string) $data['entry_id'] );
			$this->assertSame( 'abc', $data['attribution']['gclid'] );
			$this->assertSame( 'banner-a', $data['attribution']['lt_content'] );

			$meta = $this->entry_meta( 501 );
			$this->assertSame( $data['event_id'], $meta['ct_event_id'] );
			$this->assertSame( $data['lead_id'], $meta['ct_lead_id'] );
			$this->assertSame( 'abc', $meta['ct_gclid'] );
			$this->assertSame( 'banner-a', $meta['ct_lt_content'] );
			$this->assertCount( 1, FrmEntryMeta::$rows, 'The duplicate call wrote no second meta row.' );

			$actions = $this->logged_actions();
			$this->assertCount( 1, $actions );
			$this->assertSame( array( 'formidable', 9, 501, $data['event_id'], $data['lead_id'] ), array_slice( $actions[0]['args'], 0, 5 ) );
		}

		public function test_a_repeat_after_a_crash_before_the_meta_write_repairs_only_the_missing_ids(): void {
			$this->formidable( self::form_fields() );
			$this->store_entry( 501 );
			$adapter = new ClickTrailFormidableAdapter();

			$adapter->on_submission( 501, 9, array() );
			FrmEntryMeta::$rows = array(); // The first call died before the meta write.
			$adapter->on_submission( 501, 9, array() );

			$this->assertCount( 1, $this->db->rows );
			$this->assertSame( array( 'ct_event_id', 'ct_lead_id' ), array_keys( $this->entry_meta( 501 ) ), 'A repeat writes only the IDs.' );
		}

		public function test_the_lead_carries_the_full_cookie_payload_even_for_a_form_without_ct_fields(): void {
			$this->formidable( array( 1 => ct_frm_field( 1, 'text', 'name1', 'Name' ) ) );
			$this->store_entry( 502 );

			( new ClickTrailFormidableAdapter() )->on_submission( 502, 9, array() );

			$data = json_decode( $this->db->rows[0]['data']['event_data'], true );
			$this->assertSame( 'abc', $data['attribution']['gclid'] );
			$this->assertSame( 'google', $data['attribution']['ft_source'] );
		}

		public function test_no_log_and_no_meta_for_drafts_spam_children_consent_blocked_or_missing_entries(): void {
			$this->formidable( self::form_fields() );
			$adapter = new ClickTrailFormidableAdapter();
			$this->store_entry( 601, array( 'is_draft' => 1 ) );
			$this->store_entry( 602, array( 'is_draft' => 4 ) );
			$this->store_entry( 603 );

			$adapter->on_submission( 601, 9, array() );
			$adapter->on_submission( 602, 9, array() );
			$adapter->on_submission( 603, 9, array( 'is_child' => true ) );
			$adapter->on_submission( 999, 9, array() );
			$adapter->on_submission( 0, 9, array() );
			$this->assertSame( array(), $this->db->rows );
			$this->assertSame( array(), FrmEntryMeta::$rows );

			$this->block_consent();
			$adapter->on_submission( 603, 9, array() );
			$this->assertSame( array(), $this->db->rows, 'Consent withdrawn: nothing is logged.' );
			$this->assertSame( array(), FrmEntryMeta::$rows, 'A gated submission never gets IDs.' );
		}

		public function test_a_cookie_less_submission_logs_nothing(): void {
			$this->formidable( self::form_fields() );
			$this->store_entry( 501 );
			$_COOKIE = array();

			( new ClickTrailFormidableAdapter() )->on_submission( 501, 9, array() );

			$this->assertSame( array(), $this->db->rows );
			$this->assertSame( array(), FrmEntryMeta::$rows );
		}

		public function test_identity_candidates_come_from_the_stored_entry(): void {
			$this->formidable( self::form_fields() );
			$this->store_entry( 501 );
			$adapter = new ClickTrailFormidableAdapter();

			$adapter->on_submission( 501, 9, array() );

			$this->assertSame( 'jonas@example.com', $adapter->identity_inputs[0]['email'] );
			$this->assertSame( 'Jonas', $adapter->identity_inputs[0]['name'] );
		}
	}
}
