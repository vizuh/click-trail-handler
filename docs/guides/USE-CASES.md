# ClickTrail Use Cases

Choose the smallest rollout that matches the conversion surface you own. The
source paths below remain subject to the evidence labels in
[INTEGRATIONS.md](../reference/INTEGRATIONS.md).

## Lead generation forms

Use when leads arrive through Contact Form 7, Fluent Forms, Gravity Forms,
WPForms, Elementor Forms Pro, or Ninja Forms.

Enable:

- `Capture`
- `Forms`

Validate:

1. Open a test URL with `utm_source`, `utm_medium`, and `utm_campaign`.
2. Browse to another page.
3. Submit a test form.
4. Confirm the expected fields or submission record contain observed attribution.

Boundary: form adapters are source connectors and remain runtime-unverified in
the current evidence ledger. Gravity Forms and WPForms need matching `ct_*`
hidden fields. Elementor Forms Pro and Ninja Forms use submission storage paths.

## WooCommerce orders

Use when campaign context must remain visible on orders or purchase events.

Enable:

- `Capture`
- WooCommerce integration
- `Events` only when browser purchase signals are needed

Validate:

1. Visit a tagged landing URL.
2. Browse products and place a test order.
3. Confirm attribution on the order.
4. If `Events` is enabled, confirm the expected purchase signal in GTM preview or `dataLayer`.

Boundary: classic and HPOS paths have isolated synthetic evidence, not full
browser-checkout, provider-delivery, queue, or privacy-lifecycle proof.

## Cached and dynamic forms

Use when page caching, AJAX, or client-rendered forms can bypass server-rendered
hidden fields.

Enable:

- `Capture`
- client-side form fallback
- dynamic-content watching when late-added forms are in scope

Validate the same form on a cached page and on a dynamically rendered page.
Confirm that attribution is present in the provider-owned record, not only in a
browser event.

Boundary: a client-side fallback improves capture continuity; it does not prove
provider persistence or consent compliance by itself.

## Approved multi-domain funnels

Use when the marketing site, application, scheduler, or checkout domain is
owned and controlled by the same team.

Enable:

- `Capture`
- cross-domain continuity only for explicitly approved domains

Validate the complete journey from tagged landing URL to the final form or
order. Test both a valid approved-domain handoff and an unapproved destination.

Boundary: external hosted payment pages cannot be decorated. Default signing
requires persisted storage, and separate origins need shared provisioning or
explicit host-provided signing and verification functions.

## Consent-aware sites

Use when the site already has Cookiebot, OneTrust, Complianz, GTM, or another
consent source.

Enable:

- `Capture`
- consent mode only when the site's consent policy requires it

Validate granted, denied, and withdrawal paths before launch. Keep one consent
source of truth and document which capabilities remain off until consent is
resolved.

Boundary: the current release gates do not support an unqualified privacy or
compliance claim. See [Security and Privacy](SECURITY-PRIVACY.md).

## Optional configured-endpoint delivery

Use only when a collector, sGTM endpoint, or receiving workflow already exists.

Enable `Delivery` after Capture and the conversion surface pass validation.
Send a synthetic event, inspect the received canonical JSON, and confirm
Diagnostics and Logs show the expected attempt.

Boundary: platform-named server adapters are configured-endpoint paths with
runtime-unverified provider authentication and acceptance. Reddit remains
relay-only; ClickTrail does not inject provider pixel SDKs.

## Related tutorials

- [Lead form attribution](../tutorials/01-lead-form-attribution.md)
- [WooCommerce order attribution](../tutorials/02-woocommerce-order-attribution.md)
- [Consent and browser events](../tutorials/03-consent-and-events.md)
