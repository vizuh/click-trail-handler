# Tasks: Conversion Semantics (purchase is paid, the thank-you page is a funnel step)

**Input**: [spec.md](spec.md), [plan.md](plan.md), [research.md](research.md)

## Phase 0: Decisions (before code)

- [ ] T001 Hugo answers research open questions 1 to 9 (step event name; default for existing installs; retiring the legacy thank-you `lead`; COD; pending-order browser recovery; setting names and lead evidence; consent authority for the existing milestones; administrator-created orders; Site Health severity). The plan assumes the proposals. Target: `research.md` (record each answer as a dated decision, as spec 013 R10 and R11 do), `plan.md` Scope decisions

## Phase 1: Settings and surfaces (US4, US8)

- [ ] T002 Add `purchase_trigger` (default `thankyou`) and `thank_you_lead` (default `always`) to `get_attribution_settings_schema()` with sanitisers that fall back to the default; expose `capture.purchase_trigger` and `capture.thank_you_lead` in the admin payload and the save mapping; add `Attribution_Settings::get_purchase_trigger()` and `get_thank_you_lead()` (FR-001, FR-002). Target: `includes/admin/class-admin.php:602`, `:1001`, `:1183`, `includes/settings/class-attribution-settings.php`
- [ ] T003 Seed `purchase_trigger = paid` and `thank_you_lead = never` inside the fresh-install branch of `seed_fresh_install_defaults()`, behind the existing earlier-use guard; leave `maybe_upgrade()` and the wizard alone (FR-003). Target: `includes/database/class-installer.php:95-111`
- [ ] T004 Events-tab section "Conversion semantics": the two selects with the FR-016 copy and the double-counting warning; strings go through the text domain. Target: `assets/js/admin-settings-app.js` near `:916` (`woo_enhanced_datalayer`), `includes/admin/class-admin.php`
- [ ] T005 Wizard step 3 summary part for WooCommerce or EDD (FR-017). Target: `includes/admin/class-setup-wizard.php:544-560`
- [ ] T006 `SiteHealth::test_conversion_semantics()` and its `add_tests()` entry; status `good` in both modes unless T001 says otherwise; when WooCommerce uses a block theme, add a hint if the order-confirmation template does not appear to contain the "Additional information" block (the browser `purchase` and the step event would not render; W6) (FR-017). Target: `includes/admin/class-sitehealth.php:36-55`
- [ ] T007 PHPUnit `tests/unit/PurchaseTriggerSettingsTest.php`, patterned on `CookieScopeSettingsTest.php`: missing key reads legacy, invalid values fall back, fresh seed, no seed on earlier use, wizard never writes the keys. Target: `tests/unit/`

## Phase 2: WooCommerce server paths (US1, US2, US3, US5)

- [ ] T008 `paid_statuses()` (filter `clicutcl_woocommerce_paid_statuses` over `wc_get_is_paid_statuses()`), `is_purchase_eligible()` (`created_via` allowlist, filter `clicutcl_woocommerce_purchase_created_via`), `is_within_server_retention()` (creation date only), `marker_has_channel()` and `marker_with_channel()` for the values `server`, `browser`, `yes` (FR-007, FR-009, FR-010). Target: `includes/integrations/class-woocommerce.php` near `:308`
- [ ] T009 `maybe_dispatch_purchase( $order, $source_hook )` and its calls from `track_paid_milestone()` and `track_paid_milestone_from_status_change()` after the unchanged `order_paid` call; `Claim_Lock` on `clicutcl_purchase_lock_<id>`, marker re-read, `clicutcl_woocommerce_purchase_payload` filter, `should_mark_milestone_sent( $result, false )`; a no-op in `thankyou` mode (FR-007, FR-011, FR-012). Do not reuse `maybe_dispatch_order_milestone()` (research R4). Target: `class-woocommerce.php:365-408`
- [ ] T010 Restructure `push_purchase_event()`: step event first (FR-004, FR-005, PHP gate then a `sessionStorage` guard in the inline script), `thankyou` branch byte-identical, `paid` branch (paid status, eligibility, thank-you retention, lock, browser push, inline server fallback, unioned marker). Target: `class-woocommerce.php:224-300`
- [ ] T011 Consent authority: set `meta.consent_authority = order_snapshot` in `dispatch_order_payload()` when `! is_customer_request_context()`; honour it in `Dispatcher::consent_allows()`; resolve identity from the snapshot's marketing flag in the same case (FR-008). Apply to `order_paid`, `order_refunded` and `order_cancelled` only if T001 says so. Target: `class-woocommerce.php:644`, `:1092`; `includes/server-side/class-dispatcher.php:420-440`
- [ ] T012 Order trace: store the marker value and the `source_hook` on the `purchase` trace and show both in the Diagnostics lookup; extend the readiness analyzer and add fixtures (`F17` paid-transition purchase, `F18` on-hold then paid, `F19` renewal) beside F06 and F07. Target: `class-woocommerce.php:734-757`, `assets/js/admin-diagnostics.js:261`, `includes/Intelligence/class-woo-readiness-analyzer.php`, `tests/fixtures/woo-readiness/v1/`

## Phase 3: Easy Digital Downloads (US7)

