# REST API Reference

- **Audience**: contributors, maintainers, integrators, and reviewers
- **Canonical for**: active routes, auth model, diagnostics endpoints, and REST-side constraints
- **Update when**: routes, auth headers, body limits, or intake behavior changes
- **Last verified against version**: `1.8.14` (the `whatsapp-ref` routes: source-present, 1.12.x branch for #145)

Active REST namespace:

- `clicutcl/v2`

Primary controller:

- `includes/api/class-tracking-controller.php`

## Active Routes

## `POST /clicutcl/v2/events/batch`

Purpose:

- receive canonical browser event batches

Used by:

- `assets/js/clicutcl-events.js`

Auth model:

- admin nonce for privileged admin/debug flows, or
- signed client token via `X-Clicutcl-Token` header or `token` in JSON body

Important constraints:

- browser event collection must be enabled
- request body size is capped
- request rate limiting is enforced
- each page token is limited to 20 browser-batch requests by default
- only the browser events emitted by `clicutcl-events.js` are accepted; purchase and lifecycle outcomes use trusted server routes

Allowed canonical browser events:

- behavior: `search`, `view_content`, `scroll_depth`, `key_page_view`, `cta_click`, `contact_call_click`, `contact_chat_start`
- storefront: `view_item`, `view_item_list`, `view_cart`, `add_to_cart`, `remove_from_cart`, `begin_checkout`
- forms and milestones confirmed in the browser: `form_start`, `form_submit_attempt`, `form_submit_failed`, `lead`, `book_appointment`, `login`, `sign_up`, `comment_submit`

`purchase`, `qualified_lead`, and `client_won` are rejected by this route and must come from WooCommerce or lifecycle ingestion.

Notes:

- browser-side REST transport is only configured when browser event collection is enabled and delivery transport is available
- browser events can still push to `window.dataLayer` when collection is enabled even if REST transport is not active

## `POST /clicutcl/v2/attribution-token/sign`

Purpose:

- mint a signed cross-domain attribution token

Used by:

- `assets/js/clicutcl-attribution.js`

Auth model:

- page client token in `X-Clicutcl-Token`
- signed attribution token in the JSON `token` field

## `POST /clicutcl/v2/attribution-token/verify`

Purpose:

- verify an incoming attribution token and normalize the allowed attribution payload

Used by:

- cross-domain attribution continuity flow

## `POST /clicutcl/v2/cookie-refresh`

Purpose:

- answer with an HTTP `Set-Cookie` that re-issues the visitor's own `attribution` cookie. Safari ITP shortens or purges cookies created with `document.cookie`; the same cookie set in an HTTP response is not subject to that cap (`specs/009-itp-cookie-reissue/research.md`). Source-present; the wire shape was checked in Chromium in the E2E harness, Safari behaviour is runtime-unverified.

Used by:

- `assets/js/clicutcl-attribution.js` (`Store.reissueCookie()`), only when the **Re-issue the attribution cookie from the server** setting (`attribution_cookie_reissue`) is on and the script has just written the attribution cookie (pending marker `localStorage['ct_cookie_reissue']`). At most one request per page load, and not again within an hour after a failed attempt. A returning visitor whose cookie is already HTTP-set sends nothing.

Body: `{ token }` (at most 2 KB). The attribution value is never read from the body: the server echoes the `attribution` cookie of this request.

Auth model, in this order:

- feature gate: `attribution_cookie_reissue` and attribution on, else 403 `cookie_reissue_disabled`
- body size: 2 KB, else 413 `payload_too_large`
- per-IP rate limit (scope `cookie_reissue`, filters `clicutcl_v2_rate_limit` / `clicutcl_v2_rate_window`), else 429
- page client token in `X-Clicutcl-Token` (or the JSON `token`), else 401
- server-side consent: when consent is required, marketing consent must be granted in this request's consent cookie (never the body), else 403 `consent_required`
- no token-nonce replay limit: the route stores nothing

Responses: 200 `{ success: true, reissued: true }` with one `Set-Cookie` and `Cache-Control: no-store, private`; 200 `{ success: true, reissued: false, reason }` with no cookie header when the request had no `attribution` cookie (`no_cookie`), the value failed validation (`empty`, `too_large`, `not_json`, `not_object`, `too_many_keys`, `bad_key`, `bad_value`), `cookie_days` is 0 (`retention_disabled`) or output had started (`headers_sent`).

The header: `Set-Cookie: attribution=<same value, encodeURIComponent form>; Max-Age=<cookie_days*86400>; Path=/[; Domain=<cookie scope domain>]; SameSite=Lax[; Secure]`. Never `HttpOnly` (the script reads and expires it), no new field (the value is echoed byte for byte after a strict schema check: JSON object, at most 128 keys, keys `[a-z0-9_]{1,64}`, scalar values, strings at most 256 characters without control characters, encoded size at most 3800 bytes). `Domain` is the same `Cookie_Scope` result the script uses as `cookieDomain`, and is left out when it does not cover the request `Host` (same fallback as the script), so the browser keeps one `attribution` cookie. `Secure` follows `is_ssl()` or an https site URL. The header is appended, never replacing another plugin's `Set-Cookie`.

Caching: this is a `POST`, so page caches do not store it. A cache, CDN rule or security plugin that blocks or caches `POST /wp-json/clicutcl/v2/*` defeats the feature (the script retries after an hour) and must exclude it. No page response ever carries this cookie.

Stays capped: a cookie the script writes after a re-issue (a new touch, a restore) is script-written again until the next re-issue; `ct_visitor_id`, `ct_session_id`, `ct_session` and the localStorage mirror are not re-issued. WebKit also caps cookies in an HTTP response that arrives through third-party CNAME or IP cloaking at 7 days; this route is served by the page's own host, so that does not apply unless only the REST host is fronted by a third-party CNAME.

## `POST /clicutcl/v2/whatsapp-ref`

Purpose:

- register a short WhatsApp reference code (`Ref: CT-<8 base32>`, `A-Z2-7`) with the visitor's attribution snapshot, so a conversation that starts from a pre-filled `wa.me` message can be mapped back

Used by:

- `assets/js/clicutcl-attribution.js` (`WhatsAppRef`), only when WhatsApp tracking and **Append attribution to message** are on

Body: `{ token, code, visitor_id, selected_click_id_key, data }`. `code` is generated by the browser (`crypto.getRandomValues`, exactly 8 characters of `A-Z2-7`); `data` goes through the same allowlist as the signed attribution token (`ft_*`, `lt_*`, click IDs, 128 characters per value).

Auth model, in this order:

- feature gate: `enable_whatsapp` and `whatsapp_append_attribution` on, else 403 `whatsapp_ref_disabled`
- body size: 16 KB, else 413 `payload_too_large`
- per-IP rate limit (scope `whatsapp_ref`, filters `clicutcl_v2_rate_limit` / `clicutcl_v2_rate_window`), else 429
- page client token in `X-Clicutcl-Token` (or the JSON `token`), else 401
- server-side consent: when consent is required, marketing consent must be granted in this request's consent cookie (never the body), else 403 `consent_required`
- per-token-nonce replay limit (default 20), else 429

Responses: 200 `{ success, code, expires_at }`; 400 `invalid_code` / `invalid_attribution_data`; 409 `whatsapp_ref_collision` (the code exists; the stored row is not touched and the client retries once with a new code); 429 `whatsapp_ref_visitor_limit` (the `visitor_id` already holds the maximum of live rows, default 20, filter `clicutcl_whatsapp_ref_max_per_visitor`); 503 `whatsapp_ref_capacity` (the table holds the maximum rows, default 50 000, filter `clicutcl_whatsapp_ref_max_rows`; nothing is stored until a purge frees room) and `whatsapp_ref_unavailable` (table missing).

What triggers a request: A row is created only after a visitor interacts with a WhatsApp link: first pointer-over, focus or pointer-down on the link, a click on it, or (touch screens, where a tap is too short to register in time) the first touch anywhere on a page that holds a WhatsApp link. A page view alone creates nothing; a visitor who never touches or hovers the page, or a page without a WhatsApp link, creates no row. The browser reuses one confirmed code for the tab session while the attribution is unchanged.

Storage: `{prefix}clicutcl_whatsapp_refs`, expiry = attribution retention (`cookie_days`, default 90). See [DATA-MODEL.md](../architecture/DATA-MODEL.md).

## `GET /clicutcl/v2/whatsapp-ref/{code}`

Purpose:

- resolve a reference code to its stored snapshot: `{ success, data: { code, created_at, expires_at, visitor_id, selected_click_id: { key, value }, attribution: { first_touch, last_touch, click_ids } } }`

Auth model:

- `manage_options` through the same permission callback as the diagnostics routes (cookie auth needs the `wp_rest` nonce)

`{code}` is exactly 8 characters of `A-Z2-7`, with or without the `CT-` prefix and in any case. An unknown, malformed or expired code returns 404 `whatsapp_ref_not_found`. The same lookup is available on the Diagnostics page ("WhatsApp Reference Lookup").

## `POST /clicutcl/v2/webhooks/{provider}`

Supported providers:

- `calendly`
- `hubspot`
- `typeform`

Purpose:

- accept external lead-source or form-source events and translate them into the canonical pipeline

Auth model:

- native Typeform `Typeform-Signature` verification
- native HubSpot `X-HubSpot-Signature-v3` verification: base64 HMAC-SHA256 of method + request URL + raw body +
  `X-HubSpot-Request-Timestamp` (milliseconds), with the timestamp checked against the replay window first. The
  signature is checked against a small set of candidate URLs, accepted if any matches: `https://` + `Host` header +
  raw `REQUEST_URI`, then `https://` + the `home_url()` host (and port) + raw `REQUEST_URI` when it differs, then any
  URLs added by `clicutcl_hubspot_v3_request_uris`. HubSpot's listed percent-encodings (`%3A`, `%2F`, `%3F`, `%40`,
  `%21`, `%24`, `%27`, `%28`, `%29`, `%2A`, `%2C`, `%3B`) are decoded in every candidate. `X-Forwarded-*` headers
  are not read. Every candidate still needs the secret, and the replay window and replay cache apply unchanged.
  When the v3 header is present it is authoritative: a failing v3 is rejected and never falls back to v1
