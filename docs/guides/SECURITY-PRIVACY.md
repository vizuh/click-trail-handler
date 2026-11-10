# Security and Privacy

- **Audience**: contributors, maintainers, reviewers, and security-focused integrators
- **Canonical for**: consent behavior, token handling, replay protection, and secret treatment
- **Update when**: consent flow, auth, signing, secret storage, or privacy behavior changes
- **Historical audit baseline**: plugin code `1.9.0`, commit `a45aa9e`, reviewed 2026-08-19
- **Current release**: plugin code `1.13.1` on GitHub and WordPress.org (1.13.1: audit fixes for consent source, delivery resilience, settings save and privacy; see `changelog.txt`). 1.13.0 added new integrations (Easy Digital Downloads order attribution and purchase event, Ultimate Member registration attribution, Formidable Forms, Forminator, SureForms and MetForm adapters), the WhatsApp click event with reference code (still off by default), Safari ITP attribution-cookie re-issue, the cookie scope setting, GA4 GS2 session-cookie parsing, ClickTrail dataLayer fields renamed to `ct_*` (behaviour change for GTM variables), security hardening (rightmost-untrusted client IP, WhatsApp reference flood bounds, sGTM probe SSRF guard) and privacy fixes (exact-match exporter/eraser, WooCommerce HPOS eraser, no purchase pushed for orders older than retention). Evidence for 1.13.0. CI-gated (`.github/workflows/`, every pull request to and push on `main`): PHPUnit `tests/unit` on PHP 8.1, 8.2 and 8.3 (including `tests/queue-processing-check.php` through `QueueProcessingTest`), and on PHP 8.3 the Node checks `tools/qa/smoke.js` (which also runs `form-runtime-evidence.js`), `npm run test:consent` (`consent-bridge`, `cmp-transition`, `hubspot-form-capture`, `form-lead-dedupe`, `whatsapp-handoff`), `npm run test:attribution` (`payment-referrer`, `gtm-starter-kit`, `ga4-session`, `fill-marker`, `cookie-scope`, `cookie-reissue`, `whatsapp-handoff`) and `tools/admin-css/check-scope.js`; `php -l`, PHPCS and PHPCompatibilityWP 8.1+; and one real-WordPress E2E job, `tools/qa/e2e/ci.sh` (attribution round trip with Contact Form 7, a signed Calendly webhook and the both-cookies case). Not in CI, run by hand: every other `tools/qa/e2e/` harness (`adapters-ci.sh`, `edd-ci.sh`, `um-ci.sh`, `woo-privacy-ci.sh`, `cookie-scope.sh`, `cookie-reissue.sh`), `tools/qa/touch-events-db.test.php`, `tools/qa/repeat-visit-scenarios.js` and `tools/qa/check-typing-policy.php`; committed run evidence is in `tools/qa/e2e/EVIDENCE-*.md`. Manual real-WordPress runs (`tools/qa/e2e/`, WordPress 7.1, PHP 8.3) with committed evidence, all against code before the 1.13.0 tag: Contact Form 7, Fluent Forms and WPForms Lite (`EVIDENCE-2026-10-31.md`, 1.11.2 and a later `main` rerun), MetForm and cookie re-issue (the 2026-11-02 sections of that file, feature branches), Easy Digital Downloads, Formidable, Forminator, SureForms and Ultimate Member (`EVIDENCE-2026-11-02-*.md`, 1.12.0 plus each feature branch). Reported as run but without a committed evidence file: WooCommerce privacy (`woo-privacy-ci.sh`) and cookie scope (`cookie-scope.sh`). WhatsApp has no `tools/qa/e2e/` harness; its coverage is the CI Node test `whatsapp-handoff.test.js` and PHPUnit. NOT run: Gravity Forms, Elementor Pro, Ninja Forms, Pro form-plugin editions, Safari/iOS, Stripe/PayPal/Square in Easy Digital Downloads, real CDN/optimizer plugins and a real GTM import. Previously, 1.12.0 shipped with automated (PHPUnit/Node) consent, queue, WooCommerce privacy, CF7 outcome, touch-event deduplication, CMP decision-transition, Calendly webhook and HubSpot webhook signature coverage and the attribution round-trip E2E in CI (`tools/qa/e2e/ci.sh`), plus a throwaway-WordPress run of CF7, Fluent Forms and WPForms Lite (`tools/qa/e2e/EVIDENCE-2026-10-31.md`); the browser storage inventory is below
- **Runtime verification**: PHP/WordPress/browser/CMP/provider E2E remains incomplete; live behavior is not implied by automated fixtures

ClickTrail is designed to capture attribution and events without treating privacy and delivery as separate concerns.
This document separates policy intent, automated contract coverage, and verified runtime behavior. Version 1.10.0
closes the queued-retry consent check and WooCommerce order-meta lifecycle implementation boundaries; browser,
WordPress, CMP, provider, and staging evidence remain release gates. See the [release-phasing plan](RELEASE-PHASING-AND-INTEGRATION-DOCS.md)
and [integration evidence ledger](../reference/integration-capabilities.json).

## Current security-status blockers

These are not claims that the behavior is fixed. They are release gates for the next runtime changes:

- The browser bridge stores a versioned canonical decision in `localStorage`, mirrors it to
  `ct_consent_state` with its authority timestamp, and treats legacy plugin-cookie and plugin-banner values
  as fallbacks only. A canonical withdrawal clears the plugin cookie and cannot be resurrected by a stale copy
  on reload.
