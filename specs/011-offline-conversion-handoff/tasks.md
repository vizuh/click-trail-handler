# Tasks: Offline Conversion Handoff (lead export, CRM stage updates, provider lead webhooks)

**Input**: [spec.md](spec.md), [plan.md](plan.md), [research.md](research.md)

Status: none started. Each slice (U1, U2, U3) ships alone; U4 (direct upload) has no task, see plan.md.

## Phase 1: Export of form leads (U1; US1, US2, US3)

- [ ] T001 Open the tracking issue. Add the `offline` block (`enabled`, `conversion_names`, `click_window_days`, `google_click_id_columns`, `include_provider_leads`, `default_currency`) to `Tracking_Settings::defaults()` and `sanitize()` (strict enums, clamp 7 to 90, `^[A-Z]{3}$`, text up to 100 chars) and a card on the Settings tab. No seed in `Installer` (FR-001). Target: `includes/tracking/class-settings.php:64-110`, `includes/admin/class-admin.php:575-640`, `:1075-1090`
- [ ] T002 `CLICUTCL\Offline\Offline_Identifier_Normalizer`: `email_for_ads`, `phone_e164`, `sha256`; the SC-002 vectors. Target: `includes/offline/class-offline-identifier-normalizer.php`
- [ ] T003 Write `event_data['offline']` (`occurred_at` UTC, `consent`, `email_sha256_ads` only when the resolver returned `hashed_email`, `selected_click_id_key`) when `enabled`; nothing else in `log_submission()` changes (dedupe, claim, hook). Target: `includes/integrations/forms/class-abstract-form-adapter.php:162-237`
- [ ] T004 `Offline_Row` (from an events row: selected click ID, click time rule, consent mapping, legacy fallbacks) and `Offline_Preflight` (pure, closed reason set, injected `now`). Target: `includes/offline/class-offline-row.php`, `class-offline-preflight.php`
- [ ] T005 `Offline_Csv`: Google file, legacy template, CRM file, RFC 4180, formula prefix with the click ID exemption, offset times. Target: `includes/offline/class-offline-csv.php`
- [ ] T006 `Offline_Export` (batched reader 500 by `id`, date and form filters, `clicutcl_offline_export_max_rows`, audit insert) and the "Lead export" submenu page with the preview counts and the "upload an extra day" note; `admin_post_clicutcl_export_leads` with capability filter, `check_admin_referer`, POST only. Target: `includes/offline/class-offline-export.php`, `includes/admin/class-admin.php:172-197`, `includes/admin/traits/trait-admin-pages.php`
- [ ] T007 PHPUnit `tests/unit/OfflineExportTest.php`: SC-001, SC-002, SC-003, SC-004 (`require_once` the classes directly like `tests/unit/BoundarySecurityTest.php:14-16`; `tests/bootstrap.php` already stubs `current_user_can` (`:116`), `wp_verify_nonce` (`:129`) and `wp_json_encode` (`:243`); add `check_admin_referer` there if the handler needs it). Target: `tests/unit/OfflineExportTest.php`, `tests/bootstrap.php`

## Phase 2: Stage updates and the lead-record contract (U2; US4)

