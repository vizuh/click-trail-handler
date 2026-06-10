<?php
/**
 * Event name map: internal vocabulary -> GA4 / Meta platform vocabulary.
 *
 * Provides additive event-name alignment for outbound server-side payloads.
 * By default the original event names are preserved and aligned names are
 * exposed via extra keys ('ga4_event_name', 'platform_event_name').
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Tracking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Event_Name_Map
 */
class Event_Name_Map {

	/**
	 * Internal event name -> GA4 recommended event name.
	 *
	 * Names already GA4-aligned (purchase, begin_checkout, add_to_cart,
	 * view_item, sign_up, login) are intentionally absent; the passthrough
	 * fallback covers them. 'order_paid' is deliberately unmapped to avoid
	 * emitting a duplicate 'purchase'.
	 *
	 * @var array<string,string>
	 */
	private const GA4_MAP = array(
		'lead'             => 'generate_lead',
		'form_submission'  => 'generate_lead',
		'qualified_lead'   => 'qualify_lead',
		'book_appointment' => 'working_lead',
		'client_won'       => 'close_convert_lead',
		'order_refunded'   => 'refund',
	);

	/**
	 * Internal event name -> Meta (Facebook) standard event name.
	 *
	 * @var array<string,string>
	 */
	private const META_MAP = array(
		'lead'             => 'Lead',
		'form_submission'  => 'Lead',
		'qualified_lead'   => 'Lead',
		'book_appointment' => 'Schedule',
		'purchase'         => 'Purchase',
		'begin_checkout'   => 'InitiateCheckout',
		'add_to_cart'      => 'AddToCart',
		'view_item'        => 'ViewContent',
		'sign_up'          => 'CompleteRegistration',
	);

	/**
	 * Get the (filterable) internal -> GA4 event name map.
	 *
	 * Filter signature:
	 * `apply_filters( 'clicutcl_event_name_map', array<string,string> $map )`
	 * where keys are internal event names and values are GA4 event names.
	 * Keys and values are passed through sanitize_key() after filtering.
	 *
	 * @return array<string,string>
	 */
	public static function ga4_map(): array {
		$map = apply_filters( 'clicutcl_event_name_map', self::GA4_MAP );

		$sanitized = array();
		foreach ( (array) $map as $internal => $ga4 ) {
			$internal = sanitize_key( (string) $internal );
			$ga4      = sanitize_key( (string) $ga4 );
			if ( '' === $internal || '' === $ga4 ) {
				continue;
			}
			$sanitized[ $internal ] = $ga4;
		}

		return $sanitized;
	}

	/**
	 * Translate an internal event name to its GA4 counterpart.
	 *
	 * Unknown names pass through unchanged.
	 *
	 * @param string $event_name Internal event name.
	 * @return string
	 */
	public static function to_ga4( string $event_name ): string {
		$map = self::ga4_map();

		return isset( $map[ $event_name ] ) ? $map[ $event_name ] : $event_name;
	}

	/**
	 * Get the (filterable) internal -> Meta standard event name map.
	 *
	 * Filter signature:
	 * `apply_filters( 'clicutcl_meta_event_name_map', array<string,string> $map )`
	 * where keys are internal event names and values are Meta standard event
	 * names. Keys are passed through sanitize_key() and values through
	 * sanitize_text_field() (Meta names are CamelCase) after filtering.
	 *
	 * @return array<string,string>
	 */
	public static function meta_map(): array {
		$map = apply_filters( 'clicutcl_meta_event_name_map', self::META_MAP );

		$sanitized = array();
		foreach ( (array) $map as $internal => $meta ) {
			$internal = sanitize_key( (string) $internal );
			$meta     = sanitize_text_field( (string) $meta );
			if ( '' === $internal || '' === $meta ) {
				continue;
			}
			$sanitized[ $internal ] = $meta;
		}

		return $sanitized;
	}

	/**
	 * Translate an internal event name to its Meta counterpart.
	 *
	 * Unknown names pass through unchanged.
	 *
	 * @param string $event_name Internal event name.
	 * @return string
	 */
	public static function to_meta( string $event_name ): string {
		$map = self::meta_map();

		return isset( $map[ $event_name ] ) ? $map[ $event_name ] : $event_name;
	}

	/**
	 * Whether the outbound 'event_name' should be renamed to GA4 vocabulary.
	 *
	 * Defaults to false (additive mode). Renaming only ever applies to the
	 * GA4-bound destinations (sgtm and the generic collector) and must be
	 * opted into via the 'clicutcl_ga4_rename_outbound' filter:
	 * `apply_filters( 'clicutcl_ga4_rename_outbound', bool $rename, string $destination )`.
	 *
	 * @param string $destination Destination adapter name.
	 * @return bool
	 */
	public static function should_rename( string $destination ): bool {
		if ( ! in_array( $destination, array( 'sgtm', 'generic', 'generic_collector' ), true ) ) {
			return false;
		}

		return (bool) apply_filters( 'clicutcl_ga4_rename_outbound', false, $destination );
	}

	/**
	 * Decorate an outbound payload body with aligned event names.
	 *
	 * Always adds 'ga4_event_name'. For the 'meta_capi' destination also adds
	 * 'platform_event_name'. When should_rename() opts in for the destination,
	 * preserves the original name in 'source_event_name' and replaces
	 * 'event_name' with the GA4 name. No other keys are modified.
	 *
	 * @param array  $body        Outbound payload body.
	 * @param string $destination Destination adapter name.
	 * @return array
	 */
	public static function decorate_body( array $body, string $destination ): array {
		if ( ! isset( $body['event_name'] ) || ! is_string( $body['event_name'] ) ) {
			return $body;
		}

		$original               = $body['event_name'];
		$body['ga4_event_name'] = self::to_ga4( $original );

		if ( 'meta_capi' === $destination ) {
			$body['platform_event_name'] = self::to_meta( $original );
		}

		if ( self::should_rename( $destination ) ) {
			$body['source_event_name'] = $original;
			$body['event_name']        = $body['ga4_event_name'];
		}

		return $body;
	}
}
