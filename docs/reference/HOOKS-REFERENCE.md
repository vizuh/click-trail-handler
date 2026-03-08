# Hooks Reference

- **Audience**: contributors, integrators, and maintainers
- **Canonical for**: public filters and actions exposed by the plugin
- **Update when**: a public hook is added, removed, renamed, or changes contract
- **Last verified against version**: `1.3.6`

This document lists the public custom hooks currently exposed by the active codebase.

## Frontend and Runtime Loading

### `clicutcl_should_load_events_js`

Type:

- filter

Purpose:

- override whether `assets/js/clicutcl-events.js` should load

Notes:

- this filter only affects asset loading
- the browser event collection capability gate still applies at runtime, so forcing the asset to load does not re-enable browser event listeners when collection is disabled in settings

Arguments:

- `bool $should_load_events`

### `clicutcl_thank_you_matchers`

Type:

- filter

Purpose:

- add extra thank-you page matchers for browser lead detection

Arguments:

- `array $matchers`

### `clicutcl_iframe_origin_allowlist`

Type:

- filter

Purpose:

- extend the allowlist used for external embedded form message handling

Arguments:

- `array $origins`

## Consent

### `clicutcl_consent_defaults`

Type:

- filter

Purpose:

- override global Consent Mode defaults

Arguments:

- `array $global_defaults`
- `string $mode`

### `clicutcl_consent_region_defaults`

Type:

- filter

Purpose:

- override region-specific Consent Mode defaults

Arguments:

- `array $region_defaults`
- `string $mode`

### `clicutcl_identity_fields_allowed`

Type:

- filter

Purpose:

- decide which identity fields may survive consent-aware resolution

Arguments:

- `array $allowed`
- `array $context`

## Event and Token Security

### `clicutcl_v2_token_ttl`

### `clicutcl_attribution_token_ttl`

### `clicutcl_v2_allow_subdomain_tokens`

### `clicutcl_v2_allowed_token_hosts`

### `clicutcl_v2_rate_window`

### `clicutcl_v2_rate_limit`

### `clicutcl_v2_token_nonce_limit`

Purpose:

- tune event intake token behavior, allowed hosts, rate limiting, and replay controls

## Webhook Security

### `clicutcl_webhook_replay_window`

### `clicutcl_webhook_replay_protection`

### `clicutcl_external_provider_secret`

### `clicutcl_external_provider_enabled`

Purpose:

- control webhook replay behavior, provider enablement, and provider secret resolution

## Lifecycle and Settings Security

### `clicutcl_lifecycle_token`

### `clicutcl_encrypt_settings_secrets`

Purpose:

- override lifecycle token resolution and secret-encryption behavior

## Proxies and Request Source

### `clicutcl_v2_trusted_proxies`

### `clicutcl_trusted_proxies`

Purpose:

- provide trusted proxy lists for request normalization

## Diagnostics and Telemetry

### `clicutcl_v2_event_buffer_size`

### `clicutcl_v2_event_buffer_ttl`

### `clicutcl_diag_dispatch_buffer_size`

### `clicutcl_diag_buffer_ttl`

### `clicutcl_diag_last_error_ttl`

### `clicutcl_failure_telemetry_flush_interval`

### `clicutcl_failure_telemetry_bucket_limit`

### `clicutcl_failure_telemetry_ttl`

### `clicutcl_diag_attempt_buffer_size`

Purpose:

- tune diagnostic ring buffers, last-error retention, and failure-telemetry behavior

## Dedup and Queue Retention

### `clicutcl_v2_dedup_ttl`

### `clicutcl_queue_retention_days`

Purpose:

- control dedup-marker lifetime and queue retention during cleanup

## Miscellaneous

### `clicutcl_cookie_name`

Purpose:

- override the cookie name used by Site Health checks

### `clicutcl_preserve_data_on_uninstall`

Purpose:

- preserve plugin data during uninstall instead of deleting tables and options

## Public Custom Action

### `clicutcl_failure_telemetry_remote`

Type:

- action

Purpose:

- receive aggregated delivery failure telemetry when remote telemetry is enabled
