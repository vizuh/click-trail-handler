# Tutorial: Consent and Browser Events

Goal: verify that browser capture and event output follow the site's configured
consent source.

## 1. Pick one consent source

Use the site's existing Cookiebot, OneTrust, Complianz, GTM, or custom consent
source when possible. Do not configure competing sources without documenting
which one is authoritative.

In **ClickTrail > Settings**:

1. Configure `Capture`.
2. Enable `Events` only when browser signals are needed.
3. Select the consent source and mode that match the site.
4. Do not add a GTM container ID if the site already loads GTM elsewhere.

## 2. Test three states

Run the same tagged journey with:

1. consent granted before landing;
2. consent denied;
3. consent granted, then withdrawn before a later event or delivery attempt.

Check the browser `dataLayer`, ClickTrail Diagnostics, and any enabled delivery
logs separately. Verify that the canonical browser decision survives a reload,
that a stale `ct_consent` cookie cannot restore a withdrawn decision, and that
an open second tab receives the withdrawal through the browser `storage` event.
Do not treat a visible browser event as proof of provider acceptance. The
repository test (`npm run test:consent`) is a Node VM boundary test; it is not a
real browser, WooCommerce, or provider E2E test.

## 3. Keep delivery optional

Only enable `Delivery` after the consent states pass and a receiving endpoint is
ready. Platform-named server adapters remain configured-endpoint paths with
runtime-unverified provider authentication and acceptance.

See [Consent Decision v1](../guides/CONSENT-DECISION-V1.md),
[Security and Privacy](../guides/SECURITY-PRIVACY.md), and the
[integration evidence ledger](../reference/integration-capabilities.json).
