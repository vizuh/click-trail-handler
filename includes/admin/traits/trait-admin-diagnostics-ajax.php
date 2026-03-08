<?php
/**
 * Admin diagnostics/ajax trait.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Admin;

use CLICUTCL\Server_Side\Dispatcher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Admin_Diagnostics_Ajax_Trait {

	public function ajax_log_pii_risk() {
		check_ajax_referer( 'clicutcl_pii_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'click-trail-handler' ) ), 403 );
		}

		// OPTIMIZATION: Check if already detected to save DB writes.
		if ( get_option( 'clicutcl_pii_risk_detected' ) ) {
			wp_send_json_success();
		}

		$pii_found = isset( $_POST['pii_found'] ) ? filter_var( wp_unslash( $_POST['pii_found'] ), FILTER_VALIDATE_BOOLEAN ) : false;

		if ( $pii_found ) {
			update_option( 'clicutcl_pii_risk_detected', true, false );
			wp_send_json_success();
		}
		wp_send_json_error();
	}

	public function display_pii_warning() {
		if ( get_option( 'clicutcl_pii_risk_detected' ) ) {
			$diagnostics_url = admin_url( 'admin.php?page=clicutcl-diagnostics' );
			$settings_url    = admin_url( 'admin.php?page=clicutcl-settings&tab=capture' );
			?>
			<div class="notice notice-error is-dismissible">
				<p><strong><?php esc_html_e( 'ClickTrail Audit detected PII risk on your Thank You page. Your tracking may be deactivated by Google.', 'click-trail-handler' ); ?></strong></p>
				<p>
					<a href="<?php echo esc_url( $diagnostics_url ); ?>" class="button button-primary">
						<?php esc_html_e( 'Open Diagnostics', 'click-trail-handler' ); ?>
					</a>
					<a href="<?php echo esc_url( $settings_url ); ?>" class="button button-secondary">
						<?php esc_html_e( 'Review Tracking Settings', 'click-trail-handler' ); ?>
					</a>
				</p>
			</div>
			<?php
		}
	}

	public function ajax_test_endpoint() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'click-trail-handler' ) ), 403 );
		}
		check_ajax_referer( 'clicutcl_diag', 'nonce' );

		$result = Dispatcher::health_check();

		if ( $result->skipped ) {
			wp_send_json_error(
				array(
					'message' => __( 'Server-side adapter not configured.', 'click-trail-handler' ),
				)
			);
		}

		if ( $result->success ) {
			wp_send_json_success(
				array(
					// translators: %1$d: HTTP status code.
					'message' => sprintf( __( 'Endpoint reachable (HTTP %1$d).', 'click-trail-handler' ), (int) $result->status ),
				)
			);
		}

		wp_send_json_error(
			array(
				// translators: %1$d: HTTP status code, %2$s: error message.
				'message' => sprintf( __( 'Endpoint error (HTTP %1$d): %2$s', 'click-trail-handler' ), (int) $result->status, $result->message ),
			)
		);
	}

	public function ajax_toggle_debug() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'click-trail-handler' ) ), 403 );
		}
		check_ajax_referer( 'clicutcl_diag', 'nonce' );

		$mode = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'on';
		if ( 'off' === $mode ) {
			delete_transient( 'clicutcl_debug_until' );
			wp_send_json_success( array( 'message' => __( 'Debug disabled.', 'click-trail-handler' ) ) );
		}

		$until = time() + ( 15 * MINUTE_IN_SECONDS );
		set_transient( 'clicutcl_debug_until', $until, 15 * MINUTE_IN_SECONDS );
		wp_send_json_success( array( 'message' => __( 'Debug enabled for 15 minutes.', 'click-trail-handler' ) ) );
	}

	/**
	 * Purge local tracking data (events, queue, diagnostics transients).
	 *
	 * @return void
	 */
	public function ajax_purge_tracking_data() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'click-trail-handler' ) ), 403 );
		}
		check_ajax_referer( 'clicutcl_diag', 'nonce' );

		global $wpdb;

		$events_table = $wpdb->prefix . 'clicutcl_events';
		$queue_table  = $wpdb->prefix . 'clicutcl_queue';
		$errors       = array();

		// Clear plugin-owned event and retry tables if present.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$events_ready = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $events_table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$queue_ready = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $queue_table ) );
		if ( $events_ready === $events_table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$result = $wpdb->query( "TRUNCATE TABLE {$events_table}" );
			if ( false === $result ) {
				$errors[] = 'events_truncate_failed';
			}
		}
		if ( $queue_ready === $queue_table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$result = $wpdb->query( "TRUNCATE TABLE {$queue_table}" );
			if ( false === $result ) {
				$errors[] = 'queue_truncate_failed';
			}
		}

		delete_transient( 'clicutcl_last_error' );
		delete_transient( 'clicutcl_dispatch_buffer' );
		delete_transient( 'clicutcl_failure_telemetry' );
		delete_transient( 'clicutcl_failure_flush_lock' );
		delete_transient( 'clicutcl_v2_dedup_stats' );
		delete_transient( 'clicutcl_v2_events_buffer' );

		// Remove legacy fallback options if present.
		delete_option( 'clicutcl_last_error' );
		delete_option( 'clicutcl_dispatch_log' );
		delete_option( 'clicutcl_attempts' );

		if ( ! empty( $errors ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Could not purge all tracking tables.', 'click-trail-handler' ),
					'errors'  => $errors,
				),
				500
			);
		}

		wp_send_json_success(
			array(
				'message' => __( 'Tracking data purged.', 'click-trail-handler' ),
			)
		);
	}

	/**
	 * Return unified admin settings via AJAX.
	 *
	 * @return void
	 */
	public function ajax_get_admin_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'click-trail-handler' ) ), 403 );
		}
		check_ajax_referer( 'clicutcl_admin_settings', 'nonce' );

		wp_send_json_success(
			array(
				'settings' => $this->get_unified_admin_settings(),
			)
		);
	}

	/**
	 * Save unified admin settings via AJAX.
	 *
	 * @return void
	 */
	public function ajax_save_admin_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'click-trail-handler' ) ), 403 );
		}
		check_ajax_referer( 'clicutcl_admin_settings', 'nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$raw = isset( $_POST['settings'] ) ? sanitize_text_field( wp_unslash( $_POST['settings'] ) ) : '';
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		wp_send_json_success(
			array(
				'message'  => __( 'Settings saved.', 'click-trail-handler' ),
				'settings' => $this->save_unified_admin_settings( $raw ),
			)
		);
	}

	/**
	 * Return tracking v2 settings via AJAX.
	 *
	 * @return void
	 */
	public function ajax_get_tracking_v2_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'click-trail-handler' ) ), 403 );
		}
		check_ajax_referer( 'clicutcl_tracking_v2', 'nonce' );

		if ( ! class_exists( 'CLICUTCL\\Tracking\\Settings' ) ) {
			wp_send_json_error( array( 'message' => 'tracking_settings_class_missing' ), 500 );
		}

		wp_send_json_success(
			array(
				'settings' => \CLICUTCL\Tracking\Settings::get_for_admin(),
			)
		);
	}

	/**
	 * Save tracking v2 settings via AJAX.
	 *
	 * @return void
	 */
	public function ajax_save_tracking_v2_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'click-trail-handler' ) ), 403 );
		}
		check_ajax_referer( 'clicutcl_tracking_v2', 'nonce' );

		if ( ! class_exists( 'CLICUTCL\\Tracking\\Settings' ) ) {
			wp_send_json_error( array( 'message' => 'tracking_settings_class_missing' ), 500 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$raw = isset( $_POST['settings'] ) ? sanitize_text_field( wp_unslash( $_POST['settings'] ) ) : '';
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$clean = \CLICUTCL\Tracking\Settings::sanitize( $raw );
		update_option( \CLICUTCL\Tracking\Settings::OPTION, $clean, false );

		wp_send_json_success(
			array(
				'message'  => __( 'Advanced event settings saved.', 'click-trail-handler' ),
				'settings' => \CLICUTCL\Tracking\Settings::get_for_admin(),
			)
		);
	}
}
