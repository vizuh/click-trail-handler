# Feature Specification: Cache-Safe Hidden Fields

**Feature Branch**: `fix/cache-safe-hidden-fields`

**Created**: 2026-10-31

**Status**: Implemented (PHPUnit and Node VM only; no live page cache run). Originally: Draft, not started. Every claim below is source-present / runtime-unverified: the leak is derived from reading `main` @ `ef6a20b`, not from a live cached site. Depends on [`specs/004-submit-time-refresh-parity`](../004-submit-time-refresh-parity/spec.md): that spec gives CF7, Fluent Forms, Elementor and WPForms a submit-time refresh hook; this spec changes the merge rule inside every refresh hook, including the Gravity Forms one that already exists.

**Input**: GitHub issue [#152](https://github.com/vizuh/click-trail-handler/issues/152). Server render writes the requesting visitor's attribution cookie into form HTML. A full-page cache that does not vary on the `attribution` cookie stores that HTML and serves visitor A's `ct_*` values to visitor B. Related: [#142](https://github.com/vizuh/click-trail-handler/issues/142) (refresh parity), [#154](https://github.com/vizuh/click-trail-handler/issues/154) (cookie scope).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Rendered HTML carries no visitor's attribution (Priority: P1)

Visitor A lands with `?gclid=A`, the browser writes the cookie, A opens the contact page and a page cache stores the response. Today CF7 (`wpcf7_form_hidden_fields`), Gravity Forms (`gform_field_value`) and Fluent Forms (echo on `fluentform/form_element_start`) render A's cookie values into that HTML. After this change the server emits the `ct_*` hidden fields with empty values, so the cached HTML is visitor-neutral, and the browser Injector fills B's own values from B's storage after load. A new filter `clicutcl_render_server_attribution_values` (default `false`) restores the old render for sites that have no page cache and want fields filled without JavaScript.

**Why this priority**: Cached HTML is served to every later visitor until it expires. One cached contact page can attach A's click ID to every lead that follows (see research.md R1 for which cache layers do and do not vary on cookies).

**Independent Test**: PHPUnit per adapter: set `$_COOKIE['attribution']` to A's payload, call the render hook, assert every `ct_*` field is present with value `''`; with the filter returning `true`, assert A's values.

**Acceptance Scenarios**:

1. **Given** a request carrying A's cookie and the filter at its default, **When** CF7 builds hidden fields, **Then** each key from `Attribution_Provider::get_field_mapping()` is present as `ct_<key>` with value `''`.
2. **Given** the same request, **When** Gravity Forms asks `gform_field_value` for a `ct_*` parameter, **Then** the adapter returns the value it was given (GF's own default), not the cookie.
3. **Given** the same request, **When** Fluent Forms renders, **Then** each echoed `<input type="hidden" name="ct_*">` has `value=""`.
4. **Given** `add_filter( 'clicutcl_render_server_attribution_values', '__return_true' )`, **Then** scenarios 1 to 3 emit A's cookie values exactly as on `ef6a20b`.
5. **Given** consent is required and absent, **Then** the render path emits the same empty fields (names present, values empty) so a later in-page grant can fill them.

---

### User Story 2 - Submit-time rule prefers the submitter's cookie (Priority: P1)

B submits a form whose HTML was cached from A. B's browser sends B's `attribution` cookie. The refresh hook treats the cookie as the authoritative set: every `ct_*` key the cookie holds is written, and every `ct_*` key the cookie lacks is blanked (today GF keeps the posted value for absent keys at `class-gravity-forms-adapter.php:204-205`, CF7 keeps posted values under `array_merge` at `class-cf7-adapter.php:129`). When the request carries no readable cookie, posted values are kept only when the form also posts the JS fill marker `ct_fill_source=js`, which the Injector writes into a form when it fills at least one field in it; without the marker all posted `ct_*` are blanked.

Gravity Forms inputs are named `input_N`, so the Injector never reaches them and never writes a marker into a GF form. GF therefore relies on the cookie alone: cookie present, cookie wins for every `ct_*` dynamic-population input; cookie absent, those inputs are blanked. Same for Elementor (`form_fields[<id>]`) and WPForms (`wpforms[fields][N]`). The marker only matters for CF7 and Fluent Forms, whose inputs carry exact `ct_*` names.

**Independent Test**: PHPUnit per adapter: post A's full `ct_*` set, set B's cookie lacking two keys, run the refresh hook, assert the two keys are `''` and the others equal B's cookie. Second case: no cookie, marker present, posted kept; no cookie, no marker, all `''`.

**Acceptance Scenarios**:

1. **Given** posted `ct_gclid=A1`, `ct_ft_source=A2`, `ct_lt_source=A3` and B's cookie `{ gclid: B1 }`, **Then** the stored set is `gclid=B1`, `ft_source=''`, `lt_source=''`.
2. **Given** no `attribution` cookie and posted `ct_fill_source=js`, **Then** posted `ct_*` values are stored unchanged.
3. **Given** no `attribution` cookie and no marker, **Then** every posted `ct_*` is `''` and ClickTrail's own log skips the submission (empty attribution).
4. **Given** a Gravity Forms submission with no cookie, **Then** every `allowsPrepopulate` input named `ct_*` is `''` in `$_POST` before GF saves the entry.
5. **Given** a cookie that fails `json_decode`, **Then** it counts as absent.

---

### User Story 3 - Consent blocks everything at submit (Priority: P1)

Consent is required and B has not granted. Today `refresh_submitted_fields()` returns at `class-gravity-forms-adapter.php:169-171`, `CF7_Adapter::on_submission()` at `:94-96` and `Fluent_Forms_Adapter::on_submission()` at `:113-115`, so A's cached values pass untouched into the provider's entry, mail and add-ons. After this change the refresh hook blanks all posted `ct_*` before the provider stores or mails them, then returns.

**Independent Test**: PHPUnit: consent required, no consent cookie, posted A values; assert `$_POST` (GF) / the refreshed posted data (CF7, Fluent) hold `''` for every `ct_*` key.

**Acceptance Scenarios**:

1. **Given** `Consent::is_required()` and no marketing consent, **When** the refresh hook runs, **Then** every posted `ct_*` is `''` and nothing is logged.
2. **Given** consent denied in-page after fill, **Then** `Injector.clear()` already blanks the fields client-side and the server blanks again (defence in depth).

---

### User Story 4 - Docs stop asking for cache exclusions (Priority: P2)

Operators currently read "cached or dynamic form rendering" as a reason to exclude form pages from the page cache. After US1 the rendered HTML is visitor-neutral, so the guidance becomes "no exclusion needed; the browser fills, the submit refresh corrects".

**Independent Test**: grep the docs listed in FR-010 for the old wording; each hit is rewritten.

---

### Edge Cases

- Visible prepopulated GF fields (a text field with `allowsPrepopulate` and a `ct_*` parameter name): with the default filter the visitor sees an empty field until submit; the refresh fills it from the cookie. Sites that want it visibly filled turn the filter on and accept the cache rule.
- Multi-page GF forms: `gform_pre_submission` runs once at the final submit; the rule applies to every `ct_*` input across pages.
- AJAX re-render (CF7 `wpcf7init`, Fluent, Elementor popups): the re-rendered HTML is also visitor-neutral; the Injector's `wpcf7init` listener and MutationObserver (`clicutcl-attribution.js:1217-1220`) fill again and rewrite the marker.
- `require_consent` off: `should_populate()` returns `true` (`class-attribution-provider.php:195-197`); the cookie rule and marker rule apply unchanged.
- `injectEnabled` off (`enable_js_injection`, `class-clicutcl-core.php:411`): no Injector, no marker. Server-rendered values are empty unless the filter is on; a cookie-less submit stores nothing. Documented as a configuration consequence, not a bug.
- `injectOverwrite` off: `setIfEmpty()` treats `ct_*` as ClickTrail's own (`clicutcl-attribution.js:1152`), so empty server fields are still filled.
- Legacy `ct_attribution` cookie name: read through the same `get_payload()` key loop; counts as present.
- The marker is a posted field like any other; a forged `ct_fill_source=js` without a cookie keeps forged posted values, which the submitter already controlled before this change. No new trust is granted.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: CF7, Gravity Forms and Fluent Forms render paths MUST emit `ct_*` fields with empty values unless `apply_filters( 'clicutcl_render_server_attribution_values', false, $platform )` returns `true`.
- **FR-002**: The field names emitted at render MUST stay exactly `ct_<key>` for every key in `get_field_mapping()` so `Injector.findInputs()` (`clicutcl-attribution.js:1090-1093`, exact `name` match) keeps finding them.
- **FR-003**: `Injector.run()` MUST write a hidden input `ct_fill_source` with value `js` into the closest `<form>` of every input it fills, creating it once per form and reusing it on later runs. `Injector.clear()` MUST remove or blank it.
- **FR-004**: Each adapter's submit-time refresh hook MUST apply the rule: readable cookie present, cookie is the authoritative set and keys absent from it are blanked; cookie absent, posted values are kept only when `ct_fill_source=js` is posted, otherwise blanked.
- **FR-005**: When `should_populate()` is `false` or `Consent::is_required() && ! Consent::marketing_allowed()`, the refresh hook MUST blank every posted `ct_*` before returning, for every adapter.
- **FR-006**: The blanking in FR-004 and FR-005 MUST happen before the provider persists or mails the submission (GF `gform_pre_submission`; CF7, Fluent, Elementor, WPForms at the hook chosen in spec 004).
- **FR-007**: `ct_fill_source` MUST NOT be stored as attribution, logged, or forwarded; adapters strip it after reading it.
- **FR-008**: ClickTrail's own `log_submission()` MUST receive the post-rule set, never the raw posted values.
- **FR-009**: Ninja Forms (cookie injected at `ninja_forms_submit_data`) and WPForms entry meta (cookie at `wpforms_process_complete`) already use the cookie only; they MUST stay cookie-only and gain the consent blanking of FR-005 where posted `ct_*` exist.
- **FR-010**: Docs MUST change: `SECURITY-PRIVACY.md` (new leak class "cached server-rendered attribution" with the fix), `MASTER-SPECIFICATION.md` CT-014 (render is empty by default, filter named), `IMPLEMENTATION-PLAYBOOK.md` Forms section and `README.en.md:39` (cache guidance: no exclusion needed), `HOOKS-REFERENCE.md` (new filter), `INTEGRATIONS.md` cache boundary section, `changelog.txt` Unreleased.

### Key Entities

- **`ct_fill_source`**: hidden input, value `js`, written by the Injector into a form it filled; read and stripped by the refresh hook; never stored.
- **`clicutcl_render_server_attribution_values`**: filter `(bool $render, string $platform)`, default `false`.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In the regression (PHPUnit per adapter with A's posted values and B's partial cookie), 0 values from A reach `log_submission()` or the provider's posted data. Source-present / runtime-unverified until run on a real cached site.
- **SC-002**: 0 `ct_*` values stored by any adapter when consent is required and absent at submit.
- **SC-003**: VM test: after `Injector.run()` on a page with a CF7-style form, exactly one `ct_fill_source=js` input exists per filled form; after `Injector.clear()` it is gone or empty.
- **SC-004**: Rendered HTML for CF7, GF and Fluent contains no cookie value when the filter is at its default.

## Assumptions

- Spec 004 lands first and defines one refresh hook per adapter; this spec edits the rule inside those hooks rather than adding parallel hooks.
- Visitors with JavaScript disabled or blocked lose server-side fill by default; the filter is the documented opt-in.
- Cache layers are treated as not varying on the `attribution` cookie unless the operator configures it (research.md R1).
