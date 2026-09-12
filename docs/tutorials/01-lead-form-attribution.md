# Tutorial: Lead Form Attribution

Goal: confirm that observed campaign context reaches a supported lead form and
can answer which sources supplied the observed successful submissions.

## 1. Configure the smallest path

1. Open **ClickTrail > Settings**.
2. Keep `Capture` enabled.
3. Enable only `Forms` integrations used by the site.
4. Keep `Delivery` off unless a receiving endpoint is already tested.

Form behavior differs by adapter:

- Contact Form 7 and Fluent Forms can receive hidden fields automatically.
- Gravity Forms and WPForms need the matching `ct_*` hidden fields you want stored or exported.
- Elementor Forms Pro and Ninja Forms use submission-record paths rather than automatic hidden-field injection.

### Fluent Forms: make attribution visible and exportable

For native entry display and CSV export, add native **Hidden Field** elements
to the Fluent Forms form. Use these exact **Name Attribute** values, with the
same values as their admin labels; leave default values empty:

```text
ct_ft_source
ct_lt_source
ct_ft_medium
ct_lt_medium
ct_ft_campaign
ct_lt_campaign
```

Keep ClickTrail's JavaScript field population enabled. Its existing injector
fills these fields; no custom JavaScript or receiving endpoint is needed.
Test as a visitor outside the WordPress admin session, which can enable admin
QA exclusion. Apply the site's consent policy before testing; do not disable
required consent to produce a passing result.

Verified on 2026-09-12 with ClickTrail 1.10.1, WordPress 7.1, PHP 8.3 and
Fluent Forms 6.2.13 (free): configured fields appeared in the native entry and
native CSV response after a browser submission with consent not required.
Without configured fields, attribution was stored in submission metadata but
was absent from the native entry field display. A Source URL containing UTMs
does not establish first/latest attribution visibility.

Adding fields does not backfill earlier entries. This bounded check does not
verify consent-required, cache, erasure, or other adapter paths.

## 2. Run a synthetic journey

Open a test URL like:

```text
https://example.com/contact/?utm_source=test&utm_medium=cpc&utm_campaign=clicktrail-form
```

Navigate to another page, submit the form with test data, and inspect the
provider-owned entry or submission record.

## 3. Read the result

In Fluent Forms, open the form's **Entries**, then the successful entry. Check
the six named fields. To distinguish touch semantics, make a second tagged
visit with a different source and campaign in the same visitor journey before
submitting: `ft_*` should preserve the original touch and `lt_*` should show
the latest eligible touch.

Use **Entries > Export > Export as CSV** and confirm the export. In an existing
spreadsheet, group by either `ct_ft_source` or `ct_lt_source` and count distinct
`entry_id` values for the selected `created_at` window. State which touch basis
and site time zone the answer uses. For example, an entry with first source
`newsletter` and latest source `google` contributes one submission to the
chosen source basis, not two leads.

Keep blank fields in an **Unknown / not captured** bucket. Older entries and
consent-limited capture can be incomplete; never convert blanks to Direct.
These are observed submissions, not unique people, qualified leads, revenue,
conversion rates, or proof that a channel caused the conversion.

## 4. Record evidence

Record the form adapter, fields expected, whether the page was cached, whether
the form was added after page load, and the resulting provider record. A browser
`dataLayer` event alone is not proof that the provider stored attribution.

See [INTEGRATIONS.md](../reference/INTEGRATIONS.md) for the evidence labels and
[Security and Privacy](../guides/SECURITY-PRIVACY.md) before enabling consent-aware paths.
