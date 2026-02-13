<?php
/**
 * Server-side Dispatcher
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Server_Side;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Dispatcher
 */
class Dispatcher {
	/**
	 * Dispatch WA click event.
	 *
	 * @param array $payload Payload.
	 * @return Adapter_Result
	 */
	public static function dispatch_wa_click( $payload ) {
		$event = Event::from_wa_click( $payload );
		return self::dispatch( $event );
	}

	/**
	 * Dispatch form submission event.
	 *
	 * @param string $platform Platform name.
	 * @param mixed  $form_id Form ID.
	 * @param array  $attribution Attribution payload.
	 * @param array  $context Optional context.
	 * @return Adapter_Result
	 */
	public static function dispatch_form_submission( $platform, $form_id, $attribution, $context = array() ) {
		$event = Event::from_form_submission( $platform, $form_id, $attribution, $context );
		return self::dispatch( $event );
	}

	/**
	 * Dispatch purchase event.
	 *
	 * @param array $payload Purchase payload.
	 * @return Adapter_Result
	 */
	public static function dispatch_purchase( $payload ) {
		$event = Event::from_purchase( $payload );
		return self::dispatch( $event );
	}

	/**
	 * Dispatch event through adapter.
	 *
	 * @param Event $event Event.
	 * @return Adapter_Result
	 */
	public static function dispatch( Event $event ) {
		if ( ! self::is_enabled() ) {
			return Adapter_Result::skipped( 'disabled' );
		}

		$endpoint = self::get_endpoint();
		if ( ! $endpoint ) {
			return Adapter_Result::skipped( 'missing_endpoint' );
		}

		if ( ! self::consent_allows() ) {
			return Adapter_Result::skipped( 'consent_denied' );
		}

		$adapter = self::get_adapter();
		if ( ! $adapter ) {
			return Adapter_Result::error( 0, 'missing_adapter' );
		}

		$result = $adapter->send( $event );
		self::log_dispatch( $event, $adapter, $result );
		if ( ! $result->success && ! $result->skipped ) {
			self::record_last_error( 'adapter_error', $result->message );
			Queue::enqueue( $event, $adapter->get_name(), self::get_endpoint(), $result->message );
		}

		return $result;
	}

	/**
	 * Health check for current adapter.
	 *
	 * @return Adapter_Result
	 */
	public static function health_check() {
		if ( ! self::is_enabled() ) {
			return Adapter_Result::skipped( 'disabled' );
		}

		$endpoint = self::get_endpoint();
		if ( ! $endpoint ) {
			return Adapter_Result::error( 0, 'missing_endpoint' );
		}

		$adapter = self::get_adapter();
		if ( ! $adapter ) {
			return Adapter_Result::error( 0, 'missing_adapter' );
		}

		return $adapter->health_check();
	}

	/**
	 * Check if server-side sending is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$options = Settings::get();
		return ! empty( $options['enabled'] );
	}

	/**
	 * Return endpoint URL.
	 *
	 * @return string
	 */
	public static function get_endpoint() {
		$options  = Settings::get();
		$endpoint = isset( $options['endpoint_url'] ) ? esc_url_raw( (string) $options['endpoint_url'] ) : '';
		return $endpoint;
	}

	/**
	 * Return timeout.
	 *
	 * @return int
	 */
	public static function get_timeout() {
		$options = Settings::get();
		return isset( $options['timeout'] ) ? absint( $options['timeout'] ) : 5;
	}

	/**
	 * Return adapter key.
	 *
	 * @return string
	 */
	public static function get_adapter_key() {
		$options = Settings::get();
		return isset( $options['adapter'] ) ? sanitize_key( $options['adapter'] ) : 'generic';
	}

	/**
	 * Build adapter instance from settings.
	 *
	 * @param string $adapter Adapter key.
	 * @param string $endpoint Endpoint URL.
	 * @param int    $timeout Timeout.
	 * @return Adapter_Interface|null
	 */
	public static function build_adapter( $adapter, $endpoint, $timeout ) {
		$endpoint = esc_url_raw( (string) $endpoint );
		if ( ! $endpoint ) {
			return null;
		}

		$timeout = max( 1, absint( $timeout ) );
		$adapter = sanitize_key( (string) $adapter );

		switch ( $adapter ) {
			case 'sgtm':
				return new Sgtm_Adapter( $endpoint, $timeout );
			case 'meta_capi':
				return new Meta_Capi_Adapter( $endpoint, $timeout );
			case 'generic':
			default:
				return new Generic_Collector_Adapter( $endpoint, $timeout );
		}
	}

