# Tasks: Form Adapter Conformance Suite and Shared Attribution Fixtures

**Input**: [spec.md](spec.md), [plan.md](plan.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/](contracts/), [quickstart.md](quickstart.md)

**Tests**: this feature *is* test infrastructure, so test tasks are the deliverable, not optional extras.

**Commit rule**: one task per commit unless a task says otherwise. Each R4 fix and each adapter's D1 move is its own commit, so any one can be reverted alone (plan, Risks). Every commit runs `composer run test`, `npm run smoke`, `npm run test:consent` and `npm run test:attribution` green, plus `npm run test:rules` once it exists.

**Plan phase ↔ story**: plan phase 1 = US1, plan phase 2 = US2, plan phase 3 = US3 + US4, plan phase 4 = US5.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an incomplete task)
- **[Story]**: US1–US5 from spec.md

---

## Phase 1: Setup

- [ ] T001 Record baselines in `specs/004-adapter-conformance-and-shared-fixtures/research.md` (append an "R8. Baselines" section): local `time composer run test` and the PHPUnit step duration from the latest green run of `.github/workflows/phpunit.yml` on `main` (read it from the Actions tab or `mcp__github__actions_list`). SC-006 compares against these numbers.
- [ ] T002 [P] Create empty directories with a `.gitkeep` each: `tests/fixtures/attribution-rules/`, `tests/helpers/attribution-rules/`, `tests/helpers/form-drivers/`, `tools/qa/attribution-rules/`. Confirm `tests/` and `tools/` are listed in `.distignore` (they are as of 2026-10-30; do not edit it).
- [ ] T003 [P] Add `"test:rules": "node tools/qa/attribution-rules.test.js"` to `scripts` in `package.json`, and create `tools/qa/attribution-rules.test.js` as a stub that prints `no fixtures yet` and exits 0, so the script exists for later phases.

---

## Phase 2: Foundational (blocks US1 and US2)

**Purpose**: the fixture loaders both languages share, and the behavior-preserving JS extraction. Nothing in this phase may change runtime behavior.

- [ ] T004 Move the pure rules into a `window.ClickTrailRules` IIFE at the top of `assets/js/clicutcl-attribution.js`, before the existing runtime IIFE, per `contracts/js-rule-module.md`. Move, do not copy: `TOUCH_QUERY_FIELD_MAP`, `CLICK_ID_KEYS`, `CLICK_ID_ALIASES`, `ATTRIBUTION_KEY_ALIASES`, `PAID_MEDIUMS`, `sanitizeKey`, `canonicalizeAttributionKey`, `sanitizeValue`, `sanitizeAttributionData` (with `sanitizeClickIdHistory`, which it calls), `pickClickId`, `withCanonicalClickIds`, `normalizeHostname`, `parseUrlSafely`, `areRelatedHosts`, `hostMatchesDomain`, `hostMatchesLabel`, `paidLabelFromSource`, `resolveChannelLabel`, and the body of `mapQueryFields` as a free function `mapQueryFields(params)`. Make implicit inputs explicit: `parseUrlSafely(rawUrl, baseUrl)` keeps `baseUrl` required inside the module; `resolveChannelLabel(params, referrer, pageUrl)`; `isIgnoredReferrer(referrer, ignoredHosts, pageUrl)`. In the runtime IIFE, bind local names to the module members (`const { sanitizeValue, ... } = window.ClickTrailRules;`) and pass `window.location.href` and the existing `IGNORED_REFERRER_HOSTS` at each call site. `ClickTrailAttribution.prototype.mapQueryFields` becomes a one-line call to the module. Freeze the module and every constant. Skip redefinition when `window.ClickTrailRules` already has the same `version`.
- [ ] T005 Verify T004 changed nothing: `npm run smoke && npm run test:consent && npm run test:attribution` pass with **no** edits to those test files, and `grep -n "function sanitizeValue\|function resolveChannelLabel\|function withCanonicalClickIds" assets/js/clicutcl-attribution.js` shows each defined exactly once. If any test needed editing, redo T004. (quickstart §1)
- [ ] T006 [P] Write the PHP fixture loader `tests/helpers/attribution-rules/class-rule-fixture-loader.php` (global class `ClickTrail_Rule_Fixture_Loader`): `load_all(): array` reads every `tests/fixtures/attribution-rules/*.json` with `json_decode(..., true, 512, JSON_THROW_ON_ERROR)`, hand-checks the required fields of `contracts/rule-fixture.schema.json` (research R7): `rule` equals the file name, `version` int ≥ 1, `notes` string, `implementations` with `js.`/`php.` prefixes, unique kebab-case case `id`s, each case has `input` XOR one or more of `raw`/`flattened`/`nested`, and each `exceptions[]` entry has non-empty `reason` and `regression_test` whose file part (before `::`) exists relative to the repo root (FR-002). Throws `RuntimeException` naming the file and the field on any violation.
- [ ] T007 [P] Write the JS fixture loader `tools/qa/attribution-rules/loader.js` with the same checks and error messages as T006 (FR-002, FR-006).
- [ ] T008 Add `tests/unit/RuleFixtureLoaderTest.php` and a loader self-test in `tools/qa/attribution-rules.test.js` (replacing the T003 stub body), each feeding the loader in-memory fixtures with: an exception missing `reason`, a case with both `input` and `raw`, a duplicate case `id`, and a `regression_test` path that does not exist. Assert each is rejected with the file and field named.

