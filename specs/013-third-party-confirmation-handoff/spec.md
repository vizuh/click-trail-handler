# Feature Specification: Third-Party Confirmation Handoff (completion signal + reference round trip)

**Feature Branch**: `feat/third-party-confirmation-handoff`

**Created**: 2026-11-04

**Status**: Draft, not started. Every claim below is source-present / runtime-unverified: the current behaviour was read from `main` @ `e00552c` (1.12.0 plus unreleased 1.13 work), vendor behaviour from vendor documentation on 2026-11-04 (see [research.md](research.md)), and nothing was run against a live vendor. Proof will come from Node VM and PHPUnit tests and from stub pages and a signed stub webhook in the real-WordPress harness (`tools/qa/e2e/`); live vendor acceptance (SC-005) stays open.

**Input**: field patterns seen on sites that sell or book through a surface the site does not own: a booking engine on another domain whose confirmation page is not the site's; a form that embeds a payment step (iframe or hosted page) so the form's own success listener never fires; deposit bookings that are counted only when paid in full; purchases confirmed on the processor's domain. ClickTrail today decorates links, signs a cross-domain token, stamps Calendly links, decorates allowlisted embed URLs (`window.ClickTrail.decorateEmbedUrl()`), listens to a fixed set of external form messages and ingests Calendly, HubSpot and Typeform webhooks. There is no documented way to (a) carry attribution into a third-party checkout or booking and get the confirmation back keyed to the same visitor, or (b) fire the conversion when the third party signals completion. Related: [../001-calendly-booking-handoff/](../001-calendly-booking-handoff/), [../003-hubspot-form-capture/](../003-hubspot-form-capture/), [../008-whatsapp-handoff/](../008-whatsapp-handoff/) (reference-code store this spec generalises).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A documented completion message becomes one event (Priority: P1)

A visitor with consent completes a booking inside an iframe whose origin is allowlisted. The vendor posts a documented message to the parent page; ClickTrail matches it against a data-driven vendor map and pushes one event carrying identifiers only.

**Why this priority**: today `normalizeExternalMessage` (`assets/js/clicutcl-events.js:2436`) matches a hard-coded list of type strings. Calendly's documented message (`calendly.event_scheduled`, carried in `data.event`) is not in it, so a default-allowlisted Calendly embed produces nothing.

**Independent Test**: Node VM test (`tools/qa/third-party-confirmation.test.js`) loading the real `clicutcl-events.js`: dispatch `message` events with chosen `origin` and `data`, assert the `dataLayer` pushes and `markOnce` behaviour.

**Acceptance Scenarios**:

1. **Given** `third_party_confirmations` on, consent granted, origin `https://calendly.com` and `data = { event: 'calendly.event_scheduled', payload: { invitee: { uri: <uri> } } }`, **Then** exactly one `book_appointment` is pushed with `lead_context.provider = calendly`, `lead_context.submit_status = success` and the invitee URI as the dedupe ID, and no other field of `data` is read.
2. **Given** the same message from an origin outside `isAllowedOrigin()`, a sibling-host look-alike (`calendly.com.evil.test`), or a message whose `event` differs, **Then** nothing is pushed and no marker is written.
3. **Given** consent denied or unresolved, **Then** nothing is pushed and no dedupe marker is left behind.
4. **Given** the same invitee URI twice, **Then** the second push is dropped (`markOnce`, no window), while a different URI pushes again.
5. **Given** the setting off (existing installs), **Then** the vendor map is not consulted and the legacy `normalizeExternalMessage` behaviour is byte-identical.
6. **Given** a site adds an entry through `clicutcl_confirmation_message_map`, **Then** a matching message from an allowlisted origin pushes the entry's event; an entry naming an event outside the closed set, a bad path or an origin outside the allowlist is dropped by the PHP sanitiser and never reaches the page.

---

### User Story 2 - A site callback or a return URL reports completion (Priority: P1)

