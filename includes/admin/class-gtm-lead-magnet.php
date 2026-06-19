<?php
/**
 * GTM Starter Kit lead magnet — in-plugin email capture.
 *
 * Shows a dismissible settings-page banner after the first WooCommerce order is
 * tracked. Submitting the form subscribes the user to Brevo (fire-and-forget) and
 * returns a single-use signed download URL for the pre-built GTM container JSON.
 *
 * Configuration (add to wp-config.php before enabling):
 *   define( 'CLICUTCL_BREVO_KEY',  'your-brevo-api-key' );
 *   define( 'CLICUTCL_BREVO_LIST', 12 ); // Brevo list ID (integer)
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GTM Starter Kit lead magnet.
 */
class GTM_Lead_Magnet {

	const DISMISSED_OPTION   = 'clicutcl_gtm_offer_dismissed';
	const SUBSCRIBED_OPTION  = 'clicutcl_gtm_offer_subscribed';
	const ACTIVATED_OPTION   = 'clicutcl_activated_at';
	const FIRST_ORDER_OPTION = 'clicutcl_first_order_tracked_at';
	const DOWNLOAD_TRANSIENT = 'clicutcl_gtm_dl_token';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_notices', array( static::class, 'maybe_render_banner' ) );
		add_action( 'wp_ajax_clicutcl_gtm_subscribe', array( static::class, 'ajax_subscribe' ) );
		add_action( 'wp_ajax_clicutcl_gtm_dismiss', array( static::class, 'ajax_dismiss' ) );
		add_action( 'wp_ajax_clicutcl_gtm_download', array( static::class, 'ajax_download' ) );

		// Mark the first order when WooCommerce attribution is saved.
		add_action( 'clicutcl_order_attribution_saved', array( static::class, 'mark_first_order' ) );

