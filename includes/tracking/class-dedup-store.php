<?php
/**
 * Deduplication storage helper.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Tracking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Dedup_Store
 */
class Dedup_Store {
	/**
	 * Dedup transient prefix.
	 */
	private const KEY_PREFIX = 'clicutcl_v2_dup_';

	/**
	 * Stats transient key.
	 */
	private const STATS_KEY = 'clicutcl_v2_dedup_stats';

	/**
	 * Check and mark dedup key.
	 *
	 * @param string $destination Destination key.
	 * @param string $event_name  Event name.
	 * @param string $event_id    Event ID.
	 * @param int    $ttl_seconds Optional TTL.
	 * @return bool True when duplicate found.
	 */
	public static function check_and_mark( string $destination, string $event_name, string $event_id, int $ttl_seconds = 0 ): bool {
		$destination = sanitize_key( $destination );
		$event_name  = sanitize_key( $event_name );
		$event_id    = sanitize_text_field( $event_id );

		if ( '' === $destination || '' === $event_name || '' === $event_id ) {
			return false;
		}

		if ( self::is_duplicate( $destination, $event_name, $event_id ) ) {
			return true;
		}

		self::mark( $destination, $event_name, $event_id, $ttl_seconds );

		return false;
	}

	/**
	 * Check if key already exists.
	 *
	 * @param string $destination Destination key.
	 * @param string $event_name  Event name.
	 * @param string $event_id    Event ID.
	 * @return bool
	 */
	public static function is_duplicate( string $destination, string $event_name, string $event_id ): bool {
		$destination = sanitize_key( $destination );
		$event_name  = sanitize_key( $event_name );
		$event_id    = sanitize_text_field( $event_id );

		if ( '' === $destination || '' === $event_name || '' === $event_id ) {
			return false;
		}

		$key = self::build_key( $destination, $event_name, $event_id );
		$hit = (bool) get_transient( $key );
		self::record_stats( $hit, $destination );
		return $hit;
	}

	/**
	 * Mark a dedup key as dispatched.
	 *
	 * @param string $destination Destination key.
	 * @param string $event_name Event name.
	 * @param string $event_id Event ID.
	 * @param int    $ttl_seconds Optional TTL.
	 * @return void
	 */
	public static function mark( string $destination, string $event_name, string $event_id, int $ttl_seconds = 0 ): void {
		$destination = sanitize_key( $destination );
		$event_name  = sanitize_key( $event_name );
		$event_id    = sanitize_text_field( $event_id );

		if ( '' === $destination || '' === $event_name || '' === $event_id ) {
			return;
		}

		$key = self::build_key( $destination, $event_name, $event_id );
		$ttl = $ttl_seconds > 0 ? $ttl_seconds : self::default_ttl();
		$ttl = max( HOUR_IN_SECONDS, min( 30 * DAY_IN_SECONDS, $ttl ) );
		set_transient( $key, 1, $ttl );
	}

	/**
	 * Return dedup statistics.
	 *
	 * @return array
	 */
	public static function get_stats(): array {
		$stats = get_transient( self::STATS_KEY );
		if ( ! is_array( $stats ) ) {
			return array(
				'total_checks' => 0,
				'hits'         => 0,
				'misses'       => 0,
				'by_destination' => array(),
				'updated_at'   => 0,
			);
		}

		return $stats;
	}

	/**
	 * Default dedup TTL.
	 *
	 * @return int
	 */
	private static function default_ttl(): int {
		$settings = Settings::get();
		$ttl      = isset( $settings['dedup']['ttl_seconds'] ) ? absint( $settings['dedup']['ttl_seconds'] ) : 7 * DAY_IN_SECONDS;
		$ttl      = (int) apply_filters( 'clicutcl_v2_dedup_ttl', $ttl );
		return max( DAY_IN_SECONDS, $ttl );
	}

	/**
	 * Record dedup stats.
	 *
	 * @param bool   $hit         Whether the check was a duplicate hit.
	 * @param string $destination Destination key.
	 * @return void
	 */
	private static function record_stats( bool $hit, string $destination ): void {
		$stats = get_transient( self::STATS_KEY );
		$stats = is_array( $stats ) ? $stats : array(
			'total_checks' => 0,
			'hits'         => 0,
			'misses'       => 0,
			'by_destination' => array(),
			'updated_at'   => 0,
		);

		$stats['total_checks'] = absint( $stats['total_checks'] ) + 1;
		if ( $hit ) {
			$stats['hits'] = absint( $stats['hits'] ) + 1;
		} else {
			$stats['misses'] = absint( $stats['misses'] ) + 1;
		}

		if ( ! isset( $stats['by_destination'][ $destination ] ) || ! is_array( $stats['by_destination'][ $destination ] ) ) {
			$stats['by_destination'][ $destination ] = array(
				'hits'   => 0,
				'misses' => 0,
			);
		}
		if ( $hit ) {
			$stats['by_destination'][ $destination ]['hits'] = absint( $stats['by_destination'][ $destination ]['hits'] ) + 1;
		} else {
			$stats['by_destination'][ $destination ]['misses'] = absint( $stats['by_destination'][ $destination ]['misses'] ) + 1;
		}

		$stats['updated_at'] = time();
		set_transient( self::STATS_KEY, $stats, 30 * DAY_IN_SECONDS );
	}

	/**
	 * Build dedup transient key.
	 *
	 * @param string $destination Destination.
	 * @param string $event_name Event name.
	 * @param string $event_id Event ID.
	 * @return string
	 */
	private static function build_key( string $destination, string $event_name, string $event_id ): string {
		return self::KEY_PREFIX . md5( $destination . '|' . $event_name . '|' . $event_id );
	}
}
