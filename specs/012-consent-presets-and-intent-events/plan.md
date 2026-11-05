# Implementation Plan: Consent Default Preset, Contact-Intent Events, Click-ID Coverage

**Branch**: `feat/consent-presets-intent-events` | **Date**: 2026-11-04 | **Spec**: [spec.md](spec.md)

## Summary

A new `default_preset` setting (`none` / `open`) makes `Consent_Mode` print an explicit all-`granted` `gtag('consent','default')`, with a `denied` region block, when Consent Mode is off and no CMP is present. Capture gating is untouched. Site Health reports the two unsafe states. `clicutcl-events.js` gains `contact_email_click` and `contact_schedule_click`; the GTM starter kit gains call/email/chat/whatsapp/schedule triggers and tags. `oppref` and `obref` join the click-ID and browser-identifier lists, with an opt-in first-party cookie copy.

## Technical Context

**Language/Version**: browser ES2019 (no build step); PHP 8.1+; Python 3 for the kit builder
**Primary Dependencies**: none new
**Storage**: one option key (`default_preset` inside `clicutcl_consent_mode`); one option key (`ad_reference_cookies` inside `clicutcl_attribution_settings`); optional cookies `ct_oppref`, `ct_obref` (off by default, Marketing). No table.
**Testing**: PHPUnit `tests/unit/ConsentOpenPresetTest.php`, `tests/unit/SiteHealthConsentDefaultTest.php`; Node VM `tools/qa/intent-events.test.js`, `tools/qa/ad-reference.test.js`, `tools/qa/gtm-starter-kit.test.js` (extended); `tools/qa/cmp-transition.test.js` as the storage-inventory guard; E2E scenario `consent_open_preset` in `tools/qa/e2e/adapters.py`
**Constraints**: no cookie or storage before consent; no `Set-Cookie` on page views; no new third-party code; preset default off for every install

## Repo gates

- AGENTS.md docs triggers: `docs/reference/INTEGRATIONS.md`, `HOOKS-REFERENCE.md` (new filters), `docs/reference/REST-API.md` (event list), `docs/guides/SECURITY-PRIVACY.md` (storage inventory, consent), `docs/guides/SETTINGS-AND-ADMIN.md`, `docs/architecture/DATA-MODEL.md` (click IDs), `docs/MASTER-SPECIFICATION.md` (CT-008, new CT row), `docs/tutorials/04-gtm-starter-kit.md`, `readme.txt`, `README.en.md`, `changelog.txt`. `docs/reference/integration-capabilities.json` is not touched: no integration is added.
- CodeQL: every host and value is validated at the sink inline (schedule hosts in PHP and JS, `mailto:` parsing, `oppref` charset and length).
- Evidence boundary: source-present / runtime-unverified until SC-004 and SC-005 pass.

## Design

### U1: preset

| Change | File |
|---|---|
| `ALLOWED_PRESETS`, `default_preset` default `none`, sanitiser keeps the stored value when the key is absent, `get_default_preset()` | `includes/Modules/consent-mode/class-consent-mode-settings.php` |
| Register the hook when enabled or preset `open`; `build_open_preset( $regions )` (pure: global granted block, region denied block built from `build_consent_defaults( 'strict', 500 )`); `expand_regions()` (EEA, UK, ISO regex, dedupe); CMP gate at render time | `includes/Modules/consent-mode/class-consent-mode.php` |
| `Consent_Mode::cmp_blocks_open_preset()` (static): `Setup_Detector::detect_cmp()` non-empty, or `cmp_source` in `cookiebot`, `onetrust`, `complianz`, `gtm`, `custom`, then `apply_filters( 'clicutcl_open_preset_with_cmp', false, $detected )` | same |
| `clicutcl_consent_defaults` / `_region_defaults` receive mode `open` | same |
| Test `test_consent_default()` and registration in `add_tests()` | `includes/admin/class-sitehealth.php` |
| `privacy.default_preset` in the getter and `$consent_input` in the save | `includes/admin/class-admin.php` |
| Preset select, legal-responsibility help, disabled while Consent Mode is on | `assets/js/admin-settings-app.js` |
| Stub gains `default_preset` and `get_default_preset()` | `tests/helpers/consent-mode-settings-stub.php` |

Emitted for preset `open`, regions `DE, GB` (key order of the existing strict builder):

```text
gtag('consent', 'default', {"ad_personalization":"denied","ad_storage":"denied","ad_user_data":"denied","analytics_storage":"denied","functionality_storage":"denied","security_storage":"denied","personalization_storage":"denied","wait_for_update":500,"region":["DE","GB"]});
gtag('consent', 'default', {"ad_personalization":"granted","ad_storage":"granted","ad_user_data":"granted","analytics_storage":"granted","functionality_storage":"granted","security_storage":"granted","personalization_storage":"granted"});
```

