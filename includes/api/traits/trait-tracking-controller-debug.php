<?php
/**
 * Debug ring-buffer helpers for Tracking_Controller.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Tracking_Controller_Debug_Trait {
	/**
	 * Return debug intake ring buffer.
	 *
	 * @return array
	 */
	public static function get_debug_event_buffer(): array {
		$buffer = get_transient( self::INTAKE_DEBUG_TRANSIENT );
		return is_array( $buffer ) ? $buffer : array();
	}

	/**
	 * Record permission-gate debug entry.
	 *
	 * @param string $status  Status.
	 * @param string $reason  Reason code.
	 * @param array  $context Optional context.
	 * @return void
	 */
	private function record_gate_debug( string $status, string $reason, array $context ): void {
		if ( ! $this->is_debug_enabled() ) {
			return;
		}

		$this->push_debug_event(
			array(
				'time'             => time(),
				'kind'             => 'gate',
				'status'           => sanitize_key( $status ),
				'reason'           => sanitize_key( $reason ),
				'event_name'       => '',
				'event_id'         => '',
				'consent'          => array(),
				'attribution_keys' => array(),
				'identity_keys'    => array(),
				'lead'             => array(),
				'context'          => $this->sanitize_debug_context( $context ),
			)
		);
	}

	/**
	 * Record normalized intake debug entry.
	 *
	 * @param array  $canonical Canonical event.
	 * @param string $status    Status.
	 * @param string $reason    Reason.
	 * @return void
	 */
	private function record_intake_debug( array $canonical, string $status, string $reason ): void {
		if ( ! $this->is_debug_enabled() ) {
			return;
		}

		$consent = isset( $canonical['consent'] ) && is_array( $canonical['consent'] ) ? $canonical['consent'] : array();
		$lead    = isset( $canonical['lead_context'] ) && is_array( $canonical['lead_context'] ) ? $canonical['lead_context'] : array();

		$entry = array(
			'time'             => time(),
			'kind'             => 'event',
			'status'           => sanitize_key( $status ),
			'reason'           => sanitize_key( $reason ),
			'event_name'       => sanitize_key( (string) ( $canonical['event_name'] ?? '' ) ),
			'event_id'         => sanitize_text_field( (string) ( $canonical['event_id'] ?? '' ) ),
			'funnel'           => sanitize_key( (string) ( $canonical['funnel_stage'] ?? '' ) ),
			'source'           => sanitize_key( (string) ( $canonical['source_channel'] ?? '' ) ),
			'consent'          => array(
				'marketing' => ! empty( $consent['marketing'] ),
				'analytics' => ! empty( $consent['analytics'] ),
			),
			'attribution_keys' => $this->sanitize_key_list( array_keys( is_array( $canonical['attribution'] ?? null ) ? $canonical['attribution'] : array() ) ),
			'identity_keys'    => $this->sanitize_key_list( array_keys( is_array( $canonical['identity'] ?? null ) ? $canonical['identity'] : array() ) ),
			'lead'             => array(
				'provider'      => sanitize_text_field( (string) ( $lead['provider'] ?? '' ) ),
				'submit_status' => sanitize_key( (string) ( $lead['submit_status'] ?? '' ) ),
				'form_id'       => sanitize_text_field( (string) ( $lead['form_id'] ?? '' ) ),
			),
		);

		$this->push_debug_event( $entry );
	}

	/**
	 * Push entry to bounded transient ring buffer.
	 *
	 * @param array $entry Entry.
	 * @return void
	 */
	private function push_debug_event( array $entry ): void {
		$buffer = self::get_debug_event_buffer();
		array_unshift( $buffer, $entry );

		$max = (int) apply_filters( 'clicutcl_v2_event_buffer_size', self::INTAKE_DEBUG_MAX );
		$max = max( 1, min( 200, $max ) );
		$buffer = array_slice( $buffer, 0, $max );

		$ttl = (int) apply_filters( 'clicutcl_v2_event_buffer_ttl', 6 * HOUR_IN_SECONDS );
		$ttl = max( HOUR_IN_SECONDS, min( 30 * DAY_IN_SECONDS, $ttl ) );
		set_transient( self::INTAKE_DEBUG_TRANSIENT, $buffer, $ttl );
	}

	/**
	 * Debug mode flag.
	 *
	 * @return bool
	 */
	private function is_debug_enabled(): bool {
		$until = get_transient( 'clicutcl_debug_until' );
		return $until && (int) $until > time();
	}

	/**
	 * Sanitize a list of keys.
	 *
	 * @param array $keys Raw keys.
	 * @return array
	 */
	private function sanitize_key_list( array $keys ): array {
		$out = array();
		foreach ( $keys as $key ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$out[] = $key;
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Sanitize small debug context map.
	 *
	 * @param array $context Context.
	 * @return array
	 */
	private function sanitize_debug_context( array $context ): array {
		$out = array();
		foreach ( $context as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}

			if ( is_numeric( $value ) ) {
				$out[ $key ] = $value + 0;
				continue;
			}

			if ( is_bool( $value ) ) {
				$out[ $key ] = (bool) $value;
				continue;
			}

			$out[ $key ] = sanitize_text_field( (string) $value );
		}

		return $out;
	}
}
