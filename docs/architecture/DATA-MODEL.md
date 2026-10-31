# Data Model

- **Audience**: contributors, maintainers, and reviewers
- **Canonical for**: option keys, cookies, tables, transients, cron hooks, and persistence surfaces
- **Update when**: stored keys, retention behavior, queue schema, or cookie/storage usage changes
- **Last verified against version**: `1.9.0`

This document summarizes the active storage surfaces used by ClickTrail.

> **Retention boundary:** WooCommerce order metadata has a dedicated lifecycle in
> `includes/privacy/class-woo-order-privacy.php`. It uses WooCommerce CRUD APIs for both classic
> storage and HPOS, and only removes the allowlisted ClickTrail keys/prefixes.

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
- `clicutcl_touch_events_table_ready`
- `clicutcl_touch_events_table_checked_at`
- `clicutcl_db_version` (schema version; `Installer::maybe_upgrade()` re-runs `dbDelta` on existing installs until it matches `Installer::DB_VERSION`)
- `clicutcl_purchase_lock_<order_id>` (short-lived, `autoload = no`, value `<uuid4>|<unix time>`; written by `WooCommerce::push_purchase_event()` only when no persistent object cache is present, deleted by a row-conditional `$wpdb->delete()` on its exact value after `_clicutcl_tracking_sent` is saved, taken over the same way after 30 s if a request died holding it; with a persistent object cache the same key holds the uuid in cache group `clicutcl` with a 30 s TTL instead)

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

- `attribution` (canonical). Browsers can still carry the legacy `ct_attribution` cookie; JavaScript and PHP both read `attribution` first and fall back to `ct_attribution`. The legacy cookie is never rewritten or mirrored into localStorage on read (either would refresh its retention); it is removed the next time ClickTrail saves attribution, which only happens on a new touch with consent

Browser storage behavior:

- attribution is stored in a first-party cookie for server-readable integrations
- localStorage keeps a TTL-bound mirror of the attribution payload for cached or dynamic-page resilience
- the localStorage mirror now carries explicit expiry metadata tied to `cookie_days`
- legacy localStorage copies without expiry metadata are discarded instead of being revived indefinitely
- when consent resolves to denied, both the attribution cookie and the localStorage mirror are cleared
- attribution metadata fields use the same `ft_` / `lt_` key convention as the rest of the schema, and legacy `first_*` / `last_*` aliases are normalized back to the canonical keys on read
- campaign attribution now includes the extended GA-style query fields `utm_id`, `utm_source_platform`, `utm_creative_format`, and `utm_marketing_tactic` under `ft_*` / `lt_*` keys
- a referrer on the current site (or a related subdomain) or on a host listed by `clicutcl_ignored_referrer_hosts` (payment and hosted-checkout hosts such as `paypal.com` and `stripe.com`, subdomains included) is not a touch: it creates no `ft_*` and leaves every `lt_*` field, including `lt_landing_page` and `lt_touch_timestamp`, unchanged. UTMs and click IDs on the URL still form a touch. The list reaches the script as `clicutcl_config.ignoredReferrerHosts`
- top-level click IDs (`gclid`, `fbclid`, ...) mirror the last touch: `lt_<id>` overwrites the top-level copy on read, on save, and in the PHP normalizers; `ft_<id>` fills it only when no last-touch or top-level value exists. Link decoration, the Calendly stamp, the cross-domain token, `ct_page_view`, injected hidden fields (`gclid` / `ct_gclid`), form entry meta (`ct_gclid`), and WooCommerce order meta (`_clicutcl_<id>`) read this mirror. Cookies written before 1.10.5 can still hold the first visit's value at the top level; every reader normalizes on read, so they resolve to the last touch
- click-ID selection (D3, same contract as `clicktrail-js`): each touch that carries ad click IDs (`gclid`, `wbraid`, `gbraid`, `fbclid`, `ttclid`, `msclkid`, `twclid`, `li_fat_id`, `sccid`, `epik`) appends `{k, v, t}` entries to `click_id_history` (50 newest kept) and sets `attribution_selected_click_id` to the newest one, with `attribution_selected_click_id_reason` (`newest_valid` or `newest_valid_superseded_previous`). A touch without a click ID never clears the selection. `click_id_history` lives only inside the browser's localStorage/sessionStorage attribution envelope (it would overflow the 4 KB cookie), expires with that envelope (`cookie_days`), is cleared on consent withdrawal, and is never returned by `ClickTrail.getData()`, pushed to the `dataLayer` or posted with forms; the selected ID and reason ride the cookie, so forms, WooCommerce order meta (`_clicutcl_attribution_selected_click_id`) and the Calendly stamp can use them (with a selection the stamp carries only the selected click, never an older one from another platform; the fixed Google-first order applies only to payloads saved before 1.10.5). Extra captured IDs (`rdt_cid`, `pin_cid`, `snap_cid`, `mc_cid`, `mc_eid`, `dclid`) are stored but never selected
- browser-level identifiers such as `fbc`, `fbp`, `ttp`, `li_gc`, `ga_client_id`, and `ga_session_id` are stored at the top level of the attribution payload when available and permitted by consent

