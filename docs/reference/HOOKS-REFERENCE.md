# Hooks Reference

- **Audience**: contributors, integrators, and maintainers
- **Canonical for**: public filters and actions exposed by the plugin
- **Update when**: a public hook is added, removed, renamed, or changes contract
- **Last verified against version**: `1.8.3`

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

## Gravity Forms

### Notes about the GF integration

**Entry meta scope (M-4):** the GF adapter declares the full `ct_*` attribution key set as Gravity Forms entry meta for **all forms**, regardless of the per-form tracking toggle. This is a metadata declaration only — it makes the columns available to GF's entry-list picker, exporter, and merge-tag UI. Actual values are written at `gform_after_submission` time and gated by the per-form toggle (`clicutcl_gf_tracking_enabled` filter), so disabled forms produce empty entries with no `ct_*` values.

**Channel labels are stored data, not UI strings (M-5):** values like `"Google Ads"`, `"Microsoft Ads"`, `"Gemini"`, `"ChatGPT"`, `"Unknown"`, etc. are persisted to `ct_ft_channel` and consumed by reports, exports, and downstream automations. They are **deliberately not wrapped in `__()`** — a Portuguese site and an English site must record the same label for the same source so cross-locale reporting is consistent. If you want to localise the display in a single context, use `clicutcl_gf_channel_label` (overrides the stored value) or transform it at the reporting layer; do not translate it via WordPress text domain.

### `clicutcl_gf_tracking_enabled`

Type:

- filter

Purpose:

- override whether attribution tracking is enabled for a specific Gravity Forms form

Arguments:

- `bool $enabled` — current state (per-form meta when set, otherwise global `gf_tracking_default_enabled` option)
- `int $form_id`
- `array|null $form` — full form object when available

Notes:

- returning `false` suppresses all `ct_*` entry meta writes, merge tag population, and field pre-population for that form

### `clicutcl_gf_channel_label`

Type:

- filter

Purpose:

- override the resolved channel label before it is stored as `ct_ft_channel` entry meta

Arguments:

- `string $channel` — computed label (e.g. `"Google Ads"`, `"ChatGPT"`, `"Unknown"`)
- `array $payload` — full attribution payload at submission time
- `array $entry` — Gravity Forms entry object
- `array $form` — Gravity Forms form object

### `clicutcl_gf_merge_tag_value`

Type:

- filter

Purpose:

- override the raw value resolved for any `{clicutcl_*}` merge tag before formatting is applied

Arguments:

- `string $value` — raw meta value (may be empty string)
- `string $tag` — merge tag key without braces (e.g. `"clicutcl_channel"`, `"clicutcl_click_id"`)
- `array $entry` — Gravity Forms entry object
- `array $form` — Gravity Forms form object

### `clicutcl_gf_merge_tag_formatted_value`

Type:

- filter

Purpose:

- override the formatted value for any `{clicutcl_*}` merge tag after escaping and encoding are applied

Arguments:

- `string $formatted` — value after `esc_html` / `urlencode` / `nl2br` as requested by the notification context
- `string $tag` — merge tag key without braces
- `array $entry` — Gravity Forms entry object
- `array $form` — Gravity Forms form object
- `bool $url_encode`
- `bool $esc_html`
- `string $format`

### `clicutcl_gf_merge_tag_default_value`

Type:

- filter

Purpose:

- supply a fallback string when a `{clicutcl_*}` merge tag resolves to an empty value

Arguments:

- `string $default` — empty string by default
- `string $tag` — merge tag key without braces
