# Research: Consent Default Preset, Contact-Intent Events, Click-ID Coverage

Source read on 2026-11-04 (`main` @ `e00552c`). Nothing below was run in a browser or against a vendor; every row is source-present / runtime-unverified. Vendor facts were read from the vendors' own pages on the same day (R6, R14).

## Current state

| Surface | File | Finding |
|---|---|---|
| Snippet hook | `includes/Modules/consent-mode/class-consent-mode.php:71-73` | The `wp_head` snippet is hooked only when Consent Mode is enabled. Disabled means no `gtag('consent','default')` at all. `HEAD_PRIORITY = 0` (`:35`); `Web_Tag::HEAD_PRIORITY = Consent_Mode::HEAD_PRIORITY + 1` (`includes/Modules/GTM/class-web-tag.php:36`, hook at `:72`), so the default prints before the GTM bootstrap (#147). |
| What each mode prints | `class-consent-mode.php:79-115`, `:124-148` | `strict`: one default, all seven signals `denied`, `wait_for_update: 500`. `relaxed`: `ad_*` three `denied`, `analytics_storage`, `functionality_storage`, `security_storage`, `personalization_storage` `granted`, `wait_for_update: 500`. `geo` with regions: a strict block with `region` = the raw saved tokens, then the relaxed block as global. `geo` with no regions: the strict block as the global default (`:84-96`). Filters `clicutcl_consent_defaults` (`:98`) and `clicutcl_consent_region_defaults` (`:100`). No mode prints an all-`granted` default. |
| Capture gating | `includes/server-side/class-consent.php:101-111`, `includes/Modules/consent-mode/class-consent-mode-settings.php:189-204` | `Consent::is_required()` is `enabled && is_consent_required_for_request()`: strict true, relaxed false, geo true when the request country matches or is unknown (`:328-333`). Disabled gives false: capture is ungated. `is_required_for_queue()` (`:118-126`) is `enabled && mode !== relaxed`. |
| Bridge with Consent Mode off | `includes/class-clicutcl-core.php:371-379`, `assets/js/clicutcl-consent-bridge.js:564-567` | `require_consent` is forced false and `autoDetect()` resolves `granted` with source `mode-disabled`. ClickTrail ignores any CMP while Consent Mode is off. |
| Banner | `includes/class-clicutcl-core.php:270` | The built-in banner loads when Consent Mode is on and `cmp_source` is `auto` or `plugin`, whatever the mode. A US site that turns Consent Mode on to get a default also gets a banner. |
| CMP detection | `includes/admin/class-setup-detector.php:233-293` | `detect_cmp()` returns CookieYes, Cookiebot, OneTrust, Complianz by `active_plugins` slug, `COOKIEBOT_VERSION`, `Cookiebot_WP`, `CMPLZ_VERSION`, `CMPLZ_GDPR`. |
| Site Health | `includes/admin/class-sitehealth.php:37-54` | Three tests (cache, JS heartbeat, cookie). Nothing on consent defaults. |
| Settings UI | `assets/js/admin-settings-app.js:1068-1105`, `includes/admin/class-admin.php:664-670`, `:1020-1027` | Privacy & consent card: enable, mode, regions, source, wait time. Save builds `$consent_input` from five keys; the sanitiser (`class-consent-mode-settings.php:83-147`) re-reads stored values for the rest. |
| Intent events | `assets/js/clicutcl-events.js:879-940` | One capture-phase click listener on `a, button, [data-ct-cta], [data-chat-trigger], [data-booking-trigger]`: `tel:` gives `contact_call_click` (`:885`), `[data-chat-trigger]` gives `contact_chat_start` (`:893`), WhatsApp gives `contact_whatsapp_start` with consent then `markOnce` (`:901-919`), Calendly / Acuity hosts or `[data-booking-trigger]` give `book_appointment` (`:923-934`), `data-ct-cta` gives `cta_click`. No `mailto:` branch. `markOnce(key, windowMs)` is an in-memory `Map` (`:2307-2313`), so it writes no storage. `pushEvent` checks consent (`:88-97`). |
| Event contract | `includes/tracking/class-eventv2.php:22-45`, `includes/tracking/class-event-translator-v1-to-v2.php:113,143`, `clicutcl-events.js:190-193` | The three contact names are listed in each. |
| Starter kit | `assets/build-starter-kit.py:329-332`, `:483-487` | Loops over `form_submit`, `email_click`, `phone_click`, `contact_whatsapp_start` build `LC - <name>` triggers and `GA4 Event - <name>` tags. The plugin pushes no `email_click`, `phone_click` or `form_submit`. |
| Click IDs | `assets/js/clicutcl-attribution.js:27-31`, `includes/Core/class-attribution-provider.php:64-81` | 16 captured IDs; 10 in `D3_CLICK_ID_KEYS` (`:35`). No `oppref`, no `obref`. |

## R1: A separate setting, not a fourth mode

**Decision**: `default_preset` next to `mode`, consulted only while Consent Mode is off.
**Rationale**: `get_mode()` also drives `is_consent_required_for_request()`, the bridge `fallbackGranted` (`class-clicutcl-core.php:390`), `is_required_for_queue()` and the banner. A fourth mode value would have to pick a gating behaviour for all of them, which is the silent consent change the task forbids. The operators in the field pattern do not want Consent Mode (banner, gating) at all.
**Rejected**: mode `open` (changes gating); emitting the preset next to `strict` / `relaxed` (two defaults, no defined winner, see R4).

## R2: Preset content and regions

**Decision**: global block all seven signals `granted`, no `wait_for_update` (no CMP is expected to update it). Region block = `build_consent_defaults( 'strict', 500 )` plus `region`, printed first. Region tokens come from the existing `regions` setting (default `Regions::get_regions()`, 32 country codes including `GB`, `CH`, `IS`, `LI`, `NO`), expanded: `EEA` to those codes, `UK` to `GB`, then `^[A-Z]{2}(-[A-Z0-9]{1,3})?$`, deduplicated. An empty result prints the global block only.
**Rationale**: Google scopes defaults to regions and says "A gtag consent default command without a region parameter sets the default for all visitors not covered by another region-specific command" (R6). Reusing `regions` keeps one field; the card relabels its help per mode.
**Finding, not fixed here**: `geo` passes the raw tokens (`class-consent-mode.php:92-93`). `EEA` and `UK` are not ISO 3166-2 codes, so a geo site that saved them may get no region default. The default list holds country codes and is fine. The new `expand_regions()` is static so `geo` can adopt it (Q4).

## R3: Capture semantics do not move

**Decision**: the preset touches `Consent_Mode` rendering only; `Consent::is_required()`, the bridge config and `clicutcl_config.requireConsent` are asserted equal with it on and off (SC-001).
**Rationale**: with Consent Mode off ClickTrail already captures ungated (`mode-disabled`). The `region` default therefore tells Google tags `denied` while ClickTrail still captures in those regions. That is the existing behaviour made visible, and the setting help says to use Consent Mode `geo` when ClickTrail itself must be gated by region.

## R4: Precedence with a CMP

**Decision**: while Consent Mode is on, the preset is ignored. While it is off, a detected CMP (`detect_cmp()` non-empty) or a declared `cmp_source` of `cookiebot`, `onetrust`, `complianz`, `gtm`, `custom` suppresses the preset; `clicutcl_open_preset_with_cmp` lets a site force it.
**Rationale**: Google documents two defaults with different regions ("the one with a more specific region will take effect") but not two defaults for the same scope, so the winner between a CMP default and ours is unverified. A granted default printed before a CMP's denied default could silently undo it. Suppression fails safe.

## R5: Decide at `wp_head`, not at `register()`

**Decision**: `register()` hooks when preset is `open` (cheap option read); the CMP check runs in the callback.
**Rationale**: `detect_cmp()` depends on `defined()` / `class_exists()` of other plugins; [INFERENCE] WordPress loads active plugins in stored order (alphabetical by default), so `click-trail-handler` normally loads before `cookiebot`, `complianz-gdpr` and `cookie-law-info`, and a check at `register()` can miss them; not run. `Setup_Detector` lives under `includes/admin/`; calling it from the front end is a layering smell accepted because the task names it; the autoloader (`includes/class-autoloader.php`) resolves `CLICUTCL\Admin\Setup_Detector` in any context, no `is_admin()` guard involved. Per-request static cache. `active_plugins` misses network-activated plugins (edge case).

## R6: Vendor facts (Google)

Source: <https://developers.google.com/tag-platform/security/guides/consent> (read 2026-11-04).
- "By default, no consent mode values are set." and "call the `gtag('consent', 'default', ...)` command on every page of your site before any commands that send measurement data".
- "specify a region (according to ISO 3166-2) in your gtag consent default command"; example `'region': ['ES', 'US-AK']`.
- "If two default consent commands occur on the same page with values for a region and subregion, the one with a more specific region will take effect."
- "The order of the code here is vital. If your consent code is called out of order, consent defaults won't work."
- `wait_for_update` is for "asynchronous consent management platforms".

## R7: Seeding and legal text

**Decision**: never seed `open`, not even on fresh installs (unlike `seed_cookie_scope`, `class-installer.php:95`): a granted default is a legal posture the owner chooses. Help text: ClickTrail emits what is selected; the site owner decides whether it is lawful for their visitors. Everything stays off for existing installs without a migration.

## R8: Site Health

**Decision**: one test, `clicutcl_consent_default`, outcomes per User Story 3. "GTM injected by ClickTrail" is `GTM_Settings::get_container_id()` non-empty (`Web_Tag::register`, `:65-77`). `recommended`, never `critical`: ClickTrail cannot prove the absence of a default set by a hosted CMP.

## R9: `contact_email_click` payload

**Decision**: `contact_type: email`, `email_domain` (lowercased domain of the first recipient, validated `^[a-z0-9.-]+\.[a-z]{2,}$`, at most 253), `cta_label`. Scheme matched case-insensitively after trim; percent-decoding in a `try`; everything after `?` ignored; only the first of a comma list read.
**Rationale**: the anchor text is often the address itself (`Jane.Doe@example.com`), so `cta_label` is blanked when it contains `@`. The existing `tel:` branch sends the label as is (`:885-888`); that is a public business number, an address can be personal.

## R10: Scheduling hosts and the `book_appointment` overlap

**Decision**: default hosts `calendly.com`, `acuityscheduling.com`, `meetings.hubspot.com`, `meetings-eu1.hubspot.com`, `meetings-na2.hubspot.com`, `cal.com`, host-or-subdomain match as `onHost` does today (`clicutcl-events.js:923`). `link_url` is origin plus path: Calendly-style links are commonly shared with prefilled `name` / `email` in the query (not verified against vendor docs). Filter `clicutcl_schedule_hosts`, validated in PHP and JS.
**Evidence**: HubSpot regional hosts come from a third-party how-to, <https://missionseo.com/blog/hubspot-meeting-scheduler-webflow/> ("the link starts with meetings.hubspot.com, meetings-eu1.hubspot.com or meetings-na2.hubspot.com"); HubSpot's own page was not found. `cal.com` and the Calendly / Acuity hosts are the plugin's existing or obvious names and are unverified against vendor docs.
**Decision on overlap**: `MASTER-SPECIFICATION.md:277` defines `book_appointment` as a "Booking confirmation event", but `:923-934` pushes it on a mere link click with `submit_status: success`. The click moves to `contact_schedule_click`; the confirmation paths keep `book_appointment`. Recommended default, needs sign-off (Q1).

## R11: Starter kit rebuild

The builder needs `assets/shopify-gtm-container-templates-master/fb-ga4-gads-web.json` (`build-starter-kit.py:15`, read at `:160`), gitignored (`.gitignore:9`) and absent. It reads only `containerVersion.accountId`, `.containerId` and the `customTemplate` entry whose name contains `facebook` or `pixel` (`:163-175`). Procedure used for #145: write a temporary stand-in JSON with those three values copied from the shipped `assets/gtm-starter-kit.json` (`6004967354`, `187414573`, template `Facebook Pixel`), run `python3 assets/build-starter-kit.py`, delete the stand-in, diff the output. Triggers and tags are numbered from counters (`tid = 200`, `tag_id = 200`), so inserting some shifts later IDs; the test resolves by name. `tools/qa/gtm-starter-kit.test.js` prints `ok` before its last assertions: keep new assertions after that line as #145 did, or move the log last. Match exact trigger filter values, not substrings: `email_click` is a substring of `contact_email_click`.

## R12: `oppref` and `obref` and the D3 selection

**Decision**: `oppref` is captured, not selected; `obref` is a browser identifier. Neither goes into the signed token, link decoration, `oneClickId`, or `D3_CLICK_ID_KEYS`.
**Rationale**: D3 is "same contract as `clicktrail-js`" (`docs/architecture/DATA-MODEL.md:130`) and covers IDs ClickTrail dispatches on; there is no OpenAI destination here. Decorating outbound links with a vendor reference would also push a value off the site that the vendor does not ask for. `dclid` is the precedent (captured, never selected).

## R13: First-party copy

**Decision**: the `attribution` store already holds `oppref` and `obref`; with `cookie_scope = registrable` (spec 007) a sibling-subdomain server container can read that cookie JSON. For a plain cookie, opt-in `ad_reference_cookies` writes `ct_oppref` and `ct_obref` (30 days, refreshed on read, `cookie_scope` domain, JS only, after consent, removed on withdrawal). Default off and never seeded: two new cookies change the owner's cookie policy.
**Not done**: ClickTrail never reads `__oppref` (the URL is the source of truth) and never writes the vendor's names.

## R14: Vendor facts (OpenAI Ads), verified

- Parameter: "When an ad click supplies an `oppref` identifier, capture it and include the original value as `oppref` on the server event. The Conversions API does not capture this value automatically." <https://developers.openai.com/ads/conversion-tracking.md>. Also "`oppref`: An opaque, OpenAI-provided attribution identifier. Pass the original string without modification." <https://developers.openai.com/ads/conversions-api.md>.
- Cookies: "It captures `oppref` from the landing page URL ... It stores `oppref` in a first-party `__oppref` cookie so later page views can reuse it." and the expiry table: `__oppref` 30 days, `__obref` 365 days, <https://developers.openai.com/ads/measurement-pixel.md>.
- `obref`: "read the `__obref` first-party cookie in the browser, send it to your server, and include it unchanged as `events[].user.obref` ... Before collecting or forwarding the cookie, follow your site's measurement consent requirements." <https://developers.openai.com/ads/conversions-api.md>.
- Not published: maximum length or character set of `oppref` / `obref`, and the cookie `Domain` attribute. The "host-only" claim comes from the field report, not from the docs.

## Open

- **Q1**: stop pushing `book_appointment` on Calendly / Acuity link clicks (R10)? It is a visible change for GA4 key events already keyed on it; the alternative is to push both and document the double count.
- **Q2**: cap for `oppref` / `obref` (255 proposed) until the vendor publishes one.
- **Q3**: does the owner want a `ct_oppref` plain cookie at all, or is the `attribution` JSON cookie enough? The spec carries the opt-in setting.
- **Q4**: fix `geo` region tokens (`EEA`, `UK`) in this change or a follow-up?
- **Q5**: `LC - form_submit` also has no producer (`lead` and `form_submit_attempt` are pushed). Out of scope here; rename or drop?
- SC-004 and SC-005 are not run.
