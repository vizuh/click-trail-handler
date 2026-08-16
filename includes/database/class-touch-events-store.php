<?php
/**
 * Structured touch-events write path (Pro-reporting foundation).
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes one row per touch/conversion event to `{prefix}clicutcl_touch_events`.
 *
 * The build_row() method is pure (no $wpdb access) so it is unit-testable
 * without a DB harness; record()/insert() are the thin impure wrapper around it.
 */
class Touch_Events_Store {

	/**
	 * Record a normalized Server_Side\Event payload, subject to consent.
	 *
	 * @param array $event_data       Event::to_array() payload.
	 * @param bool  $consent_allowed  Result of Dispatcher::consent_allows() for this event.
	 * @param int   $blog_id          Current blog ID (1 on single-site).
	 * @return void
	 */
	public static function record( array $event_data, bool $consent_allowed, int $blog_id ): void {
		$row = self::build_row( $event_data, $consent_allowed, $blog_id );
		if ( null === $row ) {
			return;
		}

		self::insert( $row );
	}

	/**
	 * Build the row to insert, or null to skip the write entirely.
	 *
	 * Consent gate mirrors Dispatcher::consent_allows(): when consent is
	 * required and not granted, the write is skipped outright rather than
	 * persisting a partial/anonymous row -- simplest option of the two the
	 * roadmap allows, and it keeps this table free of an ungated hit ledger.
	 *
	 * @param array $event_data      Event::to_array() payload.
	 * @param bool  $consent_allowed Whether consent allows this write.
	 * @param int   $blog_id         Current blog ID.
	 * @return array<string,mixed>|null
	 */
	public static function build_row( array $event_data, bool $consent_allowed, int $blog_id ): ?array {
		if ( ! $consent_allowed ) {
			return null;
		}

		$event_name = isset( $event_data['event_name'] ) ? sanitize_key( (string) $event_data['event_name'] ) : '';
		if ( '' === $event_name ) {
			return null;
		}

		$meta        = isset( $event_data['meta'] ) && is_array( $event_data['meta'] ) ? $event_data['meta'] : array();
		$commerce    = isset( $event_data['commerce'] ) && is_array( $event_data['commerce'] ) ? $event_data['commerce'] : array();
		$identity    = isset( $event_data['identity'] ) && is_array( $event_data['identity'] ) ? $event_data['identity'] : array();
		$attribution = isset( $event_data['attribution'] ) && is_array( $event_data['attribution'] ) ? $event_data['attribution'] : array();
		$touch       = self::extract_touch_fields( $attribution );

		$session_id   = isset( $meta['session_id'] ) ? sanitize_text_field( (string) $meta['session_id'] ) : '';
		$hashed_email = isset( $identity['hashed_email'] ) ? sanitize_text_field( (string) $identity['hashed_email'] ) : '';
		// Pseudonymous visitor key only -- never a raw email or IP. Prefer the
		// hashed identity (stable across sessions) and fall back to the session
		// cookie ID when no identity has been resolved for this event.
		$visitor_id = '' !== $hashed_email ? $hashed_email : $session_id;

		$order_id = 0;
		if ( isset( $meta['order_id'] ) ) {
			$order_id = absint( $meta['order_id'] );
		} elseif ( isset( $commerce['order_id'] ) ) {
			$order_id = absint( $commerce['order_id'] );
		}

		$timestamp = isset( $event_data['timestamp'] ) ? absint( $event_data['timestamp'] ) : time();

		return array(
			'blog_id'        => $blog_id,
			'visitor_id'     => $visitor_id,
			'session_id'     => $session_id,
			'event_name'     => $event_name,
			'funnel_stage'   => isset( $meta['funnel_stage'] ) && '' !== $meta['funnel_stage'] ? sanitize_key( (string) $meta['funnel_stage'] ) : 'unknown',
			'source_channel' => isset( $event_data['source'] ) && '' !== $event_data['source'] ? sanitize_key( (string) $event_data['source'] ) : 'web',
			'touch_source'   => $touch['touch_source'],
			'touch_medium'   => $touch['touch_medium'],
			'touch_campaign' => $touch['touch_campaign'],
			'ft_source'      => $touch['ft_source'],
			'ft_medium'      => $touch['ft_medium'],
			'ft_campaign'    => $touch['ft_campaign'],
			'order_id'       => $order_id > 0 ? $order_id : null,
			'amount'         => isset( $commerce['value'] ) && is_numeric( $commerce['value'] ) ? (float) $commerce['value'] : null,
			'currency'       => isset( $commerce['currency'] ) && '' !== $commerce['currency'] ? sanitize_text_field( (string) $commerce['currency'] ) : null,
			'created_at'     => gmdate( 'Y-m-d H:i:s', $timestamp ),
		);
	}

