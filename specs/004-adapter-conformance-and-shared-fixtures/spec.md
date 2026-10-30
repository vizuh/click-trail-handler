# Feature Specification: Form Adapter Conformance Suite and Shared Attribution Fixtures

**Feature Branch**: `feat/adapter-conformance-fixtures`

**Created**: 2026-10-30

**Status**: Draft

**Input**: Two gaps surfaced while fixing the Gravity Forms landing-page bug ([#137](https://github.com/vizuh/click-trail-handler/pull/137), WordPress.org thread "Gravity Forms Issue on First Page Load Form Submission"). First, six form adapters share one interface but no shared test: the landing-page submit case had unit coverage for none of them before #137, and the reporter also described unspecified CF7 failures. Second, the attribution rules are implemented separately in the browser runtime and in PHP, and the 1.10.5 click-ID change had to be repeated by hand in `clicutcl-attribution.js`, `Attribution_Provider::normalize_click_ids()`, `EventV2::normalize_click_ids()`, the attribution-token trait and the WooCommerce flattener. Nothing today detects when one copy drifts.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Every form adapter passes the same submission cases (Priority: P1)

A maintainer changes one form adapter, or a shared helper under them, and runs `composer run test`. One table-driven PHPUnit suite runs the same submission scenarios against Contact Form 7, Elementor Forms, Fluent Forms, Gravity Forms, Ninja Forms and WPForms, with each host plugin's hook entry point stubbed. A regression in any adapter fails by name.

**Why this priority**: The Gravity Forms bug existed because the server-rendered field value was one request behind the cookie. CF7 had the same class of bug until 1.10.5. The other four adapters have never been checked for it. Each adapter has its own entry point (`wpcf7_mail_sent`, `elementor_pro/forms/new_record`, `fluentform/submission_inserted`, `gform_after_submission`, `ninja_forms_after_submission`, `wpforms_process_complete`), so a bug fixed in one does not fix the others.

**Independent Test**: `tests/unit/FormAdapterConformanceTest.php` with a data provider over the six adapters. Each adapter gets a small driver (`tests/helpers/form-drivers/`) that knows how to build the host plugin's submission arguments and read back what the adapter persisted, so the scenarios themselves are written once.

**Acceptance Scenarios**:

1. **Given** the `attribution` cookie on the submit request carries `lt_source`, `lt_medium`, `lt_campaign`, `fbclid` and `attribution_selected_click_id`, and the posted `ct_*` fields are empty (landing-page submit), **When** the adapter's submission hook runs, **Then** the persisted record carries every one of those values.
2. **Given** the posted `ct_*` fields carry a stale previous touch (`lt_source = google`, `gclid = G1`) and the cookie carries a newer touch (`lt_source = facebook`, `fbclid = F2`), **Then** the persisted record carries the cookie's values, not the posted ones.
3. **Given** no `attribution` cookie and posted `ct_*` fields with values (cookies blocked), **Then** the posted values are persisted for adapters that read posted fields (CF7, Elementor Forms), and nothing is persisted for adapters that only read the cookie. [NEEDS CLARIFICATION: whether cookie-less fallback should be a requirement for every adapter or stay per-adapter as today.]
4. **Given** Consent Mode is enabled, consent is required for the request, and the consent cookie says `denied`, **Then** nothing is persisted and `log_submission()` writes no event row, for every adapter.
5. **Given** Consent Mode is enabled and no consent cookie is present, **Then** the same as scenario 4.
6. **Given** the cookie carries the legacy name `ct_attribution` only, **Then** the adapter persists its values (the canonical `attribution` cookie wins when both exist).
7. **Given** the cookie carries a Facebook macro value such as `{{campaign.name}}`, **Then** that key is absent from the persisted record.
8. **Given** `visitor_id` and `ct_session` cookies are present, **Then** `visitor_id` and `session_id` are persisted alongside the attribution keys.
9. **Given** a submission on an adapter with a per-form toggle (Gravity Forms today), **When** the toggle is off for that form, **Then** nothing is persisted; **When** it is on, scenarios 1 to 8 apply unchanged.

---

### User Story 2 - The browser and PHP attribution rules are checked against one set of fixtures (Priority: P1)

A maintainer changes a rule in the browser runtime (click-ID precedence, touch field mapping, channel label, ignored referrers, macro rejection, value length) and runs the test suites. Both the Node tests under `tools/qa/` and PHPUnit read the same fixture files under `tests/fixtures/attribution-rules/`. If the PHP implementation of that rule now disagrees with the fixture, PHPUnit fails; if the JS does, the Node test fails. A rule change therefore has to update the fixture, and the fixture change shows every implementation that must follow.

**Why this priority**: PHP cannot run the browser runtime and the browser cannot run PHP, so the duplication is permanent. The only thing that can be shared is the expected behaviour. Today the nearest precedent is `tools/qa/payment-referrer.test.js` reading `IGNORED_REFERRER_HOSTS` out of the PHP source with a regex, which proves the need and is fragile.

**Independent Test**: `tests/unit/AttributionRulesFixtureTest.php` and `tools/qa/attribution-rules.test.js`, both driven by the same JSON files. Each fixture is a list of `{ "name", "input", "expected" }` cases per rule.

**Acceptance Scenarios**:

1. **Given** `click-id-precedence.json` with a case where `ft_gclid = A`, `lt_gclid = B` and no top-level `gclid`, **Then** JS `withCanonicalClickIds()` and PHP `Attribution_Provider::sanitize()`, `EventV2` normalization and the attribution-token normalizer all resolve `gclid = B`.
2. **Given** the same fixture with only `ft_gclid = A`, **Then** every implementation resolves `gclid = A`.
3. **Given** `touch-mapping.json` with raw query params (`utm_source`, `utm_id`, `utm_source_platform`, ...) and expected `ft_*` / `lt_*` keys, **Then** JS `mapQueryFields()` and the PHP touch field list in `Attribution_Provider` agree on the key set.
4. **Given** `ignored-referrers.json` listing hosts and subdomains, **Then** JS `isIgnoredReferrer()` and PHP `Attribution_Provider::get_ignored_referrer_hosts()` agree on every case, and `payment-referrer.test.js` no longer parses the PHP source.
5. **Given** `channel-label.json` with `(params, referrer, expected label)` cases, **Then** JS `resolveChannelLabel()` and PHP `Gf_Channel_Resolver::resolve()` return the same label. [NEEDS CLARIFICATION: `Gf_Channel_Resolver` takes a flattened payload, not raw params; the fixture may need both shapes, or the two resolvers may be allowed to differ on documented cases.]
6. **Given** `value-sanitization.json` with a `{{campaign.name}}` macro, a 300-character value and a value with control characters, **Then** JS `sanitizeAttributionData()` and PHP `Attribution_Provider::sanitize()` produce the same output (macro dropped, value cut to 255, control characters removed).
7. **Given** a fixture case is added with an `expected` that only one implementation satisfies, **Then** exactly that suite fails and the failure message names the fixture file and case name.

---

### User Story 3 - A live end-to-end check for one non-CF7 adapter (Priority: P2)

The existing `tools/qa/e2e/ci.sh` round trip runs a throwaway WordPress with CF7. Adding one more form plugin that can be installed from WordPress.org without a licence (Fluent Forms free or WPForms Lite) gives the landing-page scenario a real-browser proof for a second adapter, and a template for the licensed ones to be run locally.

**Why this priority**: US1 proves adapter logic against stubs. The Gravity Forms bug would have passed a stub that did not model `gform_field_value` running at render time. Only a real request cycle catches render-versus-submit timing.

**Independent Test**: `e2e-attribution.yml` gains a job that lands on a page with the form and UTMs, submits without navigating, and asserts the stored submission meta.

**Acceptance Scenarios**:

1. **Given** the throwaway site has the chosen form plugin and a form with `ct_*` fields, **When** the browser lands on that page with UTMs and a click ID and submits, **Then** the stored submission carries them.
2. **Given** the same site, **When** the browser lands on the home page with UTMs and then submits on the form page, **Then** the stored submission carries them (the case that already worked for Gravity Forms must keep working).

---

### Edge Cases

- Gravity Forms and WPForms only persist what the site owner has configured as fields plus entry meta. The conformance driver for those adapters must assert on entry meta (`gform_add_meta` calls, `wpforms` entry meta) rather than on form fields, or the stub must declare the fields.
- Ninja Forms injects attribution on `ninja_forms_submit_data` before `ninja_forms_after_submission` runs. The driver has to run both hooks in order or scenario 1 fails for the wrong reason.
- Fluent Forms registers both the slash and underscore hook names. The driver must fire one, not both, or the dedupe guard is what gets tested.
- The CF7 adapter fires only on `wpcf7_mail_sent`. A driver that calls `on_submission()` directly skips the outcome gate that `CF7SubmissionOutcomeTest` covers; the conformance test must not duplicate that test, only reuse its entry point.
- `should_populate()` reads the consent cookie by the name from `Consent_Mode_Settings`. The existing test stub returns `production_consent`; the conformance test must set that cookie name, not `ct_consent`.
- Fixtures are consumed by two languages. Keys must be strings, values strings or booleans, no comments, no trailing commas, and every file must parse with both `JSON.parse` and `json_decode`.
- A fixture describes a rule, not a version. When a rule changes on purpose, the fixture changes in the same commit and the changelog entry names the fixture file, so the history of the rule is in git.
- The current PHP tests run with `@runTestsInSeparateProcesses` where globals (`$_COOKIE`, `$_POST`) are involved. A data-provider suite over six adapters multiplies process spawns; measure before deciding whether to keep that annotation or reset globals in `setUp()`.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: One PHPUnit test class MUST run scenarios US1-1 to US1-9 against every class that implements `Form_Adapter_Interface` under `includes/integrations/forms/`. Adding a seventh adapter without a driver MUST fail the suite, not silently skip it.
- **FR-002**: Each adapter driver MUST build the host plugin's real hook arguments (the shapes documented in the adapter's docblocks) and MUST read back the persisted record through the same storage the adapter writes (`gform_add_meta` stub, `fluentform_submission_meta` stub, Ninja `extra`, WPForms entry meta, CF7 and Elementor event rows via `log_submission()`).
- **FR-003**: The conformance suite MUST NOT re-implement attribution expectations inline. Expected values MUST come from the same fixture files as US2 where a rule is involved (for example the click-ID precedence in US1-2).
- **FR-004**: Fixture files MUST live under `tests/fixtures/attribution-rules/` as JSON, one file per rule, with the shape `{ "rule": string, "version": int, "cases": [ { "name", "input", "expected" } ] }`.
- **FR-005**: `tools/qa/attribution-rules.test.js` MUST load the real `assets/js/clicutcl-attribution.js` in a Node `vm` (same pattern as `payment-referrer.test.js`) and assert every fixture case against the exported or reachable JS functions. If a function is not reachable from outside the IIFE, the test MUST exercise it through `window.ClickTrail` or the stored cookie, not through a copy of the logic.
- **FR-006**: `tests/unit/AttributionRulesFixtureTest.php` MUST assert every fixture case against each PHP implementation of that rule. The click-ID precedence fixture MUST be run against `Attribution_Provider::sanitize()`, `EventV2` normalization, the attribution-token normalizer and `WooCommerce::flatten_attribution_for_event()`.
- **FR-007**: `tools/qa/payment-referrer.test.js` MUST read `ignored-referrers.json` instead of parsing `class-attribution-provider.php`.
- **FR-008**: Both new suites MUST run in `.github/workflows/phpunit.yml` (PHPUnit via `composer run test`; the Node test via a new `npm run test:rules` step).
- **FR-009**: A failing fixture case MUST report the fixture file, the case name and the implementation that failed.
- **FR-010**: `docs/guides/CODE-QUALITY.md` MUST describe the fixture directory as the source of truth for attribution rules and the change procedure (fixture first, implementations second, changelog names the file). `docs/reference/INTEGRATIONS.md` MUST state which adapters pass the conformance suite and which scenarios, if any, are skipped per adapter.

### Key Entities

- **Adapter driver**: a test helper per form plugin: `submit(array $cookies, array $posted, array $form): void` and `persisted(): array`. Owns the host plugin stubs for that adapter.
- **Rule fixture**: a JSON file naming one attribution rule with cases of `input` and `expected`. Consumed by both test suites. Versioned by an integer bumped when the rule changes on purpose.
- **Conformance scenario**: one row of the US1 table, parameterised by adapter. Written once.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Reverting #137 (`refresh_submitted_fields()`) fails the conformance suite for Gravity Forms and no other adapter.
- **SC-002**: Reverting the 1.10.5 CF7 cookie-precedence change fails the conformance suite for CF7 and no other adapter.
- **SC-003**: Changing the click-ID precedence in any one of the five implementations (JS plus four PHP) without touching the others fails exactly one suite and names the implementation.
- **SC-004**: `payment-referrer.test.js` contains no regex over PHP source.
- **SC-005**: The full `composer run test` wall time grows by no more than 5 seconds on the CI runner after the conformance suite is added. If the separate-process annotation makes that impossible, the suite documents the measured cost and the decision.
- **SC-006**: US3 round trip passes in CI for the chosen free form plugin.

## Assumptions

- The six adapters' hook argument shapes documented in their docblocks are accurate. The GF driver can rely on `tests/unit/GravityFormsSubmissionRefreshTest.php` as its starting point; the Fluent Forms driver on `FluentFormsAdapterTest.php`.
- Host plugin APIs needed by the drivers (`gform_add_meta`, `GFAPI::get_form`, `wpFluent()`, WPForms entry helpers, Ninja `Ninja_Forms()->form()`) can be stubbed with small classes in `tests/helpers/`, the way the Fluent Forms test already does. No host plugin is installed in the PHPUnit job.
- The browser runtime's rule functions are inside an IIFE. Reaching them for US2 may need a test-only export guarded by `window.__clicutclTestHooks` or similar, which must not ship in the release zip. [NEEDS CLARIFICATION: acceptable to add a test-only export to the runtime, or must the Node test exercise rules only through the public `ClickTrail` API and cookies?]
- "Persisted record" means whatever the adapter writes for that platform. The suite asserts presence and value of keys, not the platform's storage format.
- This spec does not cover the HubSpot, Calendly or WooCommerce paths. They have their own tests and their own consent gates; a later spec can extend the fixture suite to `WooCommerce::flatten_attribution_for_event()` beyond the click-ID rule named in FR-006.
- No monorepo, package extraction or build step is in scope. The fixtures are the contract; where the code lives is unchanged.