- Same-tab listeners receive `ct:consentResolved`; other tabs receive the canonical decision through the
  browser `storage` event. Browser/CMP and queue/retry coverage below remains a focused VM boundary test,
  not a full browser or WordPress E2E proof.
- Thank-you purchase processing now checks current visitor consent before reading stored attribution or emitting `dataLayer`; denial/unknown and grant entry paths have focused PHP tests. Form/Woo posted attribution and full browser/CMP revocation still need live verification. Strict/geo queued retries require a durable subject-consent resolver; missing authority defers without sending or consuming transport attempts.
- Woo traces can retain identity metadata; ClickTrail now covers its Woo order-meta lifecycle through an explicit allowlist and automated contract tests, subject to live Woo runtime verification.
- Browser conversion tokens and external form messages do not prove a real action or provider confirmation.
- Webhook identity/timestamp/replay semantics still require hardening. The sGTM preview probe validates the admin-posted loader and collector URLs (http/https, `wp_http_validate_url()`) and fetches them with `wp_safe_remote_request()` (unsafe URLs rejected, 2 redirects); that is unit-tested and runtime-unverified.

Until these gates pass in PHP/WordPress/browser/provider tests, use `source-present / runtime-unverified`,
not `privacy compliant`, `secure`, `guaranteed`, or `production-ready`.

## Consent Model

Consent settings are managed through `clicutcl_consent_mode`.

Supported behavior modes:

- `strict`
- `relaxed`
- `geo`

Current source behavior (audit status):

