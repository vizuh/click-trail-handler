<?php
/**
 * Privacy export/erase of the events table matches a subject exactly.
 *
 * The SQL match is a substring prefilter (`LIKE '%b@x.com%'`, `"user_id":5`), so `b@x.com` also
 * hits `bob@x.com` and user 5 also hits user 55. Candidate rows are verified in PHP before they are
 * exported, deleted or used to reach WhatsApp reference rows.
 *
 * `$wpdb` is an in-memory fake that emulates the prefilter (case-insensitive substring) and the
 * statements the handler issues.
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

	require_once dirname( __DIR__, 2 ) . '/includes/Core/Storage/class-option-cache.php';
	require_once dirname( __DIR__, 2 ) . '/includes/database/class-whatsapp-ref-store.php';
	require_once dirname( __DIR__, 2 ) . '/includes/privacy/class-woo-order-privacy.php';
	require_once dirname( __DIR__, 2 ) . '/includes/privacy/class-edd-order-privacy.php';
	require_once dirname( __DIR__, 2 ) . '/includes/privacy/class-privacy-handler.php';

	if ( ! function_exists( 'esc_sql' ) ) {
		function esc_sql( $value ) {
			return $value;
		}
	}
	if ( ! function_exists( 'email_exists' ) ) {
		function email_exists( $email ) {
			return $GLOBALS['clicktrail_test_email_users'][ strtolower( (string) $email ) ] ?? false;
		}
	}

	use CLICUTCL\Privacy\Privacy_Handler;
	use PHPUnit\Framework\TestCase;

	/**
	 * In-memory `$wpdb` for the events table and the reference table.
	 */
	final class Fake_Privacy_Wpdb {
		public string $prefix     = 'wp_';
		public string $last_error = '';
		/** @var array<int,array<string,mixed>> */
		public array $events = array();
		/** @var array<int,string[]> Visitor IDs of every reference-table SELECT/DELETE. */
		public array $ref_selects = array();
		public array $ref_deletes = array();
		public int $event_selects = 0;

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

		public function get_var( $q ) {
			if ( is_array( $q ) && 0 === strpos( $q['sql'], 'SHOW TABLES LIKE' ) ) {
				$table = $q['args'][0];
				return in_array( $table, array( 'wp_clicutcl_events', 'wp_clicutcl_whatsapp_refs' ), true ) ? $table : null;
			}
			return null;
		}

		/**
		 * @param array<int,string> $patterns LIKE patterns.
		 */
		private function prefilter( array $row, array $patterns ): bool {
			foreach ( $patterns as $pattern ) {
				$needle = stripcslashes( trim( (string) $pattern, '%' ) );
				if ( false !== stripos( (string) $row['event_data'], $needle ) ) {
					return true;
				}
			}
			return false;
		}

		public function get_results( array $q, $output = null ): array {
			$sql  = $q['sql'];
			$args = $q['args'];
			if ( false !== strpos( $sql, 'clicutcl_whatsapp_refs' ) ) {
				$this->ref_selects[] = array_slice( $args, 1, -1 );
				return array();
			}
			if ( false === strpos( $sql, 'clicutcl_events' ) ) {
				return array();
			}
			++$this->event_selects;

			if ( false !== strpos( $sql, 'id > %d' ) ) { // Eraser: cursor scan.
				$last     = (int) $args[0];
				$limit    = (int) $args[ count( $args ) - 1 ];
				$patterns = array_slice( $args, 1, -1 );
				$out      = array();
				foreach ( $this->events as $row ) {
					if ( $row['id'] > $last && $this->prefilter( $row, $patterns ) ) {
						$out[] = array( 'id' => $row['id'], 'event_data' => $row['event_data'] );
					}
				}
				return array_slice( $out, 0, $limit );
			}

			// Export and visitor-ID walk: OFFSET paging.
			$offset   = (int) $args[ count( $args ) - 1 ];
			$limit    = (int) $args[ count( $args ) - 2 ];
			$patterns = array_slice( $args, 0, -2 );
			$out      = array();
			foreach ( $this->events as $row ) {
				if ( $this->prefilter( $row, $patterns ) ) {
					$out[] = $row;
				}
			}
			return array_slice( $out, $offset, $limit );
		}

		public function query( array $q ) {
			$sql  = $q['sql'];
			$args = $q['args'];
			if ( false !== strpos( $sql, 'clicutcl_whatsapp_refs' ) && false !== strpos( $sql, 'visitor_id IN' ) ) {
				$this->ref_deletes[] = $args;
				return 0;
			}
			if ( 0 === strpos( $sql, 'DELETE FROM' ) && false !== strpos( $sql, 'clicutcl_events' ) && false !== strpos( $sql, 'id IN' ) ) {
				$deleted = 0;
				foreach ( $this->events as $i => $row ) {
					if ( in_array( $row['id'], array_map( 'intval', $args ), true ) ) {
						unset( $this->events[ $i ] );
						++$deleted;
					}
				}
				return $deleted;
			}
			return 0;
		}

		/**
		 * @return int[] Remaining event IDs.
		 */
		public function event_ids(): array {
			return array_values( array_map( static fn( $r ) => $r['id'], $this->events ) );
		}
	}

	/**
	 * Class PrivacyEventMatchTest
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class PrivacyEventMatchTest extends TestCase {
		private Fake_Privacy_Wpdb $db;
		private int $next_id = 1;

		protected function setUp(): void {
			$this->db                                  = new Fake_Privacy_Wpdb();
			$GLOBALS['wpdb']                           = $this->db;
			$GLOBALS['clicktrail_test_email_users']    = array();
			$GLOBALS['clicktrail_test_transients']     = array();
			$GLOBALS['clicktrail_test_options']        = array();
		}

		private function add_event( array $payload ): int {
			$id             = $this->next_id++;
			$this->db->events[] = array(
				'id'         => $id,
				'event_type' => 'form_submission',
				'event_data' => json_encode( $payload ),
				'created_at' => '2026-01-01 00:00:00',
			);
			return $id;
		}

		private function export_event_ids( string $email, int $page = 1 ): array {
			$result = ( new Privacy_Handler() )->export_user_data( $email, $page );
			$ids    = array();
			foreach ( $result['data'] as $item ) {
				if ( 'clicutcl_events' === $item['group_id'] ) {
					$ids[] = (int) substr( $item['item_id'], strlen( 'clicutcl-event-' ) );
				}
			}
			sort( $ids );
			return array( $ids, $result['done'] );
		}

		// ----- Email -----

		public function test_export_does_not_return_a_suffix_lookalike_email(): void {
			$mine   = $this->add_event( array( 'platform' => 'cf7', 'identity' => array( 'email' => 'b@x.com' ) ) );
			$theirs = $this->add_event( array( 'platform' => 'cf7', 'identity' => array( 'email' => 'bob@x.com' ) ) );
			$this->add_event( array( 'platform' => 'cf7', 'identity' => array( 'email' => 'bob@x.com.au' ) ) );

			list( $ids, $done ) = $this->export_event_ids( 'b@x.com' );
			$this->assertSame( array( $mine ), $ids );
			$this->assertTrue( $done );
			$this->assertNotContains( $theirs, $ids );
		}

		public function test_email_equality_is_case_insensitive_and_nested(): void {
			$a = $this->add_event( array( 'identity' => array( 'email' => 'Bob@X.com' ) ) );
			$b = $this->add_event( array( 'attribution' => array( 'nested' => array( 'billing_email' => ' bob@x.com ' ) ) ) );
			$this->add_event( array( 'identity' => array( 'email' => 'alice@x.com' ) ) );

			list( $ids ) = $this->export_event_ids( 'bob@x.com' );
			$this->assertSame( array( $a, $b ), $ids );
		}

		public function test_eraser_deletes_only_the_exact_subject_rows(): void {
			$mine   = $this->add_event( array( 'identity' => array( 'email' => 'b@x.com' ) ) );
			$theirs = $this->add_event( array( 'identity' => array( 'email' => 'bob@x.com' ) ) );

			$result = ( new Privacy_Handler() )->erase_user_data( 'b@x.com' );
			$this->assertTrue( $result['items_removed'] );
			$this->assertTrue( $result['done'] );
			$this->assertSame( array( $theirs ), $this->db->event_ids() );
			$this->assertNotContains( $mine, $this->db->event_ids() );
		}

		public function test_a_lookalike_only_subject_erases_nothing(): void {
			$theirs = $this->add_event( array( 'identity' => array( 'email' => 'bob@x.com' ) ) );

			$result = ( new Privacy_Handler() )->erase_user_data( 'b@x.com' );
			$this->assertFalse( $result['items_removed'] );
			$this->assertTrue( $result['done'] );
			$this->assertSame( array( $theirs ), $this->db->event_ids() );
		}

		// ----- identity.hashed_email (what a form submission row actually stores) -----

		public function test_rows_holding_only_the_hashed_email_are_exported_and_erased_for_that_subject(): void {
			$mine   = $this->add_event( array( 'platform' => 'cf7', 'identity' => array( 'hashed_email' => hash( 'sha256', 'b@x.com' ), 'user_agent' => 'UA' ), 'attribution' => array( 'visitor_id' => 'vis_mine' ) ) );
			$theirs = $this->add_event( array( 'platform' => 'cf7', 'identity' => array( 'hashed_email' => hash( 'sha256', 'bob@x.com' ) ), 'attribution' => array( 'visitor_id' => 'vis_theirs' ) ) );

			list( $ids ) = $this->export_event_ids( 'b@x.com' );
			$this->assertSame( array( $mine ), $ids );
			$this->assertSame( array( array( 'vis_mine' ) ), $this->db->ref_selects );

			( new Privacy_Handler() )->erase_user_data( 'b@x.com' );
			$this->assertSame( array( $theirs ), $this->db->event_ids() );
			$this->assertSame( array( array( 'vis_mine' ) ), $this->db->ref_deletes );
		}

		public function test_the_email_is_normalised_like_the_identity_resolver_before_hashing(): void {
			// Identity_Resolver hashes strtolower( trim( email ) ) with plain sha256 and no salt.
			$row = $this->add_event( array( 'identity' => array( 'hashed_email' => hash( 'sha256', 'b@x.com' ) ) ) );
			list( $ids ) = $this->export_event_ids( 'B@X.com' );
			$this->assertSame( array( $row ), $ids );
		}

		public function test_the_hash_must_sit_in_a_hashed_email_field(): void {
			$this->add_event( array( 'note' => 'copied ' . hash( 'sha256', 'b@x.com' ) ) );
			$this->add_event( array( 'identity' => array( 'hashed_phone' => hash( 'sha256', 'b@x.com' ) ) ) );
			list( $ids ) = $this->export_event_ids( 'b@x.com' );
			$this->assertSame( array(), $ids );
		}

		// ----- user_id / user_hash -----

		public function test_user_id_5_does_not_match_user_id_55(): void {
			$GLOBALS['clicktrail_test_email_users']['five@x.com'] = 5;
			$int    = $this->add_event( array( 'params' => array( 'user_id' => 5 ) ) );
			$string = $this->add_event( array( 'user_id' => '5' ) );
			$this->add_event( array( 'params' => array( 'user_id' => 55 ) ) );
			$this->add_event( array( 'user_id' => '55' ) );
			$this->add_event( array( 'params' => array( 'user_id' => 155 ) ) );

			list( $ids ) = $this->export_event_ids( 'five@x.com' );
			$this->assertSame( array( $int, $string ), $ids );

			$result = ( new Privacy_Handler() )->erase_user_data( 'five@x.com' );
			$this->assertTrue( $result['items_removed'] );
			$this->assertSame( array( 3, 4, 5 ), $this->db->event_ids() );
		}

		public function test_user_hash_must_equal_the_users_hash(): void {
			$GLOBALS['clicktrail_test_email_users']['five@x.com'] = 5;
			$hash   = hash( 'sha256', '5' . wp_salt( 'auth' ) );
			$mine   = $this->add_event( array( 'params' => array( 'user_hash' => $hash ) ) );
			$this->add_event( array( 'params' => array( 'user_hash' => hash( 'sha256', '6' . wp_salt( 'auth' ) ) ) ) );
			// The hash sits in a field that is not `user_hash`: a substring prefilter hit, not the subject.
			$this->add_event( array( 'note' => 'x' . $hash ) );

			list( $ids ) = $this->export_event_ids( 'five@x.com' );
			$this->assertSame( array( $mine ), $ids );
		}

		// ----- WhatsApp reference path -----

		public function test_only_exact_rows_contribute_visitor_ids_to_the_whatsapp_ref_match(): void {
			$this->add_event( array( 'identity' => array( 'email' => 'b@x.com' ), 'attribution' => array( 'visitor_id' => 'vis_mine' ) ) );
			$this->add_event( array( 'identity' => array( 'email' => 'bob@x.com' ), 'attribution' => array( 'visitor_id' => 'vis_theirs' ) ) );

			( new Privacy_Handler() )->export_user_data( 'b@x.com' );
			$this->assertSame( array( array( 'vis_mine' ) ), $this->db->ref_selects, 'export looks up only the subject\'s visitor ID' );

			( new Privacy_Handler() )->erase_user_data( 'b@x.com' );
			$this->assertSame( array( array( 'vis_mine' ) ), $this->db->ref_deletes, 'erase deletes only the subject\'s reference rows' );
		}

		// ----- Paging -----

		public function test_export_paging_follows_the_fetched_page_not_the_verified_rows(): void {
			for ( $i = 0; $i < 50; $i++ ) {
				$this->add_event( array( 'identity' => array( 'email' => 'bob@x.com' ) ) );
			}
			$mine = $this->add_event( array( 'identity' => array( 'email' => 'b@x.com' ) ) );

			list( $first_ids, $first_done ) = $this->export_event_ids( 'b@x.com', 1 );
			$this->assertSame( array(), $first_ids, 'a full page of look-alikes exports nothing' );
			$this->assertFalse( $first_done, 'but the next page is still read' );

			list( $second_ids, $second_done ) = $this->export_event_ids( 'b@x.com', 2 );
			$this->assertSame( array( $mine ), $second_ids );
			$this->assertTrue( $second_done );
		}

		public function test_the_eraser_skips_lookalikes_and_terminates(): void {
			// 120 look-alikes first, so no page of candidates contains a verified row until the tail.
			for ( $i = 0; $i < 120; $i++ ) {
				$this->add_event( array( 'identity' => array( 'email' => 'bob@x.com' ) ) );
			}
			$mine = array( $this->add_event( array( 'identity' => array( 'email' => 'b@x.com' ) ) ), $this->add_event( array( 'identity' => array( 'email' => 'B@x.com' ) ) ) );

			$result = ( new Privacy_Handler() )->erase_user_data( 'b@x.com' );
			$this->assertTrue( $result['done'], 'the scan reached the end of the table' );
			$this->assertTrue( $result['items_removed'] );
			$this->assertCount( 120, $this->db->event_ids() );
			$this->assertSame( array(), array_intersect( $mine, $this->db->event_ids() ) );
			$this->assertLessThanOrEqual( 4, $this->db->event_selects, 'a bounded cursor walk, no re-reading the same page' );
		}

		public function test_the_eraser_reports_not_done_after_a_full_page_of_matches_and_finishes(): void {
			for ( $i = 0; $i < 120; $i++ ) {
				$this->add_event( array( 'identity' => array( 'email' => 'b@x.com' ) ) );
			}
			$handler = new Privacy_Handler();

			$first = $handler->erase_user_data( 'b@x.com', 1 );
			$this->assertFalse( $first['done'] );
			$this->assertCount( 70, $this->db->event_ids() );

			$second = $handler->erase_user_data( 'b@x.com', 2 );
			$this->assertFalse( $second['done'] );
			$this->assertCount( 20, $this->db->event_ids() );

			$third = $handler->erase_user_data( 'b@x.com', 3 );
			$this->assertTrue( $third['done'] );
			$this->assertSame( array(), $this->db->event_ids() );
		}
	}
}