	/**
	 * Return adapter instance.
	 *
	 * @return Adapter_Interface|null
	 */
	private static function get_adapter() {
		$endpoint = self::get_endpoint();
		if ( ! $endpoint ) {
			return null;
		}

		$timeout = self::get_timeout();
		$adapter = self::get_adapter_key();

		return self::build_adapter( $adapter, $endpoint, $timeout );
	}

	/**
	 * Consent gate for sending.
	 *
	 * @return bool
	 */
	private static function consent_allows() {
		$attr_options    = get_option( 'clicutcl_attribution_settings', array() );
		$require_consent = ! empty( $attr_options['require_consent'] );

		if ( ! $require_consent ) {
			return true;
		}

		return Consent::marketing_allowed();
	}

	/**
	 * Record last error for diagnostics.
	 *
	 * @param string $code Error code.
	 * @param string $message Error message.
	 * @return void
	 */
	public static function record_last_error( $code, $message ) {
		update_option(
			'clicutcl_last_error',
			array(
				'code'    => sanitize_key( $code ),
				'message' => sanitize_text_field( $message ),
				'time'    => time(),
			),
			false
		);
	}

	/**
	 * Check if debug logging is enabled.
	 *
	 * @return bool
	 */
	private static function is_debug_enabled() {
		$until = get_transient( 'clicutcl_debug_until' );
		return $until && (int) $until > time();
	}

	/**
	 * Should we record dispatch for diagnostics.
	 *
	 * @param Adapter_Result $result Adapter result.
	 * @return bool
	 */
	private static function should_log_dispatch( Adapter_Result $result ) {
		if ( ! $result->success || $result->skipped ) {
			return true;
		}

		return self::is_debug_enabled();
	}

	/**
	 * Record dispatch for diagnostics.
	 *
	 * @param Event            $event Event.
	 * @param Adapter_Interface $adapter Adapter instance.
	 * @param Adapter_Result   $result Result.
	 * @return void
	 */
	public static function log_dispatch( Event $event, $adapter, Adapter_Result $result ) {
		if ( ! self::should_log_dispatch( $result ) ) {
			return;
		}

		$data = $event->to_array();

		$event_name = isset( $data['event_name'] ) ? sanitize_text_field( (string) $data['event_name'] ) : '';
		$event_id   = isset( $data['event_id'] ) ? sanitize_text_field( (string) $data['event_id'] ) : '';
		$adapter_id = method_exists( $adapter, 'get_name' ) ? sanitize_key( $adapter->get_name() ) : 'adapter';

		$status = $result->skipped ? 'skipped' : ( $result->success ? 'sent' : 'error' );

		$message = sanitize_text_field( (string) $result->message );
		if ( strlen( $message ) > 200 ) {
			$message = substr( $message, 0, 200 );
		}

		$endpoint_host = '';
		if ( isset( $result->meta['endpoint'] ) ) {
			$endpoint_host = wp_parse_url( (string) $result->meta['endpoint'], PHP_URL_HOST );
			$endpoint_host = $endpoint_host ? sanitize_text_field( $endpoint_host ) : '';
		}

		$entry = array(
			'time'          => time(),
			'event_name'    => $event_name,
			'event_id'      => $event_id,
			'adapter'       => $adapter_id,
			'status'        => $status,
			'http_status'   => (int) $result->status,
			'message'       => $message,
			'endpoint_host' => $endpoint_host,
		);

		$dispatches = get_option( 'clicutcl_dispatch_log', array() );
		if ( ! is_array( $dispatches ) ) {
			$dispatches = array();
		}

		array_unshift( $dispatches, $entry );
		$dispatches = array_slice( $dispatches, 0, 20 );

		update_option( 'clicutcl_dispatch_log', $dispatches, false );
	}
}
