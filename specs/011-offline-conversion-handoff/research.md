# Research: Offline Conversion Handoff (lead export, CRM stage updates, provider lead webhooks)

Source read on 2026-11-04 (`main` @ `e00552c`, 1.12.0 plus unreleased 1.13 work). Vendor pages read the same day. Nothing below was run against Google Ads, CallRail or a CRM; every code row is source-present / runtime-unverified.

Vendor references:

- Google Ads Help, fix import errors: https://support.google.com/google-ads/answer/13321563
- Google Ads Help, import from clicks using files (legacy): https://support.google.com/google-ads/answer/7014069
- Google Ads Help, GCLID setup: https://support.google.com/google-ads/answer/7012522
- Google Ads Data Manager Help, prepare data: https://support.google.com/google-ads-data-manager/answer/14184381
- Google Ads API, upload offline conversions: https://developers.google.com/google-ads/api/docs/conversions/upload-offline
- Data Manager API, send events and formatting: https://developers.google.com/data-manager/api/devguides/events/google-ads/offline/send-events , https://developers.google.com/data-manager/api/devguides/concepts/formatting
- Google Ads lead form webhook: https://developers.google.com/google-ads/webhook/docs/implementation ; About lead form assets (9423234): https://support.google.com/google-ads/answer/9423234 ; webhook setup (16729613): https://support.google.com/google-ads/answer/16729613
- CallRail API v3 (webhooks, security): https://apidocs.callrail.com/

## Current state

| Surface | File | Finding |
|---|---|---|
| Form log | `includes/integrations/forms/class-abstract-form-adapter.php:104-144`, `:169-185`, `:230` | `log_submission()` is consent-gated, claim-locked and deduplicated; the row `event_data` holds `event_id`, `lead_id`, `platform`, `form_id`, `attribution`, optional `entry_id`, `identity` (resolver output, IP anonymised). Action `clicutcl_form_submission_logged` fires once per entry. Lead ID is `lead_` + event ID without `evt_` (`:248-251`); entry meta `ct_event_id` / `ct_lead_id` via `entry_id_meta()` (`:263-272`). |
| Events table | `includes/database/class-installer.php:148-155` | Columns `id`, `event_type`, `event_data` longtext, `created_at`; one index on `event_type`. No lead ID column, so a lookup by lead is a `LIKE` on JSON. |
| Retention | `includes/utils/class-cleanup.php:37-42`, `:57` | Rows older than `cookie_days` (default 90) are deleted, 1000 per run, against `UTC_TIMESTAMP()`; the column default is DB-local time. |
| Identity | `includes/tracking/class-identity-resolver.php:65-75`, `:107-133` | Email: trim, lowercase, SHA-256, no Gmail rule. Phone: E.164 via default country, dropped if invalid. Empty unless `Consent_Decision` allows (`consent_gated_minimal`). |
| Click IDs | `includes/Core/class-attribution-provider.php:64-79`, `:282-318`; `assets/js/clicutcl-attribution.js:223-281` | Top-level IDs are newest-wins per platform; `attribution_selected_click_id` is the newest across platforms (any of ten keys, not only Google); touch timestamps `ft_` / `lt_touch_timestamp` are ISO 8601 UTC (`toISOString()`, `:2392-2400`). `click_id_history` stays out of the cookie (`:925-927`). |
| Lifecycle route | `includes/api/class-tracking-controller.php:156-166`, `:796-880`, `:87-92` | Stages `lead`, `book_appointment`, `qualified_lead`, `client_won`. Reads only `stage`, `lead_id`, `event_id`, `provider`; ignores value, currency, time and click IDs; stores nothing; calls `Dispatcher::dispatch_from_v2()` (`class-dispatcher.php:225-265`). Auth: `manage_options`, else flag `lifecycle_ingestion` (default 1) plus a non-empty token, `hash_equals`. No rate limit. `lifecycle.crm_ingestion.enabled` (`class-settings.php:94-99`) is stored and shown in the admin but not read by the permission check. |
| Webhooks | `includes/api/class-tracking-controller.php:665-790`, `:923-934`; `includes/tracking/class-webhook-auth.php:29-118` | Calendly, HubSpot, Typeform only. Each adapter maps to a canonical event and dispatches; none stores a lead. Auth is header HMAC per provider plus a replay cache (409 on a repeated signature). `Dedup_Store` is transient-backed and capped at 30 days (`class-dedup-store.php:102`). |
| Locks | `includes/tracking/class-claim-lock.php:43-68` | `acquire( key, ttl )` returns an owner value or `''`; used by `log_submission()` and Woo purchase. |
| Google Ads adapter | `includes/server-side/class-google-ads-adapter.php:58-84` | Generic JSON `wp_remote_post` to one endpoint with `reject_unsafe_urls`. Not an upload client: no OAuth, no conversion action, no click ID mapping. |
| Consent | `includes/server-side/class-consent.php:68-71`, `:101-111` | State is `marketing` and `analytics` booleans; no `ad_user_data` / `ad_personalization`. |
| Admin | `includes/admin/class-admin.php:160-197`, `includes/admin/class-log-list-table.php:62-115` | Menu `ClickTrail` with Settings, Logs, Diagnostics; the Logs page lists `clicutcl_events` rows by `event_type`. No audit-log facility exists. |
| Sibling examples (read only) | `../clicktrail-examples/nextjs-google-ads-offline-conversions/src/lib/hash.ts`, `google-ads-client.ts`; `webhook-to-offline-conversion/src/index.mjs`; `activecampaign-won-handoff/src/index.mjs`; `twenty-crm-google-ads/src/index.mjs` | Gmail rule with dots and `+` removed; E.164 required, no guessed country; one click ID per row with the order `gclid`, `wbraid`, `gbraid` (labelled a sample policy); offset formatted conversion time; consent gates identifiers; click ID allowlist and deterministic event ID; CRM custom fields carry `gclid`/`gbraid`/`wbraid`. The Next.js README says it does not implement Data Manager. |
| Sibling JS | `../clicktrail-js/integrations/n8n/src/events.ts:327-350`, `docs/internal/WP-PARITY-DRAFT.md:140-152` | `sale` event requires `clickId` or `trailId`; the click-ID history exists because uploads are tied to the click within the window. `../clicktrail-mcp` has no offline conversion code (grep for "offline"). |

