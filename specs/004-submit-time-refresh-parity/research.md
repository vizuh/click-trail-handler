# Research: Submit-Time Refresh Parity for Form Adapters

Vendor docs read on 2026-10-31:

- Elementor Pro form hooks: https://developers.elementor.com/docs/hooks/forms/ (`process` runs "after the forms fields have been validated and processed"; `new_record` runs "after the form actions have run")
- Elementor hidden render filter: https://github.com/elementor/elementor/issues/9231 (`render/item/hidden` runs after attributes are set; `remove_render_attribute` + `add_render_attribute( 'input' . $index, 'value', ... )` is the working pattern)
- Fluent Forms: https://fluentforms.com/docs/fluentform_insert_response_data/ (`apply_filters( 'fluentform/insert_response_data', $formData, $formId, $inputConfigs )`, in `SubmissionHandlerService::prepareInsertData()`)
- WPForms: https://wpforms.com/developers/wpforms_field_properties/ (`$properties, $field, $form_data`, source `includes/fields/class-base.php`), https://wpforms.com/developers/wpforms_process_filter/ ("fires after all field validation and formatting data"; `$fields, $entry, $form_data`)

## Current state (source, `main` @ `ef6a20b`, 1.11.0 + #137)

| Surface | File | Finding |
|---|---|---|
| Rule of record | `class-gravity-forms-adapter.php:165-211` | `gform_pre_submission` rewrites `$_POST['input_*']` from the cookie for `allowsPrepopulate` inputs named `ct_*`; empty cookie key skipped (204); consent + per-form gate (169-175). |
| CF7 render | `class-cf7-adapter.php:55-67` | `wpcf7_form_hidden_fields` writes every payload key as `ct_<key>` from the page-request cookie. |
| CF7 submit | `class-cf7-adapter.php:93-133` | `wpcf7_mail_sent` → posted `ct_*` then `array_merge( $attribution, cookie )` (129) for ClickTrail's log only; `wpcf7_posted_data` never filtered. |
| Fluent render | `class-fluent-forms-adapter.php:64-86` | `fluentform/form_element_start` (+ legacy) echoes hidden inputs from the cookie; `static $last_form` dedupes the double hook. |
| Fluent submit | `class-fluent-forms-adapter.php:112-176` | `submission_inserted` (+ legacy); posted `ct_*` first, cookie only `if ( empty( $attribution ) )` (140); writes `fluentform_submission_meta` and logs. No pre-insert filter registered. |
| Elementor | `class-elementor-forms-adapter.php:45-96` | Only `elementor_pro/forms/new_record`; no render hook; all-empty fallback (86-88). Inputs are `form_fields[<custom_id>]`, so the JS injector exact-name match (`clicutcl-attribution.js:1090-1092`) cannot fill them. |
| WPForms render | `class-wpforms-adapter.php:43-52, 66-90` | Registers `wpforms_field_value_{ct_*}`; comment reads "this hooks in?". Filter absent from WPForms Lite 2.0.3.1 `includes/fields/class-base.php`, `includes/class-frontend.php`, `src/Frontend/Frontend.php`, `includes/functions.php`; dynamic population reads only `$_GET` `wpf{form}_{field}`. Inputs are `wpforms[fields][N]`. |
| WPForms submit | `class-wpforms-adapter.php:118-158` | `wpforms_process_complete` writes entry meta from the cookie (Pro only) and logs; `$fields` values untouched. |
| WPForms detection | `trait-admin-diagnostics-ajax.php:996-1004` | Diagnostics counts a form as covered when a field `label` or `name` starts with `ct_`. Docs (`INTEGRATIONS.md:175,215-216`, `README.en.md:104`, `tutorials/01-lead-form-attribution.md:16`) say "add matching `ct_*` hidden fields" without naming the attribute. |
| Ninja | `class-ninja-forms-adapter.php:59, 76-107` | `ninja_forms_submit_data` injects the cookie payload into `extra.clicktrail_attribution` at submit; consent-withdrawn path removes a stale extra. Already submit-time. |
| Consent gate | `class-abstract-form-adapter.php:46-48, 64`; `class-attribution-provider.php:183-216` | `should_populate()` reads the consent cookie when consent mode requires it; `log_submission()` adds `Consent::is_required() && ! marketing_allowed()`. |
| Per-form toggle | `class-gf-form-settings-tab.php:118` | Gravity Forms only. No per-provider or per-form toggle exists for the other adapters. |

## R1 - One rule, one helper

**Decision**: add `refresh_allowed()` and `cookie_value_for()` to `Abstract_Form_Adapter` and have every adapter call them, so the gate (GF 169, `log_submission()` 64, Fluent 113, WPForms 120 all repeat it) and the "present and non-empty wins" test (GF 204) live once.

## R2 - CF7 seam

**Decision**: `wpcf7_posted_data`, priority 5. It runs when `WPCF7_Submission` builds posted data, before mail and `wpcf7_mail_sent`, so mail tags, Flamingo and add-ons read the refreshed values and `on_submission()` can drop its merge. Existing `tests/unit/CF7SubmissionOutcomeTest.php` covers the lifecycle; the new test covers the filter.

## R3 - Elementor seams

