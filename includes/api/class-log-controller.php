<?php
/**
 * REST API Log Controller
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Api;

use CLICUTCL\Server_Side\Dispatcher;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Legacy v1 controller is disabled by default.
// Enable only for controlled migrations/backward compatibility.
if ( ! defined( 'CLICUTCL_ENABLE_LEGACY_V1_API' ) || true !== CLICUTCL_ENABLE_LEGACY_V1_API ) {
	return;
}

require_once CLICUTCL_DIR . 'includes/api/traits/trait-log-controller-public-wa.php';
require_once CLICUTCL_DIR . 'includes/api/traits/trait-log-controller-runtime.php';

/**
 * Class Log_Controller
 */
class Log_Controller extends WP_REST_Controller {
	use Log_Controller_Public_WA_Trait;
	use Log_Controller_Runtime_Trait;

	/**
	 * Allowed timestamp drift (seconds).
	 */
	private const TIMESTAMP_DRIFT = 300;

	/**
	 * Rate limit default (requests per window).
	 */
	private const RATE_LIMIT_DEFAULT = 30;

	/**
	 * Rate limit window (seconds).
	 */
	private const RATE_WINDOW_DEFAULT = 60;

	/**
	 * Default WA token TTL (seconds).
	 */
	private const TOKEN_TTL_DEFAULT = 900;

	/**
	 * Maximum WA token TTL (seconds).
	 */
	private const TOKEN_TTL_MAX = 3600;

	/**
	 * Default allowed hits per token nonce within TTL window.
	 */
	private const NONCE_REPLAY_LIMIT_DEFAULT = 20;

	/**
	 * DB readiness option key.
	 */
	private const DB_READY_OPTION = 'clicutcl_events_table_ready';

	/**
	 * DB readiness last checked timestamp option key.
	 */
	private const DB_READY_CHECKED_AT_OPTION = 'clicutcl_events_table_checked_at';

	/**
	 * Diagnostics transient key for attempts ring buffer.
	 */
	private const ATTEMPTS_TRANSIENT = 'clicutcl_attempts_buffer';

	/**
	 * Diagnostics transient key for last error.
	 */
	private const LAST_ERROR_TRANSIENT = 'clicutcl_last_error';

	/**
	 * In-request memoized DB readiness.
	 *
	 * @var bool|null
	 */
	private static $db_ready_mem = null;

