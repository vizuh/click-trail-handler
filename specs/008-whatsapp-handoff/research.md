# Research: WhatsApp Handoff (click event + short reference code)

Source read on 2026-11-02 (`main` @ `805a721`, 1.12.0). Nothing below was run against WhatsApp; every row is source-present / runtime-unverified.

## Current state

| Surface | File | Finding |
|---|---|---|
| WhatsApp listener | `assets/js/clicutcl-attribution.js` `initWhatsAppListener` | Returns early unless `enableWhatsapp` and `whatsappAppendAttribution`. On `click` and `touchstart` (capture) it resolves the anchor, keeps the original in `data-clicutcl-href` / `data-clicutcl-decorated`, and sets `text` to `<text>\n\nCT:<ClickTrail.getEncoded()>`: the base64url of the whole stored attribution object. Allowlist `wa.me`, `whatsapp.com`, `api.whatsapp.com`, `web.whatsapp.com`. `Decorator.restore()` undoes it on withdrawal. |
| Click events | `assets/js/clicutcl-events.js` `trackLeadGenEvents` click handler | `tel:` gives `contact_call_click`, `[data-chat-trigger]` gives `contact_chat_start`, Calendly/Acuity give `book_appointment`. No WhatsApp branch. |
| Event contract | `includes/tracking/class-eventv2.php` `BROWSER_EVENT_NAMES`; `includes/tracking/class-event-translator-v1-to-v2.php:113,143` | The translator already lists `contact_whatsapp_start` among the mid-funnel names; the browser allowlist and `buildCanonicalEvent` lack it. |
| GTM starter kit | `assets/build-starter-kit.py` (two loops), `assets/gtm-starter-kit.json` | `whatsapp_click` trigger and GA4 tag, never pushed by the plugin. |
| Tracking REST | `includes/api/class-tracking-controller.php` | Routes `events/batch`, `attribution-token/sign|verify`, `webhooks/<provider>`, `lifecycle/update`, `diagnostics/*`. Sign route gates: body size, `check_rate_limit`, `Auth::verify_client_token`, `check_token_nonce_limit( 20 )`. Diagnostics routes: `manage_options`. The client token (`eventsToken`) is minted only when transport or the cross-domain token is on. |
| Attribution sanitiser | `includes/api/traits/trait-tracking-controller-attribution-token.php` `sanitize_attribution_token_data` | Allowlist of `ft_*`, `lt_*` and click-ID keys, 128-char cap, last-touch-wins normalisation of the top-level click IDs. Reused as is. |
| Storage patterns | `includes/database/class-installer.php`, `class-touch-events-store.php`, `includes/utils/class-cleanup.php`, `includes/privacy/class-privacy-handler.php`, `uninstall.php` | `DB_VERSION = 4`; dbDelta plus per-table readiness options; daily `LIMIT 1000` delete by `created_at`; email-keyed eraser; uninstall drops tables. |
| Consent gate (server) | `includes/server-side/class-consent.php`, `Dispatcher::consent_allows` | `Consent::is_required()` then the live `ct_consent` / `ct_consent_state` cookie. |
| Diagnostics lookup | `trait-admin-diagnostics-ajax.php` `ajax_lookup_woo_order_trace`, `assets/js/admin-diagnostics.js`, `trait-admin-pages.php` | An existing "Woo Order Trace Lookup" card: `manage_options` + `clicutcl_diag` nonce AJAX, results into a container. |

## R1 — Where the click event is produced

