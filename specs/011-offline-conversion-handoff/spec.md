# Feature Specification: Offline Conversion Handoff (lead export, CRM stage updates, provider lead webhooks)

**Feature Branch**: `feat/offline-conversion-handoff`

**Created**: 2026-11-04

**Status**: Draft, not started. Every claim about the current code is source-present / runtime-unverified: it was read on `main` @ `e00552c` (1.12.0 plus unreleased 1.13 work). Nothing below was run against Google Ads, CallRail or a CRM. Vendor behaviour is quoted with URLs in [research.md](research.md).

**Input**: A field pattern seen on production sites: the CRM-to-ad-platform offline loop is the most common unfinished tracking work. Leads reach the CRM or ESP without a click ID, so deal and qualified stages cannot be uploaded; uploads that do happen are rejected for a missing or old click ID, a wrong conversion action name, unhashed or badly normalised email or phone, or a conversion time with no timezone; and leads from a Google lead form asset or a tracked phone call never reach the CRM with their `gclid`. A WordPress site running ClickTrail already captures `gclid`/`gbraid`/`wbraid`, the touch time and a lead ID at form submit (`Abstract_Form_Adapter::log_submission()`), but nothing turns that into an upload-ready file, the lifecycle route ignores value and time, and the webhook adapters cover only Calendly, HubSpot and Typeform. Related: [../001-calendly-booking-handoff/research.md](../001-calendly-booking-handoff/research.md) (the `ct1` click ID stamp on a webhook source).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Download a Google Ads upload file (Priority: P1)

An administrator opens ClickTrail, Lead export, picks a date range and a form, chooses "Google Ads file" and downloads a CSV that Google Ads Data Manager accepts without hand editing: click ID columns, conversion name, an unambiguous conversion time, value, currency, order ID and SHA-256 normalised email and phone.

**Why this priority**: it is the smallest change that closes the loop for a site whose CRM cannot be wired to an API, and it needs no Google credentials.

**Independent Test**: PHPUnit `tests/unit/OfflineExportTest.php` against `Offline_Export` with fixture rows (no WordPress, no DB); E2E `tools/qa/e2e/offline-export.sh` submits a CF7 form carrying `?gclid=GA`, downloads the file with a logged-in admin cookie and asserts the row.

**Acceptance Scenarios**:

1. **Given** the feature is on, a consented `form_submission` row with `gclid = Cj0KCQ_test-AbC`, `occurred_at = 2026-10-20T08:20:11Z` and a stage name mapped to `Lead`, **When** the Google file is downloaded, **Then** line 1 is exactly `Google Click ID,GBRAID,WBRAID,Conversion Name,Conversion Time,Conversion Value,Conversion Currency,Order ID,Email,Phone`, the row has the click ID byte for byte (case kept), `Conversion Time = 2026-10-20T08:20:11+00:00` (ISO 8601 with offset), `Order ID = lead_id`, and Email and Phone are lowercase 64-character SHA-256 hex or empty.
2. **Given** a row carrying `gclid`, `wbraid` and `gbraid`, **When** `google_click_id_columns = one` (default), **Then** only one column is filled, chosen by the precedence Google documents (`gclid`, then `wbraid`, then `gbraid`); with `all` every captured Google ID is filled.
3. **Given** the "legacy template" option, **Then** the file starts with `Parameters:TimeZone=+0000`, the time reads `2026-10-20 08:20:11+0000`, and the Email and Phone columns are absent.
4. **Given** a free-text cell (conversion name, landing page, UTM, form title) starting with `=`, `+`, `-`, `@`, TAB or CR, **Then** the cell is written with a leading `'`; **given** a click ID that matches `^[A-Za-z0-9._-]{1,200}$` (it may start with `-`), **Then** it is written untouched, because a prefix would corrupt the identifier.
5. **Given** a stage with a value and no currency and no `offline.default_currency`, **Then** the row is rejected `value_without_currency`, never exported with a guessed currency.

### User Story 2 - A generic CRM CSV and a per-row preflight (Priority: P1)

The same export screen offers a "CRM file" with every field a CRM import needs, and shows, before download, how many rows are ready and why the others are not.

**Independent Test**: PHPUnit on `Offline_Preflight` with a clock injected; the screen is covered by the E2E download test.

**Acceptance Scenarios**:

1. **Given** the CRM file, **Then** columns are `lead_id,event_id,stage,provider,form,gclid,gbraid,wbraid,selected_click_id_key,selected_click_id,click_time_utc,conversion_time_utc,value,currency,ft_source,ft_medium,ft_campaign,lt_source,lt_medium,lt_campaign,landing_page,consent,email_sha256,phone_sha256,preflight,preflight_reason`, rejected rows included.
2. **Given** a row, **Then** `preflight` is `ready`, `warn` (exportable, see reason) or `rejected`, with reasons from this closed set: `no_click_id`, `invalid_click_id`, `click_too_old` (older than `click_window_days`, default 90, with no hashed identifier to fall back on), `conversion_before_click`, `consent_not_granted`, `consent_not_recorded`, `conversion_name_missing`, `value_without_currency`, `provider_lead_excluded`; warnings `click_too_recent` (under 6 hours old), `click_time_unknown`, `click_id_dropped_old` (click ID omitted, hashed identifier kept), `time_assumed_utc`.
3. **Given** the Google file, **Then** only `ready` and `warn` rows are written; the on-screen summary and the CRM file carry the rejected ones.

### User Story 3 - Privacy and access are enforced (Priority: P1)

**Independent Test**: PHPUnit on the export handler with stubbed `current_user_can` and `wp_verify_nonce`.

**Acceptance Scenarios**:

1. **Given** a user without the capability (`manage_options`, filter `clicutcl_offline_export_capability`) or a bad nonce, **Then** the download answers 403 and no query runs.
2. **Given** any export, **Then** no raw email, raw phone, name or IP is written; only SHA-256 hashes. ClickTrail never stores a raw email or phone for this feature.
3. **Given** a row whose consent is `granted`, **Then** it is eligible; `not_required` rows are eligible only when the "site had no consent requirement" box is checked (default checked only while `Consent::is_required()` is false); rows logged before this feature (`legacy`, consent not recorded) only when "include legacy rows" is checked.
4. **Given** any download, **Then** one `clicutcl_events` row `offline_export` records user ID, format, range, filters and counts, no personal data, visible on the Logs page.

### User Story 4 - A CRM step records Qualified / Won against the stored lead (Priority: P1)

**Independent Test**: PHPUnit `tests/unit/OfflineStageTest.php` calling `lifecycle_update()` with a stubbed store; E2E posts a stage after the CF7 submit and finds the second row in the file.

**Acceptance Scenarios**:

1. **Given** the feature on and a known `lead_id`, **When** `POST /clicutcl/v2/lifecycle/update` carries `stage = client_won`, `value = 4200.5`, `currency = EUR`, `occurred_at = 2026-11-02T14:00:00+01:00` (and optionally `order_id`), **Then** a stage row is stored with a snapshot of the lead's click ID, click time and hashed identifiers, the response adds `recorded: true`, and the export shows it as a second conversion row (`Order ID = lead_id` unless `order_id` is given).
2. **Given** the same lead, stage and order ID again, **Then** nothing is written and the response carries `duplicate: true`.
3. **Given** a `lead_id` that is unknown or already purged, **Then** the response is HTTP 200 with `recorded: false, reason: lead_not_found` and the existing dispatch is unchanged.
4. **Given** `occurred_at` without a timezone offset, a future time, a non-numeric `value` or a currency that is not three letters, **Then** 400 with `invalid_occurred_at`, `invalid_value` or `invalid_currency`; **given** none of the new fields, **Then** the route behaves exactly as today.
5. **Given** a provider lead, **Then** `provider` + `provider_ref` (the Google lead ID or the CallRail call ID) may replace `lead_id`.

### User Story 5 - Google lead form asset leads are stored with their click ID (Priority: P2)

**Independent Test**: PHPUnit `tests/unit/GoogleAdsLeadWebhookTest.php` with a fixture shaped like the documented `WebhookLead`.

**Acceptance Scenarios**:

1. **Given** provider `google_ads_lead` enabled with a key, **When** Google POSTs `{lead_id, gcl_id, google_key, user_column_data:[{column_id:"EMAIL",string_value:...}], lead_submit_time, ...}` with the right `google_key`, **Then** a `lead` row is stored (`provider = google_ads_lead`, `provider_ref = lead_id`, `click_id_key = gclid`, hashed email and phone, `consent_state = unknown`, `consent_basis = legitimate_interest`) and the answer is HTTP 200 with body `{}`.
2. **Given** a wrong or missing `google_key`, **Then** 401 and nothing is stored; **given** `is_test: true`, **Then** 200 and nothing is stored.
3. **Given** the same `lead_id` twice (concurrent or later), **Then** one row; the repeat answers 200.
4. **Given** the default settings, **Then** such rows are excluded from every export (`provider_lead_excluded`) unless `offline.include_provider_leads` is on, and no destination receives a dispatch.

### User Story 6 - CallRail post-call webhook leads are stored with their click ID (Priority: P2)

**Independent Test**: PHPUnit `tests/unit/CallRailWebhookTest.php`; the signature test uses the vendor vector in [research.md](research.md) R6.

