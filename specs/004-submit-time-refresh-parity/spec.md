# Feature Specification: Submit-Time Refresh Parity for Form Adapters

**Feature Branch**: `fix/submit-time-refresh-parity`

**Created**: 2026-10-31

**Status**: Draft, not started. Every claim below is source-present / runtime-unverified: the adapter code was read on `main` @ `ef6a20b` (1.11.0 + #137), no form plugin was exercised.

**Input**: GitHub issues [#142](https://github.com/vizuh/click-trail-handler/issues/142) (CF7, WPForms, Fluent Forms and Elementor Pro still store render-time `ct_*` values) and [#151](https://github.com/vizuh/click-trail-handler/issues/151) (the WPForms render fill hooks `wpforms_field_value_ct_*`, a filter WPForms does not fire).

#137 gave Gravity Forms a `gform_pre_submission` refresh (`class-gravity-forms-adapter.php:165-211`): for every `ct_<key>` input, a non-empty cookie value for `<key>` replaces the posted value, keys the cookie lacks keep the posted value, consent and the per-form toggle gate it. The other adapters render `ct_*` values from the cookie the page request carried, so a visitor who submits on the landing page (render runs before the browser writes the cookie) or a returning visitor with a new click ID posts stale values. This feature applies the #137 rule to each of them. #152 will later tighten the "keys absent from the cookie keep the posted value" clause for the cache-leak case; this feature implements the #137 rule as-is.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Contact Form 7 mail tags and add-ons see submit-time values (Priority: P1)

A visitor lands from a Meta ad, submits a CF7 form on that page, and the notification email `[ct_fbclid]`, Flamingo and CRM add-ons carry the Meta click, not the empty or Google value that was rendered. ClickTrail filters `wpcf7_posted_data` so the posted `ct_*` keys are refreshed from the submit-time cookie before CF7 builds mail and before `wpcf7_mail_sent`.

**Why this priority**: today `on_submission()` (`class-cf7-adapter.php:93-133`) merges the cookie into ClickTrail's own log only (`array_merge( $attribution, $this->get_attribution_payload() )`, line 129); everything CF7 itself does with the posted data still sees render-time values.

**Independent Test**: PHPUnit `tests/unit/CF7PostedDataRefreshTest.php` modeled on `GravityFormsSubmissionRefreshTest.php` (set `$_COOKIE['attribution']`, call the filter method with a posted-data array, assert the return).

**Acceptance Scenarios**:

1. **Given** posted `ct_gclid = old` and cookie `gclid = new`, **When** `wpcf7_posted_data` runs, **Then** the returned array has `ct_gclid = new`.
2. **Given** posted `ct_utm_term = x` and no `utm_term` in the cookie, **Then** `ct_utm_term` stays `x`.
3. **Given** posted `ct_lt_campaign = ''` and cookie `lt_campaign = "o'brien"`, **Then** the returned value is `o'brien` (sanitized, not slashed; CF7 posted data is already unslashed).
4. **Given** marketing consent is required and absent or denied, **Then** the array is returned unchanged.
5. **Given** the refreshed posted data, **When** `wpcf7_mail_sent` fires, **Then** `on_submission()` logs the same values (no second source of truth).

---

### User Story 2 - Fluent Forms stores the submit-time touch per key (Priority: P1)

The Fluent Forms adapter stops preferring posted `ct_*` values with an all-empty cookie fallback (`class-fluent-forms-adapter.php:132-142`) and instead rewrites each `ct_<key>` in the response data at the pre-insert filter `fluentform/insert_response_data` (slash style, with the legacy `fluentform_insert_response_data` alias), so the native entry, `fluentform_submission_meta` and ClickTrail's log all hold the submit-time value.

**Independent Test**: PHPUnit `tests/unit/FluentFormsSubmissionRefreshTest.php` calling the new filter method with `$formData`, `$formId`, `$inputConfigs`.

**Acceptance Scenarios**:

1. **Given** posted `ct_gclid = old` and cookie `gclid = new`, **Then** the returned `$formData['ct_gclid']` is `new`.
2. **Given** posted `ct_utm_term = x` and no `utm_term` in the cookie, **Then** `x` is kept.
3. **Given** posted `ct_ft_source = ''` and `ct_lt_source = 'google'` and a cookie with both keys, **Then** both are rewritten (per key, not only when every posted value is empty).
4. **Given** consent required and absent, **Then** `$formData` is returned unchanged and `on_submission()` still returns early.
5. **Given** both the slash and the legacy hook fire for one submission, **Then** the rewrite is idempotent (same output on the second run).
6. **Given** a form with zero `ct_*` fields and a cookie present, **Then** no provider field is written and `log_submission()` receives the cookie payload (today's behaviour for such forms). This is the only case where the cookie is read without a matching field; the all-empty fallback at `class-fluent-forms-adapter.php:139-142` is removed. Same rule for Elementor (`class-elementor-forms-adapter.php:86-88`).

---

### User Story 3 - Elementor Pro fields render and submit with current attribution (Priority: P1)

The Elementor adapter gains a render fill: for a hidden field whose `custom_id` is `ct_<key>`, `elementor_pro/forms/render/item/hidden` sets the value from the cookie. At submit, `elementor_pro/forms/process` (which Elementor documents as running after validation and before actions; `new_record` runs after the actions, so it is too late to change what Submissions saves) rewrites each `ct_<key>` field value from the submit-time cookie via `Form_Record::update_field()`. `on_submission()` on `new_record` keeps logging but drops the all-empty fallback (`class-elementor-forms-adapter.php:86-88`) in favour of the per-key rule.

**Independent Test**: PHPUnit `tests/unit/ElementorFormsSubmissionRefreshTest.php` with a minimal `Form_Record` double exposing `get('fields')` and `update_field()`, plus a render test on the `$item` array.

**Acceptance Scenarios**:

1. **Given** a hidden item with `custom_id = ct_gclid` and cookie `gclid = abc`, **When** the render filter runs, **Then** the item value is `abc` and the render attribute `value` is replaced, not appended.
2. **Given** record field `ct_gclid = old` and cookie `gclid = new`, **When** the process hook runs, **Then** `update_field( 'ct_gclid', 'value', 'new' )` is called.
3. **Given** record field `ct_utm_term = x` and no `utm_term` in the cookie, **Then** no update is made for that field.
4. **Given** consent required and absent, **Then** neither filter writes anything.

---

### User Story 4 - WPForms hidden fields are filled at render and refreshed at submit (Priority: P1)

The dead `wpforms_field_value_{ct_*}` registrations (`class-wpforms-adapter.php:44-52`, `populate_field()` 66-90) are removed. A field is a ClickTrail field when its CSS class list contains `ct_<key>` (recommended, new) or its label is exactly `ct_<key>` (what the docs and the Diagnostics scan `trait-admin-diagnostics-ajax.php:996-1004` already accept). At render, `wpforms_field_properties` sets `$properties['inputs']['primary']['attr']['value']` from the cookie. At submit, `wpforms_process_filter` rewrites `$fields[ $id ]['value']` from the submit-time cookie. Entry meta keeps being written from the cookie at `wpforms_process_complete`.

**Independent Test**: PHPUnit `tests/unit/WPFormsFieldRefreshTest.php` calling both filter methods with WPForms-shaped arrays.

**Acceptance Scenarios**:

1. **Given** a hidden field with `css = "ct_gclid"` and cookie `gclid = abc`, **When** `wpforms_field_properties` runs, **Then** the primary input `value` attr is `abc`.
2. **Given** a hidden field with `label = "ct_ft_source"` and no CSS class, **Then** it is detected the same way (backward compatible).
3. **Given** `$fields[3]['value'] = old`, field 3 marked `ct_gclid`, cookie `gclid = new`, **When** `wpforms_process_filter` runs, **Then** `$fields[3]['value']` is `new`.
4. **Given** field 4 marked `ct_utm_term` and no `utm_term` in the cookie, **Then** its value is kept.
5. **Given** consent required and absent, **Then** both filters return their input unchanged.
6. **Given** the plugin boots, **Then** no `wpforms_field_value_*` filter is registered.

---

### User Story 5 - Ninja Forms needs no change (Priority: P3)

`ninja_forms_submit_data` already injects the cookie payload server-side at submit (`class-ninja-forms-adapter.php:59, 76-107`) and removes a stale extra when consent is withdrawn. Confirm and document; no code change.

**Independent Test**: none new; the existing adapter path is cited in research.md.

---

### Edge Cases

- Multi-input Gravity Forms style fields (`field->inputs`) do not exist in these providers; each `ct_<key>` maps to one scalar.
- Fluent Forms fires legacy and slash hooks for the same event (`class-fluent-forms-adapter.php:50-56`); the refresh is a pure function of (posted, cookie) so running twice yields the same data. `on_submission()` keeps its `static $logged` guard.
- CF7: `wpcf7_posted_data` runs inside `WPCF7_Submission` construction, before validation, mail and `wpcf7_mail_sent`; the refreshed data is what `get_posted_data()` returns later, so `on_submission()` needs no separate merge.
- WPForms Lite vs Pro: both filters live in Lite core (`includes/fields/class-base.php`, `includes/class-process.php`); entry meta still requires Pro. A Lite install gets correct notification values but no stored entry.
- Elementor: `new_record` runs after the form actions (vendor doc), so a refresh there would not reach Submissions, email or webhooks; that is why the rewrite is at `process`.
- A cookie key whose value is `''` counts as absent (same as GF line 204).
- Per-form toggle: only Gravity Forms has one (`Gf_Form_Settings_Tab`); the other adapters are gated by `should_populate()` and `Consent::is_required() && ! Consent::marketing_allowed()` only. No new toggle is introduced.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Each adapter (CF7, Fluent Forms, Elementor Pro, WPForms) MUST register a submit-time refresh on the hooks named in US1-US4 and apply one rule: for each `ct_<key>` field the form carries, a non-empty cookie value for `<key>` replaces the posted value; keys absent or empty in the cookie keep the posted value.
- **FR-002**: Every refresh and render fill MUST return early when `should_populate()` is false or when `Consent::is_required()` and not `Consent::marketing_allowed()`, leaving the provider data untouched.
- **FR-003**: The refresh MUST read keys only through `Attribution_Provider::get_field_mapping()` and values through `get_attribution_payload()`; it MUST NOT add fields the form does not carry.
- **FR-004**: Elementor Pro and WPForms MUST gain a render fill for `ct_<key>` hidden fields (US3-1, US4-1/2) so the field is non-empty on non-landing pages and for add-ons that read render-time values.
- **FR-005**: The WPForms adapter MUST remove the `wpforms_field_value_*` registrations and `populate_field()`; detection MUST accept CSS class `ct_<key>` and the existing label form.
- **FR-006**: Fluent Forms and Elementor `on_submission()` MUST drop the all-empty cookie fallback and log the per-key refreshed values; CF7 `on_submission()` MUST drop its `array_merge` once `wpcf7_posted_data` is refreshed.
- **FR-007**: Each adapter MUST have a PHPUnit test modeled on `tests/unit/GravityFormsSubmissionRefreshTest.php` covering cookie-wins, posted-kept and consent-denied cases.
- **FR-008**: `INTEGRATIONS.md` per-provider bullets, `integration-capabilities.json` form rows, `MASTER-SPECIFICATION.md` CT-014/CT-015/CT-016, `changelog.txt`, `readme.txt` and `README.en.md` MUST describe the behaviour and the WPForms field-detection contract. Rows stay source-present / runtime-unverified until SC-005.

### Key Entities

- **Attribution cookie**: `attribution` (legacy `ct_attribution`), JSON of `ft_*`, `lt_*`, click IDs; read server-side by `Attribution_Provider::get_payload()`.
- **ClickTrail field**: a provider field addressed as `ct_<key>` (CF7/Fluent name, Elementor `custom_id`, WPForms CSS class or label, GF parameter name).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In PHPUnit, each adapter yields `new` for posted `old` + cookie `new`, keeps `x` for a key missing from the cookie, and leaves data unchanged under denied consent (12 assertions minimum, 4 adapters).
- **SC-002**: `grep -r wpforms_field_value_ includes/` returns nothing.
- **SC-003**: `npm run smoke` and the full PHPUnit suite pass with the new tests included.
- **SC-004**: Docs in FR-008 mention `wpcf7_posted_data`, `fluentform/insert_response_data`, `elementor_pro/forms/process`, `wpforms_field_properties` and `wpforms_process_filter` by name.
- **SC-005**: A live landing-page submission on each of the four plugins (tallk.me staging, see research.md checklist) stores the submit-time click ID in the provider's own record. Until that passes, every row keeps its runtime-unverified label.

## Assumptions

- Vendor hook signatures as documented on 2026-10-31: `wpcf7_posted_data( $posted_data )`; `fluentform/insert_response_data( $formData, $formId, $inputConfigs )`; `elementor_pro/forms/render/item/hidden( $item, $item_index, $form )`, `elementor_pro/forms/process( $record, $ajax_handler )`; `wpforms_field_properties( $properties, $field, $form_data )`, `wpforms_process_filter( $fields, $entry, $form_data )`.
- `Form_Record::update_field( $field_id, $property, $value )` exists in Elementor Pro (not verified against the Pro source; T001).
- The `wpforms_field_value_*` filter is absent from the full WPForms Lite zip, not only the four files grepped (T001 confirms before deletion).
- #140 (dedupe), #143 (entry ids), #152 (cache-safe fields) and #141 (ITP) are out of scope.
