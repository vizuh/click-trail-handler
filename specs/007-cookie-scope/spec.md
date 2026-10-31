# Feature Specification: Cookie Scope (host vs registrable domain)

**Feature Branch**: `feat/cookie-scope`

**Created**: 2026-10-31

**Status**: Draft, not started. Every claim below is source-present / runtime-unverified: the current behaviour was read from `main` @ `ef6a20b` (1.11.0), nothing has been run in a browser yet.

**Input**: GitHub issue [#154](https://github.com/vizuh/click-trail-handler/issues/154). Every cookie ClickTrail writes from JavaScript is host-only, so attribution captured on `www.example.com` is invisible on `example.com` or `lp.example.com`. Related: [#141](https://github.com/vizuh/click-trail-handler/issues/141) (server re-issue must use the same scope), competitor review in [../004-submit-time-refresh-parity/research.md](../004-submit-time-refresh-parity/research.md).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Attribution survives a hop between hosts of one site (Priority: P1)

A visitor with marketing consent lands on `www.example.com/?gclid=X` and converts on `example.com` or `lp.example.com`. With `cookie_scope = registrable` the `attribution`, `ct_visitor_id`, `ct_session`, `ct_session_id`, `ct_consent` and `ct_consent_state` cookies carry `domain=example.com`, so the browser sends them to every host under `example.com` and the hidden fields, entry meta and server readers see the original click.

**Why this priority**: Today `Store.setCookie` (`assets/js/clicutcl-attribution.js:578-594`) writes `path=/; SameSite=Lax` with no `domain`, and the same shape is repeated in `clicutcl-events.js:546-555`, `clicutcl-consent.js:165-174` and `clicutcl-consent-bridge.js:178-189`. No `cookie_domain` or `cookie_scope` setting exists under `includes/`. The cross-domain token path targets different registrable domains and does nothing for www / apex / subdomain hops that start without a decorated link.

**Independent Test**: Run each of the four real scripts (`clicutcl-attribution.js`, `clicutcl-events.js`, `clicutcl-consent.js`, `clicutcl-consent-bridge.js`) in a Node VM (`tools/qa/cookie-scope.test.js`) with a `document.cookie` jar that records the `domain` attribute, set the localized `cookieDomain` for each script's config object, and assert every write and every delete per script.

**Acceptance Scenarios**:

1. **Given** `cookieDomain = "example.com"` and `location.hostname = "www.example.com"`, **When** attribution is saved, **Then** the `attribution` write contains `; domain=example.com; path=/; SameSite=Lax` and the same holds for `ct_visitor_id`, `ct_session_id` and `ct_session`.
2. **Given** the same config, **When** `Store.removeCookie('attribution')` runs (consent withdrawal, oversize value, legacy-name cleanup), **Then** the expiring write carries the identical `domain` attribute.
3. **Given** `cookieDomain = ""` (scope `host`), **Then** no write contains a `domain` attribute (today's behaviour, byte-for-byte).
4. **Given** `cookieDomain` is not a suffix of `location.hostname` (stale value, domain-mapped multisite, CDN preview host), **Then** the script falls back to host-only writes and sets `Store.cookieScopeReason = "domain_mismatch"`.

---

### User Story 2 - Existing installs keep `host`, new installs default to `registrable` (Priority: P1)

An admin who updates the plugin sees no change in cookie behaviour. A fresh install gets `registrable` out of the box.

**Independent Test**: PHPUnit (`tests/unit/CookieScopeSettingsTest.php`) against `Admin::sanitize_settings()` and `Cookie_Scope::resolve()` with stubbed `get_option` / `home_url`.

**Acceptance Scenarios**:

1. **Given** a stored `clicutcl_attribution_settings` array without a `cookie_scope` key, **Then** sanitisation and the localized config resolve to `host` and `cookieDomain = ""`.
2. **Given** no stored option at all (activation or setup wizard on a new site), **Then** the seeded array contains `cookie_scope = registrable`.
3. **Given** `cookie_scope = custom` with `cookie_custom_domain = "example.com"` and `home_url()` host `www.example.com`, **Then** `cookieDomain = "example.com"`. **Given** `cookie_custom_domain = "other.com"`, **Then** sanitisation stores `host`, records a settings error, and `cookieDomain = ""`.

---

### User Story 3 - One-time migration from host-only to domain-scoped cookies (Priority: P2)

A returning visitor on a site that just switched to `registrable` keeps their attribution: the host-only cookie value is copied into the domain-scoped cookie once and the host-only copy is deleted, so two cookies with the same name never diverge.

**Independent Test**: same VM harness, jar pre-seeded with a host-only `attribution`.

**Acceptance Scenarios**:

1. **Given** `localStorage['ct_cookie_scope']` is absent or differs from the resolved domain and a host-only `attribution` exists, **When** the script boots, **Then** it reads the value, expires the host-only cookie (no `domain`), writes the value with `domain=example.com` and stores `ct_cookie_scope = "example.com"`.
2. **Given** `ct_cookie_scope` already equals the resolved domain, **Then** no migration write happens.
3. **Given** the admin switches back to `host` and `ct_cookie_scope = "example.com"`, **Then** the script expires every plugin cookie with `domain=example.com`, rewrites the values host-only, and stores `ct_cookie_scope = ""`.

---

### User Story 4 - Public-suffix safety (Priority: P1)

A site on `brand.co.uk` or `team.github.io` never receives `domain=co.uk` or `domain=github.io`. The resolver falls back to `host` and the reason is visible in diagnostics.

**Independent Test**: PHPUnit table test on `Cookie_Scope::resolve()`; VM test confirms `cookieDomain = ""` yields host-only writes.

**Acceptance Scenarios**:

1. **Given** host `brand.co.uk`, **Then** `registrable` resolves to `brand.co.uk` (3-label rule from the decorator heuristic) and is accepted.
2. **Given** host `team.github.io`, **Then** the heuristic yields `github.io`, which is in the suffix table, so the result is `""` with reason `public_suffix`.
3. **Given** host `127.0.0.1`, `localhost` or any single-label host, **Then** the result is `""` with reason `ip_or_single_label`.
4. **Given** a site filters `clicutcl_public_suffixes` to add `example.org`, **Then** host `a.example.org` resolves to `""` with reason `public_suffix`.

---

### User Story 5 - Server re-issue uses the same scope (Priority: P2, cross-reference)

When #141 adds a PHP re-issue of the attribution cookie, it calls `Cookie_Scope::resolve()` and passes the result as the `domain` option of `setcookie()`. This story only fixes the contract; #141 owns the implementation.

---

### Edge Cases

- `removeCookie` must send the same `domain` as the write; a host-only expiry does not delete a domain cookie (RFC 6265 treats them as distinct cookies).
- Switching `registrable` back to `host` leaves the domain cookie in place; the browser sends both and the host-only one is not guaranteed to be first in `document.cookie`. US3-3 cleanup is mandatory, not optional.
- localStorage mirror (`Store.setLocalData`, `clicutcl-attribution.js:633`) is per-origin. After a hop the new host has no mirror; `getCookie` is the only source. Kept as is: the cookie is canonical and the mirror only backs it up when the cookie is blocked or too large.
- IP hosts, `localhost`, and single-label intranet hosts: browsers reject or ignore `domain`; always host-only.
- Multisite subdomain networks: `registrable` scopes cookies to the whole network (`site1.network.com` and `site2.network.com` share `attribution`). The activation seed uses `host` when `is_multisite()` is true and the network is a subdomain install.
- `Secure` stays protocol-dependent (`clicutcl-attribution.js:591`); a domain cookie written over http is also sent to https hosts of the same domain, which is unchanged from today's host-only behaviour on a mixed site.
- localStorage mirror (`Store.setLocalData`, `:632-650`) is per-origin. After a hop the new host has no mirror; `getCookie` is the only source. Kept as is: the cookie is canonical and the mirror only backs it up when the cookie is blocked or too large.
- Consent cookie `ct_consent` is written by `clicutcl-consent.js:165-174` and cleared by the bridge (`clicutcl-consent-bridge.js:119-123`); both must use the same domain or a grant on `www` is unknown on the apex.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: A new attribution setting `cookie_scope` MUST accept `host`, `registrable`, `custom`; `cookie_custom_domain` MUST be stored lowercase without a leading dot and MUST be a suffix of the `home_url()` host, else the scope MUST be sanitised to `host`.
- **FR-002**: The schema default MUST be `host` (existing arrays merge to today's behaviour). Activation and the setup wizard MUST seed `registrable` only when `clicutcl_attribution_settings` does not exist yet, and `host` on subdomain multisite networks.
- **FR-003**: PHP `Cookie_Scope::resolve()` MUST return `['domain' => string, 'reason' => string]`, MUST port the decorator's `getRegistrableDomain()` rule (`clicutcl-attribution.js:1351-1359`), MUST return `""` for IP, `localhost`, single-label hosts and any result listed by `clicutcl_public_suffixes`, and MUST be the only place that computes the domain.
- **FR-004**: The resolved domain MUST be localized as `cookieDomain` in `clicutcl_config`, `ctConsentBridgeConfig`, `clicutclConsentL10n` and `clicutclEventsConfig`.
- **FR-005**: Every JS cookie writer and remover (`Store.setCookie` / `Store.removeCookie`, `clicutcl-events.js` `setCookie` / `removeCookie`, `clicutcl-consent.js` `setCookie`, bridge `writeServerCookie` / `clearPluginCookie`) MUST append `; domain=<cookieDomain>` when it is non-empty and a suffix of `location.hostname`, and MUST omit it otherwise.
- **FR-006**: `clicutcl-attribution.js` MUST run the US3 migration once per origin, keyed by `localStorage['ct_cookie_scope']`, over the fixed list `attribution`, `ct_attribution`, `ct_visitor_id`, `ct_session_id`, `ct_session`, `ct_consent` (configured name), `ct_consent_state`, each with its own retention.
- **FR-007**: `Attribution_Provider::get_payload()` and the other `$_COOKIE` readers MUST NOT change; the browser sends the cookie regardless of scope.
- **FR-008**: The fallback reason MUST be visible in Site Health (`includes/admin/class-sitehealth.php`) and in the debug log when `debug` is active.
- **FR-009**: Docs MUST be updated: `DATA-MODEL.md` client-side storage, `SECURITY-PRIVACY.md` storage inventory (new "Scope" column), `IMPLEMENTATION-PLAYBOOK.md` cross-domain section (scope vs token), `HOOKS-REFERENCE.md` (`clicutcl_public_suffixes`), `docs/guides/SETTINGS-AND-ADMIN.md`, `readme.txt`, `README.en.md`, `changelog.txt`.

### Key Entities

- **cookie_scope**: `host` | `registrable` | `custom`, stored in `clicutcl_attribution_settings`.
- **cookieDomain**: resolved string (`""` means host-only), localized to all four scripts.
- **ct_cookie_scope**: per-origin localStorage marker holding the last applied domain.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: VM test: 100% of writes and expiries for the seven cookie names in FR-006 carry the identical `domain` attribute under `registrable`, and 0 carry one under `host`; the legacy `ct_attribution` expiry (`clicutcl-attribution.js:675-680`) is asserted explicitly.
- **SC-002**: VM test: after migration exactly one `attribution` cookie remains in the jar and its value equals the pre-migration host-only value; a second boot performs 0 cookie writes for migration.
- **SC-003**: PHPUnit: `co.uk`, `github.io`, `127.0.0.1`, `localhost` and a filtered suffix all resolve to `""`; `www.example.com` resolves to `example.com`; `custom = other.com` on `www.example.com` sanitises to `host`.
- **SC-004**: Live: a tagged landing on `www.<site>` followed by a CF7 submission on `<site>` apex shows `ct_gclid` in the entry. Until this passes the feature stays runtime-unverified.

## Assumptions

- The decorator heuristic (`co`, `com`, `net`, `org`, `gov`, `edu`, `ac`, `ne`, `or`, `me` as second-level labels) is good enough when paired with an explicit suffix table; a full Public Suffix List is out of scope.
- `home_url()` host is the host visitors use; the JS suffix guard (US1-4) covers the cases where it is not.
- Spec 004 (competitor review) and #141 land independently; this spec does not block on them.
