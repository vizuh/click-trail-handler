# Data Model

- **Audience**: contributors, maintainers, and reviewers
- **Canonical for**: option keys, cookies, tables, transients, cron hooks, and persistence surfaces
- **Update when**: stored keys, retention behavior, queue schema, or cookie/storage usage changes
- **Last verified against version**: `1.5.1`

This document summarizes the active storage surfaces used by ClickTrail.

## WordPress Options

Primary option keys:

- `clicutcl_attribution_settings`
- `clicutcl_consent_mode`
- `clicutcl_gtm`
- `clicutcl_server_side`
- `clicutcl_server_side_network`
- `clicutcl_tracking_v2`

Operational and readiness keys:

- `clicutcl_last_error`
- `clicutcl_dispatch_log`
- `clicutcl_db_ready`
- `clicutcl_db_ready_checked_at`
- `clicutcl_events_table_ready`
- `clicutcl_events_table_checked_at`
- `clicutcl_queue_table_ready`
- `clicutcl_queue_table_checked_at`

## Option Responsibilities

## `clicutcl_attribution_settings`

Stores:

- attribution enablement
- retention days
- client-side fallback
- dynamic-content watching
- overwrite behavior
- cross-domain controls
- WhatsApp settings

## `clicutcl_consent_mode`

Stores:

- consent mode enablement
- consent behavior mode
- consent regions
- CMP source
- CMP timeout
- consent cookie metadata

## `clicutcl_gtm`

Stores:

- GTM container ID
- GTM loader mode
- tagging-server URL
- first-party loader toggle
- custom loader toggle and path
- richer Woo `dataLayer` toggle
- consent-aware Woo `user_data` toggle

## `clicutcl_server_side`

Stores:

- site-level server-side transport settings

## `clicutcl_server_side_network`

Stores:

- multisite network defaults for server-side transport

## `clicutcl_tracking_v2`

Stores advanced runtime state for:

- feature flags
- destinations
- identity policy
- external provider configuration
- lifecycle ingestion
- security controls
- diagnostics tuning
- dedup tuning

Current destination state lives here as well, including:

- `meta`
- `google`
- `linkedin`
- `reddit`
- `pinterest`
- `tiktok`

## Client-Side Attribution Storage

Cookie key:

- `attribution` (or `ct_attribution` on older installs still carrying the legacy name)

Browser storage behavior:

- attribution is stored in a first-party cookie for server-readable integrations
- localStorage keeps a TTL-bound mirror of the attribution payload for cached or dynamic-page resilience
- the localStorage mirror now carries explicit expiry metadata tied to `cookie_days`
- legacy localStorage copies without expiry metadata are discarded instead of being revived indefinitely
- when consent resolves to denied, both the attribution cookie and the localStorage mirror are cleared
- attribution metadata fields use the same `ft_` / `lt_` key convention as the rest of the schema, and legacy `first_*` / `last_*` aliases are normalized back to the canonical keys on read
- campaign attribution now includes the extended GA-style query fields `utm_id`, `utm_source_platform`, `utm_creative_format`, and `utm_marketing_tactic` under `ft_*` / `lt_*` keys
- browser-level identifiers such as `fbc`, `fbp`, `ttp`, `li_gc`, `ga_client_id`, and `ga_session_id` are stored at the top level of the attribution payload when available and permitted by consent

## Custom Database Tables

Created by:

- `includes/database/class-installer.php`

## `wp_clicutcl_events`

Purpose:

- store event log rows for the admin Logs screen
- store form submission event payloads including resolved attribution and, when available, consent-aware identity fields

Columns:

- `id`
- `event_type`
- `event_data`
- `created_at`

## `wp_clicutcl_queue`

Purpose:

- store queued server-side delivery retries

Columns:

- `id`
- `event_name`
- `event_id`
- `adapter`
- `endpoint`
- `payload`
- `attempts`
- `next_attempt_at`
- `last_error`
- `created_at`

## WooCommerce Order Meta Surfaces

WooCommerce order-level tracking state now also uses order meta for traceability:

- `_clicutcl_tracking_sent`: purchase dedup marker written only after a successful, skipped, or confirmed queued purchase attempt
- `_clicutcl_woo_trace_snapshot`: stored purchase and milestone trace snapshots
- `_clicutcl_woo_milestone_sent_{event_name}`: per-milestone sent markers written only after a successful, skipped, or confirmed queued milestone attempt

The trace snapshot stores:

- `event_name`
- `event_id`
- `source_hook`
- `attempted_at`
- canonical payload snapshot
- dispatch result summary

## Client-Side Storage

Cookies:

- attribution cookie: `attribution`
- consent cookie: default `ct_consent`
- lightweight session ID fallback: `ct_session_id`
- lightweight visitor ID fallback: `ct_visitor_id`
- canonical session state cookie: `ct_session`

Browser storage:

- `sessionStorage['ct_session_id']`
- `localStorage['ct_visitor_id']`
- `localStorage['ct_session']`

Notes:

- `ct_session` stores the structured session object used by the current session manager and server-side readers
- `ct_session_id` and `ct_visitor_id` remain lightweight browser identity fallbacks used by event helpers and compatibility paths
- the richer session object is not stored in `sessionStorage['ct_session_id']`

## Diagnostics and Queue Transients

Common transients:

- `clicutcl_debug_until`
- `clicutcl_dispatch_buffer`
- `clicutcl_last_error`
- `clicutcl_failure_telemetry`
- `clicutcl_failure_flush_lock`
- `clicutcl_health_check_result`
- `clicutcl_queue_lock`
- `clicutcl_v2_events_buffer`

There are also dynamic transient families used for:

- rate limiting
- token replay / nonce guards
- diagnostics buffers
- dedup markers

## Cron Hooks

Scheduled hooks:

- `clicutcl_daily_cleanup`
- `clicutcl_dispatch_queue`

Cleanup behavior:

- event rows retained according to attribution retention days
- queue rows retained according to `clicutcl_queue_retention_days` filter

## Secret Handling

Secrets stored inside `clicutcl_tracking_v2` are:

- masked in admin responses
- treated as write-only updates
- optionally encrypted at rest when supported and enabled

## Server-Side Event Shape Notes

Legacy server-side event payloads now carry identity as a first-class top-level `identity` object.

For backward compatibility during the transition, that resolved identity is also mirrored into `meta.identity` when present.

When the richer Woo `dataLayer` contract is enabled, thank-you page purchase pushes may also include:

- `event_id`
- `user_data`

## Uninstall Behavior

`uninstall.php`:

- removes plugin options
- clears scheduled hooks
- clears ClickTrail transients
- drops queue and events tables by default

Data preservation can be overridden with:

- `clicutcl_preserve_data_on_uninstall`
