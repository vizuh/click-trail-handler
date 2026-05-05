<?php
/**
 * Setup Wizard
 *
 * Three-step onboarding wizard that auto-detects the environment, collects
 * a GA4 Measurement ID, and writes the minimum settings needed to get
 * Easy Mode running on first activation.
 *
 * Page slug:  clicutcl-setup
 * Registered: hidden submenu (no sidebar entry)
 * Entry:      activation redirect via transient, or direct link
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Setup_Wizard
 */
class Setup_Wizard {

	const PAGE_SLUG          = 'clicutcl-setup';
	const OPTION_MODE        = 'clicutcl_mode';
	const OPTION_COMPLETE    = 'clicutcl_setup_complete';
	const TRANSIENT_REDIRECT = 'clicutcl_activation_redirect';
	const NONCE_ACTION       = 'clicutcl_wizard_save';
	const NONCE_FIELD        = 'clicutcl_wizard_nonce';

	/**
	 * Register all wizard hooks.
	 *
	 * Safe to call multiple times — uses a static flag to prevent double-registration.
	 *
	 * @return void
	 */
	public static function init(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect_on_activation' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

		// Add a "Setup Wizard" link to the plugins list as a permanent fallback
		// so the wizard is accessible even when the activation redirect is suppressed.
		add_filter(
			'plugin_action_links_' . plugin_basename( CLICUTCL_PLUGIN_MAIN_FILE ),
			array( __CLASS__, 'add_action_links' )
		);
	}

	/**
	 * Register the wizard as a hidden admin page (no sidebar entry).
	 *
	 * @return void
	 */
	public static function register_page(): void {
		add_submenu_page(
			null,
			__( 'ClickTrail Setup', 'click-trail-handler' ),
			__( 'Setup', 'click-trail-handler' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Inject a "Setup Wizard" link into the plugin's action links row.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public static function add_action_links( array $links ): array {
		$wizard_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Setup Wizard', 'click-trail-handler' )
		);
		array_unshift( $links, $wizard_link );
		return $links;
	}

	/**
	 * Redirect to the wizard on first admin load after activation.
	 *
	 * The transient is set in the activation hook (clicutcl.php). Redirect is
	 * skipped if setup was already completed on a previous activation.
	 *
	 * @return void
	 */
	public static function maybe_redirect_on_activation(): void {
		if ( ! get_transient( self::TRANSIENT_REDIRECT ) ) {
			return;
		}
		if ( wp_doing_ajax() || is_network_admin() ) {
			return;
		}
		delete_transient( self::TRANSIENT_REDIRECT );
		if ( get_option( self::OPTION_COMPLETE ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Process wizard form submission.
	 *
	 * Enables attribution capture and form injection, stores the GA4 ID if
	 * provided, records mode and completion timestamp, then redirects to
	 * the main settings page.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'click-trail-handler' ), 403 );
		}

		// Enable core attribution settings.
		$attr = get_option( 'clicutcl_attribution_settings', array() );
		if ( ! is_array( $attr ) ) {
			$attr = array();
		}
		$attr['enable_attribution']       = 1;
		$attr['enable_js_injection']      = 1;
		$attr['inject_mutation_observer'] = 1;

		// Store GA4 Measurement ID if a valid value was supplied.
		$ga4_raw = isset( $_POST['ga4_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ga4_id'] ) ) : '';
		if ( '' !== $ga4_raw && preg_match( '/^G-[A-Z0-9]+$/i', $ga4_raw ) ) {
			$attr['ga4_measurement_id'] = strtoupper( $ga4_raw );
		}

		update_option( 'clicutcl_attribution_settings', $attr, false );
		update_option( self::OPTION_MODE, 'easy', false );
		update_option( self::OPTION_COMPLETE, time(), false );

