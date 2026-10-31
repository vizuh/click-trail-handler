# Tasks: Optimizer Exclusions For ClickTrail Scripts

**Input**: [spec.md](spec.md), [plan.md](plan.md), [research.md](research.md)

## Phase 0 - Decide the open attribute

- [ ] T001 Confirm no vendor source added a `data-no-minify` reader since research.md R2 was written (WP Rocket `inc/Engine/Optimization/Minify/JS`, LiteSpeed `src/optimize.cls.php`, Autoptimize `classes/autoptimizeScripts.php`); if one has, reinstate it in FR-002 with the citation. - `specs/006-optimizer-exclusions/research.md`

## Phase 1 - US1 and US2 (P1): one class, all handles, correct attributes

- [ ] T002 Create `CLICUTCL\Integrations\Optimizer_Compat` with `HANDLES` (four handles), `ATTRIBUTES` (R2), `register()`, `add_attributes()` on `wp_script_attributes` keyed on `id = "{handle}-js"`, and `mark_tag()` on `script_loader_tag` with a per-attribute presence check that targets the `<script` token carrying the handle id (FR-001 to FR-003, plan.md Design). - `includes/integrations/class-optimizer-compat.php`
- [ ] T003 Register `Optimizer_Compat::register()` after `NitroPack_Compat::register()`; add the `use` import next to `NitroPack_Compat`. - `includes/class-clicutcl-core.php:21,178`
- [ ] T004 Remove `new Gf_Minification_Protector();` and the docblock mention; delete the old class file (FR-004). - `includes/integrations/forms/class-gravity-forms-adapter.php:8,104`, `includes/integrations/forms/class-gf-minification-protector.php`
- [ ] T005 Add `nowprocket data-no-defer="1"` to the inline consent default snippet (FR-006, R4). - `includes/Modules/consent-mode/class-consent-mode.php:95`

## Phase 2 - US3 (P2): vendor filters

- [ ] T006 Add `exclude_paths()`, `exclude_files()`, `exclude_handles()`, `exclude_csv()` and register the 13 verified filters from research.md R3 (WP Rocket x3, LiteSpeed x3, Autoptimize x1, Perfmatters x3, SiteGround x3). No FlyingPress code (FR-005). - `includes/integrations/class-optimizer-compat.php`

## Phase 3 - Tests

- [ ] T007 PHPUnit `OptimizerExclusionsTest`: WordPress 6.5 tag shapes for the four handles (blocking and `defer data-wp-strategy="defer"`), each attribute exactly once after `add_attributes` + `mark_tag`, identical after a second `mark_tag`, no duplicate when a theme already added `data-cfasync="false"`, foreign handle untouched, with and without `GFForms` stubbed (SC-001, SC-003). Stub `add_filter` as in `tests/unit/CF7SubmissionOutcomeTest.php:15`. - `tests/unit/OptimizerExclusionsTest.php`
- [ ] T008 Same test file: each vendor callback with an empty list and with a list that already contains the entries (SC-002). - `tests/unit/OptimizerExclusionsTest.php`

## Phase 4 - Docs

- [ ] T009 Rewrite the caching line to list the five verified attributes, the vendor filters, NitroPack, and the check: view source, confirm attributes on every `clicutcl-*.js` tag, confirm `window.ClickTrail` is defined before submit; add that Delay JS runs on first interaction (including `keydown`, so a keyboard-only submit still runs it, too late for the landing touch) and that FlyingPress needs the keywords `clicutcl` and `clicktrail-consent-mode` in its exclusion field (FR-007, US4). - `README.en.md:84`, `readme.txt:81`, `readme.txt:160` (FAQ)
- [ ] T010 Add the same guidance to the playbook caching paragraph and a short optimizer subsection under Cache and visitor boundary. - `docs/guides/IMPLEMENTATION-PLAYBOOK.md:45`, `docs/reference/INTEGRATIONS.md:232`
- [ ] T011 List `includes/integrations/class-optimizer-compat.php` next to the other integration files; changelog and change record entries naming the removed class and the new filters. - `docs/architecture/CODE-MAP.md:92-94`, `changelog.txt`, `docs/MASTER-SPECIFICATION-CHANGE-RECORD.md`

## Phase 5 - Proof

- [ ] T012 Run `tools/qa/e2e/ci.sh` with `CT_E2E_OUT` set, dump the landing page HTML from the pod, and record in research.md that all four tags carry the attributes and the consent snippet carries `nowprocket` (SC-004). Optional: a second pod with WP Rocket Delay JS on; confirm no ClickTrail tag has `type="text/rocketlazyloadscript"`. - `tools/qa/e2e/`, `specs/006-optimizer-exclusions/research.md`
- [ ] T013 Only after T012: remove the runtime-unverified wording from the docs touched in T009 to T011. - docs

## Dependencies

T001 → T002. T002 → T003, T004, T005, T006. T002, T006 → T007, T008. T009 to T011 after T001 (wording) and T006 (filter list). T012 after T003 to T006. T013 after T012.
