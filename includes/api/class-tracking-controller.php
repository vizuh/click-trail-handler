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

require_once CLICUTCL_DIR . 'includes/api/traits/trait-tracking-controller-attribution-token.php';
require_once CLICUTCL_DIR . 'includes/api/traits/trait-tracking-controller-security.php';
require_once CLICUTCL_DIR . 'includes/api/traits/trait-tracking-controller-debug.php';

/**
 * Class Tracking_Controller
 */
class Tracking_Controller extends WP_REST_Controller {
	use Tracking_Controller_Attribution_Token_Trait;
	use Tracking_Controller_Security_Trait;
	use Tracking_Controller_Debug_Trait;
	/**
	 * Transient key for v2 debug intake ring buffer.
	 */
	private const INTAKE_DEBUG_TRANSIENT = 'clicutcl_v2_events_buffer';

	/**
	 * Default ring buffer size.
	 */
	private const INTAKE_DEBUG_MAX = 50;

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
	 * Valid lifecycle stage values for the /lifecycle-update endpoint.
	 * Keyed by value for O(1) isset() validation.
	 *
	 * @var array<string,true>
	 */
	private const ALLOWED_LIFECYCLE_STAGES = array(
		'lead'             => true,
		'book_appointment' => true,
		'qualified_lead'   => true,
		'client_won'       => true,
	);

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
			'/attribution-token/sign',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_attribution_token' ),
					'permission_callback' => array( $this, 'attribution_token_sign_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/attribution-token/verify',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'verify_attribution_token' ),
					'permission_callback' => array( $this, 'attribution_token_verify_permissions_check' ),
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
		if ( ! Tracking_Settings::browser_event_collection_enabled() ) {
			$this->record_gate_debug( 'rejected', 'event_v2_disabled', array() );
			return new WP_Error( 'event_v2_disabled', 'Event v2 intake is disabled', array( 'status' => 403 ) );
		}

		$body_size = strlen( (string) $request->get_body() );
		if ( $body_size > self::MAX_BODY_BYTES ) {
			$this->record_gate_debug(
				'rejected',
				'payload_too_large',
				array(
					'body_bytes' => $body_size,
				)
			);
			return new WP_Error( 'payload_too_large', 'Payload too large', array( 'status' => 413 ) );
		}

		$rate = $this->check_rate_limit( 'events_batch' );
		if ( is_wp_error( $rate ) ) {
			$this->record_gate_debug( 'rejected', $rate->get_error_code(), array() );
			return $rate;
		}

		// Allow logged-in admin/debug flows only for administrators.
		if ( $this->verify_rest_nonce( $request ) && current_user_can( 'manage_options' ) ) {
			return true;
		}

		$body  = $request->get_json_params();
		$token = $request->get_header( 'x-clicutcl-token' );
		if ( ! $token && is_array( $body ) && ! empty( $body['token'] ) ) {
			$token = sanitize_text_field( (string) $body['token'] );
		}

		$verified = Auth::verify_client_token( (string) $token );
		if ( is_wp_error( $verified ) ) {
			$this->record_gate_debug( 'rejected', $verified->get_error_code(), array() );
			return $verified;
		}

		$nonce_limit = $this->check_token_nonce_limit( $verified );
		if ( is_wp_error( $nonce_limit ) ) {
			$this->record_gate_debug( 'rejected', $nonce_limit->get_error_code(), array() );
			return $nonce_limit;
		}

