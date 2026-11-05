# Implementation Plan: Offline Conversion Handoff (lead export, CRM stage updates, provider lead webhooks)

**Branch**: `feat/offline-conversion-handoff` | **Date**: 2026-11-04 | **Spec**: [spec.md](spec.md) | **Issue**: none yet (open one before T001)

## Summary

Three slices, in this order, each shippable alone: **U1** an admin "Lead export" (Google Ads file and CRM CSV, per-row preflight, audit) over the existing `clicutcl_events` `form_submission` rows; **U2** `lifecycle/update` gains value, currency, time and order ID and persists a stage row in a new `clicutcl_conversions` table, plus a documented lead-record contract for CRM field mapping; **U3** two webhook adapters (Google Ads lead form, CallRail post-call) that store provider leads in the same table. **U4**, a direct Google Ads upload, is out of scope and written down below. U1 comes first because it needs no new table and no vendor credentials; U2 needs U1's row builder; U3 needs U2's table.

## Technical Context

**Language/Version**: PHP 8.1+; no browser change (the touch timestamps and click IDs the export reads are already captured)
**Primary Dependencies**: none new
**Storage**: new table `{prefix}clicutcl_conversions` (`DB_VERSION` 6, U2); `event_data['offline']` block on new form rows (U1); one `offline_export` audit row per download
**Testing**: PHPUnit `tests/unit/OfflineExportTest.php`, `OfflineStageTest.php`, `GoogleAdsLeadWebhookTest.php`, `CallRailWebhookTest.php`; E2E scenario `offline-export` (`tools/qa/e2e/offline-export.sh`, `flock /tmp/ct-e2e.lock`); `bash tools/qa/e2e/ci.sh` as no-regression gate. No Node VM test: no JS changes.
**Constraints**: off by default everywhere, no seed (lesson g); hashed identifiers only; no cookie, storage or third-party code on the front end; no anonymous write route; no outbound HTTP at all in this spec

## Repo gates

- AGENTS.md docs triggers (API/auth, storage/retention, integration behaviour, new settings): `docs/reference/INTEGRATIONS.md`, `docs/reference/integration-capabilities.json`, `docs/reference/HOOKS-REFERENCE.md`, `docs/reference/REST-API.md`, `docs/architecture/DATA-MODEL.md`, `docs/guides/SECURITY-PRIVACY.md`, `docs/guides/SETTINGS-AND-ADMIN.md`, `docs/guides/OPERATIONS-RUNBOOK.md`, `docs/architecture/CODE-MAP.md`, `readme.txt`, `README.en.md`, `changelog.txt`.
- CodeQL in CI: every user value is validated where it is used: dates with `DateTimeImmutable::createFromFormat` strict, form filter against the discovered form list, click IDs against `^[A-Za-z0-9._-]{1,200}$`, currency `^[A-Z]{3}$`, landing page reduced with `wp_parse_url()` to scheme, host, path and checked by `wp_http_validate_url()`.
- Evidence boundary: source-present / runtime-unverified until SC-007 and SC-008 pass.

## Design

### U1 Export (no new table)

| Change | File |
|---|---|
| `offline` settings block (defaults, sanitiser; no secret, so nothing to mask) | `includes/tracking/class-settings.php:64-110` |
| `event_data['offline']` written next to `identity` when `enabled`; `email_sha256_ads` only if `$identity['hashed_email']` is set | `includes/integrations/forms/class-abstract-form-adapter.php:169-185` |
| `Offline_Identifier_Normalizer` (`email_for_ads`, `phone_e164`, `sha256`) | `includes/offline/class-offline-identifier-normalizer.php` (new) |
| `Offline_Row` builder from an events row (selected click ID, click time rule of spec Edge Cases, consent mapping) and from a conversion record | `includes/offline/class-offline-row.php` (new) |
| `Offline_Preflight::evaluate( row, settings, now )` | `includes/offline/class-offline-preflight.php` (new) |
| `Offline_Csv` (Google file, legacy template, CRM file, escaping) | `includes/offline/class-offline-csv.php` (new) |
| `Offline_Export` (batched reader, filters, caps, audit insert) | `includes/offline/class-offline-export.php` (new) |
| Submenu "Lead export" (preview with counts per reason), `admin_post_clicutcl_export_leads` | `includes/admin/class-admin.php:172-197`, `includes/admin/traits/trait-admin-pages.php` |