**Checkpoint**: runtime unchanged; loaders reject malformed fixtures in both languages.

---

## Phase 3: User Story 1 — JS and PHP rules checked against one set of fixtures (P1) 🎯 MVP

**Goal**: one fixture per rule, run against every implementation, with every divergence resolved or documented.

**Independent test**: `npm run test:rules` and `vendor/bin/phpunit tests/unit/AttributionRulesFixtureTest.php` pass; quickstart §2 drift check fails naming only the changed implementation (SC-003).

### Implementation registries and suites

- [ ] T009 [P] [US1] Write `tools/qa/attribution-rules/implementations.js`: load the real `assets/js/clicutcl-attribution.js` in a `vm` context with the minimal `window` from `tools/qa/payment-referrer.test.js` (factor that window builder into `tools/qa/attribution-rules/vm-window.js` and import it from both files), then map IDs to callables on `window.ClickTrailRules`: `js.withCanonicalClickIds` (reads `flattened`), `js.mapQueryFields` (reads `raw.params`), `js.isIgnoredReferrer` (reads `input.referrer`, `input.page_url`, `input.ignored_hosts`), `js.resolveChannelLabel` (reads `raw.params`, `raw.referrer`, `raw.page_url`), `js.sanitizeAttributionData` (reads `input`).
- [ ] T010 [P] [US1] Write `tests/helpers/attribution-rules/class-rule-implementations.php` (global class `ClickTrail_Rule_Implementations`) mapping IDs to closures: `php.attribution_provider.sanitize` (`Attribution_Provider::sanitize( $flattened )`), `php.eventv2.normalize_click_ids` and `php.token.normalize_click_ids` (via `ReflectionMethod` + `setAccessible(true)` on `CLICUTCL\Tracking\EventV2::normalize_click_ids` and on the trait method `normalize_click_ids_for_token` through a minimal class that `use`s `CLICUTCL\Api\Tracking_Controller_Attribution_Token_Trait` from `includes/api/traits/trait-tracking-controller-attribution-token.php`), `php.woocommerce.flatten` (reflection on `WooCommerce::flatten_attribution_for_event`, reads `nested`; construct `WooCommerce` the way `tests/unit/WooCommerceDataLayerContractTest.php` does), `php.attribution_provider.field_mapping` (returns `Attribution_Provider::get_field_mapping()`), `php.attribution_provider.ignored_hosts` (computes `is ignored` for `input.referrer` against `get_ignored_referrer_hosts()` using the same host-or-subdomain rule documented in that method's filter docblock), `php.gf_channel_resolver.resolve` (reads `flattened`, `flattened.referrer`, host of `flattened.page_url`).
- [ ] T011 [US1] Write `tools/qa/attribution-rules.test.js` (full body) and `tests/unit/AttributionRulesFixtureTest.php`: for every fixture, for every implementation with this suite's prefix, for every case, compare the result to `expected` (or to the matching `exceptions[]` entry's `expected`). Fail if a fixture lists an implementation ID of this suite's prefix that the registry lacks. Failure message: `<file>::<case id>::<implementation>` plus expected/actual (FR-006). PHPUnit: one data-provider row per (file, case, implementation), no process isolation (research R2).

