# Implementation Plan: Attribution cookie restore and server re-issue (Safari ITP)

**Branch**: `feat/itp-cookie-reissue` | **Date**: 2026-11-02 | **Spec**: [spec.md](spec.md) | **Issue**: [#141](https://github.com/vizuh/click-trail-handler/issues/141)

## Summary

U1 restores a missing `attribution` cookie from the localStorage mirror inside `runAttribution()`. U2 adds one tiny REST route, `POST /clicutcl/v2/cookie-refresh`, that echoes the request's own validated `attribution` cookie back as an HTTP `Set-Cookie` with the same name, path, `SameSite` and `Domain` the JS writer uses, only when marketing consent is live server-side. The browser calls it only after a script write (pending marker). A setting `attribution_cookie_reissue` gates U2: off for existing installs, seeded on for fresh installs.

## Technical Context

**Language/Version**: browser ES2019 (one script changes: `clicutcl-attribution.js`); PHP 8.1+ for the route and the new class
**Primary Dependencies**: none new
**Storage**: one new localStorage key, `ct_cookie_reissue` (marker, no attribution data); the `attribution` cookie gains a second writer
**Testing**: PHPUnit `AttributionCookieReissueTest` + updated `CookieScopeSettingsTest`; Node VM `tools/qa/cookie-reissue.test.js` (in `npm run test:attribution`); E2E `tools/qa/e2e/cookie-reissue.sh`; `adapters-ci.sh` (S8 must still pass); `npm run smoke`, `test:consent`
**Constraints**: never `Set-Cookie` on a page response; never write cookie or storage before consent; no new data in the cookie; same `Domain` as the JS writer; no deprecations

## Repo gates

AGENTS.md docs triggers (API, setting, storage/cookie change): `docs/reference/REST-API.md`, `docs/reference/HOOKS-REFERENCE.md`, `docs/guides/SETTINGS-AND-ADMIN.md`, `docs/architecture/DATA-MODEL.md`, `docs/guides/SECURITY-PRIVACY.md`, `docs/guides/OPERATIONS-RUNBOOK.md`, `docs/architecture/CODE-MAP.md`, `readme.txt`, `README.en.md`, `README.pt-BR.md`, `changelog.txt`. Evidence boundary: Safari stays runtime-unverified; the wire shape is proven in Chromium only.

## Decision: default of `attribution_cookie_reissue`

| Option | For | Against |
|---|---|---|
| On for everyone | most Safari visitors fixed after update | the plugin starts to emit a `Set-Cookie` on a new route for installs that never asked; a cache or WAF rule that blocks or caches `POST /wp-json/` would turn into silent 4xx or stale responses; changes what a cookie audit of existing sites finds |
| Off for everyone | zero change | the fix never reaches fresh installs unless an admin finds the checkbox |
| **Schema default off, seeded on for fresh installs only** | existing installs unchanged (as `cookie_scope`); new installs get the fix with no action; reversible | an upgrading site must opt in to get U2 (U1 needs no opt-in) |

**Chosen**: the third row, mirroring `cookie_scope` (#154): `Installer::seed_fresh_install_defaults()` (the renamed `seed_cookie_scope()`) writes `attribution_cookie_reissue = 1` together with `cookie_scope`, only when no attribution option and no sign of earlier use exists; the schema default stays `0`; the setup wizard keeps whatever is stored. The changelog tells upgrading installs to switch it on after checking that REST `POST` bypasses their cache. U1 is not behind the setting: it only rewrites a cookie the plugin wrote earlier, inside the same retention bound, after consent, and touches no server path.

## Design

### Server

| Change | File |
|---|---|
| `Attribution_Cookie_Reissue` (new): `NAME='attribution'`, `MAX_ENCODED_BYTES=3800`, `inspect( string $raw ): string` (`''` valid, else reason `empty|too_large|not_json|not_object|bad_key|bad_value|too_many_keys`), `build_header( string $raw, int $days, string $domain, bool $secure ): string`, `issue( string $host, bool $secure ): array{issued:bool,reason:string}` (reads `$_COOKIE['attribution']`, `Attribution_Settings`, `Cookie_Scope::resolve_for_site()` + `covers()`, emits with `header( $line, false )` unless `headers_sent()`) | `includes/Core/class-attribution-cookie-reissue.php` |
| `Cookie_Scope::covers( string $domain, string $host ): bool` (the same suffix rule the JS `coversHost()` uses) | `includes/Core/class-cookie-scope.php` |
| Route `/cookie-refresh`, `cookie_refresh_permissions_check()`, `refresh_attribution_cookie()`; optional `Attribution_Cookie_Reissue` injection in the constructor for tests | `includes/api/class-tracking-controller.php` |
| Setting `attribution_cookie_reissue` (schema default 0, `sanitize_toggle`), Capture tab mapping both directions, `Attribution_Settings::is_cookie_reissue_enabled()` | `includes/admin/class-admin.php`, `includes/settings/class-attribution-settings.php` |
| Seed rename and the new key | `includes/database/class-installer.php`, `clicutcl.php` |
| `cookieReissueUrl` in `build_attribution_config()`; mint the client token when the feature is on | `includes/class-clicutcl-core.php` |
| Site Health line "Attribution cookie re-issue: on/off" next to the cookie scope line | `includes/admin/class-sitehealth.php` |
| Capture-tab toggle with the cache warning | `assets/js/admin-settings-app.js` |

### Browser (`assets/js/clicutcl-attribution.js`)

| Change | Where |
|---|---|
| `readLocalEnvelope()` (shared by `getLocalData()`); `restoreCookieFromMirror()` | `Store`, next to `getLocalData` |
| `setCookie()`: on a persisted write of `CONFIG.cookieName` with live consent, `markReissuePending()` | `Store.setCookie` |
| `readReissueMarker()`, `writeReissueMarker()`, `markReissuePending()`, `reissueCookie()` (fetch POST, outcome handling, one hour floor) | `Store` |
| `clearData()` removes the marker | `Store.clearData` |
| `runAttribution()`: `Store.restoreCookieFromMirror()` right after `canCapture()`; `Store.reissueCookie()` at the end | `ClickTrailAttribution` |

### Contract: wire

```text
POST /wp-json/clicutcl/v2/cookie-refresh        X-Clicutcl-Token: <page token>   Cookie: attribution=<v>; ct_consent=...
body {"token":"...","domain_applied":"example.com"|"" [,"expires_in":<seconds, restored cookies only>]}
200 {"success":true,"reissued":true}
Set-Cookie: attribution=<rawurlencode v>; Max-Age=<cookie_days*86400>; Path=/; Domain=example.com; SameSite=Lax; Secure
Cache-Control: no-store, private

host scope, http   -> no Domain, no Secure          registrable www.example.com -> Domain=example.com
domain_applied "" / absent / != scope domain / host not covered -> no Domain (host-only)       403 consent_required | 403 cookie_reissue_disabled | 401 | 413 | 429
200 {"success":true,"reissued":false,"reason":"not_json"}   (no Set-Cookie)
```

## Risks

- A cache, WAF or security plugin that blocks `POST /wp-json/clicutcl/v2/*` makes U2 fail silently; the marker retries hourly. Documented in the runbook and the setting description.
- A page-cache plugin that caches REST `POST` (non-standard) could replay another visitor's `Set-Cookie`. Mitigation: `Cache-Control: no-store, private` on that response, the cookie echoes only the sender's own value, and docs tell operators to bypass `/wp-json/clicutcl/v2/`.
- The route is reachable by anyone holding a page token: it echoes a cookie the caller already sent. No store, no side effect beyond the response.
- `Domain` parity: the server cannot know whether the script's `probeDomain()` fell back to host-only (browser refused the domain), and a `Domain=` Set-Cookie next to a host-only script cookie would be a second same-name cookie (PHP reads the first, the script's write-time cleanup expires the other, the marker re-arms, flip-flop each visit). So the script reports `domain_applied` (the same memoized `cookieDomainAttr()` result it wrote with) and the server sends `Domain=` only when it equals the configured scope domain and covers the request host; anything else gets a host-only cookie. PHPUnit covers `''`, the scope domain, another domain, injection text, non-string and absent; the VM test covers the body after an accepted, a rejected and a non-covering domain; the E2E checks the single resulting cookie in a real browser.
- A cookie restored from the mirror must not outlive the mirror: the marker keeps the mirror expiry (`x`) and the POST sends `expires_in`; the server clamps `Max-Age` to `[1, cookie_days*86400]`. A fresh landing write sends none, so its `Max-Age` is exactly `cookie_days*86400`.
