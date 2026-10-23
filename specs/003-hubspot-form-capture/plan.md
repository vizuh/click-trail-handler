# Implementation Plan: HubSpot Embedded Form Capture

**Branch**: `feat/hubspot-form-capture` | **Date**: 2026-10-23 | **Spec**: [spec.md](spec.md) | **Issue**: [#126](https://github.com/vizuh/click-trail-handler/issues/126)

## Summary

Listen for HubSpot's documented submission events (v4 window events and the legacy `hsFormCallback` message) and push one consent-gated `lead` per submission, or `form_submit_failed` on a failed v4 submission. Attribution writes into HubSpot hidden fields (US2) wait for Phase 0 live proof.

## Technical Context

**Language/Version**: browser ES2019 (no build step); PHP 8.1+ for the browser-route allowlist and the origin default
**Primary Dependencies**: none new
**Storage**: none (in-memory dedupe markers only)
**Testing**: `tools/qa/hubspot-form-capture.test.js` (Node VM, real `clicutcl-events.js`) in `npm run test:consent`; `npm run smoke`; PHPUnit (`BoundarySecurityTest`)
**Constraints**: identifiers only, no field values; no events without consent; browser never pushes `qualified_lead`

## Repo gates

- AGENTS.md docs triggers (integration behaviour and the browser route): `INTEGRATIONS.md`, `integration-capabilities.json`, `EVENT-PIPELINE.md`, `SECURITY-PRIVACY.md`, `REST-API.md` (allowed browser events), `HOOKS-REFERENCE.md` (allowlist default), `CODE-MAP.md` (test list). No new hook; no settings toggle, so `SETTINGS-AND-ADMIN.md` is unchanged.
- Evidence boundary: the HubSpot embedded forms row stays source-present / runtime-unverified until SC-004 passes.

## Design

### Browser (`assets/js/clicutcl-events.js`)

| Change | Where |
|---|---|
| `HUBSPOT_FORM_EVENTS` map (success → `lead`, failed → `form_submit_failed`) and `HUBSPOT_LEAD_DEDUPE_MS` | IIFE scope |
| Window listeners registered at script load, forwarding to `trackerInstance.trackHubSpotFormEvent()` when the tracker exists | next to `trackerInstance` |
| `trackHubSpotFormEvent()`, `getHubSpotConversionId()` (feature-detects `HubSpotFormsV4`, try/catch), `getHubSpotPageNonce()`, `pushHubSpotLead()` (exact-ID and per-form window dedupe) | `ClickTrailEvents` |
| `normalizeHubSpotCallback()` called from `normalizeExternalMessage()` for `type === 'hsFormCallback'`; `trackExternalFormMessages()` routes it to `pushHubSpotLead()` after the origin check | `ClickTrailEvents` |
| `form_submit_failed` (mid funnel) in the canonical map | `buildCanonicalEvent()` |

### Server

| Change | File |
|---|---|
| `form_submit_failed` allowed on the browser route | `includes/tracking/class-eventv2.php` |
| `hsforms.net` added to the `clicutcl_iframe_origin_allowlist` default | `includes/class-clicutcl-core.php` |

### Contract: HubSpot → `dataLayer`

```text
hs-form-event:on-submission:success {formId, instanceId}
    -> lead { form_provider: hubspot, form_id, lead_context: {provider: hubspot, form_id, submit_status: success} }
       event_id = hsform_<getConversionId()> | hsform_<formId>|<instanceId>|<page nonce>
hs-form-event:on-submission:failed {formId, instanceId}
    -> form_submit_failed { ..., lead_context.submit_status: failed }
message {type: hsFormCallback, eventName: onFormSubmitted, id}   (origin rule applies)
    -> lead (same shape), event_id = hsform_<id>|legacy|<page nonce>
dedupe: same event_id once per page; one HubSpot lead per formId per 10 s across both paths
```

## Risks

- `getConversionId()` may be empty at `on-submission:success`; the fallback ID still dedupes on the page but the server cannot match it to HubSpot's conversion.
- The legacy iframe origin is unverified; if it is not `hsforms.net` or `hubspot.com`, iframe-rendered legacy forms stay invisible until a site adds the origin through `clicutcl_iframe_origin_allowlist`.
- Running the HubSpot webhook and browser capture together records two `lead` events per submission (different sources).