		wp_safe_redirect( admin_url( 'admin.php?page=clicutcl-settings&clicutcl_wizard=done' ) );
		exit;
	}

	/**
	 * Enqueue wizard-specific CSS and JS.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}
		wp_enqueue_style(
			'clicutcl-admin',
			CLICUTCL_URL . 'assets/css/admin.css',
			array(),
			CLICUTCL_VERSION
		);
		wp_enqueue_style(
			'clicutcl-wizard',
			CLICUTCL_URL . 'assets/css/wizard.css',
			array( 'clicutcl-admin' ),
			CLICUTCL_VERSION
		);
		wp_enqueue_script(
			'clicutcl-wizard',
			CLICUTCL_URL . 'assets/js/wizard.js',
			array(),
			CLICUTCL_VERSION,
			\clicutcl_script_args( true, 'defer' )
		);
	}

	// -----------------------------------------------------------------------
	// Render
	// -----------------------------------------------------------------------

	/**
	 * Render the wizard page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'click-trail-handler' ), 403 );
		}

		$env    = Setup_Detector::run();
		$attr   = get_option( 'clicutcl_attribution_settings', array() );
		$ga4_id = ( is_array( $attr ) && ! empty( $attr['ga4_measurement_id'] ) )
			? (string) $attr['ga4_measurement_id']
			: '';
		?>
		<div class="wrap clicutcl-wizard-wrap">
			<div class="clicutcl-wizard">

				<div class="clicutcl-wizard__header">
					<span class="clicutcl-wizard__logo">
						<span class="dashicons dashicons-chart-area" aria-hidden="true"></span>
						ClickTrail
					</span>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=clicutcl-settings' ) ); ?>" class="clicutcl-wizard__skip">
						<?php esc_html_e( 'Skip setup', 'click-trail-handler' ); ?>
					</a>
				</div>

				<?php static::render_step_indicator(); ?>

				<form method="post" action="" id="clicutcl-wizard-form" class="clicutcl-wizard__form">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
					<?php static::render_step_1( $env, $ga4_id ); ?>
					<?php static::render_step_2(); ?>
					<?php static::render_step_3( $env ); ?>
				</form>

			</div>
		</div>
		<?php
	}

	/**
	 * Render the step progress indicator.
	 *
	 * @return void
	 */
	private static function render_step_indicator(): void {
		$steps = array(
			1 => __( 'What are you tracking?', 'click-trail-handler' ),
			2 => __( 'Ad platforms', 'click-trail-handler' ),
			3 => __( "You're set up", 'click-trail-handler' ),
		);
		?>
		<div class="clicutcl-wizard__steps" role="list" aria-label="<?php esc_attr_e( 'Setup progress', 'click-trail-handler' ); ?>">
			<?php foreach ( $steps as $n => $label ) : ?>
				<div
					class="clicutcl-wizard__step<?php echo 1 === $n ? ' is-active' : ''; ?>"
					data-step="<?php echo esc_attr( (string) $n ); ?>"
					role="listitem"
				>
					<div class="clicutcl-wizard__step-number" aria-hidden="true"><?php echo esc_html( (string) $n ); ?></div>
					<div class="clicutcl-wizard__step-label"><?php echo esc_html( $label ); ?></div>
				</div>
				<?php if ( $n < count( $steps ) ) : ?>
					<div class="clicutcl-wizard__step-connector" aria-hidden="true"></div>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render Step 1: environment detection checklist + GA4 ID input.
	 *
	 * @param array  $env    Setup_Detector::run() result.
	 * @param string $ga4_id Previously saved GA4 Measurement ID (if any).
	 * @return void
	 */
	private static function render_step_1( array $env, string $ga4_id ): void {
		$active_labels = Setup_Detector::active_form_labels();
		$active_str    = implode( ', ', $active_labels );
		?>
		<div class="clicutcl-wizard__panel" data-panel="1">
			<div class="clicutcl-wizard__panel-inner">

				<h1 class="clicutcl-wizard__title">
					<?php esc_html_e( 'What are you tracking?', 'click-trail-handler' ); ?>
				</h1>
				<p class="clicutcl-wizard__subtitle">
					<?php
					if ( $env['has_active_forms'] && $env['woocommerce'] ) {
						printf(
							/* translators: %s: form plugin names. */
							esc_html__( 'We found %s and WooCommerce. ClickTrail will track form submissions and eCommerce sessions automatically.', 'click-trail-handler' ),
							esc_html( $active_str )
						);
					} elseif ( $env['has_active_forms'] ) {
						printf(
							/* translators: %s: form plugin names. */
							esc_html__( 'We found %s. ClickTrail will inject attribution data into your forms automatically.', 'click-trail-handler' ),
							esc_html( $active_str )
						);
					} elseif ( $env['woocommerce'] ) {
						esc_html_e( 'We found WooCommerce. ClickTrail will store attribution on every order automatically.', 'click-trail-handler' );
					} else {
						esc_html_e( 'ClickTrail will capture UTMs and click IDs from every visitor automatically.', 'click-trail-handler' );
					}
					?>
				</p>

				<div class="clicutcl-wizard__checklist">

					<div class="clicutcl-wizard__check-row">
						<span class="clicutcl-wizard__check-icon dashicons dashicons-yes-alt" style="color:var(--clicktrail-success)" aria-hidden="true"></span>
						<span class="clicutcl-wizard__check-label"><?php esc_html_e( 'UTM &amp; click ID capture', 'click-trail-handler' ); ?></span>
						<span class="clicutcl-wizard__check-badge clicutcl-wizard__check-badge--on"><?php esc_html_e( 'Auto', 'click-trail-handler' ); ?></span>
					</div>

					<div class="clicutcl-wizard__check-row">
						<span class="clicutcl-wizard__check-icon dashicons dashicons-yes-alt" style="color:var(--clicktrail-success)" aria-hidden="true"></span>
						<span class="clicutcl-wizard__check-label"><?php esc_html_e( 'First-touch &amp; last-touch attribution', 'click-trail-handler' ); ?></span>
						<span class="clicutcl-wizard__check-badge clicutcl-wizard__check-badge--on"><?php esc_html_e( 'Auto', 'click-trail-handler' ); ?></span>
					</div>

					<?php foreach ( $env['forms'] as $form ) : ?>
						<div class="clicutcl-wizard__check-row">
							<span
								class="clicutcl-wizard__check-icon dashicons <?php echo $form['active'] ? 'dashicons-yes-alt' : 'dashicons-minus'; ?>"
								style="color:<?php echo $form['active'] ? 'var(--clicktrail-success)' : 'var(--clicktrail-text-subtle)'; ?>"
								aria-hidden="true"
							></span>
							<span class="clicutcl-wizard__check-label">
								<?php
								printf(
									/* translators: %s: form plugin name. */
									esc_html__( '%s attribution', 'click-trail-handler' ),
									esc_html( $form['label'] )
								);
								?>
							</span>
							<?php if ( $form['active'] ) : ?>
								<span class="clicutcl-wizard__check-badge clicutcl-wizard__check-badge--on"><?php esc_html_e( 'Detected', 'click-trail-handler' ); ?></span>
							<?php else : ?>
								<span class="clicutcl-wizard__check-badge clicutcl-wizard__check-badge--off"><?php esc_html_e( 'Not found', 'click-trail-handler' ); ?></span>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>

					<?php if ( $env['woocommerce'] ) : ?>
						<div class="clicutcl-wizard__check-row">
							<span class="clicutcl-wizard__check-icon dashicons dashicons-yes-alt" style="color:var(--clicktrail-success)" aria-hidden="true"></span>
							<span class="clicutcl-wizard__check-label"><?php esc_html_e( 'WooCommerce order attribution', 'click-trail-handler' ); ?></span>
							<span class="clicutcl-wizard__check-badge clicutcl-wizard__check-badge--on"><?php esc_html_e( 'Detected', 'click-trail-handler' ); ?></span>
						</div>
					<?php endif; ?>

					<?php if ( ! empty( $env['cmp'] ) ) : ?>
						<div class="clicutcl-wizard__check-row">
							<span class="clicutcl-wizard__check-icon dashicons dashicons-yes-alt" style="color:var(--clicktrail-success)" aria-hidden="true"></span>
							<span class="clicutcl-wizard__check-label">
								<?php
								printf(
									/* translators: %s: CMP plugin name. */
									esc_html__( '%s consent integration', 'click-trail-handler' ),
									esc_html( $env['cmp'][0]['label'] )
								);
								?>
							</span>
							<span class="clicutcl-wizard__check-badge clicutcl-wizard__check-badge--on"><?php esc_html_e( 'Detected', 'click-trail-handler' ); ?></span>
						</div>
					<?php endif; ?>

					<?php if ( ! empty( $env['caching'] ) ) : ?>
						<div class="clicutcl-wizard__check-row">
							<span class="clicutcl-wizard__check-icon dashicons dashicons-yes-alt" style="color:var(--clicktrail-success)" aria-hidden="true"></span>
							<span class="clicutcl-wizard__check-label">
								<?php
								printf(
									/* translators: %s: caching/CDN plugin name. */
									esc_html__( '%s compatibility (client-side fallback)', 'click-trail-handler' ),
									esc_html( $env['caching'][0] )
								);
								?>
							</span>
							<span class="clicutcl-wizard__check-badge clicutcl-wizard__check-badge--on"><?php esc_html_e( 'Enabled', 'click-trail-handler' ); ?></span>
						</div>
					<?php endif; ?>

				</div><!-- /.clicutcl-wizard__checklist -->

				<div class="clicutcl-wizard__field">
					<label for="clicutcl-ga4-id" class="clicutcl-wizard__field-label">
						<?php esc_html_e( 'GA4 Measurement ID', 'click-trail-handler' ); ?>
						<span class="clicutcl-wizard__field-optional"><?php esc_html_e( 'optional', 'click-trail-handler' ); ?></span>
					</label>
					<input
						type="text"
						id="clicutcl-ga4-id"
						name="ga4_id"
						value="<?php echo esc_attr( $ga4_id ); ?>"
						placeholder="G-XXXXXXXXXX"
						class="regular-text clicutcl-wizard__input"
						autocomplete="off"
						spellcheck="false"
					/>
					<p class="description">
						<?php esc_html_e( 'Needed for GA4 event forwarding. You can add this later in Settings.', 'click-trail-handler' ); ?>
					</p>
				</div>

				<div class="clicutcl-wizard__field">
					<label class="clicutcl-wizard__checkbox-label">
						<input
							type="checkbox"
							id="clicutcl-run-paid-ads"
							name="run_paid_ads"
							value="1"
							class="clicutcl-wizard__checkbox"
						/>
						<?php esc_html_e( 'I run paid ads (Google Ads or Meta Ads)', 'click-trail-handler' ); ?>
					</label>
				</div>

			</div><!-- /.panel-inner -->

			<div class="clicutcl-wizard__actions">
				<button
					type="button"
					class="button button-primary clicutcl-wizard__btn"
					data-action="next"
					data-next="2"
					data-next-no-ads="3"
				>
					<?php esc_html_e( 'Next', 'click-trail-handler' ); ?>
					<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Step 2: ad platform toggles.
	 *
	 * This step is shown only when the user checks "I run paid ads" on Step 1.
	 * The hidden attribute is removed by wizard.js based on that checkbox state.
	 *
	 * @return void
	 */
	private static function render_step_2(): void {
		?>
		<div class="clicutcl-wizard__panel" data-panel="2" hidden>
			<div class="clicutcl-wizard__panel-inner">

				<h1 class="clicutcl-wizard__title">
					<?php esc_html_e( 'Where are your leads coming from?', 'click-trail-handler' ); ?>
				</h1>
				<p class="clicutcl-wizard__subtitle">
					<?php esc_html_e( 'ClickTrail captures click IDs automatically. Confirm which platforms you use so we can surface them in your form entries.', 'click-trail-handler' ); ?>
				</p>

				<div class="clicutcl-wizard__toggles">

					<label class="clicutcl-wizard__toggle-row is-checked" id="ct-google-row">
						<input
							type="checkbox"
							name="run_google_ads"
							value="1"
							checked
							class="clicutcl-wizard__toggle-input"
							aria-label="<?php esc_attr_e( 'Google Ads', 'click-trail-handler' ); ?>"
						/>
						<span class="clicutcl-wizard__toggle-switch" aria-hidden="true"></span>
						<span class="clicutcl-wizard__toggle-text">
							<strong><?php esc_html_e( 'Google Ads', 'click-trail-handler' ); ?></strong>
							<span><?php esc_html_e( 'Captures GCLID and auto-tag parameters from every Google Ads click.', 'click-trail-handler' ); ?></span>
						</span>
					</label>

					<label class="clicutcl-wizard__toggle-row is-checked" id="ct-meta-row">
						<input
							type="checkbox"
							name="run_meta_ads"
							value="1"
							checked
							class="clicutcl-wizard__toggle-input"
							aria-label="<?php esc_attr_e( 'Meta / Facebook Ads', 'click-trail-handler' ); ?>"
						/>
						<span class="clicutcl-wizard__toggle-switch" aria-hidden="true"></span>
						<span class="clicutcl-wizard__toggle-text">
							<strong><?php esc_html_e( 'Meta / Facebook Ads', 'click-trail-handler' ); ?></strong>
							<span><?php esc_html_e( 'Captures FBCLID from every Meta ad click.', 'click-trail-handler' ); ?></span>
						</span>
					</label>

				</div>

			</div><!-- /.panel-inner -->

			<div class="clicutcl-wizard__actions">
				<button type="button" class="button clicutcl-wizard__btn" data-action="back" data-back="1">
					<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
					<?php esc_html_e( 'Back', 'click-trail-handler' ); ?>
				</button>
				<button type="button" class="button button-primary clicutcl-wizard__btn" data-action="next" data-next="3">
					<?php esc_html_e( 'Next', 'click-trail-handler' ); ?>
					<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Step 3: confirmation summary and submit.
	 *
	 * @param array $env Setup_Detector::run() result.
	 * @return void
	 */
	private static function render_step_3( array $env ): void {
		$parts = array( __( 'UTM tracking: ON', 'click-trail-handler' ) );

		$active_labels = Setup_Detector::active_form_labels();
		if ( ! empty( $active_labels ) ) {
			$parts[] = sprintf(
				/* translators: %s: form plugin names. */
				__( 'Forms: %s', 'click-trail-handler' ),
				implode( ', ', $active_labels )
			);
		}
		if ( $env['woocommerce'] ) {
			$parts[] = __( 'WooCommerce: ON', 'click-trail-handler' );
		}
		?>
		<div class="clicutcl-wizard__panel" data-panel="3" hidden>
			<div class="clicutcl-wizard__panel-inner clicutcl-wizard__panel-inner--center">

				<div class="clicutcl-wizard__success-icon" aria-hidden="true">
					<span class="dashicons dashicons-yes-alt"></span>
				</div>

				<h1 class="clicutcl-wizard__title">
					<?php esc_html_e( "You're set up", 'click-trail-handler' ); ?>
				</h1>
				<p class="clicutcl-wizard__subtitle">
					<?php esc_html_e( 'ClickTrail is tracking your visitors. Attribution data will appear in your form entries as soon as someone submits a form with UTMs in the URL.', 'click-trail-handler' ); ?>
				</p>

				<div class="clicutcl-wizard__summary">
					<p class="clicutcl-wizard__summary-line" id="clicutcl-wizard-summary">
						<?php echo esc_html( implode( ' · ', $parts ) ); ?>
					</p>
					<p class="clicutcl-wizard__summary-ga4" id="clicutcl-wizard-ga4-line" hidden>
						<span class="dashicons dashicons-yes-alt" style="color:var(--clicktrail-success)" aria-hidden="true"></span>
						<span id="clicutcl-wizard-ga4-text"></span>
					</p>
				</div>

				<p class="clicutcl-wizard__test-tip">
					<strong><?php esc_html_e( 'Quick test:', 'click-trail-handler' ); ?></strong>
					<?php esc_html_e( 'Open your site with', 'click-trail-handler' ); ?>
					<code>?utm_source=test</code>
					<?php esc_html_e( 'in the URL, submit a form, and look for', 'click-trail-handler' ); ?>
					<code>ct_utm_source=test</code>
					<?php esc_html_e( 'in the entry.', 'click-trail-handler' ); ?>
				</p>

			</div><!-- /.panel-inner -->

			<div class="clicutcl-wizard__actions clicutcl-wizard__actions--center">
				<button type="button" class="button clicutcl-wizard__btn" data-action="back-smart">
					<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
					<?php esc_html_e( 'Back', 'click-trail-handler' ); ?>
				</button>
				<button type="submit" class="button button-primary clicutcl-wizard__btn">
					<?php esc_html_e( 'View my tracking dashboard', 'click-trail-handler' ); ?>
					<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
				</button>
			</div>

			<p class="clicutcl-wizard__advanced-link">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=clicutcl-settings' ) ); ?>">
					<?php esc_html_e( 'I want more control &rarr; Switch to Advanced Mode', 'click-trail-handler' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
