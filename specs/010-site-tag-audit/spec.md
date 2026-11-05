# Feature Specification: Site Tag Audit (static check of what the site's own HTML loads)

**Feature Branch**: `feat/site-tag-audit`

**Created**: 2026-11-04

**Status**: Draft, not started. Every claim below is source-present / runtime-unverified: current behaviour was read from `main` @ `e00552c` (1.12.0 plus unreleased 1.13 work); nothing was run against a real site, a real CDN or a real CMP.

**Input**: Field pattern, not a single issue. Sites routinely ship broken tag plumbing that no plugin reports to the owner: GTM loaded twice (a framework component plus a first-party loader, or theme plus plugin), a hard-coded gtag/GA4 snippet beside GTM, two GA4 measurement IDs, two Meta base-code inits, two or three consent platforms at once, a second GTM container the site owner does not control, a GTM snippet missing from the full-page-cached home page while a cache-busted URL is tagged, `gtm.js` served from Google while a first-party loader is configured, untagged landing pages or subdomains, a call-tracking script on some hosts only, a `tel:` link that dials a different number than the visible one, duplicate GTM noscript iframes. ClickTrail's Conflict Scan (`includes/admin/traits/trait-admin-diagnostics-ajax.php:789-966`) only reads PHP-side state (constants, active plugin slugs, ClickTrail's own settings); it never looks at the HTML a visitor receives.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Duplicate GTM, GA4 and Meta loads are named (Priority: P1)

Duplicates silently double-count page views and conversions and nothing in the plugin sees them today: `Web_Tag` guards only its own noscript output (`includes/Modules/GTM/class-web-tag.php:110-113`). The admin runs "Tag audit" in Diagnostics and is told which container, measurement or pixel ID is loaded more than once on a page, and which IDs differ.

**Independent Test**: PHPUnit (`tests/unit/TagAuditParserTest.php`, `TagAuditRulesTest.php`) over HTML fixtures in `tests/fixtures/tag-audit/`, one per pattern, plus `clean.html` and `consent-gated.html`.

**Acceptance Scenarios**:

1. **Given** a page with the GTM bootstrap twice for `GTM-AAAA` (inline snippet plus a `<script src=".../gtm.js?id=GTM-AAAA">`), **Then** finding `gtm_duplicate_load`, severity `high`, count 2, evidence is the truncated matched tag.
2. **Given** two different container IDs on one page, **Then** `gtm_multiple_containers`, severity `medium`, hint to confirm ownership in the Tag Manager account (ownership cannot be checked statically).
3. **Given** two `<iframe src=".../ns.html?id=GTM-AAAA">` noscript frames, **Then** `gtm_duplicate_noscript`, severity `medium`.
4. **Given** `gtag/js?id=G-ONE` plus a GTM container on the same page, **Then** `gtag_beside_gtm`, severity `medium`; **given** `gtag('config','G-ONE')` twice, **Then** `ga4_duplicate_config`, `high`; **given** `G-ONE` and `G-TWO`, **Then** `ga4_multiple_ids`, `medium`.
5. **Given** `fbq('init','111')` twice, **Then** `meta_duplicate_init`, `high`; two different pixel IDs, `meta_multiple_ids`, `medium`. A duplicated `fbevents.js` src alone is `fbevents_duplicate_src`, `info` (the base code returns early when `fbq` exists).
6. **Given** ClickTrail's own output on its own (`clicktrail-consent-mode` inline script defining `function gtag` and `gtag('consent','default',...)`, `includes/Modules/consent-mode/class-consent-mode.php:103-112`, plus its GTM bootstrap), **Then** zero findings: only `gtag/js?id=` loads and `gtag('config', ...)` calls count as gtag.
7. **Given** an inert script (`type="text/plain"`, `data-cookieconsent`, or another CMP block marker) that would load a counted ID after consent, **Then** it counts as `gated`; a duplicate of one active plus one gated load is reported as `medium` with "duplicates after consent".

### User Story 2 - More than one consent platform is named (Priority: P1)

**Independent Test**: PHPUnit parser fixtures `multi-cmp.html` (two families), `cmp-single.html`, `consent-gated.html`.

**Acceptance Scenarios**:

1. **Given** signatures of two or more CMP families (CookieYes, Cookiebot, OneTrust, Complianz, Usercentrics, Termly, Iubenda, Shopify consent markers), **Then** `cmp_multiple`, severity `high`, listing the families; one family gives no finding. No opinion is given on which CMP to use.
2. **Given** `cmp_source` in Consent Mode settings names a family (`includes/Modules/consent-mode/class-consent-mode-settings.php:211`) and the page shows a different single family, **Then** `cmp_source_mismatch`, `medium`.
3. **Given** `Setup_Detector::detect_cmp()` reports a CMP plugin but no signature of it appears in the HTML, **Then** `cmp_plugin_not_rendered`, `info` (optimizer or cache may hide it, or it loads after interaction).

