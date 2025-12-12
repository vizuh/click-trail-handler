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
use CLICUTCL\Utils\Attribution;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Log_Controller
 */
class Log_Controller extends WP_REST_Controller {

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
	}

	/**
	 * Check permissions.
	 *
	 * @param \WP_REST_Request $request Request instance.
	 * @return bool
	 */
	public function create_item_permissions_check( $request ) {
		// Public endpoint, but we can rate limit or check nonces if we pass them
		return true;
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
			return $this->handle_wa_click( $params );
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

		// Strictly sanitize and cast params.
		$wa_href     = isset( $params['wa_href'] ) ? esc_url_raw( wp_unslash( (string) $params['wa_href'] ) ) : '';
		$wa_location = isset( $params['wa_location'] ) ? esc_url_raw( wp_unslash( (string) $params['wa_location'] ) ) : '';
		$attribution = isset( $params['attribution'] ) ? Attribution::sanitize( $params['attribution'] ) : array();

		if ( ! $wa_href ) {
			return new WP_Error( 'missing_href', 'Missing wa_href', array( 'status' => 400 ) );
		}

		// Strictly validate URL
		$allowed_hosts = array( 'wa.me', 'whatsapp.com', 'api.whatsapp.com', 'web.whatsapp.com' );
		$parsed_url    = wp_parse_url( $wa_href );
		
		if ( ! $parsed_url || ! isset( $parsed_url['host'] ) || ! in_array( $parsed_url['host'], $allowed_hosts, true ) ) {
			return new WP_Error( 'invalid_url', 'Invalid WhatsApp URL', array( 'status' => 400 ) );
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
						'wa_href'     => $wa_href,
						'wa_location' => $wa_location,
						'attribution' => $attribution,
					) ),
					'created_at' => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s' )
			);

			if ( $inserted ) {
				return rest_ensure_response( array( 'success' => true, 'id' => $wpdb->insert_id ) );
			}
		}

		return new WP_Error( 'db_error', 'Could not save event', array( 'status' => 500 ) );
	}
}
