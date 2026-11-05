# Implementation Plan: Site Tag Audit (static check of what the site's own HTML loads)

**Branch**: `feat/site-tag-audit` | **Date**: 2026-11-04 | **Spec**: [spec.md](spec.md) | **Issue**: none (field pattern)

## Summary

A manual "Tag audit" in Diagnostics. An admin AJAX action fetches up to 6 own-host URLs server-side (home, a `?ct_audit=<token>` cache-bust twin, an optional form page, `/` of each validated `audit_extra_hosts` entry) through one allowlisted `wp_safe_remote_get` call, parses the HTML with a pure parser (script/iframe/anchor tag scan plus bounded regexes), and a pure rules class turns the facts into findings: duplicate GTM / GA4 / Meta loads, several CMPs, cache parity (plain vs twin), observed vs ClickTrail-injected container and loader host, call-tracking and `tel:` consistency. The redacted report (no body) is stored in one non-autoloaded option and surfaced as a single Site Health test that only reads it. Everything a browser must prove goes to the sibling repo `clicktrail-verify`.

## Technical Context

**Language/Version**: PHP 8.1+; one small block of ES2019 in `assets/js/admin-diagnostics.js` (no build step)
**Primary Dependencies**: none new (no HTML5 parser package; `preg_*` and `DOMDocument` are not required)
**Storage**: option `clicutcl_tag_audit_report` (autoload false, one row, at most 64 KB); transient `clicutcl_tag_audit_lock` (30 s); setting `diagnostics.audit_extra_hosts` in `clicutcl_tracking_v2`. No cookies, no browser storage, no new table
**Network**: only to `home_url()` host and validated own hosts, admin-initiated, never from the front end, Site Health or cron
**Testing**: PHPUnit `tests/unit/TagAuditParserTest.php`, `TagAuditRulesTest.php`, `TagAuditFetcherTest.php`, `TagAuditAjaxTest.php`, `TagAuditSettingsTest.php`, `TagAuditSiteHealthTest.php` over `tests/fixtures/tag-audit/*.html`; Node VM `tools/qa/tag-audit-ui.test.js`; E2E S9 `tag_audit`; `npm run smoke`
**Constraints**: setting empty by default (no seed needed); no consent surface touched; no output on any front-end request

## Repo gates

- AGENTS.md docs triggers (new setting, new filters, outbound network, stored option): `docs/guides/SETTINGS-AND-ADMIN.md`, `docs/guides/SECURITY-PRIVACY.md`, `docs/guides/OPERATIONS-RUNBOOK.md` (Conflict Scan section, `:304`), `docs/reference/HOOKS-REFERENCE.md`, `docs/architecture/DATA-MODEL.md`, `docs/architecture/CODE-MAP.md`, `readme.txt`, `README.en.md`, `changelog.txt`. `INTEGRATIONS.md` and `integration-capabilities.json`: unchanged (not an integration).
- CodeQL: the allowlist check and `wp_safe_remote_get` live in one function (`Tag_Audit_Fetcher::fetch`); the URL reaching the sink is the validated string, rebuilt from parsed parts.
- Evidence boundary: source-present / runtime-unverified until SC-004 (S9) passes in `tools/qa/e2e/`, run under `flock /tmp/ct-e2e.lock`.

## Design

### Server

| Change | File |
|---|---|
| `Tag_Audit_Fetcher::allowed_hosts( $home_host, $extra )` and `::fetch( $url, $allowed )`: validate scheme/host/userinfo/port, `wp_safe_remote_get` (`timeout 5`, `redirection 0`, `limit_response_size 1048576`, `cookies []`, `Cache-Control: no-cache`, UA `ClickTrail-TagAudit/<version>`), manual follow of at most 2 redirects re-validated each hop; returns status, truncated flag, body, final host, a whitelist of cache hint headers (`age`, `cf-cache-status`, `x-cache`, `x-litespeed-cache`), never raw headers | `includes/admin/class-tag-audit-fetcher.php` (new) |
| `Tag_Audit_Parser::parse( $html )`: pure, returns facts (see Contract); inert-script detection (`type=text/plain`, `data-cookieconsent`, CMP block markers) marks loads `gated`; `preg_last_error()` becomes `parse_error` | `includes/admin/class-tag-audit-parser.php` (new) |
| `Tag_Audit_Rules::evaluate( $facts_by_target, $context )`: pure, returns findings sorted high, medium, info, capped at 50; `$context` = injected container ID, `build_script_src()` host, GA4 IDs, `cmp_source`, detected CMP plugins, call-tracking plugins, caching labels | `includes/admin/class-tag-audit-rules.php` (new) |
| `Tag_Audit::run( $form_url )`: builds targets, enforces the 20 s deadline and the 6-fetch cap, builds `$context`, stores the report (`update_option( ..., false )`, trims `info` first to stay under 64 KB); `::last_report()` | `includes/admin/class-tag-audit.php` (new) |
| `ajax_tag_audit()`: `manage_options`, `check_ajax_referer( 'clicutcl_diag', 'nonce' )`, transient lock (429), optional `form_url` validated against the same allowlist (400), returns `{message, html, report}` | `includes/admin/traits/trait-admin-diagnostics-ajax.php` (next to `:317`), hook beside `class-admin.php:70` |
| Signature tables with filters `clicutcl_tag_audit_cmp_signatures`, `clicutcl_tag_audit_call_tracking_signatures`; no filter for targets | `includes/admin/class-tag-audit-parser.php` |
| `audit_extra_hosts`: default `array()` under `diagnostics`, sanitise via `sanitize_hosts_list()` then keep only hosts equal to or under the `home_url()` registrable domain (`Cookie_Scope::registrable_domain()`, `is_public_suffix()`); rejected entries raise `add_settings_error` | `includes/tracking/class-settings.php:110-114`, `:236-248`; admin mapping `includes/admin/class-admin.php:682-684`, `:1104-1108` |
| Site Health test `clicutcl_tag_audit` (reads the option, no HTTP) | `includes/admin/class-sitehealth.php:37-53` |
| Option cleanup | `uninstall.php:20-48` |

