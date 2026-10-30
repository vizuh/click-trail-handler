# Research: Adapter Conformance and Shared Attribution Fixtures

Phase 0 for [plan.md](plan.md). Each item: decision, rationale, alternatives. Items marked **NEEDS DECISION** have a recommended default the plan proceeds on; the maintainer may overrule before `/speckit-tasks`.

## R1. Form of the JS rule module (D3)

**Decision**: a frozen `window.ClickTrailRules` object defined in its own IIFE at the top of `assets/js/clicutcl-attribution.js`, used by the runtime IIFE below it. Contract: [contracts/js-rule-module.md](contracts/js-rule-module.md).

**Rationale**
- The release zip is an `rsync` copy filtered by `.distignore` (`tools/release/make-zip.js`). There is no transform step, so nothing can be stripped at release. D3's fallback applies.
- The rules are already nearly pure. Their only implicit inputs are `window.location.href` (URL base in `parseUrlSafely`, `isIgnoredReferrer`) and `CONFIG.ignoredReferrerHosts` (read once at load). Both become arguments.
- The module holds functions and frozen constants, no visitor data, so a production global exposes nothing not already in the page source.
- Same file means no new script handle. A new handle would have to be added to the `wp_register_script` dependency chain in `class-clicutcl-core.php`, `Gf_Minification_Protector::CT_HANDLES` and the NitroPack compat list, and would be exposed to defer/delay reordering by WP Rocket and similar plugins. WP Rocket is in use on the site that reported #137.

**Alternatives rejected**
- *Separate `clicutcl-attribution-rules.js` file*: the handle, ordering and caching-plugin risks above, plus an extra request.
- *Conditional test hook* (`if (window.__clicutclTest) { expose internals }`): ships test code in the zip, which D3 forbids.
- *Harness rewrites the source before `vm` runs it* (injecting an export before the closing `})();`): tests modified code, not the production file, which D3 forbids, and breaks on any formatting change.
- *Public `ClickTrail` API and cookies only*: rejected in D3 as coupling pure-rule tests to consent, bot detection, storage and init.

## R2. Process isolation in the conformance suite (SC-006)

**Decision**: no `@runTestsInSeparateProcesses` on `FormAdapterConformanceTest`. Each case gets a fresh adapter instance, and driver `reset()` in `setUp()` restores `$_COOKIE`, `$_POST` and stub storage.

**Rationale**: measured locally (4 cores, PHP 8.3.6): `PaymentReturnAttributionTest`, which is isolated, takes 0.43 s for 6 tests. `ClickIdPrecedenceTest`, not isolated, takes 0.06 s for 5. That is about 0.06 s per isolated test, so 6 adapters × 11 scenarios ≈ 4 s, inside the 5 s budget but with no margin, and the CI runner was not measured. Isolation in the existing tests protects `require_once`'d stubs and `$_COOKIE`. Stubs here are guarded by `class_exists` / `function_exists` and record into globals the driver resets. Adapter classes hold no static state except Fluent Forms' `$logged` guard, which the Fluent driver resets.

**Alternatives**: isolate everything (≈4 s, no margin); isolate per driver class only (fallback if a leak shows up).

## R3. Host plugin hook shapes and posted-value channels

Read from WordPress.org source of the free editions (grep only, nothing executed): **Contact Form 7 6.2.4.1, Fluent Forms 6.2.16, Ninja Forms 3.15.7, WPForms Lite 2.0.3.1**. Gravity Forms and Elementor Pro are licensed and were not read; their drivers rely on the adapters' current assumptions.

