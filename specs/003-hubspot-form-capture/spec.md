# Feature Specification: HubSpot Embedded Form Capture

**Feature Branch**: `claude/compassionate-tesla-817n70`

**Created**: 2026-10-22

**Status**: Draft. Implementation is blocked on the Phase 0 live test (tasks.md T001). Nothing in this spec is runtime-verified.

**Input**: GitHub issue [#126](https://github.com/vizuh/click-trail-handler/issues/126). A HubSpot form embedded on a WordPress page (updated editor, rendered in a cross-origin `js-na1.hsforms.net` iframe; or the legacy v2 `hbspt.forms.create` embed) produces no ClickTrail `lead` and receives no attribution. The first case is a tenant site whose leadin shortcode falls back from the v2 loader to the v4 iframe embed, so quote-form leads are invisible to ClickTrail, GTM form triggers, GA4 and Google Ads.

Related: [#127](https://github.com/vizuh/click-trail-handler/issues/127) (Enhanced Conversions `user_data`, separate spec 004) and [#128](https://github.com/vizuh/click-trail-handler/issues/128) (webhook adapter hardening). Both are out of scope here.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A HubSpot form submit becomes one `lead` (Priority: P1)

A visitor lands from Google Ads (`?gclid=`), browses to a second page, and submits the HubSpot form embedded there. ClickTrail pushes exactly one `lead` event with `lead_context.provider = hubspot` and the stored first/last-touch attribution, through the same consent gate as every other browser event.

**Why this priority**: Without it the conversion does not exist for ClickTrail, `dataLayer` consumers, or REST transport. This is the whole reason for the feature.

**Independent Test**: Load a page that stubs `window.HubSpotFormsV4` and dispatches `hs-form-event:on-submission:success` on `window` with `detail = {formId, instanceId}`. Assert one `dataLayer` entry with `event: 'lead'`, `form_provider: 'hubspot'`, `form_id = formId`, and `event_id = 'hsform_' + conversionId`. Dispatch the same event again and assert no second entry.

**Acceptance Scenarios**:

1. **Given** consent allows events and the HubSpot v4 API is present, **When** `hs-form-event:on-submission:success` fires, **Then** one `lead` is pushed with `lead_context.provider = hubspot`, `submit_status = success`, `form_id = detail.formId`, and `event_id = hsform_<conversionId>`.
2. **Given** `getConversionId()` returns an empty value, **When** the success event fires, **Then** the `event_id` falls back to `hsform_<formId>|<instanceId>|<timestamp bucket>` and the event is still pushed once.
3. **Given** the same `instanceId` and `conversionId` are seen twice (a re-dispatched event, a re-render), **When** the second event fires, **Then** nothing is pushed.
4. **Given** `hs-form-event:on-submission:failed` fires, **When** it is handled, **Then** `form_submit_failed` is pushed with the same `lead_context` and no `lead` is pushed.
5. **Given** consent is pending, denied or withdrawn, **When** the success event fires, **Then** nothing is pushed (the existing `pushEvent()` gate).

---

### User Story 2 - Attribution reaches the HubSpot contact (Priority: P1)

The site owner creates hidden contact properties in HubSpot and adds them to the form as hidden fields. When the form is ready, ClickTrail writes the stored first/last touch, click IDs, landing pages and `trail_id` into those fields with HubSpot's `setFieldValue()` API, so the contact record carries attribution even though the fields live in a cross-origin iframe.

**Why this priority**: The hidden-field injector cannot reach inside the iframe, and HubSpot's own analytics do not attribute these contacts when no HubSpot tracking code runs. Without this the CRM record has no `gclid` or source.

**Independent Test**: With the stub API, dispatch `hs-form-event:on-ready` and assert `setFieldValue` was called once per mapped field with `['<value>']`, and not at all for keys that are empty in the stored attribution. Set `injectOverwrite` off and a pre-filled value, and assert the field is left alone. Withdraw consent and assert each written field is cleared.

**Acceptance Scenarios**:

1. **Given** consent allows injection and `hs-form-event:on-ready` fires, **When** the handler runs, **Then** every key in the field map with a non-empty stored value is written with `form.setFieldValue(name, [value])`.
2. **Given** a form became ready before ClickTrail attached its listener, **When** ClickTrail initialises, **Then** it scans `HubSpotFormsV4.getForms()` and writes the same fields on each instance.
3. **Given** `injectOverwrite` is off and `getFieldValue(name)` resolves to a non-empty value ClickTrail did not write, **When** the handler runs, **Then** that field is kept.
4. **Given** consent is withdrawn on the page after fields were written, **When** the withdrawal is handled, **Then** every field ClickTrail wrote is set to `['']` and no field is refilled until consent is granted again.
5. **Given** `window.HubSpotFormsV4` is absent, **When** the page loads, **Then** no HubSpot-specific code runs and no listener for `hs-form-event:*` is registered.

---

### User Story 3 - Legacy HubSpot embeds produce a `lead` too (Priority: P2)

A site still on the legacy v2 embed (`hbspt.forms.create`) gets the same `lead` from the `hsFormCallback` message HubSpot posts to the parent window after the submission is persisted.

**Why this priority**: Many portals still render legacy forms, and the current message normaliser can never match them because it only accepts `type` values HubSpot does not send.

**Independent Test**: Post `{type: 'hsFormCallback', eventName: 'onFormSubmitted', id: '<formId>', data: {...}}` from the page's own origin. Assert one `lead` with `provider = hubspot` and `form_id = id`. Post `eventName: 'onFormReady'` and `onBeforeFormSubmit` and assert nothing is pushed.

**Acceptance Scenarios**:

1. **Given** a same-origin message with `type === 'hsFormCallback'` and `eventName === 'onFormSubmitted'`, **When** it is normalised, **Then** it maps to `lead`, `provider = hubspot`, `form_id = data.id`.
2. **Given** any other `eventName`, **When** it is normalised, **Then** it is ignored.
3. **Given** the legacy message is repeated with the same `id` and the same `submissionValues` hash, **When** it is normalised, **Then** the duplicate is dropped by the existing `externalMarkers` set.

---

### Edge Cases

- Two instances of the same form on one page (inline plus popup): each `instanceId` is handled on its own; a submit on one must not count for the other.
- The v4 script loads after ClickTrail (the leadin v2-to-v4 fallback), or before it (a direct v4 embed in `<head>`): both orders must work through the listener plus `getForms()` rescan. No inline bootstrap is required unless Phase 0 shows forms being missed.
- Multi-step forms: `on-interaction:navigate` events are ignored; only `on-submission:success` counts.
- `getFieldValue()` and `getFormFieldValues()` are async and may reject; every call is wrapped and bounded by a timeout so a HubSpot change can never block ClickTrail.
- A HubSpot field name in the map does not exist on the form: `setFieldValue` may throw or no-op; the handler continues with the next field and logs in debug mode only.
- HubSpot's query-string prefill may already have filled a hidden field from the landing URL: `injectOverwrite` decides, as for DOM inputs.
- Consent is required and not yet resolved when the form becomes ready: fields are written when consent resolves granted (`ct:consentResolved`), not before.
- REST transport is off (as on the tenant site): the `lead` reaches `dataLayer` only. Nothing is stored server-side. This is configuration, not a defect, and is stated in the docs.
- A site also subscribes HubSpot webhooks to ClickTrail: the browser `lead` and the webhook `lead` are separate events and never dedupe against each other (`Dedup_Store` is keyed per destination). The docs tell site owners to pick one source per event.
- Browser code never pushes `qualified_lead`; that event stays restricted to trusted routes.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The events runtime MUST register `window` listeners for `hs-form-event:on-ready`, `hs-form-event:on-submission:success` and `hs-form-event:on-submission:failed` only when `window.HubSpotFormsV4` is present at init or appears later (checked on `on-ready`, which HubSpot dispatches on `window`).
- **FR-002**: On `on-submission:success`, the runtime MUST push one `lead` with `form_provider: 'hubspot'`, `form_id`, and `lead_context: {provider: 'hubspot', form_id, instance_id, submit_status: 'success'}` through `pushEvent()`, so the consent gate, identity, `marketing_trail` and REST transport apply unchanged.
- **FR-003**: The `event_id` MUST be `hsform_<conversionId>` when `getConversionId()` returns a non-empty string, otherwise `hsform_<formId>|<instanceId>|<minute bucket>`; the runtime MUST drop a second event for the same `instanceId` plus `conversionId`.
- **FR-004**: On `on-submission:failed`, the runtime MUST push `form_submit_failed` and MUST NOT push `lead`.
- **FR-005**: On `on-ready`, and once at init for every instance returned by `getForms()`, the runtime MUST write each mapped attribution key with a non-empty stored value using `setFieldValue(name, [value])`, subject to live consent and `injectOverwrite`, and MUST record which fields it wrote.
- **FR-006**: On consent withdrawal the runtime MUST clear every field it wrote (`setFieldValue(name, [''])`) and MUST NOT write again until consent is granted.
- **FR-007**: The field map MUST default to `0-1/<ct_name>` for the injector's existing `ct_*` aliases (`ct_ft_source`, `ct_lt_gclid`, `ct_trail_id`, ...) and MUST be filterable server-side (`clicutcl_hubspot_field_map`) and exposed to the browser through `clicutclEventsConfig`.
- **FR-008**: `normalizeExternalMessage()` MUST accept `type === 'hsFormCallback'` with `eventName === 'onFormSubmitted'` from an allowed origin and map it to `lead` with `provider = hubspot`; other `eventName` values MUST be ignored.
- **FR-009**: No HubSpot handler may throw into HubSpot's event dispatch or block form submission; every API call is wrapped and bounded by a timeout.
- **FR-010**: No field value other than the mapped attribution keys may be read, stored or pushed by this feature. Reading `email`, `phone` or other PII is out of scope (see spec 004).
- **FR-011**: Docs per AGENTS.md: `INTEGRATIONS.md` (new "HubSpot embedded forms" section and the dedup note), `integration-capabilities.json` (new `source.browser.hubspot_forms` entry, runtime-unverified), `HOOKS-REFERENCE.md` (the new filter), `EVENT-PIPELINE.md` (browser intake bullet), `SECURITY-PRIVACY.md` (consent gating and withdrawal), `SETTINGS-AND-ADMIN.md` if a toggle is added. The HubSpot rows stay runtime-unverified until SC-004 passes.

### Key Entities

- **Form instance**: HubSpot's `{formId, instanceId}` pair plus the `HubSpotFormsV4` form object. ClickTrail keeps a `Map` of `instanceId` to the list of fields it wrote and the last `conversionId` seen.
- **Field map**: ordered list of `[clicktrail_key, hubspot_field_name]` pairs, default derived from the injector's `ct_*` aliases with the `0-1/` prefix.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In the stub-API E2E page, 100% of dispatched success events produce exactly one `lead`; a repeated dispatch produces zero additional events.
- **SC-002**: In the same page, every mapped key with a stored value is written once per instance, and zero writes happen while consent is pending or denied.
- **SC-003**: Node unit tests for the legacy normaliser pass for `onFormSubmitted`, and ignore `onFormReady`, `onBeforeFormSubmit` and `onFormSubmit`.
- **SC-004**: Live acceptance on the tenant's staging (or a disposable HubSpot portal): a `?gclid=TEST` landing, a second page, one submit; the HubSpot contact shows the hidden attribution fields filled, `dataLayer` shows one `lead` with `event_id = hsform_<conversionId>`, and the popup and inline instances do not double count. Until this passes, the INTEGRATIONS row stays runtime-unverified.

## Assumptions

- HubSpot dispatches the three global events on `window` with `event.detail = {formId, instanceId}` (verified in HubSpot docs, 2026-10-22; see research.md R1).
- `setFieldValue()` accepts hidden-field values as `string[]` (verified, R2).
- The `0-1/` prefix in HubSpot's examples is the contact object type and is stable. HubSpot does not document it; Phase 0 confirms it (R3).
- Hidden fields are available on every HubSpot plan, and required fields cannot be hidden (verified, R4). The site owner creates the hidden properties and fields; ClickTrail cannot do this.
- The site owner enables ClickTrail REST transport if server-side storage of the `lead` is wanted.
- Enhanced Conversions, HubSpot webhook changes and HubSpot's own tracking code are out of scope.