**Acceptance Scenarios**:

1. **Given** provider `callrail` enabled with the company signing key, **When** a post-call payload arrives with a valid `Signature` header (base64 HMAC-SHA1 of the raw body), **Then** a `lead` row is stored (`provider_ref = id`, `click_id = gclid` when present, `occurred_at = start_time`, `phone_sha256` of `customer_phone_number` when it is E.164, UTM source/medium/campaign, landing page without its query string).
2. **Given** a bad signature or a `timestamp` outside the tolerance, **Then** 401; **given** an outbound call, a repeat of a stored call ID, or a payload without `id`, **Then** 200 with `ignored: true` (CallRail does not resend and may disable an integration after repeated non-2xx).
3. **Given** a call without `gclid`, **Then** the row is stored (stage updates may still match on hashed phone) and exports as `no_click_id` unless a hashed identifier exists.

### User Story 7 - Existing installs are unchanged (Priority: P1)

**Acceptance Scenarios**:

1. **Given** an upgrade, **Then** `offline.enabled = 0`, no row gets an `offline` block, no table is written, the Lead export page and the two providers are absent from the UI, and `lifecycle/update` answers byte for byte as today.
2. **Given** a fresh install, **Then** the setting is also off: it stores new personal-data hashes and a new table, which is not a safe default.

### Edge Cases

- `created_at` in `clicutcl_events` is `DEFAULT CURRENT_TIMESTAMP` (DB-local, `class-installer.php:152`) while cleanup compares against `UTC_TIMESTAMP()` (`class-cleanup.php:57`). New rows carry an explicit UTC `offline.occurred_at`; older rows fall back to `created_at` read as UTC with the warning `time_assumed_utc`.
- Click time is the browser's `lt_touch_timestamp` (`toISOString()`, `clicutcl-attribution.js:2392-2400`) when `lt_<key>` equals the selected value, else `ft_touch_timestamp`; it is the page-load time of the touch, so it trails the real click by the redirect and load time [INFERENCE] and cannot precede it.
- `click_id_history` is deliberately not in the cookie (`clicutcl-attribution.js:925-927`), so the server cannot read per-ID capture times; only the two touch timestamps exist.
- Hashes of Gmail addresses use Google's rules (dots and `+suffix` removed), which differ from `Identity_Resolver` (trim, lowercase only, `class-identity-resolver.php:65-67`); rows without the `offline` block export no Email column value.
- Attribution retention is `cookie_days` (default 90): a stage arriving after the lead row was purged is `lead_not_found`; a CRM with a longer sales cycle must keep the IDs itself (US4 contract in the plan).
- The same lead exported twice is harmless: Google deduplicates on conversion action, time and order ID.
- Provider rows (leads and their stages) are excluded from every export by default: Google and CallRail record their own conversions, so exporting the `lead` row can double count [INFERENCE, research R8].

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Settings MUST live in `clicutcl_tracking_v2['offline']` (`Tracking_Settings::defaults()`): `enabled` (0), `conversion_names` (stage to text, ≤100 chars, default empty), `click_window_days` (90, clamp 7 to 90), `google_click_id_columns` (`one`|`all`, `one`), `include_provider_leads` (0), `default_currency` (`''`, ISO 4217 or empty). Nothing is seeded on install.
- **FR-002**: With `enabled`, `write_and_dispatch_submission()` MUST add `event_data['offline'] = {occurred_at (UTC ISO 8601), consent (granted|not_required), email_sha256_ads, selected_click_id_key}`; `email_sha256_ads` only when `Identity_Resolver` returned `hashed_email` (identity policy and consent unchanged). No raw email or phone is stored.
- **FR-003**: `Offline_Identifier_Normalizer` MUST implement Google's email rule (trim, lowercase, `gmail.com`/`googlemail.com` remove dots and the first `+` suffix), E.164 validation `^\+[1-9][0-9]{6,14}$`, SHA-256 hex, and MUST NOT change `Identity_Resolver`.
- **FR-004**: A submenu page "Lead export" and `admin_post_clicutcl_export_leads` MUST require the capability, `check_admin_referer`, POST only, validate dates (UTC, ≤ 366 days) and form filter, stream in batches of 500 by `id`, cap at `clicutcl_offline_export_max_rows` (10 000), and write the audit row.
- **FR-005**: `Offline_Csv` MUST escape per RFC 4180 and apply the formula prefix of US1-4; the Google file MUST follow US1 and US2-3; times MUST always carry an offset.
- **FR-006**: `Offline_Preflight` MUST be pure (row, settings, `now`) and return one state and one reason from the closed set of US2-2.
- **FR-007**: `lifecycle_update()` MUST accept `value`, `currency`, `occurred_at`, `order_id`, `provider`, `provider_ref` additively (US4), persist a stage row only when `enabled`, keep its auth (`manage_options` or the CRM token), its 128 KB cap and its dispatch unchanged, and apply `check_rate_limit( 'lifecycle' )` to token callers.
- **FR-008**: Table `{prefix}clicutcl_conversions` (`DB_VERSION` 6) MUST hold stage and provider rows, unique `(blog_id, row_key)`, `expires_at = created_at + cookie_days`, ceilings of 50 000 rows (filter) and 8 stage rows per lead, daily purge, privacy exporter and eraser, uninstall drop.
- **FR-009**: `google_ads_lead` and `callrail` MUST be added as webhook providers (disabled, empty secret) with `Webhook_Auth` branches: body `google_key` compared with `hash_equals`; `Signature` header base64 HMAC-SHA1 plus `timestamp` tolerance. Both dedupe through `Claim_Lock` and the unique row key, return 2xx for ignorable payloads, and MUST NOT call `Dispatcher::dispatch_from_v2()`.
- **FR-010**: Provider rows MUST store `consent_state = unknown`, `consent_basis = legitimate_interest`, hashes only, no name and no raw email or phone, and MUST be excluded from exports unless `include_provider_leads`.
- **FR-011**: Docs MUST be updated: `INTEGRATIONS.md` (providers, lead record contract), `integration-capabilities.json` (two `source.webhook.*` entries), `HOOKS-REFERENCE.md` (`clicutcl_offline_export_capability`, `clicutcl_offline_export_max_rows`, `clicutcl_provider_lead_logged`, `clicutcl_callrail_replay_window`), `REST-API.md`, `SECURITY-PRIVACY.md` (table, hashes, retention, export), `DATA-MODEL.md`, `SETTINGS-AND-ADMIN.md`, `OPERATIONS-RUNBOOK.md` (DB version), `readme.txt`, `README.en.md`, `changelog.txt`.