### User Story 3 - A cached page served untagged, or an untagged host, is caught (Priority: P1)

**Independent Test**: PHPUnit with fixture pairs `cached-untagged.html` / `fresh-tagged.html`; E2E S9.

**Acceptance Scenarios**:

1. **Given** the plain home URL lacks a tag signal (container, measurement or pixel ID, CMP family) that the `?ct_audit=<token>` twin carries, **Then** `cache_untagged`, severity `high`, listing the missing signals, with `detect_caching()` labels in the detail (`includes/admin/class-setup-detector.php:181`).
2. **Given** the plain URL has a signal the twin lacks, **Then** `cache_twin_untagged`, `info` (stale cache pre-dating a removal, or the twin was challenged).
3. **Given** both responses are identical and carry the same `Age` header, **Then** `cache_bust_ineffective`, `info`, parity is reported unknown, not passed.
4. **Given** the twin redirects to a URL that drops the parameter, **Then** the redirect is followed only inside the allowlist and the same `cache_bust_ineffective` applies.
5. **Given** an `audit_extra_hosts` host (a landing subdomain) whose `/` carries none of the GTM, GA4 or Meta signals the home page carries, **Then** `host_untagged`, `medium`, naming the host and the missing signals.

### User Story 4 - What the HTML loads vs what ClickTrail injects (Priority: P2)

**Independent Test**: PHPUnit rules test with stubbed `GTM_Settings` (the `tests/helpers/gtm-settings-stub.php` pattern), fixtures `clicktrail-gtm-duplicated.html`, `second-container.html`, `google-loader-first-party-configured.html`.

**Acceptance Scenarios**:

1. **Given** ClickTrail injects `GTM-AAAA` (`GTM_Settings::get_container_id()`, `class-gtm-settings.php:138`) and the HTML loads `GTM-AAAA` twice, **Then** `gtm_duplicate_load` names the second copy as "not added by ClickTrail" when the page has exactly one copy matching `GTM_Settings::build_script_src()` output; the English marker comments (`class-web-tag.php:97-102`) are a hint only, because they are translated.
2. **Given** ClickTrail injects `GTM-AAAA` and the HTML also loads `GTM-BBBB`, **Then** `gtm_unexpected_container` for `GTM-BBBB`, `medium`; if ClickTrail has no container configured, the same ID is `info` "site-owned container".
3. **Given** sGTM mode with `first_party_script` or a custom loader (`build_script_src()`, `class-gtm-settings.php:222-245`) and the HTML loads `googletagmanager.com/gtm.js?id=<same ID>`, **Then** `loader_google_host`, `medium`, with the configured loader host in the detail.
4. **Given** ClickTrail has a container ID and the plain URL has no copy of it, **Then** `gtm_expected_missing`, `high` (`info` when the twin also lacks it and `detect_caching()` is empty: GTM may be deliberately delayed by an optimizer).
5. **Given** an observed GA4 ID that is not in `Attribution_Settings::ga4_measurement_ids()` (`includes/settings/class-attribution-settings.php:82`) while that setting is non-empty, **Then** `ga4_unexpected_id`, `info`.

### User Story 5 - Call tracking and phone links agree (Priority: P2)

**Independent Test**: PHPUnit fixtures `call-tracking-one-host.html`, `tel-mismatch.html`, `tel-plain-text.html`.

**Acceptance Scenarios**:

1. **Given** CallRail, CallTrackingMetrics or WhatConverts script signatures on one audited host and none on another, **Then** `call_tracking_host_gap`, `medium`, naming both hosts; a hard-coded number cannot be told from a pool number statically, so the hint says numbers on the host without the script will not be swapped.
2. **Given** `<a href="tel:+351900000001">` whose visible text carries a different number (compared by digits, the shorter being a suffix of the longer, 7 digits minimum), **Then** `tel_mismatch`, `medium`, evidence masks all but the last three digits.
3. **Given** a visible phone-like string (9 digits minimum, not a date or price) outside any `tel:` link, **Then** `tel_text_only`, `info`, at most three reported.

### User Story 6 - The last report reaches Site Health (Priority: P2)

**Independent Test**: PHPUnit (`TagAuditSiteHealthTest.php`) calling the test method with a stubbed option.

**Acceptance Scenarios**:

1. **Given** a stored report with no `high` or `medium` finding, **Then** the test `clicutcl_tag_audit` returns `good`; **given** at least one, **Then** `recommended`, the description lists up to five titles, the audit time and a link to Diagnostics.
2. **Given** no stored report, **Then** `good` with the label "Tag audit not run yet", and the test makes no HTTP request, ever (Site Health runs on page load).
3. **Given** a report older than 30 days, **Then** the status follows its findings and the description says how old it is.

