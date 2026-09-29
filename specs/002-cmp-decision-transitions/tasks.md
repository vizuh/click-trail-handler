# Tasks: Attribution Across CMP Decision Transitions

**Input**: [spec.md](spec.md), [plan.md](plan.md)

## Phase 1 — Evidence first

- [ ] T001 Enumerate every storage write in `assets/js/*.js`: the key, the medium, the writer, and whether it is reachable before consent. Save the result as the draft inventory.
- [ ] T002 Write `tools/qa/cmp-transition.probe.mjs` with the Cookiebot stub (wipe, then `OnConsentReady`). Run it against current `main` and record the baseline. Expected: US1 passes, and US2 fails on `clicutcl_js_last_seen`.

## Phase 2 — US2 (P1): reject leaves nothing

- [ ] T003 Delete the dead `clicutcl_js_last_seen` write (it has no reader) for FR-001/002. Add a regression case to `tools/qa/consent-bridge.test.js` asserting that no localStorage write happens after a denial.

## Phase 3 — US1 (P1): accept keeps the click

- [ ] T004 Make capture on grant idempotent across `OnConsentReady`, `OnAccept` and a synchronous `hasResponse` (FR-003/004). Add probe cases for double events and late script load.
- [ ] T005 Fix the stale Phase 2 comment (FR-005).

## Phase 4 — US3 (P2): classification guidance

- [ ] T006 Add the storage inventory table and CMP notes to `docs/guides/SECURITY-PRIVACY.md` and `docs/tutorials/03-consent-and-events.md` (FR-006).
- [ ] T007 Add `tools/qa/storage-inventory.test.js` and wire it into `package.json` and CI (FR-007).

## Phase 5 — Verify

- [ ] T008 Probe matrix: Chromium, Firefox and WebKit on disposable WordPress (PHP 8.1) must meet SC-001 and SC-002. Run `npm run test:consent`, `npm run smoke` and PHPUnit.
- [ ] T009 Add `changelog.txt` / `readme.txt` entries for the next patch.

## Dependencies

T001 → T002 → (T003, T004, T005 in parallel) → T006 → T007 → T008 → T009.
