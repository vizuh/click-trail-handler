# Tasks: Submit-Time Refresh Parity for Form Adapters

**Input**: [spec.md](spec.md), [plan.md](plan.md), [research.md](research.md)

## Phase 0 - Verify vendor facts (blocks T004, T005)

- [ ] T001 Download the current WPForms Lite zip, Elementor Pro (licensed copy) and Fluent Forms free; grep the whole WPForms zip for `wpforms_field_value_` and confirm `wpforms_field_properties` reaches the hidden type in `includes/fields/class-base.php`; read `Form_Record::update_field()` and `get( 'fields' )` shape in Elementor Pro; read `SubmissionHandlerService::prepareInsertData()` in Fluent Forms. Record findings in research.md R3, R4, R5 and close the open questions. -- open

## Phase 1 - Shared helper

- [ ] T002 Add `refresh_allowed()` and `cookie_value_for( $field_name )` to `Abstract_Form_Adapter`; switch the existing GF gate (`refresh_submitted_fields()` line 169, 204) to them without changing behaviour; `GravityFormsSubmissionRefreshTest` still passes. -- `includes/integrations/forms/class-abstract-form-adapter.php`, `class-gravity-forms-adapter.php`

## Phase 2 - US1 (P1): Contact Form 7

- [ ] T003 Register `wpcf7_posted_data` (priority 5) → `refresh_posted_data()`; remove the `array_merge` in `on_submission()`; add `tests/unit/CF7PostedDataRefreshTest.php` (FR-001, FR-002, FR-006, FR-007). -- `includes/integrations/forms/class-cf7-adapter.php`

## Phase 3 - US2 (P1): Fluent Forms

- [ ] T004 Register `fluentform/insert_response_data` and legacy `fluentform_insert_response_data` → `refresh_response_data()`; replace the all-empty fallback in `on_submission()` per R6; add `tests/unit/FluentFormsSubmissionRefreshTest.php` including the double-hook idempotency case. -- `includes/integrations/forms/class-fluent-forms-adapter.php`

## Phase 4 - US3 (P1): Elementor Pro

- [ ] T005 Register `elementor_pro/forms/render/item/hidden` → `render_hidden_field()` (attribute replace pattern, R3) and `elementor_pro/forms/process` → `refresh_record()`; replace the all-empty fallback in `on_submission()` per R6; add `tests/unit/ElementorFormsSubmissionRefreshTest.php` with a `Form_Record` double. -- `includes/integrations/forms/class-elementor-forms-adapter.php`

## Phase 5 - US4 (P1): WPForms

- [ ] T006 Delete the `wpforms_field_value_*` loop and `populate_field()`; add `field_key()` (CSS class `ct_<key>` or label `ct_<key>`), `render_field_default()` on `wpforms_field_properties`, `refresh_fields()` on `wpforms_process_filter`; add `tests/unit/WPFormsFieldRefreshTest.php` covering both detection forms (FR-005). -- `includes/integrations/forms/class-wpforms-adapter.php`
- [ ] T007 Extend the Diagnostics WPForms scan to accept `css` tokens starting with `ct_` so it matches `field_key()`. -- `includes/admin/traits/trait-admin-diagnostics-ajax.php`

## Phase 6 - US5 (P3): Ninja Forms

- [ ] T008 No code change. Add the "already submit-time via `ninja_forms_submit_data`" sentence to the Ninja bullet in `INTEGRATIONS.md`. -- docs only

## Phase 7 - Proof and docs

- [ ] T009 Run the full PHPUnit suite, `npm run smoke`, and the `tools/qa/e2e` CF7 round trip (SC-001 to SC-003). -- open
- [ ] T010 Docs per FR-008: `INTEGRATIONS.md` per-provider bullets (CF7 220, GF 221, Elementor 222, Ninja 223, new Fluent and WPForms bullets, pattern cheatsheet 174-176), `integration-capabilities.json` rows `source.form.{cf7,elementor,fluent,wpforms}` (`what_exists`, `evidence`, `test_ids`), `MASTER-SPECIFICATION.md` CT-014 (remove "optional overwrite"), CT-015 (WPForms detection contract), CT-016 (Elementor render fill), `IMPLEMENTATION-PLAYBOOK.md:101-104`, `USE-CASES.md:24-26`, `tutorials/01-lead-form-attribution.md:16`, `README.en.md:28,39,96,104`, `readme.txt:29,40,91,106,166`, `CODE-MAP.md` test list, `changelog.txt` and `readme.txt` changelog entry. Keep every form row source-present / runtime-unverified. -- open
- [ ] T011 Live acceptance (SC-005) on tallk.me per the research.md staging checklist items 1, 2 and 5 for the four plugins. Only after it passes, update the evidence labels in `INTEGRATIONS.md` and the ledger. -- open

## Dependencies

T001 → T005, T006 (and confirms T004's seam). T002 → T003, T004, T005, T006. T006 → T007. T003 to T007 → T009 → T010. T011 after T009; T010 does not wait for T011.