### User Story 7 - The audit cannot be turned against the site or a third party (Priority: P1)

**Independent Test**: PHPUnit (`TagAuditFetcherTest.php`, `TagAuditAjaxTest.php`, `TagAuditSettingsTest.php`) with a recording `wp_safe_remote_get` stub; E2E S9 canary checks.

**Acceptance Scenarios**:

1. **Given** no `manage_options`, a bad `clicutcl_diag` nonce, or a second run within the 30 s lock, **Then** 403 / 403 / 429 and no request is made.
2. **Given** any target whose host is not the `home_url()` host, a host under its registrable domain, or a validated `audit_extra_hosts` entry (userinfo, `home.evil.com`, IP literal, `file://`, a scheme-relative URL, a port outside 80/443/home port), **Then** it is rejected before the request; the same check runs on every redirect hop (at most 2).
3. **Given** a response over 1 MB, **Then** it is truncated, the target is marked `truncated`, and findings that depend on the tail carry "partial page"; **given** a non-HTML content type or status 4xx/5xx, **Then** one `fetch_failed` / `fetch_blocked` finding (`info`) and no parse.
4. **Given** the request, **Then** it carries no `Cookie`, `Authorization` or admin header, uses `wp_safe_remote_get`, 5 s timeout, and the response body is never returned to the browser or stored; only counts, IDs, hosts and redacted snippets (160 characters, query strings removed) are.
5. **Given** `audit_extra_hosts = ["lp.example.com", "evil.com", "10.0.0.5"]` on `www.example.com`, **Then** only `lp.example.com` is stored; the others raise a settings error.

### Edge Cases

- Bot challenges, WAF blocks (403/503), HTTP auth (401) and maintenance pages are `fetch_blocked`, never "no tags found". Geo-dependent banners and A/B tests: absence from the server's view is not proof of absence (FR-012).
- A first-party loader with an obfuscated or path-only URL has no parseable ID: `loader_unparsed`, `info`, counted only through its inline bootstrap.
- `home_url()` host a bare IP (local or E2E): allowlist is that exact host. Shared-hosting suffixes (`Cookie_Scope::is_public_suffix()`, `includes/Core/class-cookie-scope.php:206`) also resolve to exact host only.
- Page over 1 MB: tags after the cut are unseen, so `gtm_expected_missing` drops to `info` when `truncated`. Multisite: the audit uses the current site's `home_url()` and the report option is per site.
- A cache that ignores query strings makes the twin identical to the plain URL (US3-3). A cache keyed on the query string gets one extra entry per run; the request sends `Cache-Control: no-cache` and the token is random per run.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The audit MUST run only from an admin AJAX action (`clicutcl_tag_audit`) behind `manage_options` and the `clicutcl_diag` nonce (the pattern of `ajax_conflict_scan`, `trait-admin-diagnostics-ajax.php:317-331`), MUST NOT run on the front end, on Site Health load, on cron or on activation, and MUST be rate limited by a 30 s transient lock.
- **FR-002**: Targets MUST be: `home_url('/')`, its `?ct_audit=<random 12 chars>` twin, an optional admin-entered form URL, and `/` of each `audit_extra_hosts` entry; at most 6 fetches per run and a 20 s total deadline (remaining targets `skipped_deadline`).
- **FR-003**: One function MUST own the allowlist and the request (validate then fetch in the same function, so CodeQL sees the sink): scheme http/https, host equal to the `home_url()` host or under its registrable domain (exact host only when the suffix check or an IP host says so), no userinfo, ports 80/443/home port, `wp_safe_remote_get` with `timeout 5`, `redirection 0` (manual follow, at most 2, each hop re-validated), `limit_response_size` 1 048 576, no cookies, no auth, `Cache-Control: no-cache`.
- **FR-004**: The parser MUST extract: `gtm.js` / `gtag/js` / `analytics.js` / `fbevents.js` script URLs; inline GTM bootstrap calls; `GTM-`, `G-`, `AW-` IDs; `fbq('init', ...)` IDs; noscript `ns.html` iframes; CMP signatures; call-tracking signatures; `tel:` anchors and visible phone-like text. Counts MUST be per ID and per load kind (`active`, `gated`), with the host of each loader. Malformed HTML and `preg_last_error()` MUST produce a `parse_error` finding, not an exception.
- **FR-005**: Findings MUST be `{code, severity (high|medium|info), target, title, detail, evidence, fix}` with a stable code from this spec; `evidence` redacted per US7-4; at most 50 findings per report.
- **FR-006**: The rules MUST implement the codes in US1-US5 and compare plain vs twin (US3) and observed vs injected (US4) using `GTM_Settings`, `Attribution_Settings::ga4_measurement_ids()`, `Consent_Mode_Settings::get_cmp_source()`, `Setup_Detector::detect_cmp()`, `detect_call_tracking()` and `detect_caching()` as inputs.
- **FR-007**: The last report MUST be stored in option `clicutcl_tag_audit_report`, `autoload` false, with `ran_at` (UTC timestamp), per-target status, size and truncation flags, findings and the blind-spot list; one row, at most 64 KB serialized (findings trimmed from the `info` end first). The option MUST be listed in `uninstall.php` (`:20-48`).
- **FR-008**: Site Health MUST gain one direct test `clicutcl_tag_audit` in `SiteHealth::add_tests()` (`includes/admin/class-sitehealth.php:37-53`) that reads the stored option only (US6).
- **FR-009**: Diagnostics MUST gain a "Tag audit" card next to the Conflict Scan card (`includes/admin/traits/trait-admin-pages.php:279-298`) with a Run button, an optional form-URL input and a result list rendered through `esc_html` only (US7-4); `assets/js/admin-diagnostics.js` posts the action like the scan handler (`:152-176`).
- **FR-010**: New setting `audit_extra_hosts` (list of hostnames, default empty) MUST live in `clicutcl_tracking_v2` under `diagnostics`, be sanitised like `sanitize_hosts_list()` (`includes/tracking/class-settings.php:923`) and then each entry MUST be a hostname under the registrable domain of `home_url()`, else dropped with a settings error. There is no on/off switch and nothing to seed: the audit is manual, so existing and fresh installs behave identically.
- **FR-011**: CMP and call-tracking signature tables MUST be filterable (`clicutcl_tag_audit_cmp_signatures`, `clicutcl_tag_audit_call_tracking_signatures`); there MUST NOT be a filter that adds fetch targets.
- **FR-012**: Every report MUST list what static HTML cannot see (tags injected by GTM at runtime, consent-gated behaviour after a grant, real network hits, dataLayer content, JS number swapping, client-rendered pages) and name `clicktrail-verify` for those checks. The audit MUST NOT execute JS, change the site, call a third party, write cookies or storage, send `Set-Cookie` or add front-end output; `ct_audit` is read by nothing in ClickTrail.
- **FR-013**: Docs MUST be updated: `SECURITY-PRIVACY.md` (new "Tag audit" section: outbound request to own hosts, stored option), `SETTINGS-AND-ADMIN.md`, `OPERATIONS-RUNBOOK.md` (Conflict Scan section), `HOOKS-REFERENCE.md`, `DATA-MODEL.md`, `CODE-MAP.md`, `readme.txt`, `README.en.md`, `changelog.txt`. `INTEGRATIONS.md` and `integration-capabilities.json` are intentionally unchanged: this is a diagnostic, not an integration.