| Adapter | Submit hook as the host calls it | Where a `ct_*` value is at that hook | Matches ClickTrail's adapter? |
|---|---|---|---|
| CF7 | `do_action( 'wpcf7_mail_sent', $contact_form )`, one argument, only after mail succeeds (`includes/submission.php:129`) | `WPCF7_Submission::get_instance()->get_posted_data()['ct_lt_source']`, flat; inputs added via `wpcf7_form_hidden_fields` are kept | Yes. The docblock's second and third arguments do not exist; the `get_instance()` path is the only one used. |
| Fluent Forms | `fluentform_submission_inserted` then `fluentform/submission_inserted`, both with `($insertId, $formData, $form)` (`app/Services/Form/SubmissionHandlerService.php:268,270`) | `$formData['ct_lt_source']`, flat, **but only for keys that are defined form inputs or whitelisted**. `prepareHandler` applies `array_intersect_key($formData, $acceptedFieldKeys)` (`:109-111`). The raw `<input>`s ClickTrail prints on `fluentform/form_element_start` are dropped. Whitelist is extensible via `apply_filters('fluentform/white_listed_fields', $fields, $formId)` (`app/Helpers/Helper.php:1639-1657`). | **No.** Today Fluent always runs on the cookie. D1's posted fallback needs ClickTrail to add its `ct_*` names through `fluentform/white_listed_fields`. |
| Ninja Forms | `apply_filters( 'ninja_forms_submit_data', $form_data )`, one argument (`includes/AJAX/Controllers/Submission.php:177`), then the actions loop, then `do_action( 'ninja_forms_after_submission', $data )` (`:610`) | At submit_data: `$form_data['fields'][<id>]['value']`, with `key` set server-side (`:172`) except in preview. At after_submission: `$data['fields_by_key']['ct_lt_source']['value']`. `extra['clicktrail_attribution']` survives (`scrubReservedExtraKeys` removes only `calculations`, `product_fields`). | Yes. The second declared argument is never passed (harmless). Resumed submissions skip the filter and restore `extra` from the session. |
| WPForms Lite | `do_action( 'wpforms_process_complete', $fields, $entry, $form_data, $entry_id )` (`includes/class-process.php:1041`) | `$fields[<id>] = ['name' => <label>, 'value', 'id', 'type']`. The input is `wpforms[fields][<id>]`. **No field key or name is exposed**, only the label. `$entry_id` is 0 in Lite; entry meta is Pro-only. | **No.** `wpforms_field_value_{key}` does not exist in Lite (zero hits across the plugin). `populate_field()` never runs, so ClickTrail does not populate WPForms hidden fields today, contrary to `INTEGRATIONS.md`. Entry meta writes are dead in Lite; only `log_submission()` persists. |

**Consequences for the plan**
1. **Fluent Forms**: phase 2 adds a `fluentform/white_listed_fields` filter for the `ct_*` names ClickTrail injects. The driver must model the whitelist intersection, or it would test a path that never happens live.
2. **WPForms**: D1 posted fallback needs a way to recognize a `ct_*` field. **NEEDS DECISION.** Default: a Hidden field whose **label** is exactly a `ct_*` name (`ct_lt_source`), because the label is the only text identifier WPForms passes. Alternatives: a CSS class on the field (`$form_data['fields'][id]['css']`, not verified), or no posted fallback for WPForms as a documented exception (contradicts the "all six" decision).
3. **WPForms docs**: phase 2 corrects `INTEGRATIONS.md`, which claims ClickTrail "populate[s] matching hidden fields already present in ... WPForms". Phase 2 also decides whether to delete the dead filter registration or keep it pending a WPForms Pro check (default: delete; Pro was not verified either way).
4. **Ninja Forms driver** fires `ninja_forms_submit_data` then `ninja_forms_after_submission` with the shapes above. A resumed-submission case (filter skipped) is added as scenario-level coverage for Ninja only.
5. **CF7 docblock** is corrected to the one-argument hook.

## R4. Divergences the first fixture run will expose

Found by reading the five implementations. Each must end as a code fix or an `exceptions[]` entry before phase 1 is green. "Default" is what the plan does unless overruled.

