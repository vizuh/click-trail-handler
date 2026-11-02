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
 * `add_option()` uniqueness on the options table (the same pattern core uses
 * for upgrader locks). An option claim older than its TTL is treated as
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
		if ( add_option( $key, $value, '', 'no' ) ) {
			return $value;
		}

		$stored     = (string) get_option( $key, '' );
		$held_since = (int) substr( (string) strrchr( $stored, '|' ), 1 );
		if ( $held_since <= 0 || ( time() - $held_since ) <= $ttl_seconds ) {
			return '';
		}

		// Only the request that deletes the exact stale row may re-claim; a
		// concurrent late arrival that changed the row first wins instead.
		if ( 1 !== self::delete_row( $key, $stored ) ) {
			return '';
		}

		return add_option( $key, $value, '', 'no' ) ? $value : '';
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
	 * does so a following `add_option()` pre-check sees the row is gone.
	 *
	 * @param string $key   Option name.
	 * @param string $value Exact stored value that must still be present.
	 * @return int Rows deleted (0 or 1).
	 */
	private static function delete_row( string $key, string $value ): int {
		global $wpdb;

		$deleted = $wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => $key,
				'option_value' => $value,
			)
		);
		wp_cache_delete( $key, 'options' );

		return false === $deleted ? 0 : (int) $deleted;
	}
}
