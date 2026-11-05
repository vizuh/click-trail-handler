# Research: Site Tag Audit (static check of what the site's own HTML loads)

Source read on 2026-11-04 (`main` @ `e00552c`, 1.12.0 plus unreleased 1.13 work). Nothing below was run against a live site, a CDN or a CMP; every row is source-present / runtime-unverified. The WordPress core facts were read in the E2E pod's WordPress 7.1 tree (`wp-includes/`), the plugin's declared minimum is 6.5 (`readme.txt:6`).

Vendor references (quotes in the last section):

- Install a web container: https://support.google.com/tagmanager/answer/14847097
- Google tag (gtag.js): https://developers.google.com/tag-platform/gtagjs
- Meta Pixel base code: https://developers.facebook.com/documentation/meta-pixel/get-started
- Cookiebot manual blocking: https://support.cookiebot.com/hc/en-us/articles/360009063660

## Current state

| Surface | File | Finding |
|---|---|---|
| Conflict scan action | `includes/admin/traits/trait-admin-diagnostics-ajax.php:317-331`, hook `includes/admin/class-admin.php:70` | `manage_options`, `check_ajax_referer( 'clicutcl_diag', 'nonce' )`, returns `{message, html, report}`. Nonce created at `class-admin.php:271`. |
| Conflict scan content | `trait-admin-diagnostics-ajax.php:789-966` | Reads only PHP state (constants, plugin slugs, ClickTrail settings). Findings `{severity, title, detail}`, optional `target_tab`. Never fetches a page; the runbook calls it "deterministic local checks" (`docs/guides/OPERATIONS-RUNBOOK.md:304-320`). |
| Existing GTM/destination finding | `:933-944` | Only "Review duplicate destination ownership" (`info`) when GTM plus native destinations are on. No look at the HTML. |
| Cache detection | `includes/admin/class-setup-detector.php:181-214` and the trait copy `trait-admin-diagnostics-ajax.php:1085-1114` | Two copies already drift: Setup_Detector adds SiteGround Optimizer and W3 Total Cache, the trait adds NitroPack. The audit consumes `Setup_Detector::detect_caching()`. |
| CMP detection | `class-setup-detector.php:233-293` | Plugin slug / constant only, four families (CookieYes, Cookiebot, OneTrust, Complianz). Nothing for Usercentrics, Termly, Iubenda, or a CMP pasted into the theme. |
| Call-tracking detection | `class-setup-detector.php:323-352`, trait `:1124-1126` | Plugin slug / constant only; a script pasted into a theme or GTM is invisible. |
| GTM output | `includes/Modules/GTM/class-web-tag.php:72-74` (hooks), `:96-102` (marker comments), `:108-113` (noscript once per request via a constant) | Bootstrap passes the full loader URL as the last IIFE argument, not the bare ID. Comments go through `__()`, so they change with the site locale. No stable id or data attribute. |
| GTM settings | `includes/Modules/GTM/class-gtm-settings.php:56-65` (defaults), `:138` (`get_container_id`), `:222-245` (`build_script_src`), `:251-268` (`build_noscript_src`) | Loader is `googletagmanager.com/gtm.js?id=`, or the sGTM first-party / custom loader. Same-site path loaders are legal (`:290-302`). |
| Consent default snippet | `includes/Modules/consent-mode/class-consent-mode.php:103-114` | Inline `<script id="clicktrail-consent-mode">` defines `function gtag` and calls `gtag('consent','default',...)`. It is not a gtag.js load. |
| Other ClickTrail tag loaders | grep of `includes/` and `assets/js/` for `googletagmanager`, `fbevents`, `connect.facebook` | Only `class-gtm-settings.php`. ClickTrail never loads gtag.js or fbevents.js. |
| GA4 IDs | `includes/settings/class-attribution-settings.php:55-83` | Used only to build `_ga_<ID>` cookie names; not injected anywhere. Usable as "IDs the site told ClickTrail about". |
| `cmp_source` | `includes/Modules/consent-mode/class-consent-mode-settings.php:71`, `:211` | `auto` or a named source; comparable with the page's CMP family. |
| Outbound HTTP | `includes/server-side/class-sgtm-adapter.php:63-66` and sibling adapters (`reject_unsafe_urls`), `includes/admin/class-gtm-lead-magnet.php:366` | Only POSTs. No GET fetch exists; no `wp_safe_remote_get` anywhere in `includes/`. |
| Site Health | `includes/admin/class-sitehealth.php:37-53` | Three `direct` tests; none makes an HTTP call. Option cleanup list `uninstall.php:20-48`. |
| Settings home for a host list | `includes/tracking/class-settings.php:110-114` (`diagnostics`), `:236-248`, `:923` (`sanitize_hosts_list`); admin mapping `class-admin.php:682-684`, `:1104-1108`; UI `assets/js/admin-settings-app.js:1125-1126` | Same pattern as `allowed_token_hosts`. |
| Registrable-domain helper | `includes/Core/class-cookie-scope.php:188` (`registrable_domain`), `:206` (`is_public_suffix`) | Heuristic plus suffix table; reusable for the allowlist. |
| Core URL safety | `wp-includes/http.php:81-85` (`wp_safe_remote_get`), `:559-670` (`wp_http_validate_url`), `class-wp-http.php:284-286`, `:352-354` | Rejects userinfo, `:#?[]` in the host, private / loopback / link-local / CGNAT / reserved IPv4 unless host equals the `home` option host; ports 80/443/8080 plus the home port; redirects re-validated when `reject_unsafe_urls` is set. DNS is resolved by `gethostbyname()` before the request. |
| Response cap | `class-wp-http.php:150`, `:232`, `:366-367`; `class-wp-http-curl.php:181-182`; `class-wp-http-streams.php:279-321` | `limit_response_size` is honoured by both transports and truncates. Availability on 6.5 not read. |