**Decision**: `clicutcl-events.js`, in the existing click handler, before the booking branch. The attribution script stays the only owner of href decoration and of the code; the events script asks it for the confirmed code through `window.ClickTrail.getWhatsAppRef()` (the code belongs to the visitor's current attribution, not to one link).
**Rationale**: `pushEvent` owns the consent gate, identity, canonical mapping and transport; `markOnce` lives on the tracker instance. Both scripts register their click listeners in the capture phase on `document`, so their order depends on when each registers (the attribution one is bound after a consent grant); asking for the code instead of reading it off the anchor makes the event independent of that order. The attribution script also marks decorated anchors with `data-clicutcl-wa-ref` (removed by `Decorator.restore()`) so a link whose code is no longer valid is put back. The events script does not depend on the append setting, so User Story 1 works with the setting off.
**Rejected**: pushing the event from the attribution listener (it only exists when the append setting is on, and it would bypass `pushEvent`).

## R2 — Event shape

**Decision**: `link_url` (WhatsApp URL minus its `text` parameter and fragment), `phone` (digits only, 7 to 15), `contact_type: whatsapp`, `cta_label`, optional `ref_code`. The extra keys travel in `delivery_context` through the existing `extractDeliveryContext`; `funnel_stage: mid`.
**Deviation from the issue text**: the issue lists `link_url` without saying the `text` parameter is removed. It is removed because the prefilled text is site-authored free text that may repeat page titles or offers, and because the reference is already a separate field.
**Rationale**: the number in a `wa.me` link is the business's public number, so the event stays free of visitor PII. Group-invite and `message/<id>` links give an empty `phone`.

## R3 — Reference format

**Decision**: `CT-` + 8 characters of RFC 4648 base32 (`A-Z2-7`), from `crypto.getRandomValues` (`byte & 31`, unbiased because 256 is a multiple of 32). The server accepts 6 to 8 characters, any case, with or without the `CT-` prefix on lookup.
**Rationale**: 40 bits keeps the collision chance about `N / 2^40` (9e-8 at 100 000 live rows) while staying short and typeable. `Ref:` is plain text a human or a bot can regex out of a chat export.

## R4 — Pre-mint, not mint-on-click

**Decision**: request the code when consent is granted and the page holds an allowlisted anchor (and on first pointer/touch/focus on one for links added later), decorate at click time only with a confirmed code. Reuse the confirmed code for the tab session while the snapshot hash is unchanged.
**Rationale**: the issue asks for collision handling (server rejects, client retries once). That only means something if the link has not left yet: with mint-on-click the first click would already have sent a code that maps to someone else. This is the `Store.prepareSignedToken()` pattern and the pre-mint decision in spec 001 R4. The trade-off is that a very fast click leaves the link undecorated, never wrongly decorated.

## R5 — Table, not the touch-events store

**Decision**: new table `{prefix}clicutcl_whatsapp_refs`, `DB_VERSION` 4 to 5, installed and upgraded through `Installer::create_tables()`, with `clicutcl_whatsapp_refs_table_ready` / `_checked_at` options like the other tables.
**Rationale**: the touch-events table is an event ledger keyed by `(blog_id, event_key)` with source/medium/campaign columns only; it has no click-ID columns, no expiry, and no lookup by code. Reusing it would mean overloading `event_id` as the code, shoving a JSON snapshot somewhere it does not fit, and mixing a lookup index with a consent-gated analytics ledger whose retention follows a different rule. A dedicated table gives a unique `(blog_id, code)` key (the collision primitive), an `expires_at` column (TTL per row), and a `visitor_id` index (the eraser).
**Rejected**: transients/options (cannot be queried by visitor for the eraser, no atomic collision detection), the Calendly handoff table of spec 001 (never built; that design was superseded).

## R6 — Collision detection

**Decision**: `INSERT ... ON DUPLICATE KEY UPDATE id = id` on the unique `(blog_id, code)` key; `$wpdb->query()` returns 1 for an insert and 0 for a no-op duplicate, `false` for an error. 0 maps to HTTP 409, `false` or a missing table to 503.
**Rationale**: atomic under concurrency, no read-then-write race, same trick as `Touch_Events_Store::insert()`. An expired row that was not purged yet still blocks its code until cleanup; the client simply retries with another.

## R7 — Auth and gates for the create route

**Decision**: `attribution-token/sign` gates in the same order, plus two: the feature gate (`enable_whatsapp` and `whatsapp_append_attribution` on, else 403) first, and the server-side consent gate after the token (403 when `Consent::is_required()` and no marketing grant in the request's cookie). A 16 KB body cap (the sign route uses 128 KB; this body is a few hundred bytes of allowlisted keys). Rate limit scope `whatsapp_ref` through the existing filterable `check_rate_limit`.
**Rationale**: the route writes a row on a public, token-holding request, so it needs the strictest existing pattern plus consent. Consent is derived from the request cookie, never from the body, like `process_single_event`.

## R8 — Lookup surfaces

**Decision**: REST `GET /clicutcl/v2/whatsapp-ref/<code>` (`manage_options`, same as the diagnostics routes) and a Diagnostics card "WhatsApp Reference Lookup" using a `manage_options` + `clicutcl_diag` nonce AJAX action that calls the same store method. The card is about 60 lines because the Woo trace lookup is a copy-able pattern; results are written with `textContent` into a `<pre>`.

## R9 — Eraser

**Decision**: reference rows hold only a random `visitor_id` and attribution, no email or hash, so an email cannot key them directly. `Privacy_Handler::erase_user_data()` already reads the subject's matched `clicutcl_events` rows (form submissions); their `event_data` carries the `visitor_id` of the browser (via `Attribution_Provider`). The eraser extracts those IDs and deletes the reference rows with `visitor_id IN (...)`.
**Limit, stated plainly**: a visitor who never submitted a logged form has no link from an email to their reference rows; those rows expire with the retention TTL (default 90 days). No exporter is added for this table.

## R10 — Consent withdrawal

**Decision**: browser state is cleared on withdrawal (`Decorator.restore()` removes the ref attribute and the href; the session-stored code is removed). The server row is not deleted on withdrawal: the route has no authenticated subject to delete for, and the row holds no more than the attribution the visitor already consented to share. It expires by TTL or through the eraser. Documented in `SECURITY-PRIVACY.md`.

## Open

- SC-003 (a real WhatsApp compose box showing the `Ref:` line, and the admin lookup resolving it) is not run. WhatsApp Web / mobile behaviour with `text` containing a newline is taken from the previous implementation, which used the same `\n\n` separator.