## R1 Output: Data Manager file, no API client

**Decision**: write a CSV for Google Ads Data Manager (header on line 1) and a legacy-template switch; no upload from WordPress (U4).
**Evidence**: Google, 7014069: "Enhanced conversions for leads only supports Google Ads Data Manager files." and "Offline conversion import is a legacy feature". Google, 13321563 (banner): "Starting June 15, 2026, offline conversions import and enhanced conversions for leads uploads will be migrated to the Data Manager API and blocked in the Google Ads API." Google Ads API page: "`UploadClickConversions` requests fail with a `CUSTOMER_NOT_ALLOWLISTED_FOR_THIS_FEATURE` error for developer tokens that sent no offline conversion upload requests between December 17, 2025, and June 15, 2026." Data Manager prepare data: "the first line of the file must contain the headers", so the legacy `Parameters:TimeZone` row cannot lead a Data Manager file; it is emitted only in the legacy mode.

## R2 Click ID columns: one, with Google's order

**Decision**: `one` by default, `all` optional. **Evidence**: API page: "When several identifiers are present, Google Ads uses `gclid`, then `wbraid`, then `gbraid`." and "If you set `wbraid` or `gbraid` without a `gclid`, Google Ads ignores the `user_identifiers` field." A common assumption is that the three are mutually exclusive per row; no current Google page says so (the `ClickConversion` v23 reference lists them as separate optional fields; Data Manager lists GCLID, GBRAID, WBRAID as separate optional columns). Whether the legacy file importer rejects several IDs in one row is unverified, hence `one`. `selected_click_id` is not used for the Google file: it may be an `fbclid`.

## R3 Time

