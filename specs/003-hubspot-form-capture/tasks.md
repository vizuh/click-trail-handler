# Tasks: HubSpot Embedded Form Capture

**Input**: [spec.md](spec.md), [plan.md](plan.md), [research.md](research.md) | **Issue**: [#126](https://github.com/vizuh/click-trail-handler/issues/126)

## Phase 0 - Verify external facts (blocks everything else)

- [ ] T001 Live test on the tenant's staging page (or a disposable HubSpot portal with one updated-editor form embedded on a WordPress page). With a test submission, record in research.md:
  - the rendered DOM: `.hs-form-frame` and the iframe `src`; whether `?utm_source=test&gclid=TEST` from the parent URL appears in it (R4)
  - `window.addEventListener('hs-form-event:on-ready' | 'on-submission:success' | 'on-submission:failed', e => console.log(e.type, e.detail))`: the exact `detail` shape (R1)
  - `HubSpotFormsV4.getForms()` before and after `on-ready`: does it include not-yet-ready instances (R2)
  - `getFormFromEvent(e).getConversionId()` on success: value present (R2)
  - with one hidden test property added to the form: `setFieldValue('0-1/<prop>', ['x'])` on ready, then submit, then check the contact record (R3)
  - both instances of the same form on one page (inline and popup): distinct `instanceId`s, and only the submitted one fires success
  - whether a legacy-rendered form on the same portal posts `hsFormCallback` from the page origin or from `*.hsforms.com` (R5)
  - the HubSpot submission request's `context` (`hutk`, `pageUrl`) in the network tab
  - a side check for spec 004: whether `getFieldValue('0-1/email')` resolves to a value after `on-submission:success`
  Save redacted console and network captures under `tests/fixtures/hubspot/` (no email, phone or names).

## Phase 1 - Foundation

- [ ] T002 Node unit test file `tools/qa/hubspot-forms.test.js` (vm harness, pattern `tools/qa/consent-bridge.test.js`): stub `window.HubSpotFormsV4`, `window.ClickTrailConsent`, `dataLayer`; cases for US1 scenarios 1-5, US2 scenarios 1-5, US3 scenarios 1-3. Wire into `package.json` (`test:hubspot`) and the QA workflow. Expected: all red against current `main`.
- [ ] T003 Decide toggle vs feature detection (plan.md). If a toggle: add `forms.hubspot_embed.enabled` to `Settings` defaults, admin sanitiser and the Forms card; update `SETTINGS-AND-ADMIN.md`. Otherwise record the decision in plan.md.
- [ ] T004 [P] Add `clicutcl_hubspot_field_map` filter with the default map in `includes/class-clicutcl-core.php`, localised as `clicutclEventsConfig.hubspot.fieldMap`. PHPUnit: default map keys match `Injector` aliases; filter override is honoured. Document in `HOOKS-REFERENCE.md`.

## Phase 2 - US1 (P1): one `lead` per submit

- [ ] T005 `HubSpotForms` module in `assets/js/clicutcl-events.js`: feature detection, `window` listeners, per-instance `Map`, `bounded()` helper, success handler (FR-001 to FR-003), failed handler (FR-004). T002 US1 cases go green.

## Phase 3 - US2 (P1): attribution into hidden fields

- [ ] T006 Field writer next to `Injector` in `assets/js/clicutcl-attribution.js`: reuse `Injector.run()` data assembly, `injectOverwrite` handling via `getFieldValue()`, `setFieldValue(name, [value])`, written-field record, `getForms()` rescan at init (behaviour per T001 R2 result), clear on `ct:consentResolved` with `marketing: false` (FR-005 to FR-007). T002 US2 cases go green.

## Phase 4 - US3 (P2): legacy embeds

- [ ] T007 Extend `normalizeExternalMessage()` for `hsFormCallback` + `onFormSubmitted` (FR-008); add `hsforms.com` / `hsforms.net` to the default allowlist only if T001 shows a cross-origin post. T002 US3 cases go green.

## Phase 5 - Proof and docs

- [ ] T008 E2E page in `tools/qa/e2e` (stub `HubSpotFormsV4`, dispatch events) run by the existing Playwright harness on disposable WordPress: SC-001, SC-002 (consent pending/denied/granted/withdrawn). Extend `verify.py`.
- [ ] T009 Docs per FR-011: `INTEGRATIONS.md` ("HubSpot embedded forms" section, dedup note, transport-off note), `integration-capabilities.json` (`source.browser.hubspot_forms`, runtime-unverified, test IDs from T002/T008), `EVENT-PIPELINE.md`, `SECURITY-PRIVACY.md`, `HOOKS-REFERENCE.md`. `readme.txt` / `changelog.txt` at the release cut.
- [ ] T010 `/code-review` on the branch, then `composer phpcs`, `composer test`, `npm run smoke`, `npm run test:hubspot`.
- [ ] T011 Live acceptance (SC-004) on the tenant's staging after they add the hidden properties and fields. Only after it passes, move the INTEGRATIONS and ledger rows off runtime-unverified.

## Dependencies

T001 → (T002, T003, T004) → T005 → T006 → T007 → T008 → T009 → T010 → T011. T003 and T004 can run in parallel with T002.

## Client-side prerequisites (not repo tasks)

- Create hidden contact properties matching the field map and add them as hidden fields to form `ba668b39-…` (required fields cannot be hidden).
- Decide whether to enable ClickTrail REST transport (server-side storage of the `lead`).
- Decide whether to install the HubSpot tracking code (consent-gated) for HubSpot's own source analytics.
- Confirm the HubSpot tier and whether Google Ads is connected in HubSpot (relevant to spec 004 and the native Ads conversion sync).
