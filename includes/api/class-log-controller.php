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

// Legacy v1 controller is disabled by default.
// Enable only for controlled migrations/backward compatibility.
if ( ! defined( 'CLICUTCL_ENABLE_LEGACY_V1_API' ) || true !== CLICUTCL_ENABLE_LEGACY_V1_API ) {
	return;
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

		// Write to custom table.
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

		if ( isset( $params['token'] ) && ( is_array( $params['token'] ) || is_object( $params['token'] ) ) ) {
			return new WP_Error( 'invalid_token', 'Invalid token', array( 'status' => 401 ) );
		}
		$token = isset( $params['token'] ) ? sanitize_text_field( (string) $params['token'] ) : '';

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

		if ( ! $token || strlen( $token ) > 2048 ) {
			return new WP_Error( 'invalid_token', 'Missing or invalid token', array( 'status' => 401 ) );
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
			'token'          => $token,
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
			'ft_gclid',
			'ft_fbclid',
			'ft_msclkid',
			'ft_ttclid',
			'ft_wbraid',
			'ft_gbraid',
			'lt_gclid',
			'lt_fbclid',
			'lt_msclkid',
			'lt_ttclid',
			'lt_wbraid',
			'lt_gbraid',
			'gclid',
			'fbclid',
			'msclkid',
			'ttclid',
			'wbraid',
			'gbraid',
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

		$clean = $this->normalize_click_ids( $clean );

		$canonical = array(
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
			'wbraid',
			'gbraid',
		);

		$normalized = array();
		foreach ( $canonical as $key ) {
			if ( isset( $clean[ $key ] ) && '' !== $clean[ $key ] ) {
				$normalized[ $key ] = $clean[ $key ];
			}
		}

		return $normalized;
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
	 * Normalize first/last-touch click IDs into canonical keys.
	 *
	 * @param array $attribution Attribution payload.
	 * @return array
	 */
	private function normalize_click_ids( $attribution ) {
		$attribution = is_array( $attribution ) ? $attribution : array();
		$keys        = array( 'gclid', 'fbclid', 'msclkid', 'ttclid', 'wbraid', 'gbraid' );

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
	 * Mint short-lived signed token for WA logging endpoint.
	 *
	 * @return string
	 */
	public function create_public_wa_token() {
		$now = time();
		$ttl = $this->get_token_ttl();
		$claims = array(
			'v'       => 1,
			'iat'     => $now,
			'exp'     => $now + $ttl,
			'nonce'   => wp_generate_uuid4(),
			'site'    => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'blog_id' => (int) get_current_blog_id(),
		);

		$json = wp_json_encode( $claims );
		if ( ! is_string( $json ) || '' === $json ) {
			return '';
		}

		$payload = $this->base64url_encode( $json );
		$sig     = hash_hmac( 'sha256', $payload, $this->get_token_signing_key(), true );
		return $payload . '.' . $this->base64url_encode( $sig );
	}

	/**
	 * Verify WA signed token.
	 *
	 * @param string $token Raw token.
	 * @return array|\WP_Error
	 */
	private function verify_public_token( $token ) {
		$token = trim( (string) $token );
		if ( '' === $token || false === strpos( $token, '.' ) ) {
			return new WP_Error( 'invalid_token', 'Invalid token', array( 'status' => 401 ) );
		}

		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) ) {
			return new WP_Error( 'invalid_token', 'Invalid token', array( 'status' => 401 ) );
		}

		list( $payload_b64, $sig_b64 ) = $parts;
		$provided_sig = $this->base64url_decode( $sig_b64 );
		if ( false === $provided_sig ) {
			return new WP_Error( 'invalid_token', 'Invalid token', array( 'status' => 401 ) );
		}

		$expected_sig = hash_hmac( 'sha256', $payload_b64, $this->get_token_signing_key(), true );
		if ( ! hash_equals( $expected_sig, $provided_sig ) ) {
			return new WP_Error( 'invalid_signature', 'Invalid token signature', array( 'status' => 401 ) );
		}

		$payload_json = $this->base64url_decode( $payload_b64 );
		$claims       = is_string( $payload_json ) ? json_decode( $payload_json, true ) : null;
		if ( ! is_array( $claims ) ) {
			return new WP_Error( 'invalid_token', 'Invalid token payload', array( 'status' => 401 ) );
		}

		$now = time();
		$exp = isset( $claims['exp'] ) ? absint( $claims['exp'] ) : 0;
		$iat = isset( $claims['iat'] ) ? absint( $claims['iat'] ) : 0;
		if ( ! $exp || ! $iat || $exp < $now || $iat > ( $now + 60 ) ) {
			return new WP_Error( 'token_expired', 'Token expired', array( 'status' => 401 ) );
		}

		$site_host = isset( $claims['site'] ) ? strtolower( sanitize_text_field( (string) $claims['site'] ) ) : '';
		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( ! $site_host || $site_host !== $home_host ) {
			return new WP_Error( 'token_site_mismatch', 'Token site mismatch', array( 'status' => 401 ) );
		}

		$blog_id = isset( $claims['blog_id'] ) ? absint( $claims['blog_id'] ) : 0;
		if ( $blog_id !== (int) get_current_blog_id() ) {
			return new WP_Error( 'token_blog_mismatch', 'Token blog mismatch', array( 'status' => 401 ) );
		}

		$nonce = isset( $claims['nonce'] ) ? sanitize_text_field( (string) $claims['nonce'] ) : '';
		if ( '' === $nonce || strlen( $nonce ) > 128 ) {
			return new WP_Error( 'invalid_token_nonce', 'Invalid token nonce', array( 'status' => 401 ) );
		}

		$claims['nonce'] = $nonce;
		$claims['exp']   = $exp;
		return $claims;
	}

	/**
	 * Enforce nonce replay limits for signed tokens.
	 *
	 * @param array $claims Verified claims.
	 * @return true|\WP_Error
	 */
	private function check_token_nonce_limit( $claims ) {
		$nonce = isset( $claims['nonce'] ) ? (string) $claims['nonce'] : '';
		if ( '' === $nonce ) {
			return new WP_Error( 'invalid_token_nonce', 'Invalid token nonce', array( 'status' => 401 ) );
		}

		$limit = $this->get_nonce_replay_limit();
		if ( $limit < 1 ) {
			return true;
		}

		$key   = 'clicutcl_wa_nonce_' . md5( $nonce );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return new WP_Error( 'nonce_replay_limited', 'Too many requests for token nonce', array( 'status' => 429 ) );
		}

		$ttl = max( 60, (int) $claims['exp'] - time() );
		set_transient( $key, $count + 1, $ttl );

		return true;
	}

	/**
	 * Get token TTL with bounds.
	 *
	 * @return int
	 */
	private function get_token_ttl() {
		$ttl = (int) apply_filters( 'clicutcl_wa_token_ttl', self::TOKEN_TTL_DEFAULT );
		if ( $ttl < 60 ) {
			$ttl = 60;
		}

		return min( self::TOKEN_TTL_MAX, $ttl );
	}

	/**
	 * Allowed requests per token nonce.
	 *
	 * @return int
	 */
	private function get_nonce_replay_limit() {
		$limit = (int) apply_filters( 'clicutcl_wa_token_nonce_limit', self::NONCE_REPLAY_LIMIT_DEFAULT );
		return max( 0, min( 1000, $limit ) );
	}

	/**
	 * HMAC key material for WA token signing.
	 *
	 * @return string
	 */
	private function get_token_signing_key() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ) );
	}

	/**
	 * Base64-url encode helper.
	 *
	 * @param string $value Raw bytes.
	 * @return string
	 */
	private function base64url_encode( $value ) {
		return rtrim( strtr( base64_encode( (string) $value ), '+/', '-_' ), '=' );
	}

	/**
	 * Base64-url decode helper.
	 *
	 * @param string $value Encoded string.
	 * @return string|false
	 */
	private function base64url_decode( $value ) {
		$value = strtr( (string) $value, '-_', '+/' );
		$pad   = strlen( $value ) % 4;
		if ( 0 !== $pad ) {
			$value .= str_repeat( '=', 4 - $pad );
		}

		return base64_decode( $value, true );
	}

	/**
	 * DB readiness check with in-request memoization.
	 *
	 * @return bool
	 */
	private function db_ready() {
		if ( null !== self::$db_ready_mem ) {
			return self::$db_ready_mem;
		}

		$stored = get_option( self::DB_READY_OPTION, null );
		if ( null === $stored ) {
			$stored = get_option( 'clicutcl_db_ready', null );
		}
		$now    = time();
		if ( null === $stored ) {
			$ready = $this->table_exists_fast();
			$this->persist_db_ready( $ready, $now );
			self::$db_ready_mem = $ready;
			return $ready;
		}

		$ready      = (int) $stored === 1;
		$checked_at = (int) get_option( self::DB_READY_CHECKED_AT_OPTION, 0 );
		if ( ! $checked_at ) {
			$checked_at = (int) get_option( 'clicutcl_db_ready_checked_at', 0 );
		}

		if ( ! $ready && ( $now - $checked_at ) > DAY_IN_SECONDS ) {
			$ready = $this->table_exists_fast();
			$this->persist_db_ready( $ready, $now );
		}

		self::$db_ready_mem = $ready;
		return $ready;
	}

	/**
	 * Persist DB readiness flags without autoload.
	 *
	 * @param bool $ready DB readiness.
	 * @param int  $checked_at Timestamp.
	 * @return void
	 */
	private function persist_db_ready( $ready, $checked_at ) {
		update_option( self::DB_READY_OPTION, $ready ? 1 : 0, false );
		update_option( self::DB_READY_CHECKED_AT_OPTION, absint( $checked_at ), false );
	}

	/**
	 * Fast table existence check for events table.
	 *
	 * @return bool
	 */
	private function table_exists_fast() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'clicutcl_events';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		return is_string( $found ) && $found === $table_name;
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
		$remote = $this->get_remote_addr();
		if ( ! $this->is_trusted_proxy( $remote ) ) {
			return $remote;
		}

		$cf_ip = filter_input( INPUT_SERVER, 'HTTP_CF_CONNECTING_IP', FILTER_UNSAFE_RAW );
		$cf_ip = $cf_ip ? sanitize_text_field( (string) $cf_ip ) : '';
		if ( filter_var( $cf_ip, FILTER_VALIDATE_IP ) ) {
			return $cf_ip;
		}

		$xff = filter_input( INPUT_SERVER, 'HTTP_X_FORWARDED_FOR', FILTER_UNSAFE_RAW );
		if ( $xff ) {
			$parts = array_map( 'trim', explode( ',', (string) $xff ) );
			foreach ( $parts as $candidate ) {
				$candidate = sanitize_text_field( (string) $candidate );
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					return $candidate;
				}
			}
		}

		return $remote;
	}

	/**
	 * Direct remote address.
	 *
	 * @return string
	 */
	private function get_remote_addr() {
		$remote = filter_input( INPUT_SERVER, 'REMOTE_ADDR', FILTER_UNSAFE_RAW );
		$remote = $remote ? sanitize_text_field( (string) $remote ) : '';
		if ( filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return $remote;
		}

		return '0.0.0.0';
	}

	/**
	 * Resolve trusted proxy CIDRs/IPs.
	 *
	 * @return array
	 */
	private function get_trusted_proxies() {
		$list = apply_filters( 'clicutcl_trusted_proxies', array() );
		if ( is_string( $list ) ) {
			$list = preg_split( '/[\r\n,\s]+/', $list );
		}
		if ( ! is_array( $list ) ) {
			return array();
		}

		$trusted = array();
		foreach ( $list as $entry ) {
			$entry = trim( sanitize_text_field( (string) $entry ) );
			if ( '' === $entry ) {
				continue;
			}

			if ( filter_var( $entry, FILTER_VALIDATE_IP ) ) {
				$trusted[] = $entry;
				continue;
			}

			if ( preg_match( '/^[0-9a-f:.]+\/\d{1,3}$/i', $entry ) ) {
				$trusted[] = $entry;
			}
		}

		return array_values( array_unique( $trusted ) );
	}

	/**
	 * Check if remote IP belongs to a trusted proxy.
	 *
	 * @param string $remote Remote IP.
	 * @return bool
	 */
	private function is_trusted_proxy( $remote ) {
		if ( ! filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		foreach ( $this->get_trusted_proxies() as $cidr ) {
			if ( $this->ip_matches_cidr( $remote, $cidr ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * IP in CIDR matcher supporting IPv4/IPv6.
	 *
	 * @param string $ip IP address.
	 * @param string $cidr CIDR or exact IP.
	 * @return bool
	 */
	private function ip_matches_cidr( $ip, $cidr ) {
		if ( false === strpos( $cidr, '/' ) ) {
			return $ip === $cidr;
		}

		list( $subnet, $mask_bits ) = explode( '/', $cidr, 2 );
		$mask_bits = (int) $mask_bits;

		$ip_bin     = @inet_pton( $ip );
		$subnet_bin = @inet_pton( $subnet );
		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}

		$max_bits = 8 * strlen( $ip_bin );
		if ( $mask_bits < 0 || $mask_bits > $max_bits ) {
			return false;
		}

		$bytes = (int) floor( $mask_bits / 8 );
		$bits  = $mask_bits % 8;

		if ( $bytes > 0 && substr( $ip_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) {
			return false;
		}

		if ( 0 === $bits ) {
			return true;
		}

		$mask = ( 0xFF << ( 8 - $bits ) ) & 0xFF;
		return ( ord( $ip_bin[ $bytes ] ) & $mask ) === ( ord( $subnet_bin[ $bytes ] ) & $mask );
	}

	/**
	 * Record attempt in debug transient ring buffer.
	 *
	 * @param string $status Status (accepted/rejected/error).
	 * @param string $reason Reason code.
	 * @param array  $context Safe context.
	 * @return void
	 */
	private function record_attempt( $status, $reason, $context = array() ) {
		if ( ! $this->is_debug_enabled() ) {
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

		$attempts = get_transient( self::ATTEMPTS_TRANSIENT );
		if ( ! is_array( $attempts ) ) {
			$legacy = get_option( 'clicutcl_attempts', array() );
			$attempts = is_array( $legacy ) ? $legacy : array();
		}

		$max = (int) apply_filters( 'clicutcl_diag_attempt_buffer_size', 20 );
		$max = max( 1, min( 200, $max ) );

		array_unshift( $attempts, $entry );
		$attempts = array_slice( $attempts, 0, $max );

		$ttl = (int) apply_filters( 'clicutcl_diag_buffer_ttl', 6 * HOUR_IN_SECONDS );
		$ttl = max( HOUR_IN_SECONDS, $ttl );
		set_transient( self::ATTEMPTS_TRANSIENT, $attempts, $ttl );
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
}
