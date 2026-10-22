# Feature Specification: HubSpot Lead `user_data` for Google Ads Enhanced Conversions

**Feature Branch**: `claude/compassionate-tesla-817n70`

**Created**: 2026-10-22

**Status**: Draft, blocked. Depends on spec 003 shipping and on its Phase 0 result for R1 below (whether HubSpot field values are readable after a successful submission). No plan.md or tasks.md until that is known.

**Input**: GitHub issue [#127](https://github.com/vizuh/click-trail-handler/issues/127). A HubSpot form in a cross-origin iframe cannot be read by GTM's auto-detect user-provided-data variable, so Google Ads Enhanced Conversions for web gets nothing from these leads. HubSpot's `HubSpotFormsV4` API exposes field values to the parent page, which could close that gap. This is the first feature that would put PII (email, phone) into ClickTrail's browser event path, so it is specified and reviewed on its own.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Opt-in `user_data` on a HubSpot lead (Priority: P1)

A site owner turns on "Enhanced Conversions user data" for HubSpot forms. When a visitor with granted marketing consent submits the form, the `lead` event from spec 003 carries `user_data` with the normalised email and phone (and optionally address), and `transaction_id` equal to the HubSpot conversion ID, so the Google Ads tag can send Enhanced Conversions and dedupe against any other source.

**Why this priority**: It is the only browser-side way to feed Enhanced Conversions from an iframe form, and it is what makes the HubSpot lead valuable for Ads optimisation.

**Independent Test**: With the spec 003 stub API returning email and phone, enable the setting, grant consent, dispatch success, and assert the `dataLayer` `lead` has `user_data.email` lowercased and trimmed, `user_data.phone_number` in E.164, `transaction_id = conversionId`. Disable the setting or deny consent and assert `user_data` is absent.

**Acceptance Scenarios**:

1. **Given** the setting is on and marketing consent is granted, **When** the HubSpot `lead` is built, **Then** `user_data` holds the normalised values read from the form and `transaction_id` equals the conversion ID.
2. **Given** the setting is off, or consent is not granted, **When** the `lead` is built, **Then** no field is read and `user_data` is absent.
3. **Given** the "pre-hash" option is on, **When** the `lead` is built, **Then** values are SHA-256 hashed after normalisation and the raw values never reach `dataLayer`.
4. **Given** a field is missing or empty, **When** the `lead` is built, **Then** the key is omitted; nothing is sent as an empty string.

---

### User Story 2 - PII never leaves the browser through ClickTrail (Priority: P1)

Nothing in `user_data` is written to the `marketing_trail` envelope, posted to `/clicutcl/v2/events/batch`, stored in `wp_clicutcl_events`, or logged.

**Why this priority**: ClickTrail's privacy posture (`SECURITY-PRIVACY.md`) is that it holds campaign context, not identities. This feature must not change that quietly.

**Independent Test**: With transport enabled in the E2E harness, submit and assert the captured REST body and the stored row contain no email or phone, while the `dataLayer` entry does.

**Acceptance Scenarios**:

1. **Given** REST transport is on, **When** the `lead` is sent, **Then** `buildCanonicalEvent()` strips `user_data` PII keys before posting.
2. **Given** debug logging is on, **When** the `lead` is built, **Then** the console log masks `user_data` values.

---

### User Story 3 - GTM guidance that does not double count (Priority: P2)

The GTM starter kit documents a dedicated Ads conversion action with a "Code" user-provided-data variable bound to the data-layer field, `transaction_id` wired to the conversion tag, and a warning not to run this alongside HubSpot's native Google Ads conversion sync on the same conversion action unless dedup is confirmed.

**Acceptance Scenarios**:

1. **Given** the starter kit is imported, **When** a HubSpot `lead` fires, **Then** the Ads conversion tag receives `user_data` from the variable and `transaction_id` from the event.

---

### Edge Cases

- Field values are not readable after `on-submission:success` (R1 undocumented): the feature needs a pre-submit capture point, which HubSpot's global events do not offer for single-step forms. If Phase 0 of spec 003 confirms this, this spec is re-planned or closed as not feasible from the browser.
- The form has no phone field or an unparseable phone: send email only; Google requires at least email or full address.
- Consent granted but the setting off: nothing is read.
- Consent withdrawn after the `lead` was pushed: nothing further is sent; ClickTrail does not retract pushed `dataLayer` entries (consistent with the rest of the runtime).
- Multiple forms or the popup instance: values are read from the instance that fired success, never from another.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: A setting (default off) MUST control the feature; the browser MUST read no form field when it is off.
- **FR-002**: Field reads MUST happen only when marketing consent is granted at the moment of the success event.
- **FR-003**: Values MUST be normalised per Google's documented rules before use: trim and lowercase email (strip dots in the gmail local part), phone in E.164; address fields trimmed and lowercased where Google requires.
- **FR-004**: `transaction_id` MUST be the HubSpot conversion ID; when none exists, the fallback event ID from spec 003 is used.
- **FR-005**: An optional pre-hash mode MUST SHA-256 the normalised values in the browser; when off, raw normalised values are pushed for the Google tag to hash.
- **FR-006**: `buildCanonicalEvent()` and `buildMarketingTrailEnvelope()` MUST exclude `user_data` PII keys; debug logs MUST mask them.
- **FR-007**: Docs: `SECURITY-PRIVACY.md` (PII boundary, consent requirement, what is and is not stored), `INTEGRATIONS.md` (GTM guidance, HubSpot native sync conflict), `integration-capabilities.json`, `SETTINGS-AND-ADMIN.md`, readmes with the claim boundary ("browser-side only; Google hashes; nothing stored by ClickTrail").

## Success Criteria *(mandatory)*

- **SC-001**: Stub E2E: 100% of success events with the setting on and consent granted carry normalised `user_data` and `transaction_id`; 0 carry it with the setting off or consent not granted.
- **SC-002**: Stub E2E with transport on: 0 PII keys in the captured REST body and the stored row.
- **SC-003**: Live: Google Ads tag diagnostics on the tenant's staging show Enhanced Conversions data received for a test lead, and the HubSpot contact's conversion ID matches `transaction_id`.

## Assumptions

- Google Ads Enhanced Conversions for web accepts unhashed values for the tag to normalise and hash, or SHA-256 pre-hashed values; `transaction_id` dedupes per conversion action (verified 2026-10-22, see research.md).
- The client's privacy policy and Google Ads customer-data terms cover Enhanced Conversions. ClickTrail cannot verify this.
- Offline conversion upload (Enhanced Conversions for leads, Data Manager API) is out of scope; HubSpot's native Google Ads conversion sync is a client-side alternative documented in research.md, not a ClickTrail feature.
