<?php
namespace CLICUTCL\Admin;

if (!defined('ABSPATH')) exit;

class SiteHealth {
	const OPTION_STATUS = 'clicutcl_sitehealth_status';

	public function register() {
		add_filter('site_status_tests', [$this, 'add_tests']);
		add_action('admin_init', [$this, 'maybe_schedule_status_update']);
		add_action('wp_ajax_clicutcl_sitehealth_ping', [$this, 'ajax_ping']);
	}

	public function add_tests($tests) {
		$tests['direct']['clicutcl_cache_detect'] = [
			'label' => __('ClickTrail: Caching/conflicts detected', 'click-trail-handler'),
			'test'  => [$this, 'test_cache_conflicts'],
		];

		$tests['direct']['clicutcl_js_seen'] = [
			'label' => __('ClickTrail: Frontend script running', 'click-trail-handler'),
			'test'  => [$this, 'test_js_seen'],
		];

		$tests['direct']['clicutcl_cookie_seen'] = [
			'label' => __('ClickTrail: Attribution cookie readable', 'click-trail-handler'),
			'test'  => [$this, 'test_cookie_seen'],
		];

		return $tests;
	}

	public function test_cache_conflicts() {
		$found = [];

		if (defined('WP_ROCKET_VERSION')) $found[] = 'WP Rocket';
		if (defined('LSCWP_V')) $found[] = 'LiteSpeed Cache';
		if (defined('WPCACHEHOME')) $found[] = 'WP Super Cache';
		if (defined('AUTOPTIMIZE_PLUGIN_VERSION')) $found[] = 'Autoptimize';

		// Host-level hints (best-effort)
		if (defined('WPE_APIKEY') || isset($_SERVER['WPE_APIKEY'])) $found[] = 'WP Engine (host caching)';
		if (isset($_SERVER['HTTP_CF_RAY'])) $found[] = 'Cloudflare (possible caching/optimization)';

		if (!$found) {
			return [
				'status' => 'good',
				'label' => __('No common caching/conflict plugins detected', 'click-trail-handler'),
				'description' => __('ClickTrail will still work best with JS injection enabled.', 'click-trail-handler'),
			];
		}

		return [
			'status' => 'recommended',
			'label' => __('Caching/optimization detected (JS injection recommended)', 'click-trail-handler'),
			'description' => sprintf(
				__('Detected: %s. Full-page caching can make server-side hidden fields stale. Enable ClickTrail’s JS Field Injector.', 'click-trail-handler'),
				esc_html(implode(', ', $found))
			),
		];
	}

	public function test_js_seen() {
		$status = get_option(self::OPTION_STATUS, []);
		$last_seen = isset($status['js_last_seen']) ? intval($status['js_last_seen']) : 0;

		if ($last_seen && (time() - $last_seen) < DAY_IN_SECONDS) {
			return [
				'status' => 'good',
				'label' => __('Frontend script seen in the last 24h', 'click-trail-handler'),
				'description' => __('Your frontend script reported activity recently.', 'click-trail-handler'),
			];
		}

		return [
			'status' => 'recommended',
			'label' => __('Frontend script not seen recently', 'click-trail-handler'),
			'description' => __('If you use caching/minification, confirm the ClickTrail JS is loaded on public pages.', 'click-trail-handler'),
		];
	}

	public function test_cookie_seen() {
		// Server-side can only check if the cookie arrives in requests (best-effort).
		$cookie_name = apply_filters('clicutcl_cookie_name', 'attribution'); // Default 'attribution' from core
		$has_cookie = isset($_COOKIE[$cookie_name]) && !empty($_COOKIE[$cookie_name]);

		$options = get_option('clicutcl_consent_mode', []);
		$consent_enabled = !empty($options['enabled']);

		if ($has_cookie) {
			return [
				'status' => 'good',
				'label' => __('Attribution cookie present in request', 'click-trail-handler'),
				'description' => __('Server received the attribution cookie.', 'click-trail-handler'),
			];
		}

		$description = __('This may be normal if no UTM visit occurred.', 'click-trail-handler');
		if ($consent_enabled) {
			$description .= ' ' . __('Note: Consent Mode is enabled. If you have not granted consent, no cookie will be set.', 'click-trail-handler');
		} else {
			$description .= ' ' . __('Test by visiting a page with ?utm_source=test.', 'click-trail-handler');
		}

		return [
			'status' => 'recommended',
			'label' => __('Attribution cookie not present in request', 'click-trail-handler'),
			'description' => $description,
		];
	}

	public function maybe_schedule_status_update() {
		// No cron needed; we store pings from admin JS.
	}

	public function ajax_ping() {
		if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'forbidden'], 403);
		check_ajax_referer('clicutcl_sitehealth', 'nonce');

		$status = get_option(self::OPTION_STATUS, []);
		$status['js_last_seen'] = time();
		update_option(self::OPTION_STATUS, $status, false);

		wp_send_json_success(['ok' => true]);
	}
}
