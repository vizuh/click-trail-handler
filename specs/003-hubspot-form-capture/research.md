# Research: HubSpot Embedded Form Capture

Legend: **[V-doc]** verified against vendor docs on 2026-10-22 (URL given); **[V-src]** verified by reading source (this repo at `7ee35c2`, or HubSpot's embed JS as read in the tenant investigation); **[U]** unverified, needs a live browser or account test (tasks.md T001).

## Current state (source, `main` at `7ee35c2`, 1.10.5)

| Surface | File | Finding |
|---|---|---|
| External message bridge | `assets/js/clicutcl-events.js` `trackExternalFormMessages()` (~L2116), `normalizeExternalMessage()` (~L2171) | Accepts only `type`/`event` values `typeform-submit`, `hubspot-form-submit`, `clicktrail.lead`, `clicutcl.lead`, `lead` and the booking types. HubSpot sends none of these. Same-host messages pass `isAllowedOrigin()` without the allowlist; the allowlist (`clicutcl_iframe_origin_allowlist`, default `calendly.com`, `typeform.com`, `hubspot.com`) only matters for cross-origin posts. |
| DOM form listeners | same file, `trackLeadGenEvents()` (~L723-761) | `focusin` → `form_start`, capture-phase `submit` → `form_submit_attempt`. Only CF7 (`wpcf7mailsent`) produces a `lead`. Neither sees a `<form>` inside a cross-origin iframe. |
| Event gate | same file, `pushEvent()` (~L59-90) | Drops events unless `window.ClickTrailConsent.isGranted()`; attaches identity, `event_id`, `marketing_trail`; pushes to `dataLayer`; posts to `/clicutcl/v2/events/batch` only when transport is enabled. `lead` maps to `funnel_stage: bottom`. |
| Hidden-field injector | `assets/js/clicutcl-attribution.js` `Injector` (~L1073-1270) | Fills parent-document inputs by name (`ct_ft_source`, `utm_source`, `gclid`, `ct_lt_gclid`, `ct_trail_id`, ...). Gated by `liveConsentAllows()`; `Injector.clear()` empties written fields on withdrawal; `injectOverwrite` decides whether non-empty values are replaced. Cannot reach iframe content. |
| Server allowlist | `includes/api/class-tracking-controller.php` | `qualified_lead`, `purchase`, `client_won` are restricted to trusted routes; browser events may push `lead`. |
| Dedup | `includes/tracking/class-dedup-store.php` `is_duplicate(destination, event_name, event_id)` | Keyed per destination, so a browser `lead` and a `webhook_hubspot` `lead` never dedupe against each other. |
| Webhook adapter | `includes/tracking/webhooks/class-hubspotwebhookadapter.php`, `class-webhook-auth.php` L46-48 | v1 signature only, no timestamp, every non-property-change event → `lead`. Tracked in [#128](https://github.com/vizuh/click-trail-handler/issues/128); out of scope here. |
| Tenant site config | live `clicutclEventsConfig` | `enabled: "1"`, `transportEnabled: ""` (off), `thankYouMatchers: []`; `injectEnabled: "1"`, `injectMutationObserver: "1"`; consent bridge `cmpSource: auto`, `fallbackGranted: "1"`, 3 s timeout. No HubSpot tracking code on the site. [V-src] from the investigation's saved HTML. |

## R1 - Global form events (updated editor)

**Decision**: listen on `window` for `hs-form-event:on-ready`, `hs-form-event:on-submission:success`, `hs-form-event:on-submission:failed`; read `event.detail.formId` and `event.detail.instanceId`; resolve the form with `HubSpotFormsV4.getFormFromEvent(event)`.

**[V-doc]** https://developers.hubspot.com/docs/api-reference/latest/marketing/forms/global-form-events : event names; payload "includes two nested IDs, the `formId` and the `instanceId`"; all examples use `window.addEventListener`; "you should define any `hs-form-event:on-ready` listeners on your page before the form embed code is executed"; events apply to updated-editor forms only. Also lists `hs-form-event:on-interaction:navigate[:next|:previous]` for multi-step forms, which this feature ignores.

**Not documented**: dispatch target and bubbling (the investigation read "bubbling CustomEvent" from HubSpot's embed JS, [V-src] only). Use `window` as the docs do.

## R2 - `HubSpotFormsV4` API

**Decision**: use `getForms()`, `getFormFromEvent()`, `getFormId()`, `getInstanceId()`, `getConversionId()`, `getFieldValue()`, `setFieldValue()`. Do not use `setExtraSubmissionMetadata()` in this spec (its destination in HubSpot is not documented well enough to rely on). Do not touch `__SECRET_INTERNAL_DO_NOT_USE` fields.

**[V-doc]** same URL: `getForms()` "will return an array of `form` instances"; `getFormFromEvent(event)`; `getFieldValue(name)` is async and returns string or string[]; `getFormFieldValues()` async, array of `{name, value}`; `getConversionId()` "returns a valid string only when called after the form is successfully submitted"; `setFieldValue(name, value)` with Hidden → `string[]`, single checkbox → boolean, text/dropdown/radio → string, file fields unsupported; "some form data fields can only be retrieved or set before the form is submitted".

**[U]** whether `getForms()` includes instances that are not yet ready, and whether `setFieldValue` on a not-yet-ready instance is accepted or lost. Phase 0 decides whether the init rescan can write directly or must wait for `on-ready`.

## R3 - Field naming

**Decision**: default map `0-1/<ct_name>`, filterable (`clicutcl_hubspot_field_map`).

**[V-doc]** examples use `"0-1/firstname"`, `"0-1/multicheckbox"`. The prefix is never explained. **[U]** meaning and stability; Phase 0 confirms that `setFieldValue('0-1/<hidden property>', ['x'])` lands on the contact.

## R4 - Hidden fields and query-string prefill

**Decision**: the site owner creates hidden contact properties with internal names matching ClickTrail's `ct_*` aliases and adds them as hidden fields. ClickTrail writes them with `setFieldValue`. Query-string prefill is a bonus for the landing pageview only; it is not relied on.

**[V-doc]** https://knowledge.hubspot.com/forms/pass-contact-property-values-with-hidden-form-fields : hidden fields available on "All products and plans"; "If the Required field option is toggled on for your form field, this field cannot be set as hidden."
**[V-doc]** https://knowledge.hubspot.com/forms/can-i-auto-populate-form-fields-through-a-query-string : "Form fields, including hidden fields, will automatically populate based on the query string"; dependent fields cannot be prefilled. The doc describes HubSpot-hosted pages and is silent on embedded forms on external sites and on the updated editor.
**[V-src]** the investigation read in HubSpot's v4 embed JS that the parent page query string and `_hsUtk` are appended to the iframe URL. **[U]** confirm on the live page.

## R5 - Legacy v2 embed messages

**Decision**: extend `normalizeExternalMessage()` to accept `data.type === 'hsFormCallback' && data.eventName === 'onFormSubmitted'`, `form_id = data.id`, external id = `data.id` plus a short hash of `JSON.stringify(data.data.submissionValues)` when present.

**[V-doc]** https://developers.hubspot.com/docs/cms/start-building/features/forms/legacy-forms : listen on `window`, check `event.data.type === 'hsFormCallback'`, read `event.data.eventName`; payload `type`, `eventName`, `id`, `data`; `onFormSubmitted` "Called after the form has been submitted and the submission has persisted", data `{redirectUrl, submissionValues}`; `onBeforeFormSubmit` fires before persistence; `onFormSubmit` is deprecated in favour of `onBeforeFormSubmit`.

**[V-src]** the legacy v2 loader posts the message from the parent window to itself (`postMessage(..., "*")`), so `event.origin` is the site's own host and `isAllowedOrigin()` already passes it. **[U]** exact origin for iframe-rendered legacy forms (`forms.hsforms.com`?). If cross-origin, add `hsforms.com` and `hsforms.net` to the default allowlist.

**Not documented**: the v2 loader's `"Not an Embed version 2 or 3 form"` 403 and automatic fallback to the v4 embed. The only official statement is the beta announcement that updated-editor forms "do not support the existing developer features". [V-src] from HubSpot's `v2.js`.

## R6 - Consent and withdrawal

**Decision**: field writes go through the same live-consent check as `Injector.run()` and clear on `ct:consentResolved` with `marketing: false`, mirroring `Injector.clear()` (1.10.2 semantics documented in `SECURITY-PRIVACY.md`). Events go through `pushEvent()` unchanged. The attribution script owns consent state and the field map; the events script owns the `lead`. Plan.md decides where the HubSpot module lives.

## R7 - Dedup and source of truth

**Decision**: `event_id = hsform_<conversionId>`; per-page `Map` keyed by `instanceId` prevents double counting on re-dispatch. The webhook adapter's IDs (`hs_<md5>`) are distinct by prefix, and `Dedup_Store` is per destination anyway, so a site running both paths records both. Document "pick one source per event" in INTEGRATIONS.md.

## R8 - Rejected alternatives

- **Inline bootstrap before the embed script**: only `on-ready` is timing-sensitive, and the `getForms()` rescan covers it. Revisit if Phase 0 shows forms missed in the direct-v4 load order.
- **GTM Custom HTML listener as the product path**: works as a per-site stop-gap, but duplicates ClickTrail's consent gate and event ID logic outside the plugin.
- **HubSpot tracking code as a substitute**: gives HubSpot its own source analytics but nothing to ClickTrail; a client-side decision, not a plugin feature.
- **Webhook-only capture**: carries no visitor link, needs a HubSpot app, and has the issues in #128.
