<?php
/**
 * Short-lived atomic claim shared by the purchase and form dedupe paths.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Tracking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Claim_Lock
 *
 * With a persistent object cache, `wp_cache_add()` is an atomic claim that
 * fails when the key already exists. Without one, the in-memory cache is
 * per request and cannot see a concurrent PHP worker, so it falls back to
 * a first-writer-wins `INSERT IGNORE` on the options table (core `add_option()`
 * upserts, so it cannot be the claim). An option claim older than its TTL is treated as
 * abandoned by a request that died before releasing it and is taken over
 * through a row-conditional delete of the exact stale value, so two late
 * arrivals cannot both take it.
 *
 * Every claim stores a value unique to this request and returns it; release
 * removes the claim only while that exact value is still stored, so an expired
 * owner can never release a successor's claim.
 */
class Claim_Lock {
	/**
	 * Object cache group for claims.
	 */
	private const GROUP = 'clicutcl';

	/**
	 * Try to claim a key.
	 *
	 * @param string $key         Cache key / option name.
	 * @param int    $ttl_seconds Seconds before the claim counts as abandoned.
	 * @return string The stored value this request owns, or '' when another request holds the claim.
	 */
	public static function acquire( string $key, int $ttl_seconds ): string {
		$token = wp_generate_uuid4();

		if ( wp_using_ext_object_cache() ) {
			return wp_cache_add( $key, $token, self::GROUP, $ttl_seconds ) ? $token : '';
		}

		$value = $token . '|' . time();
		if ( self::insert_row( $key, $value ) ) {
			return $value;
		}

		// Read the row, not get_option(): the per-request options cache may hold a stale value.
		$stored     = self::read_row( $key );
		$held_since = self::held_since( $stored );
		if ( $held_since <= 0 || ( time() - $held_since ) <= $ttl_seconds ) {
			return '';
		}

		// Only the request that deletes the exact stale row may re-claim; a
		// concurrent late arrival that changed the row first wins instead.
		if ( 1 !== self::delete_row( $key, $stored ) ) {
			return '';
		}

		return self::insert_row( $key, $value ) ? $value : '';
	}

