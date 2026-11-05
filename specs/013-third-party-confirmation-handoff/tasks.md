# Tasks: Third-Party Confirmation Handoff (completion signal + reference round trip)

**Input**: [spec.md](spec.md), [plan.md](plan.md), [research.md](research.md)

## Phase 0: Decisions

- [ ] T001 Resolve research open questions 1 and 2 (rename timing, `calendly-booked`); the rest of the plan assumes "1.13 not shipped, keep the string". Target: `research.md`, `plan.md` Risks

## Phase 1: Generalise the reference store (US3 foundation)

- [ ] T002 Rename `Whatsapp_Ref_Store` to `Handoff_Ref_Store` (file, class, table `clicutcl_handoff_refs`, readiness options, `clicutcl_handoff_ref_*` filters, route `/handoff-ref`, localized `handoffRefUrl`, AJAX action, Diagnostics card text, sessionStorage key `ct_handoff_ref`, `getWhatsAppRef` to `getHandoffRef`), migrating every caller and removing the old names (FR-007). Target: `includes/database/class-whatsapp-ref-store.php`, `class-installer.php`, `includes/utils/class-cleanup.php`, `includes/privacy/class-privacy-handler.php`, `uninstall.php`, `includes/api/class-tracking-controller.php:551-657`, `includes/class-clicutcl-core.php:426`, `includes/admin/traits/trait-admin-diagnostics-ajax.php`, `assets/js/clicutcl-attribution.js:1957-2088`, `:1299`, `assets/js/clicutcl-events.js:907`, `assets/js/admin-diagnostics.js`
- [ ] T003 Update the tests and docs that name the old identifiers. Target: `tools/qa/whatsapp-handoff.test.js`, `tools/qa/cmp-transition.test.js`, `tests/unit/WhatsappRefTest.php` to `HandoffRefTest.php`, `docs/guides/SECURITY-PRIVACY.md:115`, `:208`
- [ ] T004 Setting `handoff_refs` (default 0, never seeded); the mint route's feature gate becomes `append || handoff_refs`; `eventsToken` also minted when `handoff_refs` is on (FR-006, FR-008). Target: `includes/admin/class-admin.php` schema near `:1233`, `includes/api/class-tracking-controller.php`, `includes/class-clicutcl-core.php:221`

## Phase 2: Completion events (US1, US2)

- [ ] T005 Add `payment_confirmed`, `deposit_paid`, `balance_paid` to `EventV2::BROWSER_EVENT_NAMES`, the `buildCanonicalEvent` map (`bottom`) and the translator's bottom-funnel and converted lists; keep `purchase` out of the browser list (FR-003). Target: `includes/tracking/class-eventv2.php:22`, `assets/js/clicutcl-events.js:171-196`, `includes/tracking/class-event-translator-v1-to-v2.php:114`, `:140`
- [ ] T006 `Confirmation_Map::sanitize()` plus the default entry (Calendly `calendly.event_scheduled`, V1) and the `clicutcl_confirmation_message_map` filter; localize `confirmationMap` and `thirdPartyConfirmations` next to `iframeOrigins` (FR-001, FR-002). Target: `includes/Core/class-confirmation-map.php` (new), `includes/class-clicutcl-core.php:334-345`
- [ ] T007 Setting `third_party_confirmations` (default 0; seeded 1 only when no attribution option and no sign of earlier use, same rule as `seed_cookie_scope()`); Capture tab toggle with the Calendly-webhook double-count note (FR-006). Target: `includes/admin/class-admin.php`, `includes/database/class-installer.php:95`
- [ ] T008 Events script: consult the map after the legacy matcher; `window.ClickTrailEvents.confirm()` defined at script load, delegating to the tracker once it exists and returning `false` before consent or when the setting is off; `return_param` scan at init; `ct_confirm_<id>` marker written after the consent check and cleared with the other markers; identifiers only (FR-001 to FR-005). Target: `assets/js/clicutcl-events.js:2239`, `:2436`, `:661`, `:2610`

## Phase 3: Reference round trip (US3)