| # | Rule | JS | PHP | Default |
|---|---|---|---|---|
| 1 | Paid mediums | `PAID_MEDIUMS = ['cpc','ppc','paid','paidsearch','paid_social']` | `Gf_Channel_Resolver`: `['cpc','paid_social','paid']` | **Fix PHP** to the JS list. `utm_medium=ppc` with `fbclid` stops resolving to Facebook Organic server-side. |
| 2 | Paid medium, no click ID | `paidLabelFromSource()` → `Google Ads`, `Facebook Ads`, ... `Paid Search` | Branch missing; falls through to the referrer, then `Unknown` | **Fix PHP**: port `paidLabelFromSource()`. Changes the GF server-side channel fallback label. |
| 3 | Channel fallback for last touch | n/a (JS resolves per touch) | `resolve()` reads `utm_medium`/`ft_medium` and `utm_source`/`ft_source`; the GF adapter calls it for both `ft_channel` and `lt_channel` | **Fix PHP**: take a touch prefix so `lt_channel` resolves from `lt_*`. Today a missing `lt_channel` is filled from the first touch. |
| 4 | Same-site referrer | Excluded before classification (`getExternalReferrerDetails`) | Excluded inside `resolve()` via `$current_host` | **Representation difference**, documented in `notes`. Both shapes carry `page_url`. |
| 5 | Click IDs normalized | `withCanonicalClickIds` and `Attribution_Provider`: all 16 | `EventV2` and the token normalizer: 10 (no `rdt_cid`, `pin_cid`, `snap_cid`, `mc_cid`, `mc_eid`, `dclid`) | **NEEDS DECISION.** The JS token builder's allowlist includes all 16, so the PHP token normalizer looks like drift: default **fix** to 16. For `EventV2`, the 10-key list matches the D3 selection set, which may be intended for server events: default **exception** with reason "server events carry the D3 selection set only" **if the maintainer confirms**, otherwise fix. |
| 6 | Snapchat aliases | `sccid` ← `sc_click_id` | Token: also `ScCid`, `lt_ScCid`, `ft_ScCid`; EventV2: `sc_click_id` only | **Exception** for the token's `ScCid` aliases (reason: legacy token payloads). Align EventV2 only if #5 is fixed. |
| 7 | Value length cap | Query capture 128 (`mapQueryFields`), storage 256 (`sanitizeAttributionData`) | 255 (`Attribution_Provider::sanitize`) | **NEEDS DECISION.** Default: **fix JS storage to 255** so a 256-character stored value is not cut by one on the server. Keep the 128 capture cap as part of `touch-mapping.json`. |
| 8 | Whitespace in values | Control characters → space, then trim | Stub: strip control characters, trim. Real WordPress `sanitize_text_field`: also strips tags, collapses whitespace, **removes percent-encoded octets** | See R6. Fixture covers ClickTrail-owned behavior only. |

## R5. Live suite plugin selection

**Decision**: CF7 (already pinned in `tools/qa/e2e/setup.sh`), Fluent Forms, Ninja Forms and WPForms Lite, pinned to the versions read in R3. Gravity Forms and Elementor Pro are reported `not_run: licence required`.

**Rationale**: all four free editions have a Hidden field type (CF7 `hidden` tag; Fluent `input_hidden`; Ninja `hidden`; WPForms `hidden`). Each can be created with WP-CLI plus form JSON in `setup.sh`.

**Per-plugin verification read-back**
- CF7, Elementor: `clicutcl_events` rows (no entry storage).
- Fluent: `fluentform_submission_meta`.
- Ninja: submission `extra.clicktrail_attribution`.
- WPForms Lite: `clicutcl_events` rows, because Lite has no entries (`$entry_id` is 0).

**Alternatives**: running GF and Elementor Pro in CI with licence keys in secrets. Rejected for now: licence terms for CI use not checked, and keys in a public repo's CI are a leak risk.

## R6. `sanitize_text_field` in PHPUnit

**Decision**: the `value-sanitization.json` fixture asserts only ClickTrail-owned behavior (macro rejection, length cap, control characters, key canonicalization). WordPress's own transformations are out of fixture scope and noted in `notes`.

**Rationale**: `tests/bootstrap.php` stubs `sanitize_text_field` as "strip control chars, trim". Parity on top of WordPress's real function cannot be proven in PHPUnit. That function also strips `%xx` octets, which can alter URL-valued fields like `ft_landing_page`. That is a separate possible bug, worth its own issue; it is not fixed here.

**Alternative**: vendor WordPress's `formatting.php` into the test bootstrap. Rejected: heavy, and it pulls in many other functions.

## R7. Fixture validation without a schema library

**Decision**: both loaders hand-check the fields `rule-fixture.schema.json` requires (top-level keys, case shape exclusivity, exception completeness, ID patterns). The schema file is the documented contract and is not loaded at runtime.

**Rationale**: no new dev dependency in either language (spec scope). The checks are short. A malformed fixture fails the loader with the file name.

**Alternative**: `opis/json-schema` (PHP) and `ajv` (Node). Rejected as two new dev dependencies for about 30 lines of checks.
