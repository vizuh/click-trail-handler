# Tutorial: Lead Form Attribution

Goal: confirm that observed campaign context reaches a supported lead form.

## 1. Configure the smallest path

1. Open **ClickTrail > Settings**.
2. Keep `Capture` enabled.
3. Enable only `Forms` integrations used by the site.
4. Keep `Delivery` off unless a receiving endpoint is already tested.

Form behavior differs by adapter:

- Contact Form 7 and Fluent Forms can receive hidden fields automatically.
- Gravity Forms and WPForms need the matching `ct_*` hidden fields you want stored or exported.
- Elementor Forms Pro and Ninja Forms use submission-record paths rather than automatic hidden-field injection.

## 2. Run a synthetic journey

Open a test URL like:

```text
https://example.com/contact/?utm_source=test&utm_medium=cpc&utm_campaign=clicktrail-form
```

Navigate to another page, submit the form with test data, and inspect the
provider-owned entry or submission record.

## 3. Record evidence

Record the form adapter, fields expected, whether the page was cached, whether
the form was added after page load, and the resulting provider record. A browser
`dataLayer` event alone is not proof that the provider stored attribution.

See [INTEGRATIONS.md](../reference/INTEGRATIONS.md) for the evidence labels and
[Security and Privacy](../guides/SECURITY-PRIVACY.md) before enabling consent-aware paths.
