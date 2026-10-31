# Implementation Plan: Submit-Time Refresh Parity for Form Adapters

**Branch**: `fix/submit-time-refresh-parity` | **Date**: 2026-10-31 | **Spec**: [spec.md](spec.md) | **Issues**: [#142](https://github.com/vizuh/click-trail-handler/issues/142), [#151](https://github.com/vizuh/click-trail-handler/issues/151)

## Summary

Give CF7, Fluent Forms, Elementor Pro and WPForms the same submit-time rule Gravity Forms got in #137: at the provider's pre-save seam, rewrite each posted `ct_<key>` from the cookie sent with the submit when that key is non-empty, keep the posted value otherwise, gated by consent. Add render fills where the provider has none (Elementor, WPForms), delete the WPForms filter that never fires, and drop the all-empty cookie fallbacks that let stale values win.

## Technical Context

**Language/Version**: PHP 8.1+ (adapters only); no browser change
**Primary Dependencies**: none new; vendor hooks listed in the contract block
**Storage**: none new; the same provider records and `wp_clicutcl_events` rows as today
**Testing**: PHPUnit `tests/unit/{CF7PostedDataRefresh,FluentFormsSubmissionRefresh,ElementorFormsSubmissionRefresh,WPFormsFieldRefresh}Test.php` (bootstrap stubs, `tests/helpers/consent-mode-settings-stub.php`); `npm run smoke`; `tools/qa/e2e/` CF7 round trip must still pass
**Constraints**: no new settings; no writes when consent blocks; never add fields a form does not carry; keep `static $logged` in Fluent `on_submission()`

## Repo gates

- AGENTS.md docs triggers (integration behaviour): `INTEGRATIONS.md` per-provider bullets (lines 213-224 today), `integration-capabilities.json` rows `source.form.{cf7,elementor,fluent,wpforms}` (`what_exists`, `evidence`, `test_ids`), `MASTER-SPECIFICATION.md` CT-014 (drop "optional overwrite"), CT-015 (WPForms detection contract), CT-016 (Elementor render fill), `IMPLEMENTATION-PLAYBOOK.md:101-104`, `USE-CASES.md:24-26`, `tutorials/01-lead-form-attribution.md:16`, `README.en.md:28,39,96,104`, `readme.txt:29,40,91,106,166`, `changelog.txt`. No new hook of ClickTrail's own, so `HOOKS-REFERENCE.md` is unchanged. `CODE-MAP.md` test list gains four files.
- Evidence boundary: form rows stay source-present / runtime-unverified until SC-005.

## Design

### Shared helper (`includes/integrations/forms/class-abstract-form-adapter.php`)

| Change | Where |
|---|---|
| `protected function refresh_allowed(): bool` returning `should_populate() && ! ( Consent::is_required() && ! Consent::marketing_allowed() )` (the gate GF repeats at line 169 and `log_submission()` at line 64) | next to `should_populate()` |
| `protected function cookie_value_for( $field_name )`: strips `field_prefix`, returns the non-empty payload value or `null` (encodes "present and non-empty wins") | next to `get_field_name()` |

### Contact Form 7 (`class-cf7-adapter.php`)

| Change | Where |
|---|---|
| `add_filter( 'wpcf7_posted_data', array( $this, 'refresh_posted_data' ) )` | `register_hooks()` (line 42) |
| `refresh_posted_data( $posted )`: for each mapping key, if `isset( $posted[ ct_key ] )` and cookie has a value, set `sanitize_text_field( value )` | new method |
| Remove the `array_merge` at line 129; `on_submission()` reads the refreshed posted data only | `on_submission()` |

### Fluent Forms (`class-fluent-forms-adapter.php`)

| Change | Where |
|---|---|
| `add_filter( 'fluentform/insert_response_data', array( $this, 'refresh_response_data' ), 10, 3 )` plus legacy `fluentform_insert_response_data` | `register_hooks()` (line 49) |
| `refresh_response_data( $form_data, $form_id, $input_configs )`: per-key rewrite, idempotent | new method |
| Replace lines 139-142 (all-empty fallback) with the per-key read of `$form_data` only | `on_submission()` |

### Elementor Pro (`class-elementor-forms-adapter.php`)

| Change | Where |
|---|---|
| `add_filter( 'elementor_pro/forms/render/item/hidden', array( $this, 'render_hidden_field' ), 10, 3 )`: when `custom_id` starts with `ct_`, set `$item['field_value']`, `remove_render_attribute( 'input' . $index, 'value' )` then `add_render_attribute(...)` (see research R3) | `register_hooks()` (line 45) |
| `add_action( 'elementor_pro/forms/process', array( $this, 'refresh_record' ), 10, 2 )`: iterate `$record->get( 'fields' )`, `update_field( $id, 'value', ... )` for `ct_*` ids with a cookie value | `register_hooks()` |
| Replace lines 86-88 (all-empty fallback) with the per-key read of the record | `on_submission()` |

### WPForms (`class-wpforms-adapter.php`)

| Change | Where |
|---|---|
| Delete the `wpforms_field_value_*` loop (lines 44-52) and `populate_field()` (66-90) | `register_hooks()` |
| `private function field_key( array $field )`: returns `<key>` when `css` contains a `ct_<key>` token or `label === ct_<key>`; else `''` | new |
| `add_filter( 'wpforms_field_properties', array( $this, 'render_field_default' ), 10, 3 )`: hidden type only, sets `$properties['inputs']['primary']['attr']['value']` | `register_hooks()` |
| `add_filter( 'wpforms_process_filter', array( $this, 'refresh_fields' ), 10, 3 )`: rewrite `$fields[ $id ]['value']` by looking up `$form_data['fields'][ $id ]` for the marker | `register_hooks()` |
| `on_submission()` unchanged (entry meta from cookie, `wpforms_process_complete`) | |

### Diagnostics (`includes/admin/traits/trait-admin-diagnostics-ajax.php:996-1004`)

| Change | Where |
|---|---|
| Also accept `css` tokens starting with `ct_` so the scan and the adapter share one detection rule | WPForms branch of `check_form_attribution_fields()` |

### Contract: submit-time refresh (all adapters)

```text
input : posted[ct_<key>] (string, may be ''), cookie[<key>] (string | absent)
gate  : should_populate() && !(Consent::is_required() && !Consent::marketing_allowed())
rule  : cookie[<key>] present && cookie[<key>] !== ''  -> posted[ct_<key>] = sanitize_text_field(cookie[<key>])
        otherwise                                       -> posted[ct_<key>] unchanged
scope : only keys in Attribution_Provider::get_field_mapping() and only fields the form carries
seams : CF7 wpcf7_posted_data | Fluent fluentform/insert_response_data (+ legacy alias)
        Elementor elementor_pro/forms/process (render: render/item/hidden)
        WPForms wpforms_process_filter (render: wpforms_field_properties)
        GF gform_pre_submission (#137, unchanged) | Ninja ninja_forms_submit_data (unchanged)
```

## Risks

- `Form_Record::update_field()` or the `render/item/hidden` attribute handling may differ across Elementor Pro versions; T001 checks the Pro source before coding.
- CF7 `wpcf7_posted_data` priority: other plugins (Flamingo, CRM add-ons) also filter it; register at priority 5 so they read refreshed values.
- Fluent Forms: the vendor doc places `insert_response_data` in `SubmissionHandlerService::prepareInsertData()` but does not state its order relative to validation; the rewrite only touches `ct_*` keys, so either order is harmless. T001 reads the Fluent source to confirm.
- WPForms CSS class detection depends on users adding the class; the label form stays as a fallback so existing setups keep working. The Diagnostics scan must change in the same PR or it will warn on class-only forms.
- Deleting the all-empty fallbacks means a form with no `ct_*` fields logs nothing for Fluent/Elementor; today it logs the cookie. Decision in research R6: keep a cookie-only log when the form carries no `ct_*` field at all, so ClickTrail's own record is not lost.
