# Feature Specification: HubSpot Embedded Form Capture

**Feature Branch**: `feat/hubspot-form-capture`

**Created**: 2026-10-23

**Status**: US1 and US3 implemented on `feat/hubspot-form-capture` (Node VM contract test only). US2 is blocked on Phase 0 live proof (see tasks.md). A live HubSpot submission (SC-004) is still pending.

**Input**: GitHub issue [#126](https://github.com/vizuh/click-trail-handler/issues/126). A HubSpot form embedded on a WordPress page (updated editor, rendered in a cross-origin `hsforms.net` iframe, or the legacy v2 `hbspt.forms.create` embed) produces no ClickTrail event and receives no attribution.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - An updated-editor (v4) HubSpot submission is one lead (Priority: P1)

A visitor with marketing consent submits a HubSpot form built in the updated forms editor. HubSpot dispatches `hs-form-event:on-submission:success` on `window`. ClickTrail pushes exactly one `lead` through its existing consent gate, so GTM, GA4 and Google Ads see the conversion.

**Why this priority**: Today these leads are invisible. The capture-phase `submit` listener cannot see a `<form>` inside a cross-origin iframe, and `normalizeExternalMessage()` only accepts message types HubSpot never sends.

**Independent Test**: Run the real `assets/js/clicutcl-events.js` in a Node VM with a stubbed consent bridge and `HubSpotFormsV4`, dispatch the documented events on `window`, and assert the `dataLayer` (`tools/qa/hubspot-form-capture.test.js`).

**Acceptance Scenarios**:

1. **Given** consent is granted, **When** `hs-form-event:on-submission:success` fires with `detail = { formId, instanceId }`, **Then** exactly one `lead` is pushed with `form_id = formId`, `form_provider = hubspot`, `lead_context.provider = hubspot`, `lead_context.submit_status = success`.
2. **Given** `HubSpotFormsV4.getFormFromEvent(event).getConversionId()` returns a value, **Then** `event_id = hsform_<conversionId>`. Otherwise `event_id = hsform_<formId>|<instanceId>|<page nonce>`.
3. **Given** the same success event is dispatched twice, or a legacy `onFormSubmitted` message for the same form also arrives, **Then** only one `lead` is pushed.
4. **Given** `hs-form-event:on-submission:failed` fires, **Then** `form_submit_failed` is pushed and no `lead`.
5. **Given** consent is denied, pending or withdrawn, **Then** nothing is pushed.

---

### User Story 2 - Attribution reaches the HubSpot contact (Priority: P1, blocked)

On `hs-form-event:on-ready` (plus a `HubSpotFormsV4.getForms()` rescan for forms that were ready first), ClickTrail writes first/last touch and click IDs into matching hidden HubSpot fields with `form.setFieldValue('0-1/<name>', [value])`, respecting `injectOverwrite` and live consent, and clears them on withdrawal (same semantics as `Injector.clear()`). The field map would be configurable through a new `clicutcl_hubspot_field_map` filter.

**Why blocked**: HubSpot documents `setFieldValue()` and the hidden-field `string[]` type, but not whether a value set this way lands on the contact record, what the `0-1/` prefix means or whether it is stable, or whether `getForms()` returns instances that are not ready yet. Shipping a write path on those guesses could write nothing, or write into the wrong property. Phase 0 must prove them on a real form first. Not implemented; the filter does not exist yet.

---

### User Story 3 - A legacy HubSpot form submission is one lead (Priority: P2)

A legacy form posts `{ type: 'hsFormCallback', eventName: 'onFormSubmitted', id: <formId>, data: {...} }` to the page. ClickTrail pushes the same `lead`.

**Independent Test**: Same harness, dispatching `message` events on `window`.

**Acceptance Scenarios**:

1. **Given** an `onFormSubmitted` callback from an allowed origin, **Then** one `lead` is pushed with `form_id` from the message `id` and `lead_context.provider = hubspot`.
2. **Given** any other `hsFormCallback` event name (`onBeforeFormInit`, `onFormReady`, `onBeforeFormSubmit`, `onFormSubmit`), **Then** nothing is pushed.
3. **Given** the message origin is neither the page host nor in `clicutcl_iframe_origin_allowlist`, **Then** nothing is pushed.
4. **Given** the message carries `data.submissionValues`, **Then** no field value appears in the pushed event.

---

### Edge Cases

- Consent pending when the visitor submits: the listeners exist (registered at script load) but the tracker has not started, so nothing is pushed. A later grant does not replay the submission.
- `HubSpotFormsV4` missing, or `getConversionId()` throws or returns nothing: the fallback `event_id` is used.
- Instance IDs may be the same for every visitor (undocumented). A bare `hsform_<formId>|<instanceId>` would collide in the server dedup store (`event_name` + `event_id`, 7-day TTL) and drop other visitors' leads, so the fallback adds a random per-page nonce.
- Two instances of the same form on one page (inline plus popup) submitted within 10 seconds count as one lead. Distinct `instanceId`s are not documented.
- Without a conversion ID, the same form submitted twice on one page load counts once.
- The browser never pushes `qualified_lead`; that stage stays on the HubSpot webhook and lifecycle routes.
- A HubSpot webhook `lead` for the same submission is a separate event (different source and dedup key); see the HubSpot mapping section of `INTEGRATIONS.md`.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Listeners for `hs-form-event:on-submission:success` and `hs-form-event:on-submission:failed` MUST be registered on `window` when `clicutcl-events.js` loads, independent of consent resolution, and MUST do nothing until the tracker exists.
- **FR-002**: A success event MUST push one `lead` through `pushEvent()` with `form_id`, `form_provider` and `lead_context` as in US1, and `event_id` as in US1-2.
- **FR-003**: A failed event MUST push `form_submit_failed` and MUST NOT push `lead`. `form_submit_failed` MUST be accepted by the browser events route.
- **FR-004**: A legacy `hsFormCallback` message with `eventName === 'onFormSubmitted'` MUST push one `lead` and MUST pass the existing external-message origin rule. Other event names MUST be ignored.
- **FR-005**: One HubSpot submission MUST produce at most one `lead`, across repeated dispatches and across the v4 and legacy paths.
- **FR-006**: The capture MUST read only identifiers (`formId`, `instanceId`, conversion ID, legacy `id`). It MUST NOT call `getFieldValue()` / `getFormFieldValues()` or read the legacy message's `data`.
- **FR-007**: The capture MUST NOT write browser storage.
- **FR-008**: `INTEGRATIONS.md`, `integration-capabilities.json`, `EVENT-PIPELINE.md` and `SECURITY-PRIVACY.md` MUST describe the behaviour. The HubSpot embedded forms row stays source-present / runtime-unverified until SC-004 passes.

### Key Entities

- **HubSpot form event**: `CustomEvent` on `window` with `detail = { formId, instanceId }`.
- **Legacy form callback**: `postMessage` payload `{ type: 'hsFormCallback', eventName, id, data }`.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In the VM test, each success dispatch yields exactly one `lead` with the expected `event_id`, including duplicate and cross-path dispatches.
- **SC-002**: 0 `lead` events for failed submissions and for non-`onFormSubmitted` legacy callbacks.
- **SC-003**: 0 events pushed while consent is denied or withdrawn, and 0 submitted field values in any pushed event.
- **SC-004**: A live submission of a real updated-editor form and a real legacy form on a disposable site shows exactly one `lead` each with the conversion ID (v4). Until that passes, the integration keeps its runtime-unverified label.

## Assumptions

- HubSpot dispatches the global form events on the parent page's `window`, as documented, even when the form renders in an `hsforms.net` iframe.
- `getConversionId()` returns a value by the time `on-submission:success` fires (documented as valid after a successful submission; not verified live).
- `hsforms.net` is HubSpot's form host (the documented embed script is `js.hsforms.net/forms/embed/v2.js`). The `event.origin` of iframe-rendered legacy embeds is not documented.
- Enhanced Conversions `user_data` and webhook adapter hardening are out of scope (separate issues).