### U2: intent events

| Change | File |
|---|---|
| `mailto:` branch after `tel:`: `parseMailto()` returns `{domain}`; consent check, `markOnce( 'email|' + domain, 10000 )`, `pushEvent( 'contact_email_click', { contact_type, email_domain, cta_label } )`; label dropped when it contains `@` | `assets/js/clicutcl-events.js` (click listener at `:879`) |
| Schedule branch before the booking branch: host match against `SCHEDULE_HOSTS` (config, else default list), `parseScheduleLink()`, `markOnce( 'schedule|' + host + path )`; the Calendly / Acuity `book_appointment` link branch is removed | same |
| `contact_email_click`, `contact_schedule_click` in the `buildCanonicalEvent` map (`mid`) | same (`:190-193`) |
| `scheduleHosts` in `clicutclEventsConfig`, from `apply_filters( 'clicutcl_schedule_hosts', ... )` after validation | `includes/class-clicutcl-core.php` (`:321`) |
| Two names in `BROWSER_EVENT_NAMES`; two names in the `$mid` and `high` lists | `includes/tracking/class-eventv2.php`, `includes/tracking/class-event-translator-v1-to-v2.php:113,143` |
| Triggers and GA4 tags; `contact_type`, `email_domain`, `schedule_provider`, `link_url` data-layer variables | `assets/build-starter-kit.py`, `assets/gtm-starter-kit.json` |

### U3: click IDs

`dclid` is the template (capture-only, never selected) for capture and storage. `oppref` is deliberately NOT added where a value leaves the site or is selected: the signed-token payload (`buildToken`, `:953`), the link Decorator lists (`:1662`, `:1674`), `oneClickId` (`:1782-1788`) and `D3_CLICK_ID_KEYS` (`:35`). `obref` joins `BROWSER_IDENTIFIER_KEYS` / `BROWSER_IDENTIFIER_FIELDS`. `tools/qa/click-id-parity.test.js` audits both directions with an explicit exclusion list.

| Site | File |
|---|---|
| `CLICK_ID_KEYS` (`:27-31`), `CLICK_ID_ALIASES` (`:52-68`), source label (`:481`), `BROWSER_IDENTIFIER_KEYS` (`:38`) and `collect()` (`:1207-1230`, reads `__obref`) | `assets/js/clicutcl-attribution.js` |
| `CLICK_ID_FIELDS`, `BROWSER_IDENTIFIER_FIELDS` | `includes/Core/class-attribution-provider.php` |
| Cap 255, drop (never truncate) over it: the capture loop truncates every click ID at 128 (`:2548-2561`), `sanitizeValue` truncates at 256 by default (`:114-125`), the provider truncates at 255 (`:255-257`) | `assets/js/clicutcl-attribution.js`, `includes/Core/class-attribution-provider.php` |
| Platform map entry (`openai`, `click_id`) | `includes/Intelligence/class-attribution-readiness-analyzer.php` |
| Extra-key lists (`dclid` is already in the first; the Woo fallback list is for uninstall runs without the autoloader) | `includes/database/class-whatsapp-ref-store.php:134`, `includes/privacy/class-woo-order-privacy.php:43-70` |
| `ad_reference_cookies` in the attribution schema and defaults; `ct_oppref` / `ct_obref` writer and remover | `includes/admin/class-admin.php:1171`, `:1258`, `assets/js/clicutcl-attribution.js` |

### Contract

```text
page (preset open, no CMP) -> <script id="clicktrail-consent-mode"> region denied + global granted </script> before 'gtm.start'
click mailto:  (consent)   -> dataLayer {event:'contact_email_click', contact_type:'email', email_domain, cta_label}
click schedule (consent)   -> dataLayer {event:'contact_schedule_click', contact_type:'schedule', schedule_provider, link_url}
landing ?oppref=V (consent)-> store {oppref, ft_oppref, lt_oppref} -> dataLayer ct_attribution.oppref ; browser id obref from cookie __obref
```

## Risks

- A granted default is a legal choice: the setting stays off everywhere, the help says the owner decides, and a detected CMP suppresses it.
- Moving Calendly / Acuity link clicks from `book_appointment` to `contact_schedule_click` changes what GA4 key events already count (Q1).
- `detect_cmp()` only sees plugin CMPs; a hosted CMP needs the owner to keep the preset off.
- The vendor does not publish a length or charset for `oppref`; the cap is a guess and a wrong cap silently drops real values (R14).
