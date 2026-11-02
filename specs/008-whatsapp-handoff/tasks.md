# Tasks: WhatsApp Handoff (click event + short reference code)

**Input**: [spec.md](spec.md), [plan.md](plan.md), [research.md](research.md)

## Phase 1 — Event (US1)

- [x] T001 Add `contact_whatsapp_start` to `EventV2::BROWSER_EVENT_NAMES` and to the `buildCanonicalEvent` map (`mid`). — `includes/tracking/class-eventv2.php`, `assets/js/clicutcl-events.js`
- [x] T002 WhatsApp branch in the events click handler: allowlist + `whatsapp:`, `link_url` without `text` / fragment, `phone` digits, `ref_code` from `window.ClickTrail.getWhatsAppRef()`, consent check then `markOnce`, `pushEvent`. — `assets/js/clicutcl-events.js`
- [x] T003 Rename the starter-kit trigger and GA4 tag to `contact_whatsapp_start` in `assets/build-starter-kit.py`, regenerate `assets/gtm-starter-kit.json` (the Stape input file is absent from the checkout: rebuilt with a temporary stand-in holding the ids and the Facebook template taken from the shipped JSON, then removed), assert it in `tools/qa/gtm-starter-kit.test.js`. — `assets/build-starter-kit.py`, `assets/gtm-starter-kit.json`, `tools/qa/gtm-starter-kit.test.js`

## Phase 2 — Storage and routes (US3)

- [x] T004 `Whatsapp_Ref_Store` (pure builders, `create`, `find`, `purge_expired`, `delete_by_visitor_ids`). — `includes/database/class-whatsapp-ref-store.php`
- [x] T005 Table, readiness options, `DB_VERSION` 5 in the installer; update `tools/qa/touch-events-db.test.php` to read the constant. — `includes/database/class-installer.php`, `tools/qa/touch-events-db.test.php`
- [x] T006 `POST /whatsapp-ref` and `GET /whatsapp-ref/<code>` with gates, validation, 409, 404. — `includes/api/class-tracking-controller.php`
- [x] T007 Localize `whatsappRefUrl`; mint `eventsToken` when the append setting is on. — `includes/class-clicutcl-core.php`
- [x] T008 Cleanup batch purge, Diagnostics purge, privacy eraser, uninstall. — `includes/utils/class-cleanup.php`, `trait-admin-diagnostics-ajax.php`, `includes/privacy/class-privacy-handler.php`, `uninstall.php`
- [x] T009 Diagnostics lookup card (AJAX + JS). — `includes/admin/class-admin.php`, `trait-admin-diagnostics-ajax.php`, `trait-admin-pages.php`, `assets/js/admin-diagnostics.js`

## Phase 3 — Reference code in the link (US2)

- [x] T010 `WhatsAppRef` in the attribution script and the rewritten `initWhatsAppListener`; `Decorator.restore()` / `handleConsentDenied` cleanup. — `assets/js/clicutcl-attribution.js`
- [x] T011 Update the existing `cmp-transition.test.js` WhatsApp case to the new format. — `tools/qa/cmp-transition.test.js`

## Phase 4 — Proof and docs

- [x] T012 Node VM test `tools/qa/whatsapp-handoff.test.js`; wire into `npm run test:attribution` and `npm run test:consent`. — `tools/qa/whatsapp-handoff.test.js`, `package.json`
- [x] T013 PHPUnit `tests/unit/WhatsappRefTest.php`: route auth, validation, TTL, collision, consent gate, lookup 404, eraser helper. — `tests/unit/WhatsappRefTest.php`
- [x] T014 Docs per FR-009 and `changelog.txt` (`= Unreleased =`). — docs
- [ ] T015 Live acceptance (SC-003): a real click on a consented tagged landing, the `Ref:` visible in the WhatsApp compose box, the admin lookup resolving it. Open: not run.

## Dependencies

T004 → T005, T006, T008. T006, T007 → T010. T002, T010 → T012. T004, T006 → T013. T012, T013 → T014. T015 after T012.