## Custom Database Tables

Created by:

- `includes/database/class-installer.php`

## `wp_clicutcl_events`

Purpose:

- store event log rows for the admin Logs screen
- store form submission event payloads including resolved attribution and, when available, consent-aware identity fields
- a `form_submission` row's `event_data` carries `event_id`, `lead_id` (`lead_` + the event ID without `evt_`; it matches the server-side event and is not the ID of the browser `lead` push), `platform`, `form_id`, `entry_id` (only when the provider has one), `attribution` and the IP-anonymized `identity`; there is one row per `(provider, form_id, entry_id)` (#140, #143)

Columns:

- `id`
- `event_type`
- `event_data`
- `created_at`

## Form Entry Meta

Written by the form adapters at submission time, after `log_submission()`, when consent allows. The first call of an entry writes attribution and IDs; a repeat writes only an ID the entry lacks (repairing a request that died between the dedupe mark and the meta write). `ct_event_id` / `ct_lead_id` are the values `log_submission()` returned, so a CRM sync that copies entry fields can carry an ID that matches the server-side event and the `clicutcl_events` row (#143). They do not match the browser `lead` push, whose ID is generated in the browser.

| Provider | Store | Keys written | Source of IDs |
| --- | --- | --- | --- |
| Gravity Forms | entry meta (`gform_add_meta`), declared via `gform_entry_meta` and restored after an entry edit | `ct_<attribution key>`, `ct_event_id`, `ct_lead_id` | `log_submission()` |
| WPForms | `wpforms()->entry_meta` (only when the entry ID is a real, positive ID) | `ct_<attribution key>`, `ct_event_id`, `ct_lead_id` | `log_submission()` |
| Fluent Forms | `fluentform_submission_meta` (`response_id` = entry ID) | `ct_<attribution key>`, `ct_event_id`, `ct_lead_id` | `log_submission()` |
| Ninja Forms | none | none | `clicutcl_events` row and action `clicutcl_form_submission_logged` |
| Elementor Forms (Pro) | none | none | `clicutcl_events` row and action `clicutcl_form_submission_logged` |
| Contact Form 7 | none | none | `clicutcl_events` row and action `clicutcl_form_submission_logged` |

No ID is written when `log_submission()` returned early (consent denied or empty attribution), and a deduplicated repeat never rewrites an ID the entry already has.

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

## `wp_clicutcl_touch_events`

Added in `1.9.0`. This is a separate, permanent table from `wp_clicutcl_events` above (which
remains the JSON-blob admin Logs / GDPR source) -- it is the structured, queryable touch-event
log the Pro reporting layer will read from. Written by `Dispatcher::dispatch()` (via
`CLICUTCL\Database\Touch_Events_Store`) for every event source -- browser events, WooCommerce
purchases and milestones, form submissions, webhooks, and lifecycle updates -- regardless of
whether server-side delivery is configured or enabled. No free-tier UI reads this table yet.

Consent gating: when marketing consent is required and not granted (per the same
`Dispatcher::consent_allows()` gate used for delivery), the write is skipped entirely rather
than persisting a partial or anonymous row.

Columns:

- `id`
- `blog_id` -- multisite blog ID (`1` on single-site)
- `visitor_id` -- pseudonymous key: the resolved `hashed_email` (SHA-256) when identity is
  available, else the session ID; never a raw email or IP
- `session_id`
- `event_name` -- canonical event name (e.g. `purchase`, `view_item`, `lead`)
- `event_id` -- original logical event ID; nullable for pre-migration or unidentified events
- `event_key` -- SHA-256 of the event-name/ID tuple; unique with `blog_id`
- `funnel_stage` -- `top` / `mid` / `bottom` / `unknown`
- `source_channel`
- `touch_source`, `touch_medium`, `touch_campaign` -- "current touch": last-touch when present,
  else first-touch, derived from whichever attribution shape the source uses (WooCommerce's
  nested `first_touch`/`last_touch`, or the flat `ft_*`/`lt_*` keys browser events and forms use)
- `ft_source`, `ft_medium`, `ft_campaign` -- first-touch, kept alongside the derived "current
  touch" fields for first-touch vs. last-touch reporting
- `order_id`
- `amount`, `currency`
- `created_at` -- the event's own occurrence time (`event_time`/`timestamp`), not insert time

Retention: 90 days by default (same cookie-duration-based retention as `wp_clicutcl_events`),
cleaned up by the existing `clicutcl_daily_cleanup` cron in `includes/utils/class-cleanup.php`.

Schema version 4 adds the nullable identity columns and an atomic unique index. Replays keep
the first row unchanged, independently of server-side delivery and its deduplication TTL.
Different sites, event names, and case-sensitive IDs remain distinct. Existing rows keep null
identity: historical duplicates cannot be reconstructed or removed safely. Unidentified events
remain separate rows. If the schema upgrade is incomplete, new touch writes are skipped until
the columns and complete unique index are verified; event delivery remains independent.

Disposable database verification: `CLICKTRAIL_DISPOSABLE_DB_TEST=1 wp eval-file
tools/qa/touch-events-db.test.php` against a fresh local WordPress installation. The check
exercises fresh install, v3 migration, preserved historical rows, replay, and site/ID isolation.

GDPR: exported and erased by `Privacy_Handler`, matched on `visitor_id = <hashed_email>` (an
exact match against the same hash format `Identity_Resolver` and the queue matcher use).
Rows whose `visitor_id` fell back to a session ID (no identity was ever resolved for that
visitor, e.g. anonymous browsing that never converted) are not reachable by an email-keyed
erasure request -- inherent to hashed-only matching, same limitation the queue eraser already
has.

## WooCommerce Order Meta Surfaces

WooCommerce order-level tracking state now also uses order meta for traceability:

- `_clicutcl_consent`: v1 decision snapshot for new orders (`schema_version`, decision, basis, revision, capture/expiry, source ID, marketing, analytics); historical boolean snapshots normalize as `legacy_unversioned`
- `_clicutcl_tracking_sent`: purchase dedup marker written only after a successful, skipped, or confirmed queued purchase attempt
- `_clicutcl_woo_trace_snapshot`: stored purchase and milestone trace snapshots
- `_clicutcl_woo_milestone_sent_{event_name}`: per-milestone sent markers written only after a successful, skipped, or confirmed queued milestone attempt
- `_clicutcl_{field}`, `_clicutcl_ft_{field}`, `_clicutcl_lt_{field}`: attribution fields, click IDs, browser IDs, visitor/session/trail IDs, and session counters

Lifecycle: `Privacy_Handler` exports and erases matching customer orders by billing email. The daily cleanup
removes metadata from at most 100 expired orders per run and emits `clicutcl_cleanup_batch_incomplete` when
more expired orders remain. The admin tracking purge and uninstall remove the same managed metadata. Uninstall
preserves options, tables, transients, and order metadata when `clicutcl_preserve_data_on_uninstall` is truthy.

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
- these identifiers are created only after marketing consent and are removed again on denial or withdrawal
- the richer session object is not stored in `sessionStorage['ct_session_id']`
- pending attribution is not written to `sessionStorage` while consent is required and unresolved
- the complete key inventory, with recommended CMP categories, lives in [`docs/guides/SECURITY-PRIVACY.md`](../guides/SECURITY-PRIVACY.md#browser-storage-inventory-and-cmp-classification); `tools/qa/cmp-transition.test.js` keeps it in sync

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
- touch event rows retained for the same attribution retention days (default 90)
- queue rows retained according to `clicutcl_queue_retention_days` filter
- Woo order metadata retained according to attribution retention days, in bounded batches

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

All thank-you purchase processing first checks the visitor's current marketing-consent
decision. Denied or unresolved required consent produces no purchase script, order-attribution
read, or sent marker. An order's historical grant cannot authorize this browser output.

## Uninstall Behavior

`uninstall.php`:

- removes plugin options
- clears scheduled hooks
- clears ClickTrail transients
- removes allowlisted ClickTrail Woo order metadata through the WooCommerce CRUD API
- drops the queue, events, and touch events tables by default

Data preservation can be overridden with:

- `clicutcl_preserve_data_on_uninstall`
