# Data Model

- **Audience**: contributors, maintainers, and reviewers
- **Canonical for**: option keys, cookies, tables, transients, cron hooks, and persistence surfaces
- **Update when**: stored keys, retention behavior, queue schema, or cookie/storage usage changes
- **Last verified against version**: `1.9.0`

This document summarizes the active storage surfaces used by ClickTrail.

> **Retention boundary:** WooCommerce order metadata has a dedicated lifecycle in
> `includes/privacy/class-woo-order-privacy.php`. It uses WooCommerce CRUD APIs for both classic
> storage and HPOS, and only removes the allowlisted ClickTrail keys/prefixes. Orders to clean are found
> with one anchored `meta_key REGEXP` meta-query clause (an `EXISTS` clause per key made HPOS join
> `wc_orders_meta` ~107 times and exceed MariaDB's 61-table join limit, #206). `_clicutcl_tracking_sent`
> is the exception: subject erase, retention and the Diagnostics purge keep it; only uninstall removes it.

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
- `clicutcl_whatsapp_refs_table_ready`
- `clicutcl_whatsapp_refs_table_checked_at`
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
- GA4 session fields (`ga_session_id`, `ga_session_number`; source-present / runtime-unverified, #135, parity with `clicktrail-js`): read from the `_ga_<property>` cookies, after consent (`canCapture()`), by `BrowserIdentifiers.parseGaSessionData()`. GS2 `GS2.1.s<id>$o<n>$g<0|1>$t<ts>$j<n>...` is read as `$`-separated tokens after the `GS2.<n>.` prefix (`s<id>`, at least six digits, is the session ID; `o<n>` the session number); GS1 `GS1.1.<id>.<n>...` as dot fields. Any other value, and a malformed field, yields nothing: there is no numeric-token fallback, because a wrong session ID splits the GA4 session and a missing one does not. A cookie without a session ID is skipped. With the `ga4_measurement_id` setting (one or more `G-` IDs, localized as `clicutcl_config.ga4MeasurementId`) only `_ga_<ID without G->` is read, in the configured order, and never another property's cookie; with the setting empty the first `_ga_*` cookie that has a session ID wins and, when several `_ga_*` cookies exist, the script fires one `ct:diagnostic` document event per page load with `code: 'ga_session_property_unpinned'` (cookie count only, no values). `ga_client_id` is parsed from `_ga` (last two numeric parts of `GA1.<n>.<a>.<b>`). When two cookies share a name the last occurrence is kept, unchanged
- the plugin-minted `session_id` / `session_number` (see `Identity`) are not the GA4 session: GA4 session fields live under `ga_session_id` / `ga_session_number`, and no dataLayer push carries a flat `session_id`, `session_number`, `client_id`, `user_id`, `engagement_time_msec`, `timestamp_micros`, `page_location` or `page_referrer` (they are pushed as `ct_<name>`, see [EVENT-PIPELINE.md](EVENT-PIPELINE.md#datalayer-push-shape))

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
| Formidable Forms | `frm_item_metas` row with `field_id` 0 and a serialized array (`FrmEntryMeta::add_entry_meta()`, the shape Formidable uses for its own non-field entry data); not shown in the Formidable entry view | `ct_<attribution key>`, `ct_event_id`, `ct_lead_id` (one row; a repeat adds a row with only the missing IDs) | `log_submission()` |
| Forminator | `frmt_form_entry_meta` through `Forminator_Form_Entry_Model::set_fields()` (only when the entry ID is a real, positive ID) | `ct_<attribution key>`, `ct_event_id`, `ct_lead_id` | `log_submission()` |
| MetForm | `metform-entry` post meta (`update_post_meta`; only when MetForm stored an entry for the form) | `ct_<attribution key>`, `ct_event_id`, `ct_lead_id` | `log_submission()` |
| SureForms | entry `extras` column (`srfm_entries.extras`, an array SureForms owns; ClickTrail merges its keys into it and never replaces a non-array value; no write when the form stores no entry) | `ct_<attribution key>`, `ct_event_id`, `ct_lead_id` | `log_submission()` |
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

## `wp_clicutcl_whatsapp_refs`

Added with schema version 5 (#145, `CLICUTCL\Database\Whatsapp_Ref_Store`). Maps the short `Ref: CT-<code>` a visitor sends in a pre-filled WhatsApp message back to the attribution snapshot. Separate from `clicutcl_touch_events`, which is an event ledger without click-ID columns, expiry or a lookup key (reasons in `specs/008-whatsapp-handoff/research.md` R5). Written only by `POST /clicutcl/v2/whatsapp-ref` (consent-gated, see [REST-API.md](../reference/REST-API.md)).

Columns:

- `id`
- `blog_id` -- multisite blog ID
- `code` -- exactly 8 characters of `A-Z2-7`, without the `CT-` prefix; unique with `blog_id` (`blog_code`), which is what makes a duplicate code detectable atomically
- `visitor_id` -- the browser's `ct_visitor_id` (random, not derived from an email); indexed for the eraser
- `selected_click_id_key`, `selected_click_id` -- the click ID the attribution engine selected (empty when none)
- `snapshot` -- JSON `{ first_touch, last_touch, click_ids }` from the signed-token allowlist
- `created_at`, `expires_at` -- UTC; `expires_at = created_at + cookie_days` (default 90)

Row creation and bounds: A row is created only after a visitor interacts with a WhatsApp link: first pointer-over, focus or pointer-down on the link, a click on it, or (touch screens, where a tap is too short to register in time) the first touch anywhere on a page that holds a WhatsApp link. A page view alone creates nothing; a visitor who never touches or hovers the page, or a page without a WhatsApp link, creates no row. The table is bounded: at most 20 live rows per `visitor_id` and 50 000 rows in total (both filterable, see [HOOKS-REFERENCE.md](../reference/HOOKS-REFERENCE.md)); beyond either the route stores nothing. The table-wide count is cached in the transient `clicutcl_whatsapp_refs_count` (5 minutes; inserts bump it, purges drop it).

Retention and lifecycle: `Cleanup::run_cleanup()` deletes `expires_at < now` and `created_at < now - retention` rows in batches of 1000 (up to 20 per run); lookups ignore expired rows even before the purge; the Diagnostics purge truncates the table; uninstall drops it; `Privacy_Handler` exports and deletes the rows of the `visitor_id` values found in the subject's logged events (one matching rule for both). The daily purge runs before the events-table check, so a missing events table does not stop it. `Installer::DB_VERSION` is 5 and is recorded only when this table exists, like the touch-events table.

## WooCommerce Order Meta Surfaces

WooCommerce order-level tracking state now also uses order meta for traceability:

- `_clicutcl_consent`: v1 decision snapshot for new orders (`schema_version`, decision, basis, revision, capture/expiry, source ID, marketing, analytics); historical boolean snapshots normalize as `legacy_unversioned`
- `_clicutcl_tracking_sent`: purchase dedup marker written only after a successful, skipped, or confirmed queued purchase attempt; holds no personal data, so subject erase, retention and the Diagnostics purge keep it (export lists it, uninstall removes it); the thank-you page emits no purchase for an order older than the retention window
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

## Easy Digital Downloads Order Meta Surfaces

EDD 3.x orders live in `edd_orders` with their own meta table; ClickTrail reads and writes it only through `edd_update_order_meta()`, `edd_get_order_meta()`, `edd_delete_order_meta()` and `edd_get_orders()`. The keys are the WooCommerce ones, so one allowlist (`Woo_Order_Privacy::is_managed_meta_key()`) governs both:

- `_clicutcl_consent`: checkout consent snapshot, written for every customer-request order even when attribution is denied
- `_clicutcl_{field}`, `_clicutcl_ft_{field}`, `_clicutcl_lt_{field}`: attribution fields, click IDs, visitor/session/trail IDs, written at `edd_built_order` only when `Attribution_Provider::should_populate()` allows; a key outside the allowlist is never written, so every stored key can be exported and erased
- `_clicutcl_tracking_sent`: receipt purchase marker, written only after a successful, skipped, or confirmed queued purchase attempt
- `clicutcl_edd_purchase_lock_<order_id>`: the receipt dispatch lock, an option row or object-cache key with the same lifecycle as `clicutcl_purchase_lock_<order_id>` above

No trace snapshot or milestone markers exist for EDD. Lifecycle (`CLICUTCL\Privacy\Edd_Order_Privacy`): the personal-data exporter and eraser match orders by billing email (`edd_get_orders( array( 'email' => ... ) )`), the daily cleanup removes metadata from at most 100 orders created more than the retention window ago and emits `clicutcl_cleanup_batch_incomplete` with scope `edd_order_meta` when more remain, the Diagnostics purge and uninstall (when `edd_get_orders()` exists and `clicutcl_preserve_data_on_uninstall` is not truthy) remove the managed keys from every order. The purchase-sent marker `_clicutcl_tracking_sent` is the exception: it holds no personal data and is kept by subject erase, retention and the Diagnostics purge (only uninstall removes it, and export still lists it), so a receipt visited after cleanup cannot re-push the purchase; the receipt also emits nothing for orders older than the retention window. Other EDD order meta (`_edd_phone`, `tax_rate`, gateway IDs) is never read or deleted. The order query uses one anchored `REGEXP` clause on the meta key, because `WP_Meta_Query` joins the meta table per clause and the Woo query's 107 `EXISTS` clauses exceed MariaDB's 61-table join limit against EDD's tables.

## Registration User Meta Surfaces (Ultimate Member)

A visitor registered through an Ultimate Member registration form gets ClickTrail user meta, written by
`Integrations\Ultimate_Member` from the attribution cookie sent with the registration request (never from `$_POST`)
and only when marketing consent allows (`Attribution_Provider::should_populate()` and, when consent is required,
`Consent::marketing_allowed()`):

- `_clicutcl_{field}` for every key of `Attribution_Provider::get_field_mapping()` present in the cookie payload (`gclid`, `lt_source`, `ft_campaign`, `visitor_id`, `session_id`, `session_count`, ...), each value `sanitize_text_field()`ed and cut to 255 characters; a key outside the mapping is dropped
- `_clicutcl_um_signup_sent`: `yes`, written only after the `sign_up` dispatch succeeded, was skipped (server-side delivery off, duplicate) or was confirmed queued; an unqueued failure leaves it unset so a repeat of the hook can send again

The leading underscore keeps the keys out of core's custom-field UI. Nothing is written for a user added from wp-admin
(`um_registration_complete` carries a `null` form), for a registration without marketing consent, or when no
attribution cookie came with the request (then only the sent marker is written). The password UM passes in `$args` is
never read.

Lifecycle: `Privacy_Handler` registers a separate exporter and eraser, `clicutcl-user-registration`, matched through
`get_user_by( 'email' )` (`User_Registration_Privacy`); both work on the exact allowlist above, so lookalike keys and
all other user meta are untouched, and they also run after Ultimate Member is deactivated. The daily cleanup removes the
meta of at most 100 users per run whose `user_registered` is older than the attribution retention (cookie duration,
default 90 days; the same period that applies to Woo order meta) and emits `clicutcl_cleanup_batch_incomplete` with
scope `registration_user_meta` when more remain. The admin tracking purge and uninstall remove the meta of every user
(uninstall keeps it when `clicutcl_preserve_data_on_uninstall` is truthy). WordPress deletes all of a user's meta with
the user. Ultimate Member's own registration data (`submitted` details, profile fields) is outside this lifecycle.

Because retention is measured from the registration date, attribution on accounts older than the retention period is
not kept; copy it to a CRM or another user field at registration time if it must outlive that.

## Client-Side Storage

Cookies:

- attribution cookie: `attribution`
- consent cookie: default `ct_consent`
- lightweight session ID fallback: `ct_session_id`
- lightweight visitor ID fallback: `ct_visitor_id`
- canonical session state cookie: `ct_session`
- consent decision cookie for the server: `ct_consent_state`

Cookie scope: the `cookie_scope` attribution setting (`host` default for existing installs, `registrable` seeded on new installs, `custom` with `cookie_custom_domain`) resolves in PHP (`Cookie_Scope::resolve()`) to one `cookieDomain`. Every cookie above is written and expired from JavaScript with `; domain=<cookieDomain>` when that value is a suffix of `location.hostname`, and host-only otherwise (public suffixes, IPs, single-label hosts and a non-covering domain always fall back to host-only). PHP readers read `$_COOKIE` by name and are unaffected. `localStorage['ct_cookie_scope']` holds the last applied domain so `clicutcl-attribution.js` can migrate existing cookies once per origin (host-only to domain, domain to host, domain to domain); it is written only next to a domain-scoped cookie or a migration. The `localStorage` mirror of `attribution` stays per origin. Safety: a throwaway `ct_scope_probe` cookie is written and read back before any real cookie gets a `domain` (the public-suffix table is incomplete, so the probe is the real guard; a failing probe means host-only writes); every write expires the same name in the other scope and every removal expires both scopes, in all four scripts; the migration expires the previous copy, writes the new one only if no shared copy is still readable, and restores the old cookie (and skips the marker) if that write fails, so an already shared value wins. The probe is written only when a real cookie write is about to happen, never for removals or an empty/denied boot; write and removal also expire the domain recorded in `ct_cookie_scope`. Limit: `document.cookie` cannot distinguish same-named cookies, and the marker is per origin, so another subdomain's stale copy is cleaned only when that origin writes or removes the cookie.

Server re-issue of the attribution cookie (#141, `specs/009-itp-cookie-reissue/`): `attribution` has two writers. The script writes it with `document.cookie` (`Store.setCookie()`). When the `attribution_cookie_reissue` setting is on (schema default off for existing installs, seeded on for fresh installs), the script then asks `POST /clicutcl/v2/cookie-refresh` and the REST response sets the same cookie again with `Set-Cookie` (`Attribution_Cookie_Reissue`): same name and value, `Max-Age = cookie_days * 86400`, `Path=/`, `SameSite=Lax`, `Secure` on https, not `HttpOnly`, and a `Domain` only when the script reports (`domain_applied` in the request body) that it wrote the cookie with the configured `Cookie_Scope` domain and that domain covers the request `Host` (the script's domain probe can fall back to host-only, which the server cannot see), so there is one cookie, not two. An HTTP-set cookie is not subject to Safari's cap on script-written cookies; any later script write of the cookie (a new touch, a restore) makes it script-written again until the next re-issue. Only the REST `POST` sets it; no page response does. Restore: when the cookie is absent but an unexpired `localStorage['attribution']` mirror exists, `runAttribution()` rewrites the cookie from it (mirror's `expiresAt` as the bound, never past `cookie_days`, without `click_id_history`) after live consent allows capture. `localStorage['ct_cookie_reissue']` is a `{w, t, x?}` marker (script write time, last attempt time, and for a restored cookie the mirror's expiry, so the re-issue cannot extend it) that holds no attribution data; it is removed when the server answers, when consent is withdrawn and with `clearData()`.

Browser storage:

- `sessionStorage['ct_session_id']`
- `localStorage['ct_visitor_id']`
- `localStorage['ct_session']`

Notes:

- `ct_session` stores the structured session object used by the current session manager and server-side readers
- `ct_session_id` and `ct_visitor_id` remain lightweight browser identity fallbacks used by event helpers and compatibility paths
- these identifiers are minted only on consent-gated code paths and are removed again on denial or withdrawal. Attribution runtime (`Identity.sessionId()` / `Identity.visitorId()` in `clicutcl-attribution.js`): created on the first `Identity.get()` call, which only the capture run, the form injector and the API re-announce reach after the `liveConsentAllows()` gate. That gate is true when consent is resolved with marketing granted, or when consent is unresolved and the `requireConsent` setting is off — so with consent not required, the IDs are written without any explicit grant. Events runtime (`ensureIdentity()` in `clicutcl-events.js`): created on the first `pushEvent()`, which requires `ClickTrailConsent.isGranted()` (resolved with marketing granted) regardless of `requireConsent`. Both runtimes write `sessionStorage['ct_session_id']` + cookie (1 day) and `localStorage['ct_visitor_id']` + cookie (365 days), reuse an existing value, and fall back to the cookie when storage throws. Denial or withdrawal removes the storage keys and cookies (`Identity.clear()` in the attribution runtime, `clearIdentity()` in the events runtime on a `ct:consentResolved` without marketing). Source-present / runtime-unverified.
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
