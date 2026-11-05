# Tasks: Consent Default Preset, Contact-Intent Events, Click-ID Coverage

**Input**: [spec.md](spec.md), [plan.md](plan.md), [research.md](research.md)

## Phase 0: Decisions (before code)

- [ ] T000 Hugo answers Q1 (stop `book_appointment` on Calendly / Acuity link clicks), Q2 (cap for `oppref` / `obref`), Q3 (plain `ct_oppref` cookie or not), Q4 (fix `geo` region tokens now or later), Q5 (`LC - form_submit` without a producer). Files: `research.md` Open

## Phase 1: Consent preset (US1, US2)

- [ ] T001 `ALLOWED_PRESETS`, `default_preset` default `none`, sanitiser that keeps the stored value when the key is absent, `get_default_preset()`. Files: `includes/Modules/consent-mode/class-consent-mode-settings.php`
- [ ] T002 Hook when enabled or preset `open`; `expand_regions()`, `build_open_preset()`, `cmp_blocks_open_preset()` (detect at `wp_head`, filter `clicutcl_open_preset_with_cmp`); print region block then global block with id `clicktrail-consent-mode`; run both existing filters with mode `open`. Files: `includes/Modules/consent-mode/class-consent-mode.php`
- [ ] T003 Stub gains `default_preset` and `get_default_preset()` and a `Setup_Detector` double. Files: `tests/helpers/consent-mode-settings-stub.php`
- [ ] T004 PHPUnit `tests/unit/ConsentOpenPresetTest.php`: output strings for no regions, `EEA`, `UK, CH, US-CA`, invalid token; `none` byte-identical to today; Consent Mode on ignores the preset; CMP gate; `Consent::is_required()`, `is_required_for_queue()` and the bridge config equal with the preset on and off; snippet before the GTM bootstrap (extend the `ConsentDefaultBeforeGtmTest.php` pattern). Files: `tests/unit/ConsentOpenPresetTest.php`

## Phase 2: Settings and Site Health (US1, US3)

- [ ] T005 `privacy.default_preset` in the admin getter and `$consent_input`; preset select with the legal-responsibility help, disabled while Consent Mode is on. Files: `includes/admin/class-admin.php`, `assets/js/admin-settings-app.js`
- [ ] T006 `SiteHealth::test_consent_default()` and its entry in `add_tests()`. Files: `includes/admin/class-sitehealth.php`
- [ ] T007 PHPUnit `tests/unit/SiteHealthConsentDefaultTest.php` for the three outcomes (with and without a detected CMP). Files: `tests/unit/SiteHealthConsentDefaultTest.php`

## Phase 3: Intent events (US4, US5)

- [ ] T008 `mailto:` branch with `parseMailto()`, label blanking, `markOnce` after the consent check. Files: `assets/js/clicutcl-events.js`
- [ ] T009 Schedule branch with `SCHEDULE_HOSTS` from `clicutclEventsConfig.scheduleHosts` (JS default list as fallback), `parseScheduleLink()`; remove the Calendly / Acuity `book_appointment` link branch (per T000). Files: `assets/js/clicutcl-events.js`
- [ ] T010 `scheduleHosts` localized from `clicutcl_schedule_hosts` after validation (lowercase hostname with a dot, at most 50). Files: `includes/class-clicutcl-core.php`
- [ ] T011 Both names in `BROWSER_EVENT_NAMES`, the `buildCanonicalEvent` map and the two translator lists. Files: `includes/tracking/class-eventv2.php`, `includes/tracking/class-event-translator-v1-to-v2.php`, `assets/js/clicutcl-events.js`
- [ ] T012 Node VM `tools/qa/intent-events.test.js` (consent, denial leaves no marker, dedupe, no PII, allowlist, filter, malformed `mailto:`); wire into `npm run test:consent` and `npm run test:attribution`; confirm `cmp-transition.test.js` inventory is unchanged. Files: `tools/qa/intent-events.test.js`, `package.json`

## Phase 4: Starter kit (US6)

- [ ] T013 Replace the `email_click` / `phone_click` loop entries with `contact_call_click`, `contact_email_click`, `contact_chat_start`, `contact_whatsapp_start`, `contact_schedule_click`; add the data-layer variables; rebuild with the stand-in input from research R11 and delete it. Files: `assets/build-starter-kit.py`, `assets/gtm-starter-kit.json`
- [ ] T014 Extend `gtm-starter-kit.test.js`: five triggers by exact filter value, five GA4 tags, `ct_*` parameter parity, no `LC - email_click` / `LC - phone_click`, every new variable defined. Files: `tools/qa/gtm-starter-kit.test.js`

## Phase 5: Click IDs (US7)

- [ ] T015 `oppref` in `CLICK_ID_KEYS`, aliases and the source label; `obref` in `BROWSER_IDENTIFIER_KEYS` with a `collect()` read of `__obref`; drop-on-over-255 for both; nothing in `D3_CLICK_ID_KEYS`, `buildToken`, the Decorator lists or `oneClickId`. Files: `assets/js/clicutcl-attribution.js`
- [ ] T016 `oppref` in `CLICK_ID_FIELDS`, `obref` in `BROWSER_IDENTIFIER_FIELDS`; readiness-analyzer entry; extra-key lists. Files: `includes/Core/class-attribution-provider.php`, `includes/Intelligence/class-attribution-readiness-analyzer.php`, `includes/database/class-whatsapp-ref-store.php`, `includes/privacy/class-woo-order-privacy.php`
- [ ] T017 `ad_reference_cookies` setting (default off, never seeded) and the `ct_oppref` / `ct_obref` writer (consent first, `cookie_scope` domain, 30 days) and remover on withdrawal. Files: `includes/admin/class-admin.php`, `assets/js/clicutcl-attribution.js`
- [ ] T018 Node VM `tools/qa/ad-reference.test.js` (capture, over-cap drop, denial, `__obref` read, cookie copy and removal, no write of vendor names) and `tools/qa/click-id-parity.test.js` (every `dclid` site has `oppref` except the listed exclusions). Files: `tools/qa/ad-reference.test.js`, `tools/qa/click-id-parity.test.js`

## Phase 6: Proof and docs

- [ ] T019 E2E scenario `consent_open_preset` (preset on: snippet before `'gtm.start'`, no `Set-Cookie`; a CMP plugin active: snippet absent), run under `flock /tmp/ct-e2e.lock`; wording stays source-present / runtime-unverified until it passes. Files: `tools/qa/e2e/adapters.py`, `tools/qa/e2e/README.md`
- [ ] T020 Docs per FR-015, the storage inventory rows for `ct_oppref` / `ct_obref`, and `changelog.txt` (`= Unreleased =`). Files: docs.
- [ ] T021 Live acceptance SC-005: a real vendor click, `oppref` in the store, a server container reading `ct_oppref`. Open.

## Dependencies

T000 → T009, T015, T017. T001 → T002, T005, T006. T002, T003 → T004. T006 → T007. T008, T009, T011 → T012. T013 → T014. T015, T016 → T018. T002, T004 → T019. T012, T014, T018, T019 → T020. T021 after T018.
