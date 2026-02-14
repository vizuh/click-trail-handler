# Settings and Admin Reference

This document lists admin surfaces, option keys, and sanitize behavior.

## Admin Screens

Defined in `includes/admin/class-admin.php`.

Top-level menu:

- `ClickTrail` (`page=clicutcl-settings`)

Submenus:

- `Settings` (tabbed)
- `Logs` (`page=clicutcl-logs`)
- `Diagnostics` (`page=clicutcl-diagnostics`)

Settings tabs:

- `general` (Attribution)
- `whatsapp`
- `consent`
- `gtm`
- `server`
- `trackingv2` (Gutenberg-native screen rendered into `#clicutcl-tracking-v2-root`)

Network admin page (multisite):

- `page=clicutcl-network-settings`

## Admin Scripts and Styles

From `enqueue_admin_assets($hook)`:

- `admin-sitehealth.js` only on:
  - ClickTrail screens
  - Site Health screen
- `admin.css` only on ClickTrail screens
- `admin-diagnostics.js` only on diagnostics page
- `admin-tracking-v2.js` only on `page=clicutcl-settings&tab=trackingv2`

Tracking v2 admin script dependencies:

- `wp-element`
- `wp-components`
- `wp-i18n`

## Option: `clicutcl_attribution_settings`

Registered setting group:

- `clicutcl_attribution_settings`

Sanitizer:

- `Admin::sanitize_settings()`
- schema + merge semantics:
  - keeps existing known keys when omitted from POST
  - applies defaults for missing known keys
  - sanitizes only allowlisted schema keys

Known keys in schema:

- `enable_attribution` (0/1)
- `cookie_days` (1..3650)
- `enable_js_injection` (0/1)
- `inject_overwrite` (0/1)
- `inject_mutation_observer` (0/1)
- `inject_observer_target` (selector string, max length bounded)
- `enable_link_decoration` (0/1)
- `link_allowed_domains` (normalized CSV domain list)
- `link_skip_signed` (0/1)
- `enable_cross_domain_token` (0/1)
- `enable_whatsapp` (0/1)
- `whatsapp_append_attribution` (0/1)
- backward compatibility keys:
  - `enable_consent_banner`
  - `require_consent`
  - `consent_mode_region`

## Option: `clicutcl_consent_mode`

Class:

- `includes/Modules/consent-mode/class-consent-mode-settings.php`

Defaults:

- `enabled`: `false`
- `regions`: default region list from `Regions::get_regions()`

Sanitize behavior:

- accepts array or comma/space separated regions
- normalizes uppercase tokens
- alias mapping:
  - `EU -> EEA`
  - `GB -> UK`
- allows:
  - `EEA`, `UK`, `US`, `US-XX`, `AA`, `AA-BB` style tokens
- dedupes and stores as array

## Option: `clicutcl_gtm`

Class:

- `includes/Modules/GTM/class-gtm-settings.php`

Key:

- `container_id`

Validation:

- format must match `^GTM-[A-Z0-9]+$`

## Option: `clicutcl_server_side`

Registered by admin class using `sanitize_server_side_settings()`.

Keys:

- `enabled` (0/1)
- `endpoint_url` (URL)
- `adapter` (`generic|sgtm|meta_capi|google_ads|linkedin_capi`)
- `timeout` (1..15)
- `use_network` (multisite toggle)
- `remote_failure_telemetry` (0/1, opt-in)

Multisite:

- network option key: `clicutcl_server_side_network`
- site can inherit network defaults via `use_network`

## Option: `clicutcl_tracking_v2`

Class:

- `includes/tracking/class-settings.php`

Saved by:

- normal settings API (`register_setting`)
- tracking v2 AJAX UI save action (`clicutcl_save_tracking_v2_settings`)

Default structure:

- `feature_flags`
- `destinations`
- `identity_policy`
- `external_forms.providers`
- `lifecycle.crm_ingestion`
- `security`
- `diagnostics`
- `dedup`

Current default values in code:

```php
array(
  'feature_flags' => array(
    'event_v2' => 1,
    'external_webhooks' => 1,
    'connector_native' => 1,
    'diagnostics_v2' => 1,
    'lifecycle_ingestion' => 1,
  ),
  'destinations' => array(
    'meta' => array('enabled' => 0, 'credentials' => array()),
    'google' => array('enabled' => 0, 'credentials' => array()),
    'linkedin' => array('enabled' => 0, 'credentials' => array()),
    'reddit' => array('enabled' => 0, 'credentials' => array()),
    'pinterest' => array('enabled' => 0, 'credentials' => array()),
  ),
  'identity_policy' => array(
    'mode' => 'consent_gated_minimal',
  ),
  'external_forms' => array(
    'providers' => array(
      'calendly' => array('enabled' => 0, 'secret' => ''),
      'hubspot' => array('enabled' => 0, 'secret' => ''),
      'typeform' => array('enabled' => 0, 'secret' => ''),
    ),
  ),
  'lifecycle' => array(
    'crm_ingestion' => array('enabled' => 0, 'token' => ''),
  ),
  'security' => array(
    'token_ttl_seconds' => 7 * DAY_IN_SECONDS,
    'token_nonce_limit' => 0,
    'webhook_replay_window' => 300,
    'trusted_proxies' => array(),
    'allowed_token_hosts' => array(),
  ),
  'diagnostics' => array(
    'dispatch_buffer_size' => 20,
    'failure_flush_interval' => 10,
    'failure_bucket_retention' => 72,
  ),
  'dedup' => array(
    'ttl_seconds' => 7 * DAY_IN_SECONDS,
  ),
);
```

Sanitize behavior:

- merge existing + defaults + submitted keys
- allowlist per subtree
- typed/normalized values with bounds
- unknown keys are not introduced through sanitizer

## AJAX Actions

From `Admin::init()`:

- `wp_ajax_clicutcl_log_pii_risk`
- `wp_ajax_clicutcl_test_endpoint`
- `wp_ajax_clicutcl_toggle_debug`
- `wp_ajax_clicutcl_get_tracking_v2_settings`
- `wp_ajax_clicutcl_save_tracking_v2_settings`

Diagnostics AJAX:

- endpoint health check
- debug mode enable/disable (15-minute window via transient)

Tracking v2 AJAX:

- loads/saves full tracking v2 settings payload

## Site Health Integration

Class:

- `includes/admin/class-site-health.php`

Adds Site Health tests:

- cache/conflict detection
- admin diagnostics heartbeat check
- attribution cookie visibility check