- server snapshot capture and `Dispatcher` share one policy requirement helper; when consent mode is disabled, both treat consent as not required rather than reading the legacy hidden gate
- when consent mode is enabled, the runtime asks `Consent_Mode_Settings` whether the current request requires consent
- when consent mode is enabled, the `gtag('consent','default', …)` snippet prints on `wp_head` at `Consent_Mode::HEAD_PRIORITY` (0) and the GTM bootstrap at `Web_Tag::HEAD_PRIORITY` (derived as consent + 1), so the default is set before `gtm.js` loads regardless of module registration order (#147; structural + rendered-order PHPUnit test, source-present / runtime-unverified in a real WordPress)
- new Woo orders store a v1 decision snapshot; historical boolean order snapshots remain readable and are explicitly labeled `legacy_unversioned`
- Woo order events (milestones, refunds, cancellations, server `purchase`) in wp-admin, REST, cron and WP-CLI ignore the request's consent cookie: the order's `_clicutcl_consent` snapshot decides dispatch and identity, geo mode counts as required, and a missing or malformed snapshot sends nothing when consent is required. A front-end request without a live consent state (for example a `?wc-api=` gateway callback) also follows a stored snapshot. The buyer's own request with a live state keeps it, so a withdrawal there wins. Limits: a buyer's later withdrawal is not visible to a request without their cookie (the stored checkout grant stands), and a cookieless front-end request for an order without a snapshot follows that request's own consent policy, including its geography in geo mode. Details: `docs/reference/INTEGRATIONS.md`
- frontend attribution consumes the consent bridge as its primary runtime contract, but stale-cookie/CMP precedence
  and cross-tab synchronization remain runtime test requirements
- consent resolution is normalized through `ct:consentResolved`, with compatibility events still emitted for older listeners
- when consent resolves to denied, local attribution storage is cleared; prior `dataLayer` entries, durable rows,
  order metadata, queued deliveries, and third-party deliveries are not automatically erased
- most browser event paths check consent before pushing tracked events, but follow-up logger, form, and Woo paths
  require independent verification
- follow-up event cookies (`ct_event_login`, `ct_event_signup`, `ct_event_comment`; 60 seconds, `HttpOnly`, `SameSite=Lax`)
  carry `base64url(JSON).HMAC-SHA256` keyed with `wp_salt( 'auth' )`. The next page prints the event only when the signature
  verifies and the event is the one its cookie owns (`login`, `sign_up`, `comment_submit`); an unsigned, tampered or
  mismatched cookie prints nothing and is still expired. A page that prints a queued event sends `nocache_headers()` and
  defines `DONOTCACHEPAGE`, so a page cache or CDN does not replay one visitor's event to others. A signed value has no
  expiry of its own: a visitor can replay their own captured cookie to their own browser
- initial server-side dispatch checks consent; queued retries resolve current subject consent through `clicutcl_queue_consent_snapshot`, never worker cookies. No built-in subject registry exists: strict/geo retries remain pending without authoritative resolution, bounded by queue retention. Explicit withdrawal blocks delivery; unknown or expired authority defers without consuming attempts.

## Consent Sources

Supported consent sources:

- `auto`
- `plugin`
- `cookiebot`
- `onetrust`
- `complianz`
- `gtm`
- `custom`

When the plugin is the source, ClickTrail can render its own lightweight consent banner.

The Cookiebot bridge stays subscribed to `CookiebotOnConsentReady`, `CookiebotOnAccept`, and
`CookiebotOnDecline`, so an in-page change or withdrawal (Cookiebot's renew dialog) clears ClickTrail
storage without a reload. The same withdrawal removes the parameters ClickTrail added from every link it decorated, including the
`Ref: CT-...` reference on WhatsApp links (later site edits to those links are kept) and restores the `data-url` of inline Calendly widgets; an inline iframe or
popup that already loaded keeps the URL it loaded with. The same holds for an embed iframe whose URL came from
`ClickTrail.decorateEmbedUrl()`; after withdrawal that function returns URLs unchanged. It clears every `ct_*` hidden field and plain-name fields
(`gclid`, `utm_source`, ...) only when ClickTrail filled them. `ct_*` inputs are ClickTrail's namespace: while
consent allows, they are refreshed from current attribution even when `injectOverwrite` is off. After withdrawal, form listeners refill nothing and
mint no visitor or session IDs. Re-granting on the same page decorates and fills again from current attribution.

The click-ID audit trail (`click_id_history`, D3) holds up to 50 `{platform key, click ID, timestamp}` entries in
the existing localStorage/sessionStorage attribution envelope, never in the cookie. It shares the envelope's
`cookie_days` retention, is cleared on withdrawal, and is not exposed through `ClickTrail.getData()`, the
`dataLayer` or form posts. Only `attribution_selected_click_id` and its reason reach the cookie and the server. The Complianz bridge reads `cmplz_has_consent()` (or the accepted
`event.detail.categories`) on `cmplz_fire_categories` and `cmplz_status_change`.

## Browser Storage Inventory and CMP Classification

With consent required, ClickTrail writes nothing before marketing consent is granted. After a
refusal, only the consent decision itself remains. A visitor who navigates away from the landing page
before answering the banner therefore loses the pre-consent click ID; this is intentional.

Some CMPs delete keys their scanner lists as "unclassified" at the moment the visitor answers the
banner (observed with Cookiebot). ClickTrail writes attribution after the first decision event, so the
landing-page click survives that wipe. A later renewal that repeats the same choice does not rewrite
attribution, so unclassified keys wiped at that point are lost: classify the keys below in your CMP.
Do not classify attribution keys as "Necessary".

`tools/qa/cmp-transition.test.js` runs the consent bridge, attribution, and events scripts and fails
when they write a key that is missing from this table. The built-in banner's `ct_consent` cookie
(`clicutcl-consent.js`) is listed manually.

<!-- storage-inventory:start -->
| Key | Medium | Written by | Purpose | Lifetime | Before consent | CMP category | Scope |
|---|---|---|---|---|---|---|---|
| `attribution` | cookie (JS and server re-issue), local, session | `clicutcl-attribution.js`; server re-issue: `Attribution_Cookie_Reissue` on `POST /clicutcl/v2/cookie-refresh` (setting `attribution_cookie_reissue`) | First/last-touch UTMs, click IDs, landing page, referrer (session copy only when the cookie is too large). The server re-issue echoes the visitor's own cookie value and adds nothing; it runs only with marketing consent when consent is required | cookie duration setting (default 90 days) | no | Marketing | per `cookie_scope` (host-only or `domain=<cookieDomain>`) |
| `ct_session` | cookie, local | `clicutcl-attribution.js` | Session number and timing | 1 day | no | Statistics | per `cookie_scope` (host-only or `domain=<cookieDomain>`) |
| `ct_session_id` | cookie, session | `clicutcl-attribution.js`, `clicutcl-events.js` | Pseudonymous session ID | 1 day / tab | no | Statistics | per `cookie_scope` (host-only or `domain=<cookieDomain>`) |
| `ct_visitor_id` | cookie, local | `clicutcl-attribution.js`, `clicutcl-events.js` | Pseudonymous visitor ID | 365 days | no | Marketing | per `cookie_scope` (host-only or `domain=<cookieDomain>`) |
| `ct_pending_v1` | session | `clicutcl-attribution.js` | Landing-page signals promoted on the next page (only when consent is not required or already granted) | tab | no | Marketing | origin (browser storage) |
| `ct_thankyou_lead_<path>` | session | `clicutcl-events.js` | Deduplicates a thank-you-page lead | tab | no | Statistics | origin (browser storage) |
| `ct_wa_ref` | session | `clicutcl-attribution.js` | Server-confirmed WhatsApp reference code and a hash of the attribution it maps to (only with WhatsApp tracking and Append attribution on) | tab | no | Marketing | origin (browser storage) |
| `ct_consent_state` | cookie | `clicutcl-consent-bridge.js` | Consent decision read by the server | 365 days | decision only | Necessary | per `cookie_scope` (host-only or `domain=<cookieDomain>`) |
| `ct_consent_v1` | local | `clicutcl-consent-bridge.js` | Consent decision shared across tabs | until changed | decision only | Necessary | origin (browser storage) |
| `ct_consent` | cookie | `clicutcl-consent.js` | Built-in banner decision (plugin source only) | 365 days | decision only | Necessary | per `cookie_scope` (host-only or `domain=<cookieDomain>`) |
| `ct_cookie_reissue` | local | `clicutcl-attribution.js` | Marker that the script wrote the attribution cookie and the server has not yet re-issued it (`{w, t, x?}`: write time, last attempt time, mirror expiry of a restored cookie; no attribution data). Written only with the re-issue setting on and marketing consent live; removed when the server answers, on consent withdrawal and with the cookie | until the server answers or consent is withdrawn | no | Marketing | origin (browser storage) |
| `ct_cookie_scope` | local | `clicutcl-attribution.js`, `clicutcl-consent-bridge.js`, `clicutcl-consent.js` | Last cookie domain the plugin applied (empty means host-only), so a scope change can expire the previous cookies. Written only next to a domain-scoped cookie or a migration. | until changed | no (written with the first domain-scoped cookie) | Necessary | origin (browser storage) |
<!-- storage-inventory:end -->

Server re-issue (#141): Safari ITP caps cookies created with `document.cookie` (24 hours after a link-decorated landing, deletion after 7 days without interaction; quotes in `specs/009-itp-cookie-reissue/research.md`). With `attribution_cookie_reissue` on, the browser asks the server once after it writes the attribution cookie and the response sets the same cookie as an HTTP-set cookie with the configured retention. It is a REST `POST`, never a page response (a page that sets a cookie is not cached by CDNs), only when marketing consent is granted in the request's own consent cookie, with the same value and no added field. Retention is `cookie_days` from each re-issue, and a re-issue only follows a script write of the cookie, so it does not extend retention beyond what the script itself would have given. Consent withdrawal expires the cookie from the script as before (the cookie is deliberately not `HttpOnly`). Site owners should list this behaviour with the `attribution` cookie in their cookie policy: it is the same cookie, now set by the server as well as the script.

Transient `ct_scope_probe` cookie: when a real cookie write is about to use a `domain` attribute, the scripts write `ct_scope_probe` with that domain, read it back and expire it within the same synchronous call to learn whether the browser accepts the domain (public suffixes are refused). It is never persisted, never carries data, and is not written on removals, on a denied or empty boot, or when `cookie_scope` is `host`.

CMP notes:

- **Cookiebot**: declare each key in the Cookiebot manager with the category above (cookies and
  HTML local/session storage are listed separately). Unclassified keys may be deleted when the visitor
  answers or renews consent.
- **OneTrust**: assign the keys to the matching cookie categories (Targeting for Marketing,
  Performance for Statistics) and keep ClickTrail's consent source set to `onetrust`.
- **Complianz**: add the keys under Marketing / Statistics in the cookie scan so the cookie policy lists them.

## Geo Consent Resolution

Region-scoped consent (`geo` mode) must resolve the request's country. Client-supplied
geo headers (`CF-IPCOUNTRY`, `X-Country-Code`, `GeoIP-*`) are spoofable unless a trusted
edge sets them, so they are **not trusted by default** — an unknown country fails safe to
requiring consent.

Resolution order:

1. `clicutcl_request_country_code` filter — authoritative server-resolved country (recommended; e.g. a GeoIP provider). Return a 2-letter ISO code.
2. Request headers — only when `clicutcl_trust_geo_request_headers` is filtered to `true` (opt in when behind a trusted CDN that overwrites these headers).
3. Otherwise unknown → consent required.

## Data Minimization

Current design goals:

- preserve attribution fields needed for marketing and reporting
- avoid storing unnecessary identity data by default
- omit identity data when consent logic requires it
- keep remote failure telemetry aggregated and payload-free
- anonymize the visitor IP at rest in the diagnostic events log via `wp_privacy_anonymize_ip()`; some WooCommerce
  trace snapshots can still copy raw IP/user-agent identity data and require separate retention/erasure handling

Identity exposure is additionally filterable through:

- `clicutcl_identity_fields_allowed`
- `clicutcl_identity_default_country`

`Identity_Resolver` (`includes/tracking/class-identity-resolver.php`) hashes only normalised values. Email is
trimmed and lowercased. Phone is normalised to E.164 before SHA-256 (#144): everything but digits and a leading
`+` is stripped, a `00` prefix becomes `+`, and a number with no country code is prefixed with the default
country's calling code (`clicutcl_identity_default_country` filter, else the WooCommerce base country, else the
billing country on the order). A number that cannot be resolved to `^\+[1-9][0-9]{6,14}$` is dropped from the
identity payload (never hashed as-is) and counted per request in `Identity_Resolver::get_drop_counts()`
(`phone_no_country`, `phone_invalid`); the v2 intake debug buffer records those reasons as `identity_dropped`
and the Diagnostics "Identity Keys" column shows them as `dropped: …`. Drops are not delivery failures and do
not touch the Dispatcher failure telemetry. The calling-code table is the ISO 3166-1 alpha-2 list in
`includes/tracking/class-phone-calling-codes.php`; national trunk prefixes (for example a leading `0` in UK or
German numbers) are not stripped. Source-present / runtime-unverified in a live WordPress.

HubSpot embedded form capture (`assets/js/clicutcl-events.js`) reads only identifiers: `formId` and `instanceId`
from HubSpot's v4 event, the conversion ID from `getConversionId()`, and the form `id` from a legacy
`hsFormCallback` message. It never calls `getFieldValue()` / `getFormFieldValues()` and never reads the legacy
message's `data` (which carries `submissionValues`), so no submitted field value or PII reaches `dataLayer` or the
events route. It writes no browser storage (the dedupe markers are in memory) and pushes only through the
`pushEvent()` consent gate; listeners registered before consent drop everything until the tracker starts.
Legacy messages pass the external-message origin rule (same host or `clicutcl_iframe_origin_allowlist`, which
now includes HubSpot's form host `hsforms.net` by default); the exact origin of iframe-rendered legacy embeds is
not verified.

The personal-data eraser currently targets matching rows from the events table, the structured touch
events table (`clicutcl_touch_events`, matched on the exact hashed-email `visitor_id`), and the server-side
delivery queue (`clicutcl_queue`, matched on raw and SHA-256-hashed email). This is not complete erasure proof:
legacy event matching does not cover every stored hashed identity shape. Woo order metadata now has an
allowlisted export/erase/uninstall lifecycle, but live Woo runtime verification remains open.

Events-table matching is exact. The SQL `LIKE` over `event_data` is only a prefilter, so every candidate row is decoded
and compared in PHP before it is exported, before its ID is collected for deletion and before its `visitor_id` is used for
the WhatsApp reference rows: a value equal to the requested email (whole value, case-insensitive; `b@x.com` does not match
`bob@x.com`), a `hashed_email` field equal to the SHA-256 of the email (form submissions store `identity.hashed_email`: plain SHA-256 of the trimmed, lower-cased address, no salt, `Identity_Resolver::resolve()`), a `user_id` field equal to the user's ID (`5` does not match `55`), or a `user_hash` field equal to the user's
salted hash. Paging is unchanged: the exporter pages over the candidate query and reports `done` from the fetched page, and
the eraser scans candidates by `id` cursor, skipping look-alike rows, until it has a page of exact matches or reaches the end of the table.

Ultimate Member registration attribution (`_clicutcl_*` user meta, see [DATA-MODEL.md](../architecture/DATA-MODEL.md))
is written only with marketing consent, from the attribution cookie sent with the registration request; no `$_POST`
value and no password is read. It has its own exporter and eraser (`clicutcl-user-registration`, matched by the WordPress
user with the requested email), which touch only the exact keys ClickTrail writes and also work after Ultimate Member is
deactivated, a retention purge by registration date (the attribution retention, default 90 days), a purge-all in the
Diagnostics purge and at uninstall, and deletion with the user. The dispatched `sign_up` event and its touch-event row
fall under the touch-event and queue matching above (hashed email). Ultimate Member's own registration data is
UM's exporter and eraser to handle; this lifecycle has not been run against a consent-required site with a real CMP.

## WhatsApp Reference Codes

With WhatsApp tracking and **Append attribution to message** on (off by default) and marketing consent granted, a visitor who clicks a WhatsApp link sends `Ref: CT-<8 chars>` in the chat. The server holds one row per code in `wp_clicutcl_whatsapp_refs`:

- stored: the code, the random `ct_visitor_id`, first/last-touch UTMs and click IDs from the signed-token allowlist, the selected click ID, creation and expiry time. Not stored: IP address, user agent, email, phone number, the WhatsApp number of the visitor (ClickTrail never sees it) or any page content
- written only by a request that passes the client token, rate limit, size cap and the **server-side consent check** (the request's consent cookie, never the body); never written without attribution. A row is created only after a visitor interacts with a WhatsApp link: first pointer-over, focus or pointer-down on the link, a click on it, or (touch screens, where a tap is too short to register in time) the first touch anywhere on a page that holds a WhatsApp link. A page view alone creates nothing; a visitor who never touches or hovers the page, or a page without a WhatsApp link, creates no row.
- bounded, because a public route writes here and the `visitor_id` is client-chosen: at most 100 creations per client IP per 24 hours (`clicutcl_whatsapp_ref_max_per_ip`, 429 `whatsapp_ref_ip_limit`, checked before any database write), at most 20 creations per (`visitor_id`, client IP) pair per 24 hours (`clicutcl_whatsapp_ref_max_per_visitor`; an empty `visitor_id` is keyed on the IP alone, so anonymous requests of one address cannot use up the allowance of every other anonymous visitor), and 50 000 rows in the table (`clicutcl_whatsapp_ref_max_rows`). At the row ceiling, expired rows are purged first and, if the table is still full, the rows are re-counted (the cached count can be stale-high) and only the oldest rows needed are evicted (at most 500 per request), so a flood cannot hold the feature down for the whole retention period; 503 `whatsapp_ref_capacity` is returned only when that eviction fails. The two per-client counters are transients (the object cache when there is one) keyed on a keyed hash of the IP, so the IP is never stored: not in the table and not in a key. They are fixed 24-hour windows opened by the first creation and updated without a lock, so concurrent requests can overshoot slightly
- retention: the attribution retention setting (`cookie_days`, default 90 days); expired rows answer 404 immediately and are deleted by the daily cleanup in batches (and rows older than a shortened retention), by **Purge Tracking Data**, and at uninstall unless `clicutcl_preserve_data_on_uninstall`
- read: only by administrators (`manage_options`) through `GET /clicutcl/v2/whatsapp-ref/<code>` or the Diagnostics lookup box
- consent withdrawal clears the browser side (link restored, `ct_wa_ref` removed); the server row is not deleted at that moment, it expires with the TTL or goes through the eraser
- export and eraser: rows carry no email, so `Privacy_Handler` finds them through the `visitor_id` values recorded in the subject's logged `clicutcl_events` rows, with one matching rule for both. The exporter returns code, created and expiry time and the stored attribution snapshot; the eraser deletes the rows. A visitor who never submitted a logged form cannot be reached by an email request; their rows expire by TTL

## Client Token Security

Browser event intake uses signed client tokens.

Relevant controls:

- token TTL
- token replay limit per (token nonce, client IP, session id), fixed window of at most one hour (default 120 browser events, 30 sign/WhatsApp-ref requests; `0` in settings = default); gate rejections (`nonce_replay_limited`, `rate_limited`, `token_expired`, `invalid_signature`) are counted in failure telemetry
- allowed token hosts
- optional subdomain token acceptance

Browser event intake also enforces:

- request size limits
- rate limits
- nonce replay controls

## Cross-Domain Attribution Tokens

Routes:

- `/clicutcl/v2/attribution-token/sign`
- `/clicutcl/v2/attribution-token/verify`

Security properties:

- signed payload
- allowed-host checks
- subdomain acceptance is filterable
- attribution payload is normalized before use
- both `/sign` and `/verify` require the page-embedded signed client token (verify is only meaningful on the install that signed the token)

## Embed URL Handoff

`window.ClickTrail.decorateEmbedUrl(url)` adds last-touch `utm_*` and one click ID (the D3 selection) to a URL the
site's own script loads in an iframe. It sends nothing unless:

- the host is listed in `embed_allowed_domains` (empty by default; exact host match, so a third-party widget on a
  sibling subdomain must be listed explicitly) or added through `clicutcl_embed_allowed_domains`
- the URL is https, and carries no signature parameter while `link_skip_signed` is on (default)
- live marketing consent allows it (same rule as link decoration)

No visitor ID, session ID, click history or cross-domain token is added. The widget vendor receives the values in
its URL and its own request logs; what it stores is the vendor's responsibility.

## Webhook Security

Webhook providers use signed request verification.

Controls include:

- provider enablement
- provider secret resolution
- replay-window enforcement on provider timestamps where the scheme carries one: HubSpot v3, native Calendly and the
  ClickTrail relay. HubSpot v1 and Typeform carry no timestamp; for them only the signature replay cache and event-ID
  dedup (`clicutcl_v2_dedup_ttl`) apply
- optional replay protection filter control

Verification hardening:

- Typeform uses its native base64 HMAC `Typeform-Signature`
- HubSpot uses `X-HubSpot-Signature-v3` (base64 HMAC-SHA256 of method + `https://` + `Host` + raw request URI with
  HubSpot's listed percent-encodings decoded + body + `X-HubSpot-Request-Timestamp`), rejecting timestamps outside
  the replay window (default 300 s, HubSpot's recommended 5 minutes). A failing v3 never falls back to v1. The
  legacy SHA-256 `X-HubSpot-Signature` (v1) is checked only when no v3 header is sent. The v3 URL is tried as
  `Host` + request URI and `home_url()` host + request URI, plus any `clicutcl_hubspot_v3_request_uris` entries;
  each candidate still needs the secret, and `X-Forwarded-*` headers are not read. HubSpot subscription types off the
  `clicutcl_hubspot_webhook_event_types` allowlist (deletion, merge, restore, association change, privacy deletion,
  unknown) are acknowledged (2xx, `ignored`) without recording an event
- Calendly subscriptions use the native `Calendly-Webhook-Signature` (`t=<unix>,v1=<hex HMAC-SHA256 of "t.body">`,
  timestamp checked against the replay window) with the subscription's signing key as the provider secret.
  Requests without that header keep ClickTrail's timestamped HMAC relay contract. The invitee email never enters
  the event or the retry queue in clear text: with a `c=1` stamp it is passed through `Identity_Resolver` and only
  `hashed_email` (SHA-256 of the trimmed, lowercased address) is kept; without `c=1` (no stamp, no `c`, or `c=0`)
  the event carries no identity, even when consent is not required. The provider timestamp and downstream
  retention behavior still require review
- Calendly `payload.tracking` UTMs are mapped to last-touch attribution on `invitee.created`; cancellations and other
  Calendly events are acknowledged (2xx, `ignored`) without recording an event. A native Calendly webhook carries no
  visitor cookie; its consent snapshot comes only from the `c=` segment of ClickTrail's `salesforce_uuid` stamp
  (`ct1;…;c=1`), which records the visitor's live consent decision at decoration time and is omitted when no
  decision exists. That stamp has the same trust level as the consent cookie it was read from. Without `c=1`,
  when consent is required, the booking event is not delivered
- every signature is compared on the raw value with constant-time `hash_equals`
- provider secrets are stored verbatim (not truncated or whitespace-stripped), so long/base64/structured secrets verify correctly
- replay protection uses an atomic `wp_cache_add()` claim where a persistent object cache exists, falling back to a durable DB transient

Supported providers:

- Calendly
- HubSpot
- Typeform

### Server-origin events and strict consent

Webhook and lifecycle events reach ClickTrail from a provider or CRM server, so there is no visitor cookie to read
consent from (the translator's fallback, `Consent::get_state()`, finds nothing). When consent is required for the
request (`strict`, or `geo` with an unknown or listed request country; by default the country is unknown), ClickTrail
fails closed:

- HubSpot, Typeform and `lifecycle/update` events carry no consent and are skipped. They are not delivered, queued,
  written to `wp_clicutcl_touch_events` or counted in failure telemetry; the provider gets HTTP `200` with
  `success: false`, `skipped: true` and `detail: "consent_denied"`
- Calendly events are delivered only when the booking stamp carries `c=1`
- a consent cookie present on the request (for example an administrator's own browser session calling
  `lifecycle/update`) is used instead, because the dispatcher treats any live cookie as authoritative
- in `geo` mode with a resolved country, the country is the provider server's, not the visitor's

The queue resolver `clicutcl_queue_consent_snapshot` does not cover these events, because a skipped event is never
queued. A subject-bound consent store for server-origin events is deferred by design. Response shape, skip reasons and
operator options: [REST-API.md](../reference/REST-API.md#server-origin-events-and-strict-consent).

## Cached Server-Rendered Attribution (#152)

Leak class: a form adapter that writes the requesting visitor's attribution cookie into form HTML makes that
HTML visitor-specific. A full-page cache that does not vary on the `attribution` cookie stores it and serves
visitor A's click IDs and UTM values to visitor B, whose lead then carries A's attribution.

Controls:

- server render is empty by default (CF7, Gravity Forms, Fluent Forms, Elementor, WPForms, Formidable Forms, Forminator; MetForm renders nothing from ClickTrail; SureForms renders nothing at all, its field names are not `ct_*`); the filter
  `clicutcl_render_server_attribution_values` (default `false`) restores the old render for sites without a
  page cache
- the browser Injector fills the visitor's own values after load and writes a hidden `ct_fill_source=js` into
  each form it fills; `Injector.clear()` removes it on consent denial
- submit rule per adapter: a readable cookie is authoritative and keys it lacks are blanked; with no cookie,
  posted values are kept only with the marker (CF7, Fluent Forms, MetForm block builder; SureForms honours a posted marker but the Injector cannot mark its forms), otherwise blanked (Formidable Forms inputs are
  named `item_meta[<field id>]`, so the Injector never fills or marks them and its fields are always blanked
  without a cookie); consent blocked blanks
  every posted `ct_*` before the provider stores or mails it; ClickTrail's own log receives the post-rule set
- `ct_fill_source` is stripped by the adapters and is never stored, logged or forwarded; a forged marker without
  a cookie keeps only values the submitter already controlled

Evidence: source-present / runtime-unverified (PHPUnit and a Node VM test; no live page cache run).

## Queue, Revocation, and Retention Boundary

The initial dispatcher consent decision is not the same as a complete lifecycle privacy guarantee. Version 1.10.0
adds the implementation and automated contract coverage for the queue retry consent check and the allowlisted
WooCommerce order-meta lifecycle. Live runtime evidence is still required to prove that:

- consent withdrawal blocks queued sends immediately before adapter invocation in a WordPress installation;
- browser dataLayer history, pending capture, ClickTrail tables, Woo order metadata, debug buffers, and provider
  deliveries have an explicit documented behavior;
- retention is independent of the attribution cookie duration and cleanup continues when one table is unavailable;
- purge/export/erase/uninstall cover every ClickTrail-owned storage key in a live installation; expired Woo metadata
  is processed in bounded cleanup batches.

The Woo lifecycle (`Woo_Order_Privacy`) finds the orders to clean with one anchored `meta_key REGEXP` clause built from the key allowlist, not one `EXISTS` clause per key: the earlier 107-clause query made WooCommerce join `wc_orders_meta` 107 times on HPOS and MariaDB refused it (reproduced on HPOS only; posts storage never failed and is regression coverage) ("can only use 61 tables in a join"), so the eraser and retention cleanup silently processed no orders (#206). Every order's keys are still re-checked against the allowlist before anything is deleted. The purchase-sent marker `_clicutcl_tracking_sent` and the milestone markers `_clicutcl_woo_milestone_sent_*` hold no personal data (a flag or a timestamp) and are kept by subject erase, retention and the Diagnostics purge (export still lists them; only uninstall removes them), so a later status change cannot re-send `order_paid`, `order_refunded` or `order_cancelled`; the thank-you page also emits no purchase for an order whose paid (else created) date is older than the retention window, so a late visit cannot push a second copy once the marker is gone. Checkout writes only allowlisted attribution keys from the attribution cookie or posted fields, never the consent snapshot, sent markers or `_clicutcl_woo_*` state, so a crafted cookie cannot overwrite the stored checkout consent or suppress a purchase.

Easy Digital Downloads orders carry the same `_clicutcl_*` keys and the same lifecycle (`Edd_Order_Privacy`: personal-data export and erase by billing email, bounded retention, Diagnostics purge, uninstall). Attribution is written at `edd_built_order` only for customer-context requests and only when `Attribution_Provider::should_populate()` allows; only keys the allowlist can erase are written (the attribution cookie parser does not allowlist keys itself); a denied or withdrawn checkout stores just the consent snapshot, and the receipt never pushes or dispatches a purchase without current marketing consent. Runtime evidence: Store gateway, EDD 3.7.3, `tools/qa/e2e/edd-ci.sh` (export, erase, retention and purge against real EDD order queries). Evidence status: source-present, staged E2E for the Store gateway only, Stripe/PayPal runtime-unverified.

## Trusted Proxies and Request Identity

Trusted proxy handling matters for:

- rate limiting
- diagnostics
- request-source normalization

Relevant filters:

- `clicutcl_v2_trusted_proxies`
- `clicutcl_trusted_proxies`

How the client IP is resolved (`Tracking_Controller::get_client_ip()`):

1. When `REMOTE_ADDR` is **not** in the trusted-proxy list, it is the client IP and no forwarding header is read.
2. When `REMOTE_ADDR` is a trusted proxy and the opt-in filter `clicutcl_trust_cf_connecting_ip` returns `true` (default `false`), a valid `CF-Connecting-IP` is the client IP.
3. Otherwise `X-Forwarded-For` is walked **from the right**. Each hop is normalised first (`1.2.3.4:5678` becomes `1.2.3.4`, `[2001:db8::1]:443` and `[2001:db8::1]` become `2001:db8::1`). A hop that is a trusted proxy is skipped; the first hop that is not is the client. `X-Forwarded-For: fake, real` therefore resolves to `real`, and `client, trustedProxy` to `client`.
4. A hop that is still not an IP after normalisation (`unknown`, an obfuscated token, an empty part) **stops** the walk and the direct peer (`REMOTE_ADDR`, the trusted proxy) is used: entries to its left are client-controlled and are never reached by skipping over it. An absent header or an all-trusted chain also resolves to `REMOTE_ADDR`.

What to list as trusted proxies: every address that can be the direct peer of WordPress **and** appends to `X-Forwarded-For` (a local reverse proxy such as `127.0.0.1`, your load balancer) plus, when Cloudflare sits in front and appends to the chain WordPress sees, the Cloudflare ranges. The walk needs every infrastructure hop to the right of the visitor to be listed, otherwise that hop is taken for the client.

When to enable `clicutcl_trust_cf_connecting_ip`: only when the site is reachable exclusively through Cloudflare, so the header is always set or overwritten by Cloudflare. It is off by default because any other trusted proxy passes a client-sent `CF-Connecting-IP` through, which lets a visitor choose the IP that rate limits and the per-IP quotas use. Typical case for the opt-in: Cloudflare in front of a local reverse proxy where you list only `127.0.0.1`; there `X-Forwarded-For` is `client, cf-edge`, so without the filter (and without listing the Cloudflare ranges as trusted) every visitor resolves to the Cloudflare edge address and shares one rate-limit bucket. Either list the Cloudflare ranges as trusted proxies, or enable the filter, e.g. `add_filter( 'clicutcl_trust_cf_connecting_ip', '__return_true' );`.

Behaviour change (1.13.0): earlier versions preferred `CF-Connecting-IP` and took the **leftmost** untrusted `X-Forwarded-For` entry, both of which a client can set. A site with no trusted proxies configured is unaffected.

## Secret Storage

Advanced provider and lifecycle secrets are stored in `clicutcl_tracking_v2`.

Admin-facing behavior:

- secrets are masked before being returned to the UI
- blank or masked values preserve the existing secret
- explicit clear semantics are supported internally

Optional hardening:

- `encrypt_secrets_at_rest`
- `clicutcl_encrypt_settings_secrets`

When `encrypt_secrets_at_rest` is enabled but the server lacks OpenSSL AES-256-GCM, the
toggle is inert (secrets stay plaintext); an admin notice surfaces this so it does not
fail silently.

**Settings backup export contains unmasked secrets.** The "Export Backup" action (Settings
> Diagnostics) reads secrets via the decrypted/unmasked accessor, not the masked one used by
the admin UI, so the downloaded JSON contains the Calendly/HubSpot/Typeform webhook secrets
and the CRM lifecycle token in cleartext — even when `encrypt_secrets_at_rest` is on. The
action is capability- and nonce-gated, so this is not an access-control issue, but the
downloaded file itself should be handled like a credentials file: store it in a secrets
manager or encrypted storage, never commit it to a repository, and avoid sending it over
unencrypted channels. The admin UI carries an on-screen warning to this effect as of 1.8.14.

## Environment Safeguards

Server-side dispatch is blocked by default in:

- `local`
- `development`

Override hook:

- `clicutcl_dispatch_in_environment`

This helps prevent accidental dispatches against live platforms from cloned development environments.

## Diagnostics Privacy Posture

Always-on delivery telemetry stores:

- aggregated hourly failure counts

It does not store:

- full request payloads
- raw personal data

Debug windows can temporarily increase visibility for troubleshooting, but production behavior is intentionally limited.

### Form-readiness comparator boundary

The M5 presence comparator is redacted by construction. It accepts field-name
and presence snapshots, not attribution or submission values. Its report may
contain only:

- a closed adapter and pattern ID
- allowlisted `ct_*` field names derived from the attribution field mapping
- named evidence states for provider record, hook payload, and ClickTrail event
- closed reason/status enums, booleans, and counts

It must not accept or return raw attribution values, click IDs, browser or
visitor identifiers, identity fields, full submissions, landing/referrer URLs,
secrets, or provider records. Extra request properties are ignored and never
echoed. No live lookup or new persistence surface exists in the first slice.

### Woo-readiness comparator boundary

The M6-A comparator is also redacted by construction. Its inputs are closed
synthetic enums. Its output is limited to fixture IDs, placeholder event-name
forms, contract status/reason enums, bounded integers such as `dedup_ttl_days`,
constant value-basis labels, and counts. Unknown properties are ignored
and invalid enum values fail closed.

It must not accept or return real order/refund/customer IDs, identities,
attribution values, IP addresses, user agents, purchase payloads, URLs, or
secrets. It performs no WordPress/WooCommerce lookup and creates no storage.

The contract records the remaining privacy-lifecycle verification work:
identity-bearing canonical Woo trace snapshots and Diagnostics surfaces have an
allowlisted erase, purge, export, and uninstall path, but live Woo runtime verification remains open.
Runtime work remains blocked until that boundary is verified.