**Decision**: store an explicit UTC `offline.occurred_at` at log time; write `yyyy-MM-ddTHH:mm:ss+00:00`. **Evidence**: API page: `conversion_date_time` "Must be after the click time and include a timezone offset: `yyyy-mm-dd HH:mm:ss+|-HH:mm`"; Data Manager: "If no fallback timezone is selected, rows without a timezone won't be imported." and `2012-08-14T17:01:54-07:00` is a listed format. Legacy help lists `yyyy-MM-dd HH:mm:ss+z` with `+0500` (no colon), used in legacy mode. 13321563: "The offline conversion can't happen before the ad click. Add 1-2 days to your conversion time in your upload, or check that the time zone is properly set." The ordering rule is checked in preflight (`conversion_before_click`).

## R4 Hashing, normalisation, no raw identifiers

**Decision**: new `Offline_Identifier_Normalizer`; `Identity_Resolver` unchanged (every destination reads its output, so a Gmail rule there would change other platforms' hashes). The Google email hash is computed at log time from the submitted email and stored as `offline.email_sha256_ads` because a stored hash cannot be re-normalised later. **Evidence**: formatting guide: "If the email address has the `gmail.com` or `googlemail.com` domain: Remove all dots (`.`) before the `@` symbol. Remove the plus sign (`+`) from the local-part and remove all characters that follow it." Data Manager Help lists only dots ("Remove all periods (.) that precede the domain name in gmail.com and googlemail.com"); the developer rule is used and the conflict is open question 3. Vectors from the formatting guide, recomputed locally: `john.smith@example.com` gives `8e621e3d...022a0`, `+18005550199` gives `78724165...20b90` (SC-002). 7014069: "Make sure your data doesn't include ... any unhashed personal information", so raw email is never written; ClickTrail does not store it either (`identity` holds hashes), so there is no "raw email behind a setting" option. Phone reuses `identity.hashed_phone` (already E.164).

## R5 New table for stages and provider rows; events table for form leads

**Decision**: form leads are read from `clicutcl_events` (no new write on the hot path except the small `offline` block); stages and provider leads go to `clicutcl_conversions`. **Rationale**: a stage can arrive after the events row is purged, and it must be idempotent and self-contained (click ID, hashes, consent copied at write time); events has no unique key and no lead index; `Dedup_Store` is a transient capped at 30 days, too weak for provider redelivery. Same pattern as spec 008 R5/R6 (unique key, `ON DUPLICATE KEY UPDATE id = id`). Rows expire at `created_at + cookie_days`, which is also all the click ID is useful for: 13321563 "We only keep the GCLID for 90 days."
**Rejected**: stage rows as `clicutcl_events` rows (no idempotency, JSON `LIKE` on every export).

## R6 CallRail

**Decision**: provider key `callrail`; auth `Signature` header, `base64( HMAC-SHA1( signing_key, raw body ) )`; freshness from the body `timestamp` with tolerance 1800 s (filter `clicutcl_callrail_replay_window`) because the post-call hook "has a maximum delay of 20 minutes after the hangup"; the existing replay cache applies. **Evidence**: CallRail Security: "CallRail includes a `Signature` header"; "Each webhook request will include a `timestamp` field ... you can compare to the current time to prevent an unauthorized system from replaying requests."; "CallRail does not resend webhooks ... Repeated failed webhooks could result in an automated disabling of the webhook integration." The vendor test vector (key `072e77e426f92738a72fe23c4d1953b4`, the documented JSON body) was recomputed on 2026-11-04 and gives `UZAHbUdfm3GqL7qzilGozGzWV64=`; its body carries `"timestamp":"2018-02-19T13:41:00.236-05:00"`, so the test injects the clock. Fields used: `id`, `start_time` ("ISO 8601 format with an offset"), `customer_phone_number` ("in E.164 format"), `gclid`, `utm_source`/`utm_medium`/`utm_campaign`, `landing_page_url`, `direction`, `call_type`. gbraid and wbraid are not documented call fields.
**Unverified**: no documented field says which webhook type a payload came from; the adapter accepts payloads with `id`, `start_time` and `duration`/`call_type`, drops `direction = outbound` and bodies with `changes` (Call Modified), and the admin text says to set only `post_call_webhook`. A live payload is needed before the status moves.

## R7 Google lead form webhook

**Decision**: provider key `google_ads_lead`; auth is the `google_key` field of the JSON body compared with `hash_equals` to the stored secret (Google signs nothing and sends no timestamp, so there is no replay window; dedupe is the control). **Evidence**: "Before processing a lead received over webhook, validating `google_key` is the same as configuring in Google Ads"; "Use `lead_id` to dedupe leads."; "A single lead is not guaranteed to be delivered exactly once"; `is_test`: "If value is true, treat this lead as test lead."; responses `200` with `{}`, `4XX` not retryable, `5XX` retryable; `gcl_id` is the click ID, `lead_submit_time` is ISO 8601, `PHONE_NUMBER` is E.164, `user_column_data[].column_id` is `EMAIL`, `WORK_EMAIL`, `PHONE_NUMBER`, `WORK_PHONE`. The key travels in the body, so the site must be HTTPS (docs). Google keeps leads 60 days (About lead form assets, 9423234), a second reason to store the click ID on arrival.

## R8 Consent semantics for provider leads

**Decision**: these leads did not pass the site's CMP. Store `consent_state = unknown`, `consent_basis = legitimate_interest` (an operational label, not a legal finding; open question 1), hashes only, and exclude provider rows (leads and their stages) from every export unless `include_provider_leads` is on. Calendly precedent (`includes/tracking/webhooks/class-calendlywebhookadapter.php`): a webhook consent state exists only when the booking link carried a stamp; absent means no decision. The admin text next to the setting warns that Google's lead form has its own conversion goal (About lead form assets: "Campaign must be optimized towards a Google lead form conversion goal") and CallRail may report calls to Google itself, so exporting the `lead` row can double count [INFERENCE: not verified with either vendor].

## R9 CSV escaping versus click IDs

**Decision**: formula prefix `'` on every free-text cell; none on cells matching `^[A-Za-z0-9._-]{1,200}$` in the click ID columns. A GCLID is base64url-like (the `-` and `_` characters are legal) and "The GCLID is case sensitive" (7012522); a prefix would break the upload. The allowlist excludes `=`, `+`, `@`, `|`, `'`, `!`, so no DDE or formula payload fits.

## R10 Preflight windows

`click_too_old`: `now - click_time > click_window_days` (default 90, from "We only keep the GCLID for 90 days"); with a hashed identifier the click ID is dropped (`click_id_dropped_old`), because enhanced conversions for leads match on user data (13321563: for "Unknown clicks" with enhanced conversions for leads, "this error can generally be ignored"). `click_too_recent`: "less than 6 hours ago, retry after 6 hours" is a warning, not a rejection. Google also advises: "upload an extra day's worth of data with each import"; the screen says so.

## R11 Bounds on writes

No route here is anonymous. Webhooks: secret or signature auth, 128 KB cap, rate limit scope `offline_webhook`. Lifecycle: admin or CRM token, 128 KB cap, `lifecycle` rate limit for token callers. Store: 50 000 rows, 8 stage rows per lead, atomic unique key. No outbound HTTP, so no SSRF surface.

## Open questions

1. Provider rows: keep `legitimate_interest` as the stored label, or use a neutral `provider_originated`?
2. Map site `marketing` consent to Google's `Ad User Data` / `Ad Personalization` columns, or keep them unset? (EEA uploads may need them.)
3. Gmail rule: dots only (Data Manager Help) or dots plus `+suffix` (API and formatting guide)? Also 7014069 step 7 says hashed first-party data "should exactly match what was entered in the lead form", which conflicts with normalising first.
4. Enforce `lifecycle.crm_ingestion.enabled` in `lifecycle_permissions_check()` (today only the flag and the token gate it)?
5. A restated stage value (same lead, stage, order ID) is ignored as a duplicate; is that acceptable or should a later value supersede?
