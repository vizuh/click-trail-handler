# Feature Specification: Attribution cookie restore and server re-issue (Safari ITP)

**Feature Branch**: `feat/itp-cookie-reissue`

**Created**: 2026-11-02

**Status**: Implemented (PHPUnit `AttributionCookieReissueTest`, Node VM `tools/qa/cookie-reissue.test.js`; SC-003 ran in the real-WordPress harness: `tools/qa/e2e/cookie-reissue.sh`, 49 checks, 0 failures, Chromium via Playwright 1.59.0, http, `www.example.test` / `example.test`, Safari's 24-hour script-cookie cap emulated by an init script; `adapters-ci.sh` S8 `edge_cache_headers` still 132 PASS). Not run: Safari or iOS, https (`Secure` only in PHPUnit), a real CDN or a cache that stores `POST` responses. Source-present / runtime-unverified for Safari.

**Input**: GitHub issue [#141](https://github.com/vizuh/click-trail-handler/issues/141). The `attribution` cookie is written only with `document.cookie`, which Safari ITP shortens or purges (see [research.md](research.md), W1-W5), so `Attribution_Provider::get_payload()` finds nothing for a visitor who converts later. Related: [../007-cookie-scope/spec.md](../007-cookie-scope/spec.md) US5 (the re-issue must use the same domain attribute as the JS writer).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Cookie restored from the localStorage mirror (Priority: P1)

A visitor with marketing consent lands from a link-decorated cross-site click on Safari. ITP cuts the script-written `attribution` cookie to 24 hours; the localStorage mirror survives until 7 days without interaction. When the visitor returns on day 3 the cookie is gone and the mirror is not: the script rewrites the cookie from the mirror before any form field is filled.

**Why this priority**: it closes the part of the loss the browser itself can fix, with no server involvement and no setting.

**Independent Test**: Node VM (`tools/qa/cookie-reissue.test.js`) with the real `clicutcl-attribution.js`: seed the mirror, no cookie, consent granted, run init, assert the cookie.

**Acceptance Scenarios**:

1. **Given** an unexpired mirror, no `attribution` cookie (and no legacy `ct_attribution`), and live consent that allows capture, **When** `runAttribution()` runs, **Then** the cookie is written with the mirror's data (without `click_id_history`), its expiry equals the mirror's `expiresAt` (never later than now + `cookieDays`), and this happens before `Injector.install()`.
2. **Given** consent is required and denied, or required and still pending, **Then** no cookie and no marker is written.
3. **Given** the mirror is expired (or a legacy envelope without `expiresAt`), **Then** it is removed and no cookie is written.
4. **Given** `cookie_scope` resolves to a domain, **Then** the restored cookie carries the identical `; domain=` attribute as every other plugin write, the `ct_scope_probe` rules apply, and no second same-name host-only cookie is left.
5. **Given** the cookie is present, **Then** the mirror is never used to overwrite it.
6. **Given** admin QA mode, **Then** nothing is restored (QA mode is session-scoped).

---

### User Story 2 - Server re-issue upgrades the cookie to HTTP-set (Priority: P1)

After the script writes (or restores) the attribution cookie, it asks the server to re-issue the same cookie in a REST response. An HTTP-set cookie is not subject to the script-writeable-storage rules (W4), so it outlives the 24-hour and 7-day cuts for the full `cookie_days`.

**Independent Test**: PHPUnit (`tests/unit/AttributionCookieReissueTest.php`) on the controller and the `Attribution_Cookie_Reissue` class; E2E (`tools/qa/e2e/cookie-reissue.sh`) on the wire.

**Acceptance Scenarios**:

1. **Given** a valid client token, the setting on, marketing consent allowed server-side (or not required) and a valid `attribution` request cookie, **When** `POST /clicutcl/v2/cookie-refresh` runs, **Then** the response carries exactly one `Set-Cookie: attribution=<same value>; Max-Age=<cookie_days*86400>; Path=/; [Domain=<Cookie_Scope domain>;] SameSite=Lax[; Secure]`, no `HttpOnly`, and the body says `reissued: true`.
2. **Given** the value fails validation (not JSON, a list, nesting, a bad key, an over-long value, encoded size over 3800 bytes, control characters), **Then** no header, body `reissued: false` with a reason.
3. **Given** `require_consent` is on and the consent cookie is absent or denies marketing, **Then** the permission check answers 403 `consent_required` and no header is sent.
4. **Given** no `attribution` cookie in the request, `cookie_days` is 0, or the setting is off, **Then** no header (403 `cookie_reissue_disabled` for the setting, 200 `reissued:false` otherwise).
5. **Given** a missing or invalid client token, an oversized body or a rate-limited client, **Then** the same errors as the sibling routes (401, 413, 429) and no header.
6. **Given** any non-REST request (a page GET or HEAD, the front page, a landing with `?gclid=`), **Then** the response carries no `Set-Cookie` from this feature, with consent granted or not.
7. **Given** the re-issued cookie's name, path and `Domain` equal the JS writer's, **Then** the browser holds exactly one `attribution` cookie (no duplicate-name hazard, #154).
8. **Given** the value, **Then** the cookie contains no field the request cookie did not already contain.

---

### User Story 3 - Setting and defaults (Priority: P2)

A new `attribution_cookie_reissue` setting controls U2. An admin who updates the plugin sees no change: existing installs keep it off. A fresh install gets it on. Decision and reasoning in [plan.md](plan.md).

**Independent Test**: PHPUnit `CookieScopeSettingsTest` (seed and schema) and `AttributionCookieReissueTest` (sanitisation, localisation).

**Acceptance Scenarios**:

1. **Given** a stored `clicutcl_attribution_settings` array without the key, **Then** sanitisation and the localized config resolve to off and `cookieReissueUrl = ""`.
2. **Given** no stored option and no sign of earlier use at activation, **Then** the seeded array holds `attribution_cookie_reissue = 1` next to `cookie_scope`.
3. **Given** the setting is on and attribution is enabled, **Then** `clicutcl_config` carries `cookieReissueUrl` and the client token is minted.

---

### User Story 4 - Pending marker and request budget (Priority: P2)

The browser does not call the route on every page view. It calls it when it has just written the cookie.

**Independent Test**: the VM test.

**Acceptance Scenarios**:

1. **Given** a script write of the attribution cookie with live consent, **Then** `localStorage['ct_cookie_reissue']` is set; with consent denied or pending it is not.
2. **Given** a marker and a cookie, **Then** one POST (token header, credentials same-origin) is sent at the end of `runAttribution()`; a 2xx or a 403 clears the marker; 401, 429, 5xx and a network error keep it, and a new attempt is not sent within one hour.
3. **Given** no marker, **Then** no request is sent (a returning visitor with an HTTP-set cookie costs nothing).
4. **Given** consent is withdrawn, **Then** the marker is removed together with the cookie and the mirror.

---

### Edge Cases

- **Cached page, stale token**: the route needs the page-embedded client token (default TTL 7 days). A page cached longer answers 401; the marker stays and the next fresh page retries.
- **Consent decided outside the plugin** (a CMP that stores consent only in `localStorage`): the server cannot see consent, `require_consent` on answers 403; the client drops the marker on 403 and the cookie stays script-written. Documented limit.
- **TLS-terminating proxy** that makes `is_ssl()` false while the site URL is https: `Secure` is also set when `home_url()` is https.
- **Preview/CDN host not covered by `cookieDomain`**: the server falls back to host-only, like the JS suffix guard, using the request `Host`.
- **HTTP-set cookie later rewritten by the script** (a new touch): the script write replaces it with a script-written cookie and sets the marker, so the next re-issue re-upgrades it.
- **Cookie blocked or too large for the cookie jar**: the script sets no marker (the write did not persist) and nothing is sent.
- **What stays capped**: any script-written cookie after a re-issue until the next re-issue; `ct_visitor_id`, `ct_session_id`, `ct_session`; the mirror and the marker (script-writeable storage). WebKit also caps cookies in an HTTP response that comes through third-party CNAME or IP cloaking (W6); the route is served by the page's own host, which is not that case, but a site that fronts only its REST host through a third-party CNAME would see a 7-day cap on the re-issue.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: `Store.restoreCookieFromMirror()` MUST run in `runAttribution()` after `canCapture()` and before `Injector.install()`, MUST write only when neither `attribution` nor `ct_attribution` is present, MUST use `Store.setCookie()` (so scope, probe, `Secure`, size cap and the other-scope expiry apply) with `days = min(cookieDays, (expiresAt - now) / 86400000)`, and MUST NOT run in admin QA mode.
- **FR-002**: The mirror read MUST remove an expired or legacy envelope (existing behaviour) and MUST NOT write storage or cookies before consent resolves.
- **FR-003**: A new route `POST clicutcl/v2/cookie-refresh` MUST exist with the gate order: setting on (403 `cookie_reissue_disabled`), body at most 2048 bytes (413), rate limit scope `cookie_reissue` (429), client token (401), consent from the request cookie (403 `consent_required`). It MUST NOT use the token-nonce replay limit (it stores nothing and a shared page-cache token would starve other visitors behind one IP).
- **FR-004**: `Attribution_Cookie_Reissue` MUST be the only place that validates the value and builds the header; the controller MUST emit it with `header( ..., false )` (append, never replace another plugin's `Set-Cookie`) only when `headers_sent()` is false.
- **FR-005**: The header MUST be `Set-Cookie: attribution=<rawurlencode(value)>; Max-Age=<cookie_days*86400>; Path=/; Domain=<d>; SameSite=Lax; Secure` with `Domain` present only when `Cookie_Scope::resolve_for_site()` returns a domain that covers the request host, `Secure` present when `is_ssl()` or `home_url()` is https, and `HttpOnly` never present. Name `attribution`, path `/`, `SameSite=Lax` are constants.
- **FR-006**: The route MUST add `Cache-Control: no-store, private` to a response that carries the header; it MUST NOT add a header to any other response.
- **FR-007**: `attribution_cookie_reissue` MUST be in the attribution settings schema (default `0`), mapped in both directions in the admin settings app on the Capture tab, seeded `1` only on a fresh install (same rule as `cookie_scope`), localized as `cookieReissueUrl` (empty when off or when attribution is off) in `clicutcl_config`, and mint the client token when on.
- **FR-008**: `ct_cookie_reissue` MUST be added to the storage inventory (`docs/guides/SECURITY-PRIVACY.md`), which `tools/qa/cmp-transition.test.js` checks against the keys the scripts write.
- **FR-009**: Docs MUST be updated: `DATA-MODEL.md`, `SECURITY-PRIVACY.md` (storage inventory: `attribution` medium becomes "cookie (JS and server re-issue)"), `OPERATIONS-RUNBOOK.md` (caches must not cache `POST /clicutcl/v2/*`), `REST-API.md`, `HOOKS-REFERENCE.md`, `SETTINGS-AND-ADMIN.md`, `CODE-MAP.md`, `readme.txt`, `README.en.md`, `README.pt-BR.md`, `changelog.txt`, and this spec's status line.

### Key Entities

- **attribution_cookie_reissue**: `0|1` in `clicutcl_attribution_settings`.
- **cookieReissueUrl**: REST URL localized to `clicutcl-attribution.js`; empty when the feature is off.
- **ct_cookie_reissue**: per-origin localStorage marker `{w: written-at ms, t: last-attempt ms}`; holds no attribution data.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: PHPUnit: the header is emitted exactly once with the exact attribute string for each of host / registrable / custom scope and for http / https; zero headers for denied or absent consent, absent cookie, invalid value, setting off, and zero headers on every non-route code path.
- **SC-002**: VM: restore happens only after consent, zero cookie or storage writes before it, the domain attribute is the same string as the other plugin writes, and the expiry bound equals the mirror's.
- **SC-003**: E2E (Chromium, real WordPress): the landing page GET and HEAD carry no `Set-Cookie`; the `cookie-refresh` POST carries exactly one `attribution` `Set-Cookie` whose `Max-Age` is `cookie_days*86400`; after it the browser has one `attribution` cookie with the expected expiry and domain; S8 `edge_cache_headers` still passes. Safari behaviour stays runtime-unverified.

## Assumptions

- The client token minted for the page is the right gate for a route that stores nothing; a stricter gate would starve cached pages.
- `localStorage` and cookies are both available; when either is blocked the feature does nothing.
- Spec 007 (cookie scope) is merged; this spec consumes `Cookie_Scope`.