Some vendors publish a JavaScript callback on the parent page (Stripe Embedded Checkout `onComplete`, Cal.com `Cal('on', ...)`) or only redirect the visitor back with an identifier in the query string. The site reports the completion in one line, or ClickTrail reads a configured return parameter.

**Independent Test**: same VM harness; call `window.ClickTrailEvents.confirm(...)` and load pages with chosen `location.search`.

**Acceptance Scenarios**:

1. **Given** the setting on and consent granted, **When** `ClickTrailEvents.confirm({ provider: 'stripe', kind: 'payment', id: 'cs_live_a1b2' })` runs, **Then** one `payment_confirmed` is pushed with `transaction_id = cs_live_a1b2` and no value, currency or customer field; a second identical call returns `false`.
2. **Given** `kind` outside `lead | booking | payment | deposit | balance`, an `id` that fails `^[A-Za-z0-9._:/@=-]{1,128}$`, or consent not granted, **Then** it returns `false`, pushes nothing and writes no storage.
3. **Given** a `return_param` map entry (parameter `session_id`, pattern `^cs_(live|test)_[A-Za-z0-9]{8,200}$`, event `payment_confirmed`) and a page URL whose parameter matches, **Then** one event is pushed and `sessionStorage['ct_confirm_<id>']` (written only after the consent check) stops a reload from pushing again; a value that fails the pattern pushes nothing.
4. **Given** consent withdrawal, **Then** `ct_confirm_*` keys are removed with the other event markers.
5. **Given** the deposit pattern, **Then** `kind: 'deposit'` pushes `deposit_paid` and `kind: 'balance'` pushes `balance_paid`, each deduped on its own ID; ClickTrail attaches no value (the site's GTM or own code decides).

---

### User Story 3 - Attribution goes into the third party and a reference comes back (Priority: P1, only when `handoff_refs` is on)

Before the visitor leaves, the site obtains a server-stored short reference for the current attribution and gives it to the third party as a field the vendor echoes back.

**Independent Test**: VM harness plus PHPUnit (`tests/unit/HandoffRefTest.php`, the renamed `WhatsappRefTest.php`).

**Acceptance Scenarios**:

1. **Given** the setting on, consent granted and a stored attribution, **When** `await ClickTrail.requestHandoffRef()` runs, **Then** it resolves to a confirmed `CT-<8 base32>` code (same mint, retry and tab-session reuse as spec 008), or `''` when anything fails; it never resolves with an unconfirmed code.
2. **Given** `ClickTrail.decorateHandoffUrl('https://buy.stripe.com/x')` and the host in **Embedded widget hosts**, **Then** `client_reference_id=CT-...` is added; for another listed host the parameter is `ct_ref`; non-https, unlisted hosts, a URL that already has the parameter, no confirmed code or denied consent return the input unchanged.
3. **Given** `POST /clicutcl/v2/handoff-ref`, **Then** it enforces the spec 008 gates (feature flag, 16 KB cap, rate limit, client token, nonce limit, consent from the request cookie, 20 rows per visitor, 50 000 rows) and answers 409 on a collision.
4. **Given** an administrator, **Then** `GET /clicutcl/v2/handoff-ref/<code>` returns the snapshot; unknown or expired gives 404.

---

### User Story 4 - A signed Stripe webhook becomes a deduped server `purchase` (Priority: P2, optional, off by default)

**Independent Test**: PHPUnit (`tests/unit/StripeWebhookTest.php`) with signature vectors; E2E scenario `third-party-handoff` posts a stub-signed payload.

**Acceptance Scenarios**:

1. **Given** the Stripe provider enabled with a `whsec_` secret, **When** a `checkout.session.completed` with `payment_status = paid`, `livemode = true` and `client_reference_id = CT-ABCD2345` arrives with a valid `Stripe-Signature` inside the tolerance, **Then** one `purchase` is dispatched with the stored click IDs and touches, `commerce_context` holding only `transaction_id` (session ID), `value` and `currency`, and the response is `{ success: true }`.
2. **Given** a tampered body, a stale timestamp, a missing or `v0`-only signature, an unknown reference, a reference of another purpose, `payment_status` other than `paid`, or `livemode = false`, **Then** the response is 401 for signature failures and `{ success: true, ignored: true }` for the rest, and nothing is dispatched.
3. **Given** a redelivery, or `checkout.session.completed` and `checkout.session.async_payment_succeeded` for one session, **Then** one event is dispatched.
4. **Given** `metadata.ct_event = deposit_paid` or `balance_paid`, **Then** that name replaces `purchase`; any other value is ignored and `purchase` is used.

---

### User Story 5 - Recipes for the three patterns (Priority: P2)

`docs/tutorials/05-third-party-confirmation-handoff.md` gives copy-able steps for a booking engine on another domain, a payment iframe inside a form and deposit-then-balance bookings, each ending in a check an operator can run. Independent Test: every snippet in the tutorial is exercised by the VM test or the E2E stub pages (SC-004).

### Edge Cases

- Stripe retries a failed delivery with a new timestamp and signature; the replay cache keys on the signature, so event-level dedupe (session ID) is what stops double counting.
- A site that takes Stripe payments through WooCommerce (or EDD once #204 lands) already gets a server `purchase`; those sessions carry no `CT-` reference, so the adapter ignores them and nothing double counts.
- A visitor can edit a payment-link URL parameter and misattribute their own purchase; the reference only selects stored attribution, it never carries value.
- A message ID or return parameter is forgeable by the visitor: browser confirmations are signals, never purchase-grade; purchase-grade value comes only from the signed webhook or WooCommerce/EDD.
- A deposit booking and its balance payment are separate sessions with separate IDs; ad platforms may reject a conversion older than their window, which the site owns (tutorial caveat).
- Vendors with no documented browser contract (Acuity, Braintree drop-in, PayPal `onApprove`) are covered only by the site callback or not at all; see [research.md](research.md) R2.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: With `third_party_confirmations` on, `clicutcl-events.js` MUST match `message` events against `clicutcl_confirmation_message_map` (localized as `confirmationMap`) only after `isAllowedOrigin()` passes, and MUST push the entry's event through `pushEvent` after the consent check and `markOnce`; the legacy matcher stays unchanged and always on.
- **FR-002**: Entries MUST name an event from the closed set `lead`, `book_appointment`, `payment_confirmed`, `deposit_paid`, `balance_paid`; id and type values come from dot paths of depth at most 4 over own properties; the pushed event MUST carry `provider`, `submit_status`/`transaction_id` and the vendor ID only, never a field value, amount, email or free text.
- **FR-003**: `payment_confirmed`, `deposit_paid` and `balance_paid` MUST be added to `EventV2::BROWSER_EVENT_NAMES`, the `buildCanonicalEvent` map (`bottom`) and the translator's bottom-funnel and converted lists; `purchase` MUST NOT be accepted from the browser.
- **FR-004**: `window.ClickTrailEvents.confirm({ provider, kind, id })` MUST be defined at script load, delegate to the tracker once it exists, apply FR-002, the consent gate and `markOnce`, return a boolean (`false` before consent or with the setting off), and never throw; it is a separate global because `clicutcl-attribution.js` assigns the whole `window.ClickTrail` object.
- **FR-005**: `return_param` entries MUST validate the parameter against the entry pattern before use, write `ct_confirm_<id>` to `sessionStorage` only after consent, and never place the value in the DOM or a request URL.
- **FR-006**: New settings `third_party_confirmations` (default 0, seeded 1 on fresh installs only), `handoff_refs` (default 0, never seeded) and the Stripe provider `external_forms.providers.stripe` (`enabled` 0, `secret` ''); existing installs keep today's behaviour.
- **FR-007**: `Whatsapp_Ref_Store`, table `clicutcl_whatsapp_refs`, route `/whatsapp-ref`, key `ct_wa_ref` and the `clicutcl_whatsapp_ref_*` filters MUST be renamed to `Handoff_Ref_Store`, `clicutcl_handoff_refs`, `/handoff-ref`, `ct_handoff_ref` and `clicutcl_handoff_ref_*`, with every caller migrated and no alias, if 1.13 has not shipped (otherwise see [plan.md](plan.md) Risks).
- **FR-008**: The mint route MUST be allowed when WhatsApp append or `handoff_refs` is on, keep every spec 008 gate and ceiling, and require marketing consent from the request cookie.
- **FR-009**: `ClickTrail.requestHandoffRef()`, `getHandoffRef()` (replaces `getWhatsAppRef`) and `decorateHandoffUrl(url)` MUST use only a server-confirmed code and the existing embed host list (exact host, https, signed URLs skipped, live consent).
- **FR-010**: `Webhook_Auth::verify_request()` MUST verify `Stripe-Signature`: `t=` plus every `v1=`, HMAC-SHA256 of `t.body` with the endpoint secret, ignore other schemes, constant-time compare against each `v1`, tolerance from `clicutcl_webhook_replay_window` (default 300 s, never 0).
- **FR-011**: `Stripe_Webhook_Adapter` MUST handle `checkout.session.completed` and `checkout.session.async_payment_succeeded` with `payment_status = paid` and `livemode = true`, require `client_reference_id` matching `^CT-[A-Z2-7]{8}$` and resolving to a live row, and make no outbound request.
- **FR-012**: The mapped event MUST store `transaction_id`, `value` (from the minor-unit amount and the currency exponent) and `currency` only; no customer, address, card, email or payment-method field is read.
- **FR-013**: Docs MUST be updated: `INTEGRATIONS.md` (confirmation contract, vendor table, Stripe provider), `integration-capabilities.json` (`source.webhook.stripe`), `HOOKS-REFERENCE.md` (new filters), `REST-API.md`, `SECURITY-PRIVACY.md` (storage inventory, retention, network), `SETTINGS-AND-ADMIN.md`, `DATA-MODEL.md`, `docs/tutorials/05-...`, `readme.txt`, `README.en.md`, `changelog.txt`.

### Key Entities

- **Vendor map entry**: `{ id, kind: message | return_param, origins, type_path, type_value, id_path, param, param_pattern, event, provider }`, sanitised in PHP.
- **Confirmation event**: `book_appointment | lead | payment_confirmed | deposit_paid | balance_paid` with `provider` and an opaque vendor ID.
- **Handoff reference**: the spec 008 reference row, now purpose-agnostic.

## Success Criteria *(mandatory)*

- **SC-001**: VM test: the Calendly message yields one `book_appointment`; wrong origin, wrong event, denied consent and a repeat yield none; `confirm()` and a return parameter behave as US2; setting off keeps the legacy output byte-identical; no pushed object contains a field outside FR-002.
- **SC-002**: PHPUnit: Stripe vectors (valid, tampered body, wrong secret, stale and future timestamp, `v0` only, two `v1` with the second valid, missing header), reference lookup, expiry, consent snapshot, `payment_status`, `livemode`, dedupe, closed event set, minor-unit exponents (USD, JPY, KWD), mint-route gates and ceilings.
- **SC-003**: E2E `third-party-handoff` (under `flock /tmp/ct-e2e.lock`): a stub page on a second host posts the Calendly-shaped message and the event reaches the stored events; a stub-signed `checkout.session.completed` for a minted reference is dispatched once and carries the click ID.
- **SC-004**: every tutorial snippet runs unchanged in the VM test or the stub pages.
- **SC-005**: Live (open): a real Calendly booking and a real Stripe test-mode payment with a minted reference produce the events. Until then the feature stays source-present / runtime-unverified.

## Assumptions

- The origin allowlist (`clicutcl_iframe_origin_allowlist`) stays the opt-in for which hosts may post; the map never widens it.
- A reference row exists only for a visitor who consented at mint time; the adapter treats that as the consent snapshot, like the Calendly `c=1` stamp.
- Stripe test-mode events are ignored in production by default.
