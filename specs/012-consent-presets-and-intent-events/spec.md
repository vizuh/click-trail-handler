# Feature Specification: Consent Default Preset, Contact-Intent Events, Click-ID Coverage

**Feature Branch**: `feat/consent-presets-intent-events`

**Created**: 2026-11-04

**Status**: Draft, not started. Every claim about current behaviour is source-present / runtime-unverified: read from `main` @ `e00552c` (1.12.0 plus unreleased 1.13 work). Nothing here was run in a browser or against a vendor; the real-WordPress harness `tools/qa/e2e/` has not exercised any of it.

**Input**: three field patterns seen on many real sites. (1) Sites with no CMP, or a CMP that sets no explicit default, run Google tags with an unset consent state; operators want one documented, explicit default to roll out per site. (2) Visitors reach leads through `mailto:` and scheduling links, but ClickTrail has no event for them and the GTM starter kit has no call/email/chat intent triggers, so operators hand-build fragile click listeners. (3) An AI-ads platform issues a click reference and keeps it in first-party cookies a server container cannot read; ClickTrail has no entry for it. Related: [../002-cmp-decision-transitions/](../002-cmp-decision-transitions/), [../007-cookie-scope/](../007-cookie-scope/), [../008-whatsapp-handoff/](../008-whatsapp-handoff/).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - An explicit "open" default for sites without a CMP (Priority: P1)

An operator of a US-only site with no CMP picks the preset "US, no CMP: explicit granted". Every page prints one `gtag('consent','default', ...)` with all signals `granted` before the GTM bootstrap, plus a `denied` default for listed regions.

**Why this priority**: with Consent Mode off ClickTrail prints no default at all (`Consent_Mode::register()` hooks the snippet only when enabled).

**Independent Test**: PHPUnit `tests/unit/ConsentOpenPresetTest.php` captures the `wp_head` output per config; E2E checks the served HTML.

**Acceptance Scenarios**:

1. **Given** `default_preset = open`, Consent Mode off, no CMP, no regions, **Then** exactly one default prints with `ad_personalization`, `ad_storage`, `ad_user_data`, `analytics_storage`, `functionality_storage`, `security_storage`, `personalization_storage` all `granted` and no `wait_for_update`.
2. **Given** regions `EEA, UK, CH`, **Then** an all-`denied` block with `wait_for_update: 500` and `region` set to the expanded ISO codes prints first (`EEA` to the 32 codes of `Regions::get_regions()`, `UK` to `GB`, deduplicated), then the global granted block.
3. **Given** the rendered page, **Then** the snippet (`id="clicktrail-consent-mode"`, `nowprocket`, `data-no-defer`) is before `'gtm.start'` and no cookie is written or `Set-Cookie` sent.
4. **Given** `default_preset = none` (default, every existing install), **Then** output is byte-identical to today.
5. **Given** the setting help, **Then** it says ClickTrail emits what the owner selected and the site owner decides whether a granted default is lawful for their visitors.

---

### User Story 2 - The preset never overrides a CMP or moves capture gating (Priority: P1)

**Independent Test**: PHPUnit with `Setup_Detector::detect_cmp()` stubbed, and `Consent::is_required()` compared with the preset on and off.

**Acceptance Scenarios**:

1. **Given** preset `open` and a CMP detected, or `cmp_source` declared as `cookiebot`, `onetrust`, `complianz`, `gtm` or `custom`, **Then** no preset prints unless a site opts in through `clicutcl_open_preset_with_cmp`.
2. **Given** preset `open` and Consent Mode on, **Then** the preset is ignored and `strict` / `relaxed` / `geo` output is unchanged.
3. **Given** the preset on or off, **Then** `Consent::is_required()`, `Consent::is_required_for_queue()`, `ctConsentBridgeConfig` and `clicutcl_config.requireConsent` are identical: the preset tells Google tags a default, it does not make ClickTrail treat consent as granted or required.
4. **Given** a CMP plugin that loads after ClickTrail, **Then** it is still seen, because detection runs at `wp_head`, not at `register()` (research R5).

---

### User Story 3 - Site Health says when no default exists (Priority: P1)

**Independent Test**: PHPUnit `tests/unit/SiteHealthConsentDefaultTest.php` on `SiteHealth::test_consent_default()`.

**Acceptance Scenarios**:

1. **Given** Consent Mode off, preset `none`, GTM container ID set (injected by ClickTrail), **Then** `recommended`: tags run with an unset consent state; the text names the exits (preset, Consent Mode, a CMP that sets its own default). With a CMP detected it adds that ClickTrail cannot verify the CMP emits a default.
2. **Given** preset `open` and a CMP detected or declared, **Then** `recommended`: the preset is inactive, and ClickTrail does not read that CMP while Consent Mode is off.
3. **Given** Consent Mode on, or the preset active, or no GTM container, **Then** `good`.

