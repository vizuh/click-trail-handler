# Research: Calendly Booking Attribution Handoff

## Current state (source, `main` at `b674c15`)

| Surface | File | Finding |
|---|---|---|
| Outbound decoration | `assets/js/clicutcl-attribution.js` (`Decorator.decorateUrl`) | Appends `utm_*` plus 16 click-ID keys to allowed outbound hosts, and optionally `ct_token`. There is no provider-specific shaping. Anchor-click only. |
| Webhook mapping | `includes/tracking/webhooks/class-calendlywebhookadapter.php` | Maps `event`, `payload.uri` and `payload.email` only. `payload.tracking` is ignored. |
| Webhook auth | `includes/tracking/class-webhook-auth.php` | Branches exist for `typeform-signature` and `x-hubspot-signature`. Everything else uses `x-clicutcl-timestamp` / `x-clicutcl-signature`, so Calendly's own signature header is never checked. |
| Signed token | `includes/api/traits/trait-tracking-controller-attribution-token.php` | HMAC-signed base64url JSON holding the attribution (click IDs included). Default TTL 15 min, filterable up to 90 days. The payload is readable, not encrypted. |
| Storage / retention | `includes/database/class-installer.php` (`DB_VERSION = 4`), `includes/utils/class-cleanup.php`, `includes/privacy/class-privacy-handler.php` | Existing patterns for dbDelta tables, daily cleanup batches, and email-keyed erasure via hashed email. |

## R1 — What Calendly preserves

**Decision**: Rely on `utm_campaign`, `utm_source`, `utm_medium`, `utm_content`, `utm_term` and `salesforce_uuid` only.
**Rationale**: These are the query parameters Calendly documents as copied into the invitee's `tracking` object. Other parameters, including `gclid`, are not carried into the webhook.
**Verify before implementation**: re-read Calendly's current "UTM tracking" / webhook payload docs and capture a real `invitee.created` payload (task T001). If Calendly adds native click-ID tracking, FR-001 still holds, because the reference is the contract.

## R2 — Reference format and length

**Decision**: a 22-character base64url random ID (128 bits, from `random_bytes(16)`).
**Rationale**: It is short enough for any plausible field limit and carries nothing sensitive. The `salesforce_uuid` length limit is undocumented; T001 measures it with a real booking.
**Rejected**: putting the existing signed token in `salesforce_uuid`. It is long, it exposes the click IDs in readable base64 to Calendly and to anyone who sees the URL, and its 15-minute default TTL is shorter than typical click-to-booking gaps.

## R3 — Where the handoff record lives

**Decision**: a new table `{prefix}clicutcl_handoffs`, added with `DB_VERSION` 4 → 5 through the existing Installer pattern. Columns: `ref` (unique), `blog_id`, `attribution` (JSON), `consent` (JSON), `hashed_email` (nullable, set when a webhook resolves it), `created_at`, `expires_at`.
**Rationale**: It needs lookup by reference, erasure by hashed email, and batch expiry. Transients can't be erased by email, and the touch-events table has no click-ID columns.
**Retention**: expire after the attribution cookie duration (the same setting `Cleanup::run_cleanup()` already uses, default 90 days).

## R4 — Minting timing

**Decision**: pre-mint when consent is granted and the page contains a Calendly link or widget. This follows the `Store.prepareSignedToken()` pre-fetch pattern, so decoration at click time stays synchronous.
**Endpoint**: `POST /wp-json/clicutcl/v2/attribution-handoff`. It uses the same client-token, body-size, rate-limit and per-token-nonce gates as `attribution-token/sign`. The server re-checks the consent snapshot and refuses to mint without granted marketing consent.

## R5 — Calendly webhook signature

**Decision**: add a `calendly` branch that parses `Calendly-Webhook-Signature: t=<unix>,v1=<hex>` and compares `hash_hmac('sha256', "<t>.<raw body>", signing_key)` with `hash_equals`. Replay window: reuse the existing `$max` tolerance.
**Verify before implementation**: confirm the header format against Calendly's webhook signature docs (task T001). The format above matches Calendly's published scheme at the time of writing, but it has not been checked against a live delivery.

## R6 — Consent semantics

The consent that applies is the snapshot stored with the record at mint time. On top of that, a live withdrawal propagates by deleting the visitor's handoff rows. This fits the withdrawal handling in 1.10.2, where denial clears browser state. The server side needs a new hook: the browser calls the existing withdrawal path, and the handler deletes rows whose `ref` the browser holds. It never mints while consent is pending or denied.
