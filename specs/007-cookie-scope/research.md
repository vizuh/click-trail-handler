# Research: Cookie Scope (host vs registrable domain)

Source read on 2026-10-31 (`main` @ `ef6a20b`, 1.11.0). Nothing below was run in a browser; every row is source-present / runtime-unverified. Competitor behaviour (which plugins scope to the registrable domain) is in [../004-submit-time-refresh-parity/research.md](../004-submit-time-refresh-parity/research.md).

Vendor references:

- RFC 6265 section 5.3 (host-only flag, `domain` attribute matching, public suffix rejection): https://www.rfc-editor.org/rfc/rfc6265#section-5.3
- Public Suffix List: https://publicsuffix.org/list/

## Current state

| Surface | File | Finding |
|---|---|---|
| Attribution cookie write | `assets/js/clicutcl-attribution.js:578-594` (`Store.setCookie`) | `name=value; expires; path=/; SameSite=Lax[; Secure]`, no `domain`. Returns `getCookie(name) === rawValue`. Oversize values call `removeCookie` (`:582`). |
| Attribution cookie expiry | `:596-599` (`Store.removeCookie`) | Same attributes, `Max-Age=0`, no `domain`. Used by `clearData` (`:678-681`), legacy-name cleanup (`:738`), identity reset (`:1603-1604`), session clear (`:1716`). |
| Cookie names written by `clicutcl-attribution.js` | `:735` `CONFIG.cookieName` (`attribution`, `class-clicutcl-core.php:401`); `:1620`, `:1626` `ct_session_id` (1 day); `:1637`, `:1643` `ct_visitor_id` (365 days); `:1705` `SESSION_COOKIE_NAME = 'ct_session'` (`:1668`, 1 day) | All host-only. |
| Cookie names written elsewhere | `clicutcl-events.js:546-560` (`setCookie`/`removeCookie`), `:595-618` (`ct_session_id`, `ct_visitor_id`); `clicutcl-consent.js:165-174` (`ct_consent`, 365 days, name from `clicutclConsentL10n.cookieName`); `clicutcl-consent-bridge.js:178-189` (`writeServerCookie`, `ct_consent_state`), `:119-123` (`clearPluginCookie`) | Four independent writers, same attribute string, no shared helper. |
| localStorage mirror | `:601-630` (`getLocalData`), `:633-650` (`setLocalData`), key `CONFIG.cookieName` | Per-origin by definition; cannot cross hosts. |
| Registrable-domain heuristic | `:1351-1359` (`getRegistrableDomain`, inside `Decorator.matchesAllowedDomain`) | `parts.length <= 2` returns the host; if the second-to-last label is in `co com net org gov edu ac ne or me` returns the last three labels, else the last two. No Public Suffix List. Function-local; not reusable without a refactor. |
| Settings | `includes/admin/class-admin.php:1139-1202` (`get_attribution_settings_schema`), `:1145-1148` `cookie_days` | No `cookie_scope`, `cookie_domain` or similar key anywhere in `includes/` (grep `cookie_domain|cookieDomain` returned nothing). |
| Localized config | `includes/class-clicutcl-core.php:238` (`ctConsentBridgeConfig`), `:252-256` (`clicutcl_config`), `:279-289` (`clicutclConsentL10n`), `:307-312` (`clicutclEventsConfig`), `:399-404` (`build_attribution_config`) | `cookieName`, `cookieDays`, `consentCookieName`, `siteId` are localized; nothing about domain. |
| Install seed | `includes/admin/class-setup-wizard.php:142-156` writes `clicutcl_attribution_settings` with three keys; `includes/database/class-installer.php:28` owns `clicutcl_db_version` | No place today distinguishes a fresh install from an upgrade for attribution settings. |
| PHP readers | `includes/Core/class-attribution-provider.php:139-145` (`attribution`, `ct_attribution`), `:163-164` (`ct_visitor_id`), `:333-338` (`ct_session`); `includes/server-side/class-consent.php:27-42` (`ct_consent`, `ct_consent_state`); `includes/admin/class-sitehealth.php:138-140` | Read `$_COOKIE` by name only. A `domain` attribute changes which hosts the browser sends the cookie to, not the name, so PHP is unaffected. |
| PHP writers | `includes/Modules/Events/class-events-logger.php:131`, `:152` (login/register/comment event cookies, consent-gated); `includes/class-clicutcl-core.php:461`, `:485` (`clicutcl_admin_qa`) | Short-lived, read back on the same host; out of scope. #141 will add an attribution re-issue and must call the resolver. |

