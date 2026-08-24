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

## 3. Record the boundary

Record the WordPress version, WooCommerce version, order storage mode, tagged
URL, order result, and browser-event result. Classic and HPOS synthetic checks
do not prove browser checkout, provider delivery, queue behavior, or complete
privacy lifecycle behavior.

See [WooCommerce in the integration reference](../reference/INTEGRATIONS.md#woocommerce)
and the [operations runbook](../guides/OPERATIONS-RUNBOOK.md).
