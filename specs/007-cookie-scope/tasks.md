# Tasks: Cookie Scope (host vs registrable domain)

**Input**: [spec.md](spec.md), [plan.md](plan.md), [research.md](research.md)

## Phase 1 — Resolver and settings (US2, US4)

- [ ] T001 Add `includes/Core/class-cookie-scope.php` (`CLICUTCL\Core\Cookie_Scope`, picked up by `includes/class-autoloader.php:25-38`) with `resolve( $scope, $custom, $host )`, `registrable_domain( $host )` (port of `getRegistrableDomain()`, `assets/js/clicutcl-attribution.js:1351-1359`), `is_public_suffix( $domain )` backed by the R2 table and `apply_filters( 'clicutcl_public_suffixes', ... )`, reasons `host_scope`, `ok`, `ip_or_single_label`, `public_suffix`, `custom_not_suffix` (FR-003). — `includes/Core/class-cookie-scope.php`
- [ ] T002 Add `cookie_scope` (default `host`) and `cookie_custom_domain` (default `""`) to `get_attribution_settings_schema()` with `sanitize_cookie_scope` / `sanitize_cookie_custom_domain` (FR-001); surface the select and text field on the Capture tab next to `retention_days` with the staging warning (FR-002 description). — `includes/admin/class-admin.php:1145-1148`, `:596`, `:990`, sanitizers near `:1269`
- [ ] T003 Seed `cookie_scope` only when `clicutcl_attribution_settings` does not exist: `registrable`, or `host` when `is_multisite() && is_subdomain_install()`; the wizard keeps an existing key (FR-002). — `includes/database/class-installer.php`, `includes/admin/class-setup-wizard.php:142-156`
- [ ] T004 Localize `cookieDomain` (and `cookieScopeReason` in `clicutcl_config`) to `ctConsentBridgeConfig`, `clicutcl_config`, `clicutclConsentL10n`, `clicutclEventsConfig` from one `Cookie_Scope::resolve()` call per request (FR-004). — `includes/class-clicutcl-core.php:238`, `:252-256`, `:279-289`, `:307-312`, `:399-404`
- [ ] T005 Site Health: print scope, applied domain and fallback reason next to the cookie check (FR-008). — `includes/admin/class-sitehealth.php:138-140`
- [ ] T006 PHPUnit `tests/unit/CookieScopeSettingsTest.php` (`require_once` the class like `tests/unit/BoundarySecurityTest.php:16` does): resolver table (SC-003), `custom` suffix rule, schema default `host`, seed `registrable` vs `host` on subdomain multisite. Stub `home_url`, `is_multisite`, `is_subdomain_install`, `add_settings_error` in the bootstrap if missing. — `tests/unit/CookieScopeSettingsTest.php`, `tests/unit/bootstrap.php`

## Phase 2 — Browser writers (US1)

- [ ] T007 `Store.cookieDomainAttr()`, suffix guard with `cookieScopeReason = 'domain_mismatch'`, `setCookie`/`removeCookie` accept an optional explicit attribute and append it before `path=/` (FR-005). — `assets/js/clicutcl-attribution.js:578-599`
- [ ] T008 Same attribute helper in `setCookie`/`removeCookie` of the events tracker. — `assets/js/clicutcl-events.js:546-560`
- [ ] T009 Same in the consent banner `setCookie`. — `assets/js/clicutcl-consent.js:165-174`
- [ ] T010 Same in `writeServerCookie` and `clearPluginCookie` of the bridge. — `assets/js/clicutcl-consent-bridge.js:119-123`, `:178-189`
- [ ] T011 Debug log line when the guard falls back (FR-008), using the existing debug logger in `clicutcl-attribution.js`. — `assets/js/clicutcl-attribution.js`

## Phase 3 — Migration (US3)

- [ ] T012 `COOKIE_SCOPE_NAMES` (name + retention days: `attribution`/`cookieDays`, `ct_attribution`/`cookieDays`, `ct_visitor_id`/365, `ct_session_id`/1, `ct_session`/1, `CONFIG.consentCookieName`/365, `ct_consent_state`/365) and `Store.migrateCookieScope()` keyed by `localStorage['ct_cookie_scope']`, covering host to domain, domain to host, domain to domain; called once before `runAttribution()` (FR-006). — `assets/js/clicutcl-attribution.js` (boot path around `:1895`)

## Phase 4 — Proof and docs

- [ ] T013 Node VM test `tools/qa/cookie-scope.test.js` with a jar that records attributes: scope selection, `removeCookie` symmetry, suffix-guard fallback, migration idempotence, switch back to host (SC-001, SC-002); add to `npm run test:attribution` in `package.json:7`. — `tools/qa/cookie-scope.test.js`, `package.json`
- [ ] T014 Add `ct_cookie_scope` to the key inventory checked by `tools/qa/cmp-transition.test.js` and to the storage table (Necessary, local, holds only the applied domain). — `tools/qa/cmp-transition.test.js`, `docs/guides/SECURITY-PRIVACY.md:107-113`
- [ ] T015 Docs per FR-009: `DATA-MODEL.md:248-272` (scope line per cookie), `SECURITY-PRIVACY.md` (Scope column), `IMPLEMENTATION-PLAYBOOK.md:278-299` (new subsection "Same domain, different hosts: cookie scope" vs "Different domains: cross-domain token"), `HOOKS-REFERENCE.md` (`clicutcl_public_suffixes`), `SETTINGS-AND-ADMIN.md` (Capture tab), `CODE-MAP.md` (new class, new test), `readme.txt`, `README.en.md`, `changelog.txt`. — docs
- [ ] T016 Live acceptance (SC-004) on a disposable site serving `www` and apex; record the result in research.md and drop the runtime-unverified label only after it passes. — open
- [ ] T017 Comment on #141 with the `Cookie_Scope::resolve()` signature so the server re-issue consumes it (US5). — issue thread only

## Dependencies

T001 → T002, T004, T005, T006. T002 → T003. T004 → T007–T010. T007 → T011, T012. T007–T012 → T013. T013 → T014, T015. T016 after T013. T017 after T001.
