# Operations Runbook

- **Audience**: maintainers, support engineers, and reviewers
- **Canonical for**: activation checks, queue behavior, diagnostics, and common failure handling
- **Update when**: operational troubleshooting, queue behavior, diagnostics surfaces, or recovery steps change
- **Source baseline**: plugin code `1.9.0`, commit `a45aa9e`
- **Runtime verification**: structural smoke only; live WordPress/queue/provider operations were not exercised in the 2026-08-19 audit

## Current release boundary

This runbook documents source-observed operations, not a production safety certificate. Before the next runtime
release, test consent withdrawal immediately before queue retry, Woo order-meta and Ultimate Member user-meta purge/export/erase/uninstall,
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
- lock: option row `clicutcl_queue_lock` (not a transient; see [Stuck queue lock](#stuck-queue-lock))

Retries use exponential backoff and stop after max attempts.

If queue rows report `consent_unresolved`, configure the subject-bound
[`clicutcl_queue_consent_snapshot`](../reference/HOOKS-REFERENCE.md#clicutcl_queue_consent_snapshot)
resolver against your durable consent authority. ClickTrail has no built-in subject
registry; cron cookies and historical event grants cannot establish current consent.
Unresolved rows stay pending, retry after five minutes, and retain their attempt
count until the normal retention cleanup. Explicit withdrawal becomes
`consent_denied`; expired or invalid authority stays unresolved. Strict and geo modes
require this lookup independently of the worker's location. Local/development
workers leave queued rows untouched unless the environment override allows sending.

The queue is also used to enrich Diagnostics Woo order-trace lookups with current retry state for a stored `event_name` plus `event_id`.

Woo purchase and milestone sent markers are only written after a successful, skipped, or confirmed queued attempt. If a Woo order trace shows an error with no matching queue row, the order-level sent marker should remain unset and the event can be retried by the original hook path.

### Dead-letter rows and replay

A row that failed five times, got a non-retryable result, or hit an explicit `consent_denied` snapshot is kept with
`status = 'failed'` (dead-letter) instead of being deleted. Diagnostics shows the count as "Failed (dead-letter)".
Row lifecycle and columns: [DATA-MODEL.md](../architecture/DATA-MODEL.md#wp_clicutcl_queue).

To replay:

1. Fix the cause first (endpoint, adapter, collector credentials, consent resolver). A replayed row goes back to
   `failed` after five more retryable failures, or at once on a non-retryable result or an explicit `consent_denied`
   snapshot.
2. Click "Retry Failed Deliveries" in Diagnostics. It calls `Queue::requeue_failed()`, which resets up to 50
   `failed` rows (oldest first) to `pending` with `attempts = 0`, due now. The button is disabled when there are no
   failed rows. Click again for more than 50 rows. From code: `\CLICUTCL\Server_Side\Queue::requeue_failed( $limit )`
   accepts 1 to 500.
3. The next queue run (every 5 minutes) sends them. Rows for an event the destination dedup already marked as sent
   are deleted without a send.

Retention runs on `created_at`, which a replay does not reset: a replayed row older than `clicutcl_queue_retention_days`
(default 7) is deleted by the next daily cleanup if it is still `pending`. Replay older dead-letter rows shortly
before a queue run and confirm they leave the queue. Dead-letter rows are deleted after
`clicutcl_queue_dead_letter_retention_days` (default 30).

### Stuck queue lock

The lock is the option row `clicutcl_queue_lock` (not a transient), holding the unix time the current run took it.
A run that cannot take the lock does nothing. Check it with `wp option get clicutcl_queue_lock`:

- no row: no run holds the lock
- a time less than 120 s old (`Queue::LOCK_TTL`): a run is in progress, or one died very recently; wait
- a time more than 120 s old: the holder died. The next run takes the lock over automatically; no action needed
- a value that is not a unix time (for example `0`): never expires; delete it with
  `wp option delete clicutcl_queue_lock` while no queue run is active

The queue does not run at all while server-side delivery is disabled, and workers in a `local` or `development`
environment leave rows untouched unless `clicutcl_dispatch_in_environment` allows sending. In Diagnostics, "Max Attempts in Queue" and
"Oldest Next Attempt" include dead-letter rows; use "Pending Events" and "Due Now" to judge a backlog.

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
- a lock left by a crashed worker is taken over by the next run after 120 s; see [Stuck queue lock](#stuck-queue-lock)
- replay dead-letter rows once the cause is fixed; see [Dead-letter rows and replay](#dead-letter-rows-and-replay)

## 6. A booking or lead shows an older ad click

Symptoms:

- a CRM record carries `gclid` from an earlier Google visit although the visitor last came from another platform

Checks:

- read `attribution_selected_click_id` (and its reason) on the form entry, order meta or dispatched event; it is the
  newest ad click across platforms (D3). Top-level `gclid`, `fbclid`, ... each hold the newest click per platform
- in the browser, `localStorage['attribution']` holds the envelope whose `data.click_id_history` lists the clicks in
  order (50 newest, browser-only; not returned by `ClickTrail.getData()`)
- for Calendly, `salesforce_uuid=ct1;<key>=<value>` names the click sent: the selected click, or none. The fixed
  Google-first order only applies to attribution saved before 1.10.5, which has no selection

Typical fixes:

- map downstream systems to `attribution_selected_click_id` rather than pairing `gclid` with the last-touch UTMs
- replay the round trip locally with `tools/qa/e2e/` (see its README)

## 7. Historical failures remain visible after server-side is disabled

Symptoms:

- Diagnostics still shows delivery failures while transport is currently off

Explanation:

- failure telemetry is retained for a period of time and represents historical operational data

Typical action:

- confirm no new failures are being added
- purge local diagnostics if you want a clean slate

## 8. HubSpot webhooks fail with `401`

Symptoms:

- the HubSpot app's webhook log shows `401` (`webhook_signature_invalid`, `webhook_timestamp_invalid` or
  `webhook_secret_missing`) and each event is retried

Explanation:

- HubSpot re-sends any `4xx`/`5xx` up to 10 times over 24 hours, so one misconfiguration produces 10 failed
  deliveries per event. ClickTrail keeps `401` for authentication failures rather than acknowledging them
- with `X-HubSpot-Signature-v3` present, ClickTrail checks the signature against `https://` + the `Host` header +
  the raw request path and query, and against the same path on the `home_url()` host, so a proxy that only rewrites
  `Host` still verifies. A proxy or CDN that strips a path prefix, rewrites the path or reorders query parameters
  breaks the signature, and v3 never falls back to v1. `X-Forwarded-*` headers are not read
- `webhook_timestamp_invalid` means `X-HubSpot-Request-Timestamp` is outside the replay window (default 300 s)

Typical fixes:

- paste the HubSpot app's client secret (not an access token) as the HubSpot provider secret
- configure the HubSpot target URL as the exact public HTTPS URL on the `home_url()` host, and have the proxy forward
  the path and query unchanged
- if the proxy must rewrite the path, add the public target URL configured in HubSpot through the
  `clicutcl_hubspot_v3_request_uris` filter (see `HOOKS-REFERENCE.md`)
- check the server clock (NTP) when timestamps are rejected
- non-conversion HubSpot events (deletion, merge, privacy deletion, unlisted types) return `200` with `ignored`;
  those are not failures

## 9. Safari visitors lose attribution after a week (cookie re-issue)

Symptoms:

- leads from Safari or iOS carry no `ct_*` values when the visitor converts days after the first visit, while Chrome visitors keep theirs

Explanation:

- Safari ITP caps cookies written by JavaScript (24 hours after a link-decorated landing, deletion after 7 days without interaction) and purges `localStorage` on the same 7-day rule. ClickTrail restores a missing cookie from the `localStorage` mirror when the mirror survived, and, with **Re-issue the attribution cookie from the server** on (`attribution_cookie_reissue`), sets the same cookie again in the response of `POST /clicutcl/v2/cookie-refresh`, which Safari does not cap
- the setting is on for fresh installs and off for upgraded ones until an admin switches it on

Typical checks:

- Site Health shows "Attribution cookie server re-issue: on/off"
- in the browser network tab, a request to `/wp-json/clicutcl/v2/cookie-refresh` follows the first page view that wrote the cookie and answers 200 with one `Set-Cookie: attribution=...; Max-Age=...`. 401 means the page was cached longer than the client token lives (7 days by default); 403 `consent_required` means the consent cookie is not readable by the server (for example a CMP that keeps consent only in `localStorage`); 403 `cookie_reissue_disabled` means the setting is off
- exclude `/wp-json/clicutcl/v2/*` from page caches, CDN rules and WAF blocks that apply to `POST` requests; the browser retries an unanswered re-issue at most once an hour. If the REST host differs from the page origin (for example `www` versus the apex), the browser skips the request because the cookie would not be sent
- the rate limiter (60 requests a minute per IP, scope `cookie_reissue`) uses the object cache when a persistent one exists and a transient otherwise, so on hosts without a persistent object cache it is best effort
- page responses never carry this cookie; if a `Set-Cookie: attribution` appears on a page GET, another plugin or the server writes it

## Attribution Readiness

Diagnostics attribution readiness calls:

- admin AJAX `clicutcl_attribution_readiness`

Use it with a bounded test JSON payload to inspect UTM field status, recognized click-ID keys/platforms, source evidence, issues, and recommendations. Optional source aliases are request-scoped JSON mappings from recognized platforms to lowercase UTM source tokens of at most 64 characters; they are validated and never stored.

The analyzer can deterministically suggest only `utm_source` when exactly one recognized platform is present. It never invents `utm_medium` or `utm_campaign`. An observed source, unresolved source macro, or multiple platform signals suppresses automatic source suggestion. Referrer evidence remains non-deterministic.

Field output includes `selection_tier` to explain whether the selected value came from `last_touch`, the bare `direct` payload tier, `first_touch`, or no valid tier. `direct` describes key precedence only; it is not a traffic-channel claim. When safe core values exist, the endpoint may also return a copy-only test URL. The builder accepts only a query-free, fragment-free HTTP(S) site URL without userinfo or an explicit port, emits at most the three canonical core UTM keys, and rejects invalid/macro or over-bound output. The admin action uses the Clipboard API only after an explicit click; it does not navigate, rewrite links, persist the URL, or change attribution state.

The response does not include click-ID values and does not prove provider delivery, production runtime behavior, or compliance. An isolated WordPress 6.8.2 / PHP 8.3 / Chrome fixture verified the local admin and browser/PHP M4 contract on 2026-08-23; release and public runtime claims remain separately gated.

## Form-readiness contract

The M5 first slice is a pure comparator and fixture corpus, not an admin tool.
It accepts only field-presence snapshots for `cf7`, `fluent`, `formidable`,
`forminator`, `gravity`, `wpforms`, `elementor`, `ninja`, and `sureforms`. It reports expected/submitted presence and
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
- expired WhatsApp reference rows from `clicutcl_whatsapp_refs`

Retention defaults:

- events: attribution retention days
- queue: 7 days, filterable
- WhatsApp reference codes: attribution retention days (each row carries its own `expires_at`)

## Multisite

Touch-event schema version 4 preserves historical rows and adds logical-event deduplication.
Verify `clicutcl_db_version = 5`, `clicutcl_touch_events_table_ready = 1` and `clicutcl_whatsapp_refs_table_ready = 1` after an upgrade (schema 5 adds `clicutcl_whatsapp_refs`; it is recorded only when that table exists).
Incomplete migrations suppress new touch-ledger writes while leaving transport independent;
inspect database migration errors before using reporting totals. Existing historical duplicates
are not deleted or inferred. The disposable migration check lives at
`tools/qa/touch-events-db.test.php` and must never run against an existing ClickTrail database.

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