- HubSpot v1 `X-HubSpot-Signature` (SHA-256 of secret + body) only when no v3 header is sent. v1 carries no
  timestamp, so only the replay cache and event-ID dedup protect it
- HubSpot v2 signatures (workflow "Send a webhook" actions) are not supported; see `INTEGRATIONS.md`
- native Calendly `Calendly-Webhook-Signature` verification; ClickTrail `X-Clicutcl-Timestamp` / `X-Clicutcl-Signature` relay verification when that header is absent
- provider events that are valid but not conversions (Calendly events other than `invitee.created`; HubSpot
  subscription types off the `clicutcl_hubspot_webhook_event_types` allowlist, such as `contact.deletion`,
  `contact.merge` or `contact.privacyDeletion`, and property changes that fail the qualified-lead rule) return
  `{ "success": true, "ignored": true }` so the provider does not retry
- replay window (`clicutcl_webhook_replay_window`, default 300 s) enforced on provider timestamps for HubSpot v3,
  native Calendly and the ClickTrail relay; HubSpot v1 and Typeform carry no timestamp
- replay cache keyed on the signature for the length of the replay window (`409` on a repeat)
- provider enablement and secret checks

HubSpot webhook arrays are processed item by item. Consent-blocked webhook and lifecycle requests still return HTTP success to prevent retries, but their JSON body reports `success: false`, `skipped: true`, and the skip reason.

