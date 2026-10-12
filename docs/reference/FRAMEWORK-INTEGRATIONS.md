# Framework and external integrations

This document explains how non-WordPress frontends use ClickTrail's browser
contract. It is canonical for Angular, React, Vue, Svelte, plain JavaScript,
payment-return, webhook, and Apointoo handoff boundaries.

ClickTrail does not ship native Angular, React, Vue, or Svelte SDKs in this
repository. These frameworks use the host site's `window.dataLayer` and the
same event envelope. The integration status remains **GTM-mediated** or
**source-present / runtime-unverified** unless a separate runtime proof says
otherwise.

## Choose the path

| Surface | Correct path | Current boundary |
| --- | --- | --- |
| Angular, React, Vue, Svelte, plain JavaScript | Push the documented event shape to the site's `window.dataLayer` | No framework-specific ClickTrail package is shipped here |
| Next.js or another server-rendered JavaScript app | Push browser events from the client; keep secrets and outcome confirmation server-side | Browser and server paths must not be conflated |
| WordPress forms | Use the supported form adapter and its documented field/storage pattern | Source connector / runtime-unverified |
| WooCommerce | Let ClickTrail capture order attribution and optionally emit purchase/storefront events | Source connector / runtime-unverified; see WooCommerce gates |
| Google Tag Manager | Use the site-owned container or import the checked-in starter kit | GTM-mediated; provider delivery is separate |
| Stripe Checkout, PayPal, Mollie, Square | Preserve the ClickTrail cookie before hosted checkout; read it on the return page | Hosted payment pages are not native ClickTrail adapters |
| Calendly, HubSpot, Typeform | Use the documented inbound webhook route where applicable | Webhook ingress / runtime-unverified |
| Apointoo | Treat Apointoo as a separate server-side attribution and outcome system | No ClickTrail-Apointoo adapter is claimed by this repository |

Provider delivery keys such as Meta CAPI, Google Ads, LinkedIn CAPI, Pinterest,
and TikTok remain configured-endpoint adapters until provider authentication,
acceptance, retries, consent transitions, and destination receipts are tested.
See the [integration reference](INTEGRATIONS.md) and its
[machine-readable ledger](integration-capabilities.json).

## Browser contract

Every framework must publish a top-level `event`. Events that need identity or
deduplication also publish `event_id` and a matching `marketing_trail.event_id`.
Do not put raw email, phone, clinical detail, payment secrets, or access tokens
in the data layer.

```js
window.dataLayer = window.dataLayer || []
window.dataLayer.push({
  event: 'generate_lead',
  event_id: 'evt_example_001',
  form_id: 'contact',
  marketing_trail: {
    schema_version: 1,
    event_id: 'evt_example_001',
    event_name: 'generate_lead',
    consent: { analytics: true, advertising: false },
  },
})
```

Use `ct_page_view` for a WordPress-originated page event and `page_view` for a
JavaScript-originated page event. The checked-in GTM starter kit accepts both.
Use the [marketing trail envelope](MARKETING-TRAIL-ENVELOPE.md) for the full
field contract.

## Framework recipes

The helper is ordinary browser JavaScript, so framework choice changes where
it is called, not the event contract.

### Angular

Keep the helper in a client-side service or utility. Call it after the user
action is known, not during server-side rendering.

```ts
type ClickTrailWindow = Window & { dataLayer?: Record<string, unknown>[] }

export function pushClickTrailEvent(event: Record<string, unknown>) {
  if (typeof window === 'undefined') return
  const browserWindow = window as ClickTrailWindow
  browserWindow.dataLayer = browserWindow.dataLayer || []
  browserWindow.dataLayer.push(event)
}

const eventId = crypto.randomUUID()

pushClickTrailEvent({
  event: 'generate_lead',
  event_id: eventId,
  form_id: 'contact',
})
```

Declare `window.dataLayer` in the app's TypeScript globals if strict typing
requires it. Send the same generated ID in the confirmed API request and gate
advertising fields through the chosen consent source.

### React, Vue, Svelte, and plain JavaScript

- **React:** call the helper from the submit handler or a client-only effect;
  do not emit a lead from a render path.
- **Vue:** call it from the form action or a composable after successful
  client-side validation.
- **Svelte:** call it from the submit handler; keep browser access out of SSR
  module initialization.
- **Plain JavaScript:** call it after the event is confirmed and before a
  redirect, allowing the tag to flush where the destination requires it.

All four use the same top-level `event`, `event_id`, consent, and envelope
rules. A framework wrapper that changes field names is not compatible with the
starter kit until its GTM mappings are updated and checked.

## Payment and webhook paths

### Hosted payments

Stripe Checkout, PayPal, Mollie, and Square host payment pages outside the
site. Query decoration cannot be relied on across those domains. Capture the
visitor context before redirecting and reconcile the return page or a verified
server webhook with the stored cookie/session. ClickTrail does not claim a
native Stripe or PayPal SDK integration.

Never treat a browser success page as the authoritative paid outcome when the
payment provider exposes a signed server notification. Keep provider secrets
server-side, verify signatures in the receiving system, make processing
idempotent, and pass only the minimum canonical event data downstream.

### Inbound webhooks

The current documented webhook sources are Calendly, HubSpot, and Typeform.
Their routes translate incoming data into ClickTrail's canonical event model;
provider timestamp, identity minimization, replay, consent, and end-to-end
delivery still require runtime verification.

### Apointoo handoff

Apointoo has its own SDK, GTM contract, server confirmation, and integration
catalog. Pairing the products means agreeing the handoff fields and owner:

1. ClickTrail captures the browser journey and emits the canonical event.
2. The site or server integration forwards only the fields Apointoo accepts.
3. Apointoo confirms the business outcome server-side.
4. Each system keeps its own receipt and deduplication evidence.

Do not describe this as a native ClickTrail adapter until a versioned contract,
authenticated transport, replay behavior, and staged proof exist in both
repositories.

## Verification checklist

1. Confirm the app emits exactly one top-level `event` per user action.
2. Confirm `event_id` is stable across the browser event, form/order record,
   and any server handoff.
3. Check GTM Preview for the expected custom event and resolved data-layer
   variables.
4. Confirm the selected consent source controls advertising destinations.
5. Confirm hosted payment returns or webhooks reconcile to the pre-payment
   attribution record.
6. Record provider receipts separately from source presence or GTM Preview.

For the local GTM contract, run:

```sh
node examples/validate-gtm-starter-kit.mjs
```
