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
		$dispatch_rows   = is_array( $dispatches ) ? array_values( $dispatches ) : array();

		if ( ! empty( $dispatch_rows ) ) {
			usort(
				$dispatch_rows,
				static function ( $left, $right ) {
					return (int) ( $right['time'] ?? 0 ) <=> (int) ( $left['time'] ?? 0 );
				}
			);
		}

		$latest_dispatch      = ! empty( $dispatch_rows ) ? $dispatch_rows[0] : array();
		$latest_dispatch_time = isset( $latest_dispatch['time'] ) ? absint( $latest_dispatch['time'] ) : 0;
		$latest_dispatch_http = isset( $latest_dispatch['http_status'] ) ? absint( $latest_dispatch['http_status'] ) : 0;
		$latest_dispatch_ok   = $latest_dispatch_http >= 200 && $latest_dispatch_http < 300;
		$latest_dispatch_tone = empty( $latest_dispatch ) ? 'neutral' : ( $latest_dispatch_ok ? 'ok' : 'warn' );
		$latest_dispatch_text = empty( $latest_dispatch )
			? __( 'No dispatches yet', 'click-trail-handler' )
			: ( $latest_dispatch_http ? (string) $latest_dispatch_http : (string) ( $latest_dispatch['status'] ?? '' ) );
		$latest_dispatch_sub  = $latest_dispatch_time
			? sprintf(
				/* translators: %s: relative time. */
				__( '%s ago', 'click-trail-handler' ),
				human_time_diff( $latest_dispatch_time, time() )
			)
			: __( 'No delivery attempts recorded.', 'click-trail-handler' );
		$queue_pending        = absint( $queue_stats['pending'] ?? 0 );
		$queue_due_now        = absint( $queue_stats['due_now'] ?? 0 );
		$queue_tone           = $queue_pending > 0 ? 'warn' : 'ok';
		$last_error_code      = isset( $last_error['code'] ) ? sanitize_key( (string) $last_error['code'] ) : '';
		$last_error_message   = isset( $last_error['message'] ) ? sanitize_text_field( (string) $last_error['message'] ) : '';
		$last_error_tone      = $last_error_code ? 'err' : 'ok';
		$failure_total        = 0;

		foreach ( $failure_telemetry as $bucket ) {
			$failure_total += absint( $bucket['total'] ?? 0 );
		}
		?>
		<div class="wrap clicktrail-diagnostics-wrap">
			<div class="clicktrail-page-header">
				<div class="clicktrail-page-title">
					<h1><?php esc_html_e( 'ClickTrail Diagnostics', 'click-trail-handler' ); ?></h1>
				</div>
			</div>

			<div class="clicktrail-diagnostics-grid">
				<div class="clicktrail-diagnostic-stat clicktrail-diagnostic-stat--<?php echo esc_attr( $queue_tone ); ?>">
					<div class="clicktrail-diagnostic-stat__label"><?php esc_html_e( 'Queue Backlog', 'click-trail-handler' ); ?></div>
					<div class="clicktrail-diagnostic-stat__value"><?php echo esc_html( (string) $queue_pending ); ?></div>
					<div class="clicktrail-diagnostic-stat__sub">
						<?php
						printf(
							/* translators: %d: due now count. */
							esc_html__( 'Due now: %d', 'click-trail-handler' ),
							$queue_due_now
						);
						?>
					</div>
				</div>

				<div class="clicktrail-diagnostic-stat clicktrail-diagnostic-stat--<?php echo esc_attr( $latest_dispatch_tone ); ?>">
					<div class="clicktrail-diagnostic-stat__label"><?php esc_html_e( 'Last Dispatch', 'click-trail-handler' ); ?></div>
					<div class="clicktrail-diagnostic-stat__value"><?php echo esc_html( $latest_dispatch_text ); ?></div>
					<div class="clicktrail-diagnostic-stat__sub"><?php echo esc_html( $latest_dispatch_sub ); ?></div>
				</div>

				<div class="clicktrail-diagnostic-stat clicktrail-diagnostic-stat--<?php echo esc_attr( $last_error_tone ); ?>">
					<div class="clicktrail-diagnostic-stat__label"><?php esc_html_e( 'Last Error', 'click-trail-handler' ); ?></div>
					<div class="clicktrail-diagnostic-stat__value"><?php echo esc_html( $last_error_code ? $last_error_code : __( 'None', 'click-trail-handler' ) ); ?></div>
					<div class="clicktrail-diagnostic-stat__sub"><?php echo esc_html( $last_error_time ? $last_error_time : __( 'No errors recorded.', 'click-trail-handler' ) ); ?></div>
				</div>

				<div class="clicktrail-diagnostic-stat clicktrail-diagnostic-stat--info">
					<div class="clicktrail-diagnostic-stat__label"><?php esc_html_e( 'Debug Logging', 'click-trail-handler' ); ?></div>
					<div class="clicktrail-diagnostic-stat__value"><?php echo esc_html( $debug_active ? __( 'Enabled', 'click-trail-handler' ) : __( 'Disabled', 'click-trail-handler' ) ); ?></div>
					<div class="clicktrail-diagnostic-stat__sub">
						<?php echo esc_html( $debug_active ? $debug_until_str : __( '15 minute window when enabled.', 'click-trail-handler' ) ); ?>
					</div>
				</div>
			</div>

			<?php if ( $last_error_code || $last_error_message ) : ?>
				<div class="clicktrail-inline-notice clicktrail-inline-notice--warning">
					<span class="dashicons dashicons-warning" aria-hidden="true"></span>
					<span>
						<strong><?php echo esc_html( $last_error_code ); ?></strong>
						<?php echo esc_html( $last_error_message ); ?>
					</span>
				</div>
			<?php endif; ?>

			<section class="clicktrail-card">
				<div class="clicktrail-card__header clicktrail-card__header--static">
					<span class="clicktrail-card__header-main">
						<span class="clicktrail-card__icon dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
						<span class="clicktrail-card__heading">
							<span class="clicktrail-card__title"><?php esc_html_e( 'Endpoint Test', 'click-trail-handler' ); ?></span>
							<span class="clicktrail-card__description"><?php esc_html_e( 'Validate connectivity to the configured server-side endpoint.', 'click-trail-handler' ); ?></span>
						</span>
					</span>
				</div>
				<div class="clicktrail-card__body">
					<div class="clicktrail-action-row">
						<button class="button button-primary" id="clicutcl-test-endpoint"><?php esc_html_e( 'Test Endpoint', 'click-trail-handler' ); ?></button>
						<span id="clicutcl-test-endpoint-status" class="clicktrail-action-status"></span>
					</div>
				</div>
			</section>

			<section class="clicktrail-card">
				<div class="clicktrail-card__header clicktrail-card__header--static">
					<span class="clicktrail-card__header-main">
						<span class="clicktrail-card__icon dashicons dashicons-media-text" aria-hidden="true"></span>
						<span class="clicktrail-card__heading">
							<span class="clicktrail-card__title"><?php esc_html_e( 'Debug Logging', 'click-trail-handler' ); ?></span>
							<span class="clicktrail-card__description"><?php esc_html_e( 'Capture temporary dispatch traces and normalized event intake entries for debugging.', 'click-trail-handler' ); ?></span>
						</span>
					</span>
				</div>
				<div class="clicktrail-card__body">
					<div class="clicktrail-action-row">
						<button class="button" id="clicutcl-debug-toggle" data-mode="<?php echo esc_attr( $debug_active ? 'off' : 'on' ); ?>">
							<?php echo esc_html( $debug_active ? __( 'Disable Debug', 'click-trail-handler' ) : __( 'Enable 15 Minutes', 'click-trail-handler' ) ); ?>
						</button>
						<span id="clicutcl-debug-status" class="clicktrail-action-status"></span>
					</div>
				</div>
			</section>

			<section class="clicktrail-card clicktrail-card--danger">
				<div class="clicktrail-card__header clicktrail-card__header--static">
					<span class="clicktrail-card__header-main">
						<span class="clicktrail-card__icon dashicons dashicons-trash" aria-hidden="true"></span>
						<span class="clicktrail-card__heading">
							<span class="clicktrail-card__title"><?php esc_html_e( 'Data Management', 'click-trail-handler' ); ?></span>
							<span class="clicktrail-card__description"><?php esc_html_e( 'Purge local tracking data, including events, queue rows, and diagnostic buffers. This cannot be undone.', 'click-trail-handler' ); ?></span>
						</span>
					</span>
				</div>
				<div class="clicktrail-card__body">
					<div class="clicktrail-action-row">
						<button class="button button-secondary" id="clicutcl-purge-data"><?php esc_html_e( 'Purge Tracking Data', 'click-trail-handler' ); ?></button>
						<span id="clicutcl-purge-data-status" class="clicktrail-action-status"></span>
					</div>
				</div>
			</section>

			<section class="clicktrail-card">
				<div class="clicktrail-card__header clicktrail-card__header--static">
					<span class="clicktrail-card__header-main">
						<span class="clicktrail-card__icon dashicons dashicons-warning" aria-hidden="true"></span>
						<span class="clicktrail-card__heading">
							<span class="clicktrail-card__title"><?php esc_html_e( 'Failure Telemetry', 'click-trail-handler' ); ?></span>
							<span class="clicktrail-card__description">
								<?php
								printf(
									/* translators: %d: total failures. */
									esc_html__( 'Failure-only aggregated counters. Total failures tracked: %d.', 'click-trail-handler' ),
									$failure_total
								);
								?>
							</span>
						</span>
					</span>
				</div>
				<div class="clicktrail-card__body">
					<table class="widefat striped clicktrail-data-table">
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
				</div>
			</section>

			<section class="clicktrail-card">
				<div class="clicktrail-card__header clicktrail-card__header--static">
					<span class="clicktrail-card__header-main">
						<span class="clicktrail-card__icon dashicons dashicons-clock" aria-hidden="true"></span>
						<span class="clicktrail-card__heading">
							<span class="clicktrail-card__title"><?php esc_html_e( 'Queue Backlog Details', 'click-trail-handler' ); ?></span>
							<span class="clicktrail-card__description"><?php esc_html_e( 'Current state of the retry queue used for failed dispatch attempts.', 'click-trail-handler' ); ?></span>
						</span>
					</span>
				</div>
				<div class="clicktrail-card__body">
					<table class="widefat striped clicktrail-data-table">
						<tbody>
							<tr>
								<th><?php esc_html_e( 'Queue Ready', 'click-trail-handler' ); ?></th>
								<td><?php echo ! empty( $queue_stats['ready'] ) ? esc_html__( 'Yes', 'click-trail-handler' ) : esc_html__( 'No', 'click-trail-handler' ); ?></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Pending Events', 'click-trail-handler' ); ?></th>
								<td><?php echo esc_html( (string) $queue_pending ); ?></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Due Now', 'click-trail-handler' ); ?></th>
								<td><?php echo esc_html( (string) $queue_due_now ); ?></td>
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
				</div>
			</section>

			<section class="clicktrail-card">
				<div class="clicktrail-card__header clicktrail-card__header--static">
					<span class="clicktrail-card__header-main">
						<span class="clicktrail-card__icon dashicons dashicons-randomize" aria-hidden="true"></span>
						<span class="clicktrail-card__heading">
							<span class="clicktrail-card__title"><?php esc_html_e( 'Recent Event Intake', 'click-trail-handler' ); ?></span>
							<span class="clicktrail-card__description"><?php esc_html_e( 'Last normalized event intake entries captured during the debug window.', 'click-trail-handler' ); ?></span>
						</span>
					</span>
				</div>
				<div class="clicktrail-card__body">
					<table class="widefat striped clicktrail-data-table">
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
								<td colspan="8"><?php esc_html_e( 'No normalized intake entries recorded yet. Enable Debug Logging and reproduce an event.', 'click-trail-handler' ); ?></td>
							</tr>
						<?php endif; ?>
						</tbody>
					</table>
				</div>
			</section>

			<section class="clicktrail-card">
				<div class="clicktrail-card__header clicktrail-card__header--static">
					<span class="clicktrail-card__header-main">
						<span class="clicktrail-card__icon dashicons dashicons-update" aria-hidden="true"></span>
						<span class="clicktrail-card__heading">
							<span class="clicktrail-card__title"><?php esc_html_e( 'Recent Dispatches', 'click-trail-handler' ); ?></span>
							<span class="clicktrail-card__description"><?php esc_html_e( 'Dispatch entries are captured only while Debug Logging is enabled.', 'click-trail-handler' ); ?></span>
						</span>
					</span>
				</div>
				<div class="clicktrail-card__body">
					<table class="widefat striped clicktrail-data-table">
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
						<?php if ( ! empty( $dispatch_rows ) ) : ?>
							<?php foreach ( $dispatch_rows as $dispatch ) : ?>
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
			</section>
		</div>
		<?php
	}

	public function logs_page() {
		require_once CLICUTCL_DIR . 'includes/admin/class-log-list-table.php';
		$table = new Log_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap clicktrail-logs-wrap">
			<div class="clicktrail-page-header">
				<div class="clicktrail-page-title">
					<h1><?php esc_html_e( 'ClickTrail Logs', 'click-trail-handler' ); ?></h1>
				</div>
			</div>
			<div class="clicktrail-card">
				<div class="clicktrail-card__body">
					<form method="post">
						<?php
						$table->display();
						?>
					</form>
				</div>
			</div>
		</div>
		<?php
	}
}
