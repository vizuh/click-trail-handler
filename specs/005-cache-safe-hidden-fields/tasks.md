# Tasks: Cache-Safe Hidden Fields

**Input**: [spec.md](spec.md), [plan.md](plan.md), [research.md](research.md). Prerequisite: [004-submit-time-refresh-parity](../004-submit-time-refresh-parity/tasks.md) merged (refresh hooks for CF7, Fluent, Elementor, WPForms).

## Phase 0 — Shared rule (blocks everything else)

- [ ] T001 Add `Abstract_Form_Adapter::get_render_payload()` (filter `clicutcl_render_server_attribution_values`, default `false`, second arg platform name) and `resolve_submitted_attribution( array $posted, bool $marker ): array` implementing the plan.md contract (consent gate → all `''`; cookie present → cookie set, absent keys `''`; cookie absent → posted if marker else `''`). — `includes/integrations/forms/class-abstract-form-adapter.php`
- [ ] T002 PHPUnit for the resolver alone: A's posted set, B's cookie lacking two keys; no cookie + marker; no cookie + no marker; consent required and absent; malformed cookie JSON. — `tests/unit/SubmittedAttributionResolverTest.php` (new, bootstrap stubs as in `GravityFormsSubmissionRefreshTest.php`)

## Phase 1 — US1 (P1): render emits empty values

- [ ] T003 `CF7_Adapter::add_hidden_fields()` uses `get_render_payload()`; names always emitted. — `includes/integrations/forms/class-cf7-adapter.php`
- [ ] T004 `Fluent_Forms_Adapter::add_hidden_fields()` uses `get_render_payload()`; remove the empty-payload early return so empty inputs are echoed. — `includes/integrations/forms/class-fluent-forms-adapter.php`
- [ ] T005 `Gravity_Forms_Adapter::populate_fields_dynamic()` returns `$value` unless the filter is on. — `includes/integrations/forms/class-gravity-forms-adapter.php`
- [ ] T006 PHPUnit render tests per adapter: default → empty values with full name set; filter `__return_true` → cookie values; consent absent → empty names present (SC-004). — `tests/unit/FluentFormsAdapterTest.php` (extend), `tests/unit/CF7RenderTest.php` (new), `tests/unit/GravityFormsRenderTest.php` (new)

## Phase 2 — US2 + US3 (P1): submit rule and consent blanking

- [ ] T007 `refresh_submitted_fields()`: build the posted `ct_*` map from `$_POST['input_*']`, call the resolver with `marker = false`, write every result (including `''`) back to `$_POST`; blanking runs before the consent/per-form-toggle returns. — `includes/integrations/forms/class-gravity-forms-adapter.php`
- [ ] T008 CF7 refresh hook (from spec 004): read `ct_fill_source`, call the resolver, rewrite posted `ct_*`, unset `ct_fill_source`; `on_submission()` logs the resolved set and drops the `array_merge`. — `includes/integrations/forms/class-cf7-adapter.php`
- [ ] T009 Fluent refresh hook (from spec 004): same as T008; `on_submission()` drops the `empty( $attribution )` cookie fallback. — `includes/integrations/forms/class-fluent-forms-adapter.php`
- [ ] T010 Elementor and WPForms refresh hooks (from spec 004): resolver with `marker = false`; Elementor `on_submission()` drops the posted-first fallback; WPForms meta write unchanged (cookie-only) plus FR-005 blanking of any posted `ct_*`. — `includes/integrations/forms/class-elementor-forms-adapter.php`, `includes/integrations/forms/class-wpforms-adapter.php`
- [ ] T011 Ninja: confirm no posted `ct_*` path; add FR-005 blanking if `ninja_forms_submit_data` carries `ct_*` fields. — `includes/integrations/forms/class-ninja-forms-adapter.php`
- [ ] T012 PHPUnit per adapter submit tests (SC-001, SC-002): A's posted values + B's cookie lacking two keys; consent-blocked; GF no-cookie blanks `allowsPrepopulate` inputs. — extend `tests/unit/GravityFormsSubmissionRefreshTest.php`, `tests/unit/CF7SubmissionOutcomeTest.php`, `tests/unit/FluentFormsAdapterTest.php`; new `tests/unit/ElementorFormsSubmitTest.php`, `tests/unit/WPFormsSubmitTest.php`

## Phase 3 — Browser marker

- [ ] T013 `Injector.markForm()` called from `run()` for each filled input's `closest('form')` (once per form per run); `clear()` blanks `ct_fill_source`; `map()` never includes it. — `assets/js/clicutcl-attribution.js`
- [ ] T014 Node VM test: real `clicutcl-attribution.js`, stubbed consent bridge, CF7-style form with empty `ct_*` inputs and stored attribution; assert one `ct_fill_source=js` per filled form, none in an unfilled form, blanked after `clear()` (SC-003). Wire into `npm run test:attribution`. — `tools/qa/fill-marker.test.js` (new), `package.json`

## Phase 4 — Docs and proof

- [ ] T015 Docs per FR-010: `docs/guides/SECURITY-PRIVACY.md` (leak class + fix), `docs/MASTER-SPECIFICATION.md` CT-014, `docs/guides/IMPLEMENTATION-PLAYBOOK.md` Forms section + Common Mistakes, `README.en.md:39`, `docs/reference/HOOKS-REFERENCE.md` (`clicutcl_render_server_attribution_values`), `docs/reference/INTEGRATIONS.md` cache boundary + `integration-capabilities.json` rows, `readme.txt`, `changelog.txt` Unreleased.
- [ ] T016 E2E: extend `tools/qa/e2e/` CF7 round trip with a second visitor loading the cached HTML of the first (serve the first response body verbatim) and submitting; assert no first-visitor value in the stored event. Until it runs, SC-001 stays runtime-unverified. — `tools/qa/e2e/`
- [ ] T017 Live acceptance on a disposable site behind WP Rocket or LiteSpeed with default settings; record results in research.md R6 and update the evidence rows.

## Dependencies

Spec 004 → T001. T001 → T002. T001 → T003–T005 → T006. T001 → T007–T011 → T012. T013 → T014 (independent of PHP phases). T015 after T003–T013. T016 after T008 and T013. T017 after T016.