	/**
	 * Construction
	 */
	public function __construct() {
		$this->namespace = 'clicutcl/v1';
		$this->rest_base = 'log';
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/wa-click',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'public_wa_click' ),
					'permission_callback' => array( $this, 'public_wa_click_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
			)
		);
	}

	/**
	 * Check permissions.
	 *
	 * @param \WP_REST_Request $request Request instance.
	 * @return bool
	 */
	public function create_item_permissions_check( $request ) {
		$rate = $this->check_rate_limit( 'log' );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		if ( $this->verify_rest_nonce( $request ) ) {
			return true;
		}

		return new WP_Error( 'rest_forbidden', 'Missing Nonce', array( 'status' => 401 ) );
	}

	/**
	 * Public WhatsApp click permissions (HMAC).
	 *
	 * @param \WP_REST_Request $request Request instance.
	 * @return bool|\WP_Error
	 */
	public function public_wa_click_permissions_check( $request ) {
		$rate = $this->check_rate_limit( 'wa_public' );
		if ( is_wp_error( $rate ) ) {
			$this->record_attempt( 'rejected', 'rate_limited', array() );
			return $rate;
		}

		$payload = $this->sanitize_public_payload( $request );
		if ( is_wp_error( $payload ) ) {
			$this->record_attempt( 'rejected', $payload->get_error_code(), array() );
			return $payload;
		}

		$claims = $this->verify_public_token( $payload['token'] );
		if ( is_wp_error( $claims ) ) {
			$this->record_attempt( 'rejected', $claims->get_error_code(), $payload );
			return $claims;
		}

		if ( ! $this->is_timestamp_valid( $payload['ts'] ) ) {
			$this->record_attempt( 'rejected', 'invalid_timestamp', $payload );
			return new WP_Error( 'invalid_timestamp', 'Invalid timestamp', array( 'status' => 401 ) );
		}

		if ( $this->is_duplicate_event( $payload['event_id'] ) ) {
			$this->record_attempt( 'rejected', 'duplicate_event', $payload );
			return new WP_Error( 'duplicate_event', 'Duplicate event', array( 'status' => 409 ) );
		}

		$target_hash = md5( $payload['wa_target_type'] . '|' . $payload['wa_target_path'] );
		$rate_target = $this->check_rate_limit( 'wa_public_target_' . $target_hash );
		if ( is_wp_error( $rate_target ) ) {
			$this->record_attempt( 'rejected', 'rate_limited_target', $payload );
			return $rate_target;
		}

		$nonce_limit = $this->check_token_nonce_limit( $claims );
		if ( is_wp_error( $nonce_limit ) ) {
			$this->record_attempt( 'rejected', $nonce_limit->get_error_code(), $payload );
			return $nonce_limit;
		}

		$request->set_param( '_clicutcl_payload', $payload );
		$request->set_param( '_clicutcl_claims', $claims );
		return true;
	}

	/**
	 * Public WhatsApp click endpoint.
	 *
	 * @param \WP_REST_Request $request Request instance.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function public_wa_click( $request ) {
		$payload = $request->get_param( '_clicutcl_payload' );
		if ( ! is_array( $payload ) ) {
			$payload = $this->sanitize_public_payload( $request );
		}

		if ( is_wp_error( $payload ) ) {
			$this->record_attempt( 'rejected', $payload->get_error_code(), array() );
			return $payload;
		}

		return $this->handle_wa_click( $payload );
	}

	/**
	 * Create log item.
	 *
	 * @param \WP_REST_Request $request Request instance.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$params = $request->get_json_params();
		$event  = isset( $params['event'] ) ? sanitize_text_field( $params['event'] ) : '';

		if ( 'wa_click' === $event ) {
			$payload = $this->sanitize_public_payload( $params );
			if ( is_wp_error( $payload ) ) {
				return $payload;
			}

			return $this->handle_wa_click( $payload );
		}

		return new WP_Error( 'invalid_event', 'Invalid Event Type', array( 'status' => 400 ) );
	}

	/**
	 * Handle WhatsApp Click
	 *
	 * @param array $params Request params.
	 * @return \WP_REST_Response|WP_Error
	 */
	private function handle_wa_click( $params ) {
		global $wpdb;

		$event_id       = isset( $params['event_id'] ) ? sanitize_text_field( (string) $params['event_id'] ) : '';
		$ts             = isset( $params['ts'] ) ? absint( $params['ts'] ) : 0;
		$wa_target_type = isset( $params['wa_target_type'] ) ? sanitize_text_field( (string) $params['wa_target_type'] ) : '';
		$wa_target_type = $wa_target_type ? strtolower( $wa_target_type ) : '';
		$wa_target_path = isset( $params['wa_target_path'] ) ? sanitize_text_field( (string) $params['wa_target_path'] ) : '';
		$page_path      = isset( $params['page_path'] ) ? sanitize_text_field( (string) $params['page_path'] ) : '';
		$attribution    = isset( $params['attribution'] ) ? $this->sanitize_attribution_subset( $params['attribution'] ) : array();

		if ( ! $event_id || ! $wa_target_type || ! $wa_target_path ) {
			$this->record_attempt( 'rejected', 'missing_fields', $params );
			return new WP_Error( 'missing_fields', 'Missing required fields', array( 'status' => 400 ) );
		}

		$table_name = $wpdb->prefix . 'clicutcl_events';

		if ( ! $this->db_ready() ) {
			$this->record_last_error( 'db_not_ready', 'WA table not ready' );
			$this->record_attempt(
				'error',
				'db_not_ready',
				array(
					'event_id'       => $event_id,
					'wa_target_type' => $wa_target_type,
					'wa_target_path' => $wa_target_path,
					'page_path'      => $page_path,
				)
			);
			return new WP_Error( 'db_not_ready', 'Database is not ready', array( 'status' => 503 ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Intentional insert into custom plugin table.
		$inserted = $wpdb->insert(
			$table_name,
			array(
				'event_type' => 'wa_click',
				'event_data' => wp_json_encode(
					array(
						'event_id'       => $event_id,
						'ts'             => $ts,
						'page_path'      => $page_path,
						'wa_target_type' => $wa_target_type,
						'wa_target_path' => $wa_target_path,
						'attribution'    => $attribution,
					)
				),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s' )
		);

		if ( $inserted ) {
			$dispatch = Dispatcher::dispatch_wa_click(
				array(
					'event_id'       => $event_id,
					'ts'             => $ts,
					'page_path'      => $page_path,
					'wa_target_type' => $wa_target_type,
					'wa_target_path' => $wa_target_path,
					'attribution'    => $attribution,
				)
			);

			if ( ! $dispatch->success && ! $dispatch->skipped ) {
				$this->record_last_error( 'adapter_error', $dispatch->message );
			}

			$this->record_attempt(
				'accepted',
				'ok',
				array(
					'event_id'       => $event_id,
					'wa_target_type' => $wa_target_type,
					'wa_target_path' => $wa_target_path,
					'page_path'      => $page_path,
				)
			);
			return rest_ensure_response( array( 'success' => true, 'id' => $wpdb->insert_id ) );
		}

		$this->record_last_error( 'db_error', 'Could not save event' );
		$this->record_attempt(
			'error',
			'db_error',
			array(
				'event_id'       => $event_id,
				'wa_target_type' => $wa_target_type,
				'wa_target_path' => $wa_target_path,
				'page_path'      => $page_path,
			)
		);
		return new WP_Error( 'db_error', 'Could not save event', array( 'status' => 500 ) );
	}
}
