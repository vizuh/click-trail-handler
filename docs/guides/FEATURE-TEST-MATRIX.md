# Feature Test Matrix

- **Audience**: maintainers, reviewers, QA contributors, and release engineers
- **Canonical for**: ClickTrail smoke-test IDs, evidence-backed regression checks, and manual verification coverage
- **Update when**: registry smoke IDs change or a shipped capability needs new regression coverage
- **Source baseline**: plugin code `1.9.0`, commit `a45aa9e`
- **Runtime verification**: structural smoke plus consent-bridge checks only; provider/WordPress E2E was not completed

ClickTrail now keeps a small evidence-backed smoke matrix so capability breadth can grow without silent docs or QA drift.

Primary machine-readable source:

- `config/feature-test-matrix.json`

Primary automated entry point:

- `npm run smoke`

Implementation:

- `tools/qa/smoke.js`

## What the Smoke Harness Covers

The current smoke harness is primarily structural, not provider end-to-end. It verifies that registry and
source-backed capabilities still have:

- registry coverage
- canonical docs ownership
- code evidence in the expected runtime files
- smoke IDs that stay aligned between the feature registry and the matrix

The consent-bridge checks also exercise a small JavaScript boundary. This is intentionally lighter than a
WordPress integration or provider contract suite, and it must not be used as proof of production delivery,
privacy compliance, or native platform support. It is strong enough to catch common breadth regressions such as:

- new adapters added in one place but not wired into the dispatcher
- new destination toggles added in admin without registry coverage
- Woo storefront or milestone features losing their expected runtime hooks
- diagnostics tools drifting out of sync with their AJAX handlers
- docs targets going stale when a capability changes

## Coverage Groups

## Admin and Settings Mapping

Smoke IDs:

- `admin-save-capture`
- `admin-save-forms`
- `settings-export-import`

These checks defend the grouped settings app, the save/load mapping back into the five main option stores, and the Diagnostics backup/restore path.

## Browser Intake and Security

Smoke IDs:

- `signed-intake-gate`

This check defends the signed browser-intake path, including token verification, host validation, and nonce replay limits.

## WooCommerce Storefront and Milestones

Smoke IDs:

- `woo-view-item`
- `woo-add-to-cart`
- `woo-remove-from-cart`
- `woo-begin-checkout`
- `woo-view-item-list`
- `woo-order-paid`
- `woo-order-refunded`
- `woo-order-cancelled`
- `diagnostics-woo-lookup`
- `woo-readiness-contract`

The first nine IDs are structural source checks with `automated: false`; they do
not prove Woo runtime behavior. `woo-readiness-contract` is an automated pure
comparator check across 16 synthetic scenarios. It proves only the closed M6-A
contract, not hooks, order persistence, browser behavior, HPOS, provider
acceptance, consent correctness, dedup concurrency, or privacy lifecycle.

## Delivery Adapters and Destinations

Smoke IDs:

- `delivery-adapter-generic`
- `delivery-adapter-sgtm`
- `delivery-adapter-meta`
- `delivery-adapter-google`
- `delivery-adapter-linkedin`
- `delivery-adapter-pinterest`
- `delivery-adapter-tiktok`
- `destination-meta-toggle`
- `destination-google-toggle`
- `destination-linkedin-toggle`
- `destination-reddit-toggle`
- `destination-pinterest-toggle`
- `destination-tiktok-toggle`

These checks defend the selective destination-expansion surface without turning ClickTrail into a dynamic tag manager.

## Queue and Diagnostics Operations

Smoke IDs:

- `diagnostics-conflict-scan`
- `queue-retry-semantics`

These checks defend the deterministic conflict scan and the queue retry/backoff contract used by the delivery layer.

## Form-readiness contract

Smoke ID:

- `form-readiness-contract`

This structural check pins the pure presence comparator, its privacy tests, and
one versioned fixture for each of the six existing form adapters. It verifies
fixture ownership only. It does not prove that a form hook fired, a provider
record was written, a cache/AJAX path worked, or a browser completed a submit.

## Manual QA Still Required

The smoke harness does not replace real runtime validation. Releases should still include targeted manual checks for:

- provider-specific adapter contract fixtures and staged account delivery
- consent withdrawal immediately before queue retry, including zero-send assertions
- Woo order-meta export, erase, uninstall, and purge drills
- rendered-docs claims review so registry status is not presented as provider support

- Woo shop, product, cart, checkout, and thank-you flows
- queue retries against a real or staged failing endpoint
- admin export/import round trips with masked secrets
- consent-gated browser intake in a live WordPress runtime
- localized admin copy review for changed support-facing screens

## Change Rules

When shipping a new capability:

1. add or update the feature registry entry
2. add or update the smoke matrix entry
3. update the canonical docs named by the registry
4. run `npm run smoke`
5. note any remaining manual QA in the final change summary or PR
