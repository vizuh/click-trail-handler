<?php
/**
 * REST API Tracking Controller v2
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Api;

use CLICUTCL\Server_Side\Dispatcher;
use CLICUTCL\Tracking\Auth;
use CLICUTCL\Tracking\Dedup_Store;
use CLICUTCL\Tracking\Event_Translator_V1_To_V2;
use CLICUTCL\Tracking\EventV2;
use CLICUTCL\Tracking\Identity_Resolver;
use CLICUTCL\Tracking\Settings as Tracking_Settings;
use CLICUTCL\Tracking\Webhook_Auth;
use CLICUTCL\Tracking\Webhooks\CalendlyWebhookAdapter;
use CLICUTCL\Tracking\Webhooks\HubSpotWebhookAdapter;
use CLICUTCL\Tracking\Webhooks\TypeformWebhookAdapter;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Tracking_Controller
 */
class Tracking_Controller extends WP_REST_Controller {
	/**
	 * Maximum JSON request body size in bytes.
	 */
	private const MAX_BODY_BYTES = 131072;

	/**
	 * Maximum events accepted per batch.
	 */
	private const MAX_BATCH_EVENTS = 50;

	/**
	 * Default rate limit (requests per window).
	 */
	private const RATE_LIMIT_DEFAULT = 60;

