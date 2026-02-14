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

- deletes plugin option keys (including some legacy keys)
- clears queue cron hook
- drops queue table

Current behavior to note:

- events table is not explicitly dropped

