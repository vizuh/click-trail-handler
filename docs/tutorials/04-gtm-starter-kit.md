# GTM starter kit

Use this tutorial to import ClickTrail's checked-in GTM container export and
verify its WordPress and JavaScript event paths with synthetic data. It proves
container wiring only. It does not prove provider acceptance, credentials,
consent-law compliance, or destination delivery.

## Before you start

- Confirm you own or administer the destination GTM container.
- Decide which consent source is authoritative before publishing.
- Do not configure GTM injection in ClickTrail if the site already injects GTM.
- Keep this first import in a new GTM workspace or a reviewed merge.

## Import and configure

1. Open `assets/gtm-starter-kit.json` in this repository.
2. In GTM, choose **Admin → Import Container** and select the JSON file.
3. Import into a new workspace or merge after reviewing every overwrite.
4. In the `Setup` folder, replace the placeholder values for GA4, Google Ads,
   Meta Pixel, the optional sGTM endpoint, and the thank-you page path.
5. Review triggers and tags. Do not publish yet.

The export expects ClickTrail data-layer events to include a top-level `event`
key. It uses `event_id` for Meta `eventId` and reads
`marketing_trail.consent.advertising` with a missing-value default of `false`.
The Meta PageView tag accepts both `ct_page_view` from WordPress and
`page_view` from JavaScript. Google tags retain their native GTM consent
behavior.

## Run the local contract check

From the repository root:

```sh
node examples/validate-gtm-starter-kit.mjs
```

The check parses the real export and the synthetic fixture at
`examples/gtm-data-layer-events.json`. It verifies event IDs, consent mapping,
both page-view triggers, Meta tag count, and a purchase payload with
`transaction_id`, value, currency, and items.

## Verify in GTM Preview

Push one fixture object at a time into the site's existing `window.dataLayer`
or reproduce the same shape through the integration that owns the event.
In GTM Preview, confirm:

- `ct_page_view` and `page_view` appear as separate custom events.
- `DLV - event_id` resolves to the fixture event ID.
- `DLV - marketing_trail.consent.advertising` is `false` when the field is
  absent and reflects the published consent source when present.
- Meta tags do not run before the selected advertising consent state allows
  them.
- Purchase data resolves from the `ecommerce` object.

Preview is local container evidence, not provider delivery evidence. Use each
provider's own test or diagnostics surface before treating a destination as
working.

## Regeneration boundary

The checked-in JSON is the import artifact. Regeneration uses:

```sh
python3 assets/build-starter-kit.py
```

Run that command only when the expected Stape reference file is present under
`assets/shopify-gtm-container-templates-master/`. This checkout currently
keeps the generated artifact but not that reference source.

## Troubleshooting

- **No custom event:** the pushed object is missing top-level `event`.
- **No Meta event ID:** `event_id` is missing or the tag mapping was overwritten.
- **Meta fires too early:** the consent variable or selected consent source is
  not controlling the tag as reviewed.
- **Duplicate page views:** GTM is injected twice or another PageView tag is
  active outside this starter kit.
- **JavaScript page view missing:** the integration emitted only
  `ct_page_view`; use the matching trigger for the event actually published.
