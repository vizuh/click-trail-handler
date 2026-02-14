<?php
/**
 * Server-side Dispatcher
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Server_Side;

use CLICUTCL\Tracking\Dedup_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Dispatcher
 */
class Dispatcher {
	/**
	 * Transient key for dispatch ring buffer.
	 */
	private const DISPATCH_BUFFER_TRANSIENT = 'clicutcl_dispatch_buffer';

	/**
	 * Transient key for last error.
	 */
	private const LAST_ERROR_TRANSIENT = 'clicutcl_last_error';

	/**
	 * Transient key for aggregated failure telemetry.
	 */
	private const FAILURE_TELEMETRY_TRANSIENT = 'clicutcl_failure_telemetry';

	/**
	 * Transient key for telemetry write throttling.
	 */
	private const FAILURE_TELEMETRY_FLUSH_LOCK = 'clicutcl_failure_flush_lock';

	/**
	 * In-request failure counters waiting for flush.
	 *
	 * @var array<string,int>
	 */
	private static $failure_deltas = array();

	/**
	 * Whether shutdown flush hook is registered.
	 *
	 * @var bool
	 */
	private static $failure_flush_registered = false;

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
			self::record_last_error( 'missing_endpoint', 'missing_endpoint' );
			self::record_failure( 'missing_endpoint' );
			return Adapter_Result::skipped( 'missing_endpoint' );
		}

		if ( ! self::consent_allows() ) {
			return Adapter_Result::skipped( 'consent_denied' );
		}

		$adapter = self::get_adapter();
		if ( ! $adapter ) {
			self::record_last_error( 'missing_adapter', 'missing_adapter' );
			self::record_failure( 'missing_adapter' );
			return Adapter_Result::error( 0, 'missing_adapter' );
		}

		$event_payload    = $event->to_array();
		$event_name       = isset( $event_payload['event_name'] ) ? sanitize_key( (string) $event_payload['event_name'] ) : '';
		$event_id         = isset( $event_payload['event_id'] ) ? sanitize_text_field( (string) $event_payload['event_id'] ) : '';
		$destination_key  = method_exists( $adapter, 'get_name' ) ? sanitize_key( (string) $adapter->get_name() ) : 'adapter';
		if ( $event_name && $event_id && Dedup_Store::is_duplicate( $destination_key, $event_name, $event_id ) ) {
			return Adapter_Result::skipped( 'duplicate_event' );
		}

		$result = $adapter->send( $event );
		self::log_dispatch( $event, $adapter, $result );
		if ( ! $result->success && ! $result->skipped ) {
			self::record_last_error( 'adapter_error', $result->message );
			self::record_failure( 'adapter_error' );
			Queue::enqueue( $event, $adapter->get_name(), self::get_endpoint(), $result->message );
		}
		if ( $result->success && ! $result->skipped && $event_name && $event_id ) {
			Dedup_Store::mark( $destination_key, $event_name, $event_id );
		}

		return $result;
	}

	/**
	 * Dispatch canonical event v2 payload through the existing adapter pipeline.
	 *
	 * @param array $event_v2 Canonical event v2 payload.
	 * @return Adapter_Result
	 */
	public static function dispatch_from_v2( array $event_v2 ) {
		$event_name = isset( $event_v2['event_name'] ) ? sanitize_key( (string) $event_v2['event_name'] ) : '';
		$event_id   = isset( $event_v2['event_id'] ) ? sanitize_text_field( (string) $event_v2['event_id'] ) : '';
		if ( ! $event_name || ! $event_id ) {
			return Adapter_Result::error( 0, 'invalid_v2_event' );
		}

		$legacy = array(
			'event_name'   => $event_name,
			'event_id'     => $event_id,
			'timestamp'    => isset( $event_v2['event_time'] ) ? absint( $event_v2['event_time'] ) : time(),
			'source'       => isset( $event_v2['source_channel'] ) ? sanitize_text_field( (string) $event_v2['source_channel'] ) : 'web',
			'page'         => isset( $event_v2['page_context'] ) && is_array( $event_v2['page_context'] ) ? $event_v2['page_context'] : array(),
			'attribution'  => isset( $event_v2['attribution'] ) && is_array( $event_v2['attribution'] ) ? $event_v2['attribution'] : array(),
			'consent'      => isset( $event_v2['consent'] ) && is_array( $event_v2['consent'] ) ? $event_v2['consent'] : array(),
			'meta'         => isset( $event_v2['meta'] ) && is_array( $event_v2['meta'] ) ? $event_v2['meta'] : array(),
		);

		if ( isset( $event_v2['lead_context'] ) && is_array( $event_v2['lead_context'] ) ) {
			$legacy['form'] = $event_v2['lead_context'];
		}
		if ( isset( $event_v2['commerce_context'] ) && is_array( $event_v2['commerce_context'] ) ) {
			$legacy['commerce'] = $event_v2['commerce_context'];
		}
		if ( isset( $event_v2['identity'] ) && is_array( $event_v2['identity'] ) ) {
			$legacy['meta']['identity'] = $event_v2['identity'];
		}
		if ( isset( $event_v2['delivery_context'] ) && is_array( $event_v2['delivery_context'] ) ) {
			$legacy['meta']['delivery_context'] = $event_v2['delivery_context'];
		}
		if ( isset( $event_v2['session_id'] ) ) {
			$legacy['meta']['session_id'] = sanitize_text_field( (string) $event_v2['session_id'] );
		}
		if ( isset( $event_v2['funnel_stage'] ) ) {
			$legacy['meta']['funnel_stage'] = sanitize_key( (string) $event_v2['funnel_stage'] );
		}

		$event = new Event( $legacy );
		return self::dispatch( $event );
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
			case 'google_ads':
				return new Google_Ads_Adapter( $endpoint, $timeout );
			case 'linkedin_capi':
				return new LinkedIn_Capi_Adapter( $endpoint, $timeout );
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
		$entry = array(
			'code'    => sanitize_key( $code ),
			'message' => sanitize_text_field( $message ),
			'time'    => time(),
		);

		$existing = get_transient( self::LAST_ERROR_TRANSIENT );
		if (
			is_array( $existing ) &&
			( $existing['code'] ?? '' ) === $entry['code'] &&
			( $existing['message'] ?? '' ) === $entry['message'] &&
			( (int) ( $existing['time'] ?? 0 ) + 30 ) > time()
		) {
			return;
		}

		$ttl = (int) apply_filters( 'clicutcl_diag_last_error_ttl', DAY_IN_SECONDS );
		$ttl = max( 300, $ttl );
		set_transient( self::LAST_ERROR_TRANSIENT, $entry, $ttl );
	}

	/**
	 * Record a failure signal without payload data (failure-only telemetry).
	 *
	 * @param string $code Failure code.
	 * @return void
	 */
	public static function record_failure( $code ) {
		$code = self::normalize_failure_code( $code );

		if ( ! isset( self::$failure_deltas[ $code ] ) ) {
			self::$failure_deltas[ $code ] = 0;
		}
		self::$failure_deltas[ $code ]++;

		if ( ! self::$failure_flush_registered ) {
			self::$failure_flush_registered = true;
			add_action( 'shutdown', array( __CLASS__, 'flush_failure_telemetry' ), 1 );
		}
	}

	/**
	 * Flush in-request failure counters to aggregated transient storage.
	 *
	 * @return void
	 */
	public static function flush_failure_telemetry() {
		if ( empty( self::$failure_deltas ) ) {
			return;
		}

		$deltas = self::$failure_deltas;
		self::$failure_deltas = array();

		$flush_interval = (int) apply_filters( 'clicutcl_failure_telemetry_flush_interval', 10 );
		$flush_interval = max( 0, $flush_interval );

		if ( $flush_interval > 0 && get_transient( self::FAILURE_TELEMETRY_FLUSH_LOCK ) ) {
			self::log_failure_telemetry_line( $deltas, true );
			return;
		}

		if ( $flush_interval > 0 ) {
			set_transient( self::FAILURE_TELEMETRY_FLUSH_LOCK, 1, $flush_interval );
		}

		$telemetry = get_transient( self::FAILURE_TELEMETRY_TRANSIENT );
		$telemetry = is_array( $telemetry ) ? $telemetry : array();

		$bucket_key = gmdate( 'YmdH' );
		if ( ! isset( $telemetry[ $bucket_key ] ) || ! is_array( $telemetry[ $bucket_key ] ) ) {
			$telemetry[ $bucket_key ] = array(
				'bucket_start' => time() - ( time() % HOUR_IN_SECONDS ),
				'updated_at'   => time(),
				'total'        => 0,
				'codes'        => array(),
			);
		}

		foreach ( $deltas as $code => $count ) {
			$count = absint( $count );
			if ( $count < 1 ) {
				continue;
			}

			$telemetry[ $bucket_key ]['total'] += $count;
			if ( ! isset( $telemetry[ $bucket_key ]['codes'][ $code ] ) ) {
				$telemetry[ $bucket_key ]['codes'][ $code ] = 0;
			}
			$telemetry[ $bucket_key ]['codes'][ $code ] += $count;
		}
		$telemetry[ $bucket_key ]['updated_at'] = time();

		krsort( $telemetry, SORT_STRING );
		$bucket_limit = (int) apply_filters( 'clicutcl_failure_telemetry_bucket_limit', 72 );
		$bucket_limit = max( 1, min( 720, $bucket_limit ) );
		$telemetry    = array_slice( $telemetry, 0, $bucket_limit, true );

		$ttl = (int) apply_filters( 'clicutcl_failure_telemetry_ttl', 7 * DAY_IN_SECONDS );
		$ttl = max( HOUR_IN_SECONDS, $ttl );
		set_transient( self::FAILURE_TELEMETRY_TRANSIENT, $telemetry, $ttl );

		self::maybe_emit_remote_failure_telemetry( $bucket_key, $deltas );
	}

	/**
	 * Return aggregated failure telemetry buckets.
	 *
	 * @return array
	 */
	public static function get_failure_telemetry() {
		$telemetry = get_transient( self::FAILURE_TELEMETRY_TRANSIENT );
		if ( ! is_array( $telemetry ) ) {
			return array();
		}

		krsort( $telemetry, SORT_STRING );
		return $telemetry;
	}

	/**
	 * Return delivery diagnostics bundle.
	 *
	 * @return array
	 */
	public static function get_delivery_diagnostics() {
		$last_error = get_transient( self::LAST_ERROR_TRANSIENT );
		if ( ! is_array( $last_error ) ) {
			$legacy    = get_option( 'clicutcl_last_error', array() );
			$last_error = is_array( $legacy ) ? $legacy : array();
		}

		$dispatches = get_transient( self::DISPATCH_BUFFER_TRANSIENT );
		if ( ! is_array( $dispatches ) ) {
			$legacy     = get_option( 'clicutcl_dispatch_log', array() );
			$dispatches = is_array( $legacy ) ? $legacy : array();
		}

		return array(
			'last_error'        => $last_error,
			'recent_dispatches' => $dispatches,
			'failure_telemetry' => self::get_failure_telemetry(),
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
	private static function should_log_dispatch( Adapter_Result $result ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
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

		$dispatches = get_transient( self::DISPATCH_BUFFER_TRANSIENT );
		if ( ! is_array( $dispatches ) ) {
			$legacy = get_option( 'clicutcl_dispatch_log', array() );
			$dispatches = is_array( $legacy ) ? $legacy : array();
		}

		$max = (int) apply_filters( 'clicutcl_diag_dispatch_buffer_size', 20 );
		$max = max( 1, min( 200, $max ) );

		array_unshift( $dispatches, $entry );
		$dispatches = array_slice( $dispatches, 0, $max );

		$ttl = (int) apply_filters( 'clicutcl_diag_buffer_ttl', 6 * HOUR_IN_SECONDS );
		$ttl = max( HOUR_IN_SECONDS, $ttl );
		set_transient( self::DISPATCH_BUFFER_TRANSIENT, $dispatches, $ttl );
	}

	/**
	 * Normalize error codes for telemetry buckets.
	 *
	 * @param string $code Raw code.
	 * @return string
	 */
	private static function normalize_failure_code( $code ) {
		$normalized = sanitize_key( (string) $code );
		if ( '' === $normalized ) {
			return 'unknown_failure';
		}

		return substr( $normalized, 0, 64 );
	}

	/**
	 * Emit remote telemetry hook when explicitly enabled.
	 *
	 * @param string $bucket_key Hour bucket key.
	 * @param array  $deltas Failure counters for this flush.
	 * @return void
	 */
	private static function maybe_emit_remote_failure_telemetry( $bucket_key, $deltas ) {
		if ( ! self::is_remote_failure_telemetry_enabled() ) {
			return;
		}

		do_action(
			'clicutcl_failure_telemetry_remote',
			array(
				'version'      => 1,
				'bucket'       => sanitize_text_field( (string) $bucket_key ),
				'emitted_at'   => time(),
				'site_host'    => sanitize_text_field( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
				'failure_code' => array_map( 'absint', is_array( $deltas ) ? $deltas : array() ),
			)
		);
	}

	/**
	 * Check remote telemetry opt-in.
	 *
	 * @return bool
	 */
	private static function is_remote_failure_telemetry_enabled() {
		$options = Settings::get();
		return ! empty( $options['remote_failure_telemetry'] );
	}

	/**
	 * Fallback server-log line when writes are throttled.
	 *
	 * @param array $deltas Failure counters.
	 * @param bool  $throttled Whether write was throttled.
	 * @return void
	 */
	private static function log_failure_telemetry_line( $deltas, $throttled ) {
		if ( ! function_exists( 'error_log' ) ) {
			return;
		}

		$payload = array(
			'source'    => 'clicktrail',
			'event'     => 'failure_telemetry',
			'throttled' => (bool) $throttled,
			'time'      => time(),
			'codes'     => array_map( 'absint', is_array( $deltas ) ? $deltas : array() ),
		);

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( wp_json_encode( $payload ) );
	}
}
