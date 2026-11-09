# Implementation Plan: Third-Party Confirmation Handoff (completion signal + reference round trip)

**Branch**: `feat/third-party-confirmation-handoff` | **Date**: 2026-11-04 | **Spec**: [spec.md](spec.md) | **Issue**: none yet

## Summary

Four parts, in dependency order. U1: a data-driven vendor map (`clicutcl_confirmation_message_map`) and a `ClickTrailEvents.confirm()` call turn a documented completion message, a site callback or a return parameter into one consent-gated, deduped, identifier-only event. U2: the spec 008 reference store (shipped in 1.13.0 as `Whatsapp_Ref_Store`, unrenamed; generic names are aliases) is reused, so a `CT-` code minted before the visitor leaves can be passed to a third party as a field it echoes back. U3: an optional Stripe webhook adapter resolves `client_reference_id` to that code and dispatches a deduped server `purchase` (or records the verified payment with attribution `unresolved`). U4: a tutorial with three recipes. Out of scope and listed in [research.md](research.md) R9: Braintree, PayPal, Square and booking-engine adapters.

## Technical Context

**Language/Version**: browser ES2019 (no build step); PHP 8.1+
**Primary Dependencies**: none new; Stripe signatures are checked with `hash_hmac`, no Stripe SDK
**Storage**: table `{prefix}clicutcl_whatsapp_refs` unchanged (shipped in 1.13.0, `DB_VERSION` stays 5; no rename, no view: a view would add privilege and `dbDelta`/eraser/uninstall complexity for no benefit, so the generic names live only on the route, JS and filter surface); `sessionStorage['ct_handoff_ref']` written beside `ct_wa_ref` and `ct_confirm_<id>` (Marketing category, cleared on withdrawal); no new cookie, no new table
**Testing**: `tools/qa/third-party-confirmation.test.js` (Node VM, real `clicutcl-events.js` and `clicutcl-attribution.js`) in `npm run test:consent`; `tools/qa/whatsapp-handoff.test.js` extended for the aliases; PHPUnit `tests/unit/WhatsappRefTest.php` extended (generic route and filter aliases), `tests/unit/StripeWebhookTest.php`, `tests/unit/ConfirmationMapTest.php`; E2E scenario `third-party-handoff` (`tools/qa/e2e/third-party.sh`, `flock /tmp/ct-e2e.lock`); `npm run smoke`
**Constraints**: every new setting off for existing installs; no storage, request or third-party code before consent; no `Set-Cookie` on page views; no outbound request from the webhook; no payment data stored

## Repo gates

- AGENTS.md docs triggers (new settings, filters, REST route, provider, storage key): `docs/reference/INTEGRATIONS.md`, `docs/reference/integration-capabilities.json`, `docs/reference/HOOKS-REFERENCE.md`, `docs/reference/REST-API.md`, `docs/guides/SECURITY-PRIVACY.md` (storage inventory; `tools/qa/cmp-transition.test.js` keeps the key list in sync), `docs/guides/SETTINGS-AND-ADMIN.md`, `docs/architecture/DATA-MODEL.md`, `docs/architecture/CODE-MAP.md`, `docs/tutorials/05-third-party-confirmation-handoff.md`, `readme.txt`, `README.en.md`, `changelog.txt`.
- CodeQL: the return parameter, message paths and `decorateHandoffUrl` input are validated at the sink with an anchored pattern or an exact-host check; no value is written to the DOM or concatenated into a request URL.
- Evidence boundary: source-present / runtime-unverified until SC-005 passes; E2E proves harness behaviour only.

## Scope decisions (confirm before T001)

| Part | Decision | Why |
|---|---|---|
| U1 events | reuse `book_appointment` and `lead`; add `payment_confirmed`, `deposit_paid`, `balance_paid`; never `purchase` from the browser | a message or return parameter is forgeable; `purchase` stays server-confirmed (Woo, EDD, Stripe webhook) |
| U1 callbacks | `ClickTrailEvents.confirm()` | Stripe Embedded Checkout `onComplete` and Cal.com `Cal('on')` are page callbacks, not messages ([research.md](research.md) R2) |
| U2 store | generalise the spec 008 store without renaming it (generic names are aliases, spec FR-007), one row per attribution snapshot, no `purpose` column | the snapshot is purpose-agnostic; one set of ceilings; a second table would duplicate the collision, TTL, eraser and cleanup code; 1.13.0 shipped the WhatsApp names |
| U3 | Stripe only, optional, off by default | signature scheme is symmetric HMAC like the Calendly branch; PayPal needs a certificate fetch (SSRF surface), Square signs over the URL, Braintree publishes no payment-completed hook |
| U4 | one tutorial, three recipes | `docs/tutorials/` is where end-to-end walkthroughs live |

## Design

### Server