	/**
	 * First-writer-wins insert of the claim row.
	 *
	 * Core `add_option()` is not an atomic claim: after a racy `get_option()` pre-check it runs
	 * `INSERT ... ON DUPLICATE KEY UPDATE`, so a second concurrent writer overwrites the row and
	 * still reports success. `INSERT IGNORE` leaves an existing row untouched and reports 0
	 * affected rows, so exactly one concurrent caller sees 1.
	 *
	 * @param string $key   Option name.
	 * @param string $value Claim value.
	 * @return bool Whether this call created the row.
	 */
	private static function insert_row( string $key, string $value ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core add_option() upserts, so it is not an atomic claim; INSERT IGNORE is first-writer-wins.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, $value ) );
		if ( 1 !== $inserted ) {
			return false;
		}

		self::forget_cached_row( $key );

		return true;
	}

	/**
	 * Current stored value of a claim row, straight from the options table.
	 *
	 * @param string $key Option name.
	 * @return string Empty when no row exists.
	 */
	private static function read_row( string $key ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- A cached get_option() may be stale; the takeover decision needs the live row.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Drop the options cache entries for a row changed behind core's back.
	 *
	 * @param string $key Option name.
	 * @return void
	 */
	private static function forget_cached_row( string $key ): void {
		wp_cache_delete( $key, 'options' );

		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $key ] ) ) {
			unset( $notoptions[ $key ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
	}

	/**
	 * Delete option-backed claim rows abandoned for longer than `$max_age_seconds`.
	 *
	 * Without a persistent object cache every claim is an options row named like
	 * `clicutcl_purchase_lock_<id>` or `clicutcl_form_claim_<hash>`; release() removes it, but a
	 * request that died before releasing leaves it behind until the same key is claimed again, which
	 * for a purchase or form fingerprint may be never. Only rows whose stored `<token>|<time>` time
	 * is older than the bound go, each through the same row-conditional delete the takeover uses, so
	 * a claim re-taken meanwhile is left alone. Rows whose value has no readable time are kept.
	 * The oldest rows are examined first and at most `$limit` per call.
	 *
	 * @param int $max_age_seconds Age (seconds) after which a claim row counts as abandoned.
	 * @param int $limit           Maximum rows examined per call.
	 * @return int Rows deleted.
	 */
	public static function purge_stale( int $max_age_seconds = DAY_IN_SECONDS, int $limit = 500 ): int {
		global $wpdb;

		$patterns = self::option_name_patterns();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Cron cleanup of the plugin's own option rows; the options table has no core query API for a name pattern.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s ORDER BY option_id ASC LIMIT %d", $patterns[0], $patterns[1], max( 1, $limit ) ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return 0;
		}

		$cutoff  = time() - max( 1, $max_age_seconds );
		$deleted = 0;
		foreach ( $rows as $row ) {
			$held_since = self::held_since( (string) $row['option_value'] );
			if ( $held_since > 0 && $held_since < $cutoff ) {
				$deleted += self::delete_row( (string) $row['option_name'], (string) $row['option_value'] );
			}
		}

		return $deleted;
	}

	/**
	 * Delete every option-backed claim row (uninstall).
	 *
	 * @return void
	 */
	public static function delete_all_rows(): void {
		global $wpdb;

		$patterns = self::option_name_patterns();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall cleanup of the plugin's own option rows; the options table has no core query API for a name pattern.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $patterns[0], $patterns[1] ) );
	}

	/**
	 * LIKE patterns of the option names claims use: `clicutcl_<name>_lock_<id>` (purchase, Ultimate
	 * Member sign-up) and `clicutcl_form_claim_<hash>`.
	 *
	 * @return string[]
	 */
	private static function option_name_patterns(): array {
		global $wpdb;

		return array(
			$wpdb->esc_like( 'clicutcl_' ) . '%' . $wpdb->esc_like( '_lock_' ) . '%',
			$wpdb->esc_like( 'clicutcl_form_claim_' ) . '%',
		);
	}

	/**
	 * Time a stored claim value (`<token>|<unix time>`) was taken.
	 *
	 * @param string $stored Stored option value.
	 * @return int Unix time, or 0 when the value carries none.
	 */
	private static function held_since( string $stored ): int {
		return (int) substr( (string) strrchr( $stored, '|' ), 1 );
	}

	/**
	 * Release a claim while this request still owns it.
	 *
	 * @param string $key   Cache key / option name.
	 * @param string $claim Value returned by acquire().
	 * @return void
	 */
	public static function release( string $key, string $claim ): void {
		if ( wp_using_ext_object_cache() ) {
			if ( wp_cache_get( $key, self::GROUP ) === $claim ) {
				wp_cache_delete( $key, self::GROUP );
			}
			return;
		}

		self::delete_row( $key, $claim );
	}

	/**
	 * Delete the option row only while it still stores the given value.
	 *
	 * `delete_option()` is unconditional, so this goes to the options table
	 * directly and uses the row's current value as part of the WHERE clause;
	 * the affected-rows count tells the caller whether it won. The per-request
	 * options cache entry is dropped the same way core's `delete_option()`
	 * does so a following `get_option()` does not serve the deleted row.
	 *
	 * @param string $key   Option name.
	 * @param string $value Exact stored value that must still be present.
	 * @return int Rows deleted (0 or 1).
	 */
	private static function delete_row( string $key, string $value ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic row-conditional delete (name and exact value); core's delete_option() is unconditional and no core API offers a compare-and-delete.
		$deleted = $wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => $key,
				'option_value' => $value,
			)
		);
		self::forget_cached_row( $key );

		return false === $deleted ? 0 : (int) $deleted;
	}
}