- [ ] T013 Confirm where EDD 3.7.3 emits `edd_update_payment_status` for a transition into `complete` (read the source in the harness container, then run `edd_update_order_status()` on a pending `sale` order with a probe); if it is not the right hook, pick EDD's real completion action and record it in research W9. Target: `research.md`, `tools/qa/e2e/edd.py`
- [ ] T014 EDD handler on the confirmed hook: `sale` type, `PAID_STATUSES`, creation-date guard, snapshot consent, `Claim_Lock`, marker values, event id `purchase_edd_<id>`; no-op in `thankyou` mode (FR-013). Target: `includes/integrations/class-edd.php:64-71`
- [ ] T015 EDD receipt: step event for any status, `paid` branch with the unioned marker, `thankyou` branch unchanged. Target: `class-edd.php:113-186`

## Phase 4: Lead gen and browser (US6)

- [ ] T016 `pushEvent()` option `server: false`; `ct_lead_seen` write after the consent check for non-thank-you `lead` pushes; the same in `pushDL()` for `lead_submit`; `ct_form_submit_seen` in the `submit` listener (FR-005, FR-015). Target: `assets/js/clicutcl-events.js:88-119`, `:844-862`; `assets/js/clicutcl-attribution.js:2720-2730`
- [ ] T017 `trackThankYouLead()`: step event with `ct_thankyou_view_<path>`, `orderReceived` deferral, `thankYouLead` branch, legacy body untouched for `always`; extend `clearIdentity()` to the new keys (FR-014). Target: `clicutcl-events.js:2213-2237`, `:656-663`
- [ ] T018 Localize `thankYouLead` and the Woo `orderReceived` flag. Target: `includes/class-clicutcl-core.php:333`, `:568-`
- [ ] T019 Storage inventory: add the three session keys to the table and to `tools/qa/cmp-transition.test.js` (the existing prefix rule for `ct_thankyou_lead_` is the model, `:208`). Target: `docs/guides/SECURITY-PRIVACY.md:114`, `tools/qa/cmp-transition.test.js`

## Phase 5: Proof

- [ ] T020 Move the shared order and dispatcher doubles from `WooCommercePurchaseDispatchLockTest.php` to `tests/helpers/woo-doubles.php` without changing that test's assertions (SC-005). Target: `tests/unit/WooCommercePurchaseDispatchLockTest.php`, `tests/helpers/`
- [ ] T021 PHPUnit `tests/unit/WooPaidPurchaseTest.php`: every row of the plan's test table (SC-001). Target: `tests/unit/`
- [ ] T022 PHPUnit EDD cases in `EddIntegrationTest.php` (SC-002). Target: `tests/unit/EddIntegrationTest.php`, `tests/helpers/edd-doubles.php`
- [ ] T023 Node VM `tools/qa/thank-you-semantics.test.js` (SC-003) and add it to `npm run test:consent`. Target: `tools/qa/thank-you-semantics.test.js`, `package.json:6`
- [ ] T024 Harness `woo-purchase-ci.sh`, `woo_purchase.py`, `verify_woo_purchase.py`: `hook-probe` (a real thank-you page on the classic shortcode checkout and on a block checkout, plus a block template with the "Additional information" block removed), `bacs-then-paid` (including the authenticated wp-admin status change with an opposite consent cookie), `card-simulated`, `cod`, `failed`, `refresh-tabs`, `legacy-unchanged`, `retention`; record `EVIDENCE-<date>-woo-purchase.md`; run under `flock /tmp/ct-e2e.lock` (SC-004). Target: `tools/qa/e2e/`
- [ ] T025 Harness `edd-pending-complete` in `edd-ci.sh`/`edd.py`/`verify_edd.py`; record `EVIDENCE-<date>-edd-paid.md` (SC-004). Target: `tools/qa/e2e/`

## Phase 6: Docs and release

- [ ] T026 Docs per FR-019, including the Woo and EDD flows in `INTEGRATIONS.md`, the two filters in `HOOKS-REFERENCE.md` and a note on `clicutcl_thank_you_matchers`, the marker values in `DATA-MODEL.md`, the consent authority in `SECURITY-PRIVACY.md`, and `changelog.txt` (`= Unreleased =`) with the plan's wording. Target: docs, `readme.txt`, `README.en.md`, `README.pt-BR.md`, `config/feature-test-matrix.json`
- [ ] T027 Migration section from the plan in `docs/tutorials/04-gtm-starter-kit.md` and `docs/tutorials/02-woocommerce-order-attribution.md`; add a `CE - ct_thank_you_view` trigger (no tag) to `assets/gtm-starter-kit.json` only if T001 approves; keep `tools/qa/gtm-starter-kit.test.js` green. Target: docs, `assets/gtm-starter-kit.json`
- [ ] T028 Live acceptance (SC-007): a real card order, a real bank-transfer order marked paid, a Subscriptions renewal and a block-theme order-received page on a disposable site; drop the runtime-unverified label only for what passes. Open: not run.

## Dependencies

T001 → T002, T008, T011, T014, T017. T002 → T003, T004, T005, T006, T007, T018. T008 → T009, T010. T009, T010 → T011, T012. T013 → T014 → T015. T016 → T017. T018 → T017. T017 → T019. T020 → T021. T008 to T012 → T021. T014, T015 → T022. T016 to T019 → T023. T009 to T012 → T024. T013 to T015 → T025. T021 to T025 → T026, T027. T028 after T024 and T025. Phase 1, Phase 2, Phase 3 and Phase 4 can run in parallel after T002; T011 and T012 share files with T009 and T010 and go in one change.