- [ ] T008 Table `clicutcl_conversions`, readiness options, `DB_VERSION` 6 recorded only when the table is ready; update `tools/qa/touch-events-db.test.php` (reads the constant). Target: `includes/database/class-installer.php:23`, `:140-260`, `tools/qa/touch-events-db.test.php`
- [ ] T009 `Conversion_Store`: pure `build_row`, `insert` (1 / 0 / false), `find_lead` (conversion table, then events `LIKE` on a validated ID), `count_for_lead`, `purge_expired`, `delete_by_lead_ids`, ceilings with filters. Target: `includes/database/class-conversion-store.php`
- [ ] T010 `lifecycle_update()` and `lifecycle_permissions_check()` per FR-007 and FR-012 (new fields additive, 400 codes, `recorded` / `duplicate` / `reason`, rate limit for token callers, `lifecycle.crm_ingestion.enabled` enforced server-side, dispatch untouched). `Offline_Row` gains a conversion-record source so the export lists stage rows. Target: `includes/api/class-tracking-controller.php:87-92`, `:796-880`, `includes/offline/class-offline-row.php`
- [ ] T011 Daily purge before the events-table early return; eraser and exporter for conversion rows by `lead_id` and by `email_sha256` (plain and Google-normalised hash of the subject's email); `build_event_match_where()` also matches the hashed email in event rows (`hashed_email`, `offline.email_sha256_ads`), because it only matches the raw email today; uninstall drop. Target: `includes/utils/class-cleanup.php:33-60`, `includes/privacy/class-privacy-handler.php:502-531`, `uninstall.php`
- [ ] T012 PHPUnit `tests/unit/OfflineStageTest.php`: SC-005 (accept, duplicate, unknown lead, 400 codes, unchanged legacy request, ceilings, `provider` + `provider_ref`) plus FR-012: `lifecycle.crm_ingestion.enabled` off + valid token => 403 `lifecycle_disabled`, nothing written. Target: `tests/unit/OfflineStageTest.php`
- [ ] T013 Lead-record contract section for CRM mapping (plan.md "Lead-record contract") in `INTEGRATIONS.md`, with the retention limit stated. Target: `docs/reference/INTEGRATIONS.md`

## Phase 3: Provider leads (U3; US5, US6)

- [ ] T014 `Webhook_Auth::verify_google_key()` and the `callrail` branch (`Signature`, HMAC-SHA1 with a `phpcs:ignore` note that the vendor mandates it, `timestamp` tolerance, filter `clicutcl_callrail_replay_window`); `webhook_permissions_check()` reads the JSON body for the key provider and rate-limits scope `offline_webhook`. Target: `includes/tracking/class-webhook-auth.php:29-118`, `includes/api/class-tracking-controller.php:665-695`
- [ ] T015 `GoogleAdsLeadWebhookAdapter`, `CallRailWebhookAdapter`, `Offline_Lead_Adapter` interface, `provider_adapter()` cases, store-instead-of-dispatch branch in `ingest_webhook_payload()`, `Claim_Lock` plus unique row key, `clicutcl_provider_lead_logged`, a `{}` JSON body for Google (return `new \stdClass()`, an empty PHP array encodes as `[]`), default provider rows and admin rows (secret masking as for the others). Target: `includes/tracking/webhooks/class-googleadsleadwebhookadapter.php`, `class-callrailwebhookadapter.php`, `includes/tracking/class-webhookprovideradapterinterface.php`, `includes/api/class-tracking-controller.php:743`, `:923-934`, `includes/tracking/class-settings.php:78-92`, `includes/admin/class-admin.php:625-640`, `:1075-1090`
- [ ] T016 PHPUnit `GoogleAdsLeadWebhookTest.php` and `CallRailWebhookTest.php` with fixtures `tests/fixtures/offline/google-ads-lead.json` (shape of the vendor `WebhookLead`) and `tests/fixtures/offline/callrail-post-call.json` (the vendor documentation body, byte exact, for the `UZAHbUdfm3GqL7qzilGozGzWV64=` vector): SC-006. Target: `tests/unit/`, `tests/fixtures/offline/`

## Phase 4: Proof and docs

- [ ] T017 E2E scenario `offline-export` in the shared pod: CF7 submit with `?gclid=GA`, stage post with the CRM token, download both files with an admin cookie, assert both rows and the audit row; `flock /tmp/ct-e2e.lock`; run `ci.sh` and `adapters-ci.sh` as no-regression gates. Target: `tools/qa/e2e/offline-export.sh`, `offline_export.py`, `README.md`
- [ ] T018 Docs per FR-011 and `changelog.txt` (`= Unreleased =`); two `source.webhook.*` entries in `integration-capabilities.json` with `known_limits` copied from research R6 and R7. Target: `docs/`, `readme.txt`, `README.en.md`, `changelog.txt`
- [ ] T019 Live acceptance, open: (a) upload the Google file to a test Google Ads account and record the Data Manager result (SC-008); (b) capture one real CallRail post-call payload and one Google "Send test data" request and settle the unverified points of research R6 and R7; (c) record both in research.md and drop the runtime-unverified label only for what passed.

## Dependencies

T001 → T003, T006. T002 → T003, T004. T004 → T005 → T006 → T007. T008 → T009 → T010, T011, T015. T004, T009 → T010. T010 → T012, T013. T014 → T015 → T016. T006, T010 → T017. T017 → T018. T019 after T017.
