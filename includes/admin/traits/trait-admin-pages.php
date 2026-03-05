<?php
/**
 * Admin page rendering trait.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Admin;

use CLICUTCL\Server_Side\Dispatcher;
use CLICUTCL\Server_Side\Queue;
use CLICUTCL\Server_Side\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Admin_Pages_Trait {
	public function network_admin_menu() {
		add_menu_page(
			__( 'ClickTrail Network', 'click-trail-handler' ),
			__( 'ClickTrail', 'click-trail-handler' ),
			'manage_network_options',
			'clicutcl-network-settings',
			array( $this, 'render_network_settings_page' ),
			'dashicons-chart-area',
			56
		);
	}

	public function render_network_settings_page() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return;
		}

		$options = Settings::get_network();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'ClickTrail Network Settings', 'click-trail-handler' ); ?></h1>
			<form method="post" action="edit.php?action=clicutcl_network_settings">
				<?php wp_nonce_field( 'clicutcl_network_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Server-side Sending', 'click-trail-handler' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="clicutcl_server_side_network[enabled]" value="1" <?php checked( 1, $options['enabled'] ?? 0 ); ?> />
								<?php esc_html_e( 'Enabled', 'click-trail-handler' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Collector Endpoint URL', 'click-trail-handler' ); ?></th>
						<td>
							<input type="text" name="clicutcl_server_side_network[endpoint_url]" value="<?php echo esc_attr( $options['endpoint_url'] ?? '' ); ?>" class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Adapter Type', 'click-trail-handler' ); ?></th>
						<td>
							<select name="clicutcl_server_side_network[adapter]">
								<?php
								$adapter = $options['adapter'] ?? 'generic';
								$choices = array(
									'generic'       => __( 'Generic Collector', 'click-trail-handler' ),
									'sgtm'          => __( 'sGTM (Server GTM)', 'click-trail-handler' ),
									'meta_capi'     => __( 'Meta CAPI', 'click-trail-handler' ),
									'google_ads'    => __( 'Google Ads / GA4', 'click-trail-handler' ),
									'linkedin_capi' => __( 'LinkedIn CAPI', 'click-trail-handler' ),
								);
								foreach ( $choices as $value => $label ) :
									?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $adapter, $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Request Timeout (seconds)', 'click-trail-handler' ); ?></th>
						<td>
							<input type="number" min="1" max="15" name="clicutcl_server_side_network[timeout]" value="<?php echo esc_attr( $options['timeout'] ?? 5 ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Remote Failure Telemetry (Opt-in)', 'click-trail-handler' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="clicutcl_server_side_network[remote_failure_telemetry]" value="1" <?php checked( 1, $options['remote_failure_telemetry'] ?? 0 ); ?> />
								<?php esc_html_e( 'Enable aggregated remote failure reporting hook', 'click-trail-handler' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Disabled by default. Sends only aggregated failure counts (no payloads, no PII).', 'click-trail-handler' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public function save_network_settings() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'click-trail-handler' ) );
		}

		check_admin_referer( 'clicutcl_network_settings' );

		$raw   = filter_input( INPUT_POST, 'clicutcl_server_side_network', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
		$raw   = is_array( $raw ) ? $raw : array();
		$raw   = map_deep( $raw, 'sanitize_text_field' );
		$clean = $this->sanitize_server_side_settings( $raw );

		update_site_option( Settings::OPTION_NETWORK, $clean );

		wp_safe_redirect( network_admin_url( 'admin.php?page=clicutcl-network-settings&updated=1' ) );
		exit;
	}

	public function diagnostics_page() {
		$last_error = get_transient( 'clicutcl_last_error' );
		if ( ! is_array( $last_error ) ) {
			$last_error = get_option( 'clicutcl_last_error', array() );
		}
		$last_error_time = '';
		if ( isset( $last_error['time'] ) ) {
			$last_error_time = date_i18n( 'Y-m-d H:i:s', (int) $last_error['time'] );
		}

		$dispatches = get_transient( 'clicutcl_dispatch_buffer' );
		if ( ! is_array( $dispatches ) ) {
			$dispatches = get_option( 'clicutcl_dispatch_log', array() );
		}
		if ( ! is_array( $dispatches ) ) {
			$dispatches = array();
		}
		$failure_telemetry = Dispatcher::get_failure_telemetry();
		$v2_intake         = class_exists( 'CLICUTCL\\Api\\Tracking_Controller' ) ? \CLICUTCL\Api\Tracking_Controller::get_debug_event_buffer() : array();
		$queue_stats       = class_exists( 'CLICUTCL\\Server_Side\\Queue' ) ? Queue::get_stats() : array();

		$debug_until     = get_transient( 'clicutcl_debug_until' );
		$debug_active    = $debug_until && (int) $debug_until > time();
		$debug_until_str = $debug_active ? date_i18n( 'Y-m-d H:i:s', (int) $debug_until ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'ClickTrail Diagnostics', 'click-trail-handler' ); ?></h1>

			<h2><?php esc_html_e( 'Endpoint Test', 'click-trail-handler' ); ?></h2>
			<p>
				<button class="button" id="clicutcl-test-endpoint"><?php esc_html_e( 'Test Endpoint', 'click-trail-handler' ); ?></button>
				<span id="clicutcl-test-endpoint-status" style="margin-left:10px;"></span>
			</p>
			<p class="description">
				<?php esc_html_e( 'Checks if your server-side endpoint is reachable.', 'click-trail-handler' ); ?>
			</p>

			<h2><?php esc_html_e( 'Last Error', 'click-trail-handler' ); ?></h2>
			<?php if ( ! empty( $last_error ) ) : ?>
				<p>
					<strong><?php echo esc_html( $last_error['code'] ?? '' ); ?></strong>
					<?php echo esc_html( $last_error['message'] ?? '' ); ?>
					<?php if ( $last_error_time ) : ?>
						<span style="margin-left:8px;color:#666;"><?php echo esc_html( $last_error_time ); ?></span>
					<?php endif; ?>
				</p>
			<?php else : ?>
				<p><?php esc_html_e( 'No errors recorded.', 'click-trail-handler' ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Debug Logging', 'click-trail-handler' ); ?></h2>
			<p>
				<?php if ( $debug_active ) : ?>
					<strong><?php esc_html_e( 'Enabled', 'click-trail-handler' ); ?></strong>
					<span style="margin-left:8px;color:#666;"><?php echo esc_html( $debug_until_str ); ?></span>
				<?php else : ?>
					<strong><?php esc_html_e( 'Disabled', 'click-trail-handler' ); ?></strong>
				<?php endif; ?>
			</p>
			<p>
				<button class="button" id="clicutcl-debug-toggle" data-mode="<?php echo esc_attr( $debug_active ? 'off' : 'on' ); ?>">
					<?php echo esc_html( $debug_active ? __( 'Disable Debug', 'click-trail-handler' ) : __( 'Enable 15 Minutes', 'click-trail-handler' ) ); ?>
				</button>
				<span id="clicutcl-debug-status" style="margin-left:10px;"></span>
			</p>

			<h2><?php esc_html_e( 'Failure Telemetry (Always On)', 'click-trail-handler' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Failure-only aggregated counters. No payload bodies and no PII are stored.', 'click-trail-handler' ); ?>
			</p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Hour Bucket', 'click-trail-handler' ); ?></th>
						<th><?php esc_html_e( 'Total Failures', 'click-trail-handler' ); ?></th>
						<th><?php esc_html_e( 'Codes', 'click-trail-handler' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! empty( $failure_telemetry ) ) : ?>
					<?php foreach ( $failure_telemetry as $bucket_key => $bucket ) : ?>
						<?php
						$bucket_start = isset( $bucket['bucket_start'] ) ? absint( $bucket['bucket_start'] ) : 0;
						if ( ! $bucket_start && preg_match( '/^\d{10}$/', (string) $bucket_key ) ) {
							$year         = (int) substr( (string) $bucket_key, 0, 4 );
							$month        = (int) substr( (string) $bucket_key, 4, 2 );
							$day          = (int) substr( (string) $bucket_key, 6, 2 );
							$hour         = (int) substr( (string) $bucket_key, 8, 2 );
							$bucket_start = gmmktime( $hour, 0, 0, $month, $day, $year );
						}
						$codes      = isset( $bucket['codes'] ) && is_array( $bucket['codes'] ) ? $bucket['codes'] : array();
						$code_parts = array();
						foreach ( $codes as $code => $count ) {
							$code_parts[] = sanitize_key( (string) $code ) . ': ' . absint( $count );
						}
						?>
						<tr>
							<td><?php echo esc_html( $bucket_start ? date_i18n( 'Y-m-d H:00', $bucket_start ) : (string) $bucket_key ); ?></td>
							<td><?php echo esc_html( (string) absint( $bucket['total'] ?? 0 ) ); ?></td>
							<td><?php echo esc_html( $code_parts ? implode( ' | ', $code_parts ) : '-' ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr>
						<td colspan="3"><?php esc_html_e( 'No failures recorded yet.', 'click-trail-handler' ); ?></td>
					</tr>
				<?php endif; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Recent Dispatches', 'click-trail-handler' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Dispatch entries are captured only while Debug Logging is enabled.', 'click-trail-handler' ); ?>
			</p>

			<h2><?php esc_html_e( 'Queue Backlog', 'click-trail-handler' ); ?></h2>
			<table class="widefat striped" style="max-width:900px;">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'Queue Ready', 'click-trail-handler' ); ?></th>
						<td><?php echo ! empty( $queue_stats['ready'] ) ? esc_html__( 'Yes', 'click-trail-handler' ) : esc_html__( 'No', 'click-trail-handler' ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Pending Events', 'click-trail-handler' ); ?></th>
						<td><?php echo esc_html( (string) absint( $queue_stats['pending'] ?? 0 ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Due Now', 'click-trail-handler' ); ?></th>
						<td><?php echo esc_html( (string) absint( $queue_stats['due_now'] ?? 0 ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Max Attempts in Queue', 'click-trail-handler' ); ?></th>
						<td><?php echo esc_html( (string) absint( $queue_stats['max_attempts'] ?? 0 ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Oldest Next Attempt', 'click-trail-handler' ); ?></th>
						<td><?php echo ! empty( $queue_stats['oldest_next'] ) ? esc_html( $queue_stats['oldest_next'] ) : '-'; ?></td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Data Management', 'click-trail-handler' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Purge local tracking data (events, queue, diagnostics transients). This action cannot be undone.', 'click-trail-handler' ); ?></p>
			<p>
				<button class="button button-secondary" id="clicutcl-purge-data"><?php esc_html_e( 'Purge Tracking Data', 'click-trail-handler' ); ?></button>
				<span id="clicutcl-purge-data-status" style="margin-left:10px;"></span>
			</p>

			<h2><?php esc_html_e( 'Recent v2 Intake Events', 'click-trail-handler' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Last normalized v2 intake events (admin-only, debug-window-only).', 'click-trail-handler' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'click-trail-handler' ); ?></th>
						<th><?php esc_html_e( 'Kind', 'click-trail-handler' ); ?></th>
						<th><?php esc_html_e( 'Event', 'click-trail-handler' ); ?></th>
						<th><?php esc_html_e( 'Status', 'click-trail-handler' ); ?></th>
						<th><?php esc_html_e( 'Reason', 'click-trail-handler' ); ?></th>
						<th><?php esc_html_e( 'Consent', 'click-trail-handler' ); ?></th>
						<th><?php esc_html_e( 'Attribution Keys', 'click-trail-handler' ); ?></th>
						<th><?php esc_html_e( 'Identity Keys', 'click-trail-handler' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! empty( $v2_intake ) ) : ?>
					<?php foreach ( $v2_intake as $entry ) : ?>
						<?php
						$consent   = isset( $entry['consent'] ) && is_array( $entry['consent'] ) ? $entry['consent'] : array();
						$cons_text = 'm:' . ( ! empty( $consent['marketing'] ) ? '1' : '0' ) . ' a:' . ( ! empty( $consent['analytics'] ) ? '1' : '0' );
						$attr_keys = isset( $entry['attribution_keys'] ) && is_array( $entry['attribution_keys'] ) ? implode( ',', $entry['attribution_keys'] ) : '';
						$id_keys   = isset( $entry['identity_keys'] ) && is_array( $entry['identity_keys'] ) ? implode( ',', $entry['identity_keys'] ) : '';
						$event_col = trim( (string) ( $entry['event_name'] ?? '' ) . ' ' . (string) ( $entry['event_id'] ?? '' ) );
						?>
						<tr>
							<td><?php echo esc_html( date_i18n( 'Y-m-d H:i:s', (int) ( $entry['time'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( (string) ( $entry['kind'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( $event_col ); ?></td>
							<td><?php echo esc_html( (string) ( $entry['status'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $entry['reason'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( $cons_text ); ?></td>
							<td><?php echo esc_html( $attr_keys ); ?></td>
							<td><?php echo esc_html( $id_keys ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr>
						<td colspan="8"><?php esc_html_e( 'No v2 intake entries recorded yet. Enable Debug Logging and reproduce an event.', 'click-trail-handler' ); ?></td>
					</tr>
				<?php endif; ?>
				</tbody>
			</table>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'click-trail-handler' ); ?></th>
						<th><?php esc_html_e( 'Event', 'click-trail-handler' ); ?></th>
						<th><?php esc_html_e( 'Event ID', 'click-trail-handler' ); ?></th>
						<th><?php esc_html_e( 'Adapter', 'click-trail-handler' ); ?></th>
						<th><?php esc_html_e( 'Status', 'click-trail-handler' ); ?></th>
						<th><?php esc_html_e( 'HTTP', 'click-trail-handler' ); ?></th>
						<th><?php esc_html_e( 'Endpoint', 'click-trail-handler' ); ?></th>
						<th><?php esc_html_e( 'Message', 'click-trail-handler' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! empty( $dispatches ) ) : ?>
					<?php foreach ( $dispatches as $dispatch ) : ?>
						<tr>
							<td><?php echo esc_html( date_i18n( 'Y-m-d H:i:s', (int) ( $dispatch['time'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( $dispatch['event_name'] ?? '' ); ?></td>
							<td><?php echo esc_html( $dispatch['event_id'] ?? '' ); ?></td>
							<td><?php echo esc_html( $dispatch['adapter'] ?? '' ); ?></td>
							<td><?php echo esc_html( $dispatch['status'] ?? '' ); ?></td>
							<td><?php echo esc_html( $dispatch['http_status'] ?? '' ); ?></td>
							<td><?php echo esc_html( $dispatch['endpoint_host'] ?? '' ); ?></td>
							<td><?php echo esc_html( $dispatch['message'] ?? '' ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr>
						<td colspan="8"><?php esc_html_e( 'No dispatches recorded yet.', 'click-trail-handler' ); ?></td>
					</tr>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function logs_page() {
		require_once CLICUTCL_DIR . 'includes/admin/class-log-list-table.php';
		$table = new Log_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'ClickTrail Logs', 'click-trail-handler' ); ?></h1>
			<form method="post">
				<?php
				$table->display();
				?>
			</form>
		</div>
		<?php
	}
}