### Key Entities

- **Audit target**: `{label: home|twin|form|extra, url, host}` after allowlist validation. **Tag facts**: per target and signal (`gtm:<ID>`, `ga4:<ID>`, `aw:<ID>`, `meta:<ID>`, `cmp:<family>`, `calltracking:<provider>`), counts by load kind and loader host. **Report**: FR-005, FR-007.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: PHPUnit: each fixture yields exactly its expected finding codes and no others; `clean.html`, `consent-gated.html` and ClickTrail's own output yield 0.
- **SC-002**: PHPUnit: the allowlist table in US7-2 rejects 100% of listed hostile targets with 0 calls to the `wp_safe_remote_get` stub; the recorded request for an allowed target has no `Cookie` / `Authorization` header and the stated timeout and size cap.
- **SC-003**: PHPUnit: the stored option is non-autoloaded, at most 64 KB, and contains no response body (a canary string outside any evidence window never appears).
- **SC-004**: E2E S9 `tag_audit` in `tools/qa/e2e` (one shared pod, `flock /tmp/ct-e2e.lock`): the duplicate-GTM and untagged-cached-twin fixture pair yields `gtm_duplicate_load` and `cache_untagged`, a hostile `form_url` is rejected with no request logged, and the AJAX response never contains the planted canary. S9 serves a simulated cache from a local Apache/PHP pod; until a real full-page cache and CDN are checked (research Q5) the feature stays runtime-unverified.
- **SC-005**: Site Health: `good` / `recommended` mapping, zero HTTP calls from the test method.

## Assumptions

- Static HTML is enough for the plumbing mistakes listed; runtime-injected tags need a browser and belong in `clicktrail-verify`, whose runner records requests and `dataLayer` per consent state.
- The server can reach its own public URL; if not, the audit reports `fetch_failed`. CMP and call-tracking signature strings are maintained here and pinned by fixtures; they are not vendor contracts.
