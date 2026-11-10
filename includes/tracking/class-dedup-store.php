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
	 * Stats flush throttle lock transient key.
	 */
	private const STATS_FLUSH_LOCK = 'clicutcl_v2_dedup_stats_flush_lock';

	/**
	 * Minimum seconds between two writes of the shared stats row.
	 */
	private const STATS_FLUSH_INTERVAL = 10;

	/**
	 * Hit/miss counts buffered in this request, flushed at shutdown.
	 *
	 * @var array{total_checks:int,hits:int,misses:int,by_destination:array<string,array{hits:int,misses:int}>}
	 */
	private static $pending_stats = array();

	/**
	 * Whether the shutdown flush is registered for this request.
	 *
	 * @var bool
	 */
	private static $stats_flush_registered = false;

	/**
	 * Check and mark dedup key.
	 *
	 * @param string $destination Destination key.
	 * @param string $event_name  Event name.
	 * @param string $event_id    Event ID.
	 * @param int    $ttl_seconds  Optional TTL.
	 * @param bool   $record_stats Whether the check counts toward the hit/miss stats.
	 * @return bool True when duplicate found.
	 */
	public static function check_and_mark( string $destination, string $event_name, string $event_id, int $ttl_seconds = 0, bool $record_stats = true ): bool {
		$destination = sanitize_key( $destination );
		$event_name  = sanitize_key( $event_name );
		$event_id    = sanitize_text_field( $event_id );

		if ( '' === $destination || '' === $event_name || '' === $event_id ) {
			return false;
		}

		if ( self::is_duplicate( $destination, $event_name, $event_id, $record_stats ) ) {
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
	 * @param string $event_id     Event ID.
	 * @param bool   $record_stats Whether the check counts toward the hit/miss stats.
	 * @return bool
	 */
	public static function is_duplicate( string $destination, string $event_name, string $event_id, bool $record_stats = true ): bool {
		$destination = sanitize_key( $destination );
		$event_name  = sanitize_key( $event_name );
		$event_id    = sanitize_text_field( $event_id );

		if ( '' === $destination || '' === $event_name || '' === $event_id ) {
			return false;
		}

		$key = self::build_key( $destination, $event_name, $event_id );
		$hit = (bool) get_transient( $key );
		if ( $record_stats ) {
			self::record_stats( $hit, $destination );
		}
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
		$stats = is_array( $stats ) ? $stats : self::empty_stats();

		return empty( self::$pending_stats ) ? $stats : self::merge_stats( $stats, self::$pending_stats );
	}

	/**
	 * Write this request's buffered hit/miss counts to the shared stats row.
	 *
	 * Runs on `shutdown`. Writes at most once per STATS_FLUSH_INTERVAL seconds
	 * site-wide; a request that lands inside a held window drops its counts.
	 *
	 * @return void
	 */
	public static function flush_stats(): void {
		if ( empty( self::$pending_stats ) ) {
			return;
		}

		$deltas              = self::$pending_stats;
		self::$pending_stats = array();

		// ponytail: dropped deltas make the counters a sample under load; a dedicated counter table would make them exact.
		if ( get_transient( self::STATS_FLUSH_LOCK ) ) {
			return;
		}
		set_transient( self::STATS_FLUSH_LOCK, 1, self::STATS_FLUSH_INTERVAL );

		$stats = get_transient( self::STATS_KEY );
		$stats = is_array( $stats ) ? $stats : self::empty_stats();
		set_transient( self::STATS_KEY, self::merge_stats( $stats, $deltas ), 30 * DAY_IN_SECONDS );
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
	 * Buffers the count in memory; flush_stats() writes it at shutdown, so a
	 * dedup check never touches the shared stats row itself.
	 *
	 * NOTE: These are advisory counters only — do not use for billing, SLA
	 * enforcement, or dedup correctness checks. The flush read-modify-write is
	 * not atomic and is throttled to one write per STATS_FLUSH_INTERVAL, so
	 * requests that finish inside a held window are not counted: the stats are
	 * a sample under load, not an exact tally.
	 *
	 * @param bool   $hit         Whether the check was a duplicate hit.
	 * @param string $destination Destination key.
	 * @return void
	 */
	private static function record_stats( bool $hit, string $destination ): void {
		if ( empty( self::$pending_stats ) ) {
			self::$pending_stats = self::empty_stats();
		}

		$field = $hit ? 'hits' : 'misses';
		++self::$pending_stats['total_checks'];
		++self::$pending_stats[ $field ];
		if ( ! isset( self::$pending_stats['by_destination'][ $destination ] ) ) {
			self::$pending_stats['by_destination'][ $destination ] = array(
				'hits'   => 0,
				'misses' => 0,
			);
		}
		++self::$pending_stats['by_destination'][ $destination ][ $field ];

		if ( ! self::$stats_flush_registered ) {
			self::$stats_flush_registered = true;
			add_action( 'shutdown', array( __CLASS__, 'flush_stats' ), 1 );
		}
	}

	/**
	 * Add buffered counts to a stats row.
	 *
	 * @param array $stats  Stored stats row.
	 * @param array $deltas Buffered counts (same shape).
	 * @return array
	 */
	private static function merge_stats( array $stats, array $deltas ): array {
		$stats['total_checks'] = absint( $stats['total_checks'] ) + $deltas['total_checks'];
		$stats['hits']         = absint( $stats['hits'] ) + $deltas['hits'];
		$stats['misses']       = absint( $stats['misses'] ) + $deltas['misses'];

		foreach ( $deltas['by_destination'] as $destination => $counts ) {
			if ( ! isset( $stats['by_destination'][ $destination ] ) || ! is_array( $stats['by_destination'][ $destination ] ) ) {
				$stats['by_destination'][ $destination ] = array(
					'hits'   => 0,
					'misses' => 0,
				);
			}
			$stats['by_destination'][ $destination ]['hits']   = absint( $stats['by_destination'][ $destination ]['hits'] ) + $counts['hits'];
			$stats['by_destination'][ $destination ]['misses'] = absint( $stats['by_destination'][ $destination ]['misses'] ) + $counts['misses'];
		}

		$stats['updated_at'] = time();
		return $stats;
	}

	/**
	 * Empty stats row.
	 *
	 * @return array
	 */
	private static function empty_stats(): array {
		return array(
			'total_checks'   => 0,
			'hits'           => 0,
			'misses'         => 0,
			'by_destination' => array(),
			'updated_at'     => 0,
		);
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