### Fixtures (written from current JS behavior; R4 failures are expected at first)

- [ ] T012 [P] [US1] Write `tests/fixtures/attribution-rules/click-id-precedence.json` (implementations from data-model §2). Cases at minimum: `lt-wins-over-ft` (`ft_gclid=A`, `lt_gclid=B` → `gclid=B`), `ft-fills-when-no-lt` (`ft_gclid=A` → `gclid=A`), `top-level-kept-when-no-touch` (`gclid=C` only → `gclid=C`), `extended-ids` (one case each for `rdt_cid`, `pin_cid`, `snap_cid`, `mc_cid`, `mc_eid`, `dclid` as `lt_`), `sccid-from-sc-click-id` (`lt_sc_click_id=S` → `sccid=S`). `expected` lists only the top-level click-ID keys; the PHP and JS implementations compare that subset. Each case carries `flattened` and a `nested` equivalent (`{ first_touch: {...}, last_touch: {...} }`) for `php.woocommerce.flatten`.
- [ ] T013 [P] [US1] Write `tests/fixtures/attribution-rules/touch-mapping.json`: `raw.params` with every key in `TOUCH_QUERY_FIELD_MAP` plus each click ID; `expected` = the mapped field names. Add a `capture-cap-128` case (a 200-character `utm_campaign` → 128 characters). For `php.attribution_provider.field_mapping`, the comparison is "every `ft_<field>` and `lt_<field>` for each expected field is in `get_field_mapping()`"; state that in `notes`.
- [ ] T014 [P] [US1] Write `tests/fixtures/attribution-rules/ignored-referrers.json` from the current default list in `Attribution_Provider::IGNORED_REFERRER_HOSTS`: one `true` case per host, one subdomain case (`www.paypal.com`), one `false` near-miss (`notpaypal.com`), one non-HTTP referrer (`android-app://...` → `false`). `input.ignored_hosts` holds the default list for the JS side.
- [ ] T015 [P] [US1] Write `tests/fixtures/attribution-rules/channel-label.json`: one case per branch of JS `resolveChannelLabel` (each click-ID label, `fbclid` with and without each paid medium, Mailchimp, HubSpot, Pardot, Constant Contact, paid medium with no click ID for each `paidLabelFromSource` source, each AI assistant, each organic search and social host, same-site referrer, no signal → `Unknown`). Each case has `raw` and the equivalent `flattened` (`ft_source`, `ft_medium`, click IDs as `ft_*`, `referrer`, `page_url`). Write the research R4 #4 same-site representation note in `notes`.
- [ ] T016 [P] [US1] Write `tests/fixtures/attribution-rules/value-sanitization.json` (ClickTrail-owned behavior only, research R6; say so in `notes`): macro `{{campaign.name}}` dropped, 300-character value capped at 255, control characters, legacy key aliases (`_fbc` → `fbc`, `first_landing_page` → `ft_landing_page`).
- [ ] T017 [US1] Run both suites. Paste the failing `file::case::implementation` list into the T017 commit message. Expected failures map to research R4 rows 1, 2, 3, 5, 6, 7. Any failure not on that list is a new finding: add it to research R4 with a decision before continuing.

### Resolve R4 (one commit each; changelog line for each behavior change)

