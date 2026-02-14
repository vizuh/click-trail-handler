# Hooks Reference

This file lists plugin-specific filters/actions exposed in current code.

## Filters

## Frontend Loading and Behavior

- `clicutcl_should_load_events_js`
  - location: `includes/class-clicutcl-core.php`
  - args: `(bool $should_load_events)`
  - purpose: allow force enable/disable events script loading

- `clicutcl_thank_you_matchers`
  - location: `includes/class-clicutcl-core.php`
  - args: `(array $matchers)`
  - purpose: patterns used by events JS to detect redirect-based lead thank-you pages

- `clicutcl_iframe_origin_allowlist`
  - location: `includes/class-clicutcl-core.php`
  - args: `(array $origins)`
  - purpose: allowlist for external iframe message origins in events JS

- `clicutcl_cookie_name`
  - location: `includes/admin/class-site-health.php`
  - args: `(string $cookie_name)`
  - purpose: cookie key used in Site Health cookie visibility test

## Tracking v2 Auth and Rate Limits

- `clicutcl_v2_token_ttl`
  - location: `includes/tracking/class-auth.php`
  - args: `(int $ttl_seconds)`

- `clicutcl_v2_allow_subdomain_tokens`
  - location: `includes/tracking/class-auth.php`
  - args: `(bool $allow, string $token_host, string $current_host)`

- `clicutcl_v2_allowed_token_hosts`
  - location: `includes/tracking/class-auth.php`
  - args: `(array|string $hosts, string $current_host)`

- `clicutcl_v2_rate_window`
  - location: `includes/api/class-tracking-controller.php`
  - args: `(int $seconds, string $scope)`

- `clicutcl_v2_rate_limit`
  - location: `includes/api/class-tracking-controller.php`
  - args: `(int $limit, string $scope)`

- `clicutcl_v2_token_nonce_limit`
  - location: `includes/api/class-tracking-controller.php`
  - args: `(int $limit)`

- `clicutcl_v2_event_buffer_size`
  - location: `includes/api/class-tracking-controller.php`
  - args: `(int $max_entries)`
  - default: `50`

- `clicutcl_v2_event_buffer_ttl`
  - location: `includes/api/class-tracking-controller.php`
  - args: `(int $ttl_seconds)`
  - default: `6 * HOUR_IN_SECONDS`

- `clicutcl_v2_trusted_proxies`
  - location: `includes/api/class-tracking-controller.php`
  - args: `(array|string $trusted_proxies)`

- `clicutcl_trusted_proxies`
  - locations:
    - `includes/api/class-tracking-controller.php`
    - `includes/api/class-log-controller.php` (legacy class)
  - args: `(array|string $trusted_proxies)`

## Webhook Security

- `clicutcl_webhook_replay_window`
  - location: `includes/tracking/class-webhook-auth.php`
  - args: `(int $seconds)`

- `clicutcl_webhook_replay_protection`
  - location: `includes/tracking/class-webhook-auth.php`
  - args: `(bool $enabled, WP_REST_Request $request)`

## Tracking v2 Settings Resolution

- `clicutcl_external_provider_secret`
  - location: `includes/tracking/class-settings.php`
  - args: `(string $secret, string $provider)`

- `clicutcl_external_provider_enabled`
  - location: `includes/tracking/class-settings.php`
  - args: `(bool $enabled, string $provider)`

- `clicutcl_lifecycle_token`
  - location: `includes/tracking/class-settings.php`
  - args: `(string $token)`

- `clicutcl_identity_fields_allowed`
  - location: `includes/tracking/class-consent-decision.php`
  - args: `(array $allowed_fields, array $context)`

- `clicutcl_v2_dedup_ttl`
  - location: `includes/tracking/class-dedup-store.php`
  - args: `(int $ttl_seconds)`

## Diagnostics and Telemetry

- `clicutcl_diag_last_error_ttl`
  - locations:
    - `includes/server-side/class-dispatcher.php`
    - `includes/api/class-log-controller.php` (legacy class)
  - args: `(int $ttl_seconds)`

- `clicutcl_diag_buffer_ttl`
  - locations:
    - `includes/server-side/class-dispatcher.php`
    - `includes/api/class-log-controller.php` (legacy class)
  - args: `(int $ttl_seconds)`

- `clicutcl_diag_dispatch_buffer_size`
  - location: `includes/server-side/class-dispatcher.php`
  - args: `(int $max_entries)`

- `clicutcl_failure_telemetry_flush_interval`
  - location: `includes/server-side/class-dispatcher.php`
  - args: `(int $seconds)`

- `clicutcl_failure_telemetry_bucket_limit`
  - location: `includes/server-side/class-dispatcher.php`
  - args: `(int $hours)`

- `clicutcl_failure_telemetry_ttl`
  - location: `includes/server-side/class-dispatcher.php`
  - args: `(int $seconds)`

- `clicutcl_diag_attempt_buffer_size`
  - location: `includes/api/class-log-controller.php` (legacy class)
  - args: `(int $max_entries)`

- `clicutcl_rate_limit`
  - location: `includes/api/class-log-controller.php` (legacy class)
  - args: `(array $rate, string $bucket)`
  - shape: `['limit' => int, 'window' => int]`

- `clicutcl_wa_token_ttl`
  - location: `includes/api/class-log-controller.php` (legacy class)
  - args: `(int $seconds)`

- `clicutcl_wa_token_nonce_limit`
  - location: `includes/api/class-log-controller.php` (legacy class)
  - args: `(int $limit)`

## Cleanup

- `clicutcl_queue_retention_days`
  - location: `includes/utils/class-cleanup.php`
  - args: `(int $days)`

- `clicutcl_preserve_data_on_uninstall`
  - location: `uninstall.php`
  - args: `(bool $preserve_data)`
  - default: `false` (tables are dropped)

## Actions

- `clicutcl_failure_telemetry_remote`
  - location: `includes/server-side/class-dispatcher.php`
  - fired only when `remote_failure_telemetry` is enabled
  - payload includes:
    - version
    - hour bucket
    - emitted timestamp
    - site host
    - aggregated failure code counts
