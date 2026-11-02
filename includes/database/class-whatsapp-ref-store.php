<?php
/**
 * WhatsApp reference-code store.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Database;

use CLICUTCL\Settings\Attribution_Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps a short `CT-<code>` reference, sent in a pre-filled WhatsApp message, back to the
 * attribution snapshot of the visitor who clicked (`{prefix}clicutcl_whatsapp_refs`).
 *
 * The builders are pure (no `$wpdb`), so the validation, snapshot shape and TTL are unit-testable;
 * create()/find()/purge_expired()/delete_by_visitor_ids() are the thin `$wpdb` wrappers.
 */
class Whatsapp_Ref_Store {

	/**
	 * Table suffix after `$wpdb->prefix`.
	 */
	public const TABLE_SUFFIX = 'clicutcl_whatsapp_refs';

	/**
	 * Click-ID keys a snapshot may select from (the D3 selection order).
	 *
	 * @var string[]
	 */
	public const SELECTABLE_CLICK_IDS = array( 'gclid', 'wbraid', 'gbraid', 'fbclid', 'ttclid', 'msclkid', 'twclid', 'li_fat_id', 'sccid', 'epik' );

	/**
	 * Default ceiling on rows in the table; a public route writes here, so it must be bounded.
	 */
	private const DEFAULT_MAX_ROWS = 50000;

	/**
	 * Default ceiling on live rows per `visitor_id`.
	 */
	private const DEFAULT_MAX_PER_VISITOR = 20;

	/**
	 * Transient holding the cached table row count.
	 */
	private const COUNT_TRANSIENT = 'clicutcl_whatsapp_refs_count';

	/**
	 * Seconds the cached row count lives; inserts bump it and purges drop it.
	 */
	private const COUNT_TTL = 300;

	/**
	 * Rows deleted per purge statement.
	 */
	private const PURGE_BATCH = 1000;

	/**
	 * Purge statements per cleanup run.
	 */
	private const PURGE_MAX_BATCHES = 20;

	/**
	 * Table name for the current site.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Normalise a code typed or pasted by a person: optional `CT-` prefix, any case, exactly 8 base32
	 * characters. Returns '' when it is not a valid code.
	 *
	 * @param string $raw Raw code.
	 * @return string Upper-case code without prefix, or ''.
	 */
	public static function normalize_code( string $raw ): string {
		$code = strtoupper( trim( $raw ) );
		if ( 0 === strpos( $code, 'CT-' ) ) {
			$code = substr( $code, 3 );
		}

		return 1 === preg_match( '/^[A-Z2-7]{8}$/D', $code ) ? $code : '';
	}

	/**
	 * Whether a client-supplied code is already in canonical form (upper case, no prefix).
	 *
	 * @param string $code Code.
	 * @return bool
	 */
	public static function is_canonical_code( string $code ): bool {
		return 1 === preg_match( '/^[A-Z2-7]{8}$/D', $code );
	}

	/**
	 * Retention (days) the rows live: the attribution cookie duration, like the daily cleanup.
	 *
	 * @return int
	 */
	public static function retention_days(): int {
		$days = ( new Attribution_Settings() )->get_cookie_duration();
		return $days < 1 ? 90 : $days;
	}