- [ ] T018 [P] [US1] R4 #1: in `includes/integrations/forms/class-gf-channel-resolver.php`, replace `array( 'cpc', 'paid_social', 'paid' )` with the JS list `cpc, ppc, paid, paidsearch, paid_social` in a class constant used by every paid-medium check.
- [ ] T019 [US1] R4 #2 (after T018, same file): port `paidLabelFromSource()` as a private method and add the "paid medium with no surviving click ID" branch at the same position as in JS (after the email-platform checks, before referrer classification).
- [ ] T020 [US1] R4 #3 (after T019): give `Gf_Channel_Resolver::resolve()` an optional `string $prefix = 'ft_'` used for `medium`, `source` and click-ID lookups. In `includes/integrations/forms/class-gravity-forms-adapter.php`, `on_submission()` resolves `lt_channel` with prefix `lt_` (through `resolve_channel_fallback()`, which gains the same optional parameter; keep the public signature backward-compatible). Add `lt-prefix` cases to `channel-label.json`.
- [ ] T021 [P] [US1] R4 #5 (EventV2): in `includes/tracking/class-eventv2.php`, `normalize_click_ids()` iterates `Attribution_Provider::get_click_id_fields()` (16 IDs) instead of the 10-key literal.
- [ ] T022 [P] [US1] R4 #5 (token): same change in `normalize_click_ids_for_token()` in `includes/api/traits/trait-tracking-controller-attribution-token.php`. Keep its `ScCid` alias branch.
- [ ] T023 [US1] R4 #6: add an `exceptions[]` entry to `click-id-precedence.json` for `php.token.normalize_click_ids` on a new `scc-legacy-alias` case (`lt_ScCid=S` → token `sccid=S`, others leave `sccid` unset), reason "legacy token payloads carry ScCid", and a regression test `tests/unit/AttributionTokenScCidAliasTest.php::test_legacy_sccid_alias` that pins it.
- [ ] T024 [P] [US1] R4 #7: in `assets/js/clicutcl-attribution.js` (`ClickTrailRules.sanitizeAttributionData`), change the storage cap from 256 to 255. Leave the 128 capture cap in `mapQueryFields`.
- [ ] T025 [US1] Re-run `tools/qa/attribution-rules.test.js` and `tests/unit/AttributionRulesFixtureTest.php`: green with only the T023 exception. Run quickstart §2 drift and exception checks and record the result in the PR description.
- [ ] T026 [P] [US1] Point `tools/qa/payment-referrer.test.js` at `tests/fixtures/attribution-rules/ignored-referrers.json` (via `tools/qa/attribution-rules/loader.js`) and delete `defaultIgnoredHosts()`'s PHP regex (SC-004, quickstart §3). Use the T009 `vm-window.js` builder.
- [ ] T027 [P] [US1] Add the fixture tests to the Tests list in `docs/architecture/CODE-MAP.md`, plus `window.ClickTrailRules` marked internal (FR-018). Add a "Attribution rule fixtures" section to `docs/guides/CODE-QUALITY.md`: fixtures are the source of truth; change procedure is fixture first, implementations second, bump `version`, name the file in the changelog (FR-016).
- [ ] T028 [US1] Add a `= Unreleased =` changelog entry in `changelog.txt` covering T018–T022 and T024 as user-visible changes: server-side Gravity Forms channel fallback labels (paid mediums, paid-without-click-ID, last-touch channel from last touch), six more click IDs in server events and cross-domain tokens, stored values capped at 255 instead of 256.

**Checkpoint**: US1 complete and shippable as its own PR.

---

## Phase 4: User Story 2 — Every form adapter passes the same scenarios (P1)

**Goal**: D1 implemented once and used by all six adapters, proven by eleven scenarios per adapter.

**Independent test**: `vendor/bin/phpunit tests/unit/FormAdapterConformanceTest.php` green; quickstart §4 reverts fail only the targeted adapter (SC-001, SC-002).

**Depends on**: US1 (FR-007 reads click-ID expectations from `click-id-precedence.json`).

### Shared resolver

- [ ] T029 [US2] Add `Attribution_Provider::is_usable_payload( array $sanitized ): bool` and `Attribution_Provider::get_identity_payload(): array` to `includes/Core/class-attribution-provider.php` per `contracts/form-adapter-precedence.md`. Refactor `get_payload()` to use `get_identity_payload()` for `visitor_id`/`session_id` (no behavior change).
- [ ] T030 [US2] Add `Abstract_Form_Adapter::resolve_submission_attribution( array $posted_ct ): array` to `includes/integrations/forms/class-abstract-form-adapter.php` with the four-step order in the contract. Add `tests/unit/SubmissionAttributionResolverTest.php` covering the `none`/`cookie`/`posted`/`none` states, consent denied, consent missing, identity-only cookie (US2-5) and non-`ct_` keys ignored.