		$this->record_gate_debug( 'accepted', 'ok', array() );
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
			$result = $this->process_single_event( $raw_event, $index );
			switch ( $result['status'] ) {
				case 'accepted':
					$accepted++;
					break;
				case 'duplicate':
					$duplicates++;
					break;
				case 'skipped':
					$skipped++;
					break;
				case 'error':
					$errors[] = $result['error'];
					break;
			}
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
	 * Validate, deduplicate, resolve identity, and dispatch a single event.
	 *
	 * @param mixed $raw_event Raw event data.
	 * @param int   $index     Event index in the batch.
	 * @return array{status: string, error?: array} Result with 'status' key (accepted|duplicate|skipped|error).
	 */
	private function process_single_event( $raw_event, int $index ): array {
		if ( ! is_array( $raw_event ) ) {
			$this->record_intake_debug(
				array(
					'event_name' => '',
					'event_id'   => '',
					'consent'    => array(),
				),
				'error',
				'invalid_event'
			);
			return array(
				'status' => 'error',
				'error'  => array(
					'index'  => $index,
					'code'   => 'invalid_event',
					'detail' => 'Event must be an object',
				),
			);
		}

		$canonical = EventV2::normalize( $raw_event );
		if ( ! EventV2::validate( $canonical ) ) {
			$this->record_intake_debug( $canonical, 'error', 'invalid_schema' );
			return array(
				'status' => 'error',
				'error'  => array(
					'index'  => $index,
					'code'   => 'invalid_schema',
					'detail' => 'Event does not satisfy canonical schema',
				),
			);
		}

		if ( Dedup_Store::is_duplicate( 'ingest', (string) $canonical['event_name'], (string) $canonical['event_id'] ) ) {
			$this->record_intake_debug( $canonical, 'duplicate', 'duplicate_ingest' );
			return array( 'status' => 'duplicate' );
		}

		$resolver              = new Identity_Resolver();
		$canonical['identity'] = $resolver->resolve(
			$raw_event['identity'] ?? array(),
			array(
				'marketing_allowed' => ! empty( $canonical['consent']['marketing'] ),
			)
		);

		$dispatch = Dispatcher::dispatch_from_v2( $canonical );
		if ( $dispatch->skipped ) {
			$this->record_intake_debug( $canonical, 'skipped', sanitize_key( (string) $dispatch->message ) );
			return array( 'status' => 'skipped' );
		}
		if ( ! $dispatch->success ) {
			$this->record_intake_debug( $canonical, 'error', 'dispatch_failed' );
			return array(
				'status' => 'error',
				'error'  => array(
					'index'  => $index,
					'code'   => 'dispatch_failed',
					'detail' => sanitize_text_field( (string) $dispatch->message ),
				),
			);
		}

		Dedup_Store::mark( 'ingest', (string) $canonical['event_name'], (string) $canonical['event_id'] );
		$this->record_intake_debug( $canonical, 'accepted', 'ok' );
		return array( 'status' => 'accepted' );
	}

	/**
	 * Attribution token signing permission check.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function attribution_token_sign_permissions_check( WP_REST_Request $request ) {
		$body_size = strlen( (string) $request->get_body() );
		if ( $body_size > self::MAX_BODY_BYTES ) {
			return new WP_Error( 'payload_too_large', 'Payload too large', array( 'status' => 413 ) );
		}

		$rate = $this->check_rate_limit( 'attribution_token_sign' );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$token    = $this->extract_token_from_request( $request );
		$verified = Auth::verify_client_token( $token );
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
	 * Attribution token verification permission check.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function attribution_token_verify_permissions_check( WP_REST_Request $request ) {
		$body_size = strlen( (string) $request->get_body() );
		if ( $body_size > self::MAX_BODY_BYTES ) {
			return new WP_Error( 'payload_too_large', 'Payload too large', array( 'status' => 413 ) );
		}

		$rate = $this->check_rate_limit( 'attribution_token_verify' );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		return true;
	}

	/**
	 * Create server-signed attribution token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function create_attribution_token( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();
		$data    = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();
		$data    = $this->sanitize_attribution_token_data( $data );

		if ( empty( $data ) ) {
			return new WP_Error( 'invalid_attribution_data', 'No allowed attribution fields to sign', array( 'status' => 400 ) );
		}

		$token = $this->mint_signed_attribution_token( $data );
		if ( '' === $token ) {
			return new WP_Error( 'token_generation_failed', 'Unable to sign attribution token', array( 'status' => 500 ) );
		}

		return array(
			'success' => true,
			'token'   => $token,
		);
	}

	/**
	 * Verify server-signed attribution token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function verify_attribution_token( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();
		$token   = isset( $payload['token'] ) ? sanitize_text_field( (string) $payload['token'] ) : '';
		if ( '' === $token ) {
			$token = sanitize_text_field( (string) $request->get_header( 'x-clicutcl-attribution-token' ) );
		}
		if ( '' === $token ) {
			return new WP_Error( 'invalid_token', 'Missing attribution token', array( 'status' => 400 ) );
		}

		$verified = $this->verify_signed_attribution_token( $token );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		return array(
			'success' => true,
			'data'    => isset( $verified['data'] ) ? $verified['data'] : array(),
		);
	}

	/**
	 * Webhook permission check.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function webhook_permissions_check( WP_REST_Request $request ) {
		// Guard: reject oversized payloads before any processing.
		$body_size = strlen( (string) $request->get_body() );
		if ( $body_size > self::MAX_BODY_BYTES ) {
			return new WP_Error(
				'payload_too_large',
				__( 'Payload too large.', 'click-trail-handler' ),
				array( 'status' => 413 )
			);
		}

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
		// Guard: reject oversized payloads before any processing.
		$body_size = strlen( (string) $request->get_body() );
		if ( $body_size > self::MAX_BODY_BYTES ) {
			return new WP_Error(
				'payload_too_large',
				__( 'Payload too large.', 'click-trail-handler' ),
				array( 'status' => 413 )
			);
		}

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
		if ( ! isset( self::ALLOWED_LIFECYCLE_STAGES[ $stage ] ) ) {
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
		$data                 = Dispatcher::get_delivery_diagnostics();
		$data['event_intake'] = self::get_debug_event_buffer();

		return array(
			'success' => true,
			'data'    => $data,
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
