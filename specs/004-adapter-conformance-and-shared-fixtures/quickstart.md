# Quickstart: validating spec 004

How to prove each phase works. Shapes and rules live in [data-model.md](data-model.md) and [contracts/](contracts/); this file lists only what to run and what to expect.

## Prerequisites

- PHP 8.1+ and `composer install` (PHPUnit 9.6)
- Node 18+ (`vm`, `URL`, `webcrypto`)
- Phase 4 only: podman, curl, python3 with Playwright and Chromium, as for the existing `tools/qa/e2e/ci.sh`

## 1. Phase 1 extraction changes nothing

Run before writing any fixture, right after moving the rules into `window.ClickTrailRules`:

```bash
npm run smoke && npm run test:consent && npm run test:attribution
```

**Expect**: all pass, with no edits to those test files. If any test needed editing, the extraction changed behavior and must be redone.

## 2. Fixtures run in both languages

```bash
npm run test:rules
vendor/bin/phpunit tests/unit/AttributionRulesFixtureTest.php
```

**Expect**: green, once every divergence in research R4 is fixed or recorded in `exceptions[]`. On the first run, before R4 is resolved, failures name `fixture::case::implementation` (FR-006).

**Drift check (SC-003)**: in a scratch commit, change the click-ID precedence in one implementation only (for example make `EventV2::normalize_click_ids()` prefer `ft_`). Expect failures naming `php.eventv2.normalize_click_ids` and no other implementation. Revert.

**Exception check (FR-002)**: add an `exceptions[]` entry with an empty `reason`. Expect the loader to fail with the file name. Revert.

## 3. Payment-referrer test no longer parses PHP (SC-004)

```bash
grep -n "readFileSync(.*\.php" tools/qa/payment-referrer.test.js
```

**Expect**: no output.

## 4. Conformance suite catches the two known regressions (SC-001, SC-002)

```bash
vendor/bin/phpunit tests/unit/FormAdapterConformanceTest.php
```

**Expect**: green, with output listing six adapters and their scenario counts (US3-4).

Then, one at a time in a scratch branch:

| Revert | Expect |
|---|---|
| `refresh_submitted_fields()` and its hook registration (#137) | One or more failures tagged `Gravity_Forms_Adapter`; none tagged with another adapter |
| CF7's cookie-wins logic (1.10.5) | One or more failures tagged `CF7_Adapter`; none tagged with another adapter |

Record the failing assertions in the PR description as SC-001 and SC-002 evidence.

## 5. Registry enforcement (SC-005)

```bash
vendor/bin/phpunit tests/unit/FormAdapterRegistryTest.php
```

**Expect**: green. Then in a scratch branch:
- delete one line from `tests/helpers/form-drivers/registry.php`: the test fails naming that adapter class;
- add a dummy `class-dummy-adapter.php` implementing `Form_Adapter_Interface` under `includes/integrations/forms/`: the test fails naming it.

## 6. Release zip carries no test interface (SC-007)

```bash
npm run make-zip
```

**Expect**: `ZIP created: dist/click-trail-handler-<version>.zip`. Then in a scratch branch, add the string `__clicutclTest` to a comment in `assets/js/clicutcl-events.js` and run it again. Expect `Archive manifest validation failed` naming that file.

## 7. Whole suite time (SC-006)

```bash
time composer run test
```

**Expect**: no more than 5 s above the pre-feature baseline on the same machine. The local baseline on 2026-10-30 was 1.3 s for 271 tests. The CI runner baseline still has to be read from the phpunit workflow before phase 2 merges.

## 8. Live suite (SC-008)

```bash
CT_E2E_OUT="$(mktemp -d)" tools/qa/e2e/ci.sh
```

**Expect**: a report table with all six adapters × two scenarios. CF7, Fluent Forms, Ninja Forms and WPForms Lite show `passed`; Gravity Forms and Elementor Pro show `not_run: licence required`. Exit code non-zero if any row is `failed`.