### Drivers (host shapes from research R3)

- [ ] T031 [US2] Write `tests/helpers/form-drivers/interface-form-adapter-driver.php` (`ClickTrail_Form_Adapter_Driver`, contract `adapter-driver.md`) and `tests/helpers/form-drivers/class-wpdb-recorder.php`, a `$wpdb` stub whose `insert()` records rows for `event_rows()` and whose `prefix` is `wp_`.
- [ ] T032 [P] [US2] `tests/helpers/form-drivers/class-cf7-driver.php`: stub `WPCF7_Submission::get_instance()` returning an object whose `get_posted_data()` returns `$posted_ct` plus a name field, and `WPCF7_ContactForm` with `id()`. Call `on_submission( $contact_form )` with one argument (R3). `persisted()` = last event row's attribution. Reuse stubs from `tests/unit/CF7SubmissionOutcomeTest.php` where they exist (move shared stubs into the driver file, then require it from that test).
- [ ] T033 [P] [US2] `tests/helpers/form-drivers/class-elementor-forms-driver.php`: stub record object with `get( 'fields' )` returning `ct_*` fields in the shape `extract_record_fields()` reads, and `get( 'form_settings' )` for the form ID. Call `on_submission( $record, $handler )`. `persisted()` = last event row's attribution.
- [ ] T034 [P] [US2] `tests/helpers/form-drivers/class-fluent-forms-driver.php`: build `$formData` from `$posted_ct` plus one defined input, then apply Fluent's intersection: keep only defined input keys plus `apply_filters( 'fluentform/white_listed_fields', <Fluent's default list from research R3>, $form_id )` (the bootstrap `apply_filters` stub runs callbacks in `$GLOBALS['clicktrail_test_filters']`, so register the adapter's filter callback there). Fire `fluentform/submission_inserted` only (one name, not both; edge case in spec). `persisted()` from the `wpFluent()` stub's `fluentform_submission_meta` inserts (start from `tests/unit/FluentFormsAdapterTest.php`). Reset the adapter's static `$logged` guard in `reset()` via reflection.
- [ ] T035 [P] [US2] `tests/helpers/form-drivers/class-gravity-forms-driver.php`: stubs `GFAPI::get_form`, `gform_add_meta` (records into driver storage), `gform_get_meta`, and form fields as in `tests/unit/GravityFormsSubmissionRefreshTest.php` (hidden fields with `allowsPrepopulate` and `inputName` = `ct_*`). Order: set `$_POST` from `$posted_ct` as `input_<id>`, call `refresh_submitted_fields( $form )`, build `$entry` from `$_POST` (unslashed), then `on_submission( $entry, $form )`. `$options['tracking_enabled']` sets `$form['clicutcl_tracking_enabled']`. `persisted()` = recorded `gform_add_meta` values with the `ct_` prefix stripped.
- [ ] T036 [P] [US2] `tests/helpers/form-drivers/class-ninja-forms-driver.php`: build `$form_data = [ 'id' => …, 'fields' => [ id => [ 'id', 'key' => 'ct_*', 'value' ] ], 'settings' => [], 'extra' => [] ]`, run `inject_attribution( $form_data )` (one argument, R3), then build `$data` with `form_id`, `fields`, `fields_by_key`, `extra` from the filtered result and call `on_submission( $data )`. `persisted()` = `extra['clicktrail_attribution']` as the adapter wrote it (stub whatever the adapter uses to save it, read from `class-ninja-forms-adapter.php` and `class-ninja-forms-submission-extra-handler.php`).
- [ ] T037 [P] [US2] `tests/helpers/form-drivers/class-wpforms-driver.php`: `$fields[<id>] = [ 'name' => 'ct_lt_source', 'value' => …, 'id' => <id>, 'type' => 'hidden' ]` for each posted key (label = `ct_*` name, research R3 decision), plus one non-hidden field; `$entry = [ 'fields' => … ]`; `$form_data = [ 'id' => … ]`; `$entry_id = 0` (Lite). Call `on_submission( $fields, $entry, $form_data, 0 )`. `persisted()` = last event row's attribution.
- [ ] T038 [US2] `tests/helpers/form-drivers/registry.php` returning the six-entry map in `contracts/adapter-driver.md`, and a `require_once` for every driver file.

### Conformance suite (against current adapters first)

- [ ] T039 [US2] Write `tests/unit/FormAdapterConformanceTest.php`: data provider = registry × the eleven scenarios of spec US2, written once as methods taking a driver. Expected click-ID values in US2-1 and US2-2 come from `click-id-precedence.json` cases `lt-wins-over-ft` / `ft-fills-when-no-lt` via `ClickTrail_Rule_Fixture_Loader` (FR-007). `setUp()` calls `reset()` on every driver and sets `Consent_Mode_Settings` stub globals and the `production_consent` cookie name. Each failure message starts with the adapter's short class name. Print, once per run, the adapters exercised and scenario counts (US3-4).
- [ ] T040 [US2] Run T039 against the **unchanged** adapters. Record the failing (adapter, scenario) pairs in `specs/004-adapter-conformance-and-shared-fixtures/research.md` as "R9. D1 behavior delta". This is the changelog source for T048. Do not fix anything in this task.

### Move adapters onto D1 (one commit per adapter; each makes its rows green)

- [ ] T041 [P] [US2] CF7, `includes/integrations/forms/class-cf7-adapter.php`: build `$posted_ct` from `get_posted_data()`, call `resolve_submission_attribution()`, log only when `source !== 'none'`. Remove the `array_merge` per-key fill and its comment. Correct the docblock to the one-argument `wpcf7_mail_sent` hook (R3).
- [ ] T042 [P] [US2] Elementor, `includes/integrations/forms/class-elementor-forms-adapter.php`: build `$posted_ct` from `extract_record_fields()` and use the resolver. The cookie now wins; posted values go through `Attribution_Provider::sanitize()` inside the resolver.
- [ ] T043 [P] [US2] Fluent Forms, `includes/integrations/forms/class-fluent-forms-adapter.php`: register `fluentform/white_listed_fields` (2 args) appending ClickTrail's `ct_*` names (`get_field_name()` over `Attribution_Provider::get_field_mapping()`), then use the resolver with `$form_data` in `on_submission`.
- [ ] T044 [P] [US2] Gravity Forms, `includes/integrations/forms/class-gravity-forms-adapter.php`: in `on_submission`, build `$posted_ct` from entry values of fields whose `inputName` (or `inputs[].name`) starts with `ct_`, and use the resolver. In `refresh_submitted_fields()`, write only when the cookie is usable, and set inputs whose key the cookie lacks to `''`. Update `tests/unit/GravityFormsSubmissionRefreshTest.php`: the assertion "Keys missing from the cookie keep the posted value" becomes "are cleared", and add a case where the cookie is unusable and posted values are left as-is.
- [ ] T045 [P] [US2] Ninja Forms, `includes/integrations/forms/class-ninja-forms-adapter.php`: in `on_submission`, build `$posted_ct` from `$data['fields_by_key'][*]['value']` for `ct_*` keys and use the resolver.
- [ ] T046 [P] [US2] WPForms, `includes/integrations/forms/class-wpforms-adapter.php`: build `$posted_ct` from `$fields` entries with `type === 'hidden'` whose `name` (label) is a `ct_*` name, and use the resolver. Remove the `wpforms_field_value_*` filter registration and `populate_field()` (no such filter in Lite, R3). Keep the `entry_meta` write guarded by `$entry_id > 0` for Pro.
- [ ] T047 [US2] Run quickstart §4: revert T044's `refresh_submitted_fields()` hook and, separately, T041's cookie precedence, in a scratch branch. Record the failing assertions (SC-001, SC-002) for the PR description. Discard the scratch branch.

### Docs

- [ ] T048 [US2] `docs/reference/INTEGRATIONS.md`: state D1 as the form adapter contract; per adapter, how posted `ct_*` values are read (WPForms: Hidden field labeled with the `ct_*` name; Fluent: whitelisted via `fluentform/white_listed_fields`); remove the claim that ClickTrail populates WPForms hidden fields; add the per-adapter stub-suite result (FR-017). `docs/guides/SECURITY-PRIVACY.md`: one line that posted `ct_*` values pass the consent gate and `Attribution_Provider::sanitize()`. `changelog.txt` `= Unreleased =`: per-adapter behavior changes from R9 and spec Compatibility. Check `docs/reference/integration-capabilities.json` for any field describing form precedence or population, and update it if present.

**Checkpoint**: US2 complete and shippable as its own PR.

---

## Phase 5: User Story 3 — Adapter coverage is enforced (P1)

**Goal**: the test run fails, naming the class, whenever production adapters, interface implementers and drivers disagree.

**Independent test**: quickstart §5.

**Depends on**: T038 (registry).

- [ ] T049 [US3] In `includes/integrations/class-form-integration-manager.php`, add `public const ADAPTER_CLASSES = array( <class> => <relative file>, ... )` with the six current entries and make `register_adapters()` iterate it instead of the local `$potential_adapters` array (FR-011). No behavior change.
- [ ] T050 [US3] Write `tests/unit/FormAdapterRegistryTest.php`: (a) keys of `registry.php` equal the keys of `Form_Integration_Manager::ADAPTER_CLASSES`; (b) scanning `includes/integrations/forms/class-*-adapter.php`, requiring each file, and collecting declared classes that implement `CLICUTCL\Integrations\Forms\Form_Adapter_Interface` and are not abstract gives the same set; (c) each driver's `adapter_class()` equals its key. Failure messages name the missing or extra class (FR-012, SC-005).
- [ ] T051 [US3] In `FormAdapterConformanceTest`, make a driver whose stubs fail to load produce a failing assertion (catch the throwable from driver construction and `fail()` with the adapter name), never `markTestSkipped`/`markTestIncomplete`. Run quickstart §5's two negative checks in a scratch branch.

---

## Phase 6: User Story 4 — Release zip carries no test interface (P2)

**Goal**: the package job fails on a zip with tests, tools, specs or the test-hook marker.

**Independent test**: quickstart §6.

- [ ] T052 [US4] In `tools/release/make-zip.js` `validateManifest()`: fail when any entry starts with `<slug>/tests/`, `<slug>/tools/` or `<slug>/specs/`; then for each `.js` entry run `unzip -p <zip> <entry>` and fail when the content contains `__clicutclTest`, naming the entry (FR-013). Apply the same checks on the Windows path the script uses (read the `win32` branch at the end of the file first).
- [ ] T053 [US4] Run quickstart §6 positive and negative checks; confirm `.github/workflows/package.yml` (which runs `npm run make-zip`) fails on the negative case in a scratch branch push or a local `act`-free reproduction.

---

## Phase 7: User Story 5 — Live end-to-end with real form plugins (P3)

**Goal**: landing-page and navigate-then-submit proven in a real request cycle for CF7, Fluent Forms, Ninja Forms and WPForms Lite; GF and Elementor Pro reported `not_run`.

**Independent test**: quickstart §8.

**Depends on**: US2 (adapters on D1).

- [ ] T054 [US5] In `tools/qa/e2e/setup.sh`, install pinned `fluentform` 6.2.16, `ninja-forms` 3.15.7 and `wpforms-lite` 2.0.3.1 next to the pinned CF7 (same `wp plugin install --version=… --activate` pattern). For each, create one form with Hidden fields for `ct_ft_source`, `ct_lt_source`, `ct_lt_medium`, `ct_lt_campaign`, `ct_fbclid` (Fluent: hidden inputs named `ct_*`; Ninja: hidden fields with key `ct_*`; WPForms: Hidden fields labeled `ct_*`) and one visible text field, each on its own page. Use WP-CLI and each plugin's documented import or `wp post`/`wp db` path. Record the commands that work in `tools/qa/e2e/README.md`.
- [ ] T055 [US5] In `tools/qa/e2e/run.py` and `verify.py`, add for each of CF7, Fluent, Ninja, WPForms the `landing-submit` (land on the form page with UTMs + `fbclid`, submit) and `navigate-then-submit` (land on home with UTMs, open the form page, submit) scenarios. Read back via WP-CLI: CF7, WPForms → `wp_clicutcl_events` rows; Fluent → `fluentform_submission_meta`; Ninja → submission `extra.clicktrail_attribution` (research R5). Write one JSON line per (adapter, scenario) to `$CT_E2E_OUT/report.jsonl` per data-model §8, and append `not_run` rows with reason `licence required` for Gravity Forms and Elementor Pro.
- [ ] T056 [US5] In `tools/qa/e2e/ci.sh` and `.github/workflows/e2e-attribution.yml`: fail the job on any `failed` row; print the report as a Markdown table to `$GITHUB_STEP_SUMMARY`; `not_run` rows appear in the table and never count as passing (FR-015).
- [ ] T057 [US5] Run `tools/qa/e2e/ci.sh` locally or in CI; on success, update the evidence label for each passing adapter in `docs/reference/INTEGRATIONS.md` (only those that pass; GF and Elementor Pro keep "source-present / runtime-unverified").

---

## Phase 8: Polish & cross-cutting

- [ ] T058 Add `npm run test:rules` as a step in `.github/workflows/phpunit.yml` after `npm run test:attribution` (FR-014).
- [ ] T059 Run quickstart §7: compare `composer run test` time to the T001 baselines. If the delta exceeds 5 s, record the measurement and the chosen remedy (per-driver isolation or none) in research.md R2 (SC-006).
- [ ] T060 [P] Run `vendor/bin/phpcs` on every changed PHP file with the repo's `phpcs.xml.dist` (in an environment where WordPress coding standards are installed) and fix violations.
- [ ] T061 Run all of `specs/004-adapter-conformance-and-shared-fixtures/quickstart.md` §1–§8 end to end and tick each Done-When in spec Success Criteria SC-001–SC-008 in the final PR description, with the evidence for each.

---

## Dependencies & execution order

```text
Setup (T001–T003)
  └─> Foundational (T004 → T005; T006, T007 in parallel → T008)
        └─> US1 (T009–T011 → T012–T016 → T017 → T018–T024 → T025 → T026–T028)
              └─> US2 (T029 → T030 → T031 → T032–T037 → T038 → T039 → T040 → T041–T046 → T047 → T048)
                    ├─> US3 (T049 → T050 → T051)
                    └─> US5 (T054 → T055 → T056 → T057)
