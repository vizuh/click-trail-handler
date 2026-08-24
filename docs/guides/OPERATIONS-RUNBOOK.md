# Operations Runbook

- **Audience**: maintainers, support engineers, and reviewers
- **Canonical for**: activation checks, queue behavior, diagnostics, and common failure handling
- **Update when**: operational troubleshooting, queue behavior, diagnostics surfaces, or recovery steps change
- **Source baseline**: plugin code `1.9.0`, commit `a45aa9e`
- **Runtime verification**: structural smoke only; live WordPress/queue/provider operations were not exercised in the 2026-08-19 audit

## Current release boundary

This runbook documents source-observed operations, not a production safety certificate. Before the next runtime
release, test consent withdrawal immediately before queue retry, Woo order-meta purge/export/erase/uninstall,
provider error responses, and sGTM preview URL rejection. The current queue path and purge scope remain release
gates; do not interpret a visible Diagnostics result or a successful HTTP smoke check as proof of complete
privacy deletion or exactly-once delivery.

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
2. the setup checklist reflects the intended rollout state
3. attribution is captured from a test URL with UTMs
4. a supported form receives attribution fields
5. if WooCommerce is active, a test order stores attribution
6. if sGTM mode is enabled, the Events-tab preview checks can reach the configured loader and collector URLs
7. if server-side delivery is enabled, `Diagnostics > Endpoint Test` succeeds

## Health and Visibility Surfaces

Primary operational surfaces:

- `ClickTrail > Diagnostics`
- `ClickTrail > Logs`
- dashboard widget
- Site Health tests

Diagnostics exposes:

- endpoint test
- conflict scan
- read-only attribution readiness analysis
- settings backup export and restore
- Woo order trace lookup
- queue backlog
- last error
- debug logging state
- recent dispatches
- failure telemetry
- local purge action

Settings also exposes:

- read-only setup checklist
- sGTM preview checks and destination template hints in the Events tab

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

The queue is also used to enrich Diagnostics Woo order-trace lookups with current retry state for a stored `event_name` plus `event_id`.

Woo purchase and milestone sent markers are only written after a successful, skipped, or confirmed queued attempt. If a Woo order trace shows an error with no matching queue row, the order-level sent marker should remain unset and the event can be retried by the original hook path.

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

## Attribution Readiness

Diagnostics attribution readiness calls:

- admin AJAX `clicutcl_attribution_readiness`

Use it with a bounded test JSON payload to inspect UTM field status, recognized click-ID keys/platforms, source evidence, issues, and recommendations. Optional source aliases are request-scoped JSON mappings from recognized platforms to lowercase UTM source tokens of at most 64 characters; they are validated and never stored.

The analyzer can deterministically suggest only `utm_source` when exactly one recognized platform is present. It never invents `utm_medium` or `utm_campaign`. An observed source, unresolved source macro, or multiple platform signals suppresses automatic source suggestion. Referrer evidence remains non-deterministic.

Field output includes `selection_tier` to explain whether the selected value came from `last_touch`, the bare `direct` payload tier, `first_touch`, or no valid tier. `direct` describes key precedence only; it is not a traffic-channel claim. When safe core values exist, the endpoint may also return a copy-only test URL. The builder accepts only a query-free, fragment-free HTTP(S) site URL without userinfo or an explicit port, emits at most the three canonical core UTM keys, and rejects invalid/macro or over-bound output. The admin action uses the Clipboard API only after an explicit click; it does not navigate, rewrite links, persist the URL, or change attribution state.

The response does not include click-ID values and does not prove provider delivery, production runtime behavior, or compliance. An isolated WordPress 6.8.2 / PHP 8.3 / Chrome fixture verified the local admin and browser/PHP M4 contract on 2026-08-23; release and public runtime claims remain separately gated.

## Form-readiness contract

The M5 first slice is a pure comparator and fixture corpus, not an admin tool.
It accepts only field-presence snapshots for `cf7`, `fluent`, `gravity`,
`wpforms`, `elementor`, and `ninja`. It reports expected/submitted presence and
keeps provider record, hook payload, and ClickTrail event evidence separate.

Operational limits:

- no live form definitions, entries, submissions, or provider stores are read
- no form hook, AJAX endpoint, option, table, transient, or correlation ID is added
- report scope `contract_only` means the comparator carries no runtime evidence; passing automated fixtures verifies only the comparator contract
- adapter status stays source-present / runtime-unverified
- pinned form-plugin versions plus hook, record-readback, consent, cache, AJAX,
  and browser staging are required before runtime promotion

## Endpoint Test

Diagnostics endpoint test calls:

- admin AJAX `clicutcl_test_endpoint`

Internal path:

- `Dispatcher::health_check()`

Use it to validate:

- endpoint reachability
- adapter-level health behavior

## Conflict Scan

Diagnostics conflict scan calls:

- admin AJAX `clicutcl_conflict_scan`

It is designed for deterministic local checks such as:

- cache or optimization plugins detected while client fallback is off
- active call tracking scripts detected (informational only; ClickTrail already skips `tel:` link decoration automatically)
- Woo storefront events enabled without WooCommerce
- sGTM mode enabled without a container or loader source
- sGTM mode enabled while Delivery is not using the sGTM adapter
- adapter and destination toggle mismatches
- GTM plus native destination ownership overlap
- delivery enabled without an endpoint URL

## Backup and Restore

Diagnostics backup actions call:

- admin AJAX `clicutcl_export_settings_backup`
- admin AJAX `clicutcl_import_settings_backup`

They cover the five main option stores:

- `clicutcl_attribution_settings`
- `clicutcl_consent_mode`
- `clicutcl_gtm`
- `clicutcl_server_side`
- `clicutcl_tracking_v2`

Restore runs through the same sanitizers used by the live admin save flow instead of raw option writes.

## Woo Order Trace Lookup

Diagnostics Woo lookup calls:

- admin AJAX `clicutcl_lookup_woo_order_trace`

Source paths are present for inspecting:

- stored purchase trace snapshots
- stored `order_paid`, `order_refunded`, and `order_cancelled` milestone traces
- queue retry status for matching event IDs

This lookup remains runtime-unverified and can expose identity-bearing trace
content to authorized administrators. Do not treat its structural smoke check as
privacy-lifecycle or HPOS proof.

## Woo conversion-readiness contract

M6-A adds a pure source contract, not an operational endpoint. Sixteen synthetic
scenarios cover granted/denied consent, reload markers, paid transitions,
partial/full refunds, currency/value edges, dedup, classic/HPOS, trace privacy,
invalid input, and queued replay after withdrawal.

The report scope is always `contract_only`. No real order should be supplied to
it. M6-B staging must use isolated synthetic orders and allowlisted evidence;
it must not emit customer data, raw order/refund IDs, IP/user-agent values, or
provider payloads.

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