## R1 - Sibling card, not a longer Conflict Scan

**Decision**: new AJAX action and Diagnostics card; the Conflict Scan stays network-free and instant. The audit reuses its detectors and its auth pattern.
**Rationale**: the scan's contract is local and deterministic; adding up to 30 s of outbound requests would change what the existing button can do and what its tests assume. A separate card can show a last-run time and a stored report, which the scan never had.

## R2 - Static HTML, regex tag scanner, no DOM dependency

**Decision**: scan `<script ...>`, `<iframe ...>`, `<a ...>`, `<noscript>` per tag with bounded patterns, keep inline script text only up to the first `</script>` and only the matched window (160 chars), classify inert scripts by `type` and CMP block attributes. No `DOMDocument`, no JS.
**Rationale**: libxml's HTML4 parser mangles `<noscript>` and modern markup, `DOMDocument` is optional on some hosts, and the facts needed (URLs, IDs, counts) are attribute and substring facts. Cost is bounded by a 1 MB input and per-tag scanning; `preg_last_error()` maps to `parse_error`.

## R3 - What counts as a "load"

**Decision**: GTM load = inline bootstrap whose last argument is `GTM-...` or a URL with `id=GTM-...`, or a `<script src>` whose path ends in `/gtm.js` with `id=GTM-...`; GA4 load = `gtag/js?id=G-...` src or `gtag('config','G-...')`; Meta load = `fbq('init','<id>')`; `fbevents.js` src alone is `info`. Count `fbq('init')` per ID, never raw ID occurrences (the base code repeats the ID in the noscript pixel). Inert scripts count as `gated`. `function gtag(){...}` and `gtag('consent', ...)` are not loads.
**Rationale**: ClickTrail's own output must audit clean (SC-001); the Meta base code states the ID appears in two places and returns early if `fbq` exists, so duplicate src is benign but duplicate init double-counts PageView.

## R4 - Who emitted a duplicate

**Decision**: attribute by ID plus loader URL equal to `GTM_Settings::build_script_src()`; use the English marker comment only as a hint. Say "not added by ClickTrail" only when exactly one copy matches.
**Rationale**: comments are translated and optimizers strip comments; a theme pasting the identical snippet is indistinguishable by markup. Counts stay authoritative, attribution is best-effort. Alternative needing a decision: add `data-clicutcl="gtm"` to the bootstrap `<script>` in `Web_Tag::render()` and drop the guessing (changes existing front-end output, so it must pass the S5 `script_attrs` expectations).

## R5 - Cache parity by twin URL

**Decision**: `home_url('/')` plain vs `?ct_audit=<random 12>`; compare the set of signals; parity unknown when the bodies are identical and `Age` matches (cache ignores query strings). Cache hint headers are an allowlist (`age`, `cf-cache-status`, `x-cache`, `x-litespeed-cache`), stored truncated to 40 chars.
**Rationale**: the field failure is "cached home has no GTM, cache-busted URL does". Header names are vendor behaviour read from memory, [INFERENCE], pinned by fixtures only; they never decide a finding alone.

