# Research: HubSpot Lead `user_data` for Google Ads Enhanced Conversions

Legend as in spec 003: **[V-doc]** verified 2026-10-22, **[V-src]** verified in source, **[U]** unverified.

## Current state (`main` at `7ee35c2`, 1.10.5)

- No email/phone capture or hashing exists in the browser runtime. `user_data` is built only for WooCommerce events (`buildWooUserData()`, `assets/js/clicutcl-events.js` ~L1939) and holds click IDs and browser IDs, not PII. [V-src]
- `hashed_email` appears only in the Calendly handoff plan (erasure), never in browser code. [V-src]
- `SECURITY-PRIVACY.md` positions ClickTrail as holding campaign context, with a storage inventory and consent gating. Adding PII to `dataLayer` is a boundary change for that document.

## R1 - Can field values be read after success?

**[V-doc]** https://developers.hubspot.com/docs/api-reference/latest/marketing/forms/global-form-events : `getFieldValue(name)` and `getFormFieldValues()` are async; `getConversionId()` is valid only after success; "some form data fields can only be retrieved or set before the form is submitted". The doc does not say whether ordinary field values survive the success event (the form is replaced by the thank-you message).
**[U]** Decided by spec 003 Phase 0 (T001, side check). If values are gone, the options are: read values on `on-interaction:navigate` (multi-step forms only), or abandon browser-side EC for single-step forms and rely on HubSpot's native sync (R4).

## R2 - Google Enhanced Conversions for web

**[V-doc]** https://support.google.com/google-ads/answer/9888145 : `gtag('set', 'user_data', {email, phone_number, address})`; "You can either send unhashed data for Google to normalize and hash automatically, or you can send pre-hashed data" (SHA-256); at least email or full address is required; phone in E.164.
**[V-doc]** https://support.google.com/google-ads/answer/13262500 : GTM "Code" (manual) configuration of the user-provided data variable; normalisation rules (trim, lowercase, E.164, strip dots in gmail local part).
**[V-doc]** https://support.google.com/google-ads/answer/6386790 : `transaction_id` dedup, per conversion action; static IDs cause undercounting.
**Partial**: Google shows `gtag('set')` and GTM variables, not a literal `dataLayer.push({user_data})`. Reading the data-layer field through a Data Layer Variable into the "Code" option is standard GTM practice but not shown verbatim in the docs.

## R3 - What the auto-detect GTM path cannot do

The Ads user-provided-data variable in "automatic" mode reads inputs from the parent DOM; it cannot see inside a cross-origin iframe. [V-src] from the tenant's GTM container, where the auto-detect tag is email-only and triggers on `gtm.formSubmit`, which never fires for the iframe. This is why a code path is needed at all.

## R4 - Alternatives outside ClickTrail

- **HubSpot native Google Ads conversion events** [V-doc] https://knowledge.hubspot.com/ads/create-and-sync-ad-conversion-events-with-your-google-ads-account : Marketing Hub Starter up to 5 events, Professional 50, Enterprise 100; needs the GCLID in HubSpot's "Google ad click ID" property or shared email/phone/address; with a Google Ads manager (MCC) account "you can't sync using Google's Enhanced Conversions". Depends on spec 003 landing `gclid` on the contact.
- **Enhanced conversions for leads (offline upload)**: Google's offline paths are moving to the Data Manager API; the exact cut-over date and allowlist rules were not re-verified here. Client-side, not ClickTrail.
- **HubSpot tracking code on the site**: sets `hubspotutk`, gives HubSpot source analytics; does not help Ads directly. "Google ad click ID" is "automatically set by HubSpot based on a Google Click identifier (GCLID) provided by Google Ads" [V-doc] https://knowledge.hubspot.com/properties/hubspots-default-contact-properties ; whether that requires the tracking code is not stated. The internal name `hs_google_click_id` appears only in community posts. [U]

## R5 - Privacy decisions to make in plan.md

- Default off; a setting plus marketing consent; pre-hash option.
- Strip `user_data` PII from `buildCanonicalEvent()` and `buildMarketingTrailEnvelope()`; mask in debug logs.
- Readme claim boundary: ClickTrail prepares browser-side `user_data` for the Google tag; it stores nothing and sends nothing to Google itself.
- Ask the client whether their privacy policy and Ads customer-data terms cover Enhanced Conversions before enabling.