	/**
	 * Arrange sanitised flat attribution keys into the stored snapshot shape.
	 *
	 * @param array  $data         Output of the signed-token sanitiser (`ft_*`, `lt_*`, click IDs).
	 * @param string $selected_key Click-ID key the client selected.
	 * @return array{snapshot:array,selected_click_id_key:string,selected_click_id:string}
	 */
	public static function build_snapshot( array $data, string $selected_key ): array {
		$snapshot = array(
			'first_touch' => array(),
			'last_touch'  => array(),
			'click_ids'   => array(),
		);

		foreach ( $data as $key => $value ) {
			$key   = (string) $key;
			$value = (string) $value;
			if ( 0 === strpos( $key, 'ft_' ) ) {
				$snapshot['first_touch'][ substr( $key, 3 ) ] = $value;
			} elseif ( 0 === strpos( $key, 'lt_' ) ) {
				$snapshot['last_touch'][ substr( $key, 3 ) ] = $value;
			} elseif ( in_array( $key, self::SELECTABLE_CLICK_IDS, true ) || in_array( $key, array( 'rdt_cid', 'pin_cid', 'snap_cid', 'mc_cid', 'mc_eid', 'dclid' ), true ) ) {
				$snapshot['click_ids'][ $key ] = $value;
			}
		}

		$selected_key = in_array( $selected_key, self::SELECTABLE_CLICK_IDS, true ) ? $selected_key : '';
		if ( '' !== $selected_key && empty( $snapshot['click_ids'][ $selected_key ] ) ) {
			$selected_key = '';
		}

		return array(
			'snapshot'              => $snapshot,
			'selected_click_id_key' => $selected_key,
			'selected_click_id'     => '' !== $selected_key ? $snapshot['click_ids'][ $selected_key ] : '',
		);
	}

	/**
	 * Build the row to insert, or null when there is nothing worth mapping or the code is invalid.
	 *
	 * @param string $code           Canonical code.
	 * @param array  $data           Sanitised flat attribution keys.
	 * @param string $selected_key   Selected click-ID key.
	 * @param string $visitor_id     `ct_visitor_id` of the browser.
	 * @param int    $blog_id        Current blog ID.
	 * @param int    $retention_days Retention in days (TTL).
	 * @param int    $now            Unix timestamp of creation.
	 * @return array<string,mixed>|null
	 */
	public static function build_row( string $code, array $data, string $selected_key, string $visitor_id, int $blog_id, int $retention_days, int $now ): ?array {
		if ( ! self::is_canonical_code( $code ) || empty( $data ) ) {
			return null;
		}

		$built = self::build_snapshot( $data, $selected_key );
		$json  = wp_json_encode( $built['snapshot'] );
		if ( ! is_string( $json ) ) {
			return null;
		}

		return array(
			'blog_id'               => $blog_id,
			'code'                  => $code,
			'visitor_id'            => substr( sanitize_text_field( $visitor_id ), 0, 64 ),
			'selected_click_id_key' => $built['selected_click_id_key'],
			'selected_click_id'     => substr( $built['selected_click_id'], 0, 128 ),
			'snapshot'              => $json,
			'created_at'            => gmdate( 'Y-m-d H:i:s', $now ),
			'expires_at'            => gmdate( 'Y-m-d H:i:s', $now + max( 1, $retention_days ) * DAY_IN_SECONDS ),
		);
	}

