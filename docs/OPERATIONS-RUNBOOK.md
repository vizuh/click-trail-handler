# Operations Runbook

## Install and Activation

On activation, ClickTrail:

1. creates/updates DB tables (`clicutcl_events`, `clicutcl_queue`)
2. seeds tracking v2 defaults when missing
3. writes DB readiness flags
4. schedules:
   - `clicutcl_daily_cleanup`
   - `clicutcl_dispatch_queue` (5-minute schedule)

## Deactivation

On deactivation, ClickTrail clears:

- `clicutcl_daily_cleanup`
- `clicutcl_dispatch_queue`

## Health Checks

### Site Health Tests

Available via WordPress Site Health:

- caching/conflict indicators
- admin script heartbeat seen in last 24h
- attribution cookie visibility in request

### Server-side Endpoint Test

Admin diagnostics page button calls:

- AJAX action `clicutcl_test_endpoint`
- internally uses `Dispatcher::health_check()`

Diagnostics page also exposes:

- queue backlog stats (`pending`, `due_now`, `max_attempts`, `oldest_next`)
- one-click local data purge action (`clicutcl_purge_tracking_data`)
- recent v2 intake ring buffer (last normalized events, debug-window-only)

## Common Failure Patterns

### 1) Caching/minification delays break form injection

Symptoms:

- hidden attribution fields are blank or missing on submit

Where to check:

- page source/network confirms `assets/js/clicutcl-attribution.js` is loaded
- optimization plugin settings for JS delay/combine/defer
- form HTML after `DOMContentLoaded` and after dynamic render (popup/iframe blocks)

First fixes:

- exclude ClickTrail scripts from aggressive JS delay/merge rules
- keep mutation-observer-based injection enabled for dynamic forms
- purge page/CDN cache and retest in a fresh browser session

### 2) Consent default false leads to empty identity

Symptoms:

- events are accepted but identity fields are absent

Where to check:

- `ct_consent` cookie state
- diagnostics `event_intake` entries for consent flags and `identity_keys`

Expected behavior:

- in `consent_gated_minimal` mode, missing marketing consent means identity is intentionally omitted

### 3) Token host/blog mismatch rejects v2 intake

Symptoms:

- `/v2/events/batch` rejects requests (401/403) during subdomain or multi-host setups

Where to check:

- batch response error code
- diagnostics intake ring buffer `kind=gate` entries (`status=rejected`, mismatch reason)
- `security.allowed_token_hosts` and token mint host/blog claims

First fixes:

- ensure token is minted on the serving host
- add explicit allowed hosts for same-domain/subdomain deployments

### 4) QA loops trigger rate limiting (429)

Symptoms:

- repeated test submissions return 429

Where to check:

- batch response status/code (`rate_limited`, `nonce_replay_limited`)
- diagnostics intake ring buffer reject reason
- tracking v2 security settings: `rate_limit_window`, `rate_limit_limit`, `token_nonce_limit`

First fixes:

- relax limits temporarily in non-production QA
- clear wait window and rotate test token/nonce when replay limits are enabled

### 5) Queue cron not running prevents retries

Symptoms:

- failed events stay pending and are never retried

Where to check:

- diagnostics queue backlog (`pending` increases, `due_now` remains high)
- scheduled hook presence for `clicutcl_dispatch_queue`
- transient lock `clicutcl_queue_lock` stuck

First fixes:

- run cron manually (`wp cron event run clicutcl_dispatch_queue`) in staging
- ensure host-level cron triggers WP-Cron on low-traffic sites
- clear stale queue lock transient if process crashed

## Queue Operations

Queue processor:

- hook: `clicutcl_dispatch_queue`
- worker: `CLICUTCL\Server_Side\Queue::process()`
- batch size: 10 rows per run
- max attempts per row: 5
- retry backoff: `60 * 2^attempt` seconds, capped at 3600

Locking:

- transient lock `clicutcl_queue_lock` (60s)

Dedup in queue:

- checks destination+event dedup before send
- marks dedup on successful send

## Diagnostics and Telemetry

### Debug Window

Admin diagnostics can toggle debug for 15 minutes:

- transient `clicutcl_debug_until`

When debug is enabled:

- dispatch ring buffer logging is recorded
- legacy v1 attempt ring buffer (if v1 controller is in use) records entries

### Always-on Failure Telemetry

Always active, even when debug is off:

- aggregated hourly counters only
- no payload body storage
- no raw PII

Storage:

- transient `clicutcl_failure_telemetry`

Remote reporting:

- disabled by default
- opt-in via `clicutcl_server_side.remote_failure_telemetry`
- emits action `clicutcl_failure_telemetry_remote`

## DB Readiness and Hot Path Guards

Readiness flags are used to avoid repeated schema checks on hot paths:

- event table readiness
- queue table readiness

Queue and legacy log controller memoize readiness in-request and use periodic recheck patterns.

## Cleanup

Daily cleanup task:

- deletes old `clicutcl_events` rows (retention linked to attribution cookie days, default 90)
- deletes old `clicutcl_queue` rows (default 7 days, filterable)

## Multisite

Server-side transport can be managed network-wide:

- network option: `clicutcl_server_side_network`
- per-site `use_network` toggle controls inheritance

## Uninstall

`uninstall.php` currently:

- deletes plugin option keys (including legacy and readiness keys)
- clears queue and daily cleanup cron hooks
- drops queue and events tables by default
- supports data preservation override via `clicutcl_preserve_data_on_uninstall` filter