---

### User Story 4 - A mailto click is an event without the address (Priority: P1)

**Independent Test**: Node VM `tools/qa/intent-events.test.js` loading the real `clicutcl-events.js`.

**Acceptance Scenarios**:

1. **Given** consent and `<a href="MAILTO:Jane.Doe@Example.com?subject=Hi&body=Secret">Jane.Doe@Example.com</a>`, **When** clicked, **Then** one `contact_email_click` is pushed with `contact_type = email`, `email_domain = example.com` and an empty `cta_label` (text containing `@` is dropped); nothing in the push holds the local part, query or body.
2. **Given** consent denied or unresolved, **Then** nothing is pushed and no dedupe marker remains.
3. **Given** the same domain twice inside 10 s, **Then** the second pushes nothing (`markOnce`); another domain, or a later click, pushes.
4. **Given** `mailto:?subject=x` or a malformed address, **Then** the event fires with `email_domain` empty.
5. **Given** the canonical event, **Then** `buildCanonicalEvent` maps it (`mid`) and `EventV2::BROWSER_EVENT_NAMES` accepts it.

---

### User Story 5 - A scheduling-link click is an event (Priority: P1)

**Independent Test**: the same VM harness.

**Acceptance Scenarios**:

1. **Given** consent and a link on an allowlisted host (default `calendly.com`, `acuityscheduling.com`, `meetings.hubspot.com`, `meetings-eu1.hubspot.com`, `meetings-na2.hubspot.com`, `cal.com`; host or subdomain), **Then** one `contact_schedule_click` is pushed with `contact_type = schedule`, `schedule_provider` (`calendly`, `acuity`, `hubspot`, `cal`, `other`), `link_url` (origin and path, no query or fragment) and `cta_label`.
2. **Given** `clicutcl_schedule_hosts` adds a host, **Then** it is localized after validation (lowercase hostname with a dot, at most 50 entries) and matched; an invalid entry is dropped.
3. **Given** a Calendly or Acuity link click, **Then** `book_appointment` is no longer pushed for the click (intent, not a booking); `[data-booking-trigger]` and the postMessage booking path keep it (Q1).
4. **Given** consent denied, a repeat inside 10 s, or a host outside the list, **Then** nothing is pushed.

---

### User Story 6 - The GTM starter kit ships the intent triggers (Priority: P2)

**Independent Test**: `tools/qa/gtm-starter-kit.test.js` through `npm run test:attribution`.

**Acceptance Scenarios**:

1. **Given** the rebuilt kit, **Then** Custom Event triggers and GA4 event tags exist for `contact_call_click`, `contact_email_click`, `contact_chat_start`, `contact_whatsapp_start`, `contact_schedule_click`, each with the `ct_*` parameters of the other GA4 tags.
2. **Given** the old `LC - email_click` and `LC - phone_click` (no producer in the plugin), **Then** they are replaced by the names above.

---

### User Story 7 - The AI-ads click reference is captured (Priority: P2)

**Independent Test**: Node VM `tools/qa/ad-reference.test.js` (landing with `?oppref=...`, a stored `__obref` cookie) plus `tools/qa/click-id-parity.test.js`.

**Acceptance Scenarios**:

1. **Given** consent and `?oppref=<value>`, **Then** the value is stored unmodified as `oppref` (plus `ft_oppref`, `lt_oppref`) and appears in the dataLayer inside `ct_attribution`; a value over 255 characters is dropped, never truncated.
2. **Given** consent and a vendor `__obref` cookie, **Then** it is read into `obref` like `fbp`; ClickTrail never reads `__oppref` and never writes `__oppref` or `__obref`.
3. **Given** consent denied, **Then** nothing is captured or read.
4. **Given** `ad_reference_cookies` on (default off), **Then** first-party `ct_oppref` and `ct_obref` (30 days, `cookie_scope` domain) are written after consent and removed on withdrawal, so a sibling-subdomain server container can read them.
5. **Given** the D3 selection, **Then** `oppref` is captured but never selected, like `dclid` (research R12).

---

### Edge Cases