### Key Entities

- **Offline row**: one upload-ready conversion: lead or stage, click ID and time, hashed identifiers, value, currency, order ID, UTC time, consent state, preflight result.
- **Conversion record**: `clicutcl_conversions` row (kind `lead` for provider leads, `stage` for CRM updates), self-contained snapshot.
- **Lead export audit**: `clicutcl_events` row, `event_type = offline_export`.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: PHPUnit: the Google file header and a fixture row match byte for byte (US1-1); `one`/`all` columns (US1-2); legacy template (US1-3); 6 formula-prefix cases (`=`, `+`, `-`, `@`, TAB, CR) plus the `-`-leading click ID (US1-4).
- **SC-002**: PHPUnit hashing vectors: `John.Smith@EXAMPLE.COM` gives `8e621e3d0368631d263d07a351fa8d34fba0d17c15fbcdec11a5f58008d022a0`, `+1 (800) 555-0199` gives `78724165620b8a76386cab242569ebc85dd2346f3f5ae88dc13bb57e31020b90`, `Jane.Doe+Shopping@googlemail.com` equals the hash of `janedoe@googlemail.com`, `user.name+NYC@Example.com` keeps dots and plus.
- **SC-003**: PHPUnit: every preflight reason of US2-2 from a fixture with an injected clock, including 90 days and 6 hours as exact boundaries.
- **SC-004**: PHPUnit: 403 for a missing capability and for a bad nonce; the audit row written once; no raw identifier in any output.
- **SC-005**: PHPUnit: stage accept, duplicate, unknown lead, invalid time/value/currency, unchanged legacy call, row and per-lead ceilings.
- **SC-006**: PHPUnit: Google key accept/reject/test lead/duplicate; CallRail vector `UZAHbUdfm3GqL7qzilGozGzWV64=`, stale timestamp, outbound, repeat; neither calls the dispatcher.
- **SC-007**: E2E (`flock /tmp/ct-e2e.lock tools/qa/e2e/offline-export.sh`): CF7 submit with `?gclid=GA`, stage post, download both files, assert both rows. Until it passes the feature stays source-present / runtime-unverified.
- **SC-008**: One real upload of the Google file to a test Google Ads account is accepted by Data Manager with no format error. Not run; wording stays unverified until then.

## Assumptions

- The marketing-consent flag in `Consent::get_state()` (`marketing`, `analytics` only, `class-consent.php:68-71`) is not Google's `ad_user_data` or `ad_personalization`; the file leaves those columns out (Google: default unset). Whether to map `marketing` to them is open.
- Out of scope (U4): direct Google Ads upload. Triggers to revisit are in [plan.md](plan.md).