| Change | File |
|---|---|
| `Whatsapp_Ref_Store`, table, constants and `find()` stay as shipped in 1.13.0 (no rename, no `DB_VERSION` bump); generic `clicutcl_handoff_ref_*` filters layered on the legacy ones | `includes/database/class-whatsapp-ref-store.php` (filters only), `class-installer.php` untouched |
| Route alias `POST /handoff-ref`, `GET /handoff-ref/<code>` beside the shipped `whatsapp-ref` route (same callbacks); feature gate `whatsapp append OR handoff_refs` | `includes/api/class-tracking-controller.php` (`whatsapp_ref_create_permissions_check` at `:551`, `create_whatsapp_ref` `:590`, `lookup_whatsapp_ref` `:643`) |
| Settings `third_party_confirmations`, `handoff_refs`; fresh-install seed of the first | `includes/admin/class-admin.php` schema near `whatsapp_append_attribution` (`:1233`), `includes/database/class-installer.php` next to `seed_cookie_scope()` (`:95`) |
| `Confirmation_Map::sanitize( array $entries ): array` (closed event set, anchored patterns, depth cap, origin shape) and the default entry list; localize `confirmationMap`, `handoffRefUrl`, `handoffUrlParams` | `includes/Core/class-confirmation-map.php` (new), `includes/class-clicutcl-core.php` (`iframeOrigins` block `:334-345`, `whatsappRefUrl` `:426`) |
| `Stripe-Signature` branch with multi-`v1` | `includes/tracking/class-webhook-auth.php` (next to the Calendly branch `:69`) |
| `Stripe_Webhook_Adapter` (`supports`, `is_ignored_event`, `map_to_canonical`); provider key `stripe` | `includes/tracking/webhooks/class-stripewebhookadapter.php` (new), `provider_adapter()` in the controller (`:923`), `defaults()` and sanitiser in `includes/tracking/class-settings.php` (`:79-91`) |
| Event names in the browser allowlist and funnel lists | `includes/tracking/class-eventv2.php:22`, `class-event-translator-v1-to-v2.php:114`, `:140` |
| Provider row and secret field | `includes/admin/class-admin.php:622`, `:1074`, `assets/js/admin-settings-app.js:505`, `:752` |
| Dedupe: `Claim_Lock::acquire( 'stripe_<md5 session id>' )` then `Dedup_Store` | `includes/tracking/class-claim-lock.php`, `class-dedup-store.php` |

### Browser

| Change | Where |
|---|---|
| `trackExternalFormMessages()` consults `confirmationMap` after the legacy matcher when the setting is on; `confirm()`; `return_param` scan at init; markers `ct_confirm_<id>` cleared with the other markers (`:661`) | `assets/js/clicutcl-events.js` (`:2239`, `:2436`, `:2213`) |
| `buildCanonicalEvent` map gains the three names | same, `:171-196` |
| `WhatsAppRef` gains `requestHandoffRef` and `getHandoffRef` (`getWhatsAppRef` kept as alias; key `ct_handoff_ref` written beside `ct_wa_ref`); `decorateHandoffUrl` next to `decorateEmbedUrl`; the events script calls `getHandoffRef` | `assets/js/clicutcl-attribution.js` (`:1957-2088`, `:1299`, `:1799`), `clicutcl-events.js:907` |

### Contract

```text
message   (origin allowlisted, map entry matches, consent) -> dataLayer {event:'book_appointment', lead_context:{provider:'calendly', submit_status:'success'}, event_id}
confirm   ClickTrailEvents.confirm({provider, kind, id})     -> same push; kind -> event: lead|booking|payment|deposit|balance
return    ?session_id=cs_live_... matches entry pattern      -> payment_confirmed {transaction_id}
mint      await ClickTrail.requestHandoffRef()               -> 'CT-ABCD2345' | ''      POST /clicutcl/v2/handoff-ref (spec 008 gates)
decorate  ClickTrail.decorateHandoffUrl('https://buy.stripe.com/x') -> ...?client_reference_id=CT-ABCD2345
webhook   POST /clicutcl/v2/webhooks/stripe  Stripe-Signature: t=<unix>,v1=<hex>[,v1=<hex>]
          -> 401 bad/stale signature | 200 {ignored:true} | 200 {success:true, attribution:'unresolved'} | 200 {success:true, event_name:'purchase', event_id:'stripe_<md5 session id>'}
```

## Risks

- 1.13.0 shipped the WhatsApp route, table and key, so none is renamed: the generic names are aliases for at least one release and removal is a later, separately announced decision ([research.md](research.md) R10).
- The vendor map is code that runs on every `message` event: it is cheap (one origin check first, one lookup), and the closed event set plus anchored patterns keep a bad filter value from widening what is emitted.
- A consented reference row outlives a later withdrawal (retention TTL, same as spec 008 R10); a webhook after withdrawal still dispatches with the consent captured at mint ([research.md](research.md) open question 3).
- Stripe API versions change event shapes; the adapter reads only `id`, `type`, `livemode`, `data.object.payment_status`, `client_reference_id`, `amount_total`, `currency` and `metadata.ct_event` and ignores the rest, and PHPUnit holds one fixture per documented shape.
- Calendly's documented message and its invitee URI form may not match the webhook's invitee URI, so the browser and webhook `book_appointment` for one booking are separate events (same caveat as HubSpot in `INTEGRATIONS.md`); enabling both needs the same note.
