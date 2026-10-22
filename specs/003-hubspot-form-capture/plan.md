# Implementation Plan: HubSpot Embedded Form Capture

**Branch**: `claude/compassionate-tesla-817n70` | **Date**: 2026-10-22 | **Spec**: [spec.md](spec.md) | **Issue**: [#126](https://github.com/vizuh/click-trail-handler/issues/126)

> Blocked on Phase 0 (tasks.md T001). R2, R3 and R5 in research.md carry [U] items that decide the init-rescan behaviour, the field-name prefix and the legacy origin allowlist. Lock those before writing code.

## Summary

Add a feature-detected HubSpot module to the browser runtime. It listens for HubSpot's documented global form events on `window`, pushes one consent-gated `lead` per successful submission with a deterministic `event_id`, writes ClickTrail attribution into hidden HubSpot fields through `HubSpotFormsV4.setFieldValue()` on ready (and on an init rescan), clears them on consent withdrawal, and fixes the legacy `hsFormCallback` path in the message normaliser. No server route, storage or schema changes.

## Technical Context

**Language/Version**: browser ES2019 (no build step); PHP 8.1+ for the filter and config localisation only
**Primary Dependencies**: none new; HubSpot's `HubSpotFormsV4` API is used only when present
**Storage**: none; an in-memory `Map` per page
**Testing**: Node vm unit test (pattern `tools/qa/consent-bridge.test.js`); a stub-API page in `tools/qa/e2e` driven by the existing Playwright harness; PHPUnit for the filter default; `npm run smoke`; live acceptance SC-004
**Constraints**: nothing runs on sites without HubSpot; no PII read or pushed; no write or event without consent; browser never pushes `qualified_lead`

## Repo gates

- AGENTS.md docs matrix: integration behaviour, hooks and event-flow changes, so `INTEGRATIONS.md`, `integration-capabilities.json`, `HOOKS-REFERENCE.md`, `EVENT-PIPELINE.md`, `SECURITY-PRIVACY.md` (consent gating and withdrawal) are updated; `SETTINGS-AND-ADMIN.md` only if a toggle is added; readmes and `changelog.txt` at the release cut.
- `/code-review` before commit (consent path and third-party API surface).
- Evidence boundary unchanged: the new capability row is runtime-unverified until SC-004 passes.

## Design

### Where the code lives

| Change | File |
|---|---|
| `HubSpotForms` module: feature detection, `window` listeners, `getForms()` rescan, per-instance `Map`, success and failed handlers, legacy normaliser fix | `assets/js/clicutcl-events.js` |
| Field writer: builds values from `Store.getData()` and `Identity.get()` (same source as `Injector.run()`), applies `injectOverwrite`, writes with `setFieldValue(name, [value])`, records written fields, clears on withdrawal | `assets/js/clicutcl-attribution.js` (next to `Injector`; reuse its data assembly) |
| Bridge between the two scripts: the attribution script exposes `window.ClickTrailAttribution.hubspotFieldValues()` (or the events script reads the cookie store the way `Injector` does); choose the smaller one during implementation and record it here | both |
| Filter `clicutcl_hubspot_field_map` with the default map; localised into `clicutclEventsConfig.hubspot.fieldMap` | `includes/class-clicutcl-core.php` (where `iframeOrigins` is localised) |
| Optional toggle `forms.hubspot_embed.enabled` (default on when HubSpot is detected) | `includes/tracking/class-settings.php`, `includes/admin/class-admin.php`, `assets/js/admin-settings-app.js`; decide in T003 whether a toggle is warranted or feature detection is enough |

### Event contract

```text
hs-form-event:on-submission:success (window)
  form = HubSpotFormsV4.getFormFromEvent(e)
  conversionId = await bounded(form.getConversionId())            # may be ''
  key = detail.instanceId + '|' + (conversionId || minuteBucket)
  seen(key) -> return
  pushEvent('lead', {
    form_provider: 'hubspot',
    form_id: detail.formId,
    lead_context: { provider: 'hubspot', form_id, instance_id, submit_status: 'success' }
  }, { eventId: 'hsform_' + (conversionId || formId + '|' + instanceId + '|' + minuteBucket) })

hs-form-event:on-submission:failed (window)
  pushEvent('form_submit_failed', { form_provider: 'hubspot', form_id, lead_context: {..., submit_status: 'failed'} })

hs-form-event:on-ready (window), and once at init for each HubSpotFormsV4.getForms()
  if (!liveConsentAllows()) return
  for [ctKey, hsName] of fieldMap:
    value = data[ctKey]; if empty continue
    if (!injectOverwrite) { current = await bounded(form.getFieldValue(hsName)); if (current non-empty && !written.has(hsName)) continue }
    await bounded(form.setFieldValue(hsName, [String(value)])); written.add(hsName)

ct:consentResolved with marketing=false
  for each instance, for each written field: setFieldValue(name, ['']); written.clear()

message (window), same-origin or allowlisted
  data.type === 'hsFormCallback' && data.eventName === 'onFormSubmitted'
    -> { event_name: 'lead', provider: 'hubspot', external_id: data.id + ':' + shortHash(data.data.submissionValues) }
```

`bounded()` wraps a promise with a 1500 ms timeout and swallows rejections (debug log only). `minuteBucket` is `Math.floor(Date.now() / 60000)`.

### Default field map

Derived from `Injector.map()` so one table serves DOM inputs and HubSpot fields: `ct_ft_source`, `ct_ft_medium`, `ct_ft_campaign`, `ct_ft_term`, `ct_ft_content`, `ct_lt_*` equivalents, `ct_gclid`, `ct_gbraid`, `ct_wbraid`, `ct_fbclid`, `ct_msclkid`, `ct_ft_landing_page`, `ct_lt_landing_page`, `ct_ft_channel`, `ct_lt_channel`, `ct_trail_id`, `ct_attribution_selected_click_id`. HubSpot name = `'0-1/' + ctKey`. Sites override with the filter.

## Risks

- `getForms()` semantics for not-yet-ready instances are undocumented (R2). If writes to not-ready forms are lost, the rescan must only register instances and defer writing to `on-ready`.
- The `0-1/` prefix is undocumented (R3). The filter makes it replaceable without a release.
- HubSpot may change the v4 embed internals without notice. The design uses only the documented global events and `HubSpotFormsV4` methods, nothing read from the embed source.
- Two scripts (attribution and events) both need consent state and attribution data. Keep one owner for the field writer to avoid two consent interpretations.
- Sites with REST transport off see the `lead` only in `dataLayer`. State this in INTEGRATIONS.md and the admin "Forms" status copy if a toggle is added.
