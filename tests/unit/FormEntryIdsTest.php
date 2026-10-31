<?php
/**
 * Form entries carry the ClickTrail event and lead IDs (#143).
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
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-abstract-form-adapter.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-elementor-forms-adapter.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-fluent-forms-adapter.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-gravity-forms-adapter.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-ninja-forms-submission-extra-handler.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-ninja-forms-adapter.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-wpforms-adapter.php';

	use CLICUTCL\Integrations\Forms\Abstract_Form_Adapter;
	use CLICUTCL\Integrations\Forms\Elementor_Forms_Adapter;
	use CLICUTCL\Integrations\Forms\Fluent_Forms_Adapter;
	use CLICUTCL\Integrations\Forms\Gravity_Forms_Adapter;
	use CLICUTCL\Integrations\Forms\Ninja_Forms_Adapter;
	use CLICUTCL\Integrations\Forms\WPForms_Adapter;
	use PHPUnit\Framework\TestCase;

	if ( ! function_exists( 'current_time' ) ) {
		function current_time( $type ) {
			return '2026-10-31 12:00:00';
		}
	}

	if ( ! function_exists( 'get_current_user_id' ) ) {
		function get_current_user_id() {
			return 0;
		}
	}

	if ( ! function_exists( 'gform_add_meta' ) ) {
		function gform_add_meta( $entry_id, $key, $value ) {
			$GLOBALS['clicktrail_test_gf_meta'][] = array( $entry_id, $key, $value );
		}
	}

	if ( ! function_exists( 'wpFluent' ) ) {
		function wpFluent() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Fluent Forms public API name.
			return $GLOBALS['clicktrail_test_fluent_db'];
		}
	}

	if ( ! function_exists( 'wpforms' ) ) {
		function wpforms() {
			return $GLOBALS['clicktrail_test_wpforms'];
		}
	}

	/**
	 * Records `$wpdb->insert()` calls.
	 */
	final class ClickTrailEntryIdsWpdb {
		public $prefix = 'wp_';
		public $rows   = array();

		public function insert( $table, $data ) {
			$this->rows[] = array( 'table' => $table, 'data' => $data );
			return 1;
		}
	}

	/**
	 * Records Fluent submission-meta inserts.
	 */
	final class ClickTrailEntryIdsFluentDb {
		public $rows   = array();
		private $table = '';

		public function table( string $table ): self {
			$this->table = $table;
			return $this;
		}

		public function insert( array $row ): bool {
			$this->rows[] = array( 'table' => $this->table, 'row' => $row );
			return true;
		}
	}

	/**
	 * Records WPForms entry-meta writes.
	 */
	final class ClickTrailEntryIdsWpformsDouble {
		public $entry_meta;

		public function __construct() {
			$this->entry_meta = new class() {
				public $rows = array();

				public function add( array $row ) {
					$this->rows[] = $row;
				}
			};
		}
	}

	/**
	 * Minimal Elementor Form_Record double.
	 */
	final class ClickTrailEntryIdsRecord {
		public function get( $property ) {
			return 'fields' === $property ? array() : null;
		}

		public function get_form_settings( $key ) {
			return 'id' === $key ? 'contact' : '';
		}
	}

	trait ClickTrailEntryIdsStubs {
		protected function get_attribution_payload() {
			return array(
				'ft_source'  => 'google',
				'ft_channel' => 'Paid Search',
				'lt_source'  => 'google',
				'lt_channel' => 'Paid Search',
			);
		}

		protected function resolve_identity_payload( $identity_input = array() ) {
			return array();
		}
	}

	final class ClickTrailEntryIdsGf extends Gravity_Forms_Adapter {
		use ClickTrailEntryIdsStubs;
	}

	final class ClickTrailEntryIdsWpforms extends WPForms_Adapter {
		use ClickTrailEntryIdsStubs;
	}

	final class ClickTrailEntryIdsFluent extends Fluent_Forms_Adapter {
		use ClickTrailEntryIdsStubs;
	}

	final class ClickTrailEntryIdsNinja extends Ninja_Forms_Adapter {
		use ClickTrailEntryIdsStubs;
	}

	final class ClickTrailEntryIdsElementor extends Elementor_Forms_Adapter {
		use ClickTrailEntryIdsStubs;
	}

	final class ClickTrailEntryIdsLogger extends Abstract_Form_Adapter {
		use ClickTrailEntryIdsStubs;

		public function is_active() { return true; }
		public function get_platform_name() { return 'test'; }
		public function register_hooks() {}
		public function populate_fields( $form_or_context ) { return $form_or_context; }
		public function on_submission( $arg1, $arg2 ) {}

		public function record( $platform, $form_id, array $attribution, $entry_id = null ) {
			return $this->log_submission( $platform, $form_id, $attribution, array(), $entry_id );
		}

		public function lead_id_for( $event_id ) {
			return $this->derive_lead_id( $event_id );
		}
	}

	/**
	 * Class FormEntryIdsTest
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class FormEntryIdsTest extends TestCase {
		/**
		 * Row recorder.
		 *
		 * @var ClickTrailEntryIdsWpdb
		 */
		private $db;

		protected function setUp(): void {
			require_once dirname( __DIR__ ) . '/helpers/consent-mode-settings-stub.php';
			$_COOKIE = array();
			$_POST   = array();
			$GLOBALS['clicktrail_test_transients']           = array();
			$GLOBALS['clicktrail_test_dispatches']           = array();
			$GLOBALS['clicktrail_test_actions']              = array();
			$GLOBALS['clicktrail_test_gf_meta']              = array();
			$GLOBALS['clicktrail_test_consent_required']     = false;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = false;
			$this->db                                        = new ClickTrailEntryIdsWpdb();
			$GLOBALS['wpdb']                                 = $this->db;
		}

		protected function tearDown(): void {
			$GLOBALS['clicktrail_test_consent_required']     = false;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = false;
		}

		/**
		 * Actions recorded for the new hook.
		 *
		 * @return array
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

		private function meta_map( array $pairs ): array {
			$map = array();
			foreach ( $pairs as $pair ) {
				$map[ $pair[0] ] = $pair[1];
			}
			return $map;
		}

		public function test_log_submission_returns_event_id_and_the_derived_lead_id(): void {
			$logger = new ClickTrailEntryIdsLogger();

			$result = $logger->record( 'gravityforms', 7, array( 'ft_source' => 'google' ), 501 );

			$this->assertMatchesRegularExpression( '/^form_[0-9a-f]{32}$/', $result['event_id'] );
			$this->assertSame( 'lead_' . $result['event_id'], $result['lead_id'] );
		}

		public function test_lead_id_strips_a_leading_evt_prefix_like_the_browser(): void {
			$logger = new ClickTrailEntryIdsLogger();

			$this->assertSame( 'lead_abc123', $logger->lead_id_for( 'evt_abc123' ) );
			$this->assertSame( 'lead_form_abc123', $logger->lead_id_for( 'form_abc123' ) );
			$this->assertSame( 'lead_abc123', $logger->lead_id_for( 'lead_abc123' ) );
		}

		public function test_the_event_row_carries_event_id_lead_id_and_entry_id(): void {
			$logger = new ClickTrailEntryIdsLogger();

			$result = $logger->record( 'test', 3, array( 'ft_source' => 'google' ), 88 );
			$data   = json_decode( $this->db->rows[0]['data']['event_data'], true );

			$this->assertSame( $result['event_id'], $data['event_id'] );
			$this->assertSame( $result['lead_id'], $data['lead_id'] );
			$this->assertSame( '88', (string) $data['entry_id'] );
		}

		public function test_the_logged_action_fires_once_with_the_ids_and_payload(): void {
			$logger      = new ClickTrailEntryIdsLogger();
			$attribution = array( 'ft_source' => 'google' );

			$result = $logger->record( 'wpforms', 3, $attribution, 88 );
			$logger->record( 'wpforms', 3, $attribution, 88 );

			$actions = $this->logged_actions();
			$this->assertCount( 1, $actions, 'A duplicate call does not fire the action again.' );
			$this->assertSame(
				array( 'wpforms', 3, 88, $result['event_id'], $result['lead_id'], $attribution ),
				$actions[0]['args']
			);
		}

		public function test_a_duplicate_returns_the_same_ids(): void {
			$logger = new ClickTrailEntryIdsLogger();

			$first  = $logger->record( 'wpforms', 3, array( 'ft_source' => 'google' ), 88 );
			$second = $logger->record( 'wpforms', 3, array( 'ft_source' => 'google' ), 88 );

			$this->assertSame( $first['event_id'], $second['event_id'] );
			$this->assertSame( $first['lead_id'], $second['lead_id'] );
		}

		public function test_gravity_forms_entry_meta_includes_the_ids(): void {
			$adapter = new ClickTrailEntryIdsGf();
			$entry   = array( 'id' => 501 );
			$form    = array( 'id' => 7 );

			$adapter->on_submission( $entry, $form );

			$meta = $this->meta_map( array_map( static function ( $row ) {
				return array( $row[1], $row[2] );
			}, $GLOBALS['clicktrail_test_gf_meta'] ) );
			$data = json_decode( $this->db->rows[0]['data']['event_data'], true );

			$this->assertSame( $data['event_id'], $meta['ct_event_id'] );
			$this->assertSame( $data['lead_id'], $meta['ct_lead_id'] );
			$this->assertSame( 'google', $meta['ct_ft_source'] );
			$this->assertSame( array( 'gravityforms', 7, 501, $data['event_id'], $data['lead_id'] ), array_slice( $this->logged_actions()[0]['args'], 0, 5 ) );
		}

		public function test_gravity_forms_declares_the_id_keys_as_entry_meta(): void {
			$meta = ( new ClickTrailEntryIdsGf() )->register_entry_meta( array(), 7 );

			$this->assertArrayHasKey( 'ct_event_id', $meta );
			$this->assertArrayHasKey( 'ct_lead_id', $meta );
			$this->assertArrayHasKey( 'ct_ft_source', $meta );
		}

		public function test_gravity_forms_writes_meta_once_when_the_hook_repeats(): void {
			$adapter = new ClickTrailEntryIdsGf();

			$adapter->on_submission( array( 'id' => 501 ), array( 'id' => 7 ) );
			$count = count( $GLOBALS['clicktrail_test_gf_meta'] );
			$adapter->on_submission( array( 'id' => 501 ), array( 'id' => 7 ) );

			$this->assertCount( $count, $GLOBALS['clicktrail_test_gf_meta'] );
			$this->assertCount( 1, $this->db->rows );
		}

		public function test_wpforms_entry_meta_includes_the_ids(): void {
			$GLOBALS['clicktrail_test_wpforms'] = new ClickTrailEntryIdsWpformsDouble();
			$adapter                            = new ClickTrailEntryIdsWpforms();

			$adapter->on_submission( array(), array(), array( 'id' => 3 ), 88 );

			$rows = $GLOBALS['clicktrail_test_wpforms']->entry_meta->rows;
			$meta = array();
			foreach ( $rows as $row ) {
				$this->assertSame( 88, $row['entry_id'] );
				$this->assertSame( 3, $row['form_id'] );
				$meta[ $row['type'] ] = $row['data'];
			}
			$data = json_decode( $this->db->rows[0]['data']['event_data'], true );

			$this->assertSame( $data['event_id'], $meta['ct_event_id'] );
			$this->assertSame( $data['lead_id'], $meta['ct_lead_id'] );
			$this->assertSame( 'google', $meta['ct_ft_source'] );
		}

		public function test_fluent_submission_meta_includes_the_ids(): void {
			$fluent_db                            = new ClickTrailEntryIdsFluentDb();
			$GLOBALS['clicktrail_test_fluent_db'] = $fluent_db;
			$adapter                              = new ClickTrailEntryIdsFluent();

			$adapter->on_submission( 42, array( 'ct_ft_source' => 'google' ), (object) array( 'id' => 7 ) );

			$meta = array();
			foreach ( $fluent_db->rows as $insert ) {
				$this->assertSame( 'fluentform_submission_meta', $insert['table'] );
				$this->assertSame( 42, $insert['row']['response_id'] );
				$meta[ $insert['row']['meta_key'] ] = $insert['row']['value'];
			}
			$data = json_decode( $this->db->rows[0]['data']['event_data'], true );

			$this->assertSame( $data['event_id'], $meta['ct_event_id'] );
			$this->assertSame( $data['lead_id'], $meta['ct_lead_id'] );
			$this->assertSame( 'google', $meta['ct_ft_source'] );
		}

		public function test_ninja_forms_row_and_action_carry_the_ids(): void {
			$adapter = new ClickTrailEntryIdsNinja();

			$adapter->on_submission(
				array(
					'form_id' => 4,
					'actions' => array( 'save' => array( 'sub_id' => '77' ) ),
				)
			);

			$data    = json_decode( $this->db->rows[0]['data']['event_data'], true );
			$actions = $this->logged_actions();

			$this->assertNotEmpty( $data['event_id'] );
			$this->assertSame( 'lead_' . $data['event_id'], $data['lead_id'] );
			$this->assertCount( 1, $actions );
			$this->assertSame( array( 'ninjaforms', 4, 77, $data['event_id'], $data['lead_id'] ), array_slice( $actions[0]['args'], 0, 5 ) );
		}

		public function test_elementor_row_and_action_carry_the_ids_without_an_entry_id(): void {
			$adapter = new ClickTrailEntryIdsElementor();

			$adapter->on_submission( new ClickTrailEntryIdsRecord(), null );

			$data    = json_decode( $this->db->rows[0]['data']['event_data'], true );
			$actions = $this->logged_actions();

			$this->assertNotEmpty( $data['event_id'] );
			$this->assertSame( 'lead_' . $data['event_id'], $data['lead_id'] );
			$this->assertArrayNotHasKey( 'entry_id', $data );
			$this->assertCount( 1, $actions );
			$this->assertSame( array( 'elementor_forms', 'contact', null, $data['event_id'], $data['lead_id'] ), array_slice( $actions[0]['args'], 0, 5 ) );
		}

		public function test_denied_consent_writes_no_ids_and_fires_no_action(): void {
			$_COOKIE['production_consent']                   = 'denied';
			$GLOBALS['clicktrail_test_consent_required']     = true;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = true;

			( new ClickTrailEntryIdsGf() )->on_submission( array( 'id' => 501 ), array( 'id' => 7 ) );
			$logger = new ClickTrailEntryIdsLogger();
			$result = $logger->record( 'wpforms', 3, array( 'ft_source' => 'google' ), 88 );

			$this->assertSame( array(), $GLOBALS['clicktrail_test_gf_meta'] );
			$this->assertSame( array(), $result );
			$this->assertSame( array(), $this->db->rows );
			$this->assertSame( array(), $this->logged_actions() );
		}
	}
}