## R1 — Where to resolve the domain

**Decision**: PHP, once, in `Cookie_Scope::resolve()`; the result is localized as `cookieDomain` to all four scripts, which only guard that it is a suffix of `location.hostname`.
**Rationale**: four scripts write cookies and do not share code (bridge loads before attribution). One PHP resolver gives one answer, one filter (`clicutcl_public_suffixes`), one PHPUnit table, and the same function for #141's `setcookie()`. Porting the decorator heuristic to PHP keeps `registrable` identical to what the decorator already treats as "same site". The decorator's own function-local copy stays untouched (link decoration is not in scope).

## R2 — Heuristic plus suffix table, not a PSL

**Decision**: keep the two-rule heuristic and add a minimal table of multi-label public suffixes where the heuristic is wrong or where the result would be a hosting suffix: `github.io`, `gitlab.io`, `herokuapp.com`, `netlify.app`, `vercel.app`, `pages.dev`, `wpengine.com`, `wpenginepowered.com`, `kinsta.cloud`, `cloudfront.net`, `azurewebsites.net`, `amazonaws.com`, `web.app`, `firebaseapp.com`, `appspot.com`, `myshopify.com`, `blogspot.com`, `wordpress.com`. Anything that resolves to an entry (or to a 2-label suffix the heuristic already extended, such as `co.uk`) falls back to `host` with reason `public_suffix`. Sites extend it with `clicutcl_public_suffixes`.
**Rationale**: a full PSL is ~250 KB and changes monthly; browsers already refuse `domain=co.uk`, so the table only needs the suffixes where a wrong `domain` would be accepted and leak cookies to sibling tenants.

## R3 — Default and migration

**Decision**: schema default `host`; the activation seed and the wizard write `registrable` only when the option does not exist (`host` on subdomain multisite). JS migration is per origin, keyed by `localStorage['ct_cookie_scope']`, and handles both directions (host to domain, domain back to host, domain to another custom domain).
**Rationale**: `document.cookie` cannot tell a host-only cookie from a domain cookie with the same name, so the migration must expire the previous variant explicitly, which requires remembering the previous domain. A per-origin marker is enough because each origin needs its own cleanup.

## R4 — localStorage mirror

**Decision**: keep per-origin; no cross-origin sync; the cookie is canonical.
**Rationale**: the mirror exists for blocked or oversize cookies (`:741` comment). On a new host it is empty and `getCookie` wins; on the original host it is still valid for that origin. Skipping the mirror when the host differs from the writer would only add state without a reader.

## R5 — Consent cookies

**Decision**: `ct_consent` and `ct_consent_state` follow the same scope.
**Rationale**: `Attribution_Provider::should_populate()` (`:183-205`) and `Consent::get_state()` read them per request; a grant on `www` that is invisible on the apex would block attribution there regardless of the attribution cookie's scope.

## R6 — Open questions

- Does any CMP that the bridge mirrors already write its own domain-scoped consent cookie, and does `ct_consent_state` then need to match it (bridge `cmpSource` auto)?
- Should Site Health warn when `registrable` is active and `home_url()` host starts with `staging.` or `dev.`?
- Which hosting suffixes appear in the install base (telemetry is not available; the table in R2 is a judgement call).
- Live proof (SC-004) needs a disposable site with `www` and apex both serving WordPress; the E2E harness in `tools/qa/e2e/` runs a single host.
