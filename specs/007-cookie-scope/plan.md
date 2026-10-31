# Implementation Plan: Cookie Scope (host vs registrable domain)

**Branch**: `feat/cookie-scope` | **Date**: 2026-10-31 | **Spec**: [spec.md](spec.md) | **Issue**: [#154](https://github.com/vizuh/click-trail-handler/issues/154)

## Summary

Add a `cookie_scope` attribution setting (`host` | `registrable` | `custom`). PHP resolves one `cookieDomain` string from the `home_url()` host (decorator heuristic plus an explicit public-suffix table, filterable), localizes it to all four front-end scripts, and every JS cookie write and expiry appends `; domain=<cookieDomain>` when the value is a suffix of `location.hostname`. `clicutcl-attribution.js` migrates host-only cookies once per origin. PHP readers do not change. #141's server re-issue consumes the same resolver.

## Technical Context

**Language/Version**: browser ES2019 (no build step) in four scripts; PHP 8.1+ for the resolver, settings schema, activation seed, localization
**Primary Dependencies**: none new (no Public Suffix List package)
**Storage**: existing cookies gain a `domain` attribute; new `localStorage['ct_cookie_scope']` marker (per origin, Necessary category, holds only the applied domain)
**Testing**: `tools/qa/cookie-scope.test.js` (Node VM, real `clicutcl-attribution.js`, cookie jar that records attributes) in `npm run test:attribution`; PHPUnit `tests/unit/CookieScopeSettingsTest.php` (resolver table, sanitisation, seed); `npm run smoke`
**Constraints**: no behaviour change for existing installs; never emit a public suffix as `domain`; expiry must mirror write attributes; consent gating unchanged

## Repo gates

- AGENTS.md docs triggers (new setting, new filter, storage change): `docs/guides/SETTINGS-AND-ADMIN.md`, `docs/reference/HOOKS-REFERENCE.md` (`clicutcl_public_suffixes`), `docs/architecture/DATA-MODEL.md` (Client-Side Storage), `docs/guides/SECURITY-PRIVACY.md` (storage inventory; `tools/qa/cmp-transition.test.js` keeps the key list in sync, so `ct_cookie_scope` goes there too), `docs/guides/IMPLEMENTATION-PLAYBOOK.md` (Cross-Domain Limitations), `docs/architecture/CODE-MAP.md` (new class, new test), `readme.txt`, `README.en.md`, `changelog.txt`.
- Evidence boundary: the feature stays source-present / runtime-unverified until SC-004 passes on a disposable site with two hosts.

## Design

### Server

| Change | File |
|---|---|
| `Cookie_Scope::resolve( string $scope, string $custom, string $host ): array{domain,reason}`; `registrable_domain( $host )` ports `getRegistrableDomain()` (`clicutcl-attribution.js:1351-1359`); `is_public_suffix()` checks a table filtered by `clicutcl_public_suffixes`; reasons `host_scope`, `ok`, `ip_or_single_label`, `public_suffix`, `custom_not_suffix` | `includes/Core/class-cookie-scope.php` (new) |
| Schema entries `cookie_scope` (default `host`, `sanitize_cookie_scope`) and `cookie_custom_domain` (default `""`, `sanitize_cookie_custom_domain`: lowercase, strip leading dot, must be a suffix of the `home_url()` host and not a public suffix, else scope becomes `host` and `add_settings_error`) next to `cookie_days` | `includes/admin/class-admin.php:1145-1148` schema, sanitizers near `:1269` |
| Capture tab field: select with description "Registrable domain shares cookies with every subdomain, including staging" plus the custom text input; mapping in both directions next to `retention_days` | `includes/admin/class-admin.php:596`, `:990` |
| Seed `cookie_scope = registrable` (or `host` on `is_multisite() && is_subdomain_install()`) only when `get_option('clicutcl_attribution_settings')` returns `false`; wizard preserves an existing key | `includes/database/class-installer.php`, `includes/admin/class-setup-wizard.php:142-156` |
| `cookieDomain` and `cookieScopeReason` in `build_attribution_config()`; `cookieDomain` in the bridge config, `clicutclConsentL10n`, `clicutclEventsConfig` | `includes/class-clicutcl-core.php:238`, `:252-256`, `:279-289`, `:307-312`, `:399-404` |
| Site Health line "Cookie scope: registrable (example.com)" or "host (fallback: public_suffix)" next to the existing cookie check | `includes/admin/class-sitehealth.php:138-140` |

### Browser

| Change | Where |
|---|---|
| `cookieDomainAttr()`: returns `; domain=` + `CONFIG.cookieDomain` when non-empty and `location.hostname === d || endsWith('.' + d)`, else `""`; sets `Store.cookieScopeReason = 'domain_mismatch'` on mismatch | `clicutcl-attribution.js` Store (`:578-599`) |
| `setCookie(name, value, days, domainAttr?)` / `removeCookie(name, domainAttr?)` append the attribute before `path=/` | same |
| `Store.migrateCookieScope()`: runs before `runAttribution()`; compares `localStorage['ct_cookie_scope']` with the applied domain; for each name in `COOKIE_SCOPE_NAMES` reads the value, expires the previous variant (host-only or previous domain), rewrites with the applied attribute and that cookie's retention, then stores the marker | boot path near `runAttribution()` (`:1895`) |
| Same attribute helper (copied, scripts do not share scope) in `setCookie` / `removeCookie` | `clicutcl-events.js:546-560` |
| Same in `setCookie` | `clicutcl-consent.js:165-174` |
| Same in `writeServerCookie` / `clearPluginCookie` | `clicutcl-consent-bridge.js:119-123`, `:178-189` |

### Contract: setting -> cookie attribute

```text
cookie_scope=host                     -> cookieDomain ""            -> "name=v; expires; path=/; SameSite=Lax[; Secure]"
cookie_scope=registrable, www.example.com -> "example.com"           -> "...; domain=example.com; path=/; ..."
cookie_scope=registrable, brand.co.uk     -> "brand.co.uk"
cookie_scope=registrable, team.github.io  -> "" reason=public_suffix
cookie_scope=registrable, 10.0.0.5 | localhost -> "" reason=ip_or_single_label
cookie_scope=custom, example.com on www.example.com -> "example.com"
cookie_scope=custom, other.com                      -> sanitised to host, settings error
JS guard: cookieDomain not a suffix of location.hostname -> host-only write, reason=domain_mismatch
remove: identical domain attribute as the write
migration marker: localStorage ct_cookie_scope = applied domain ("" for host)
```

## Risks

- The heuristic misclassifies rare TLD shapes (`k12.xx.us`, `pvt.k12.ma.us`); the table and the `clicutcl_public_suffixes` filter are the escape hatch, and browsers reject a true public suffix anyway.
- Two cookies with one name during the migration window: `document.cookie` order is not guaranteed, so the migration reads before any expiry and rewrites once; the consent bridge may already have written a domain cookie by then, which the rewrite reproduces.
- Domain-scoped consent and attribution cookies become visible to staging and every other subdomain; the setting description and the playbook must say when to pick `host`.
- #141's server re-issue must not land before this resolver exists, or it will hard-code a scope.