**Decision**: render via `elementor_pro/forms/render/item/hidden` using the attribute pattern from issue #9231; submit via `elementor_pro/forms/process` with `Form_Record::update_field()`. `new_record` is after actions per the vendor doc, so rewriting there would miss Submissions, email and webhooks. Issue #142 named `render_field/hidden`; that hook is not in the vendor doc, `render/item/hidden` is. **Open**: `update_field()` signature in the current Pro release (T001).

## R4 - Fluent seam

**Decision**: `fluentform/insert_response_data` (+ legacy `fluentform_insert_response_data`), the documented "alter the submitted data before insert" filter. Issue #142 suggested `before_insert_submission`, which is an action and cannot return data. The rewrite is idempotent so the double hook needs no dedupe state.

## R5 - WPForms contract

**Decision**: detection = CSS class token `ct_<key>` (recommended; WPForms hidden fields have no name attribute, labels are user-facing) OR label exactly `ct_<key>` (what the docs and the Diagnostics scan already imply). Render: `wpforms_field_properties` sets `inputs.primary.attr.value`. Submit: `wpforms_process_filter` rewrites `$fields[$id]['value']`. Delete the dead registrations after T001 greps the full Lite zip. Entry meta path unchanged.

## R6 - Fallback when a form carries no `ct_*` field

**Decision**: Fluent and Elementor keep logging the cookie payload when the form has zero `ct_*` fields (today's behaviour for such forms), but when at least one `ct_*` field exists the per-key rule decides every key. This removes the stale-wins case without silently dropping ClickTrail's own record.

## Competitor report review (2026-10-31)

Shared with specs 005, 006 and 007. Verdicts are source-read against `ef6a20b`.

| # | Pattern from the report | Verdict | Code fact |
|---|---|---|---|
| 1 | Hidden fields hold the previous touch on landing-page submits | Confirmed | Render from the page-request cookie; GF fixed by #137 (`class-gravity-forms-adapter.php:165`), CF7/Fluent/Elementor/WPForms not (this spec). |
| 2 | Stale posted value beats the current cookie | Confirmed | Fluent `if ( empty( $attribution ) )` (140), Elementor 86-88; CF7 fixed for its own log only (129). |
| 3 | WPForms entry/field empty | Confirmed | `wpforms_field_value_*` not fired by WPForms Lite 2.0.3.1 (`class-wpforms-adapter.php:44-52`); meta still written at `wpforms_process_complete`. |
| 4 | No documented way to mark a WPForms field | Confirmed | Docs say "matching `ct_*` hidden fields"; scan reads label/name (`trait-admin-diagnostics-ajax.php:998-1000`). R5 defines the contract. |
| 5 | Page cache serves another visitor's hidden values | Partly | Server-rendered CF7/Fluent values are cacheable; browser Injector refills `name="ct_*"` only (`clicutcl-attribution.js:1091`, `setIfEmpty` 1146). Spec 005 (#152). |
| 6 | Delay/defer optimizers stop the fill before submit | Partly | `Gf_Minification_Protector` marks 3 handles, instantiated only by the GF adapter (`class-gravity-forms-adapter.php:104`); NitroPack compat covers 4; no `nowprocket`. Spec 006 (#153). |
| 7 | Cookie lost across www/apex/subdomains | Confirmed | `Store.setCookie` (578) writes no `domain`; no setting exists. Spec 007 (#154). |
| 8 | Accept consent then submit within seconds loses attribution | Already handled | `ct:consentResolved` granted → `runAttribution()` → `Injector.install()` (1856, 1204) fills immediately; server reads the consent cookie per request (`class-attribution-provider.php:200`). Staging checklist item. |
| 9 | Popups and late-rendered forms are missed | Already handled | MutationObserver + `elementor/popup/show` listener (`clicutcl-attribution.js:1219-1258`); still name-matched, so Elementor/WPForms need server fill (this spec). |
| 10 | Duplicate form events / no provider entry id in ClickTrail's log | No action here | Tracked as #140 and #143. |
| 11 | Safari ITP expires the cookie after 7 days | No action here | `Store.setCookie` is JS-only; tracked as #141. |

## Staging checklist (tallk.me, none run)

1. Fresh incognito: land with `?gclid=A`, submit one form per plugin (CF7, Fluent, Elementor Pro popup, WPForms, GF, Ninja); provider record and `wp_clicutcl_events` show `A`.
2. Returning visitor: OLD `gclid` session, then land with NEW `fbclid` and submit; record shows both touches with `fbclid` current.
3. Repeat 1 and 2 with WP Rocket Delay JS on, then with LiteSpeed Cache JS defer/delay on.
4. Cache leak: prime a cached page as visitor X, open as visitor Y, submit; Y's values, never X's.
5. CMP: accept then submit within 2 s; deny then submit (nothing stored).
6. Elementor popup form and a GF multi-page form.
7. WooCommerce classic and block checkout.
8. www, apex and a subdomain landing, each followed by a submit on another host.
9. Safari: land, return after 8 days, submit.
10. `WP_DEBUG_LOG` scan for notices from the four adapters after every run.

## Open questions

- `Form_Record::update_field()` availability and the exact array shape of `$record->get( 'fields' )` in the current Elementor Pro release.
- Whether WPForms Lite applies `wpforms_field_properties` to the hidden field type (the doc says every field type; `class-base.php` not read in full).
- Whether `fluentform/insert_response_data` sees the `ct_*` keys as top-level `$formData` entries (expected for hidden inputs echoed by `form_element_start`).
