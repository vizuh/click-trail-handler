# Feature Specification: Form Adapter Conformance Suite and Shared Attribution Fixtures

**Feature Branch**: `ccr-91ff6eaf-3k60n9`

**Created**: 2026-10-30

**Status**: Approved direction (2026-10-30). Open questions resolved; see Decisions. Ready for `/speckit-plan`.

**Input**: Two gaps surfaced while fixing the Gravity Forms landing-page bug ([#137](https://github.com/vizuh/click-trail-handler/pull/137), WordPress.org thread "Gravity Forms Issue on First Page Load Form Submission"). First, six form adapters share one interface but no shared test: the landing-page submit case had unit coverage for none of them before #137, and the reporter also described unspecified CF7 failures. Second, the attribution rules are implemented separately in the browser runtime and in PHP, and the 1.10.5 click-ID change had to be repeated by hand in `clicutcl-attribution.js`, `Attribution_Provider::normalize_click_ids()`, `EventV2::normalize_click_ids()`, the attribution-token trait and the WooCommerce flattener. Nothing today detects when one copy drifts.

**Scope principle**: keep the existing WordPress modules where they are, establish behavioral contracts between them, and make every adapter prove conformance before it ships. No monorepo, no independent package versioning, no 10up WP Framework dependency, no asset build step introduced by this spec.

## Decisions *(resolved 2026-10-30)*

- **D1, attribution source precedence (all six adapters)**: at submit time, after the consent gate passes, an adapter MUST use, as a whole record:
  1. the **usable attribution cookie** sent with the submit, if there is one;
  2. otherwise the **posted `ct_*` values**, sanitized as untrusted input;
  3. otherwise no attribution.

  Sources are not mixed key by key, so a record never combines a current cookie touch with a stale posted click ID. The rule is the same for a landing-page submit and a later-page submit. No source bypasses consent.

  **Usable cookie**: the canonical `attribution` cookie (or legacy `ct_attribution` when the canonical one is absent) parses as a JSON object and, after `Attribution_Provider::sanitize()`, holds at least one key from `get_field_mapping()` other than `visitor_id`, `session_id`, `session_count` and `session_number`.

  **Posted values**: read only under `ct_`-prefixed names that map to `get_field_mapping()` keys, then passed through `Attribution_Provider::sanitize()` (canonical keys, macro rejection, 255-character cap, click-ID normalization). Plain `sanitize_text_field()` is not enough.

  This changes two adapters' current behavior (see Compatibility).

- **D2, one canonical fixture with explicit raw and flattened inputs**: where JS and PHP take different input shapes for the same rule (for example JS `resolveChannelLabel(params, referrer)` against PHP `Gf_Channel_Resolver::resolve($payload, ...)`), each fixture case carries both shapes and one `expected` result. Representation differences are documented in the fixture. A difference in the attribution decision is allowed only as an entry in the fixture's `exceptions` list with a written `reason` and the name of the regression test that pins it. Unexplained differences fail.

- **D3, test access to JS rules**: a test-only interface is acceptable only if it runs the exact production code, duplicates no rule, and is absent from the release zip, with CI checking that. The release pipeline (`tools/release/make-zip.js`) is an `rsync` copy filtered by `.distignore`: it does not build or transform JS, so it cannot strip an export. Per the approved fallback, the rules move into a small internal rule module that the runtime itself calls and the Node harness loads directly. No release-time transformation is added. The plan picks the module's form (see plan.md, research R1).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - JS and PHP attribution rules are checked against one set of fixtures (Priority: P1)

A maintainer changes a rule (click-ID precedence, touch field mapping, channel label, ignored referrers, value sanitization) and runs the test suites. The Node tests under `tools/qa/` and PHPUnit both read the same fixture files under `tests/fixtures/attribution-rules/`. If one implementation now disagrees with the fixture, that implementation's test fails and names it. A rule change therefore updates the fixture first, and the fixture shows every implementation that must follow.

**Why this priority**: PHP cannot run the browser runtime and the browser cannot run PHP, so the duplication is permanent. Only the expected behavior can be shared. The nearest precedent, `tools/qa/payment-referrer.test.js`, reads `IGNORED_REFERRER_HOSTS` out of the PHP source with a regex, which shows the need and is fragile. This story is first because US2's expected values come from these fixtures (FR-007).

**Independent Test**: `tests/unit/AttributionRulesFixtureTest.php` and `tools/qa/attribution-rules.test.js`, both driven by the same JSON files.

**Acceptance Scenarios**:

1. **Given** `click-id-precedence.json` with a case where `ft_gclid = A`, `lt_gclid = B` and no top-level `gclid`, **Then** the JS rule module, `Attribution_Provider::sanitize()`, `EventV2` normalization, the attribution-token normalizer and `WooCommerce::flatten_attribution_for_event()` all resolve `gclid = B`.
2. **Given** the same fixture with only `ft_gclid = A`, **Then** every implementation resolves `gclid = A`.
3. **Given** `touch-mapping.json` with raw query params (`utm_source`, `utm_id`, `utm_source_platform`, ...) and the expected `ft_*` / `lt_*` keys, **Then** the JS rule module and the PHP touch field list in `Attribution_Provider` agree on the key set.
4. **Given** `ignored-referrers.json` listing hosts and subdomains, **Then** the JS rule module and `Attribution_Provider::get_ignored_referrer_hosts()` agree on every case, and `payment-referrer.test.js` no longer parses PHP source.
5. **Given** `channel-label.json` cases each carrying `raw` (query params plus referrer) and `flattened` (payload keys) inputs, **Then** the JS rule module run on `raw` and `Gf_Channel_Resolver::resolve()` run on `flattened` return the same label, except for cases listed in `exceptions` with a reason and a named regression test.
6. **Given** `value-sanitization.json` with a `{{campaign.name}}` macro, a 300-character value and a value with control characters, **Then** the JS rule module and `Attribution_Provider::sanitize()` produce the same output (macro dropped, value capped at 255, control characters removed).
7. **Given** a fixture case whose `expected` only some implementations satisfy, **Then** each failing assertion names the fixture file, the case `id` and the implementation, and the implementations that do satisfy it pass.

---

### User Story 2 - Every form adapter passes the same submission scenarios (Priority: P1)

A maintainer changes one form adapter, or a shared helper under them, and runs `composer run test`. One table-driven PHPUnit suite runs the same scenarios against Contact Form 7, Elementor Forms, Fluent Forms, Gravity Forms, Ninja Forms and WPForms, with each host plugin's hook entry point stubbed. A regression in one adapter fails under that adapter's name.

**Why this priority**: The Gravity Forms bug existed because the server-rendered field value was one request behind the cookie. CF7 had the same class of bug until 1.10.5. The other four adapters have never been checked for it. Each adapter has its own entry point (`wpcf7_mail_sent`, `elementor_pro/forms/new_record`, `fluentform/submission_inserted`, `gform_after_submission`, `ninja_forms_after_submission`, `wpforms_process_complete`), so a fix in one does not fix the others.

**Independent Test**: `tests/unit/FormAdapterConformanceTest.php` with a data provider over the adapter driver registry. Each driver (`tests/helpers/form-drivers/`) builds the host plugin's submission arguments and reads back what the adapter persisted, so the scenarios are written once.

**Acceptance Scenarios** (each runs for every adapter):

1. **Given** a usable cookie carrying `lt_source`, `lt_medium`, `lt_campaign`, `fbclid` and `attribution_selected_click_id`, and empty posted `ct_*` values (landing-page submit), **When** the submission hook runs, **Then** the persisted record carries every one of those values.
2. **Given** posted `ct_*` values with a stale previous touch (`lt_source = google`, `gclid = G1`) and a usable cookie with a newer touch (`lt_source = facebook`, `fbclid = F2`), **Then** the persisted record equals the cookie's record: `lt_source = facebook`, `fbclid = F2`, and no `gclid = G1` (D1, no key-by-key mixing).
3. **Given** no attribution cookie and posted `ct_*` values (cookies blocked), **Then** the persisted record carries the posted values after `Attribution_Provider::sanitize()`.
4. **Given** no attribution cookie and posted `ct_*` values that include a `{{campaign.name}}` macro, a 300-character value and a non-`ct_` field named `utm_source`, **Then** the macro key is absent, the long value is capped at 255, and `utm_source` is ignored.
5. **Given** a cookie that holds only `visitor_id` and `session_id` (not usable under D1) and posted `ct_lt_source = newsletter`, **Then** the persisted record uses the posted values.
6. **Given** neither a usable cookie nor posted `ct_*` values, **Then** no attribution is persisted and `log_submission()` writes no event row.
7. **Given** Consent Mode is enabled, consent is required for the request, and the consent cookie says `denied`, **Then** nothing is persisted and no event row is written, whether attribution would have come from the cookie or from posted values.
8. **Given** Consent Mode is enabled and no consent cookie is present, **Then** the same as scenario 7.
9. **Given** only the legacy `ct_attribution` cookie, **Then** it is used as the cookie source. **Given** both cookies, **Then** the canonical `attribution` cookie wins.
10. **Given** `visitor_id` and `ct_session` cookies alongside a usable cookie, **Then** `visitor_id` and `session_id` are persisted with the attribution keys.
11. **Given** an adapter with a per-form toggle (Gravity Forms today) and the toggle off for that form, **Then** nothing is persisted. With the toggle on, scenarios 1 to 10 apply unchanged.

---

### User Story 3 - Adapter coverage is enforced, not assumed (Priority: P1)

A maintainer adds a seventh form adapter, or one adapter's driver is deleted, or a host-plugin stub fails to load. The test run reports that explicitly and fails. It never passes by running fewer adapters than production registers.

**Why this priority**: A conformance suite that silently skips an adapter is worse than none, because it reads as coverage.

**Independent Test**: `tests/unit/FormAdapterRegistryTest.php` compares three sets: the adapter classes `Form_Integration_Manager` registers, the classes under `includes/integrations/forms/` that implement `Form_Adapter_Interface`, and the driver registry.

**Acceptance Scenarios**:

1. **Given** an adapter class registered in `Form_Integration_Manager` with no driver, **Then** the registry test fails and names the class.
2. **Given** a class under `includes/integrations/forms/` that implements `Form_Adapter_Interface` but is not registered in `Form_Integration_Manager`, **Then** the registry test fails and names the class.
3. **Given** a driver whose host-plugin stubs cannot be loaded, **Then** its scenarios fail with that reason. They are not marked skipped or incomplete.
4. **Given** the PHPUnit run, **Then** its output lists the adapters exercised and the count of scenarios per adapter.

---

### User Story 4 - The release zip carries no test interface (Priority: P2)

A release is built with `npm run make-zip`. CI checks that the zip contains no test-only JS interface and no fixture or harness file.

**Why this priority**: D3 allows test access only if it never ships. The pipeline has no transform step, so the guarantee has to be a check on the built artifact.

**Independent Test**: an extension of `validateManifest()` in `tools/release/make-zip.js`, or a step in `.github/workflows/package.yml`, that inspects the built zip.

**Acceptance Scenarios**:

1. **Given** a built zip, **Then** no entry is under `tests/` or `tools/qa/`, and no shipped JS file contains the agreed test-hook marker (defined in plan.md).
2. **Given** a commit that introduces the marker into a shipped JS file, **Then** the package job fails and names the file.

---

### User Story 5 - Live end-to-end checks with real form plugins (Priority: P3)

The existing `tools/qa/e2e/ci.sh` round trip runs a throwaway WordPress with CF7. It is extended to the form plugins that can be installed from WordPress.org without a licence, so the landing-page scenario has a real request cycle behind it, not only stubs.

**Why this priority**: US2 proves adapter logic against stubs. The Gravity Forms bug would have passed a stub that did not model `gform_field_value` running at render time. Only a real request cycle catches render-versus-submit timing. It is last because it is the slowest to build and run.

**Independent Test**: `e2e-attribution.yml` jobs that land on a page with a form and UTMs, submit without navigating, and assert the stored submission. This suite is separate from the PHPUnit stub suite.

**Acceptance Scenarios**:

1. **Given** a throwaway site with the form plugin and a form with `ct_*` fields, **When** the browser lands on that page with UTMs and a click ID and submits, **Then** the stored submission carries them.
2. **Given** the same site, **When** the browser lands on the home page with UTMs and then submits on the form page, **Then** the stored submission carries them.
3. **Given** a form plugin that needs a licence (Gravity Forms, Elementor Pro), **Then** the live suite's report lists it as **not run: licence required**. It is never omitted from the report and never counted as passing.

---

### Edge Cases

- Gravity Forms persists configured fields plus entry meta. Drivers must assert on what the adapter writes (`gform_add_meta` calls), not on form fields the stub happens to declare. WPForms Lite has no entries (`$entry_id` is 0), so for WPForms only the `log_submission()` row is asserted (research R3).
- Fluent Forms drops submitted keys that are not form inputs or whitelisted, before the submit hook runs. The Fluent driver must model that, or US2-3 would pass on a path that never happens live (research R3).
- WPForms exposes no field name at the hook, only the label. A `ct_*` field is recognized there by its label (research R3, decided 2026-10-30).
- Each adapter receives posted `ct_*` values through a different channel: CF7 `get_posted_data()`, Elementor record fields, Fluent Forms submission data, Gravity Forms `input_<id>` mapped through the field's `inputName`, Ninja Forms submit data and its `extra`, WPForms `fields`. The D1 posted-values read must be implemented per adapter. The plan records each channel (research R3).
- Ninja Forms injects attribution on `ninja_forms_submit_data` before `ninja_forms_after_submission` runs. The driver runs both hooks in order.
- Fluent Forms registers both the slash and underscore hook names. The driver fires one, or the dedupe guard is what gets tested.
- The CF7 adapter records only on `wpcf7_mail_sent`. The driver uses that entry point and does not duplicate `CF7SubmissionOutcomeTest`.
- `should_populate()` reads the consent cookie by the name from `Consent_Mode_Settings`. The test stub returns `production_consent`, so the suite sets that name, not `ct_consent`.
- The Gravity Forms `refresh_submitted_fields()` hook from #137 already prefers the cookie per input. Under D1 it must also prefer the cookie as a whole record, and leave posted values alone when no usable cookie exists. Its existing test stays and is extended.
- Fixtures are read by two languages. Keys are strings, values strings, numbers, booleans, arrays or objects, with no comments and no trailing commas, and every file must parse with both `JSON.parse` and `json_decode`.
- A fixture describes a rule, not a release. When a rule changes on purpose, the fixture changes in the same commit, its `version` is bumped, and the changelog entry names the file.
- PHP tests that touch `$_COOKIE` and `$_POST` use `@runTestsInSeparateProcesses` today. A data provider over six adapters and eleven scenarios multiplies process spawns. Measure before deciding between that annotation and a global reset in `setUp()` (SC-006).

## Requirements *(mandatory)*

### Functional Requirements

**Rule fixtures (US1)**

- **FR-001**: Fixture files MUST live under `tests/fixtures/attribution-rules/` as JSON, one file per rule, shaped `{ "rule": string, "version": int, "notes": string, "cases": [ { "id", "raw"?, "flattened"?, "input"?, "expected" } ], "exceptions": [ { "case": id, "implementation": string, "reason": string, "regression_test": string } ] }`. A case uses `input` when every implementation takes the same shape, and `raw` plus `flattened` when they do not (D2).
- **FR-002**: An entry in `exceptions` without a non-empty `reason` and `regression_test` MUST fail the fixture loader.
- **FR-003**: The pure JS attribution rules (click-ID precedence, touch mapping, channel label, ignored referrers, value sanitization) MUST live in one internal rule module that `clicutcl-attribution.js` calls at runtime and that `tools/qa/attribution-rules.test.js` loads directly. The runtime MUST NOT keep a second copy of any of these rules (D3).
- **FR-004**: `tests/unit/AttributionRulesFixtureTest.php` MUST assert every case against each PHP implementation of the rule. The click-ID precedence fixture MUST run against `Attribution_Provider::sanitize()`, `EventV2` normalization, the attribution-token normalizer and `WooCommerce::flatten_attribution_for_event()`.
- **FR-005**: `tools/qa/payment-referrer.test.js` MUST read `ignored-referrers.json` instead of parsing `class-attribution-provider.php`.
- **FR-006**: A failing case MUST report the fixture file, the case `id` and the implementation.

**Adapter contract and conformance (US2, US3)**

- **FR-007**: The conformance scenarios MUST take expected attribution values from the US1 fixtures where a rule is involved (for example click-ID precedence in US2-2), not from values written inline.
- **FR-008**: All six adapters MUST implement D1. The usable-cookie check and the posted-values sanitization MUST be one shared implementation (on `Abstract_Form_Adapter` or `Attribution_Provider`) that each adapter feeds from its own posted-values channel.
- **FR-009**: One PHPUnit class MUST run US2 scenarios 1 to 11 for every adapter in the driver registry.
- **FR-010**: Each driver MUST build the host plugin's hook arguments in the shapes the adapter's hook registration expects and MUST read the persisted record back through the storage the adapter writes to (stubs for `gform_add_meta`, `fluentform_submission_meta`, Ninja `extra`, WPForms entry meta, and event rows via `log_submission()` for CF7 and Elementor).
- **FR-011**: `Form_Integration_Manager` MUST expose its adapter class list (for example a public constant or static method) so tests compare against production's list instead of a copy.
- **FR-012**: The registry test MUST fail, naming the class, when the manager's list, the classes implementing `Form_Adapter_Interface` under `includes/integrations/forms/`, and the driver registry disagree. A driver that cannot load its stubs MUST fail, not skip.

**Release and CI (US4, US5)**

- **FR-013**: The package job MUST fail when the built zip contains `tests/`, `tools/qa/`, a fixture file, or the test-hook marker in a shipped JS file.
- **FR-014**: `.github/workflows/phpunit.yml` MUST run the new PHPUnit tests (through `composer run test`) and the new Node test (through a new `npm run test:rules` step).
- **FR-015**: The live suite MUST stay separate from the PHPUnit stub suite, MUST report every one of the six adapters as passed, failed or **not run** with a reason, and MUST NOT count a not-run adapter as passing.

**Docs**

- **FR-016**: `docs/guides/CODE-QUALITY.md` MUST describe the fixture directory as the source of truth for attribution rules and the change procedure (fixture first, implementations second, changelog names the file).
- **FR-017**: `docs/reference/INTEGRATIONS.md` MUST state the D1 precedence as the form adapter contract and list, per adapter, the stub-suite result and the live-suite result or not-run reason.
- **FR-018**: `docs/architecture/CODE-MAP.md` MUST list the JS rule module and the driver directory.

### Key Entities

- **Rule fixture**: a JSON file naming one attribution rule, with cases and documented exceptions. Read by both test suites. Its `version` is bumped when the rule changes on purpose.
- **JS rule module**: the internal home of the pure attribution rules, called by the runtime and loaded by the Node harness. Holds functions only, no visitor data.
- **Adapter driver**: a test helper per form plugin with `submit(array $cookies, array $posted, array $form): void` and `persisted(): array`. Owns that plugin's stubs.
- **Driver registry**: the map from production adapter class to driver class, compared against `Form_Integration_Manager`'s list.
- **Conformance scenario**: one US2 row, run for every adapter. Written once.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Reverting #137 (`refresh_submitted_fields()`) makes at least one Gravity Forms conformance assertion fail and no assertion for any other adapter fail.
- **SC-002**: Reverting the 1.10.5 CF7 cookie-precedence change makes at least one CF7 conformance assertion fail and no assertion for any other adapter fail.
- **SC-003**: Changing the click-ID precedence in any one of the five implementations (the JS rule module plus four PHP) makes at least one fixture assertion naming that implementation fail, and no assertion naming an unchanged implementation fail.
- **SC-004**: `payment-referrer.test.js` contains no regex over PHP source.
- **SC-005**: Deleting any one driver, or adding an adapter class without a driver, fails the registry test with the class named.
- **SC-006**: `composer run test` wall time on the CI runner grows by no more than 5 seconds. If process isolation makes that impossible, the plan records the measured cost and the decision.
- **SC-007**: The package job fails on a zip that carries the test-hook marker and passes on a clean build.
- **SC-008**: The live suite reports all six adapters, with CF7 plus each free plugin the plan selects passing, and Gravity Forms and Elementor Pro reported as not run with a reason.

## Compatibility

- **CF7**: today posted values fill any key the cookie lacks (`array_merge`). Under D1, posted values are used only when no usable cookie exists. A submission whose cookie lacks a key that a posted field carries no longer gets that key from the post.
- **Elementor Forms**: today posted `ct_*` values win outright and the cookie is read only when no posted value exists, and posted values pass only `sanitize_text_field()`. Under D1 the cookie wins, and posted values get the full `Attribution_Provider::sanitize()`.
- **Fluent Forms, Gravity Forms, Ninja Forms, WPForms**: today they read the cookie only. Under D1 they also accept posted `ct_*` values when no usable cookie exists.
- Entries already stored are not rewritten.
- The changelog entry for the implementing release MUST list these behavior changes per adapter.

## Assumptions

- The six adapters' hook argument shapes in their docblocks and `register_hooks()` calls are accurate. They were not checked against each host plugin's source; the plan verifies them (research R3). The Gravity Forms driver can start from `GravityFormsSubmissionRefreshTest.php`, the Fluent Forms driver from `FluentFormsAdapterTest.php`.
- The host plugin APIs the drivers need (`gform_add_meta`, `GFAPI::get_form`, `wpFluent()`, WPForms entry helpers, Ninja Forms submission objects) can be stubbed with small classes under `tests/helpers/`, as the Fluent Forms test already does. No host plugin is installed in the PHPUnit job.
- Which free plugins the live suite covers (likely Fluent Forms, Ninja Forms and WPForms Lite next to CF7) depends on whether each free edition supports the hidden fields the scenario needs. This is not verified yet (research R5).
- HubSpot, Calendly and WooCommerce checkout paths are out of scope except for the WooCommerce flattener named in FR-004.