- `geo` with an empty region list requires consent everywhere and prints the strict default as the global default (`class-consent-mode.php:84-96`); untouched.
- A hosted CMP loaded through GTM is invisible to `detect_cmp()`: preset `open` still prints. The help says to keep it off with any CMP. A network-activated CMP on multisite is also missed (`active_plugins` only).
- The `region` default only changes what Google tags are told; with Consent Mode off ClickTrail's own capture stays ungated there, as today (R3).
- Two schedule links on one host and path inside 10 s dedupe to one event.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: `Consent_Mode_Settings` MUST gain `default_preset` (`none` default, `open`), sanitised and kept when a client omits it; no install is migrated and none is seeded to `open` (R7).
- **FR-002**: `Consent_Mode::register()` MUST hook the snippet at `HEAD_PRIORITY` when Consent Mode is on or the preset is `open`; the preset decision (Consent Mode off, no CMP detected or declared, filter) MUST be made inside the callback.
- **FR-003**: The preset snippet MUST print the region block first (when regions expand to at least one valid code) then the global granted block, reuse id `clicktrail-consent-mode` and its optimizer-exclusion attributes, and pass both blocks through `clicutcl_consent_region_defaults` / `clicutcl_consent_defaults` with mode `open`.
- **FR-004**: Region tokens MUST be expanded to codes matching `^[A-Z]{2}(-[A-Z0-9]{1,3})?$` before printing; invalid tokens are dropped.
- **FR-005**: The preset MUST NOT change `Consent::is_required()`, the bridge config or any capture gating, and MUST write no cookie or storage.
- **FR-006**: Site Health MUST add `clicutcl_consent_default` with the outcomes of User Story 3.
- **FR-007**: The Privacy & consent card MUST offer the preset select (disabled while Consent Mode is on) with the legal-responsibility help; the admin getter and save in `class-admin.php` MUST carry the key.
- **FR-008**: `clicutcl-events.js` MUST push `contact_email_click` and `contact_schedule_click` through `pushEvent`, checking consent before `markOnce` (10 s), with no mailbox local part, mailto query or body, or scheduling query or fragment in any field.
- **FR-009**: Both names MUST be in `EventV2::BROWSER_EVENT_NAMES`, the `buildCanonicalEvent` map (`mid`) and the two lists in `class-event-translator-v1-to-v2.php`.
- **FR-010**: The schedule host list MUST come from `clicutcl_schedule_hosts` through `clicutclEventsConfig.scheduleHosts`, validated in PHP and again in JS before matching.
- **FR-011**: `assets/build-starter-kit.py` MUST generate the User Story 6 triggers and tags and `assets/gtm-starter-kit.json` MUST be regenerated (rebuild procedure: R11).
- **FR-012**: `oppref` MUST join the capture and storage lists that hold `dclid`, and MUST NOT join the signed-token payload, the link Decorator lists, `oneClickId` or `D3_CLICK_ID_KEYS`; `obref` MUST join the browser identifiers.
- **FR-013**: An empty or over-255-character `oppref` / `obref` MUST be dropped, never truncated.
- **FR-014**: `ad_reference_cookies` (default off, never seeded) MUST write `ct_oppref` / `ct_obref` only from JavaScript after consent, and both MUST be listed in the storage inventory of `SECURITY-PRIVACY.md`.
- **FR-015**: Docs MUST be updated: `INTEGRATIONS.md`, `HOOKS-REFERENCE.md`, `REST-API.md`, `SECURITY-PRIVACY.md`, `SETTINGS-AND-ADMIN.md`, `DATA-MODEL.md`, `MASTER-SPECIFICATION.md`, `docs/tutorials/04-gtm-starter-kit.md`, `readme.txt`, `README.en.md`, `changelog.txt`.

### Key Entities

- **Consent preset**: `default_preset` in the `clicutcl_consent_mode` option, `none` or `open`.
- **Intent event**: `contact_email_click` (`email_domain`) and `contact_schedule_click` (`schedule_provider`, `link_url`).
- **Ad reference**: `oppref` (click ID, from the landing URL) and `obref` (browser reference, from the vendor cookie).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: PHPUnit: preset strings match for no regions, `EEA`, `UK, CH, US-CA` and an invalid token; `none` is byte-identical to today; a CMP gate suppresses the preset; `is_required()` and the bridge config are equal with the preset on and off; Site Health returns the three outcomes.
- **SC-002**: VM: mailto and schedule events pass consent, denial, dedupe, no-PII (`JSON.stringify` of the push holds no local part, query or body), allowlist and filter cases; the `cmp-transition.test.js` storage inventory is unchanged because `markOnce` is in memory.
- **SC-003**: `gtm-starter-kit.test.js` asserts the five triggers and tags, parameter parity and no `email_click` / `phone_click` trigger.
- **SC-004**: E2E (`tools/qa/e2e/`, one pod, `flock /tmp/ct-e2e.lock`): the preset page HTML has the snippet before `'gtm.start'` and no `Set-Cookie`; with a CMP plugin active it is absent. Until it runs the feature stays source-present / runtime-unverified.
- **SC-005**: Live (open): a real vendor click puts `oppref` in the store and a server container reads `ct_oppref`.

## Assumptions

- The vendor documentation read on 2026-11-04 (R14) stays accurate; its parameter and cookie names are the ones used.
- `Setup_Detector::detect_cmp()` is the CMP signal; it is conservative and the filter covers the rest.