- [ ] T009 `requestHandoffRef()` (promise, confirmed code or `''`), `decorateHandoffUrl()` with the host-to-parameter table (`buy.stripe.com` to `client_reference_id`, default `ct_ref`, filter `clicutcl_handoff_url_params`), exact host, https, signed URLs skipped, live consent (FR-009). Target: `assets/js/clicutcl-attribution.js:1799`, `:1957-2088`, `includes/class-clicutcl-core.php:437`

## Phase 4: Stripe webhook (US4)

- [ ] T010 `stripe` branch in `Webhook_Auth::verify_request()`: all `v1`, `t.body` HMAC, ignore other schemes, `hash_equals` per candidate, tolerance from the existing clamp, replay cache unchanged (FR-010). Target: `includes/tracking/class-webhook-auth.php:69`
- [ ] T011 Provider `stripe` in the defaults, sanitiser, admin provider rows and the settings app; `provider_adapter()` case (FR-006). Target: `includes/tracking/class-settings.php:79-91`, `includes/admin/class-admin.php:622`, `:1074`, `assets/js/admin-settings-app.js:505`, `:752`, `includes/api/class-tracking-controller.php:923`
- [ ] T012 `Stripe_Webhook_Adapter`: event whitelist, `payment_status = paid`, `livemode` rule and `clicutcl_stripe_accept_test_events`, reference pattern and `Handoff_Ref_Store::find()`, `metadata.ct_event` closed list, `Claim_Lock` then `Dedup_Store`, ignored events answer 200, no outbound request (FR-011). Target: `includes/tracking/webhooks/class-stripewebhookadapter.php` (new)
- [ ] T013 Minor-unit exponent table (zero-decimal, three-decimal, ISK and HUF special cases) transcribed from https://docs.stripe.com/currencies with the URL in a comment; `value` and `currency` only (FR-012). Target: adapter file
- [ ] T014 `source.webhook.stripe` entry, evidence level `source_present`. Target: `docs/reference/integration-capabilities.json`

## Phase 5: Proof and docs

- [ ] T015 Node VM test `tools/qa/third-party-confirmation.test.js`: SC-001 cases, origin look-alikes, withdrawal, no PII keys; wire into `npm run test:consent`. Target: `tools/qa/third-party-confirmation.test.js`, `package.json:6`
- [ ] T016 PHPUnit: `StripeWebhookTest.php` (SC-002 vectors, built with `hash_hmac` over a fixed secret and clock), `ConfirmationMapTest.php`, `HandoffRefTest.php` additions (gate, ceilings, TTL, consent). Target: `tests/unit/`
- [ ] T017 E2E `third-party-handoff`: a stub page served on a second host posts the Calendly-shaped message; a stub-signed `checkout.session.completed` for a minted reference is dispatched once and carries the click ID; record `EVIDENCE-<date>-third-party-handoff.md`; run under `flock /tmp/ct-e2e.lock`. Target: `tools/qa/e2e/third-party.sh`, `third_party.py`, `README.md`
- [ ] T018 Docs per FR-013 and `changelog.txt` (`= Unreleased =`); extend the "Payment provider limitations" section (`INTEGRATIONS.md:715`) with the confirmation contract. Target: docs
- [ ] T019 Tutorial `docs/tutorials/05-third-party-confirmation-handoff.md` (booking engine on another domain, payment iframe inside a form, deposit then balance with the ads conversion-window caveat) and a line in `docs/tutorials/README.md`; every snippet is asserted in T015 or T017. Target: `docs/tutorials/`
- [ ] T020 Live acceptance (SC-005): real Calendly booking and a Stripe test-mode payment with a minted reference; verify the open vendor facts in research.md. Open: not run.

## Dependencies

T001 → T002. T002 → T003, T004, T009, T012. T005 → T006, T008. T006, T007 → T008. T010, T011 → T012. T012 → T013, T014. T008, T009 → T015. T012, T013 → T016. T008, T012 → T017. T015 to T017 → T018, T019. T020 after T017. Phases 2 and 4 do not depend on each other and can run in parallel after T002.
