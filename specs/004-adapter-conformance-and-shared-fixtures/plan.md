# Implementation Plan: Form Adapter Conformance Suite and Shared Attribution Fixtures

**Branch**: `ccr-91ff6eaf-3k60n9` | **Date**: 2026-10-30 | **Spec**: [spec.md](spec.md) | **Origin**: [#137](https://github.com/vizuh/click-trail-handler/pull/137)

## Summary

Make attribution behavior a tested contract instead of five hand-synchronized copies. Four phases, each shippable on its own:

1. **Rule fixtures and JS/PHP parity** (US1). Move the pure JS rules into a frozen in-file module, `window.ClickTrailRules`, without changing behavior. Add JSON rule fixtures and run them against the JS module and every PHP implementation. Resolve each divergence the first run exposes (research R4) as a code fix or a documented exception.
2. **Adapter conformance** (US2). Add one shared D1 resolver (`resolve_submission_attribution()`), move all six adapters onto it, and run eleven scenarios against each through per-adapter drivers.
3. **Enforcement** (US3, US4). Fail when production adapters, interface implementers and drivers disagree. Fail the package job when the zip carries tests, tools or a test-hook marker. Wire the new suites into CI.
4. **Live end-to-end** (US5). Extend the existing podman/WP-CLI/Playwright round trip to the free form plugins, with an explicit `not_run` report row for the licensed ones.

No monorepo, no package split, no build step, no framework dependency.

## Technical Context

**Language/Version**: PHP 8.1+ (plugin header `Requires PHP: 8.1`; CI PHPUnit matrix 8.1, 8.2, 8.3); browser ES2019 with no build step; Node for `tools/qa/` tests (`vm` harness, as in `payment-referrer.test.js`)
**Primary Dependencies**: none new. PHPUnit 9.6 (existing dev dependency). Live suite reuses podman, the `wordpress:php8.3-apache` and `mariadb:lts` images, WP-CLI and Python Playwright from `tools/qa/e2e/`.
**Storage**: none new. Tests stub `gform_add_meta`, `wpFluent()`, Ninja `extra`, WPForms entry meta and `$wpdb`.
**Testing**: `composer run test` (PHPUnit, `tests/unit/`); `npm run test:rules` (new); existing `npm run smoke`, `npm run test:consent`, `npm run test:attribution`; `tools/qa/e2e/ci.sh`.
**Target Platform**: WordPress 6.5+ sites; GitHub Actions `ubuntu-24.04`.
**Project Type**: single WordPress plugin.
**Performance Goals**: `composer run test` grows by ≤ 5 s on CI (SC-006). Measured locally: process isolation costs about 0.06 s per test, so 66 isolated conformance cases would add about 4 s; the plan avoids isolation (R2).
**Constraints**: no visitor data in the JS module; no release-time transform; consent gate before any attribution read; no change to script handles or enqueue order (R1).
**Scale/Scope**: 5 rule fixtures, 5 JS + 5 PHP implementation entries; 6 adapters × 11 scenarios; 4 free plugins in the live suite, 2 reported `not_run`.

## Repo gates

There is no ratified constitution (`.specify/memory/constitution.md` is the unfilled template), so, as in spec 003, the gates are the repo's own rules in `AGENTS.md`.

| Gate | Status |
|---|---|
| **Docs triggers.** Integration behavior changes (D1 changes CF7 and Elementor precedence; four adapters gain posted fallback): `INTEGRATIONS.md`, product readmes if support claims change. Quality posture: `CODE-QUALITY.md`. File layout (JS module, test helpers): `CODE-MAP.md`. | Planned per phase (below). `integration-capabilities.json` is checked in phase 2 for any field that describes precedence. |
| **Storage, cookie, queue triggers** (`DATA-MODEL.md`, `SECURITY-PRIVACY.md`, `OPERATIONS-RUNBOOK.md`) | Not triggered. No cookie, storage key or retention change. The JS module holds no data. Posted `ct_*` values were already persisted by CF7 and Elementor; extending that to four adapters is a precedence change, documented in `INTEGRATIONS.md`. `SECURITY-PRIVACY.md` gets one line confirming posted values pass `Attribution_Provider::sanitize()` and the consent gate. |
| **API, hook triggers** (`REST-API.md`, `HOOKS-REFERENCE.md`) | Not triggered. No new route or public hook. `window.ClickTrailRules` is internal and stays out of `HOOKS-REFERENCE.md` (contract rule 7). |
| **Evidence labels.** Adapters stay "source-present / runtime-unverified" until the live suite passes for them. | Phase 4 updates labels only for adapters the live suite passes. GF and Elementor Pro keep their label. |
| **Scope principle** (spec): no monorepo, versioning split or framework. | Holds. One new in-file JS object, helper classes under `tests/`, one public constant on `Form_Integration_Manager`. |

Post-design re-check: passes. The only production surface additions are `window.ClickTrailRules` (internal, data-free), `Form_Integration_Manager::ADAPTER_CLASSES`, `Attribution_Provider::is_usable_payload()` / `get_identity_payload()`, and `Abstract_Form_Adapter::resolve_submission_attribution()`.

## Project Structure

### Documentation (this feature)

```text
specs/004-adapter-conformance-and-shared-fixtures/
├── spec.md
├── plan.md              # this file
├── research.md          # R1–R7
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── rule-fixture.schema.json
│   ├── js-rule-module.md
│   ├── form-adapter-precedence.md
│   └── adapter-driver.md
└── tasks.md             # /speckit-tasks, not created here
```

### Source code touched

```text
assets/js/clicutcl-attribution.js            # phase 1: ClickTrailRules IIFE at top; runtime calls it
includes/Core/class-attribution-provider.php # phase 1 fixes per R4; phase 2 is_usable_payload(), get_identity_payload()
includes/integrations/forms/
├── class-gf-channel-resolver.php            # phase 1 fixes per R4
├── class-abstract-form-adapter.php          # phase 2 resolve_submission_attribution()
└── class-{cf7,elementor-forms,fluent-forms,gravity-forms,ninja-forms,wpforms}-adapter.php   # phase 2 D1
includes/integrations/class-form-integration-manager.php   # phase 3 ADAPTER_CLASSES constant
includes/tracking/class-eventv2.php                        # phase 1, only if R4 decides to align
includes/api/traits/trait-tracking-controller-attribution-token.php   # phase 1, only if R4 decides to align

tests/fixtures/attribution-rules/*.json      # phase 1
tests/helpers/attribution-rules/             # phase 1: fixture loader, PHP implementation registry
tests/helpers/form-drivers/                  # phase 2: driver interface, six drivers, host stubs, registry.php
tests/unit/AttributionRulesFixtureTest.php   # phase 1
tests/unit/FormAdapterConformanceTest.php    # phase 2
tests/unit/FormAdapterRegistryTest.php       # phase 3
tests/unit/GravityFormsSubmissionRefreshTest.php   # phase 2: whole-record expectation (see contract)

tools/qa/attribution-rules.test.js           # phase 1
tools/qa/attribution-rules/                  # phase 1: loader, JS implementation registry
tools/qa/payment-referrer.test.js            # phase 1: read ignored-referrers.json
tools/release/make-zip.js                    # phase 3: validateManifest() checks
tools/qa/e2e/{setup.sh,run.py,verify.py,ci.sh}     # phase 4
package.json                                 # phase 1: "test:rules"
.github/workflows/phpunit.yml                # phase 3: npm run test:rules step
.github/workflows/package.yml                # phase 3: zip check runs in make-zip
.github/workflows/e2e-attribution.yml        # phase 4
```

**Structure Decision**: no new top-level directory. Production changes stay in their existing modules. Test infrastructure goes under `tests/helpers/` (PHP) and `tools/qa/` (Node), next to the tests it serves, both already excluded from the release by `.distignore`.

## Phases

### Phase 1: Rule fixtures and JS/PHP parity (US1)

1. **Extract** the rule functions listed in [contracts/js-rule-module.md](contracts/js-rule-module.md) into a `window.ClickTrailRules` IIFE at the top of `clicutcl-attribution.js`. Make implicit inputs (`window.location.href`, `CONFIG.ignoredReferrerHosts`) explicit arguments. The runtime calls the module. **Gate**: existing `smoke`, `test:consent` and `test:attribution` pass with no test edits. This commit changes no behavior.
2. **Fixture loader** in both languages: validate against `rule-fixture.schema.json` (hand-rolled checks for the schema's required fields; no JSON Schema library dependency), enforce `exceptions[]` completeness (FR-002) and the `regression_test` file existence, and report `file::case::implementation` on failure (FR-006).
3. **Write the five fixtures** from current JS behavior first, since the JS writes the data PHP reads. Run them. Each failing PHP or JS implementation is a finding from R4.
4. **Resolve findings** one commit each, per the R4 decision table: fix the code, or add an `exceptions[]` entry with reason and regression test. Any fix that changes stored or reported values gets a changelog line.
5. **Repoint** `payment-referrer.test.js` at `ignored-referrers.json` (SC-004).
6. Docs: `CODE-QUALITY.md` (fixture procedure, FR-016), `CODE-MAP.md` (module and helpers, FR-018).

### Phase 2: Adapter conformance (US2)

1. **Resolver**: `Attribution_Provider::is_usable_payload()`, `get_identity_payload()`, `Abstract_Form_Adapter::resolve_submission_attribution()` per [contracts/form-adapter-precedence.md](contracts/form-adapter-precedence.md), with its own unit test covering the four states.
2. **Drivers** per [contracts/adapter-driver.md](contracts/adapter-driver.md), host argument shapes from R3. Start from `GravityFormsSubmissionRefreshTest` and `FluentFormsAdapterTest` stubs.
3. **Conformance test** with the scenarios written once and a data provider over the registry. Write it against **current** adapters first and record which scenarios fail for which adapter. That list is the D1 behavior delta and goes into the changelog draft.
4. **Move adapters onto the resolver**, one commit per adapter, each making its rows green. Host-specific work from R3:
   - **Fluent Forms**: add a `fluentform/white_listed_fields` filter for ClickTrail's `ct_*` names, or the injected inputs never reach the hook.
   - **WPForms**: recognize `ct_*` Hidden fields by label (R3 consequence 2, decided 2026-10-30). Remove the `wpforms_field_value_*` registration, which hooks a filter that does not exist in Lite. Correct the `INTEGRATIONS.md` population claim.
   - **CF7**: correct the docblock to the one-argument hook.
5. Prove SC-001 and SC-002 by reverting #137 and the 1.10.5 CF7 change locally and recording which assertions fail (quickstart step 4).
6. Docs: `INTEGRATIONS.md` D1 contract and per-adapter stub result (FR-017); `SECURITY-PRIVACY.md` one line; changelog per-adapter behavior changes (spec Compatibility).

### Phase 3: Enforcement (US3, US4)

1. `Form_Integration_Manager::ADAPTER_CLASSES` (public constant: class → file), used by `register_adapters()` in place of the local array (FR-011).
2. `FormAdapterRegistryTest`: the three-set comparison and driver `adapter_class()` check (FR-012, SC-005). The conformance test prints adapters and scenario counts (US3-4).
3. `make-zip.js` `validateManifest()`: reject entries under `tests/`, `tools/`, `specs/`, and any `.js` entry containing `__clicutclTest` (FR-013, SC-007). Same check in the PowerShell path the script uses on Windows.
4. `phpunit.yml`: add `npm run test:rules`.

### Phase 4: Live end-to-end (US5)

1. `setup.sh`: install pinned versions of the free plugins chosen in R5 next to CF7; create one form per plugin with `ct_*` hidden fields on its own page.
2. `run.py` / `verify.py`: for each plugin run `landing-submit` and `navigate-then-submit`, read the stored submission through WP-CLI, write report rows (data-model section 8). Emit `not_run: licence required` rows for Gravity Forms and Elementor Pro.
3. `e2e-attribution.yml`: fail on any `failed` row; print the table to the job summary.
4. Docs: `INTEGRATIONS.md` evidence labels for adapters that pass.

## Risks

- **Phase 1 extraction regresses the runtime.** Mitigated by the behavior-preserving gate (step 1) and by landing extraction in its own commit before any fixture-driven change.
- **R4 fixes change live attribution labels.** For example, aligning the PHP channel resolver with the JS paid-medium branch changes Gravity Forms' server-side `ft_channel` fallback from `Unknown` to `Google Ads` for `utm_medium=cpc` without a click ID. Each such fix is a separate commit with a changelog line, so it can be reverted alone.
- **D1 is a behavior change for CF7 and Elementor**, and Elementor currently trusts posted values over the cookie. Sites relying on the current behavior (for example a custom script writing `ct_*` fields that disagree with the cookie) will see the cookie win. Documented in the changelog per adapter.
- **Two adapters already do less than the docs claim** (R3): Fluent Forms never sees ClickTrail's injected inputs, and WPForms' populate filter does not exist in Lite. Phase 2 fixes both and corrects `INTEGRATIONS.md`. Until then the docs overclaim. Consider correcting them sooner, in a docs-only commit.
- **Stubs drift from host plugins.** Drivers encode host argument shapes from R3 at the versions read there; a host update can change them. The live suite (phase 4) is the check for the four free plugins; GF and Elementor Pro remain stub-only.
- **PHPUnit `sanitize_text_field` is a stub.** `tests/bootstrap.php` only strips control characters and trims; WordPress's real function also strips tags, collapses whitespace and removes percent-encoded octets. PHP sanitization parity is proven against the stub only (R6).
- **Global state leaks between conformance cases** without process isolation. Mitigated by driver `reset()` in `setUp()`; if a leak is found, isolate that driver's class only.

## Complexity Tracking

No gate violations.
