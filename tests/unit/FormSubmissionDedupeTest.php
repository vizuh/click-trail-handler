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
				if ( ! empty( $GLOBALS['clicktrail_test_dispatch_throws'] ) ) {
					throw new \RuntimeException( 'collector_down' );
				}
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
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-claim-lock.php';
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
	class ClickTrailDedupeWpdbStub {
		use \Claim_Options_Wpdb;

		public $prefix  = 'wp_';
		public $options = 'wp_options';
		public $rows    = array();

		/**
		 * Optional hook run inside insert(), to interleave a rival request.
		 *
		 * @var callable|null
		 */
		public $on_insert = null;

		/**
		 * Value insert() reports.
		 *
		 * @var int|false
		 */
		public $insert_result = 1;

		public function insert( $table, $data ) {
			if ( is_callable( $this->on_insert ) ) {
				$hook            = $this->on_insert;
				$this->on_insert = null;
				$hook();
			}
			if ( false === $this->insert_result ) {
				return false;
			}
			$this->rows[] = array( 'table' => $table, 'data' => $data );
			return 1;
		}

		/**
		 * Row-conditional delete: only when name and exact value match.
		 */
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
	 * Records Fluent submission-meta inserts.
	 */
	final class ClickTrailDedupeFluentDb {
		public $rows    = array();
		private $table   = '';
		private $filters = array();

		public function table( string $table ): self {
			$this->table   = $table;
			$this->filters = array();
			return $this;
		}

		public function where( string $column, $value ): self {
			$this->filters[ $column ] = $value;
			return $this;
		}

		public function first() {
			foreach ( $this->rows as $insert ) {
				if ( $insert['table'] === $this->table && array_intersect_assoc( $this->filters, $insert['row'] ) === $this->filters ) {
					return (object) $insert['row'];
				}
			}
			return null;
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
			$GLOBALS['clicktrail_test_options']              = array();
			$GLOBALS['clicktrail_test_cache']                = array();
			$GLOBALS['clicktrail_test_ext_object_cache']     = false;
			unset( $GLOBALS['clicktrail_test_dispatch_throws'] );
			unset( $GLOBALS['clicktrail_test_wp_salt'] );
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

		public function test_event_id_is_an_hmac_of_the_dedupe_key_with_the_auth_salt(): void {
			$adapter = new ClickTrailDedupeAdapter();

			$result   = $adapter->record( 'gravityforms', 7, $this->attribution(), array(), 501 );
			$expected = 'form_' . substr( hash_hmac( 'sha256', '1|gravityforms|7|entry|501', 'test-salt-auth' ), 0, 32 );

			$this->assertSame( $expected, $result['event_id'] );
		}

		public function test_same_inputs_give_the_same_id_and_a_different_salt_a_different_one(): void {
			$posted = array( 'your-email' => 'jane@example.test' );

			$first = ( new ClickTrailDedupeAdapter() )->record( 'contact-form-7', 12, $this->attribution(), $posted )['event_id'];
			$GLOBALS['clicktrail_test_transients'] = array();
			$same = ( new ClickTrailDedupeAdapter() )->record( 'contact-form-7', 12, $this->attribution(), $posted )['event_id'];
			$GLOBALS['clicktrail_test_transients'] = array();
			$GLOBALS['clicktrail_test_wp_salt']    = 'another-site-salt';
			$other = ( new ClickTrailDedupeAdapter() )->record( 'contact-form-7', 12, $this->attribution(), $posted )['event_id'];

			$this->assertSame( $first, $same );
			$this->assertNotSame( $first, $other );
		}

		public function test_a_raw_or_plainly_hashed_email_or_phone_never_appears_in_the_id_or_store_keys(): void {
			$email  = 'jane@example.test';
			$phone  = '+351912345678';
			$result = ( new ClickTrailDedupeAdapter() )->record( 'contact-form-7', 12, $this->attribution(), array( 'your-email' => $email, 'your-phone' => $phone ) );

			$haystack = $result['event_id'] . ' ' . implode( ' ', array_keys( $GLOBALS['clicktrail_test_transients'] ) ) . ' ' . wp_json_encode( $GLOBALS['clicktrail_test_transients'] );
			foreach ( array( $email, $phone ) as $secret ) {
				foreach ( array( $secret, sha1( $secret ), md5( $secret ), hash( 'sha256', $secret ) ) as $needle ) {
					$this->assertStringNotContainsString( $needle, $haystack );
				}
			}
		}

		public function test_form_checks_do_not_count_toward_dedup_hit_miss_stats(): void {
			$adapter = new ClickTrailDedupeAdapter();

			$adapter->record( 'gravityforms', 7, $this->attribution(), array(), 501 );
			$adapter->record( 'gravityforms', 7, $this->attribution(), array(), 501 );

			$this->assertSame( 0, \CLICUTCL\Tracking\Dedup_Store::get_stats()['total_checks'] );
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
			$adapter->now += 30;
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
			$this->assertCount( 3, $fluent_db->rows, 'One attribution key plus the two ID keys, written once per entry.' );

			$adapter->on_submission( 43, $posted, $form );
			$this->assertCount( 2, $this->db->rows, 'A new Fluent entry is a new submission.' );
		}

		/**
		 * Backends for the claim: option row (no object cache) and `wp_cache_add` (persistent cache).
		 *
		 * @return array<string,array{bool}>
		 */
		public static function claim_backends(): array {
			return array(
				'option row'   => array( false ),
				'object cache' => array( true ),
			);
		}

		private function claim_leftovers(): array {
			return array_merge(
				array_keys( $GLOBALS['clicktrail_test_options'] ),
				array_keys( $GLOBALS['clicktrail_test_cache']['clicutcl'] ?? array() )
			);
		}

		/**
		 * @dataProvider claim_backends
		 */
		public function test_a_second_call_entering_between_claim_and_mark_logs_and_dispatches_once( bool $ext_cache ): void {
			$GLOBALS['clicktrail_test_ext_object_cache'] = $ext_cache;
			$adapter                                     = new ClickTrailDedupeAdapter();
			$rival                                       = null;

			$this->db->on_insert = function () use ( $adapter, &$rival ) {
				$rival = $adapter->record( 'gravityforms', 7, $this->attribution(), array(), 501 );
			};

			$winner = $adapter->record( 'gravityforms', 7, $this->attribution(), array(), 501 );

			$this->assertCount( 1, $this->db->rows, 'Exactly one row.' );
			$this->assertCount( 1, $GLOBALS['clicktrail_test_dispatches'], 'Exactly one dispatch.' );
			$this->assertFalse( $winner['duplicate'] );
			$this->assertTrue( $rival['duplicate'] );
			$this->assertSame( $winner['event_id'], $rival['event_id'], 'The loser returns the winner\'s deterministic IDs.' );
			$this->assertSame( $winner['lead_id'], $rival['lead_id'] );
			$this->assertSame( array(), $this->claim_leftovers(), 'The claim is released once the marker is kept.' );
			$this->assertTrue( $adapter->record( 'gravityforms', 7, $this->attribution(), array(), 501 )['duplicate'], 'The marker still dedupes later repeats.' );
			$this->assertCount( 1, $this->db->rows );
		}

		public function test_the_same_race_on_the_best_effort_key_logs_once(): void {
			$adapter = new ClickTrailDedupeAdapter();

			$this->db->on_insert = function () use ( $adapter ) {
				$adapter->record( 'contact-form-7', 12, $this->attribution(), array( 'your-email' => 'jane@example.test' ) );
			};

			$adapter->record( 'contact-form-7', 12, $this->attribution(), array( 'your-email' => 'jane@example.test' ) );

			$this->assertCount( 1, $this->db->rows );
			$this->assertCount( 1, $GLOBALS['clicktrail_test_dispatches'] );
		}

		/**
		 * @dataProvider claim_backends
		 */
		public function test_a_dispatch_exception_releases_the_claim_and_leaves_no_marker( bool $ext_cache ): void {
			$GLOBALS['clicktrail_test_ext_object_cache'] = $ext_cache;
			$adapter                                     = new ClickTrailDedupeAdapter();

			$GLOBALS['clicktrail_test_dispatch_throws'] = true;
			try {
				$adapter->record( 'gravityforms', 7, $this->attribution(), array(), 501 );
				$this->fail( 'The dispatch exception must propagate.' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'collector_down', $e->getMessage() );
			}

			$this->assertSame( array(), $this->claim_leftovers(), 'Claim released on failure.' );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_transients'], 'No dedupe marker after a failed dispatch.' );

			unset( $GLOBALS['clicktrail_test_dispatch_throws'] );
			$retry = $adapter->record( 'gravityforms', 7, $this->attribution(), array(), 501 );

			$this->assertFalse( $retry['duplicate'], 'The retry proceeds.' );
			$this->assertCount( 2, $this->db->rows, 'The failed attempt row plus the retry row.' );
		}

		/**
		 * @dataProvider claim_backends
		 */
		public function test_a_failed_insert_releases_the_claim_and_leaves_no_marker( bool $ext_cache ): void {
			$GLOBALS['clicktrail_test_ext_object_cache'] = $ext_cache;
			$this->db->insert_result                     = false;
			$adapter                                     = new ClickTrailDedupeAdapter();

			$adapter->record( 'gravityforms', 7, $this->attribution(), array(), 501 );

			$this->assertSame( array(), $this->claim_leftovers() );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_transients'] );

			$this->db->insert_result = 1;
			$this->assertFalse( $adapter->record( 'gravityforms', 7, $this->attribution(), array(), 501 )['duplicate'] );
			$this->assertCount( 1, $this->db->rows );
		}

		/**
		 * @dataProvider claim_backends
		 */
		public function test_a_held_claim_makes_the_caller_a_loser_that_writes_nothing_and_releases_nothing( bool $ext_cache ): void {
			$GLOBALS['clicktrail_test_ext_object_cache'] = $ext_cache;
			$adapter                                     = new ClickTrailDedupeAdapter();
			$key                                         = Abstract_Form_Adapter::SUBMISSION_CLAIM_PREFIX . md5( 'gravityforms|' . $this->fingerprint_for( $adapter ) );

			if ( $ext_cache ) {
				$GLOBALS['clicktrail_test_cache']['clicutcl'][ $key ] = 'rival';
			} else {
				$GLOBALS['clicktrail_test_options'][ $key ] = 'rival|' . time();
			}

			$result = $adapter->record( 'gravityforms', 7, $this->attribution(), array(), 501 );

			$this->assertTrue( $result['duplicate'] );
			$this->assertNotEmpty( $result['event_id'] );
			$this->assertSame( array(), $this->db->rows );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_dispatches'] );
			$this->assertSame( array( $key ), $this->claim_leftovers(), 'A loser must not release the winner\'s claim.' );
		}

		public function test_a_stale_option_claim_is_taken_over(): void {
			$adapter = new ClickTrailDedupeAdapter();
			$key     = Abstract_Form_Adapter::SUBMISSION_CLAIM_PREFIX . md5( 'gravityforms|' . $this->fingerprint_for( $adapter ) );

			$GLOBALS['clicktrail_test_options'][ $key ] = 'dead|' . ( time() - 31 );

			$result = $adapter->record( 'gravityforms', 7, $this->attribution(), array(), 501 );

			$this->assertFalse( $result['duplicate'] );
			$this->assertCount( 1, $this->db->rows );
			$this->assertSame( array(), $this->claim_leftovers() );
		}

		public function test_stale_takeover_loses_when_a_rival_changed_the_row_first(): void {
			$adapter = new ClickTrailDedupeAdapter();
			$key     = Abstract_Form_Adapter::SUBMISSION_CLAIM_PREFIX . md5( 'gravityforms|' . $this->fingerprint_for( $adapter ) );
			$fresh   = 'rival|' . time();

			$GLOBALS['clicktrail_test_options'][ $key ] = 'dead|' . ( time() - 31 );

			// A rival re-claims the stale row between our read and our conditional delete.
			$db     = new class( $key, $fresh ) extends ClickTrailDedupeWpdbStub {
				private $key;
				private $fresh;

				public function __construct( $key, $fresh ) {
					$this->key   = $key;
					$this->fresh = $fresh;
				}

				public function delete( $table, $where ) {
					$GLOBALS['clicktrail_test_options'][ $this->key ] = $this->fresh;
					return parent::delete( $table, $where );
				}
			};
			$GLOBALS['wpdb'] = $db;

			$result = $adapter->record( 'gravityforms', 7, $this->attribution(), array(), 501 );

			$this->assertTrue( $result['duplicate'], 'The takeover lost, so this call is a loser.' );
			$this->assertSame( array(), $db->rows );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_dispatches'] );
			$this->assertSame( $fresh, $GLOBALS['clicktrail_test_options'][ $key ], 'The rival\'s claim survives.' );
		}

		public function test_gated_consent_takes_no_claim(): void {
			$GLOBALS['clicktrail_test_consent_required']     = true;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = true;

			( new ClickTrailDedupeAdapter() )->record( 'gravityforms', 7, $this->attribution(), array(), 501 );

			$this->assertSame( array(), $this->claim_leftovers() );
			$this->assertSame( array(), $this->db->rows );
		}

		/**
		 * Dedupe fingerprint the adapter derives for the gravityforms/7/501 submission.
		 *
		 * @param ClickTrailDedupeAdapter $adapter Adapter.
		 * @return string
		 */
		private function fingerprint_for( ClickTrailDedupeAdapter $adapter ): string {
			$method = new \ReflectionMethod( Abstract_Form_Adapter::class, 'submission_keys' );
			$method->setAccessible( true );
			return $method->invoke( $adapter, 'gravityforms', 7, $this->attribution(), array(), 501 )['fingerprint'];
		}

		/**
		 * Claim key for the best-effort CF7 form 12 submission.
		 *
		 * @param ClickTrailDedupeAdapter $adapter Adapter.
		 * @param array                   $posted  Posted values.
		 * @return string
		 */
		private function best_effort_claim_key( ClickTrailDedupeAdapter $adapter, array $posted ): string {
			$method = new \ReflectionMethod( Abstract_Form_Adapter::class, 'submission_keys' );
			$method->setAccessible( true );
			$keys = $method->invoke( $adapter, 'contact-form-7', 12, $this->attribution(), $posted, null );
			return Abstract_Form_Adapter::SUBMISSION_CLAIM_PREFIX . md5( 'contact-form-7|' . $keys['claim'] );
		}

		public function test_best_effort_repeat_in_the_adjacent_bucket_is_a_duplicate_with_the_earlier_ids(): void {
			$adapter      = new ClickTrailDedupeAdapter();
			$posted       = array( 'your-email' => 'jane@example.test' );
			$adapter->now = 1000000019;
			$first        = $adapter->record( 'contact-form-7', 12, $this->attribution(), $posted );
			$adapter->now = 1000000021;
			$second       = $adapter->record( 'contact-form-7', 12, $this->attribution(), $posted );

			$this->assertFalse( $first['duplicate'] );
			$this->assertTrue( $second['duplicate'] );
			$this->assertSame( $first['event_id'], $second['event_id'] );
			$this->assertSame( $first['lead_id'], $second['lead_id'] );
			$this->assertCount( 1, $this->db->rows );
			$this->assertCount( 1, $GLOBALS['clicktrail_test_dispatches'] );
		}

		public function test_best_effort_in_flight_claim_blocks_a_repeat_in_the_next_bucket(): void {
			$adapter      = new ClickTrailDedupeAdapter();
			$posted       = array( 'your-email' => 'jane@example.test' );
			$adapter->now = 1000000019;
			$GLOBALS['clicktrail_test_options'][ $this->best_effort_claim_key( $adapter, $posted ) ] = 'rival|' . time();

			$adapter->now = 1000000021;
			$result       = $adapter->record( 'contact-form-7', 12, $this->attribution(), $posted );

			$this->assertTrue( $result['duplicate'] );
			$this->assertSame( array(), $this->db->rows );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_dispatches'] );
		}

		public function test_best_effort_repeat_after_two_buckets_is_a_new_submission(): void {
			$adapter      = new ClickTrailDedupeAdapter();
			$posted       = array( 'your-email' => 'jane@example.test' );
			$adapter->now = 1000000019;
			$first        = $adapter->record( 'contact-form-7', 12, $this->attribution(), $posted );
			$adapter->now = 1000000041;
			$second       = $adapter->record( 'contact-form-7', 12, $this->attribution(), $posted );

			$this->assertFalse( $second['duplicate'] );
			$this->assertNotSame( $first['event_id'], $second['event_id'] );
			$this->assertCount( 2, $this->db->rows );
		}

		public function test_best_effort_adjacent_bucket_with_different_values_is_not_a_duplicate(): void {
			$adapter      = new ClickTrailDedupeAdapter();
			$adapter->now = 1000000019;
			$adapter->record( 'contact-form-7', 12, $this->attribution(), array( 'your-email' => 'jane@example.test' ) );
			$adapter->now = 1000000021;
			$second       = $adapter->record( 'contact-form-7', 12, $this->attribution(), array( 'your-email' => 'joe@example.test' ) );

			$this->assertFalse( $second['duplicate'] );
			$this->assertCount( 2, $this->db->rows );
		}
	}
}