Signature, timestamp and secret failures return `401`. HubSpot re-sends any `4xx`/`5xx` up to 10 times over 24
hours, so a wrong or missing HubSpot secret, or a proxy that rewrites the path (or rewrites `Host` to something
other than the `home_url()` host) without a matching `clicutcl_hubspot_v3_request_uris` entry, produces 10 failed
deliveries per event. ClickTrail keeps `401` because accepting unauthenticated requests is worse than retry noise;
see `OPERATIONS-RUNBOOK.md` failure pattern 8.

## `POST /clicutcl/v2/lifecycle/update`

Purpose:

- accept lifecycle updates from backend or CRM systems

Allowed lifecycle stages:

- `lead`
- `book_appointment`
- `qualified_lead`
- `client_won`

Auth model:

- lifecycle token

## `GET /clicutcl/v2/diagnostics/delivery`

Purpose:

- return delivery diagnostics for privileged users

Auth model:

- admin capability check through the controller permission callback

## `GET /clicutcl/v2/diagnostics/dedup`

Purpose:

- inspect dedup diagnostics for privileged users

Auth model:

- admin capability check through the controller permission callback

## Canonical Event Flow

The active REST controller receives canonical events and then routes them into the existing delivery stack:

1. request passes auth and rate-limit checks
2. payload is normalized into `EventV2`
3. consent and identity rules are applied
4. v2 payload is translated into the existing delivery event shape
5. dispatcher sends or queues the event

## Security Controls

Relevant controls exposed by code:

- max batch size
- max request body size (128 KB for most routes, 16 KB for `whatsapp-ref`, 2 KB for `cookie-refresh`)
- request rate limiting
- token nonce replay limits
- trusted proxy resolution
- allowed token hosts
- optional subdomain token acceptance
- webhook replay protection

See also:

- [docs/guides/SECURITY-PRIVACY.md](../guides/SECURITY-PRIVACY.md)
- [docs/architecture/EVENT-PIPELINE.md](../architecture/EVENT-PIPELINE.md)

## Legacy API Status

The legacy v1 log controller (`includes/api/class-log-controller.php`) has been removed from the codebase entirely. `clicutcl/v2` via `Tracking_Controller` is the only registered REST surface.

Legacy API status:

- removed from the repository
- not part of the active GitHub-facing product surface
- would require reintroduction from an earlier tag to bring back
