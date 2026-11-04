# Research: Third-Party Confirmation Handoff (completion signal + reference round trip)

Source read on 2026-11-04 (`main` @ `e00552c`, 1.12.0 plus unreleased 1.13 work). Vendor pages were fetched on the same day. Nothing below was run against a vendor; every row is source-present / runtime-unverified. A fact I could not read is marked `unverified`, not guessed.

## Current state

| Surface | File | Finding |
|---|---|---|
| Message listener | `assets/js/clicutcl-events.js:2239` `trackExternalFormMessages` (started at `:84`) | One `message` listener. `isAllowedOrigin()` (`:2510`) first, then `normalizeExternalMessage()`; HubSpot legacy callback has its own branch; leads dedupe through `pushLeadOnce`, other events through `markOnce(name\|id)` (`:2272`). |
| Origin rule | `:2510-2530`; `includes/class-clicutcl-core.php:334-345` | Same host, or a host equal to or ending in `.<entry>` of `clicutcl_iframe_origin_allowlist` (default `calendly.com`, `typeform.com`, `hubspot.com`, `hsforms.net`). Filter only, no admin setting. |
| Type table | `:2436-2475` | `type = data.type \|\| data.event`, lowercased (`:2445`), matched against fixed lists (`:2452-2464`): lead `typeform-submit`, `hubspot-form-submit`, `clicktrail.lead`, `clicutcl.lead`, `lead`; booked `calendly-booked`, `book_appointment`, `clicktrail.book_appointment`, `clicutcl.book_appointment`. ID from `event_id \|\| id \|\| token`. `calendly.event_scheduled` (Calendly's documented value, V1 below) is not in the lists. |
| Dedupe | `:2307` `markOnce`, `:2320` `pushLeadOnce` | In-memory `Map` for the page's lifetime, optional window; consent is checked before the marker is written. |
| Canonical map | `:171-196` `buildCanonicalEvent`; `includes/tracking/class-eventv2.php:22-45` | Includes `lead`, `book_appointment`, `contact_whatsapp_start`; no payment event; `purchase` is not a browser event. |
| Return-page lead | `:2213` `trackThankYouLead`, key `ct_thankyou_lead_<path>` (`:2225`, cleared `:661`) | Path matcher only; no identifier read from the URL. |
| Embed URLs | `assets/js/clicutcl-attribution.js:1799` `decorateEmbedUrl`; `includes/class-clicutcl-core.php:437`, `:455` | Exact host, https, last-touch UTMs and one click ID; no visitor ID, no reference. |
| Calendly stamp | `clicutcl-attribution.js:1837` `calendlyStamp`; `includes/tracking/webhooks/class-calendlywebhookadapter.php:60` `parse_stamp` | `ct1;<click_id>=<v>;c=<0\|1>` in `salesforce_uuid`; the webhook reads it back. The only existing round trip. |
| Webhook ingress | `includes/api/class-tracking-controller.php:144-153` route, `:665` permissions, `:743` ingest, `:923` adapters; `includes/tracking/class-webhook-auth.php:69-85` | Providers `calendly`, `hubspot`, `typeform`; enablement and secret in `Tracking\Settings` (`:79-91`, `:267`, `:294`); Calendly's `t=,v1=` branch keeps one `v1` (the parser overwrites a repeated key), replay cache keyed on the signature, tolerance `clicutcl_webhook_replay_window` (default 300 s). |
| Server dedupe | `includes/tracking/class-dedup-store.php:38-104`, `class-claim-lock.php:43` | `Dedup_Store` is read then set (not atomic); `Claim_Lock::acquire()` is the atomic claim the Woo and form paths use. |
| Server purchases | `includes/integrations/class-woocommerce.php:488` (`purchase_<order id>`) | Woo orders dispatch their own server `purchase`; EDD does the same once PR #204 lands (not on `main` yet). |
| Reference store | `includes/database/class-whatsapp-ref-store.php:120` `build_snapshot`, `:197` `create`, `:267` `find`; controller `:551`, `:590`, `:643` | Purpose-agnostic row (snapshot, selected click ID, `visitor_id`, TTL = retention, ceilings 20 / 50 000). Only the names and the feature gate say WhatsApp. |
| Payment docs | `docs/reference/INTEGRATIONS.md:715` | States that decoration cannot reach hosted payment pages and that order metadata plus ignored referrers keep attribution; says nothing about the confirmation coming back. |

## Vendor facts (fetched 2026-11-04)

| Vendor | Completion signal | Documented contract? | Echoes a custom reference? |
|---|---|---|---|
| Calendly embed | `window.postMessage` to the parent, `event: "calendly.event_scheduled"` | yes (V1) | `utm_*` from the page URL, 255 chars (V2); `salesforce_uuid` is what spec 001 uses (limit unverified) |
| Cal.com embed | page callback `Cal('on', { action: 'bookingSuccessfulV2' })`, not a documented postMessage | yes, as a callback (V3) | unverified |
| Stripe Checkout / Payment Links | redirect to `success_url` / `return_url` with `{CHECKOUT_SESSION_ID}`; `checkout.session.completed` webhook | yes (V4, V6) | yes: `client_reference_id`, `metadata` (V5) |
| Stripe Payment Element | `return_url` with `payment_intent` and `payment_intent_client_secret`; no redirect with `redirect: 'if_required'` | yes (V7) | own query parameters on `return_url` persist (V7) |
| Stripe Embedded Checkout | page callback `onComplete` | yes (V8) | via the Session (`client_reference_id`) |
| PayPal | `onApprove` fires on approval, before capture; `PAYMENT.CAPTURE.COMPLETED` webhook | yes, but approval is not completion (V9) | `custom_id` up to 255 chars on the purchase unit; presence in the webhook resource unverified |
| Braintree Drop-in | `requestPaymentMethod` returns a nonce for the merchant's server; the sale is the merchant's own call | no browser completion contract; no payment-succeeded hook in the webhook list (V10) | not applicable |
| Square | optional `redirect_url`, else Square's own page; webhook event name unverified | partly (V11) | `reference_id` on the order: unverified |
| Acuity | custom conversion code loaded in a frame on the confirmation | no postMessage contract (V12) | unverified |
| SimplyBook, Mindbody | none found | `unverified`: the SimplyBook "Booking widget events" page is empty; the Mindbody help pages did not render | unverified |

- **V1** https://developer.calendly.com/api-docs/overview/embedding/notifying-the-parent-window : "We use `window.postMessage()` to post events to the parent window." Payload: `{ event: "calendly.event_scheduled", payload: { event: { uri }, invitee: { uri } } }`; the page's own listener example is `e.data.event.indexOf('calendly') === 0`.
- **V2** https://developer.calendly.com/api-docs/overview/embedding/recipes : "If you've added the embed code to your website, it will automatically look for these UTM parameters in the parent page URL" (`utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term`); "There is a 255 character limit for UTM values."
- **V3** https://cal.com/help/embedding/embed-events : `bookingSuccessfulV2` "*It deprecates bookingSuccessful event.*" "When a fresh booking is successfully done. It might not be confirmed."; properties include `uid`, `status`, `paymentRequired`. Usage: `Cal("on", { action, callback })`, or `Cal.ns.<namespace>('on', ...)` when the snippet used a namespace.
- **V4** https://docs.stripe.com/api/checkout/sessions/create : `client_reference_id` "A unique string to reference the Checkout Session. This can be a customer ID, a cart ID, or similar, and can be used to reconcile the session with your internal systems." "The maximum length is 200 characters." https://docs.stripe.com/checkout/fulfillment : "Include the `{CHECKOUT_SESSION_ID}` placeholder in your page’s URL, which is replaced with the Checkout Session ID when your customer completes the checkout process."
- **V5** https://docs.stripe.com/payment-links/url-parameters : "`client_reference_id` can be composed of alphanumeric characters, dashes, or underscores, and be any value up to 200 characters." and "If you add this parameter to your payment link, it’s sent in the checkout.session.completed webhook after payment completion." Payment links also take `utm_*` and return them on a redirect confirmation. The documented host is `buy.stripe.com`.
- **V6** https://docs.stripe.com/webhooks : "Currently, the only valid live signature scheme is `v1`." "To prevent downgrade attacks, ignore all schemes that aren’t `v1`." Header `t=<unix>,v1=<hex>,v0=<hex>`; signed payload is the timestamp, `.`, then the raw body; "You can have multiple signatures with the same scheme-secret pair when you roll an endpoint’s secret"; "Our libraries have a default tolerance of 5 minutes"; "Don’t use a tolerance value of `0`."; "If Stripe retries an event ... we generate a new signature and timestamp for the new delivery attempt."; "Stripe requires the raw body of the request"; retries "for up to three days"; "In some cases, two separate Event objects are generated and sent. To identify these duplicates, use the ID of the object in `data.object` along with the `event.type`." `payment_status` (https://docs.stripe.com/api/checkout/sessions/object) is "one of `paid`, `unpaid`, or `no_payment_required`"; delayed methods "generate a checkout.session.async_payment_succeeded event when payment succeeds later" (fulfillment page). Currency amounts are in the "currency’s minor unit" (https://docs.stripe.com/currencies), 500 JPY is `500`; ISK and HUF have special cases.
- **V7** https://docs.stripe.com/payments/accept-a-payment?payment-ui=elements&api-integration=paymentintents : "You can also append your own query parameters when providing the `return_url`, which persist through the redirect process." "`payment_intent_client_secret`: The client secret of the `PaymentIntent` object." "If you don’t want to redirect for card payments after payment completion, you can set redirect to `if_required`."
- **V8** https://docs.stripe.com/payments/checkout/custom-success-page?payment-ui=embedded-page : "`onComplete` is called when the Checkout Session completes successfully, or when the checkout.session.completed webhook event is sent."
- **V9** https://developer.paypal.com/sdk/js/reference : `onApprove` "Called when the buyer approves the payment in the PayPal flow. This is where you should capture or authorize the payment on your server." https://developer.paypal.com/api/rest/webhooks/event-names/ : "PAYMENT.CAPTURE.COMPLETED A payment capture completes." https://developer.paypal.com/api/rest/webhooks/rest : "utilize the public key in the certificate file specified by the header `paypal-cert-url`. This file should automatically be downloaded and cached". `customId`: https://github.com/paypal/PayPal-PHP-Server-SDK/blob/main/doc/models/purchase-unit-request.md "The API caller-provided external ID. Used to reconcile client transactions with PayPal transactions ... not visible to the payer."
- **V10** https://developer.paypal.com/braintree/docs/guides/drop-in/setup-and-integration : "Configure your button to call requestPaymentMethod to retrieve the payment method object, including the payment method nonce. From there, you can submit the nonce to your server in whatever way you see fit." https://developer.paypal.com/braintree/docs/guides/webhooks/overview lists ACH, disputes, disbursements, subscriptions, "Local Payment Methods that are completed by a customer" and others; it names no generic transaction-succeeded notification.
- **V11** https://developer.squareup.com/docs/checkout-api-overview : "the application can optionally provide a redirect URL in the request. If not provided, Square redirects the buyer to a Square-provided confirmation page." https://developer.squareup.com/docs/webhooks/step3validate : the `x-square-hmacsha256-signature` is generated from "The signature key for your webhook subscription. The notification URL for your webhook subscription. The raw body of the request."
- **V12** https://help.acuityscheduling.com/hc/en-us/articles/16676924424589 : "Acuity loads your code in a frame on the confirmation clients see after booking." Its variables include `%email%` and `%price%`; ClickTrail would never request them.

## R1: One generic contract, two signal shapes

**Decision**: a vendor map with `kind: message` (postMessage) and `kind: return_param` (query string), plus `ClickTrailEvents.confirm()` for callbacks. All three end in one push path (consent, closed event set, `markOnce`, identifiers only). Map entries are data from PHP (`clicutcl_confirmation_message_map`), so a vendor is configuration.
**Rationale**: V1 to V8 show three shapes in the field: a documented message (Calendly), a page callback (Cal.com, Stripe Embedded Checkout) and a redirect with an identifier (Stripe). A callback cannot be data, so it needs an API; the other two can.

## R2: Gate and default

**Decision**: the new path sits behind `third_party_confirmations`, default 0, seeded 1 on a fresh install; the legacy matcher is untouched and always on.
**Rationale**: turning the map on for existing installs would start counting Calendly embeds that count nothing today (the default allowlist already includes `calendly.com`), and a site that also enabled the Calendly webhook would see a second `book_appointment` per booking. Opt-in, with the HubSpot caveat copied into the docs.
**Rejected**: moving the legacy strings into the map; it removes `calendly-booked`, a value no vendor document shows, but any site that posts it from its own page would lose its event. Left as an open question.

## R3: Closed event set and what a message may carry

**Decision**: `lead`, `book_appointment`, `payment_confirmed`, `deposit_paid`, `balance_paid`. The push carries `provider`, status and the vendor ID (as `transaction_id` for payments); nothing else is read from `data`.
**Rationale**: the page cannot tell a real vendor message from a visitor-forged one inside an allowlisted origin or from the console, so a browser event is a signal, never a value. `purchase` stays server-confirmed. Acuity's confirmation code defaults to carrying `%email%` (V12); reading it is refused by construction.
**Rejected**: `purchase_confirmed` from the browser (it would invite sites to feed forged values to ad platforms); a `payment_stage` parameter instead of two names (GTM triggers and ad conversion mapping are simpler on names).

## R4: Reuse or generalise the reference store

**Decision**: generalise: rename to `Handoff_Ref_Store`; one row per attribution snapshot, no `purpose` column; mint allowed when WhatsApp append or `handoff_refs` is on.
**Rationale**: the row, collision handling, TTL, ceilings, cleanup, eraser and lookup are already purpose-agnostic (`build_snapshot` takes the signed-token allowlist, nothing WhatsApp-specific); a second table would copy all of it. Sharing ceilings is acceptable because rows are per snapshot per tab session. A code minted for a chat link is harmless as a Stripe reference.
**Rejected**: a `purpose` column (a migration that buys no isolation: lookup is by unguessable code and the data is the same snapshot); keeping the WhatsApp names and adding a second route (naming debt, two gates).

## R5: Which vendors can echo the reference

**Decision**: documented echo only: Stripe (`client_reference_id`, 8 chars plus prefix fits the 200-character, alphanumeric-dash-underscore rule in V5; own `return_url` query parameters, V7), Calendly (`utm_*`, `salesforce_uuid`, existing). PayPal `custom_id` is a later adapter; Cal.com, Acuity, Square, SimplyBook, Mindbody are `unverified` and get no promise.
**Rationale**: a table row that claims an echo without a source would send operators to debug the vendor. The tutorial lists the unverified vendors with "test with a booking and read what comes back".

## R6: Never read secrets from return URLs

**Decision**: `return_param` entries read one named parameter and match an anchored pattern; `payment_intent_client_secret` (V7) is never read, stored or sent, and the tutorial tells sites to use `payment_intent` or the Session ID.
**Rationale**: the client secret authorises payment actions on that PaymentIntent.

## R7: Stripe webhook

**Decision**: add a `stripe` branch to `Webhook_Auth::verify_request()`: parse `t` and all `v1`, compute `hash_hmac('sha256', t . '.' . raw body, secret)`, accept if any `v1` matches with `hash_equals`, ignore other schemes, reject outside the tolerance (existing filter, 60 to 3600 s clamp, so never 0). Adapter handles `checkout.session.completed` and `checkout.session.async_payment_succeeded` with `payment_status = paid`, and `livemode = true` unless `clicutcl_stripe_accept_test_events` returns true. Event ID `stripe_<md5 of session id>`, claimed with `Claim_Lock` then `Dedup_Store`. Everything else (including `unpaid`, `no_payment_required`, unknown references) returns 200 `ignored` so Stripe stops retrying (V6: three days of retries on non-2xx).
**Rationale**: V6 specifies the scheme; multiple `v1` appear while a secret is rolled, and the Calendly parser's overwrite would reject the valid one. The webhook makes no outbound request, so there is no SSRF surface. Value is `amount_total` divided by the currency exponent (V6 currencies page); the exponent table is transcribed from that page in T013 with a URL comment.
**Rejected**: calling Stripe's API to fetch the session (new outbound call, secret key stored); trusting `livemode` absent.

## R8: Consent for a webhook with no browser

**Decision**: a row exists only if the mint route saw marketing consent (or none required); the adapter sets `consent.marketing = true` from that, like the Calendly `c=1` stamp, and dispatches through the normal destination consent gate.
**Limit**: later withdrawal does not delete the row (spec 008 R10); open question 3.

## R9: Later adapters and triggers

| Vendor | Build when |
|---|---|
| PayPal (`PAYMENT.CAPTURE.COMPLETED`, `custom_id`) | a site asks, and the certificate fetch can be pinned to PayPal's hosts through `wp_safe_remote_get` with `reject_unsafe_urls` and an allowlist; `custom_id` presence in the webhook resource is verified |
| Square (payment webhook, `reference_id`) | the payment event name is verified, the signature over the notification URL is handled like HubSpot v3's candidate URLs and the order reference echo is verified |
| Braintree | a documented payment-succeeded hook exists (V10 shows none), else the site's server calls ClickTrail through its own code |
| Booking engines other than Calendly | the vendor publishes a message, callback or webhook contract and a reference field |

## Open questions for Hugo

1. Has 1.13 shipped before this branch lands? If yes, keep `/whatsapp-ref`, the table and `ct_wa_ref` for one release and add the generic names beside them; if no, rename now (FR-007).
2. Remove the unsourced `calendly-booked` string from the legacy matcher, or keep it for sites that post it themselves?
3. Should withdrawing consent delete the server row (needs a client-signed delete route) or keep the retention TTL as in spec 008?
4. When a reference does not resolve and consent is not required, emit the `purchase` without attribution, or ignore it (the current decision)?
5. Verify live: Calendly's invitee URI form in the message against the webhook's; whether `salesforce_uuid` can carry a reference; Cal.com, Acuity, SimplyBook and Mindbody echo fields (R5).
