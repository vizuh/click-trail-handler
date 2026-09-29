# Tasks: Calendly Booking Attribution Handoff

**Input**: [spec.md](spec.md), [plan.md](plan.md), [research.md](research.md)

## Phase 0 — Verify external facts (blocks format lock)

- [ ] T001 Create a free Calendly test event and a webhook subscription with a signing key. Book through a URL carrying `utm_*`, `gclid` and a 22-character `salesforce_uuid`. Save the raw `invitee.created` payload and headers to `tests/fixtures/calendly/` (with the email and name redacted). Record in research.md R1/R2/R5 which parameters survive, the `salesforce_uuid` limit, and the exact signature header format.

## Phase 1 — Foundation

- [ ] T002 Add the `clicutcl_handoffs` table and the `DB_VERSION` 5 upgrade in `includes/database/class-installer.php`. Test: a fresh install and a v4 → v5 upgrade both create the table.
- [ ] T003 Add `includes/database/class-handoff-store.php` (mint, resolve with the blog scope, expiry, forget, erase). Unit tests cover the resolve, expired, other-blog and erased cases.
- [ ] T004 [P] Add the Calendly branch to `includes/tracking/class-webhook-auth.php`. Unit tests use the T001 fixture and cover valid, tampered, stale and missing signatures (US3).

## Phase 2 — US1 (P1): paid click reaches the booking

- [ ] T005 Add the `POST clicutcl/v2/attribution-handoff` route with the sign-route gates and a server consent re-check. Tests: pending or denied consent returns 403, and nothing is written.
- [ ] T006 Browser: `Handoff.prepare()` plus Calendly shaping in `decorateUrl` (FR-001–003). Extend `tools/qa/smoke.js` with the new smoke IDs.
- [ ] T007 In `CalendlyWebhookAdapter`, read `payload.tracking`, resolve the reference, and fall back to UTMs with `handoff_status` (FR-006/007). Replay tests: resolved, unknown and expired.
- [ ] T008 Cleanup expiry batch, privacy export and erasure by hashed email, and the uninstall drop (FR-009). Tests follow `WooCommerceConsentBoundaryTest`.

## Phase 3 — US2 (P2): embeds

- [ ] T009 Decorate inline widget `data-url` and wrap `Calendly.initInlineWidget` / `initPopupWidget`. Host-set values win.

## Phase 4 — Proof and docs

- [ ] T010 Playwright probe on disposable WordPress (PHP 8.1): land with `?gclid=`, grant consent, click the Calendly link and the widget, and assert the decorated URLs meet SC-002. Deny consent and assert zero mint requests (SC-003).
- [ ] T011 Update the docs per FR-010, plus `readme.txt` / `changelog.txt` entries.
- [ ] T012 Live acceptance (SC-004): a real booking on a disposable site. Only after it passes, update the Calendly evidence row in `INTEGRATIONS.md`.

## Dependencies

T001 → (T002, T004) → T003 → T005 → T006 → T007 → T008 → T009 → T010 → T011 → T012. T004 can run in parallel with T002/T003.
