<?php
/**
 * Admin diagnostics/ajax trait.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Admin;

use CLICUTCL\Modules\Consent_Mode\Consent_Mode_Settings;
use CLICUTCL\Modules\GTM\GTM_Settings;
use CLICUTCL\Server_Side\Dispatcher;
use CLICUTCL\Server_Side\Queue;
use CLICUTCL\Server_Side\Settings;
use CLICUTCL\Support\Feature_Registry;
use CLICUTCL\Tracking\Settings as Tracking_Settings;

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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix.
			$result = $wpdb->query( "TRUNCATE TABLE {$events_table}" );
			if ( false === $result ) {
				$errors[] = 'events_truncate_failed';
			}
		}
		if ( $queue_ready === $queue_table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix.
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

	/**
	 * Run the interactive conflict scan.
	 *
	 * @return void
	 */
	public function ajax_conflict_scan() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'click-trail-handler' ) ), 403 );
		}
		check_ajax_referer( 'clicutcl_diag', 'nonce' );

		$report = $this->build_conflict_scan_report();
		wp_send_json_success(
			array(
				'message' => isset( $report['summary'] ) ? $report['summary'] : '',
				'html'    => $this->render_conflict_scan_results( $report ),
				'report'  => $report,
			)
		);
	}

	/**
	 * Run sGTM preview and setup checks against the current admin payload.
	 *
	 * @return void
	 */
	public function ajax_sgtm_preview_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'click-trail-handler' ) ), 403 );
		}
		check_ajax_referer( 'clicutcl_admin_settings', 'nonce' );

		$settings = $this->get_unified_admin_settings();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; raw JSON is decoded before recursive sanitization.
		$raw = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : '';
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$settings = array_replace_recursive( $settings, $decoded );
			}
		}

		$report = $this->build_sgtm_preview_report( $settings );
		wp_send_json_success(
			array(
				'message' => $report['summary'] ?? '',
				'report'  => $report,
			)
		);
	}

	/**
	 * Export a privileged settings backup.
	 *
	 * @return void
	 */
	public function ajax_export_settings_backup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'click-trail-handler' ) ), 403 );
		}
		check_ajax_referer( 'clicutcl_diag', 'nonce' );

		$snapshot = $this->build_settings_backup_snapshot();
		$filename = sprintf(
			'clicktrail-backup-%s.json',
			gmdate( 'Ymd-His' )
		);

		wp_send_json_success(
			array(
				'message'  => __( 'Backup prepared.', 'click-trail-handler' ),
				'filename' => $filename,
				'snapshot' => $snapshot,
			)
		);
	}

	/**
	 * Import a privileged settings backup.
	 *
	 * @return void
	 */
	public function ajax_import_settings_backup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'click-trail-handler' ) ), 403 );
		}
		check_ajax_referer( 'clicutcl_diag', 'nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified above; backup JSON is decoded before import validation and sanitization.
		$raw = isset( $_POST['snapshot'] ) ? wp_unslash( $_POST['snapshot'] ) : '';
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing backup payload.', 'click-trail-handler' ) ), 400 );
		}

		$snapshot = json_decode( $raw, true );
		if ( ! is_array( $snapshot ) ) {
			wp_send_json_error( array( 'message' => __( 'Backup file is not valid JSON.', 'click-trail-handler' ) ), 400 );
		}

		$result = $this->import_settings_backup_snapshot( $snapshot );
		if ( is_wp_error( $result ) ) {
			$status = $result->get_error_data();
			$status = is_array( $status ) && isset( $status['status'] ) ? absint( $status['status'] ) : 400;
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				),
				$status
			);
		}

		wp_send_json_success(
			array(
				'message'  => __( 'Backup restored.', 'click-trail-handler' ),
				'settings' => $this->get_unified_admin_settings(),
			)
		);
	}

	/**
	 * Lookup stored Woo order traces.
	 *
	 * @return void
	 */
	public function ajax_lookup_woo_order_trace() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'click-trail-handler' ) ), 403 );
		}
		check_ajax_referer( 'clicutcl_diag', 'nonce' );

		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'CLICUTCL\\Integrations\\WooCommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'WooCommerce is not active.', 'click-trail-handler' ) ), 400 );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		if ( $order_id < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid WooCommerce order ID.', 'click-trail-handler' ) ), 400 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'click-trail-handler' ) ), 404 );
		}

		$trace_meta = $order->get_meta( \CLICUTCL\Integrations\WooCommerce::TRACE_META_KEY, true );
		$trace_meta = is_array( $trace_meta ) ? $trace_meta : array();
		if ( empty( $trace_meta ) ) {
			wp_send_json_success(
				array(
					'message' => __( 'No ClickTrail traces stored for this order yet.', 'click-trail-handler' ),
					'html'    => $this->render_woo_order_lookup_results( array(), __( 'No ClickTrail traces stored for this order yet.', 'click-trail-handler' ) ),
				)
			);
		}

		$lookup = array(
			'order_id' => $order_id,
			'status'   => sanitize_key( (string) $order->get_status() ),
			'traces'   => array(),
		);

		foreach ( $trace_meta as $event_name => $trace ) {
			if ( ! is_array( $trace ) ) {
				continue;
			}

			$event_name = sanitize_key( (string) $event_name );
			$event_id   = isset( $trace['event_id'] ) ? sanitize_text_field( (string) $trace['event_id'] ) : '';
			$queue_row  = $event_id ? Queue::find_event_row( $event_name, $event_id ) : array();
			$queue      = array(
				'state'  => empty( $queue_row ) ? __( 'not_queued', 'click-trail-handler' ) : __( 'queued', 'click-trail-handler' ),
				'detail' => empty( $queue_row )
					? __( 'No pending retry row found.', 'click-trail-handler' )
					: sprintf(
						/* translators: 1: attempts count, 2: next attempt timestamp. */
						__( 'Attempts: %1$d. Next attempt: %2$s.', 'click-trail-handler' ),
						absint( $queue_row['attempts'] ?? 0 ),
						sanitize_text_field( (string) ( $queue_row['next_attempt_at'] ?? '-' ) )
					),
			);
			$trace['event_name'] = $event_name;
			$trace['queue']      = $queue;
			$lookup['traces'][]  = $trace;
		}

		wp_send_json_success(
			array(
				'message' => __( 'Order trace loaded.', 'click-trail-handler' ),
				'html'    => $this->render_woo_order_lookup_results( $lookup ),
				'lookup'  => $lookup,
			)
		);
	}

	/**
	 * Build deterministic conflict-scan findings.
	 *
	 * @return array<string,mixed>
	 */
	private function build_conflict_scan_report(): array {
		$settings         = $this->get_unified_admin_settings();
		$server_effective = Settings::get();
		$gtm_settings     = get_option( GTM_Settings::OPTION, array() );
		$findings         = array();
		$detected_cache   = $this->detect_cache_conflict_labels();
		$current_adapter  = isset( $server_effective['adapter'] ) ? sanitize_key( (string) $server_effective['adapter'] ) : 'generic';
		$adapter_meta     = Feature_Registry::delivery_adapters();
		$destination_meta = Feature_Registry::destinations();

		if ( ! empty( $detected_cache ) && empty( $settings['forms']['client_fallback'] ) ) {
			$findings[] = array(
				'severity' => 'high',
				'title'    => __( 'Caching or script optimization detected', 'click-trail-handler' ),
				'detail'   => sprintf(
					/* translators: %s: detected plugin labels. */
					__( 'Detected: %s. Turn on the client-side capture fallback to reduce stale attribution and delayed event issues.', 'click-trail-handler' ),
					implode( ', ', $detected_cache )
				),
			);
		}

		if ( ! empty( $server_effective['enabled'] ) && empty( $server_effective['endpoint_url'] ) ) {
			$findings[] = array(
				'severity' => 'high',
				'title'    => __( 'Server-side delivery has no endpoint', 'click-trail-handler' ),
				'detail'   => __( 'Delivery is enabled, but the collector URL is empty.', 'click-trail-handler' ),
			);
		}

		if ( ! class_exists( 'WooCommerce' ) && ! empty( $settings['events']['woocommerce_storefront_events'] ) ) {
			$findings[] = array(
				'severity' => 'high',
				'title'    => __( 'Woo storefront events enabled without WooCommerce', 'click-trail-handler' ),
				'detail'   => __( 'Disable Woo storefront events or activate WooCommerce on this site.', 'click-trail-handler' ),
			);
		}

		$gtm_mode = isset( $gtm_settings['mode'] ) ? sanitize_key( (string) $gtm_settings['mode'] ) : 'standard';
		if ( 'sgtm' === $gtm_mode && empty( $gtm_settings['container_id'] ) ) {
			$findings[] = array(
				'severity' => 'high',
				'title'    => __( 'sGTM mode enabled without a web container', 'click-trail-handler' ),
				'detail'   => __( 'Add a GTM web container ID before testing sGTM mode.', 'click-trail-handler' ),
			);
		}

		if ( 'sgtm' === $gtm_mode && empty( $gtm_settings['tagging_server_url'] ) && empty( $gtm_settings['custom_loader_url'] ) ) {
			$findings[] = array(
				'severity' => 'high',
				'title'    => __( 'sGTM mode has no loader source', 'click-trail-handler' ),
				'detail'   => __( 'Provide a tagging server URL or a custom loader path so ClickTrail can deliver the GTM script in sGTM mode.', 'click-trail-handler' ),
			);
		}

		if ( ! empty( $gtm_settings['custom_loader_enabled'] ) && empty( $gtm_settings['custom_loader_url'] ) ) {
			$findings[] = array(
				'severity' => 'medium',
				'title'    => __( 'Custom loader enabled without a path', 'click-trail-handler' ),
				'detail'   => __( 'Add a same-site path or loader URL before enabling the custom loader option.', 'click-trail-handler' ),
			);
		}

		if ( 'sgtm' === $gtm_mode && ! empty( $server_effective['enabled'] ) && 'sgtm' !== $current_adapter ) {
			$findings[] = array(
				'severity' => 'medium',
				'title'    => __( 'sGTM mode does not match the active delivery adapter', 'click-trail-handler' ),
				'detail'   => __( 'GTM is configured for sGTM mode, but Delivery is not currently using the sGTM adapter.', 'click-trail-handler' ),
			);
		}

		if ( ! empty( $gtm_settings['woo_include_user_data'] ) && empty( $gtm_settings['woo_enhanced_datalayer'] ) ) {
			$findings[] = array(
				'severity' => 'info',
				'title'    => __( 'Woo user_data is enabled without the richer contract', 'click-trail-handler' ),
				'detail'   => __( 'Turn on the richer Woo dataLayer contract if you want ClickTrail to emit consent-aware user_data objects.', 'click-trail-handler' ),
			);
		}

		if ( ! empty( $adapter_meta[ $current_adapter ]['destination'] ) ) {
			$destination_key = sanitize_key( (string) $adapter_meta[ $current_adapter ]['destination'] );
			if ( empty( $settings['events']['destinations'][ $destination_key ] ) ) {
				$findings[] = array(
					'severity' => 'medium',
					'title'    => __( 'Adapter selected without matching destination toggle', 'click-trail-handler' ),
					'detail'   => sprintf(
						/* translators: 1: adapter label, 2: destination label. */
						__( '%1$s is selected as the delivery adapter, but %2$s is off in Events.', 'click-trail-handler' ),
						Feature_Registry::adapter_label( $current_adapter ),
						Feature_Registry::destination_label( $destination_key )
					),
				);
			}
		}

		foreach ( $destination_meta as $destination_key => $destination_row ) {
			$destination_key = sanitize_key( (string) $destination_key );
			$enabled         = ! empty( $settings['events']['destinations'][ $destination_key ] );
			if ( ! $enabled ) {
				continue;
			}

			$adapter_keys = isset( $destination_row['adapter_keys'] ) && is_array( $destination_row['adapter_keys'] ) ? $destination_row['adapter_keys'] : array();
			if ( empty( $adapter_keys ) ) {
				continue;
			}

			if ( ! in_array( $current_adapter, $adapter_keys, true ) && 'generic' !== $current_adapter && 'sgtm' !== $current_adapter ) {
				$findings[] = array(
					'severity' => 'medium',
					'title'    => __( 'Destination toggle does not match the active adapter', 'click-trail-handler' ),
					'detail'   => sprintf(
						/* translators: 1: destination label, 2: adapter label. */
						__( '%1$s is enabled in Events, but Delivery currently uses %2$s.', 'click-trail-handler' ),
						Feature_Registry::destination_label( $destination_key ),
						Feature_Registry::adapter_label( $current_adapter )
					),
				);
			}
		}

		if ( ! empty( $gtm_settings['container_id'] ) ) {
			$native_destinations = array_filter(
				(array) $settings['events']['destinations']
			);
			if ( ! empty( $native_destinations ) ) {
				$findings[] = array(
					'severity' => 'info',
					'title'    => __( 'Review duplicate destination ownership', 'click-trail-handler' ),
					'detail'   => __( 'GTM is configured alongside native destination toggles. Confirm each platform is owned by only one runtime path.', 'click-trail-handler' ),
				);
			}
		}

		if ( empty( $findings ) ) {
			return array(
				'summary'  => __( 'No common conflicts detected in the current ClickTrail configuration.', 'click-trail-handler' ),
				'findings' => array(),
			);
		}

		return array(
			'summary'  => sprintf(
				/* translators: %d: findings count. */
				_n( 'Conflict scan found %d item worth reviewing.', 'Conflict scan found %d items worth reviewing.', count( $findings ), 'click-trail-handler' ),
				count( $findings )
			),
			'findings' => $findings,
		);
	}

	/**
	 * Detect common cache and optimization conflicts.
	 *
	 * @return array<int,string>
	 */
	private function detect_cache_conflict_labels(): array {
		$found = array();
		if ( defined( 'WP_ROCKET_VERSION' ) ) {
			$found[] = 'WP Rocket';
		}
		if ( defined( 'LSCWP_V' ) ) {
			$found[] = 'LiteSpeed Cache';
		}
		if ( defined( 'WPCACHEHOME' ) ) {
			$found[] = 'WP Super Cache';
		}
		if ( defined( 'AUTOPTIMIZE_PLUGIN_VERSION' ) ) {
			$found[] = 'Autoptimize';
		}

		$wpe_api_key = filter_input( INPUT_SERVER, 'WPE_APIKEY', FILTER_UNSAFE_RAW );
		if ( defined( 'WPE_APIKEY' ) || ! empty( $wpe_api_key ) ) {
			$found[] = 'WP Engine';
		}

		$cf_ray = filter_input( INPUT_SERVER, 'HTTP_CF_RAY', FILTER_UNSAFE_RAW );
		if ( ! empty( $cf_ray ) ) {
			$found[] = 'Cloudflare';
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * Build preview checks for the sGTM compatibility layer.
	 *
	 * @param array $settings Unified admin settings payload.
	 * @return array<string,mixed>
	 */
	private function build_sgtm_preview_report( array $settings ): array {
		$events             = isset( $settings['events'] ) && is_array( $settings['events'] ) ? $settings['events'] : array();
		$delivery           = isset( $settings['delivery']['server'] ) && is_array( $settings['delivery']['server'] ) ? $settings['delivery']['server'] : array();
		$destinations       = isset( $events['destinations'] ) && is_array( $events['destinations'] ) ? $events['destinations'] : array();
		$container_id       = sanitize_text_field( (string) ( $events['gtm_container_id'] ?? '' ) );
		$mode               = sanitize_key( (string) ( $events['gtm_mode'] ?? 'standard' ) );
		$gtm_payload        = GTM_Settings::sanitize(
			array(
				'container_id'           => $container_id,
				'mode'                   => $mode,
				'tagging_server_url'     => $events['gtm_tagging_server_url'] ?? '',
				'first_party_script'     => ! empty( $events['gtm_first_party_script'] ) ? 1 : 0,
				'custom_loader_enabled'  => ! empty( $events['gtm_custom_loader_enabled'] ) ? 1 : 0,
				'custom_loader_url'      => $events['gtm_custom_loader_url'] ?? '',
				'woo_enhanced_datalayer' => ! empty( $events['woo_enhanced_datalayer'] ) ? 1 : 0,
				'woo_include_user_data'  => ! empty( $events['woo_include_user_data'] ) ? 1 : 0,
			)
		);
		$tagging_server_url = (string) ( $gtm_payload['tagging_server_url'] ?? '' );
		$loader_url         = GTM_Settings::build_script_src( $gtm_payload, $container_id );
		$current_adapter    = sanitize_key( (string) ( $delivery['adapter'] ?? 'generic' ) );
		$endpoint_url       = esc_url_raw( (string) ( $delivery['endpoint_url'] ?? '' ) );
		$delivery_enabled   = ! empty( $delivery['enabled'] );
		$first_party_on     = ! empty( $gtm_payload['first_party_script'] );
		$custom_loader_on   = ! empty( $gtm_payload['custom_loader_enabled'] );
		$native_destinations = count( array_filter( $destinations ) );

		$checks = array(
			array(
				'key'    => 'web_container',
				'label'  => __( 'Web container', 'click-trail-handler' ),
				'status' => '' !== $container_id ? 'ready' : 'attention',
				'detail' => '' !== $container_id
					? sprintf(
						/* translators: %s: GTM container ID. */
						__( 'Container %s is configured.', 'click-trail-handler' ),
						$container_id
					)
					: __( 'Add a GTM web container ID before using the wizard.', 'click-trail-handler' ),
			),
			array(
				'key'    => 'sgtm_mode',
				'label'  => __( 'sGTM mode', 'click-trail-handler' ),
				'status' => 'sgtm' === $mode ? 'ready' : 'neutral',
				'detail' => 'sgtm' === $mode
					? __( 'sGTM compatibility mode is active.', 'click-trail-handler' )
					: __( 'ClickTrail is still using the standard GTM loader mode.', 'click-trail-handler' ),
			),
			array(
				'key'    => 'tagging_server',
				'label'  => __( 'Tagging server URL', 'click-trail-handler' ),
				'status' => '' !== $tagging_server_url ? 'ready' : ( 'sgtm' === $mode ? 'attention' : 'neutral' ),
				'detail' => '' !== $tagging_server_url
					? $tagging_server_url
					: __( 'Add the tagging server URL if you want first-party script delivery or a stronger preview workflow.', 'click-trail-handler' ),
			),
			array(
				'key'    => 'loader_mode',
				'label'  => __( 'Loader path', 'click-trail-handler' ),
				'status' => ( $custom_loader_on || $first_party_on ) ? 'ready' : ( 'sgtm' === $mode ? 'attention' : 'neutral' ),
				'detail' => $custom_loader_on
					? sprintf(
						/* translators: %s: loader URL. */
						__( 'Custom loader enabled: %s', 'click-trail-handler' ),
						(string) ( $gtm_payload['custom_loader_url'] ?? '' )
					)
					: ( $first_party_on
						? __( 'First-party script delivery is enabled.', 'click-trail-handler' )
						: __( 'Enable first-party delivery or add a custom loader when you want the script to avoid the default Google host.', 'click-trail-handler' ) ),
			),
			array(
				'key'    => 'delivery_adapter',
				'label'  => __( 'Delivery adapter', 'click-trail-handler' ),
				'status' => ( $delivery_enabled && 'sgtm' === $current_adapter ) ? 'ready' : ( $delivery_enabled ? 'attention' : 'neutral' ),
				'detail' => $delivery_enabled
					? sprintf(
						/* translators: %s: adapter label. */
						__( 'Delivery is enabled with %s.', 'click-trail-handler' ),
						Feature_Registry::adapter_label( $current_adapter )
					)
					: __( 'Delivery is off. You can still validate browser-side GTM behavior before enabling server delivery.', 'click-trail-handler' ),
			),
			array(
				'key'    => 'destinations',
				'label'  => __( 'Destination toggles', 'click-trail-handler' ),
				'status' => $native_destinations > 0 ? 'ready' : 'info',
				'detail' => $native_destinations > 0
					? sprintf(
						/* translators: %d: enabled destination count. */
						_n( '%d destination is enabled in Events.', '%d destinations are enabled in Events.', $native_destinations, 'click-trail-handler' ),
						$native_destinations
					)
					: __( 'No native destination toggles are enabled. That is fine if GTM or another relay owns final delivery.', 'click-trail-handler' ),
			),
		);

		if ( '' !== $loader_url ) {
			$checks[] = $this->run_sgtm_http_preview_check(
				'loader_probe',
				__( 'Loader probe', 'click-trail-handler' ),
				$loader_url,
				__( 'Could not reach the configured GTM loader URL from WordPress.', 'click-trail-handler' )
			);
		}

		if ( $delivery_enabled && 'sgtm' === $current_adapter && '' !== $endpoint_url ) {
			$checks[] = $this->run_sgtm_http_preview_check(
				'collector_probe',
				__( 'Collector probe', 'click-trail-handler' ),
				$endpoint_url,
				__( 'Could not reach the configured sGTM collector URL from WordPress.', 'click-trail-handler' )
			);
		}

		$template_hints = $this->build_sgtm_template_hints( $destinations );
		$ready_count    = count(
			array_filter(
				$checks,
				static function ( $check ) {
					return isset( $check['status'] ) && 'ready' === $check['status'];
				}
			)
		);

		return array(
			'summary'        => sprintf(
				/* translators: 1: ready checks count, 2: total checks count. */
				__( 'sGTM preview checks marked %1$d of %2$d items as ready.', 'click-trail-handler' ),
				$ready_count,
				count( $checks )
			),
			'checks'         => $checks,
			'template_hints' => $template_hints,
		);
	}

	/**
	 * Run an HTTP reachability check for the sGTM preview helper.
	 *
	 * @param string $key            Check key.
	 * @param string $label          Check label.
	 * @param string $url            URL to probe.
	 * @param string $fallback_error Default error message.
	 * @return array<string,string>
	 */
	private function run_sgtm_http_preview_check( string $key, string $label, string $url, string $fallback_error ): array {
		$response = wp_remote_request(
			$url,
			array(
				'timeout'     => 5,
				'method'      => 'GET',
				'redirection' => 2,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'key'    => sanitize_key( $key ),
				'label'  => $label,
				'status' => 'attention',
				'detail' => $fallback_error . ' ' . sanitize_text_field( (string) $response->get_error_message() ),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$tone   = ( $status >= 200 && $status < 400 ) ? 'ready' : 'attention';

		return array(
			'key'    => sanitize_key( $key ),
			'label'  => $label,
			'status' => $tone,
			'detail' => sprintf(
				/* translators: 1: URL, 2: HTTP status code. */
				__( '%1$s responded with HTTP %2$d.', 'click-trail-handler' ),
				esc_url_raw( $url ),
				$status
			),
		);
	}

	/**
	 * Build destination template hints for the sGTM setup helper.
	 *
	 * @param array $destinations Destination toggle state.
	 * @return array<int,array<string,string>>
	 */
	private function build_sgtm_template_hints( array $destinations ): array {
		$hints = array();

		if ( ! empty( $destinations['meta'] ) ) {
			$hints[] = array(
				'key'    => 'meta',
				'label'  => Feature_Registry::destination_label( 'meta' ),
				'detail' => __( 'Load the Meta tag and matching server template in GTM, then confirm Meta is owned by either GTM or ClickTrail native delivery, not both.', 'click-trail-handler' ),
			);
		}

		if ( ! empty( $destinations['google'] ) ) {
			$hints[] = array(
				'key'    => 'google',
				'label'  => Feature_Registry::destination_label( 'google' ),
				'detail' => __( 'Confirm your server container has the GA4 client and the Google tags you expect before previewing purchase and checkout events.', 'click-trail-handler' ),
			);
		}

		if ( ! empty( $destinations['linkedin'] ) ) {
			$hints[] = array(
				'key'    => 'linkedin',
				'label'  => Feature_Registry::destination_label( 'linkedin' ),
				'detail' => __( 'Verify the LinkedIn server-side tag template and consent handling in GTM before you rely on ClickTrail purchase or lead events.', 'click-trail-handler' ),
			);
		}

		if ( ! empty( $destinations['pinterest'] ) ) {
			$hints[] = array(
				'key'    => 'pinterest',
				'label'  => Feature_Registry::destination_label( 'pinterest' ),
				'detail' => __( 'Install the Pinterest Conversions template in the server container if GTM owns Pinterest delivery for this site.', 'click-trail-handler' ),
			);
		}

		if ( ! empty( $destinations['tiktok'] ) ) {
			$hints[] = array(
				'key'    => 'tiktok',
				'label'  => Feature_Registry::destination_label( 'tiktok' ),
				'detail' => __( 'Install the TikTok Events API template in the server container if GTM owns TikTok delivery for this site.', 'click-trail-handler' ),
			);
		}

		if ( empty( $hints ) ) {
			$hints[] = array(
				'key'    => 'generic',
				'label'  => __( 'Template ownership', 'click-trail-handler' ),
				'detail' => __( 'Use destination toggles as ownership markers. Keep each downstream platform owned by exactly one delivery path.', 'click-trail-handler' ),
			);
		}

		return $hints;
	}

	/**
	 * Build a settings backup payload.
	 *
	 * @return array<string,mixed>
	 */
	private function build_settings_backup_snapshot(): array {
		return array(
			'meta'    => array(
				'format'         => 'clicktrail-settings-backup',
				'schema_version' => 1,
				'plugin_version' => defined( 'CLICUTCL_VERSION' ) ? CLICUTCL_VERSION : '',
				'generated_at'   => gmdate( DATE_ATOM ),
				'site_url'       => home_url( '/' ),
				'blog_id'        => function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0,
			),
			'options' => array(
				'clicutcl_attribution_settings' => get_option( 'clicutcl_attribution_settings', array() ),
				Consent_Mode_Settings::OPTION   => get_option( Consent_Mode_Settings::OPTION, array() ),
				GTM_Settings::OPTION            => get_option( GTM_Settings::OPTION, array() ),
				Settings::OPTION_SITE           => get_option( Settings::OPTION_SITE, array() ),
				Tracking_Settings::OPTION       => Tracking_Settings::get(),
			),
		);
	}

	/**
	 * Import a settings backup payload.
	 *
	 * @param array $snapshot Backup payload.
	 * @return true|\WP_Error
	 */
	private function import_settings_backup_snapshot( array $snapshot ) {
		$meta    = isset( $snapshot['meta'] ) && is_array( $snapshot['meta'] ) ? $snapshot['meta'] : array();
		$options = isset( $snapshot['options'] ) && is_array( $snapshot['options'] ) ? $snapshot['options'] : array();

		if ( 'clicktrail-settings-backup' !== ( $meta['format'] ?? '' ) ) {
			return new \WP_Error( 'invalid_backup_format', __( 'Backup file format is not supported.', 'click-trail-handler' ), array( 'status' => 400 ) );
		}

		if ( empty( $options ) ) {
			return new \WP_Error( 'invalid_backup_payload', __( 'Backup file does not contain any ClickTrail options.', 'click-trail-handler' ), array( 'status' => 400 ) );
		}

		$attribution = isset( $options['clicutcl_attribution_settings'] ) && is_array( $options['clicutcl_attribution_settings'] )
			? $this->sanitize_settings( $options['clicutcl_attribution_settings'] )
			: $this->sanitize_settings( array() );
		update_option( 'clicutcl_attribution_settings', $attribution, false );

		$consent = isset( $options[ Consent_Mode_Settings::OPTION ] ) && is_array( $options[ Consent_Mode_Settings::OPTION ] )
			? sanitize_option( Consent_Mode_Settings::OPTION, $options[ Consent_Mode_Settings::OPTION ] )
			: sanitize_option( Consent_Mode_Settings::OPTION, array() );
		update_option( Consent_Mode_Settings::OPTION, $consent, false );

		$gtm = isset( $options[ GTM_Settings::OPTION ] ) && is_array( $options[ GTM_Settings::OPTION ] )
			? GTM_Settings::sanitize( $options[ GTM_Settings::OPTION ] )
			: GTM_Settings::sanitize( array() );
		update_option( GTM_Settings::OPTION, $gtm, false );

		$server = isset( $options[ Settings::OPTION_SITE ] ) && is_array( $options[ Settings::OPTION_SITE ] )
			? $this->sanitize_server_side_settings( $options[ Settings::OPTION_SITE ] )
			: $this->sanitize_server_side_settings( array() );
		update_option( Settings::OPTION_SITE, $server, false );

		$tracking = isset( $options[ Tracking_Settings::OPTION ] ) && is_array( $options[ Tracking_Settings::OPTION ] )
			? Tracking_Settings::sanitize( $options[ Tracking_Settings::OPTION ] )
			: Tracking_Settings::sanitize( array() );
		update_option( Tracking_Settings::OPTION, $tracking, false );

		return true;
	}
}