Namespace `CLICUTCL\Offline` resolves to `includes/offline/class-*.php` through the existing lowercase-kebab directory candidates (`includes/class-autoloader.php:53-79`); no autoloader change.

### U2 Stage updates and the lead-record contract

| Change | File |
|---|---|
| Table `clicutcl_conversions`, readiness options, `DB_VERSION` 6 recorded only when the table is ready | `includes/database/class-installer.php:23`, `:140-260` |
| `Conversion_Store`: `build_row` (pure), `insert` (`INSERT ... ON DUPLICATE KEY UPDATE id = id`: 1 inserted, 0 duplicate, `false` error), `find_lead( lead_id )` (conversion table first, then `clicutcl_events` `event_type = form_submission` with `esc_like` on a validated `lead_...` ID, `LIMIT 1`), `count_for_lead`, `purge_expired`, `delete_by_lead_ids` | `includes/database/class-conversion-store.php` (new) |
| `lifecycle_update()` and `lifecycle_permissions_check()` per spec FR-007; stage allowlist unchanged (`lead`, `book_appointment`, `qualified_lead`, `client_won`) | `includes/api/class-tracking-controller.php:87-92`, `:796-880` |
| Purge; eraser and exporter match a record by `lead_id` found in the subject's event rows (spec 008 R9 rule) or by `email_sha256` (plain and Google-normalised hash of the subject's email), which also reaches provider rows that have no events row; uninstall drop | `includes/utils/class-cleanup.php:33-60`, `includes/privacy/class-privacy-handler.php`, `uninstall.php` |

```text
clicutcl_conversions  (all datetimes UTC, written with gmdate())
  id bigint pk | blog_id bigint default 1 | row_key char(64) | kind varchar(12)  -- lead (provider) | stage
  lead_id varchar(80) | stage varchar(32) | provider varchar(24) | provider_ref varchar(128)
  click_id_key varchar(16) | click_id varchar(200) | click_time datetime null
  email_sha256 char(64) | phone_sha256 char(64)
  value decimal(12,4) null | currency char(3) | order_id varchar(128) | occurred_at datetime
  consent_state varchar(16) | consent_basis varchar(24)   -- granted|not_required|legacy|unknown ; legitimate_interest|''
  snapshot text   -- {"utm":{}, "landing_page":"", "form":{"platform","form_id"}, "click_ids":{}}
  created_at datetime | expires_at datetime
  UNIQUE (blog_id, row_key) | KEY lead_id | KEY expires_at | KEY occurred_at
row_key = hash_hmac( 'sha256', blog_id|kind|lead_id|stage|order_id  (stage)  or  blog_id|provider|provider_ref  (provider lead), wp_salt( 'auth' ) )
```

### U3 Provider leads

| Change | File |
|---|---|
| `GoogleAdsLeadWebhookAdapter` (key `google_ads_lead`), `CallRailWebhookAdapter` (key `callrail`), each with `supports`, `is_ignored_event`, `map_to_canonical` (ID and validation only) and `to_lead_record` | `includes/tracking/webhooks/class-googleadsleadwebhookadapter.php`, `class-callrailwebhookadapter.php` (new, same shape as `class-typeformwebhookadapter.php`) |
| Optional interface `Offline_Lead_Adapter { to_lead_record( array ): array }`; `ingest_webhook_payload()` stores instead of dispatching for such adapters | `includes/tracking/class-webhookprovideradapterinterface.php`, `includes/api/class-tracking-controller.php:743` |
| `provider_adapter()` cases | `includes/api/class-tracking-controller.php:923-934` |
| Default provider rows (enabled 0, secret '') and the two admin rows | `includes/tracking/class-settings.php:78-92`, `includes/admin/class-admin.php:625-640`, `:1075-1090` |
| `Webhook_Auth::verify_google_key()`; `callrail` branch in `verify_request()`; `webhook_permissions_check()` reads the JSON body for the key provider and applies `check_rate_limit( 'offline_webhook' )` | `includes/tracking/class-webhook-auth.php:29-118`, `includes/api/class-tracking-controller.php:665-695` |
| Claim and dedupe: `Claim_Lock::acquire( 'clicutcl_prov_lead_' . md5( provider . ref ), 30 )` around find, insert; the unique `row_key` is the durable guard | `includes/database/class-conversion-store.php` |
| `do_action( 'clicutcl_provider_lead_logged', $provider, $provider_ref, $lead_id, $record )` after a first insert | same |

