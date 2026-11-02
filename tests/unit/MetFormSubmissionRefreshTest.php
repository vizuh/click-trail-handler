<?php
/**
 * MetForm: submit-time refresh of ct_* fields, ClickTrail log and entry meta (#171).
 *
 * `metform_filter_before_store_form_data` rewrites each `ct_<key>` the form carries from the cookie
 * sent with the submit; `metform_after_store_form_data` logs once per entry and writes the IDs to the
 * `metform-entry` post. MetForm never fires an `elementor_pro/forms/*` hook, so the Elementor Forms
 * adapter cannot double-log a MetForm submission.
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
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-elementor-forms-adapter.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-metform-adapter.php';

	use CLICUTCL\Integrations\Forms\Elementor_Forms_Adapter;
	use CLICUTCL\Integrations\Forms\MetForm_Adapter;
	use PHPUnit\Framework\TestCase;

	if ( ! function_exists( 'wp_slash' ) ) {
		function wp_slash( $value ) {
			return is_string( $value ) ? addslashes( $value ) : $value;
		}
	}

	if ( ! function_exists( 'get_post_meta' ) ) {
		function get_post_meta( $post_id, $key, $single = false ) {
			return $GLOBALS['clicktrail_test_post_meta'][ $post_id ][ $key ] ?? '';
		}
	}

	if ( ! function_exists( 'metadata_exists' ) ) {
		function metadata_exists( $type, $post_id, $key ) {
			return isset( $GLOBALS['clicktrail_test_post_meta'][ $post_id ][ $key ] );
		}
	}

	if ( ! function_exists( 'update_post_meta' ) ) {
		function update_post_meta( $post_id, $key, $value ) {
			// Core unslashes what it is given.
			$GLOBALS['clicktrail_test_post_meta'][ $post_id ][ $key ] = is_string( $value ) ? stripslashes( $value ) : $value;
			$GLOBALS['clicktrail_test_meta_writes'][]                  = array( $post_id, $key );
			return true;
		}
	}

	/**
	 * Records `$wpdb->insert()` calls and the row-conditional option delete the claim release uses.
	 */
	final class ClickTrailMetFormWpdb {
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
	 * Real shared logger, no identity resolver stack.
	 */
	final class ClickTrailMetFormAdapter extends MetForm_Adapter {
		protected function resolve_identity_payload( $identity_input = array() ) {
			return array();
		}
	}

	/**
	 * Records what reaches ClickTrail's own log.
	 */
	final class ClickTrailMetFormLogSpy extends MetForm_Adapter {
		public $logged = array();

		protected function log_submission( $platform, $form_id, $attribution, $identity_input = array(), $entry_id = null ) {
			$this->logged[] = array( $platform, $form_id, $attribution, $entry_id );
			return array();
		}
	}

	/**
	 * Class MetFormSubmissionRefreshTest
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class MetFormSubmissionRefreshTest extends TestCase {
		/**
		 * Row recorder.
		 *
		 * @var ClickTrailMetFormWpdb
		 */
		private $db;

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
			$_POST                                           = array();
			$GLOBALS['clicktrail_test_consent_required']     = false;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = false;
			$GLOBALS['clicktrail_test_filters']              = array();
			$GLOBALS['clicktrail_test_transients']           = array();
			$GLOBALS['clicktrail_test_dispatches']           = array();
			$GLOBALS['clicktrail_test_actions']              = array();
			$GLOBALS['clicktrail_test_post_meta']            = array();
			$GLOBALS['clicktrail_test_meta_writes']          = array();
			$this->db                                        = new ClickTrailMetFormWpdb();
			$GLOBALS['wpdb']                                 = $this->db;
		}

		protected function tearDown(): void {
			$GLOBALS['clicktrail_test_consent_required']     = false;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = false;
		}

		/**
		 * The sanitised data MetForm hands the filter for a form that declares these inputs.
		 */
		private function stored_data(): array {
			return array(
				'mf-email'     => 'jonas@example.com',
				'ct_gclid'     => 'old',
				'ct_ft_term'   => 'x',
				'ct_fbclid'    => 'stale-from-render',
				'ct_ft_source' => '',
				'ct_lt_source' => 'google',
			);
		}

		/**
		 * One submit the way `Action::submit()` drives the hooks: entry draft, before action, filter, publish, after action.
		 *
		 * @param MetForm_Adapter $adapter  Adapter.
		 * @param int             $form_id  Form ID.
		 * @param array           $raw      Raw posted data.
		 * @param array           $stored   Sanitised data MetForm would store.
		 * @param int|null        $entry_id Entry post ID; null when the form stores no entry.
		 * @return array The data the filter returned.
		 */
		private function submit( MetForm_Adapter $adapter, int $form_id, array $raw, array $stored, ?int $entry_id = 88 ): array {
			if ( null !== $entry_id ) {
				$adapter->remember_entry( $entry_id );
			}
			$adapter->capture_request( (string) $form_id, $raw, array(), array() );
			$out = $adapter->refresh_form_data( $stored, (string) $form_id, array(), array() );
			if ( null !== $entry_id ) {
				$GLOBALS['clicktrail_test_post_meta'][ $entry_id ]['metform_entries__form_id'] = (string) $form_id;
				$adapter->remember_entry( $entry_id );
			}
			$adapter->on_submission( (string) $form_id, $raw, array(), array() );
			return $out;
		}

		public function test_cookie_wins_per_key_and_keys_missing_from_it_are_blanked(): void {
			$out = ( new MetForm_Adapter() )->refresh_form_data( $this->stored_data(), '7', array(), array() );

			$this->assertSame( 'jonas@example.com', $out['mf-email'] );
			$this->assertSame( 'new', $out['ct_gclid'] );
			$this->assertSame( '', $out['ct_ft_term'], 'Keys missing from the cookie are blanked (#152).' );
			$this->assertSame( '', $out['ct_fbclid'] );
			$this->assertSame( 'google', $out['ct_ft_source'], 'An empty stored value is filled.' );
			$this->assertSame( 'meta', $out['ct_lt_source'], 'A non-empty posted value is replaced too.' );
			$this->assertArrayNotHasKey( 'ct_lt_medium', $out, 'No field the form does not carry is added.' );
		}

		public function test_the_refresh_is_idempotent(): void {
			$adapter = new MetForm_Adapter();
			$once    = $adapter->refresh_form_data( $this->stored_data(), '7', array(), array() );

			$this->assertSame( $once, $adapter->refresh_form_data( $once, '7', array(), array() ) );
		}

		public function test_a_form_without_ct_fields_and_non_array_data_pass_through_unchanged(): void {
			$adapter = new MetForm_Adapter();
			$data    = array( 'mf-email' => 'jonas@example.com' );

			$this->assertSame( $data, $adapter->refresh_form_data( $data, '7', array(), array() ) );
			$this->assertNull( $adapter->refresh_form_data( null, '7', array(), array() ), 'MetForm passes null when no declared field was posted.' );
		}

		public function test_an_unmapped_ct_field_is_never_touched(): void {
			foreach ( array( 'valid cookie', 'no cookie', 'consent blocked' ) as $state ) {
				if ( 'no cookie' === $state ) {
					$_COOKIE = array();
				}
				if ( 'consent blocked' === $state ) {
					$GLOBALS['clicktrail_test_consent_required']     = true;
					$GLOBALS['clicktrail_test_consent_mode_enabled'] = true;
					$_COOKIE['production_consent']                   = 'denied';
				}
				$out = ( new MetForm_Adapter() )->refresh_form_data( array( 'ct_company' => 'Acme', 'ct_gclid' => 'A1' ), '7', array(), array() );
				$this->assertSame( 'Acme', $out['ct_company'], "ct_company is the visitor's own field ($state)." );
			}
		}

		public function test_visitor_a_values_with_visitor_b_cookie_lacking_two_keys_are_blanked(): void {
			$_COOKIE = array( 'attribution' => json_encode( array( 'gclid' => 'B1' ) ) );
			$adapter = new ClickTrailMetFormLogSpy();
			$posted  = array( 'ct_gclid' => 'A1', 'ct_ft_source' => 'A2', 'ct_lt_source' => 'A3' );

			$out = $this->submit( $adapter, 7, $posted, $posted );

			$this->assertSame( array( 'ct_gclid' => 'B1', 'ct_ft_source' => '', 'ct_lt_source' => '' ), $out );
			$this->assertSame( array( 'gclid' => 'B1' ), $adapter->logged[0][2], 'The log gets the post-rule set, not A\'s values.' );
		}

		public function test_no_cookie_keeps_posted_values_only_with_the_marker(): void {
			$_COOKIE = array();
			$adapter = new ClickTrailMetFormLogSpy();

			// The block builder posts new FormData( form ): the marker is in the raw data, not in the sanitised data.
			$marked = $this->submit( $adapter, 7, array( 'ct_gclid' => 'A1', 'ct_fill_source' => 'js' ), array( 'ct_gclid' => 'A1' ) );
			$this->assertSame( array( 'ct_gclid' => 'A1' ), $marked );
			$this->assertSame( array( 'gclid' => 'A1' ), $adapter->logged[0][2] );

			// The Elementor builder posts its own state: no marker, so the value is blanked and nothing is logged.
			$plain = $this->submit( $adapter, 8, array( 'ct_gclid' => 'A1' ), array( 'ct_gclid' => 'A1' ) );
			$this->assertSame( array( 'ct_gclid' => '' ), $plain );
			$this->assertCount( 1, $adapter->logged, 'An unmarked, cookie-less submit logs nothing.' );
		}

		public function test_the_marker_decision_is_per_form(): void {
			$_COOKIE = array();
			$adapter = new MetForm_Adapter();
			$adapter->capture_request( '7', array( 'ct_gclid' => 'A1', 'ct_fill_source' => 'js' ) );
			$adapter->capture_request( '8', array( 'ct_gclid' => 'B1' ) );

			$this->assertSame( array( 'ct_gclid' => 'A1' ), $adapter->refresh_form_data( array( 'ct_gclid' => 'A1' ), '7' ) );
			$this->assertSame( array( 'ct_gclid' => '' ), $adapter->refresh_form_data( array( 'ct_gclid' => 'B1' ), '8' ) );
		}

		public function test_denied_consent_blanks_every_ct_value_and_logs_nothing(): void {
			$GLOBALS['clicktrail_test_consent_required']     = true;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = true;
			$_COOKIE['production_consent']                   = 'denied';
			$adapter                                         = new ClickTrailMetFormLogSpy();

			$out = $this->submit( $adapter, 7, $this->stored_data(), $this->stored_data() );

			foreach ( array( 'ct_gclid', 'ct_ft_term', 'ct_fbclid', 'ct_ft_source', 'ct_lt_source' ) as $name ) {
				$this->assertSame( '', $out[ $name ], "$name is blanked before MetForm stores it." );
			}
			$this->assertSame( 'jonas@example.com', $out['mf-email'] );
			$this->assertSame( array(), $adapter->logged );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_meta_writes'], 'No entry meta without consent.' );
			$this->assertSame( array(), $this->db->rows );
		}

		public function test_the_log_carries_the_full_cookie_payload_overlaid_by_the_refreshed_values(): void {
			$adapter = new ClickTrailMetFormLogSpy();

			// The form carries only ct_gclid: the lead still holds every consented cookie key.
			$this->submit( $adapter, 7, array( 'ct_gclid' => 'old' ), array( 'ct_gclid' => 'old' ) );

			$this->assertSame(
				array( array( 'metform', 7, array( 'gclid' => 'new', 'ft_source' => 'google', 'lt_source' => 'meta' ), 88 ) ),
				$adapter->logged
			);
		}

		public function test_a_form_without_ct_fields_logs_the_cookie_payload(): void {
			$adapter = new ClickTrailMetFormLogSpy();

			$this->submit( $adapter, 7, array( 'mf-email' => 'jonas@example.com' ), array( 'mf-email' => 'jonas@example.com' ), null );

			$this->assertSame(
				array( array( 'metform', 7, array( 'gclid' => 'new', 'ft_source' => 'google', 'lt_source' => 'meta' ), null ) ),
				$adapter->logged,
				'A form with no entry logs without an entry ID.'
			);
		}

		public function test_one_event_row_and_the_ids_and_attribution_land_on_the_entry_post(): void {
			$adapter = new ClickTrailMetFormAdapter();

			$this->submit( $adapter, 7, array( 'mf-email' => 'jonas@example.com', 'ct_gclid' => 'old' ), array( 'mf-email' => 'jonas@example.com', 'ct_gclid' => 'old' ) );

			$this->assertCount( 1, $this->db->rows );
			$data = json_decode( $this->db->rows[0]['data']['event_data'], true );
			$this->assertSame( 'metform', $data['platform'] );
			$this->assertSame( '88', (string) $data['entry_id'] );
			$this->assertSame( 'new', $data['attribution']['gclid'] );

			$meta = $GLOBALS['clicktrail_test_post_meta'][88];
			$this->assertSame( $data['event_id'], $meta['ct_event_id'] );
			$this->assertSame( $data['lead_id'], $meta['ct_lead_id'] );
			$this->assertSame( 'new', $meta['ct_gclid'] );
			$this->assertSame( 'google', $meta['ct_ft_source'] );

			$this->assertCount( 1, $GLOBALS['clicktrail_test_dispatches'] );
			$this->assertSame( 'metform', $GLOBALS['clicktrail_test_dispatches'][0][0] );
		}

		public function test_a_repeat_of_the_same_entry_logs_once_and_writes_only_missing_ids(): void {
			$adapter = new ClickTrailMetFormAdapter();
			$raw     = array( 'ct_gclid' => 'old' );

			$this->submit( $adapter, 7, $raw, $raw );
			$writes = count( $GLOBALS['clicktrail_test_meta_writes'] );
			$this->submit( $adapter, 7, $raw, $raw );

			$this->assertCount( 1, $this->db->rows, 'The same entry writes one form_submission row.' );
			$this->assertCount( 1, $GLOBALS['clicktrail_test_dispatches'] );
			$this->assertCount( $writes, $GLOBALS['clicktrail_test_meta_writes'], 'Meta the entry already has is not written again.' );

			// The first call died after marking the dedupe key and before the meta write: the IDs are repaired.
			unset( $GLOBALS['clicktrail_test_post_meta'][88]['ct_event_id'], $GLOBALS['clicktrail_test_post_meta'][88]['ct_lead_id'] );
			$this->submit( $adapter, 7, $raw, $raw );
			$this->assertArrayHasKey( 'ct_event_id', $GLOBALS['clicktrail_test_post_meta'][88] );
			$this->assertArrayHasKey( 'ct_lead_id', $GLOBALS['clicktrail_test_post_meta'][88] );
			$this->assertCount( 1, $this->db->rows );
		}

		public function test_an_entry_saved_for_another_form_is_not_taken_as_this_forms_entry(): void {
			$adapter = new ClickTrailMetFormAdapter();
			$adapter->remember_entry( 99 );
			$GLOBALS['clicktrail_test_post_meta'][99]['metform_entries__form_id'] = '5';

			$adapter->on_submission( '7', array( 'ct_gclid' => 'old' ) );

			$data = json_decode( $this->db->rows[0]['data']['event_data'], true );
			$this->assertArrayNotHasKey( 'entry_id', $data );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_meta_writes'] );
		}

		public function test_state_does_not_leak_into_the_next_submission(): void {
			$adapter = new ClickTrailMetFormLogSpy();
			$_COOKIE = array();

			$this->submit( $adapter, 7, array( 'ct_gclid' => 'A1', 'ct_fill_source' => 'js' ), array( 'ct_gclid' => 'A1' ) );
			$this->submit( $adapter, 7, array( 'ct_gclid' => 'A1' ), array( 'ct_gclid' => 'A1' ), null );

			$this->assertCount( 1, $adapter->logged, 'The second submit has no marker and no entry: it is blanked and not logged.' );
		}

		public function test_hooks_are_the_current_vendor_names_and_none_is_an_elementor_pro_hook(): void {
			require_once dirname( __DIR__ ) . '/helpers/hook-recorder-forms-namespace.php';

			$GLOBALS['ct_forms_registered_hooks'] = array();
			( new MetForm_Adapter() )->register_hooks();
			$metform = $GLOBALS['ct_forms_registered_hooks'];

			$GLOBALS['ct_forms_registered_hooks'] = array();
			( new Elementor_Forms_Adapter() )->register_hooks();
			$elementor = $GLOBALS['ct_forms_registered_hooks'];

			$this->assertSame(
				array(
					'metform_after_store_form_data',
					'metform_before_store_form_data',
					'metform_filter_before_store_form_data',
					'save_post_metform-entry',
				),
				( static function ( array $hooks ) {
					sort( $hooks );
					return $hooks;
				} )( $metform )
			);
			$this->assertSame( array(), array_intersect( $metform, $elementor ), 'The two adapters share no hook, so a submission reaches one of them.' );
			foreach ( $elementor as $hook ) {
				$this->assertStringStartsWith( 'elementor_pro/', $hook );
			}
		}

		public function test_the_adapter_is_inert_unless_metform_is_active(): void {
			$this->assertFalse( ( new MetForm_Adapter() )->is_active() );
		}

		public function test_metform_active_by_its_version_constant(): void {
			define( 'METFORM_VERSION', '4.3.4' );

			$this->assertTrue( ( new MetForm_Adapter() )->is_active() );
			$this->assertSame( 'MetForm', ( new MetForm_Adapter() )->get_platform_name() );
		}
	}
}
