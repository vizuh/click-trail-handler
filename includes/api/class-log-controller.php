<?php
/**
 * REST API Log Controller
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Api;

use WP_REST_Controller;
use WP_REST_Server;
use WP_Error;
use CLICUTCL\Server_Side\Dispatcher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Log_Controller
 */
class Log_Controller extends WP_REST_Controller {
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

		if ( ! $this->is_allowed_request_origin( $request ) ) {
			$this->record_attempt( 'rejected', 'invalid_origin', array() );
			return new WP_Error( 'invalid_origin', 'Invalid origin', array( 'status' => 403 ) );
		}

		$payload = $this->sanitize_public_payload( $request );
		if ( is_wp_error( $payload ) ) {
			$this->record_attempt( 'rejected', $payload->get_error_code(), array() );
			return $payload;
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

		$request->set_param( '_clicutcl_payload', $payload );
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

		// Check if table exists (for gradual migration support)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table existence check; lightweight metadata query, no core wrapper available.
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) === $table_name ) {
			// Write to Custom Table
			$inserted = $wpdb->insert(
				$table_name,
				array(
					'event_type' => 'wa_click',
					'event_data' => wp_json_encode( array(
						'event_id'       => $event_id,
						'ts'             => $ts,
						'page_path'      => $page_path,
						'wa_target_type' => $wa_target_type,
						'wa_target_path' => $wa_target_path,
						'attribution' => $attribution,
					) ),
					'created_at' => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s' )
			);

			if ( $inserted ) {
				$dispatch = Dispatcher::dispatch_wa_click( array(
					'event_id'       => $event_id,
					'ts'             => $ts,
					'page_path'      => $page_path,
					'wa_target_type' => $wa_target_type,
					'wa_target_path' => $wa_target_path,
					'attribution'    => $attribution,
				) );

				if ( ! $dispatch->success && ! $dispatch->skipped ) {
					$this->record_last_error( 'adapter_error', $dispatch->message );
				}

				$this->record_attempt( 'accepted', 'ok', array(
					'event_id'       => $event_id,
					'wa_target_type' => $wa_target_type,
					'wa_target_path' => $wa_target_path,
					'page_path'      => $page_path,
				) );
				return rest_ensure_response( array( 'success' => true, 'id' => $wpdb->insert_id ) );
			}
		}

		$this->record_last_error( 'db_error', 'Could not save event' );
		$this->record_attempt( 'error', 'db_error', array(
			'event_id'       => $event_id,
			'wa_target_type' => $wa_target_type,
			'wa_target_path' => $wa_target_path,
			'page_path'      => $page_path,
		) );
		return new WP_Error( 'db_error', 'Could not save event', array( 'status' => 500 ) );
	}

	/**
	 * Verify wp_rest nonce if provided.
	 *
	 * @param \WP_REST_Request $request Request instance.
	 * @return bool
	 */
	private function verify_rest_nonce( $request ) {
		// Verify Nonce (passed in header X-WP-Nonce or _wpnonce param)
		$nonce = $request->get_header( 'x_wp_nonce' );
		if ( ! $nonce ) {
			$nonce = $request->get_header( 'x-wp-nonce' );
		}
		if ( ! $nonce ) {
			$nonce = $request->get_param( '_wpnonce' );
		}

		if ( ! $nonce ) {
			return false;
		}

		return (bool) wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * Validate timestamp drift.
	 *
	 * @param int $timestamp Timestamp.
	 * @return bool
	 */
	private function is_timestamp_valid( $timestamp ) {
		$now = time();
		return abs( $now - (int) $timestamp ) <= self::TIMESTAMP_DRIFT;
	}

	/**
	 * Sanitize public payload (allowlist + caps).
	 *
	 * @param \WP_REST_Request|array $input Request or params.
	 * @return array|\WP_Error
	 */
	private function sanitize_public_payload( $input ) {
		$params = $input instanceof \WP_REST_Request ? $input->get_json_params() : $input;
		$params = is_array( $params ) ? $params : array();

		if ( isset( $params['event_id'] ) && ( is_array( $params['event_id'] ) || is_object( $params['event_id'] ) ) ) {
			return new WP_Error( 'invalid_event_id', 'Invalid event_id', array( 'status' => 400 ) );
		}
		$event_id = isset( $params['event_id'] ) ? sanitize_text_field( (string) $params['event_id'] ) : '';

		if ( isset( $params['ts'] ) && ( is_array( $params['ts'] ) || is_object( $params['ts'] ) ) ) {
			return new WP_Error( 'invalid_timestamp', 'Invalid timestamp', array( 'status' => 400 ) );
		}
		$ts = isset( $params['ts'] ) ? absint( $params['ts'] ) : 0;

		if ( ! $event_id || strlen( $event_id ) > 64 ) {
			return new WP_Error( 'invalid_event_id', 'Invalid event_id', array( 'status' => 400 ) );
		}

		if ( ! $ts ) {
			return new WP_Error( 'invalid_timestamp', 'Invalid timestamp', array( 'status' => 400 ) );
		}

		if ( isset( $params['page_path'] ) && ( is_array( $params['page_path'] ) || is_object( $params['page_path'] ) ) ) {
			return new WP_Error( 'invalid_page_path', 'Invalid page_path', array( 'status' => 400 ) );
		}
		$page_path = isset( $params['page_path'] ) ? $this->normalize_path( $params['page_path'] ) : '';
		if ( ! $page_path && isset( $params['wa_location'] ) ) {
			$page_path = $this->normalize_path( $params['wa_location'] );
		}
		$page_path = $page_path ? sanitize_text_field( $page_path ) : '';

		if ( isset( $params['wa_target_type'] ) && ( is_array( $params['wa_target_type'] ) || is_object( $params['wa_target_type'] ) ) ) {
			return new WP_Error( 'invalid_target', 'Invalid WhatsApp target', array( 'status' => 400 ) );
		}
		if ( isset( $params['wa_target_path'] ) && ( is_array( $params['wa_target_path'] ) || is_object( $params['wa_target_path'] ) ) ) {
			return new WP_Error( 'invalid_target', 'Invalid WhatsApp target', array( 'status' => 400 ) );
		}
		$wa_target_type = isset( $params['wa_target_type'] ) ? sanitize_text_field( (string) $params['wa_target_type'] ) : '';
		$wa_target_path = isset( $params['wa_target_path'] ) ? sanitize_text_field( (string) $params['wa_target_path'] ) : '';

		if ( ! $wa_target_type || ! $wa_target_path ) {
			$normalized = $this->normalize_wa_target_from_params( $params );
			if ( is_wp_error( $normalized ) ) {
				return $normalized;
			}
			$wa_target_type = $normalized['wa_target_type'];
			$wa_target_path = $normalized['wa_target_path'];
		}

		$allowed_hosts = array( 'wa.me', 'whatsapp.com', 'api.whatsapp.com', 'web.whatsapp.com' );
		if ( ! in_array( $wa_target_type, $allowed_hosts, true ) ) {
			return new WP_Error( 'invalid_target', 'Invalid WhatsApp target', array( 'status' => 400 ) );
		}

		$wa_target_path = $this->normalize_path( $wa_target_path );
		$wa_target_path = preg_replace( '/\d+/', 'redacted', $wa_target_path );
		$wa_target_path = sanitize_text_field( $wa_target_path );

		$attribution = isset( $params['attribution'] ) ? $this->sanitize_attribution_subset( $params['attribution'] ) : array();

		return array(
			'event_id'       => $event_id,
			'ts'             => $ts,
			'page_path'      => $page_path,
			'wa_target_type' => $wa_target_type,
			'wa_target_path' => $wa_target_path,
			'attribution'    => $attribution,
		);
	}

	/**
	 * Sanitize attribution subset (allowlist + length caps).
	 *
	 * @param array $attribution Raw attribution.
	 * @return array
	 */
	private function sanitize_attribution_subset( $attribution ) {
		if ( ! is_array( $attribution ) ) {
			return array();
		}

		$allowed_keys = array(
			'ft_source',
			'ft_medium',
			'ft_campaign',
			'lt_source',
			'lt_medium',
			'lt_campaign',
			'gclid',
			'fbclid',
			'msclkid',
			'ttclid',
		);

		$clean = array();
		foreach ( $allowed_keys as $key ) {
			if ( ! isset( $attribution[ $key ] ) ) {
				continue;
			}
			$value = $attribution[ $key ];
			if ( is_array( $value ) || is_object( $value ) ) {
				continue;
			}
			$value = sanitize_text_field( (string) $value );
			if ( '' === $value ) {
				continue;
			}
			if ( strlen( $value ) > 128 ) {
				$value = substr( $value, 0, 128 );
			}
			$clean[ $key ] = $value;
		}

		return $clean;
	}

	/**
	 * Normalize paths (strip query/fragment, ensure leading slash).
	 *
	 * @param string $value Raw path or URL.
	 * @return string
	 */
	private function normalize_path( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$parts = wp_parse_url( $value );
		if ( ! $parts ) {
			$parts = wp_parse_url( 'https://example.com' . $value );
		}

		$path = isset( $parts['path'] ) ? $parts['path'] : '';
		if ( '' === $path ) {
			$path = '/';
		}

		if ( strlen( $path ) > 256 ) {
			$path = substr( $path, 0, 256 );
		}

		return $path;
	}

	/**
	 * Normalize WA target from legacy wa_href if provided.
	 *
	 * @param array $params Raw params.
	 * @return array|\WP_Error
	 */
	private function normalize_wa_target_from_params( $params ) {
		if ( isset( $params['wa_href'] ) && ( is_array( $params['wa_href'] ) || is_object( $params['wa_href'] ) ) ) {
			return new WP_Error( 'invalid_target', 'Invalid WhatsApp target', array( 'status' => 400 ) );
		}

		$wa_href = isset( $params['wa_href'] ) ? esc_url_raw( (string) $params['wa_href'] ) : '';
		if ( ! $wa_href ) {
			return new WP_Error( 'missing_target', 'Missing WhatsApp target', array( 'status' => 400 ) );
		}

		$parts = wp_parse_url( $wa_href );
		if ( ! $parts || empty( $parts['host'] ) ) {
			return new WP_Error( 'invalid_target', 'Invalid WhatsApp target', array( 'status' => 400 ) );
		}

		$host = strtolower( $parts['host'] );
		$path = isset( $parts['path'] ) ? $parts['path'] : '';

		return array(
			'wa_target_type' => sanitize_text_field( (string) $host ),
			'wa_target_path' => sanitize_text_field( (string) $path ),
		);
	}

	/**
	 * Check allowed Origin/Referer hosts.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	private function is_allowed_request_origin( $request ) {
		$allowed_hosts = $this->get_allowed_hosts();

		$origin = $request->get_header( 'origin' );
		if ( $origin ) {
			return $this->host_matches( $origin, $allowed_hosts );
		}

		$referer = $request->get_header( 'referer' );
		if ( $referer ) {
			return $this->host_matches( $referer, $allowed_hosts );
		}

		return false;
	}

	/**
	 * Allowed hosts list (home_url host + www variant).
	 *
	 * @return array
	 */
	private function get_allowed_hosts() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$allowed = array();
		if ( $host ) {
			$allowed[] = $host;
			if ( 0 === strpos( $host, 'www.' ) ) {
				$allowed[] = substr( $host, 4 );
			} else {
				$allowed[] = 'www.' . $host;
			}
		}

		$allowed = array_filter( array_unique( $allowed ) );

		/**
		 * Filter allowed hosts for public WA logging.
		 *
		 * @param array $allowed Allowed hostnames.
		 */
		return apply_filters( 'clicutcl_allowed_hosts', $allowed );
	}

	/**
	 * Check if URL host is allowed.
	 *
	 * @param string $url URL string.
	 * @param array  $allowed_hosts Allowed hosts.
	 * @return bool
	 */
	private function host_matches( $url, $allowed_hosts ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}

		return in_array( $host, $allowed_hosts, true );
	}

	/**
	 * Deduplicate event IDs via transient.
	 *
	 * @param string $event_id Event ID.
	 * @return bool
	 */
	private function is_duplicate_event( $event_id ) {
		$key = 'clicutcl_evt_' . md5( $event_id );
		if ( get_transient( $key ) ) {
			return true;
		}
		set_transient( $key, 1, 300 );
		return false;
	}

	/**
	 * Rate limit requests by IP + UA.
	 *
	 * @param string $bucket Bucket name.
	 * @return true|\WP_Error
	 */
	private function check_rate_limit( $bucket ) {
		$bucket = sanitize_key( $bucket );

		$rate = apply_filters(
			'clicutcl_rate_limit',
			array(
				'limit'  => self::RATE_LIMIT_DEFAULT,
				'window' => self::RATE_WINDOW_DEFAULT,
			),
			$bucket
		);

		$limit  = isset( $rate['limit'] ) ? absint( $rate['limit'] ) : self::RATE_LIMIT_DEFAULT;
		$window = isset( $rate['window'] ) ? absint( $rate['window'] ) : self::RATE_WINDOW_DEFAULT;

		if ( $limit < 1 || $window < 1 ) {
			return true;
		}

		$fingerprint = $this->get_client_fingerprint();
		$key         = 'clicutcl_rl_' . $bucket . '_' . md5( $fingerprint );
		$state       = get_transient( $key );

		if ( ! is_array( $state ) ) {
			$state = array(
				'count' => 0,
				'start' => time(),
			);
		}

		if ( ( time() - (int) $state['start'] ) > $window ) {
			$state = array(
				'count' => 0,
				'start' => time(),
			);
		}

		$state['count']++;
		set_transient( $key, $state, $window );

		if ( $state['count'] > $limit ) {
			return new WP_Error( 'rate_limited', 'Too many requests', array( 'status' => 429 ) );
		}

		return true;
	}

	/**
	 * Build a simple fingerprint for rate limiting.
	 *
	 * @return string
	 */
	private function get_client_fingerprint() {
		$ip = $this->get_client_ip();
		$ua = filter_input( INPUT_SERVER, 'HTTP_USER_AGENT', FILTER_UNSAFE_RAW );
		$ua = $ua ? sanitize_text_field( (string) $ua ) : '';

		return $ip . '|' . $ua;
	}

	/**
	 * Best-effort client IP.
	 *
	 * @return string
	 */
	private function get_client_ip() {
		$candidates = array(
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_FORWARDED_FOR',
			'REMOTE_ADDR',
		);

		foreach ( $candidates as $key ) {
			$value = filter_input( INPUT_SERVER, $key, FILTER_UNSAFE_RAW );
			if ( ! $value ) {
				continue;
			}

			if ( 'HTTP_X_FORWARDED_FOR' === $key && strpos( $value, ',' ) !== false ) {
				$value = trim( explode( ',', $value )[0] );
			}

			$value = sanitize_text_field( (string) $value );

			if ( filter_var( $value, FILTER_VALIDATE_IP ) ) {
				return $value;
			}
		}

		return '0.0.0.0';
	}

	/**
	 * Record last 20 attempts for diagnostics.
	 *
	 * @param string $status Status (accepted/rejected/error).
	 * @param string $reason Reason code.
	 * @param array  $context Safe context.
	 * @return void
	 */
	private function record_attempt( $status, $reason, $context = array() ) {
		if ( 'accepted' !== $status && ! $this->is_debug_enabled() ) {
			return;
		}

		$entry = array(
			'time'           => time(),
			'status'         => sanitize_key( $status ),
			'reason'         => sanitize_key( $reason ),
			'event_id'       => isset( $context['event_id'] ) ? sanitize_text_field( (string) $context['event_id'] ) : '',
			'wa_target_type' => isset( $context['wa_target_type'] ) ? sanitize_text_field( (string) $context['wa_target_type'] ) : '',
			'wa_target_path' => isset( $context['wa_target_path'] ) ? sanitize_text_field( (string) $context['wa_target_path'] ) : '',
			'page_path'      => isset( $context['page_path'] ) ? sanitize_text_field( (string) $context['page_path'] ) : '',
		);

		$attempts = get_option( 'clicutcl_attempts', array() );
		if ( ! is_array( $attempts ) ) {
			$attempts = array();
		}

		array_unshift( $attempts, $entry );
		$attempts = array_slice( $attempts, 0, 20 );

		update_option( 'clicutcl_attempts', $attempts, false );
	}

	/**
	 * Check if debug logging is enabled.
	 *
	 * @return bool
	 */
	private function is_debug_enabled() {
		$until = get_transient( 'clicutcl_debug_until' );
		return $until && (int) $until > time();
	}

	/**
	 * Persist last error for diagnostics.
	 *
	 * @param string $code Error code.
	 * @param string $message Error message.
	 * @return void
	 */
	private function record_last_error( $code, $message ) {
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
}