### Contracts

```text
Google file  (header, one line per ready/warn row, UTF-8 without BOM, CRLF)
  Google Click ID,GBRAID,WBRAID,Conversion Name,Conversion Time,Conversion Value,Conversion Currency,Order ID,Email,Phone
  Cj0KCQ_test-AbC,,,Lead,2026-10-20T08:20:11+00:00,,,lead_form_ab12,<sha256 hex>,<sha256 hex>
legacy template: line 1 "Parameters:TimeZone=+0000"; time "2026-10-20 08:20:11+0000"; no Email, Phone
CRM file     : spec US2-1 columns, every row, preflight + reason last

POST /clicutcl/v2/lifecycle/update   (admin cookie+nonce/app password, or header x-clicutcl-crm-token / body token)
  {"lead_id":"lead_form_ab12","stage":"client_won","value":4200.5,"currency":"EUR",
   "occurred_at":"2026-11-02T14:00:00+01:00","order_id":"DEAL-77"}
  200 {"success":true,"skipped":false,"event_id":...,"event_name":"client_won","recorded":true}
  200 {... "recorded":false,"reason":"lead_not_found"|"offline_disabled"} | 200 {... "duplicate":true}
  400 invalid_stage | invalid_occurred_at | invalid_value | invalid_currency

POST /clicutcl/v2/webhooks/google_ads_lead   body.google_key == stored secret; 200 {} ; 401 ; test lead 200 {}
POST /clicutcl/v2/webhooks/callrail          header Signature: base64(HMAC-SHA1(signing_key, raw body)); 200 | 401
```

**Lead-record contract for CRM mapping (docs, U2).** Copy these into CRM fields at form time or from `clicutcl_form_submission_logged`: `ct_gclid`, `ct_gbraid`, `ct_wbraid` (click IDs; keep case), `ct_lt_touch_timestamp` and `ct_ft_touch_timestamp` (click time proxies, ISO 8601 UTC), `ct_attribution_selected_click_id`, `ct_lead_id` and `ct_event_id` (entry meta for the adapters that call `entry_meta_to_write()`, else the hook), `ct_lt_landing_page`. Later stages go back through `lifecycle/update` with `lead_id`. Limit: ClickTrail keeps the lead for `cookie_days`; a longer sales cycle needs these fields stored in the CRM.

### U4 Deferred: direct Google Ads upload

Not built here. `Google_Ads_Adapter` (`includes/server-side/class-google-ads-adapter.php:58-84`) POSTs the canonical event as JSON to one configured endpoint; it is a collector scaffold, not an upload client. A real client needs OAuth, a conversion action ID, an account ID and the vendor's current ingestion contract (research R1: `UploadClickConversions` is allowlist-gated since 2026-06-15, the Data Manager API is the replacement). Revisit when all hold: a customer is blocked by the manual file; the maintainers approve storing OAuth refresh tokens encrypted (`encrypt_secrets_at_rest`); a test Google Ads account is available; the Data Manager API contract is re-read the day work starts. Any fetch then uses `wp_safe_remote_post` with `reject_unsafe_urls` and a fixed Google host allowlist.

## Risks

- `DB_VERSION` 6 may collide with an open branch that also bumps it (spec 008 took 5); the integrator renumbers.
- A Google lead form or CallRail call that Google or CallRail also report natively would double count if exported as `lead`; default exclusion covers it, `include_provider_leads` re-opens it knowingly.
- `cookie_days` retention bounds both the stage lookup and the lead rows; admins who lower it below their sales cycle lose stages silently except for `lead_not_found`.
- CallRail's event discriminator and `timestamp` semantics on post-call payloads are unverified (research R6): the adapter is defensive and the E2E must capture a live payload before the status changes.
- The export is a bulk personal-data download (hashes, UTMs, landing pages): audit row, capability, nonce, row cap; retention note in `SECURITY-PRIVACY.md`.