## R6 - Allowlist and request

**Decision**: allowed hosts = `home_url()` host, hosts under its registrable domain, validated `audit_extra_hosts`; exact host only for IP hosts and public suffixes. One function validates and fetches. `redirection 0` with a manual loop (2 hops, each re-validated). Never forward cookies or `Authorization`; never echo or store the body.
**Rationale**: core's validator already blocks private ranges for non-home hosts and re-validates redirects, but it does not know our own-host policy, and DNS rebinding is not closed by it (`http.php:587-596`). Keeping the allowlist narrower than core and the response unobservable bounds what a hostile `form_url` or a rebinding can reveal.

## R7 - Storage and Site Health

**Decision**: one option, non-autoloaded, last report only, 64 KB ceiling, trimmed from `info`; Site Health reads it and never fetches; `good` when never run (see Q1).
**Rationale**: Site Health runs on admin page loads; a network call there would be a hidden outbound request.

## R8 - What stays in `clicktrail-verify`

| Check | Why the plugin cannot do it | Where |
|---|---|---|
| Tags GTM injects at runtime (GA4, Meta, Ads inside the container) and duplicates they create | Needs JS execution and the container contents | `clicktrail-verify` request log per case |
| Real hits fired twice (two `collect` / `tr` requests for one page view) | Needs network observation | `clicktrail-verify` redacted request metadata |
| Behaviour after consent grant / deny, consent-gated tags actually loading | Needs the CMP UI and storage | its granted / denied / no-consent cases |
| `dataLayer` contents, event names, schema flags | Runtime state | its `dataLayer` observer and project contract |
| Which host `gtm.js` is really served from after redirects or a service worker | Needs the browser's network layer | request log hosts |
| Call-tracking number swap and `tel:` click handlers | JS rewrites the DOM | browser case |
| Client-rendered pages (framework components that mount GTM after hydration) | Server HTML is empty of tags | browser case |
| Geo-specific CMP banners | Depends on visitor region | browser with a proxy or fixed locale |

## R9 - Open questions

- Q1: when no report exists, Site Health `good` ("not run yet") or `recommended`? `good` avoids nagging every install; `recommended` makes the feature discoverable.
- Q2: add a stable `data-clicutcl="gtm"` attribute to the GTM bootstrap (R4)?
- Q3: `limit_response_size` on WordPress 6.5; if absent, cap by truncating the body after the fact (memory risk is the response size).
- Q4: fix the `detect_caching()` / `detect_cache_conflict_labels()` drift here or separately?
- Q5: does the `ct_audit` twin bypass the caches in the install base (Cloudflare, LiteSpeed, WP Rocket, host caches)? Only a live check answers; until then US3 reports "unknown" rather than "ok".
- Q6: CMP and call-tracking signature strings (CookieYes, Usercentrics, Termly, Iubenda, Shopify consent markers, CallRail, CallTrackingMetrics, WhatConverts hosts) are not yet checked against vendor documentation; each needs one doc link and one fixture before the code lands. Are Shopify markers relevant to a WordPress plugin at all (embedded storefronts, headless)?
- Q7: scheduled weekly run? Out of scope here; if wanted it needs its own opt-in setting default off.

## Vendor facts relied on

- Google Tag Manager, "Install a web container": "Copy the first block of code and paste it as high in the `<head>` tag as possible." and "Copy the second block of code and paste it immediately after the opening `<body>` tag." The noscript block is `<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-ABCDEFGH" ...>`.
- Google tag, "Add the Google tag to your website": "Place the Google tag snippet immediately after the opening `<head>` HTML tag on every page you want to measure." with `<script async src="https://www.googletagmanager.com/gtag/js?id=TAG_ID">` and `gtag('config', 'TAG_ID');`.
- Meta Pixel, "Base Code": "The base Pixel code contains your Pixel's ID in two places"; the snippet starts `if(f.fbq)return;`, loads `https://connect.facebook.net/en_US/fbevents.js`, calls `fbq('init', ...)` and has a noscript `facebook.com/tr?id=` image.
- Cookiebot, manual blocking: "changing the value of the `type` attribute to "text/plain"" with `data-cookieconsent` set to one or more of "Preferences, Statistics, Marketing"; `data-cookieconsent="ignore"` exempts a script from automatic blocking.
