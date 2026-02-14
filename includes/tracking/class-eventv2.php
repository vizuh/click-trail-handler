<?php
/**
 * Canonical event schema v2.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Tracking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EventV2
 */
class EventV2 implements CanonicalEventInterfaceV2 {
	/**
	 * Schema version.
	 */
	const VERSION = 2;

	/**
	 * Event payload.
	 *
	 * @var array
	 */
	private $event = array();

	/**
	 * Constructor.
	 *
	 * @param array $payload Raw payload.
	 */
	public function __construct( array $payload ) {
		$normalized = self::normalize( $payload );
		if ( ! self::validate( $normalized ) ) {
			$normalized = self::normalize(
				array(
					'event_name'     => 'invalid_event',
					'event_id'       => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'ct_', true ),
					'event_time'     => time(),
					'funnel_stage'   => 'unknown',
					'session_id'     => '',
					'source_channel' => 'server',
				)
			);
		}

		$this->event = $normalized;
	}

	/**
	 * Return normalized payload.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return $this->event;
	}

	/**
	 * Normalize payload to canonical schema.
	 *
	 * @param array $payload Raw payload.
	 * @return array
	 */
	public static function normalize( array $payload ): array {
		$event_name = isset( $payload['event_name'] ) ? sanitize_key( (string) $payload['event_name'] ) : '';
		$event_id   = isset( $payload['event_id'] ) ? sanitize_text_field( (string) $payload['event_id'] ) : '';
		$event_time = isset( $payload['event_time'] ) ? absint( $payload['event_time'] ) : time();
		$session_id = isset( $payload['session_id'] ) ? sanitize_text_field( (string) $payload['session_id'] ) : '';
		if ( '' === $session_id ) {
			$session_id = self::fallback_session_id( $event_id );
		}

		$normalized = array(
			'event_name'      => $event_name,
			'event_id'        => $event_id,
			'event_time'      => $event_time,
			'funnel_stage'    => isset( $payload['funnel_stage'] ) ? sanitize_key( (string) $payload['funnel_stage'] ) : 'unknown',
			'session_id'      => $session_id,
			'source_channel'  => isset( $payload['source_channel'] ) ? sanitize_key( (string) $payload['source_channel'] ) : 'web',
			'page_context'    => self::sanitize_array( $payload['page_context'] ?? array() ),
			'attribution'     => self::normalize_click_ids( self::sanitize_array( $payload['attribution'] ?? array() ) ),
			'consent'         => self::sanitize_consent( $payload['consent'] ?? array() ),
			'lead_context'    => self::sanitize_array( $payload['lead_context'] ?? array() ),
			'commerce_context'=> self::sanitize_array( $payload['commerce_context'] ?? array() ),
			'identity'        => self::sanitize_array( $payload['identity'] ?? array() ),
			'delivery_context'=> self::sanitize_array( $payload['delivery_context'] ?? array() ),
			'meta'            => self::sanitize_array( $payload['meta'] ?? array() ),
		);

		$normalized['meta']['schema_version'] = self::VERSION;
		if ( defined( 'CLICUTCL_VERSION' ) ) {
			$normalized['meta']['plugin_version'] = CLICUTCL_VERSION;
		}

		return $normalized;
	}

	/**
	 * Validate canonical schema.
	 *
	 * @param array $payload Normalized payload.
	 * @return bool
	 */
	public static function validate( array $payload ): bool {
		$required = array(
			'event_name',
			'event_id',
			'event_time',
			'funnel_stage',
			'session_id',
			'source_channel',
		);
		foreach ( $required as $field ) {
			if ( empty( $payload[ $field ] ) ) {
				return false;
			}
		}

		if ( ! is_numeric( $payload['event_time'] ) || absint( $payload['event_time'] ) < 1 ) {
			return false;
		}

		$required_arrays = array( 'page_context', 'attribution', 'consent', 'meta' );
		foreach ( $required_arrays as $field ) {
			if ( ! isset( $payload[ $field ] ) || ! is_array( $payload[ $field ] ) ) {
				return false;
			}
		}

		if ( ! isset( $payload['consent']['marketing'] ) || ! isset( $payload['consent']['analytics'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Sanitize consent state.
	 *
	 * @param mixed $consent Raw consent.
	 * @return array
	 */
	private static function sanitize_consent( $consent ): array {
		if ( ! is_array( $consent ) ) {
			return array(
				'marketing' => false,
				'analytics' => false,
			);
		}

		return array(
			'marketing' => ! empty( $consent['marketing'] ),
			'analytics' => ! empty( $consent['analytics'] ),
		);
	}

	/**
	 * Sanitize scalar-only arrays.
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	private static function sanitize_array( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();
		foreach ( $value as $key => $item ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $item ) ) {
				$out[ $key ] = self::sanitize_array( $item );
				continue;
			}

			if ( is_bool( $item ) ) {
				$out[ $key ] = $item;
				continue;
			}

			if ( is_numeric( $item ) ) {
				$out[ $key ] = $item + 0;
				continue;
			}

			$out[ $key ] = sanitize_text_field( (string) $item );
		}

		return $out;
	}

	/**
	 * Normalize first/last touch click IDs into canonical keys.
	 *
	 * @param array $attribution Attribution payload.
	 * @return array
	 */
	private static function normalize_click_ids( array $attribution ): array {
		$keys = array( 'gclid', 'fbclid', 'msclkid', 'ttclid', 'wbraid', 'gbraid' );
		foreach ( $keys as $key ) {
			if ( empty( $attribution[ $key ] ) ) {
				if ( ! empty( $attribution[ 'lt_' . $key ] ) ) {
					$attribution[ $key ] = $attribution[ 'lt_' . $key ];
				} elseif ( ! empty( $attribution[ 'ft_' . $key ] ) ) {
					$attribution[ $key ] = $attribution[ 'ft_' . $key ];
				}
			}
		}

		return $attribution;
	}

	/**
	 * Fallback session identifier when client session is unavailable.
	 *
	 * @param string $event_id Event ID.
	 * @return string
	 */
	private static function fallback_session_id( string $event_id ): string {
		$seed = $event_id ? $event_id : (string) wp_rand();
		return 's_' . substr( md5( $seed . '|' . time() . '|' . wp_rand() ), 0, 16 );
	}
}