	/**
	 * Derive first/last-touch source/medium/campaign from either attribution
	 * shape in use across the codebase: nested {first_touch:{source,...},
	 * last_touch:{...}} (WooCommerce order meta) or flat ft_ / lt_ prefixed keys
	 * (browser events, forms). "Current touch" is last-touch when present,
	 * else first-touch.
	 *
	 * @param array $attribution Raw attribution payload.
	 * @return array{ft_source:?string,ft_medium:?string,ft_campaign:?string,touch_source:?string,touch_medium:?string,touch_campaign:?string}
	 */
	private static function extract_touch_fields( array $attribution ): array {
		$ft = isset( $attribution['first_touch'] ) && is_array( $attribution['first_touch'] ) ? $attribution['first_touch'] : array();
		$lt = isset( $attribution['last_touch'] ) && is_array( $attribution['last_touch'] ) ? $attribution['last_touch'] : array();

		$ft_source   = self::first_present( $ft['source'] ?? null, $attribution['ft_source'] ?? null );
		$ft_medium   = self::first_present( $ft['medium'] ?? null, $attribution['ft_medium'] ?? null );
		$ft_campaign = self::first_present( $ft['campaign'] ?? null, $attribution['ft_campaign'] ?? null );
		$lt_source   = self::first_present( $lt['source'] ?? null, $attribution['lt_source'] ?? null );
		$lt_medium   = self::first_present( $lt['medium'] ?? null, $attribution['lt_medium'] ?? null );
		$lt_campaign = self::first_present( $lt['campaign'] ?? null, $attribution['lt_campaign'] ?? null );

		return array(
			'ft_source'      => null !== $ft_source ? sanitize_text_field( $ft_source ) : null,
			'ft_medium'      => null !== $ft_medium ? sanitize_text_field( $ft_medium ) : null,
			'ft_campaign'    => null !== $ft_campaign ? sanitize_text_field( $ft_campaign ) : null,
			'touch_source'   => null !== $lt_source ? sanitize_text_field( $lt_source ) : ( null !== $ft_source ? sanitize_text_field( $ft_source ) : null ),
			'touch_medium'   => null !== $lt_medium ? sanitize_text_field( $lt_medium ) : ( null !== $ft_medium ? sanitize_text_field( $ft_medium ) : null ),
			'touch_campaign' => null !== $lt_campaign ? sanitize_text_field( $lt_campaign ) : ( null !== $ft_campaign ? sanitize_text_field( $ft_campaign ) : null ),
		);
	}

	/**
	 * Return the first non-empty scalar of the two candidates, as a string.
	 *
	 * @param mixed $primary   Preferred value.
	 * @param mixed $secondary Fallback value.
	 * @return string|null
	 */
	private static function first_present( $primary, $secondary ): ?string {
		if ( is_scalar( $primary ) && '' !== (string) $primary ) {
			return (string) $primary;
		}
		if ( is_scalar( $secondary ) && '' !== (string) $secondary ) {
			return (string) $secondary;
		}
		return null;
	}

	/**
	 * Insert the row into the touch events table.
	 *
	 * @param array<string,mixed> $row Row built by build_row().
	 * @return void
	 */
	private static function insert( array $row ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'clicutcl_touch_events';

		// This fires on every dispatched event, including public REST intake, so a
		// missing table (e.g. dbDelta failed on activation) must fail silently
		// rather than surface a $wpdb error into a WP_DEBUG response.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lightweight metadata check on plugin-owned table; no core wrapper available.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional insert into custom plugin table.
		$wpdb->insert( $table, $row );
	}
}
