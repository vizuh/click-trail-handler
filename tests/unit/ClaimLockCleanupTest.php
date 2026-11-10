<?php
/**
 * Option-backed Claim_Lock rows: bounded stale cleanup and uninstall removal.
 *
 * `$wpdb` is an in-memory options table that understands the statements Claim_Lock issues,
 * including LIKE with escaped `_` / `%`.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

namespace {
	if ( ! defined( 'CLICUTCL_DIR' ) ) {
		define( 'CLICUTCL_DIR', dirname( __DIR__, 2 ) . '/' );
	}
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}

	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-claim-lock.php';

	if ( ! function_exists( 'esc_sql' ) ) {
		function esc_sql( $value ) {
			return $value;
		}
	}

	use CLICUTCL\Tracking\Claim_Lock;
	use PHPUnit\Framework\TestCase;

	/**
	 * In-memory options table.
	 */
	final class Fake_Options_Wpdb {
		public string $prefix  = 'wp_';
		public string $options = 'wp_options';
		/** @var array<string,string> option_name => option_value, in option_id order. */
		public array $rows = array();
		public int $examined_limit = 0;

		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}

		public function prepare( string $query, ...$args ): array {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			return array(
				'sql'  => $query,
				'args' => $args,
			);
		}

		private static function like( string $name, string $pattern ): bool {
			$regex = '';
			$len   = strlen( $pattern );
			for ( $i = 0; $i < $len; $i++ ) {
				$c = $pattern[ $i ];
				if ( '\\' === $c && $i + 1 < $len ) {
					$regex .= preg_quote( $pattern[ ++$i ], '/' );
				} elseif ( '%' === $c ) {
					$regex .= '.*';
				} elseif ( '_' === $c ) {
					$regex .= '.';
				} else {
					$regex .= preg_quote( $c, '/' );
				}
			}
			return 1 === preg_match( '/^' . $regex . '$/s', $name );
		}

		public function get_var( $q ) {
			if ( is_array( $q ) && 0 === strpos( $q['sql'], 'SELECT option_value' ) ) {
				return $this->rows[ $q['args'][0] ] ?? null;
			}
			return null; // No plugin tables exist.
		}

		public function get_results( array $q, $output = null ): array {
			$out = array();
			foreach ( $this->rows as $name => $value ) {
				if ( self::like( $name, $q['args'][0] ) || self::like( $name, $q['args'][1] ) ) {
					$out[] = array(
						'option_name'  => $name,
						'option_value' => $value,
					);
				}
			}
			$this->examined_limit = (int) $q['args'][2];
			return array_slice( $out, 0, (int) $q['args'][2] );
		}

		public function query( $q ) {
			if ( is_array( $q ) && 0 === strpos( $q['sql'], 'INSERT IGNORE' ) ) {
				if ( isset( $this->rows[ $q['args'][0] ] ) ) {
					return 0;
				}
				$this->rows[ $q['args'][0] ] = $q['args'][1];
				return 1;
			}
			if ( ! is_array( $q ) || 0 !== strpos( $q['sql'], 'DELETE FROM' ) ) {
				return 0;
			}
			$deleted = 0;
			foreach ( array_keys( $this->rows ) as $name ) {
				if ( self::like( $name, $q['args'][0] ) || self::like( $name, $q['args'][1] ) ) {
					unset( $this->rows[ $name ] );
					++$deleted;
				}
			}
			return $deleted;
		}

		public function delete( string $table, array $where ) {
			if ( isset( $this->rows[ $where['option_name'] ] ) && $this->rows[ $where['option_name'] ] === $where['option_value'] ) {
				unset( $this->rows[ $where['option_name'] ] );
				return 1;
			}
			return 0;
		}
	}

	/**
	 * Class ClaimLockCleanupTest
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class ClaimLockCleanupTest extends TestCase {
		private Fake_Options_Wpdb $db;

		protected function setUp(): void {
			$this->db                                  = new Fake_Options_Wpdb();
			$GLOBALS['wpdb']                           = $this->db;
			$GLOBALS['clicktrail_test_cache']          = array();
			$GLOBALS['clicktrail_test_filters']        = array();
			$GLOBALS['clicktrail_test_options']        = array();
			// One store: rows written through $wpdb are what get_option()/delete_option() see.
			$this->db->rows = &$GLOBALS['clicktrail_test_options'];
		}

		private function claim( int $age_seconds, string $token = 'tok' ): string {
			return $token . '|' . ( time() - $age_seconds );
		}

		private function seed(): void {
			$this->db->rows = array(
				'clicutcl_purchase_lock_1'        => $this->claim( 2 * DAY_IN_SECONDS ),
				'clicutcl_edd_purchase_lock_2'    => $this->claim( 3 * DAY_IN_SECONDS ),
				'clicutcl_um_signup_lock_3'       => $this->claim( 60 ),
				'clicutcl_form_claim_abc123'      => $this->claim( 2 * DAY_IN_SECONDS ),
				'clicutcl_form_claim_fresh'       => $this->claim( 10 ),
				'clicutcl_purchase_lock_nodate'   => 'garbage-without-time',
				'clicutcl_tracking_v2'            => 'a:0:{}',
				'clicutcl_dispatch_log'           => $this->claim( 9 * DAY_IN_SECONDS ),
				'clicutclXpurchaseXlockX9'        => $this->claim( 9 * DAY_IN_SECONDS ),
				'other_plugin_lock_1'             => $this->claim( 9 * DAY_IN_SECONDS ),
				'clicutcl_form_claimed_setting'   => $this->claim( 9 * DAY_IN_SECONDS ),
			);
		}

		public function test_a_second_claim_on_an_existing_row_loses_and_does_not_overwrite_it(): void {
			$first  = Claim_Lock::acquire( 'clicutcl_form_claim_race', 60 );
			$second = Claim_Lock::acquire( 'clicutcl_form_claim_race', 60 );

			$this->assertNotSame( '', $first );
			$this->assertSame( '', $second );
			$this->assertSame( $first, $this->db->rows['clicutcl_form_claim_race'] );
		}

		public function test_takeover_decides_on_the_live_row_not_a_cached_option(): void {
			// The live table diverges from what get_option() serves (a stale options cache) on purpose.
			$live                                                        = array( 'clicutcl_form_claim_x' => $this->claim( 600 ) );
			$this->db->rows                                              = &$live;
			$GLOBALS['clicktrail_test_options']['clicutcl_form_claim_x'] = $this->claim( 1, 'cached' );

			$won = Claim_Lock::acquire( 'clicutcl_form_claim_x', 60 );

			$this->assertNotSame( '', $won );
			$this->assertSame( $won, $this->db->rows['clicutcl_form_claim_x'] );
		}

		public function test_only_stale_claim_rows_are_deleted(): void {
			$this->seed();
			$this->assertSame( 3, Claim_Lock::purge_stale( DAY_IN_SECONDS ) );
			$this->assertSame(
				array(
					'clicutcl_um_signup_lock_3',
					'clicutcl_form_claim_fresh',
					'clicutcl_purchase_lock_nodate',
					'clicutcl_tracking_v2',
					'clicutcl_dispatch_log',
					'clicutclXpurchaseXlockX9',
					'other_plugin_lock_1',
					'clicutcl_form_claimed_setting',
				),
				array_keys( $this->db->rows )
			);
		}

		public function test_a_claim_taken_over_meanwhile_is_not_deleted(): void {
			$this->db->rows = array( 'clicutcl_purchase_lock_1' => $this->claim( 2 * DAY_IN_SECONDS ) );
			$stale          = $this->db->rows['clicutcl_purchase_lock_1'];
			// Another request re-claimed between the SELECT and the DELETE: the value differs now.
			$this->db->rows['clicutcl_purchase_lock_1'] = $this->claim( 1, 'new' );
			$this->assertSame( 0, Claim_Lock::purge_stale( DAY_IN_SECONDS ) );
			$this->assertNotSame( $stale, $this->db->rows['clicutcl_purchase_lock_1'] );
		}

		public function test_one_call_examines_a_bounded_number_of_rows_oldest_first(): void {
			for ( $i = 1; $i <= 10; $i++ ) {
				$this->db->rows[ 'clicutcl_purchase_lock_' . $i ] = $this->claim( 2 * DAY_IN_SECONDS );
			}
			$this->assertSame( 4, Claim_Lock::purge_stale( DAY_IN_SECONDS, 4 ) );
			$this->assertSame( 4, $this->db->examined_limit );
			$this->assertCount( 6, $this->db->rows );
			$this->assertArrayNotHasKey( 'clicutcl_purchase_lock_4', $this->db->rows );
			$this->assertArrayHasKey( 'clicutcl_purchase_lock_5', $this->db->rows );
			$this->assertSame( 6, Claim_Lock::purge_stale( DAY_IN_SECONDS, 500 ) );
		}

		public function test_nothing_stale_means_nothing_deleted(): void {
			$this->db->rows = array( 'clicutcl_purchase_lock_1' => $this->claim( 5 ) );
			$this->assertSame( 0, Claim_Lock::purge_stale( DAY_IN_SECONDS ) );
			$this->assertCount( 1, $this->db->rows );
		}

		public function test_the_daily_cleanup_runs_the_claim_purge_even_without_the_events_table(): void {
			require_once dirname( __DIR__, 2 ) . '/includes/Core/Storage/class-option-cache.php';
			require_once dirname( __DIR__, 2 ) . '/includes/settings/class-attribution-settings.php';
			require_once dirname( __DIR__, 2 ) . '/includes/database/class-whatsapp-ref-store.php';
			require_once dirname( __DIR__, 2 ) . '/includes/privacy/class-woo-order-privacy.php';
			require_once dirname( __DIR__, 2 ) . '/includes/privacy/class-edd-order-privacy.php';
			require_once dirname( __DIR__, 2 ) . '/includes/privacy/class-user-registration-privacy.php';
			require_once dirname( __DIR__, 2 ) . '/includes/utils/class-cleanup.php';

			$this->db->rows = array(
				'clicutcl_purchase_lock_1' => $this->claim( 2 * DAY_IN_SECONDS ),
				'clicutcl_purchase_lock_2' => $this->claim( 5 ),
			);
			( new \CLICUTCL\Utils\Cleanup() )->run_cleanup();
			$this->assertSame( array( 'clicutcl_purchase_lock_2' ), array_keys( $this->db->rows ) );
		}

		// ----- daily cleanup schedule -----

		/**
		 * Load Cleanup with a WP-cron stub that records schedule calls.
		 *
		 * @param array<string,int> $scheduled Hook => next run already in cron.
		 */
		private function cleanup_with_cron( array $scheduled ): void {
			$GLOBALS['clicktrail_test_cron']           = $scheduled;
			$GLOBALS['clicktrail_test_cron_scheduled'] = array();
			if ( ! function_exists( 'wp_next_scheduled' ) ) {
				function wp_next_scheduled( $hook ) {
					return $GLOBALS['clicktrail_test_cron'][ $hook ] ?? false;
				}
			}
			if ( ! function_exists( 'wp_schedule_event' ) ) {
				function wp_schedule_event( $timestamp, $recurrence, $hook ) {
					$GLOBALS['clicktrail_test_cron'][ $hook ]    = $timestamp;
					$GLOBALS['clicktrail_test_cron_scheduled'][] = array( $hook, $recurrence );
					return true;
				}
			}
			require_once dirname( __DIR__, 2 ) . '/includes/utils/class-cleanup.php';
		}

		public function test_register_schedules_the_missing_daily_cleanup_once(): void {
			$this->cleanup_with_cron( array() );

			( new \CLICUTCL\Utils\Cleanup() )->register();
			$this->assertNotFalse( wp_next_scheduled( 'clicutcl_daily_cleanup' ) );

			// The check runs once per request: a second register() does not re-read cron.
			$GLOBALS['clicktrail_test_cron'] = array();
			( new \CLICUTCL\Utils\Cleanup() )->register();

			$this->assertSame( array( array( 'clicutcl_daily_cleanup', 'daily' ) ), $GLOBALS['clicktrail_test_cron_scheduled'] );
		}

		public function test_register_leaves_an_existing_daily_cleanup_alone(): void {
			$this->cleanup_with_cron( array( 'clicutcl_daily_cleanup' => 123 ) );

			( new \CLICUTCL\Utils\Cleanup() )->register();

			$this->assertSame( array(), $GLOBALS['clicktrail_test_cron_scheduled'] );
			$this->assertSame( 123, wp_next_scheduled( 'clicutcl_daily_cleanup' ) );
		}

		// ----- uninstall.php -----

		/**
		 * Include uninstall.php against the fake database.
		 */
		private function run_uninstall( bool $preserve ): void {
			if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
				define( 'WP_UNINSTALL_PLUGIN', 'click-trail-handler/clicutcl.php' );
			}
			if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
				function wp_clear_scheduled_hook( $hook ) {
					return 0;
				}
			}
			$GLOBALS['clicktrail_test_filters']['clicutcl_preserve_data_on_uninstall'] = static fn() => $preserve;
			require dirname( __DIR__, 2 ) . '/uninstall.php';
		}

		public function test_uninstall_removes_claim_rows_and_only_claim_rows_among_lookalikes(): void {
			$this->seed();
			$this->run_uninstall( false );
			$this->assertSame(
				array( 'clicutclXpurchaseXlockX9', 'other_plugin_lock_1', 'clicutcl_form_claimed_setting' ),
				array_keys( $this->db->rows ),
				'fresh and stale claims and the plugin settings are gone; lookalikes the claim patterns must not match stay'
			);
		}

		public function test_uninstall_keeps_claim_rows_when_data_is_preserved(): void {
			$this->seed();
			$before = $this->db->rows;
			$this->run_uninstall( true );
			$this->assertSame( $before, $this->db->rows );
		}
	}
}
