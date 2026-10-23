# Tasks: HubSpot Embedded Form Capture

**Input**: [spec.md](spec.md), [plan.md](plan.md), [research.md](research.md)

## Phase 0 — Verify external facts on a real HubSpot form (blocks US2 and SC-004)

- [ ] T001 On a disposable site, embed one updated-editor form (v4 iframe) and one legacy `hbspt.forms.create` form, both with a hidden contact property. Record in research.md R6: whether `getForms()` returns not-yet-ready instances; whether two instances of one form get distinct `instanceId`s; whether the v4 iframe receives the parent query string; whether `getConversionId()` is set when `on-submission:success` fires; the exact `event.origin` of an iframe-rendered legacy embed. — open
- [ ] T002 On the same forms, call `setFieldValue('0-1/<hidden>', ['x'])` before submitting and confirm whether the value lands on the contact record; record what the `0-1/` prefix means and whether it is stable. — open

## Phase 1 — US1 (P1): v4 submission is one lead

- [x] T003 Register `hs-form-event:on-submission:success` / `:failed` listeners on `window` at script load; push `lead` with `event_id` from `getConversionId()` or the nonce fallback; `form_submit_failed` on failure (FR-001–003, FR-005). — `assets/js/clicutcl-events.js`
- [x] T004 Allow `form_submit_failed` on the browser route and in the canonical map. — `includes/tracking/class-eventv2.php`, `tests/unit/BoundarySecurityTest.php`

## Phase 2 — US3 (P2): legacy submission is one lead

- [x] T005 Map `hsFormCallback` + `onFormSubmitted` to the same lead, ignore other callback names, keep the origin rule, never read `data` (FR-004, FR-006); add `hsforms.net` to the `clicutcl_iframe_origin_allowlist` default. — `assets/js/clicutcl-events.js`, `includes/class-clicutcl-core.php`

## Phase 3 — US2 (P1): attribution into HubSpot hidden fields — BLOCKED on T001/T002

- [ ] T006 On `hs-form-event:on-ready` plus a `getForms()` rescan, write first/last touch and click IDs into matching hidden fields with `setFieldValue('0-1/<name>', [value])`, respecting `injectOverwrite` and live consent; clear on withdrawal like `Injector.clear()`. Do not start until T001/T002 prove the write lands on the contact and the prefix is stable. — blocked
- [ ] T007 Add the `clicutcl_hubspot_field_map` filter with T006, documented in `HOOKS-REFERENCE.md`. — blocked

## Phase 4 — Proof and docs

- [x] T008 Node VM test `tools/qa/hubspot-form-capture.test.js` covering SC-001–SC-003, wired into `npm run test:consent`. — done
- [ ] T009 E2E page in `tools/qa/e2e` stubbing `HubSpotFormsV4`. — skipped for now: the harness runs with browser event collection disabled and asserts stored/dispatched CF7 and Calendly data; enabling collection there would change the existing round trip. The VM test runs the same script.
- [x] T010 Docs per FR-008 (`INTEGRATIONS.md`, `integration-capabilities.json`, `EVENT-PIPELINE.md`, `SECURITY-PRIVACY.md`, plus `REST-API.md`, `HOOKS-REFERENCE.md`, `CODE-MAP.md`). Readme and changelog entries come with the release cut. — done
- [ ] T011 Live acceptance (SC-004) on real HubSpot forms. Only after it passes, update the HubSpot embedded forms row in `INTEGRATIONS.md` and the ledger. — open

## Dependencies

T001, T002 → T006 → T007. T003 → T004. T005 is independent of T003. T008 after T003–T005. T011 after T001.
