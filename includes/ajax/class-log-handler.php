<?php
/**
 * AJAX Log Handler
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Ajax;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Log_Handler
 */
class Log_Handler {

	/**
	 * Register AJAX hooks.
	 */
	public function register() {
		// WhatsApp Clicks
		add_action( 'wp_ajax_clicutcl_log_wa_click', array( $this, 'handle_wa_click' ) );
		add_action( 'wp_ajax_nopriv_clicutcl_log_wa_click', array( $this, 'handle_wa_click' ) );
	}

	/**
	 * Handle WhatsApp Click Log.
	 */
	public function handle_wa_click() {
		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), CLICUTCL_PII_NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
		}

		$wa_href     = isset( $_POST['wa_href'] ) ? esc_url_raw( wp_unslash( $_POST['wa_href'] ) ) : '';
		$wa_location = isset( $_POST['wa_location'] ) ? esc_url_raw( wp_unslash( $_POST['wa_location'] ) ) : '';

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON string is decoded and then sanitized.
		$raw_attribution_json = isset( $_POST['attribution'] ) ? wp_unslash( $_POST['attribution'] ) : '';
		$raw_attribution      = json_decode( $raw_attribution_json, true );
		$attribution          = is_array( $raw_attribution ) ? clicutcl_sanitize_attribution_data( $raw_attribution ) : array();

		if ( ! $wa_href ) {
			wp_send_json_error( array( 'message' => 'Missing wa_href' ) );
		}

		// Security: Validate strict WhatsApp URL allowlist
		$allowed_hosts = array( 'wa.me', 'whatsapp.com', 'api.whatsapp.com', 'web.whatsapp.com' );
		$parsed_url    = wp_parse_url( $wa_href );
		
		if ( ! $parsed_url || ! isset( $parsed_url['host'] ) || ! in_array( $parsed_url['host'], $allowed_hosts, true ) ) {
			// Also allow direct phone numbers if your plugin supports that (optional), otherwise strict URL:
			wp_send_json_error( array( 'message' => 'Invalid WhatsApp URL' ) );
		}

		// Create post
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'clicutcl_wa_click',
				'post_title'  => 'WhatsApp Click - ' . gmdate( 'Y-m-d H:i:s' ),
				'post_status' => 'publish',
			)
		);

		if ( ! is_wp_error( $post_id ) ) {
			update_post_meta( $post_id, '_wa_href', $wa_href );
			update_post_meta( $post_id, '_wa_location', $wa_location );
			update_post_meta( $post_id, '_attribution', $attribution );
			wp_send_json_success( array( 'post_id' => $post_id ) );
		} else {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
		}
	}
}
