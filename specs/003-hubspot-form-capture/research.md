# Research: HubSpot Embedded Form Capture

Vendor docs read on 2026-10-23:

- Global form events (updated editor): https://developers.hubspot.com/docs/api-reference/latest/marketing/forms/global-form-events
- Legacy forms: https://developers.hubspot.com/docs/cms/start-building/features/forms/legacy-forms

## Current state (source, `main` at `7ee35c2`, 1.10.5)

| Surface | File | Finding |
|---|---|---|
| External messages | `assets/js/clicutcl-events.js` (`trackExternalFormMessages`, `normalizeExternalMessage`) | Accepts `type` values such as `hubspot-form-submit`, `lead`, `clicktrail.lead`. HubSpot never sends these. Legacy forms post `type: "hsFormCallback"`. |
| v4 events | same file | Nothing listens for `hs-form-event:*`. |
| Submit listener | same file (`trackLeadGenEvents`) | Capture-phase `submit` pushes `form_submit_attempt`, not `lead`, and cannot see a `<form>` inside a cross-origin iframe. |
| Hidden fields | `assets/js/clicutcl-attribution.js` (Injector) | `document.querySelectorAll` on the parent page cannot reach fields inside the iframe. |
| Origin rule | `isAllowedOrigin()` + `clicutcl_iframe_origin_allowlist` (`includes/class-clicutcl-core.php`) | Same host, or a host equal to / ending in `.<entry>`. Default entries: `calendly.com`, `typeform.com`, `hubspot.com`. |
| Browser route | `EventV2::BROWSER_EVENT_NAMES` | `form_submit_failed` was not allowed; it would have been rejected as `event_not_allowed`. |
| Server dedup | `Dedup_Store::is_duplicate('ingest', event_name, event_id)` | Global across visitors, TTL `clicutcl_v2_dedup_ttl` (default 7 days). |

## R1 — Documented v4 contract

**Verified in docs**: event names `hs-form-event:on-ready`, `:on-submission:success`, `:on-submission:failed` (plus navigate events) via `window.addEventListener`; `event.detail = { formId, instanceId }`; `HubSpotFormsV4.getFormFromEvent(event)` returns a form instance; `getConversionId()` "will return a valid string value when called after the form is successfully submitted"; `getFieldValue()` and `getFormFieldValues()` are async; `setFieldValue()` takes `string[]` for hidden fields; the docs warn to define `on-ready` listeners before the embed code executes.

**Decision**: listen for the two submission events only. Register both at script load (outside the consent-gated tracker boot), which satisfies the ordering advice and costs nothing until an event fires.

## R2 — Event ID

**Decision**: `hsform_<conversionId>`; fallback `hsform_<formId>|<instanceId>|<page nonce>`.
**Rationale**: the issue proposed `formId|instanceId` as the fallback. The server dedup key is `event_name` + `event_id`, global and kept for 7 days. If `instanceId` is deterministic (undocumented), every visitor's fallback lead would share one ID and all but the first would be dropped as duplicates. A 12-hex random nonce generated once per page load keeps the ID stable for repeat dispatches on the page and unique across visitors.

## R3 — Duplicate submissions

**Decision**: one lead per exact `event_id` per page, plus one HubSpot lead per `formId` within 10 seconds across the v4 and legacy paths.
**Rationale**: whether a v4 form also posts a legacy `hsFormCallback` is undocumented, and the two paths carry different IDs (the legacy message has no conversion ID). Both arrive within one submission's handling, so a short window catches the pair without blocking a second submission later on the page. All markers are in memory; nothing is written to storage.

## R4 — Legacy message and origin

**Verified in docs**: `{ type: 'hsFormCallback', eventName: 'onFormSubmitted', id, data: { redirectUrl, submissionValues } }`, "called after the form has been submitted and the submission has persisted". Other names (`onBeforeFormInit`, `onFormReady`, `onBeforeFormSubmit`, `onFormSubmit`) fire before persistence.
**Decision**: only `onFormSubmitted` counts; `data` is never read (it carries the submitted values). The message keeps the existing origin rule. `hsforms.net` is added to the default allowlist because HubSpot serves its form embed scripts from that host (`js.hsforms.net/forms/embed/v2.js`) and the issue observed v4 iframes on `js-na1.hsforms.net`. HubSpot does not document the `event.origin` of an iframe-rendered legacy embed (Phase 0). In-page legacy (raw HTML) forms post from the page itself and pass the same-host rule.

## R5 — Failed submissions

**Decision**: `form_submit_failed`, mid funnel, added to the browser route allowlist (`EventV2`) and the JS canonical map so it is not rejected as `event_not_allowed`.

## R6 — Not documented (Phase 0, before US2 and before the row can leave runtime-unverified)

- Does `getForms()` return instances that are not yet ready?
- Do two instances of the same form on one page get distinct `instanceId`s?
- Does the v4 iframe receive the parent page query string?
- Does `setFieldValue('0-1/<hidden>', ['x'])` land on the contact record?
- Meaning and stability of the `0-1/` field-name prefix (only in examples).
- Exact `event.origin` for iframe-rendered legacy embeds.
- Is `getConversionId()` already populated when `on-submission:success` fires?