		// Lazy-init the activation timestamp on first admin load.
		add_action( 'admin_init', array( static::class, 'maybe_init_activated_at' ) );
	}

	/**
	 * Set the activation timestamp the first time an admin page loads.
	 * Using admin_init (rather than the activation hook) means it works
	 * for users who installed before this feature shipped.
	 */
	public static function maybe_init_activated_at(): void {
		if ( ! get_option( self::ACTIVATED_OPTION ) ) {
			update_option( self::ACTIVATED_OPTION, time(), false );
		}
	}

	/**
	 * Mark the first tracked WooCommerce order.
	 */
	public static function mark_first_order(): void {
		if ( ! get_option( self::FIRST_ORDER_OPTION ) ) {
			update_option( self::FIRST_ORDER_OPTION, time(), false );
		}
	}

	/**
	 * Decide whether to render the banner and do so.
	 */
	public static function maybe_render_banner(): void {
		// Only on ClickTrail admin screens.
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'click-trail' ) ) {
			return;
		}

		// Already dismissed or subscribed.
		if ( get_option( self::DISMISSED_OPTION ) || get_option( self::SUBSCRIBED_OPTION ) ) {
			return;
		}

		// Show only after plugin has been active for 3+ days OR a real order has been tracked.
		$activated_at   = (int) get_option( self::ACTIVATED_OPTION, 0 );
		$first_order_at = (int) get_option( self::FIRST_ORDER_OPTION, 0 );
		$three_days_ago = time() - ( 3 * DAY_IN_SECONDS );

		if ( ! $first_order_at && $activated_at > $three_days_ago ) {
			return;
		}

		self::render_banner();
	}

	/**
	 * Output the banner HTML + inline JS.
	 */
	private static function render_banner(): void {
		$subscribe_nonce = wp_create_nonce( 'clicutcl_gtm_subscribe' );
		$dismiss_nonce   = wp_create_nonce( 'clicutcl_gtm_dismiss' );
		$ajax_url        = esc_url( admin_url( 'admin-ajax.php' ) );
		?>
		<div class="notice clicktrail-gtm-offer" id="clicutcl-gtm-offer">
			<div class="clicktrail-gtm-offer__inner">

				<div class="clicktrail-gtm-offer__icon" aria-hidden="true">
					<svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
						<rect width="32" height="32" rx="6" fill="#2271b1"/>
						<path d="M8 10h16v2H8zM8 15h10v2H8zM8 20h12v2H8z" fill="#fff"/>
						<circle cx="24" cy="21" r="5" fill="#00a32a"/>
						<path d="M22 21l1.5 1.5L26 19" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
				</div>

				<div class="clicktrail-gtm-offer__body">
					<p class="clicktrail-gtm-offer__headline">
						<?php esc_html_e( 'Free GTM Starter Kit — pre-wired for ClickTrail', 'click-trail-handler' ); ?>
					</p>
					<p class="clicktrail-gtm-offer__sub">
						<?php esc_html_e( 'A ready-to-import GTM container with GA4, Google Ads, and Meta Pixel tags already mapped to ClickTrail\'s data layer (purchase, cart, attribution fields). Import once, fill in your IDs, done.', 'click-trail-handler' ); ?>
					</p>

					<div class="clicktrail-gtm-offer__form" id="clicutcl-gtm-form-wrap">
						<input
							type="email"
							id="clicutcl-gtm-email"
							class="clicktrail-gtm-offer__email"
							placeholder="<?php esc_attr_e( 'your@email.com', 'click-trail-handler' ); ?>"
							autocomplete="email"
						/>
						<label class="clicktrail-gtm-offer__consent">
							<input type="checkbox" id="clicutcl-gtm-consent" />
							<?php esc_html_e( 'Send me occasional ClickTrail tips and updates. Unsubscribe any time.', 'click-trail-handler' ); ?>
						</label>
						<button type="button" class="button button-primary clicktrail-gtm-offer__submit" id="clicutcl-gtm-submit">
							<?php esc_html_e( 'Get the GTM kit', 'click-trail-handler' ); ?>
						</button>
						<span class="clicktrail-gtm-offer__spinner spinner" id="clicutcl-gtm-spinner"></span>
						<p class="clicktrail-gtm-offer__error" id="clicutcl-gtm-error" style="display:none;"></p>
					</div>

					<div class="clicktrail-gtm-offer__success" id="clicutcl-gtm-success" style="display:none;">
						<strong><?php esc_html_e( 'Your kit is ready.', 'click-trail-handler' ); ?></strong>
						<?php esc_html_e( 'Import the JSON into GTM, then fill in the five constant variables at the top of the container.', 'click-trail-handler' ); ?>
						&nbsp;<a href="#" id="clicutcl-gtm-download-link" class="button button-secondary">
							<?php esc_html_e( 'Download GTM container JSON', 'click-trail-handler' ); ?>
						</a>
					</div>
				</div>

				<button
					type="button"
					class="clicktrail-gtm-offer__dismiss notice-dismiss"
					id="clicutcl-gtm-dismiss"
					aria-label="<?php esc_attr_e( 'Dismiss this offer', 'click-trail-handler' ); ?>"
				></button>
			</div>
		</div>

		<script>
		(function () {
			var ajaxUrl       = <?php echo wp_json_encode( $ajax_url ); ?>;
			var subNonce      = <?php echo wp_json_encode( $subscribe_nonce ); ?>;
			var dimNonce      = <?php echo wp_json_encode( $dismiss_nonce ); ?>;

			function qs(id) { return document.getElementById(id); }

			// Dismiss
			qs('clicutcl-gtm-dismiss').addEventListener('click', function () {
				qs('clicutcl-gtm-offer').style.display = 'none';
				var fd = new FormData();
				fd.append('action', 'clicutcl_gtm_dismiss');
				fd.append('nonce', dimNonce);
				fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
			});

			// Submit
			qs('clicutcl-gtm-submit').addEventListener('click', function () {
				var email   = qs('clicutcl-gtm-email').value.trim();
				var consent = qs('clicutcl-gtm-consent').checked;
				var errEl   = qs('clicutcl-gtm-error');

				errEl.style.display = 'none';

				if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
					errEl.textContent = <?php echo wp_json_encode( __( 'Please enter a valid email address.', 'click-trail-handler' ) ); ?>;
					errEl.style.display = 'block';
					return;
				}
				if (!consent) {
					errEl.textContent = <?php echo wp_json_encode( __( 'Please check the consent box to continue.', 'click-trail-handler' ) ); ?>;
					errEl.style.display = 'block';
					return;
				}

				qs('clicutcl-gtm-submit').disabled  = true;
				qs('clicutcl-gtm-spinner').className = 'clicktrail-gtm-offer__spinner spinner is-active';

				var fd = new FormData();
				fd.append('action',  'clicutcl_gtm_subscribe');
				fd.append('nonce',   subNonce);
				fd.append('email',   email);
				fd.append('consent', '1');

				fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						qs('clicutcl-gtm-spinner').className = 'clicktrail-gtm-offer__spinner spinner';
						if (res.success) {
							qs('clicutcl-gtm-form-wrap').style.display   = 'none';
							qs('clicutcl-gtm-success').style.display      = 'block';
							qs('clicutcl-gtm-download-link').href         = res.data.download_url;
						} else {
							qs('clicutcl-gtm-submit').disabled = false;
							errEl.textContent    = res.data && res.data.message ? res.data.message : <?php echo wp_json_encode( __( 'Something went wrong. Please try again.', 'click-trail-handler' ) ); ?>;
							errEl.style.display  = 'block';
						}
					})
					.catch(function () {
						qs('clicutcl-gtm-spinner').className = 'clicktrail-gtm-offer__spinner spinner';
						qs('clicutcl-gtm-submit').disabled   = false;
						errEl.textContent   = <?php echo wp_json_encode( __( 'Request failed. Please check your connection.', 'click-trail-handler' ) ); ?>;
						errEl.style.display = 'block';
					});
			});
		}());
		</script>
		<?php
	}

	/**
	 * AJAX: validate email + consent, subscribe to Brevo, return signed download URL.
	 */
	public static function ajax_subscribe(): void {
		check_ajax_referer( 'clicutcl_gtm_subscribe', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'click-trail-handler' ) ), 403 );
		}

		$email   = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );   // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$consent = ! empty( $_POST['consent'] );

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'A valid email address is required.', 'click-trail-handler' ) ) );
		}
		if ( ! $consent ) {
			wp_send_json_error( array( 'message' => __( 'Consent is required.', 'click-trail-handler' ) ) );
		}

		// Fire-and-forget Brevo subscribe — failure is logged but never surfaced to user.
		self::brevo_subscribe( $email );

		// Record subscription so banner never shows again.
		update_option( self::SUBSCRIBED_OPTION, time(), false );

		// Generate a single-use 10-minute download token.
		$token = wp_generate_password( 32, false );
		set_transient( self::DOWNLOAD_TRANSIENT, $token, 10 * MINUTE_IN_SECONDS );

		$download_url = add_query_arg(
			array(
				'action' => 'clicutcl_gtm_download',
				'token'  => rawurlencode( $token ),
				'nonce'  => wp_create_nonce( 'clicutcl_gtm_download' ),
			),
			admin_url( 'admin-ajax.php' )
		);

		wp_send_json_success( array( 'download_url' => esc_url_raw( $download_url ) ) );
	}

	/**
	 * AJAX: dismiss the banner permanently.
	 */
	public static function ajax_dismiss(): void {
		check_ajax_referer( 'clicutcl_gtm_dismiss', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'click-trail-handler' ) ), 403 );
		}

		update_option( self::DISMISSED_OPTION, 1, false );
		wp_send_json_success();
	}

	/**
	 * AJAX: validate download token and serve the GTM JSON file.
	 */
	public static function ajax_download(): void {
		check_ajax_referer( 'clicutcl_gtm_download', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'Insufficient permissions.', 'click-trail-handler' ),
				esc_html__( 'Forbidden', 'click-trail-handler' ),
				array( 'response' => 403 )
			);
		}

		$token  = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$stored = get_transient( self::DOWNLOAD_TRANSIENT );

		if ( ! $token || ! $stored || ! hash_equals( (string) $stored, $token ) ) {
			wp_die(
				esc_html__( 'This download link has expired or is invalid. Return to ClickTrail settings to request a new one.', 'click-trail-handler' ),
				esc_html__( 'Link expired', 'click-trail-handler' ),
				array( 'response' => 403 )
			);
		}

		// Single-use: invalidate immediately.
		delete_transient( self::DOWNLOAD_TRANSIENT );

		$file = CLICUTCL_DIR . 'assets/gtm-starter-kit.json';
		if ( ! file_exists( $file ) ) {
			wp_die(
				esc_html__( 'File not found.', 'click-trail-handler' ),
				esc_html__( 'Not found', 'click-trail-handler' ),
				array( 'response' => 404 )
			);
		}

		// Use WP_Filesystem for file reads per WP.org coding standards.
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;

		$content = $wp_filesystem->get_contents( $file );
		if ( false === $content ) {
			wp_die(
				esc_html__( 'Could not read the download file.', 'click-trail-handler' ),
				esc_html__( 'Server error', 'click-trail-handler' ),
				array( 'response' => 500 )
			);
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="clicktrail-gtm-starter-kit.json"' );
		header( 'Content-Length: ' . (string) strlen( $content ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON file download, not HTML output.
		echo $content;
		exit;
	}

	/**
	 * Subscribe an email address to the configured Brevo list.
	 * Failures are logged; never thrown or surfaced to the user.
	 *
	 * @param string $email Validated email address.
	 */
	private static function brevo_subscribe( string $email ): void {
		$api_key = defined( 'CLICUTCL_BREVO_KEY' ) ? (string) CLICUTCL_BREVO_KEY : '';
		$list_id = defined( 'CLICUTCL_BREVO_LIST' ) ? (int) CLICUTCL_BREVO_LIST : 0;

		if ( ! $api_key || ! $list_id ) {
			// Credentials not configured — skip silently.
			return;
		}

		$response = wp_remote_post(
			'https://api.brevo.com/v3/contacts',
			array(
				'timeout'     => 8,
				'redirection' => 2,
				'headers'     => array(
					'api-key'      => $api_key,
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'        => wp_json_encode(
					array(
						'email'         => $email,
						'listIds'       => array( $list_id ),
						'updateEnabled' => true,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'ClickTrail GTM lead magnet â Brevo subscribe failed: ' . $response->get_error_message() );
		}
	}
}
