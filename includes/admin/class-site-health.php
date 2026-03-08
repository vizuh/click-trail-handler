<?php

namespace CLICUTCL\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SiteHealth {
	private const OPTION_STATUS = 'clicutcl_sitehealth_status';

	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_tests' ) );
		add_action( 'admin_init', array( $this, 'maybe_schedule_status_update' ) );
		add_action( 'wp_ajax_clicutcl_sitehealth_ping', array( $this, 'ajax_ping' ) );
	}

	public function add_tests( array $tests ): array {
		$tests['direct']['clicutcl_cache_detect'] = array(
			'label' => __( 'ClickTrail: Caching/conflicts detected', 'click-trail-handler' ),
			'test'  => array( $this, 'test_cache_conflicts' ),
		);

		$tests['direct']['clicutcl_js_seen'] = array(
			'label' => __( 'ClickTrail: Admin diagnostics script heartbeat', 'click-trail-handler' ),
			'test'  => array( $this, 'test_js_seen' ),
		);

		$tests['direct']['clicutcl_cookie_seen'] = array(
			'label' => __( 'ClickTrail: Attribution cookie readable', 'click-trail-handler' ),
			'test'  => array( $this, 'test_cookie_seen' ),
		);

		return $tests;
	}

	public function test_cache_conflicts(): array {
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

		// Host-level hints (best-effort).
		$wpe_api_key = filter_input( INPUT_SERVER, 'WPE_APIKEY', FILTER_UNSAFE_RAW );
		if ( defined( 'WPE_APIKEY' ) || ! empty( $wpe_api_key ) ) {
			$found[] = 'WP Engine (host caching)';
		}

		$cf_ray = filter_input( INPUT_SERVER, 'HTTP_CF_RAY', FILTER_UNSAFE_RAW );
		if ( ! empty( $cf_ray ) ) {
			$found[] = 'Cloudflare (possible caching/optimization)';
		}

		if ( empty( $found ) ) {
			return array(
				'status'      => 'good',
				'label'       => __( 'No common caching/conflict plugins detected', 'click-trail-handler' ),
				'description' => __( 'ClickTrail will still work best with JS injection enabled.', 'click-trail-handler' ),
			);
		}

		return array(
			'status'      => 'recommended',
			'label'       => __( 'Caching/optimization detected (client-side capture recommended)', 'click-trail-handler' ),
			'description' => sprintf(
				/* translators: %s: List of detected caching or conflict plugins. */
				__( 'Detected: %s. Full-page caching can make hidden field values stale. Enable ClickTrail\'s client-side capture fallback.', 'click-trail-handler' ),
				esc_html( implode( ', ', $found ) )
			),
		);
	}

	public function test_js_seen(): array {
		$status    = get_option( self::OPTION_STATUS, array() );
		$last_seen = isset( $status['js_last_seen'] ) ? absint( $status['js_last_seen'] ) : 0;

		if ( $last_seen && ( time() - $last_seen ) < DAY_IN_SECONDS ) {
			return array(
				'status'      => 'good',
				'label'       => __( 'Admin diagnostics script seen in the last 24h', 'click-trail-handler' ),
				'description' => __( 'A recent wp-admin heartbeat ping was recorded.', 'click-trail-handler' ),
			);
		}

		return array(
			'status'      => 'recommended',
			'label'       => __( 'Admin diagnostics script not seen recently', 'click-trail-handler' ),
			'description' => __( 'Open a ClickTrail admin screen (or Site Health) to refresh the diagnostics heartbeat.', 'click-trail-handler' ),
		);
	}

	public function test_cookie_seen(): array {
		// Server-side can only check if the cookie arrives in requests (best-effort).
		$cookie_name  = sanitize_key( (string) apply_filters( 'clicutcl_cookie_name', 'attribution' ) );
		$cookie_value = isset( $_COOKIE[ $cookie_name ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ $cookie_name ] ) ) : '';
		$has_cookie   = '' !== $cookie_value;

		$options         = get_option( 'clicutcl_consent_mode', array() );
		$consent_enabled = ! empty( $options['enabled'] );

		if ( $has_cookie ) {
			return array(
				'status'      => 'good',
				'label'       => __( 'Attribution cookie present in request', 'click-trail-handler' ),
				'description' => __( 'Server received the attribution cookie.', 'click-trail-handler' ),
			);
		}

		$description = __( 'This may be normal if no UTM visit occurred.', 'click-trail-handler' );
		if ( $consent_enabled ) {
			$description .= ' ' . __( 'Note: Consent Mode is enabled. If you have not granted consent, no cookie will be set.', 'click-trail-handler' );
		} else {
			$description .= ' ' . __( 'Test by visiting a page with ?utm_source=test.', 'click-trail-handler' );
		}

		return array(
			'status'      => 'recommended',
			'label'       => __( 'Attribution cookie not present in request', 'click-trail-handler' ),
			'description' => $description,
		);
	}

	public function maybe_schedule_status_update(): void {
		// No cron needed; we store pings from admin JS.
	}

	public function ajax_ping(): void {
		check_ajax_referer( 'clicutcl_sitehealth', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Forbidden', 'click-trail-handler' ),
				),
				403
			);
		}

		$status                 = get_option( self::OPTION_STATUS, array() );
		$status['js_last_seen'] = time();
		update_option( self::OPTION_STATUS, $status, false );

		wp_send_json_success( array( 'ok' => true ) );
	}
}
