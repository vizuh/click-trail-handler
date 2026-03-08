# Data Model

- **Audience**: contributors, maintainers, and reviewers
- **Canonical for**: option keys, cookies, tables, transients, cron hooks, and persistence surfaces
- **Update when**: stored keys, retention behavior, queue schema, or cookie/storage usage changes
- **Last verified against version**: `1.3.5`

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

## Custom Database Tables

Created by:

- `includes/database/class-installer.php`

## `wp_clicutcl_events`

Purpose:

- store event log rows for the admin Logs screen

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

## Client-Side Storage

Cookies:

- attribution cookie: `attribution`
- consent cookie: default `ct_consent`
- session cookie fallback: `ct_session_id`
- visitor cookie fallback: `ct_visitor_id`

Browser storage:

- `sessionStorage['ct_session_id']`
- `localStorage['ct_visitor_id']`

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

## Uninstall Behavior

`uninstall.php`:

- removes plugin options
- clears scheduled hooks
- clears ClickTrail transients
- drops queue and events tables by default

Data preservation can be overridden with:

- `clicutcl_preserve_data_on_uninstall`
