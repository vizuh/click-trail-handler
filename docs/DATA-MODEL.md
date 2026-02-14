# Data Model

This document covers persistent and ephemeral storage used by ClickTrail.

## Database Tables

Created by `includes/database/class-installer.php`.

## 1) `{$wpdb->prefix}clicutcl_events`

Columns:

- `id` bigint unsigned PK auto increment
- `event_type` varchar(50) not null
- `event_data` longtext
- `created_at` datetime default current timestamp

Indexes:

- `PRIMARY KEY (id)`
- `KEY event_type (event_type)`

Usage:

- legacy event log storage
- form submission and WA click logging in legacy flows
- admin logs list table reads from this table

## 2) `{$wpdb->prefix}clicutcl_queue`

Columns:

- `id` bigint unsigned PK auto increment
- `event_name` varchar(100) not null
- `event_id` varchar(128) not null
- `adapter` varchar(40) not null
- `endpoint` text
- `payload` longtext not null
- `attempts` int unsigned default 0
- `next_attempt_at` datetime not null
- `last_error` text
- `created_at` datetime default current timestamp

Indexes:

- `PRIMARY KEY (id)`
- `UNIQUE KEY event_name_event_id (event_name, event_id)`
- `KEY next_attempt_at (next_attempt_at)`

Usage:

- retry queue for failed dispatches
- processed by cron worker

## WordPress Options

Core options:

- `clicutcl_attribution_settings`
- `clicutcl_consent_mode`
- `clicutcl_gtm`
- `clicutcl_server_side`
- `clicutcl_tracking_v2`

Network option:

- `clicutcl_server_side_network`

DB readiness flags:

- `clicutcl_events_table_ready`
- `clicutcl_events_table_checked_at`
- `clicutcl_queue_table_ready`
- `clicutcl_queue_table_checked_at`
- backward-compatible aggregate:
  - `clicutcl_db_ready`
  - `clicutcl_db_ready_checked_at`

Other options used:

- `clicutcl_sitehealth_status`
- `clicutcl_pii_risk_detected`

Legacy fallback option keys still read in some code paths:

- `clicutcl_last_error`
- `clicutcl_dispatch_log`
- `clicutcl_attempts`

## Transients

Debug and diagnostics:

- `clicutcl_debug_until`
- `clicutcl_last_error`
- `clicutcl_dispatch_buffer`
- `clicutcl_failure_telemetry`
- `clicutcl_failure_flush_lock`

Dedup and API guards:

- `clicutcl_v2_dup_*`
- `clicutcl_v2_dedup_stats`
- `clicutcl_v2_rl_*`
- `clicutcl_v2_nonce_*`

Queue lock:

- `clicutcl_queue_lock`

Webhook replay:

- `clicutcl_wh_replay_*`

Legacy v1 guard transients (in `Log_Controller` class):

- `clicutcl_evt_*`
- `clicutcl_wa_nonce_*`
- `clicutcl_rl_*`
- `clicutcl_attempts_buffer`

## Cookies and Browser Storage

Attribution and identity:

- cookie `attribution` (default via localized config)
- optional legacy cookie key read by PHP: `ct_attribution`
- cookie `ct_session_id`
- cookie `ct_visitor_id`

Consent:

- cookie `ct_consent`

Server event cookies (temporary):

- `ct_event_login`
- `ct_event_signup`
- `ct_event_comment`

Local/session storage:

- localStorage `attribution`
- localStorage `ct_visitor_id`
- sessionStorage `ct_session_id`
- sessionStorage markers for thank-you dedup in events script

## Cron Hooks

Scheduled hooks used:

- `clicutcl_daily_cleanup` (daily)
- `clicutcl_dispatch_queue` (custom 5-minute schedule)

Cleanup behavior:

- deletes old rows from `clicutcl_events` (retention based on cookie days setting)
- deletes old rows from `clicutcl_queue` (retention filter default 7 days)

## Uninstall Behavior

`uninstall.php`:

- removes plugin options (including some legacy keys)
- clears queue cron schedule
- drops queue table
- does not explicitly drop `clicutcl_events` table

