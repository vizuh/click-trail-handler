<?php
/**
 * One `form_submission` row and one dispatch per form entry (#140).
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
	require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-event.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-dedup-store.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Core/class-attribution-provider.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/interface-form-adapter.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-abstract-form-adapter.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-fluent-forms-adapter.php';

	use CLICUTCL\Integrations\Forms\Abstract_Form_Adapter;
	use CLICUTCL\Integrations\Forms\Fluent_Forms_Adapter;
	use PHPUnit\Framework\TestCase;

	if ( ! function_exists( 'current_time' ) ) {
		function current_time( $type ) {
			return '2026-10-31 12:00:00';
		}
	}

	if ( ! function_exists( 'wpFluent' ) ) {
		function wpFluent() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Fluent Forms public API name.
			return $GLOBALS['clicktrail_test_fluent_db'];
		}
	}

	/**
	 * Records `$wpdb->insert()` calls.
	 */
	final class ClickTrailDedupeWpdbStub {
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
	final class ClickTrailDedupeFluentDb {
		public $rows  = array();
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
	 * Concrete adapter exposing the shared logger and a controllable clock.
	 */
	final class ClickTrailDedupeAdapter extends Abstract_Form_Adapter {
		public $now = 1000000000;

		public function is_active() { return true; }
		public function get_platform_name() { return 'test'; }
		public function register_hooks() {}
		public function populate_fields( $form_or_context ) { return $form_or_context; }
		public function on_submission( $arg1, $arg2 ) {}

		protected function submission_time() {
			return $this->now;
		}

		protected function resolve_identity_payload( $identity_input = array() ) {
			return $this->extract_identity_candidates( $identity_input );
		}

		public function record( $platform, $form_id, array $attribution, array $identity = array(), $entry_id = null ) {
			return $this->log_submission( $platform, $form_id, $attribution, $identity, $entry_id );
		}
	}

	/**
	 * Fluent adapter without the identity resolver stack.
	 */
	final class ClickTrailDedupeFluentAdapter extends Fluent_Forms_Adapter {
		protected function resolve_identity_payload( $identity_input = array() ) {
			return array();
		}
	}

	/**
	 * Class FormSubmissionDedupeTest
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class FormSubmissionDedupeTest extends TestCase {
		/**
		 * Row recorder.
		 *
		 * @var ClickTrailDedupeWpdbStub
		 */
		private $db;

		protected function setUp(): void {
			require_once dirname( __DIR__ ) . '/helpers/consent-mode-settings-stub.php';
			$_COOKIE = array();
			$_POST   = array();
			$GLOBALS['clicktrail_test_transients']           = array();
			$GLOBALS['clicktrail_test_dispatches']           = array();
			$GLOBALS['clicktrail_test_actions']              = array();
			$GLOBALS['clicktrail_test_consent_required']     = false;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = false;
			$this->db                                        = new ClickTrailDedupeWpdbStub();
			$GLOBALS['wpdb']                                 = $this->db;
		}

		protected function tearDown(): void {
			$GLOBALS['clicktrail_test_consent_required']     = false;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = false;
		}

		private function attribution( string $source = 'google' ): array {
			return array(
				'ft_source' => $source,
				'gclid'     => 'abc',
			);
		}

		public function test_same_provider_form_and_entry_logs_one_row_and_returns_the_first_event_id(): void {
			$adapter = new ClickTrailDedupeAdapter();

			$first  = $adapter->record( 'gravityforms', 7, $this->attribution(), array(), 501 );
			$second = $adapter->record( 'gravityforms', 7, $this->attribution(), array(), 501 );

			$this->assertNotEmpty( $first['event_id'] );
			$this->assertFalse( $first['duplicate'] );
			$this->assertTrue( $second['duplicate'] );
			$this->assertSame( $first['event_id'], $second['event_id'] );
			$this->assertCount( 1, $this->db->rows );
			$this->assertSame( 'form_submission', $this->db->rows[0]['data']['event_type'] );
			$this->assertCount( 1, $GLOBALS['clicktrail_test_dispatches'] );
			$this->assertSame( $first['event_id'], $GLOBALS['clicktrail_test_dispatches'][0][3]['event_id'] );
		}

		public function test_a_different_entry_form_or_provider_is_a_new_submission(): void {
			$adapter = new ClickTrailDedupeAdapter();

			$ids = array(
				$adapter->record( 'gravityforms', 7, $this->attribution(), array(), 501 )['event_id'],
				$adapter->record( 'gravityforms', 7, $this->attribution(), array(), 502 )['event_id'],
				$adapter->record( 'gravityforms', 8, $this->attribution(), array(), 501 )['event_id'],
				$adapter->record( 'wpforms', 7, $this->attribution(), array(), 501 )['event_id'],
			);

			$this->assertCount( 4, array_unique( $ids ) );
			$this->assertCount( 4, $this->db->rows );
			$this->assertCount( 4, $GLOBALS['clicktrail_test_dispatches'] );
		}

		public function test_entry_ids_dedupe_regardless_of_time(): void {
			$adapter = new ClickTrailDedupeAdapter();

			$first       = $adapter->record( 'wpforms', 3, $this->attribution(), array(), 9 );
			$adapter->now += 3600;
			$second      = $adapter->record( 'wpforms', 3, $this->attribution(), array(), 9 );

			$this->assertSame( $first['event_id'], $second['event_id'] );
			$this->assertCount( 1, $this->db->rows );
		}

		public function test_without_an_entry_id_the_same_posted_values_in_one_bucket_log_once(): void {
			$adapter = new ClickTrailDedupeAdapter();
			$posted  = array( 'your-email' => 'jane@example.test' );

			$first  = $adapter->record( 'contact-form-7', 12, $this->attribution(), $posted );
			$second = $adapter->record( 'contact-form-7', 12, $this->attribution(), $posted );

			$this->assertTrue( $second['duplicate'] );
			$this->assertSame( $first['event_id'], $second['event_id'] );
			$this->assertCount( 1, $this->db->rows );
			$this->assertCount( 1, $GLOBALS['clicktrail_test_dispatches'] );
		}

		public function test_without_an_entry_id_different_values_or_a_later_bucket_are_new_submissions(): void {
			$adapter = new ClickTrailDedupeAdapter();
			$posted  = array( 'your-email' => 'jane@example.test' );

			$adapter->record( 'contact-form-7', 12, $this->attribution(), $posted );
			$adapter->record( 'contact-form-7', 12, $this->attribution( 'bing' ), $posted );
			$adapter->record( 'contact-form-7', 12, $this->attribution(), array( 'your-email' => 'joe@example.test' ) );
			$adapter->record( 'contact-form-7', 13, $this->attribution(), $posted );
			$adapter->now += 10;
			$adapter->record( 'contact-form-7', 12, $this->attribution(), $posted );

			$this->assertCount( 5, $this->db->rows );
			$this->assertCount( 5, $GLOBALS['clicktrail_test_dispatches'] );
		}

		public function test_denied_consent_logs_nothing_and_returns_no_ids(): void {
			$_COOKIE['production_consent']                   = 'denied';
			$GLOBALS['clicktrail_test_consent_required']     = true;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = true;

			$result = ( new ClickTrailDedupeAdapter() )->record( 'gravityforms', 7, $this->attribution(), array(), 501 );

			$this->assertSame( array(), $result );
			$this->assertSame( array(), $this->db->rows );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_dispatches'] );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_transients'], 'A gated call must not mark the dedupe store.' );
		}

		public function test_fluent_logs_one_row_and_one_meta_set_when_the_hook_fires_twice(): void {
			$fluent_db                            = new ClickTrailDedupeFluentDb();
			$GLOBALS['clicktrail_test_fluent_db'] = $fluent_db;
			$_COOKIE                              = array( 'attribution' => wp_json_encode( array( 'ft_source' => 'google' ) ) );
			$adapter                              = new ClickTrailDedupeFluentAdapter();
			$form                                 = (object) array( 'id' => 7 );
			$posted                               = array( 'ct_ft_source' => 'google' );

			$adapter->on_submission( 42, $posted, $form );
			$adapter->on_submission( 42, $posted, $form );

			$this->assertCount( 1, $this->db->rows );
			$this->assertCount( 1, $GLOBALS['clicktrail_test_dispatches'] );
			$this->assertCount( 1, $fluent_db->rows, 'Attribution meta is written once per entry.' );

			$adapter->on_submission( 43, $posted, $form );
			$this->assertCount( 2, $this->db->rows, 'A new Fluent entry is a new submission.' );
		}
	}
}