### Admin UI

| Change | File |
|---|---|
| "Tag audit" card after the Conflict Scan card: Run button, optional form-URL input, status span, result list, "what this cannot see" footer, last-run timestamp | `includes/admin/traits/trait-admin-pages.php:279-298` |
| `render_tag_audit_results( $report )`: every string through `esc_html`, evidence in `<code>`; never `wp_kses_post` on evidence | same file, next to `:698` |
| Click handler posting `clicutcl_tag_audit` with `nonce` and `form_url`, disabling the button while running | `assets/js/admin-diagnostics.js` (after `:176`) |
| `audit_extra_hosts` textarea (one host per line) next to `delivery.advanced.allowed_token_hosts` / `dispatch_buffer_size` | `assets/js/admin-settings-app.js:1125-1126`, `includes/admin/class-admin.php:682-684` |

### Contract: finding codes

```text
high   gtm_duplicate_load  ga4_duplicate_config  meta_duplicate_init  cmp_multiple  cache_untagged  gtm_expected_missing
medium gtm_multiple_containers  gtm_duplicate_noscript  gtm_unexpected_container  gtag_beside_gtm  ga4_multiple_ids
       meta_multiple_ids  cmp_source_mismatch  loader_google_host  call_tracking_host_gap  tel_mismatch  host_untagged
info   cache_twin_untagged  cache_bust_ineffective  cmp_plugin_not_rendered  ga4_unexpected_id  loader_unparsed
       tel_text_only  fetch_failed  fetch_blocked  parse_error  fbevents_duplicate_src
downgrades: gtm_expected_missing -> info when truncated, or twin also lacks it and caching is empty; gated+active duplicate -> medium
```

```text
facts[target] = { status, bytes, truncated, loads: { "gtm:GTM-AAAA": {active, gated, hosts[]}, "ga4:G-ONE": {...},
                  "aw:AW-1": {...}, "meta:111": {...} }, noscript: { "GTM-AAAA": n }, cmp: [family], call_tracking: [provider],
                  tel: [{href_digits_masked, text_digits_masked}], text_phones: n, loader_unparsed: n, cache_hint: {...} }
report = { v: 1, ran_at, targets: [{label, host, status, bytes, truncated, skipped?}], findings: [...], blind_spots: [...] }
allowlist: host == home_host | host endsWith "." + registrable(home_host) [unless IP / public suffix: exact only]
           reject: userinfo, port not in {80,443,home port}, scheme not http/https, scheme-relative, every redirect hop re-checked
```

## Risks

- Static HTML misleads: a cache, WAF or geo rule changes what the server sees, and GTM adds tags at runtime. Mitigation: findings say "static HTML", the blind-spot list is on every report, absence is never reported as proof.
- Core's `wp_http_validate_url()` resolves the name before the request, so DNS rebinding between check and connect is not closed by core (source: `wp-includes/http.php:559-670`). Mitigation: own-host allowlist, no credentials, body never echoed or stored, admin-only.
- The twin URL can warm a cache entry per run; `no-cache` request header, random token, 30 s lock, 6 fetches.
- Signature drift (CMP, call-tracking vendors rename hosts); fixtures pin today's shapes and the two filters let a site add its own.
- Phone detection false positives (dates, order numbers): `info` only, 9-digit floor, three reported.
- Regex cost on 1 MB: bounded quantifiers, scan per tag not per document, `parse_error` on PCRE failure.
- ClickTrail's GTM output is not self-labelling in a locale-proof way (comments are translated, `class-web-tag.php:97-102`); ownership of a duplicate is inferred by ID + loader URL, so it can be wrong when a theme emits the identical snippet. Decision pending in research R4.
