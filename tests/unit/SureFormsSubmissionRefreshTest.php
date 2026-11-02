<?php
/**
 * SureForms: submit-time refresh of ct_* fields, ClickTrail log and entry extras (#172).
 *
 * SureForms keys are `srfm-<type>-<block id>-lbl-<unpadded base64 label>-<slug>`. A field is
 * ClickTrail's when its label is exactly `ct_<mapped key>` or its slug is `ct-<mapped key>`.
 * `srfm_before_fields_processing` refreshes them before the entry and the emails;
 * `srfm_form_submit` logs and writes the entry's `extras`.
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
			public static function dispatch_form_submission( $platform, $form_id, $attribution, $context = array() ) {
				$GLOBALS['clicktrail_test_dispatches'][] = array( $platform, $form_id, $attribution, $context );
			}
		}
	}
}

namespace CLICUTCL\Integrations\Forms {
	// In the isolated child process only this file is loaded.
	if ( ! function_exists( __NAMESPACE__ . '\add_filter' ) ) {
		function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
			$GLOBALS['ct_sureforms_hooks'][] = array( 'filter', $tag, $priority, $accepted_args );
			return true;
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\add_action' ) ) {
		function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
			$GLOBALS['ct_sureforms_hooks'][] = array( 'action', $tag, $priority, $accepted_args );
			return true;
		}
	}
}

namespace SRFM\Inc\Database\Tables {
	/**
	 * SureForms entries double: `get()` and `update()` over an in-memory table.
	 */
	class Entries {
		public static function get( $entry_id ) {
			return $GLOBALS['ct_sureforms_entries'][ $entry_id ] ?? array();
		}

		public static function update( $entry_id, $data = array() ) {
			$GLOBALS['ct_sureforms_updates'][] = array( $entry_id, $data );
			$GLOBALS['ct_sureforms_entries'][ $entry_id ] = array_merge( $GLOBALS['ct_sureforms_entries'][ $entry_id ] ?? array(), $data );
			return 1;
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
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-sureforms-adapter.php';

	use CLICUTCL\Integrations\Forms\SureForms_Adapter;
	use PHPUnit\Framework\TestCase;

	if ( ! function_exists( 'current_time' ) ) {
		function current_time( $type ) {
			return '2026-11-02 12:00:00';
		}
	}

	/**
	 * Records `$wpdb->insert()` calls.
	 */
	final class ClickTrailSureFormsWpdb {
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
	 * Skips the identity resolver, which needs the full stack; everything else is real.
	 */
	final class ClickTrailSureFormsAdapter extends SureForms_Adapter {
		protected function resolve_identity_payload( $identity_input = array() ) {
			$GLOBALS['ct_sureforms_identity_input'] = $identity_input;
			return array();
		}
	}

	/**
	 * Class SureFormsSubmissionRefreshTest
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class SureFormsSubmissionRefreshTest extends TestCase {
		/**
		 * Row recorder.
		 *
		 * @var ClickTrailSureFormsWpdb
		 */
		private $db;

		protected function setUp(): void {
			require_once dirname( __DIR__ ) . '/helpers/consent-mode-settings-stub.php';
			$_COOKIE = array(
				'attribution' => json_encode(
					array(
						'gclid'     => 'abc',
						'ft_source' => 'google',
						'lt_source' => 'meta',
					)
				),
			);
			$_POST = array();
			$GLOBALS['clicktrail_test_consent_required']     = false;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = false;
			$GLOBALS['clicktrail_test_filters']              = array();
			$GLOBALS['clicktrail_test_transients']           = array();
			$GLOBALS['clicktrail_test_dispatches']           = array();
			$GLOBALS['clicktrail_test_actions']              = array();
			$GLOBALS['ct_sureforms_hooks']                   = array();
			$GLOBALS['ct_sureforms_entries']                 = array();
			$GLOBALS['ct_sureforms_updates']                 = array();
			$this->db                                        = new ClickTrailSureFormsWpdb();
			$GLOBALS['wpdb']                                 = $this->db;
		}

		/**
		 * A SureForms field key.
		 */
		private static function key( string $type, string $id, string $label, string $slug ): string {
			return 'srfm-' . $type . '-' . $id . '-lbl-' . rtrim( base64_encode( $label ), '=' ) . '-' . $slug; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		private static function form_data(): array {
			return array(
				'form-id'                                                => '9',
				self::key( 'input', 'aaaa1111', 'Name', 'name' )         => 'Jonas',
				self::key( 'email', 'bbbb2222', 'Email', 'email' )       => 'jonas@example.com',
				self::key( 'input', 'cccc3333', 'ct_gclid', 'ct-gclid' ) => 'old',
				self::key( 'input', 'dddd4444', 'ct_lt_term', 'ct-lt-term' ) => 'stale',
				self::key( 'input', 'eeee5555', 'ct_ft_source', 'ct-ft-source' ) => '',
				'srfm-honeypot-field'                                    => '',
			);
		}

		private function adapter(): SureForms_Adapter {
			return new ClickTrailSureFormsAdapter();
		}

		public function test_cookie_wins_per_key_and_marked_fields_the_cookie_lacks_are_blanked(): void {
			$out = $this->adapter()->refresh_form_data( self::form_data() );

			$this->assertSame( 'Jonas', $out[ self::key( 'input', 'aaaa1111', 'Name', 'name' ) ] );
			$this->assertSame( 'jonas@example.com', $out[ self::key( 'email', 'bbbb2222', 'Email', 'email' ) ] );
			$this->assertSame( 'abc', $out[ self::key( 'input', 'cccc3333', 'ct_gclid', 'ct-gclid' ) ], 'A non-empty cookie key replaces the posted value.' );
			$this->assertSame( '', $out[ self::key( 'input', 'dddd4444', 'ct_lt_term', 'ct-lt-term' ) ], 'A key the cookie lacks is blank (#152).' );
			$this->assertSame( 'google', $out[ self::key( 'input', 'eeee5555', 'ct_ft_source', 'ct-ft-source' ) ], 'An empty posted value is filled from the cookie.' );
			$this->assertSame( '9', $out['form-id'] );
			$this->assertSame( '', $out['srfm-honeypot-field'] );
		}

		public function test_a_field_is_marked_by_label_or_by_slug(): void {
			$by_label = self::key( 'input', '11111111', 'ct_gclid', 'visitor-note' );
			$by_slug  = self::key( 'input', '22222222', 'Google click', 'ct-gclid' );
			$by_snake = self::key( 'input', '33333333', 'Google click', 'ct_ft_source' );
			$out      = $this->adapter()->refresh_form_data( array( $by_label => 'x', $by_slug => 'y', $by_snake => 'z' ) );

			$this->assertSame( 'abc', $out[ $by_label ] );
			$this->assertSame( 'abc', $out[ $by_slug ] );
			$this->assertSame( 'google', $out[ $by_snake ] );
		}

		public function test_an_unmapped_or_merely_prefixed_ct_field_is_never_touched(): void {
			$this->in_each_state(
				function ( string $state ): void {
					$data = array(
						self::key( 'input', '11111111', 'ct_company', 'ct-company' ) => 'Acme',
						self::key( 'input', '22222222', 'ct_gclid note', 'note' )    => 'keep',
						self::key( 'input', '33333333', 'Ct_Gclid', 'x' )             => 'keep',
						self::key( 'input', '44444444', 'My ct_gclid', 'y' )          => 'keep',
						'ct_gclid'                                                    => 'not a SureForms key',
					);

					$this->assertSame( $data, $this->adapter()->refresh_form_data( $data ), "Left alone ($state)." );
				}
			);
		}

		public function test_a_form_without_a_ct_field_is_unchanged_and_gains_none(): void {
			$data = array(
				'form-id'                                        => '9',
				self::key( 'input', 'aaaa1111', 'Name', 'name' ) => 'Jonas',
			);

			$this->assertSame( $data, $this->adapter()->refresh_form_data( $data ) );
		}

		public function test_no_cookie_blanks_every_marked_field_unless_the_js_fill_marker_is_posted(): void {
			$_COOKIE = array();
			$out     = $this->adapter()->refresh_form_data( self::form_data() );
			foreach ( array( 'cccc3333' => array( 'ct_gclid', 'ct-gclid' ), 'dddd4444' => array( 'ct_lt_term', 'ct-lt-term' ) ) as $id => $pair ) {
				$this->assertSame( '', $out[ self::key( 'input', $id, $pair[0], $pair[1] ) ], 'No cookie, no marker: blank.' );
			}
			$this->assertSame( 'Jonas', $out[ self::key( 'input', 'aaaa1111', 'Name', 'name' ) ] );

			$posted                  = self::form_data();
			$posted['ct_fill_source'] = 'js';
			$out                     = $this->adapter()->refresh_form_data( $posted );
			$this->assertSame( 'old', $out[ self::key( 'input', 'cccc3333', 'ct_gclid', 'ct-gclid' ) ], 'No cookie but the Injector marker: the posted value is kept.' );
			$this->assertSame( 'stale', $out[ self::key( 'input', 'dddd4444', 'ct_lt_term', 'ct-lt-term' ) ] );
			$this->assertArrayNotHasKey( 'ct_fill_source', $out, 'The marker is stripped before the data is processed.' );

			$posted['ct_fill_source'] = 'nope';
			$out                      = $this->adapter()->refresh_form_data( $posted );
			$this->assertSame( '', $out[ self::key( 'input', 'cccc3333', 'ct_gclid', 'ct-gclid' ) ], 'Only `js` counts as the marker.' );
			$this->assertArrayNotHasKey( 'ct_fill_source', $out );
		}

		public function test_a_readable_cookie_overrides_the_marker(): void {
			$posted                   = self::form_data();
			$posted['ct_fill_source'] = 'js';
			$out                      = $this->adapter()->refresh_form_data( $posted );

			$this->assertSame( 'abc', $out[ self::key( 'input', 'cccc3333', 'ct_gclid', 'ct-gclid' ) ] );
			$this->assertSame( '', $out[ self::key( 'input', 'dddd4444', 'ct_lt_term', 'ct-lt-term' ) ], 'The cookie is authoritative even with the marker.' );
		}

		public function test_denied_consent_blanks_every_marked_field_and_leaves_the_rest(): void {
			$GLOBALS['clicktrail_test_consent_required']     = true;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = true;
			$_COOKIE['production_consent']                   = 'denied';
			$posted                                          = self::form_data();
			$posted['ct_fill_source']                        = 'js';

			$out = $this->adapter()->refresh_form_data( $posted );

			$this->assertSame( 'Jonas', $out[ self::key( 'input', 'aaaa1111', 'Name', 'name' ) ] );
			foreach ( array( 'cccc3333' => array( 'ct_gclid', 'ct-gclid' ), 'dddd4444' => array( 'ct_lt_term', 'ct-lt-term' ), 'eeee5555' => array( 'ct_ft_source', 'ct-ft-source' ) ) as $id => $pair ) {
				$this->assertSame( '', $out[ self::key( 'input', $id, $pair[0], $pair[1] ) ], 'Blanked before SureForms stores or mails it.' );
			}
		}

		public function test_non_array_input_is_returned_untouched(): void {
			$this->assertSame( 'x', $this->adapter()->refresh_form_data( 'x' ) );
		}

		public function test_hooks_are_the_current_names_with_matching_arity_and_no_deprecated_alias(): void {
			( new SureForms_Adapter() )->register_hooks();

			$this->assertSame(
				array(
					array( 'filter', 'srfm_before_fields_processing', 10, 1 ),
					array( 'action', 'srfm_form_submit', 10, 1 ),
				),
				$GLOBALS['ct_sureforms_hooks']
			);
		}

		public function test_the_adapter_is_inert_without_the_vendor_plugin(): void {
			$adapter = new SureForms_Adapter();

			$this->assertFalse( $adapter->is_active() );
			$this->assertSame( 'SureForms', $adapter->get_platform_name() );
			$this->assertSame( 'form', $adapter->populate_fields( 'form' ), 'No render output: nothing to fill, so nothing to leak into cached HTML.' );
		}

		/**
		 * Run the refresh and then the success hook, as one SureForms request does.
		 */
		private function submit( SureForms_Adapter $adapter, array $form_data, array $response ): void {
			$adapter->refresh_form_data( $form_data );
			$adapter->on_submission( $response );
		}

		private function response( int $entry_id = 41 ): array {
			$response = array(
				'success' => true,
				'form_id' => 9,
				'data'    => array( 'name' => 'Jonas', 'email' => 'jonas@example.com' ),
			);
			if ( $entry_id > 0 ) {
				$response['entry_id'] = $entry_id;
			}
			return $response;
		}

		public function test_the_log_holds_the_cookie_payload_overlaid_by_the_refreshed_values(): void {
			$this->submit( $this->adapter(), self::form_data(), $this->response() );

			$this->assertCount( 1, $this->db->rows );
			$data = json_decode( $this->db->rows[0]['data']['event_data'], true );
			$this->assertSame( 'sureforms', $data['platform'] );
			$this->assertSame( 9, $data['form_id'] );
			$this->assertSame( 41, $data['entry_id'] );
			$this->assertSame( array( 'gclid' => 'abc', 'ft_source' => 'google', 'lt_source' => 'meta' ), $data['attribution'] );
			$this->assertSame( array( 'name' => 'Jonas', 'email' => 'jonas@example.com' ), $GLOBALS['ct_sureforms_identity_input'] );
			$this->assertCount( 1, $GLOBALS['clicktrail_test_dispatches'] );
		}

		public function test_a_form_with_no_ct_field_still_logs_the_cookie_and_writes_the_entry_extras(): void {
			$GLOBALS['ct_sureforms_entries'][41] = array( 'form_id' => 9, 'extras' => array( 'is_after_submission_process_triggered' => false ) );

			$this->submit( $this->adapter(), array( 'form-id' => '9' ), $this->response() );

			$data   = json_decode( $this->db->rows[0]['data']['event_data'], true );
			$extras = $GLOBALS['ct_sureforms_entries'][41]['extras'];
			$this->assertFalse( $extras['is_after_submission_process_triggered'], 'SureForms\' own extras are kept.' );
			$this->assertSame( 'abc', $extras['ct_gclid'] );
			$this->assertSame( 'google', $extras['ct_ft_source'] );
			$this->assertSame( 'meta', $extras['ct_lt_source'] );
			$this->assertSame( $data['event_id'], $extras['ct_event_id'] );
			$this->assertSame( $data['lead_id'], $extras['ct_lead_id'] );
			$this->assertCount( 1, $GLOBALS['ct_sureforms_updates'] );
		}

		public function test_a_repeat_of_the_entry_logs_once_and_writes_only_missing_ids(): void {
			$GLOBALS['ct_sureforms_entries'][41] = array( 'form_id' => 9, 'extras' => array() );
			$adapter                             = $this->adapter();

			$this->submit( $adapter, self::form_data(), $this->response() );
			$first = $GLOBALS['ct_sureforms_entries'][41]['extras'];
			$this->submit( $adapter, self::form_data(), $this->response() );

			$this->assertCount( 1, $this->db->rows, 'One form_submission row per entry.' );
			$this->assertCount( 1, $GLOBALS['clicktrail_test_dispatches'] );
			$this->assertSame( $first, $GLOBALS['ct_sureforms_entries'][41]['extras'] );
			$this->assertCount( 1, $GLOBALS['ct_sureforms_updates'], 'The repeat found every ID and wrote nothing.' );

			// The first call died after the dedupe marker and before the extras write: the IDs are repaired.
			unset( $GLOBALS['ct_sureforms_entries'][41]['extras']['ct_event_id'], $GLOBALS['ct_sureforms_entries'][41]['extras']['ct_lead_id'] );
			$this->submit( $adapter, self::form_data(), $this->response() );

			$this->assertCount( 1, $this->db->rows );
			$this->assertSame( $first['ct_event_id'], $GLOBALS['ct_sureforms_entries'][41]['extras']['ct_event_id'] );
			$this->assertSame( $first['ct_lead_id'], $GLOBALS['ct_sureforms_entries'][41]['extras']['ct_lead_id'] );
		}

		public function test_a_submission_that_stores_no_entry_logs_but_writes_no_extras(): void {
			$this->submit( $this->adapter(), self::form_data(), $this->response( 0 ) );

			$this->assertCount( 1, $this->db->rows );
			$data = json_decode( $this->db->rows[0]['data']['event_data'], true );
			$this->assertArrayNotHasKey( 'entry_id', $data );
			$this->assertSame( array(), $GLOBALS['ct_sureforms_updates'] );
		}

		public function test_an_extras_value_that_is_not_an_array_is_never_replaced(): void {
			$GLOBALS['ct_sureforms_entries'][41] = array( 'form_id' => 9, 'extras' => 'a:0:{}' );

			$this->submit( $this->adapter(), self::form_data(), $this->response() );

			$this->assertCount( 1, $this->db->rows, 'The log does not depend on the entry write.' );
			$this->assertSame( array(), $GLOBALS['ct_sureforms_updates'] );
		}

		public function test_no_cookie_logs_only_the_marker_kept_values(): void {
			$_COOKIE                  = array();
			$posted                   = self::form_data();
			$posted['ct_fill_source'] = 'js';
			$GLOBALS['ct_sureforms_entries'][41] = array( 'form_id' => 9, 'extras' => array() );

			$this->submit( $this->adapter(), $posted, $this->response() );

			$data = json_decode( $this->db->rows[0]['data']['event_data'], true );
			$this->assertSame( array( 'gclid' => 'old', 'lt_term' => 'stale' ), $data['attribution'] );
			$this->assertSame( 'old', $GLOBALS['ct_sureforms_entries'][41]['extras']['ct_gclid'] );
		}

		public function test_no_cookie_and_no_marker_logs_and_writes_nothing(): void {
			$_COOKIE                             = array();
			$GLOBALS['ct_sureforms_entries'][41] = array( 'form_id' => 9, 'extras' => array() );

			$this->submit( $this->adapter(), self::form_data(), $this->response() );

			$this->assertSame( array(), $this->db->rows );
			$this->assertSame( array(), $GLOBALS['ct_sureforms_updates'] );
		}

		public function test_denied_consent_logs_nothing_and_writes_no_extras(): void {
			$GLOBALS['clicktrail_test_consent_required']     = true;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = true;
			$_COOKIE['production_consent']                   = 'denied';
			$GLOBALS['ct_sureforms_entries'][41]             = array( 'form_id' => 9, 'extras' => array() );

			$this->submit( $this->adapter(), self::form_data(), $this->response() );

			$this->assertSame( array(), $this->db->rows );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_dispatches'] );
			$this->assertSame( array(), $GLOBALS['ct_sureforms_updates'] );
		}

		public function test_a_refresh_from_an_earlier_submission_never_leaks_into_the_next_log(): void {
			$adapter = $this->adapter();
			$adapter->refresh_form_data( self::form_data() );
			$adapter->on_submission( $this->response( 41 ) );

			$_COOKIE = array();
			$adapter->on_submission( $this->response( 42 ) );

			$this->assertCount( 1, $this->db->rows, 'The second hook call had no cookie and no refreshed values.' );
		}

		/**
		 * Run $check in each state: valid cookie, no cookie, consent blocked.
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
