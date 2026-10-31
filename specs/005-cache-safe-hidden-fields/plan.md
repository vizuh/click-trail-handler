# Implementation Plan: Cache-Safe Hidden Fields

**Branch**: `fix/cache-safe-hidden-fields` | **Date**: 2026-10-31 | **Spec**: [spec.md](spec.md) | **Issue**: [#152](https://github.com/vizuh/click-trail-handler/issues/152) | **Depends on**: [004-submit-time-refresh-parity](../004-submit-time-refresh-parity/plan.md)

## Summary

Stop writing the requesting visitor's cookie into form HTML (empty `ct_*` fields by default, opt-in filter), make every submit-time refresh treat the submitter's cookie as the authoritative set, accept posted values without a cookie only when the Injector marked the form with `ct_fill_source=js`, and blank everything when consent blocks. Then change the docs so page caches no longer need form-page exclusions.

## Technical Context

**Language/Version**: PHP 8.1+ (adapters, provider); browser ES2019, no build step (`clicutcl-attribution.js`)
**Primary Dependencies**: spec 004 refresh hooks for CF7, Fluent, Elementor, WPForms; existing `Gravity_Forms_Adapter::refresh_submitted_fields()` (`class-gravity-forms-adapter.php:165-211`)
**Storage**: none new; `ct_fill_source` is a transient posted field, never persisted
**Testing**: PHPUnit `tests/unit/` (model: `GravityFormsSubmissionRefreshTest.php`, bootstrap stubs WP functions); Node VM `tools/qa/*.test.js` via `npm run test:attribution`; `tools/qa/e2e/` CF7 round trip (CI); `npm run smoke`
**Constraints**: field names unchanged (Injector exact match); no cookie domain change (#154 separate); no new settings UI, filter only

## Repo gates

- AGENTS.md docs triggers (form behaviour, hooks, privacy): `INTEGRATIONS.md` + `integration-capabilities.json` (CF7, GF, Fluent evidence rows note "render empty by default"), `SECURITY-PRIVACY.md`, `MASTER-SPECIFICATION.md` CT-014, `HOOKS-REFERENCE.md`, `IMPLEMENTATION-PLAYBOOK.md`, `README.en.md`, `readme.txt`, `changelog.txt`. No settings change, so `SETTINGS-AND-ADMIN.md` is unchanged.
- Evidence boundary: every adapter row stays source-present / runtime-unverified until SC-001 runs against a real page cache (WP Rocket or LiteSpeed on the throwaway E2E site).

## Design

### Render (server)

| Change | File |
|---|---|
| `Abstract_Form_Adapter::get_render_payload()`: returns `get_attribution_payload()` when `apply_filters( 'clicutcl_render_server_attribution_values', false, $this->get_platform_name() )` is `true`, otherwise `array_fill_keys( Attribution_Provider::get_field_mapping(), '' )` | `includes/integrations/forms/class-abstract-form-adapter.php` |
| `CF7_Adapter::add_hidden_fields()` uses `get_render_payload()`; keeps `should_populate()` guard only for the filled branch, always emits names | `class-cf7-adapter.php:55-67` |
| `Fluent_Forms_Adapter::add_hidden_fields()` same; drop the `empty( $payload )` early return so empty inputs are echoed | `class-fluent-forms-adapter.php:64-86` |
| `Gravity_Forms_Adapter::populate_fields_dynamic()` returns `$value` unless the filter is on | `class-gravity-forms-adapter.php:135-154` |

### Submit (server)

| Change | File |
|---|---|
| `Abstract_Form_Adapter::resolve_submitted_attribution( array $posted, bool $marker ): array`: implements FR-004/FR-005; returns the full key set with `''` for blanked keys; consent-blocked returns all `''` | `class-abstract-form-adapter.php` |
| `refresh_submitted_fields()`: call the resolver for every `ct_*` `allowsPrepopulate` input, write each result into `$_POST['input_*']` including `''`; move the consent early return after blanking | `class-gravity-forms-adapter.php:169-209` |
| CF7, Fluent, Elementor, WPForms refresh hooks from spec 004: feed posted `ct_*` plus the `ct_fill_source` flag into the resolver, write results back to the provider's posted data, strip `ct_fill_source` | files per spec 004 |
| `on_submission()` in CF7 (`:129` `array_merge`), Fluent (`:140-142`), Elementor (`:86-88`): log the resolved set, remove the posted-first merge | respective adapters |

### Browser (`assets/js/clicutcl-attribution.js`)

| Change | Where |
|---|---|
| `Injector.markForm(form)`: find or create `<input type="hidden" name="ct_fill_source" value="js">` in `form`; called from `run()` for each filled input's `closest('form')`, deduped per run | `Injector` (`:1165-1186`) |
| `Injector.clear()`: blank `ct_fill_source` inputs | `:1188-1202` |
| `findInputs()` unchanged; `ct_fill_source` is excluded from `map()` | `:1090-1093` |

### Contract: refresh rule

```text
input:  posted ct_* (from HTML, possibly another visitor's), cookie payload (may be absent), marker (ct_fill_source == 'js')
gate:   should_populate() false OR (Consent::is_required() AND NOT marketing_allowed())  -> every key ''
cookie present (json_decode ok, non-empty):
        for key in field_mapping: out[key] = cookie[key] ?? ''      (posted ignored)
cookie absent:
        marker -> out = posted (sanitized);  no marker -> every key ''
GF / Elementor / WPForms never carry a marker (inputs not named ct_*), so cookie absent -> ''
output: written to provider posted data before persistence; same set passed to log_submission()
```

## Risks

- Sites that depend on server fill without JavaScript (JS-disabled visitors, bot submissions) lose values by default; the filter restores the old behaviour at the cost of the cache rule. Called out in the changelog.
- Spec 004 hook choice per provider decides whether mail tags and add-ons see the corrected values; if a provider offers no pre-persist hook, this spec can only correct ClickTrail's own log for that provider.
- Blanking absent keys removes the previous "cookie blocked, keep posted" safety net on CF7; the marker is the replacement and depends on the Injector having run.
- A cookie present but one touch behind (landing-page submit, #137) is still the newest server-visible state; the browser cookie write precedes the submit, so this is the same as today.