	/**
	 * Rate limit window in seconds.
	 */
	private const RATE_WINDOW_DEFAULT = 60;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'clicutcl/v2';
		$this->rest_base = 'events';
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/events/batch',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'batch_events' ),
					'permission_callback' => array( $this, 'batch_events_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/webhooks/(?P<provider>[a-z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'ingest_webhook' ),
					'permission_callback' => array( $this, 'webhook_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/lifecycle/update',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'lifecycle_update' ),
					'permission_callback' => array( $this, 'lifecycle_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/diagnostics/delivery',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'diagnostics_delivery' ),
					'permission_callback' => array( $this, 'diagnostics_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/diagnostics/dedup',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'diagnostics_dedup' ),
					'permission_callback' => array( $this, 'diagnostics_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Batch events permission check.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function batch_events_permissions_check( WP_REST_Request $request ) {
		if ( ! Tracking_Settings::feature_enabled( 'event_v2' ) ) {
			return new WP_Error( 'event_v2_disabled', 'Event v2 intake is disabled', array( 'status' => 403 ) );
		}

		$body_size = strlen( (string) $request->get_body() );
		if ( $body_size > self::MAX_BODY_BYTES ) {
			return new WP_Error( 'payload_too_large', 'Payload too large', array( 'status' => 413 ) );
		}

		$rate = $this->check_rate_limit( 'events_batch' );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		// Allow logged-in admin/debug flows.
		if ( $this->verify_rest_nonce( $request ) ) {
			return true;
		}

		$body  = $request->get_json_params();
		$token = $request->get_header( 'x-clicutcl-token' );
		if ( ! $token && is_array( $body ) && ! empty( $body['token'] ) ) {
			$token = sanitize_text_field( (string) $body['token'] );
		}

		$verified = Auth::verify_client_token( (string) $token );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		$nonce_limit = $this->check_token_nonce_limit( $verified );
		if ( is_wp_error( $nonce_limit ) ) {
			return $nonce_limit;
		}

		return true;
	}

	/**
	 * Receive and dispatch canonical batch events.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public function batch_events( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();

		$events = array();
		if ( isset( $payload['events'] ) && is_array( $payload['events'] ) ) {
			$events = array_slice( $payload['events'], 0, self::MAX_BATCH_EVENTS );
		} else {
			$events = array( $payload );
		}

		$accepted   = 0;
		$duplicates = 0;
		$skipped    = 0;
		$errors     = array();

		foreach ( $events as $index => $raw_event ) {
			if ( ! is_array( $raw_event ) ) {
				$errors[] = array(
					'index'  => $index,
					'code'   => 'invalid_event',
					'detail' => 'Event must be an object',
				);
				continue;
			}

			$canonical = Event_Translator_V1_To_V2::translate( $raw_event );
			if ( ! EventV2::validate( $canonical ) ) {
				$errors[] = array(
					'index'  => $index,
					'code'   => 'invalid_schema',
					'detail' => 'Event does not satisfy canonical schema',
				);
				continue;
			}

			if ( Dedup_Store::is_duplicate( 'ingest', (string) $canonical['event_name'], (string) $canonical['event_id'] ) ) {
				$duplicates++;
				continue;
			}

			$resolver             = new Identity_Resolver();
			$canonical['identity'] = $resolver->resolve(
				$raw_event['identity'] ?? array(),
				array(
					'marketing_allowed' => ! empty( $canonical['consent']['marketing'] ),
				)
			);

			$dispatch = Dispatcher::dispatch_from_v2( $canonical );
			if ( $dispatch->skipped ) {
				$skipped++;
				continue;
			}
			if ( ! $dispatch->success ) {
				$errors[] = array(
					'index'  => $index,
					'code'   => 'dispatch_failed',
					'detail' => sanitize_text_field( (string) $dispatch->message ),
				);
				continue;
			}

			Dedup_Store::mark( 'ingest', (string) $canonical['event_name'], (string) $canonical['event_id'] );
			$accepted++;
		}

		return array(
			'success'    => empty( $errors ),
			'accepted'   => $accepted,
			'duplicates' => $duplicates,
			'skipped'    => $skipped,
			'errors'     => $errors,
		);
	}

	/**
	 * Webhook permission check.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function webhook_permissions_check( WP_REST_Request $request ) {
		if ( ! Tracking_Settings::feature_enabled( 'external_webhooks' ) ) {
			return new WP_Error( 'external_webhooks_disabled', 'External webhooks are disabled', array( 'status' => 403 ) );
		}

		$provider = sanitize_key( (string) $request['provider'] );
		if ( ! Tracking_Settings::is_provider_enabled( $provider ) ) {
			return new WP_Error( 'provider_disabled', 'Provider is disabled', array( 'status' => 403 ) );
		}

		$secret = Tracking_Settings::get_provider_secret( $provider );
		$valid  = Webhook_Auth::verify_request( $request, $secret );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		return true;
	}

	/**
	 * Ingest external provider webhook.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function ingest_webhook( WP_REST_Request $request ) {
		$provider = sanitize_key( (string) $request['provider'] );
		$adapter  = $this->provider_adapter( $provider );
		if ( ! $adapter ) {
			return new WP_Error( 'provider_not_supported', 'Provider not supported', array( 'status' => 400 ) );
		}

		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();
		if ( ! $adapter->supports( $payload ) ) {
			return new WP_Error( 'invalid_provider_payload', 'Payload not supported by provider adapter', array( 'status' => 400 ) );
		}

		$canonical = $adapter->map_to_canonical( $payload );
		if ( ! EventV2::validate( $canonical ) ) {
			return new WP_Error( 'invalid_schema', 'Mapped payload is invalid', array( 'status' => 400 ) );
		}

		if ( Dedup_Store::is_duplicate( 'webhook_' . $provider, (string) $canonical['event_name'], (string) $canonical['event_id'] ) ) {
			return array(
				'success'    => true,
				'duplicate'  => true,
				'event_id'   => $canonical['event_id'],
				'event_name' => $canonical['event_name'],
			);
		}

		$dispatch = Dispatcher::dispatch_from_v2( $canonical );
		if ( ! $dispatch->success && ! $dispatch->skipped ) {
			return new WP_Error( 'dispatch_failed', sanitize_text_field( (string) $dispatch->message ), array( 'status' => 500 ) );
		}

		if ( $dispatch->success ) {
			Dedup_Store::mark( 'webhook_' . $provider, (string) $canonical['event_name'], (string) $canonical['event_id'] );
		}

		return array(
			'success'    => true,
			'duplicate'  => false,
			'event_id'   => $canonical['event_id'],
			'event_name' => $canonical['event_name'],
		);
	}

	/**
	 * Lifecycle update permissions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function lifecycle_permissions_check( WP_REST_Request $request ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( ! Tracking_Settings::feature_enabled( 'lifecycle_ingestion' ) ) {
			return new WP_Error( 'lifecycle_disabled', 'Lifecycle ingestion is disabled', array( 'status' => 403 ) );
		}

		$token = $request->get_header( 'x-clicutcl-crm-token' );
		if ( ! $token ) {
			$body  = $request->get_json_params();
			$token = is_array( $body ) && ! empty( $body['token'] ) ? sanitize_text_field( (string) $body['token'] ) : '';
		}

		$expected = Tracking_Settings::get_lifecycle_token();
		if ( '' === $expected || ! hash_equals( $expected, (string) $token ) ) {
			return new WP_Error( 'crm_unauthorized', 'Invalid lifecycle token', array( 'status' => 401 ) );
		}

		return true;
	}

	/**
	 * Ingest lifecycle stage updates (qualified lead/client won/etc).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function lifecycle_update( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();

		$stage = isset( $payload['stage'] ) ? sanitize_key( (string) $payload['stage'] ) : '';
		$allow = array( 'lead', 'book_appointment', 'qualified_lead', 'client_won' );
		if ( ! in_array( $stage, $allow, true ) ) {
			return new WP_Error( 'invalid_stage', 'Invalid lifecycle stage', array( 'status' => 400 ) );
		}

		$lead_id = isset( $payload['lead_id'] ) ? sanitize_text_field( (string) $payload['lead_id'] ) : '';
		$event_id = isset( $payload['event_id'] ) ? sanitize_text_field( (string) $payload['event_id'] ) : '';
		if ( '' === $event_id ) {
			$event_id = 'lifecycle_' . md5( $stage . '|' . $lead_id . '|' . wp_json_encode( $payload ) );
		}

		$canonical = Event_Translator_V1_To_V2::translate(
			array(
				'event_name'   => $stage,
				'event_id'     => $event_id,
				'source'       => 'crm',
				'lead_context' => array(
					'lead_id'       => $lead_id,
					'provider'      => sanitize_text_field( (string) ( $payload['provider'] ?? 'crm' ) ),
					'submit_status' => 'success',
				),
				'meta'         => array(
					'lifecycle' => true,
				),
			)
		);

		$dispatch = Dispatcher::dispatch_from_v2( $canonical );
		if ( ! $dispatch->success && ! $dispatch->skipped ) {
			return new WP_Error( 'dispatch_failed', sanitize_text_field( (string) $dispatch->message ), array( 'status' => 500 ) );
		}

		return array(
			'success'    => true,
			'event_id'   => $canonical['event_id'],
			'event_name' => $canonical['event_name'],
		);
	}

	/**
	 * Diagnostics permissions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function diagnostics_permissions_check( WP_REST_Request $request ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return current_user_can( 'manage_options' );
	}

	/**
	 * Delivery diagnostics.
	 *
	 * @return array
	 */
	public function diagnostics_delivery(): array {
		return array(
			'success' => true,
			'data'    => Dispatcher::get_delivery_diagnostics(),
		);
	}

	/**
	 * Dedup diagnostics.
	 *
	 * @return array
	 */
	public function diagnostics_dedup(): array {
		return array(
			'success' => true,
			'data'    => Dedup_Store::get_stats(),
		);
	}

	/**
	 * Verify wp_rest nonce.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	private function verify_rest_nonce( WP_REST_Request $request ): bool {
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
	 * Basic per-IP rate limiter.
	 *
	 * @param string $scope Scope key.
	 * @return true|WP_Error
	 */
	private function check_rate_limit( string $scope ) {
		$scope  = sanitize_key( $scope );
		$window = (int) apply_filters( 'clicutcl_v2_rate_window', self::RATE_WINDOW_DEFAULT, $scope );
		$limit  = (int) apply_filters( 'clicutcl_v2_rate_limit', self::RATE_LIMIT_DEFAULT, $scope );
		$window = max( 5, min( 3600, $window ) );
		$limit  = max( 1, min( 2000, $limit ) );

		$ip  = $this->get_client_ip();
		$key = 'clicutcl_v2_rl_' . md5( $scope . '|' . $ip );
		$hit = (int) get_transient( $key );
		if ( $hit >= $limit ) {
			return new WP_Error( 'rate_limited', 'Too many requests', array( 'status' => 429 ) );
		}

		set_transient( $key, $hit + 1, $window );
		return true;
	}

	/**
	 * Optional replay-limit keyed by token nonce and client IP.
	 *
	 * @param array $claims Verified token claims.
	 * @return true|WP_Error
	 */
	private function check_token_nonce_limit( array $claims ) {
		$nonce = isset( $claims['nonce'] ) ? sanitize_text_field( (string) $claims['nonce'] ) : '';
		if ( '' === $nonce ) {
			return true;
		}

		$settings_limit = Tracking_Settings::get()['security']['token_nonce_limit'] ?? 0;
		$limit = (int) apply_filters( 'clicutcl_v2_token_nonce_limit', (int) $settings_limit );
		$limit = max( 0, min( 5000, $limit ) );
		if ( 0 === $limit ) {
			return true;
		}

		$ttl = isset( $claims['exp'] ) ? max( 60, absint( $claims['exp'] ) - time() ) : HOUR_IN_SECONDS;
		$ip  = $this->get_client_ip();
		$key = 'clicutcl_v2_nonce_' . md5( $nonce . '|' . $ip );
		$hit = (int) get_transient( $key );
		if ( $hit >= $limit ) {
			return new WP_Error( 'nonce_replay_limited', 'Too many requests for token nonce', array( 'status' => 429 ) );
		}

		set_transient( $key, $hit + 1, $ttl );
		return true;
	}

	/**
	 * Resolve client IP (best-effort).
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
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
	 * Resolve trusted proxy CIDRs/IPs from settings and filters.
	 *
	 * @return array
	 */
	private function get_trusted_proxies(): array {
		$settings = Tracking_Settings::get();
		$list     = $settings['security']['trusted_proxies'] ?? array();
		$list     = apply_filters( 'clicutcl_v2_trusted_proxies', $list );
		$list     = apply_filters( 'clicutcl_trusted_proxies', $list );
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
	 * Get direct remote address.
	 *
	 * @return string
	 */
	private function get_remote_addr(): string {
		$remote = filter_input( INPUT_SERVER, 'REMOTE_ADDR', FILTER_UNSAFE_RAW );
		$remote = $remote ? sanitize_text_field( (string) $remote ) : '';
		if ( filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return $remote;
		}

		return '0.0.0.0';
	}

	/**
	 * Check whether remote IP is a trusted proxy.
	 *
	 * @param string $remote Remote IP.
	 * @return bool
	 */
	private function is_trusted_proxy( string $remote ): bool {
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
	 * Match IP against CIDR or exact IP (IPv4/IPv6).
	 *
	 * @param string $ip   IP.
	 * @param string $cidr CIDR or IP.
	 * @return bool
	 */
	private function ip_matches_cidr( string $ip, string $cidr ): bool {
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
	 * Resolve provider adapter.
	 *
	 * @param string $provider Provider key.
	 * @return \CLICUTCL\Tracking\WebhookProviderAdapterInterface|null
	 */
	private function provider_adapter( string $provider ) {
		switch ( sanitize_key( $provider ) ) {
			case 'calendly':
				return new CalendlyWebhookAdapter();
			case 'hubspot':
				return new HubSpotWebhookAdapter();
			case 'typeform':
				return new TypeformWebhookAdapter();
			default:
				return null;
		}
	}
}