	/**
	 * Insert a row; the unique `(blog_id, code)` key makes a duplicate code a no-op.
	 *
	 * Two ceilings come first, so a public route cannot fill the table: live rows per `visitor_id`
	 * (`clicutcl_whatsapp_ref_max_per_visitor`, default 20) and rows in the table
	 * (`clicutcl_whatsapp_ref_max_rows`, default 50 000; counted from a cached figure that inserts
	 * bump and purges drop).
	 *
	 * @param array<string,mixed> $row Row built by build_row().
	 * @return string `created`, `collision`, `visitor_limit`, `capacity` or `unavailable`.
	 */
	public static function create( array $row ): string {
		global $wpdb;

		$table = self::table_name();
		if ( ! self::table_exists( $table ) ) {
			return 'unavailable';
		}

		$max_per_visitor = max( 1, (int) apply_filters( 'clicutcl_whatsapp_ref_max_per_visitor', self::DEFAULT_MAX_PER_VISITOR ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name.
		$visitor_rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE blog_id = %d AND visitor_id = %s AND expires_at > %s", $row['blog_id'], $row['visitor_id'], $row['created_at'] ) );
		if ( $visitor_rows >= $max_per_visitor ) {
			return 'visitor_limit';
		}

		$max_rows = max( 1, (int) apply_filters( 'clicutcl_whatsapp_ref_max_rows', self::DEFAULT_MAX_ROWS ) );
		if ( self::row_count( $table ) >= $max_rows ) {
			return 'capacity';
		}

		$columns      = implode( ', ', array_keys( $row ) );
		$placeholders = implode( ', ', array_fill( 0, count( $row ), '%s' ) );
		// ON DUPLICATE KEY UPDATE id = id affects 0 rows for an existing code, 1 for a new one.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table and columns are plugin-owned; every value is prepared.
		$affected = $wpdb->query( $wpdb->prepare( "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE id = id", array_values( $row ) ) );

		if ( false === $affected ) {
			return 'unavailable';
		}
		if ( 1 !== (int) $affected ) {
			return 'collision';
		}

		$cached = get_transient( self::COUNT_TRANSIENT );
		if ( false !== $cached ) {
			set_transient( self::COUNT_TRANSIENT, (int) $cached + 1, self::COUNT_TTL );
		}

		return 'created';
	}

	/**
	 * Rows in the table, from a short-lived cached count.
	 *
	 * @param string $table Table name.
	 * @return int
	 */
	private static function row_count( string $table ): int {
		global $wpdb;

		$cached = get_transient( self::COUNT_TRANSIENT );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned table name; no user input.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		set_transient( self::COUNT_TRANSIENT, $count, self::COUNT_TTL );

		return $count;
	}

	/**
	 * Look a code up. Unknown, malformed and expired codes all give null.
	 *
	 * @param string $raw_code Code as typed (optional `CT-` prefix, any case).
	 * @param int    $blog_id  Blog ID.
	 * @param int    $now      Unix timestamp used for the expiry check.
	 * @return array<string,mixed>|null
	 */
	public static function find( string $raw_code, int $blog_id, int $now ): ?array {
		global $wpdb;

		$code  = self::normalize_code( $raw_code );
		$table = self::table_name();
		if ( '' === $code || ! self::table_exists( $table ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name.
				"SELECT code, visitor_id, selected_click_id_key, selected_click_id, snapshot, created_at, expires_at FROM {$table} WHERE blog_id = %d AND code = %s AND expires_at > %s LIMIT 1",
				$blog_id,
				$code,
				gmdate( 'Y-m-d H:i:s', $now )
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}

		return self::format_row( $row );
	}

	/**
	 * Rows held for the given visitor IDs, newest first (privacy export).
	 *
	 * @param string[] $visitor_ids Visitor IDs.
	 * @param int      $blog_id     Blog ID.
	 * @param int      $limit       Maximum rows.
	 * @return array<int,array<string,mixed>> Rows shaped like find().
	 */
	public static function find_by_visitor_ids( array $visitor_ids, int $blog_id, int $limit = 500 ): array {
		global $wpdb;

		$visitor_ids = array_values( array_unique( array_filter( array_map( 'strval', $visitor_ids ) ) ) );
		$table       = self::table_name();
		if ( empty( $visitor_ids ) || ! self::table_exists( $table ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $visitor_ids ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Required for privacy export; plugin-owned table, placeholders generated above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT code, visitor_id, selected_click_id_key, selected_click_id, snapshot, created_at, expires_at FROM {$table} WHERE blog_id = %d AND visitor_id IN ({$placeholders}) ORDER BY created_at DESC LIMIT %d", array_merge( array( $blog_id ), $visitor_ids, array( max( 1, $limit ) ) ) ), ARRAY_A );

		return array_map( array( self::class, 'format_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Shape a table row for lookup and export.
	 *
	 * @param array<string,mixed> $row Table row.
	 * @return array<string,mixed>
	 */
	private static function format_row( array $row ): array {
		$snapshot = json_decode( (string) $row['snapshot'], true );
		$snapshot = is_array( $snapshot ) ? $snapshot : array();

		return array(
			'code'              => 'CT-' . $row['code'],
			'created_at'        => $row['created_at'],
			'expires_at'        => $row['expires_at'],
			'visitor_id'        => $row['visitor_id'],
			'selected_click_id' => array(
				'key'   => $row['selected_click_id_key'],
				'value' => $row['selected_click_id'],
			),
			'attribution'       => array(
				'first_touch' => isset( $snapshot['first_touch'] ) && is_array( $snapshot['first_touch'] ) ? $snapshot['first_touch'] : array(),
				'last_touch'  => isset( $snapshot['last_touch'] ) && is_array( $snapshot['last_touch'] ) ? $snapshot['last_touch'] : array(),
				'click_ids'   => isset( $snapshot['click_ids'] ) && is_array( $snapshot['click_ids'] ) ? $snapshot['click_ids'] : array(),
			),
		);
	}

	/**
	 * Delete expired rows, and rows older than the current retention, in bounded batches.
	 *
	 * @param int $retention_days Current retention in days.
	 * @param int $now            Unix timestamp.
	 * @return int Rows deleted.
	 */
	public static function purge_expired( int $retention_days, int $now ): int {
		global $wpdb;

		$table = self::table_name();
		if ( ! self::table_exists( $table ) ) {
			return 0;
		}

		$total  = 0;
		$now_at = gmdate( 'Y-m-d H:i:s', $now );
		$cutoff = gmdate( 'Y-m-d H:i:s', $now - max( 1, $retention_days ) * DAY_IN_SECONDS );
		for ( $i = 0; $i < self::PURGE_MAX_BATCHES; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Cron cleanup on a plugin-owned table.
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at < %s OR created_at < %s LIMIT %d", $now_at, $cutoff, self::PURGE_BATCH ) );
			if ( ! $deleted ) {
				break;
			}
			$total += (int) $deleted;
			if ( $deleted < self::PURGE_BATCH ) {
				break;
			}
		}

		if ( $total > 0 ) {
			delete_transient( self::COUNT_TRANSIENT );
		}

		return $total;
	}

	/**
	 * Delete the rows of the given visitor IDs (privacy eraser).
	 *
	 * @param string[] $visitor_ids Visitor IDs.
	 * @return int|false Rows deleted, false on a database error.
	 */
	public static function delete_by_visitor_ids( array $visitor_ids ) {
		global $wpdb;

		$visitor_ids = array_values( array_unique( array_filter( array_map( 'strval', $visitor_ids ) ) ) );
		$table       = self::table_name();
		if ( empty( $visitor_ids ) || ! self::table_exists( $table ) ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $visitor_ids ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Required for privacy erasure; plugin-owned table, placeholders generated above.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE visitor_id IN ({$placeholders})", $visitor_ids ) );
		delete_transient( self::COUNT_TRANSIENT );

		return $deleted;
	}

	/**
	 * Collect the browser visitor IDs recorded in logged event rows (`clicutcl_events.event_data`).
	 *
	 * @param array<int,array<string,mixed>> $rows Rows with an `event_data` JSON column.
	 * @return string[]
	 */
	public static function visitor_ids_from_event_rows( array $rows ): array {
		$ids = array();
		foreach ( $rows as $row ) {
			$decoded = isset( $row['event_data'] ) ? json_decode( (string) $row['event_data'], true ) : null;
			if ( is_array( $decoded ) ) {
				self::collect_visitor_ids( $decoded, $ids );
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Depth-first search for `visitor_id` string values.
	 *
	 * @param array    $node Decoded JSON node.
	 * @param string[] $ids  Collected IDs (by reference).
	 * @return void
	 */
	private static function collect_visitor_ids( array $node, array &$ids ): void {
		foreach ( $node as $key => $value ) {
			if ( is_array( $value ) ) {
				self::collect_visitor_ids( $value, $ids );
			} elseif ( 'visitor_id' === $key && is_string( $value ) && '' !== $value && strlen( $value ) <= 64 ) {
				$ids[] = $value;
			}
		}
	}

	/**
	 * Whether the plugin-owned table exists.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lightweight metadata check on a plugin-owned table.
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}
}
