# Tutorial: WooCommerce Order Attribution

Goal: confirm that observed campaign context is visible on a test WooCommerce
order.

## 1. Configure Capture and WooCommerce

1. Open **ClickTrail > Settings**.
2. Keep `Capture` enabled.
3. Confirm the WooCommerce integration is active.
4. Enable `Events` only when browser purchase signals are part of the test.
5. Leave `Delivery` off unless its endpoint is ready and separately verified.

## 2. Run a synthetic order

Visit the store with a tagged URL:

```text
https://shop.example.com/?utm_source=test&utm_medium=cpc&utm_campaign=clicktrail-order
```

Browse a product, complete a test order, and inspect the order in WordPress.
If browser events are enabled, inspect GTM preview or `dataLayer` as a separate
evidence surface.

## 3. Verify consent and the order handoff

Run the test once with required marketing consent granted and once with it
denied or unresolved:

- granted consent permits the canonical `_clicutcl_*` attribution metadata
- denied or unresolved consent stores the checkout snapshot in
  `_clicutcl_consent` but does not store cookie or posted attribution values
- the checkout fallback accepts `ct_*` fields only after the WooCommerce nonce
  is verified, and only when no consent-permitted cookie payload is available
- `_clicutcl_ft_*` remains first touch, `_clicutcl_lt_*` remains latest touch,
  and click IDs keep provider-specific names such as `_clicutcl_fbclid`

A local connector may listen to `clicutcl_order_attribution_saved` and read the
order metadata. It must not rewrite the keys or treat the action as proof of
provider delivery, payment, or reconciliation.

## 4. Record the boundary

Record the WordPress version, WooCommerce version, order storage mode, consent
state, tagged URL, order result, and browser-event result. Classic and HPOS
synthetic checks do not prove browser checkout, provider delivery, queue
behavior, or complete privacy lifecycle behavior.

See [WooCommerce in the integration reference](../reference/INTEGRATIONS.md#woocommerce)
and the [operations runbook](../guides/OPERATIONS-RUNBOOK.md).