US4 (T052 → T053) depends only on Setup and can run any time after T003.
Polish (T058–T061) after the stories it touches.
```

- US1 blocks US2 (FR-007 reads fixtures).
- US3 needs the driver registry from US2 (T038), not the adapter changes.
- US5 needs US2's adapter changes, or the live Fluent and WPForms rows fail for the reasons in research R3.
- US4 is independent.

## Parallel opportunities

- **Foundational**: T006 ∥ T007 (PHP and JS loaders), both while T004/T005 run.
- **US1**: T009 ∥ T010 (registries); T012–T016 (five fixtures, separate files); T018 ∥ T021 ∥ T022 ∥ T024 (different files; T019 and T020 follow T018 in the same file); T026 ∥ T027.
- **US2**: T032–T037 (six driver files); T041–T046 (six adapter files, after T040).
- **Across stories**: US4 alongside anything; US3 alongside the second half of US2 once T038 lands.

### Example: US2 drivers in parallel

```text
T032 class-cf7-driver.php
T033 class-elementor-forms-driver.php
T034 class-fluent-forms-driver.php
T035 class-gravity-forms-driver.php
T036 class-ninja-forms-driver.php
T037 class-wpforms-driver.php
```

## Implementation strategy

1. **MVP = Setup + Foundational + US1** (T001–T028). This alone delivers the drift protection the 1.10.5 click-ID change lacked and lands the R4 fixes. Ship as PR 1.
2. **PR 2 = US2** (T029–T048). The D1 behavior change ships with its per-adapter changelog.
3. **PR 3 = US3 + US4 + T058** (enforcement and CI wiring).
4. **PR 4 = US5** (live suite), the slowest to build and the one that moves evidence labels.

Stop after each PR, run quickstart for that phase, and merge only on green CI.
