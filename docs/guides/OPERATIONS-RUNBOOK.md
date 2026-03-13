# Operations Runbook

- **Audience**: maintainers, support engineers, and reviewers
- **Canonical for**: activation checks, queue behavior, diagnostics, and common failure handling
- **Update when**: operational troubleshooting, queue behavior, diagnostics surfaces, or recovery steps change
- **Last verified against version**: `1.3.9`

## Activation and Boot

On activation, ClickTrail:

1. creates or updates `clicutcl_events` and `clicutcl_queue`
2. writes DB readiness flags
3. seeds advanced settings defaults if missing
4. schedules:
   - `clicutcl_daily_cleanup`
   - `clicutcl_dispatch_queue`

## Deactivation

On deactivation, ClickTrail clears:

- `clicutcl_daily_cleanup`
- `clicutcl_dispatch_queue`

## First Operational Checks

After enabling the plugin, validate:

1. `ClickTrail > Settings` loads and saves correctly
2. attribution is captured from a test URL with UTMs
3. a supported form receives attribution fields
4. if WooCommerce is active, a test order stores attribution
5. if server-side delivery is enabled, `Diagnostics > Endpoint Test` succeeds

## Health and Visibility Surfaces

Primary operational surfaces:

- `ClickTrail > Diagnostics`
- `ClickTrail > Logs`
- dashboard widget
- Site Health tests

Diagnostics exposes:

- endpoint test
- queue backlog
- last error
- debug logging state
- recent dispatches
- failure telemetry
- local purge action

## Queue Behavior

Queue class:

- `CLICUTCL\Server_Side\Queue`

Defaults:

- cron hook: `clicutcl_dispatch_queue`
- interval: every 5 minutes
- batch size: 10 rows
- max attempts: 5
- lock transient: `clicutcl_queue_lock`

Retries use exponential backoff and stop after max attempts.

## Common Failure Patterns

## 1. Cached pages or delayed JS break form enrichment

Symptoms:

- hidden attribution fields stay empty

Checks:

- confirm `clicutcl-attribution.js` loads
- confirm consent has been granted when required
- confirm client-side fallback is enabled
- inspect whether the form appears after page load

Typical fixes:

- leave client-side fallback enabled
- leave dynamic-content watching enabled
- exclude ClickTrail scripts from aggressive delay or merge rules
- purge cache and retest

## 2. Consent prevents attribution or events

Symptoms:

- no attribution stored
- no browser events pushed through the expected flow

Checks:

- current consent mode setting
- CMP source
- resolved consent cookie state

Typical fixes:

- verify whether the site should be in `strict`, `relaxed`, or `geo`
- confirm the CMP bridge is actually resolving

## 3. Browser events appear in `dataLayer` but not in delivery

Symptoms:

- events show up client-side, but no server-side attempts happen

Checks:

- `Events > browser event collection`
- `Delivery > Enable server-side delivery`
- endpoint URL
- diagnostics health summary

Typical explanation:

- browser collection and `dataLayer` usage can work even when delivery transport is off

## 4. Browser event collection is off, but attribution still works

Symptoms:

- attribution capture still works
- forms still receive attribution
- no browser event listeners are active
- no browser-generated events appear in `dataLayer`

Explanation:

- the browser event collection toggle only controls `assets/js/clicutcl-events.js`
- attribution capture remains a separate capability

## 5. Queue backlog grows and does not drain

Symptoms:

- pending rows increase
- due-now count stays high

Checks:

- endpoint health
- WP-Cron activity
- stale queue lock
- adapter and endpoint validity

Typical fixes:

- ensure WP-Cron runs on low-traffic environments
- correct endpoint or adapter mismatch
- clear stale locks in staging if a worker crashed

## 6. Historical failures remain visible after server-side is disabled

Symptoms:

- Diagnostics still shows delivery failures while transport is currently off

Explanation:

- failure telemetry is retained for a period of time and represents historical operational data

Typical action:

- confirm no new failures are being added
- purge local diagnostics if you want a clean slate

## Endpoint Test

Diagnostics endpoint test calls:

- admin AJAX `clicutcl_test_endpoint`

Internal path:

- `Dispatcher::health_check()`

Use it to validate:

- endpoint reachability
- adapter-level health behavior

## Debug Windows

Diagnostics can enable a short debug window through:

- `clicutcl_debug_until`

Use debug temporarily when investigating:

- event intake behavior
- dispatch failures
- queue retry issues

## Cleanup

Daily cleanup removes:

- old event log rows from `clicutcl_events`
- old queue rows from `clicutcl_queue`

Retention defaults:

- events: attribution retention days
- queue: 7 days, filterable

## Multisite

When multisite network defaults are configured:

- server-side transport can inherit network settings
- site-level `use_network` controls whether the site overrides them

## Uninstall

Default uninstall behavior:

- remove options
- clear transients
- clear scheduled hooks
- drop plugin tables

Preservation override:

- `clicutcl_preserve_data_on_uninstall`
