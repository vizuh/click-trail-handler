# ClickTrail Integrations Reference

- **Audience**: contributors, maintainers, reviewers, and solution engineers
- **Canonical for**: integration roles, source evidence, status boundaries, providers, forms, webhooks, GTM, and delivery adapter keys
- **Update when**: integration support level, adapter list, provider contract, evidence status, or capability messaging changes
- **Provider-wide audit baseline**: plugin code `1.9.0`, commit `a45aa9e`, reviewed 2026-08-19
- **Current release**: plugin code `1.13.0` on GitHub and WordPress.org, adding new integrations (Easy Digital Downloads order attribution and purchase event, Ultimate Member registration attribution, Formidable Forms, Forminator, SureForms and MetForm adapters), the WhatsApp click event with reference code (still off by default), Safari ITP attribution-cookie re-issue, the cookie scope setting, GA4 GS2 session-cookie parsing, ClickTrail dataLayer fields renamed to `ct_*` (behaviour change for GTM variables), security hardening (rightmost-untrusted client IP, WhatsApp reference flood bounds, sGTM probe SSRF guard) and privacy fixes (exact-match exporter/eraser, WooCommerce HPOS eraser, no purchase pushed for orders older than retention). Evidence for 1.13.0: PHPUnit and Node tests cover everything; the real-WordPress harness (`tools/qa/e2e/`, WordPress 7.1, PHP 8.3) ran Contact Form 7, Fluent Forms, WPForms Lite, SureForms, MetForm, Formidable, Forminator, Easy Digital Downloads, Ultimate Member, WooCommerce privacy, cookie scope, cookie re-issue and WhatsApp; NOT run: Gravity Forms, Elementor Pro, Ninja Forms, Pro form-plugin editions, Safari/iOS, Stripe/PayPal/Square in Easy Digital Downloads, real CDN/optimizer plugins and a real GTM import. Previously, 1.12.0 shipped with automated consent, queue, WooCommerce privacy, CF7 outcome, touch-event deduplication, CMP decision-transition, Calendly webhook, and attribution round-trip E2E (`tools/qa/e2e/`, CI) coverage; the live Calendly booking round trip remains unverified. HubSpot embedded form capture (new in 1.11.0) has a Node VM contract test only. The Gravity Forms first-page fix (1.11.1), the 1.11.2 submit-time refresh, purchase lock, purchase dataLayer `event_id`, phone E.164 normalisation, consent-before-GTM ordering and optimizer exclusions, and, new in 1.11.3, cache-safe hidden fields, form dedupe and entry IDs and the rebuilt GTM starter kit have PHPUnit/Node tests; CF7, Fluent Forms and WPForms Lite also ran in a throwaway WordPress 7.1 / PHP 8.3 pod (175+ checks, `tools/qa/e2e/EVIDENCE-2026-10-31.md`). New in 1.12.0, `window.ClickTrail.decorateEmbedUrl()`, Contact Form 7 render-only-referenced-keys, atomic form dedupe (`Claim_Lock`) and the optimizer-exclusion fixes (LiteSpeed filter name, renamed plugin folder, FlyingPress) have PHPUnit/Node tests only; the E2E for CF7, Fluent Forms and WPForms Lite was re-run on WordPress 7.1 against the release zip, and no real optimizer plugin was run. Not run: Gravity Forms, Elementor Pro, Ninja Forms, WPForms Pro, a real page cache or optimizer, a real GTM import
- **Runtime verification**: provider-wide E2E remains incomplete; bounded WooCommerce and Fluent Forms evidence is recorded below, while the form manifests remain explicitly runtime-unverified
- **Machine-readable ledger**: [`integration-capabilities.json`](integration-capabilities.json)

This document is an evidence boundary, not a promise that every registry entry is production-ready. The
registry proves internal wiring and smoke ownership. It does not prove provider credentials, provider API
acceptance, consent transitions, retry safety, or deletion behavior.

For rollout guidance by site type, see [../guides/IMPLEMENTATION-PLAYBOOK.md](../guides/IMPLEMENTATION-PLAYBOOK.md).
For release gates, see [RELEASE-PHASING-AND-INTEGRATION-DOCS.md](../guides/RELEASE-PHASING-AND-INTEGRATION-DOCS.md).

## Normalized marketing trail envelope

WP browser events, `ct_page_view`, server-side events, and form submissions
now carry a backward-compatible `marketing_trail` envelope. It standardizes
event, trail, anonymous, and lead IDs; first-touch/latest-touch attribution;
click IDs; capture-time consent; site routing; and form provider context.

The envelope is also the contract emitted by the free `@vizuh/clicktrail`
browser package. See [MARKETING-TRAIL-ENVELOPE.md](MARKETING-TRAIL-ENVELOPE.md)
for fields, ID namespaces, and the v1 example. Runtime provider delivery and
WordPress E2E status remain governed by the evidence labels above.

## Status vocabulary

Use these labels in README copy, support answers, screenshots, and future provider pages:

- **Source-present / runtime-unverified** — the registry and source path exist; provider acceptance,
  credentials, consent transitions, retries, and purge are not proven here.
- **GTM-mediated** — ClickTrail exposes browser/dataLayer or consent signals; the site owner configures
the provider tag in GTM.
- **Relay-only** — a destination marker/toggle exists, but no native ClickTrail delivery adapter exists.
- **Webhook ingress** — provider data enters ClickTrail; this is not outbound provider delivery.
- **Source connector / runtime-unverified** — a WordPress form, WooCommerce, or webhook source exists;
live hook, consent, identity, and retention behavior still require testing.
- **Not observed / planned / unsupported** — do not describe as available.

### Capability matrix

| Surface | Current status | What is present | What is not proven or not present |
| --- | --- | --- | --- |
| Generic Collector | Source-present / runtime-unverified | Configured-endpoint JSON collector (`schema_version: 1`) | Provider contract and destination acceptance |
| sGTM adapter | Source-present / runtime-unverified | Configured-endpoint relay (`schema_version: 1`, `collector: sgtm`) | Secure preview, provider/tagging contract, and runtime delivery |
| Meta CAPI adapter | Source-present / runtime-unverified | Registry key and adapter class posting canonical JSON (`collector: meta_capi`) | Turnkey Meta API authentication/acceptance; runtime delivery |
| Google Ads / GA4 adapter | Source-present / runtime-unverified | Registry key and adapter class posting canonical JSON (`collector: google_ads`) | Turnkey Google API authentication/acceptance; runtime delivery |
| LinkedIn CAPI adapter | Source-present / runtime-unverified | Registry key and adapter class posting canonical JSON (`collector: linkedin_capi`) | Turnkey LinkedIn API authentication/acceptance; runtime delivery |
| Pinterest Conversions API adapter | Source-present / runtime-unverified | Registry key and adapter class posting canonical JSON (`collector: pinterest_capi`) | Turnkey Pinterest API authentication/acceptance; runtime delivery |
| TikTok Events API adapter | Source-present / runtime-unverified | Registry key and adapter class posting canonical JSON (`collector: tiktok_events_api`) | Turnkey TikTok API authentication/acceptance; runtime delivery |
| Google Tag Manager | GTM-mediated | Optional container injection, consent commands, browser/dataLayer events | Provider tags, consent configuration, and destination delivery owned by the site |
| Meta/Facebook Pixel | GTM-mediated only; direct SDK not observed | Can consume site-owned GTM/dataLayer configuration | ClickTrail does not inject `fbq` or a Meta Pixel SDK |
| Google tag / GA4 browser tag | GTM-mediated only; direct SDK not observed | Can consume site-owned GTM/dataLayer configuration | ClickTrail does not replace a site-owned Google tag setup |
| TikTok Pixel, LinkedIn Insight, Pinterest Tag, Reddit Pixel | GTM-mediated only; direct SDKs not observed | Possible through a site-owned GTM container | No direct pixel/SDK injection or provider setup in ClickTrail |
| Reddit destination | Relay-only | Destination toggle and `rdt_cid`/Reddit source classification | No native Reddit delivery adapter; no Reddit conversion-delivery claim |
| Forms | Source connector / runtime-unverified overall | CF7, Elementor Pro, Fluent, Formidable, Forminator, Gravity, MetForm, Ninja, SureForms, and WPForms paths; bounded Fluent Forms submission-storage evidence; bounded Formidable Forms free E2E slice (below); a Forminator 1.58.4 pod run (below); six-case evidence manifests | Live plugin-hook, posted-field, submission-storage, consent, and erasure proof for the remaining adapters |
| WooCommerce | Source connector / runtime-unverified | Order attribution, storefront events, purchase/milestones, traces, allowlisted order-meta privacy lifecycle contracts, and a per-order thank-you dispatch lock (PHPUnit `WooCommercePurchaseDispatchLockTest`, #148) | Live checkout/CMP behavior, purchase dataLayer, dedup concurrency under real concurrent requests, queue/retry, and classic/HPOS staging proof |
| Easy Digital Downloads (3.0+) | Source connector / runtime-unverified overall; Store gateway staged E2E | Consent-gated order attribution at `edd_built_order`, one receipt purchase (`purchase_edd_<order ID>`), allowlisted order-meta privacy lifecycle (PHPUnit `EddIntegrationTest`, `EddOrderPrivacyTest`; `tools/qa/e2e/edd-ci.sh` on EDD 3.7.3, Store gateway) | Stripe/PayPal/Square gateway runs, block receipt, refunds and status milestones, EDD Recurring/Software Licensing renewals |
| Ultimate Member (free) | Source connector / runtime-unverified | Registration attribution stored as allowlisted `_clicutcl_*` user meta, one `sign_up` dispatch (`um_registration_<user_id>`, claim + sent-marker dedupe), consent gate, exporter/eraser/retention/purge (PHPUnit `UltimateMemberRegistrationTest`, `UserRegistrationPrivacyTest`) | Live behavior on other UM versions, UM extensions (social login, Stripe), registration over REST or the UM API, consent from a real CMP, destination acceptance |
| Calendly, HubSpot, Typeform | Webhook ingress / runtime-unverified | Signed inbound routes and canonical translation | Provider timestamp, identity minimization, replay, consent, and E2E proof |
| HubSpot embedded forms (updated editor v4 and legacy embeds) | Source-present / runtime-unverified | Browser `lead` on HubSpot's documented v4 submission-success event and legacy `hsFormCallback` `onFormSubmitted` message; `form_submit_failed` on v4 failure; consent-gated, deduplicated; Node VM contract test | A live HubSpot form submission; iframe origin of legacy iframe embeds; attribution written into HubSpot hidden fields (not implemented) |

### Important platform boundary

The platform-named delivery classes currently receive an endpoint and timeout, serialize the canonical event,
and add a schema/collector marker before an HTTP request. They do not, by themselves, demonstrate a complete
provider SDK/API integration with provider-specific authentication, payload acceptance, or account-level
conversion verification. Treat the adapter keys as **source-present / runtime-unverified** until the relevant
provider contract fixture and staged delivery test are recorded in the ledger.

ClickTrail also does **not** inject Meta/Facebook Pixel, Google tag, TikTok Pixel, LinkedIn Insight,
Pinterest Tag, or Reddit Pixel SDKs. Those are GTM-mediated/site-owned paths and need their own provider
consent, tag, payload, and verification setup.

## Adapter evidence cards

Each card follows the same contract: role → trigger/input → consent → identity/data → transport/auth →
retry/replay/dedup → retention/purge → setup → evidence and limitations.

### Generic Collector — `generic`

- **Role:** configured-endpoint delivery adapter.
- **Input:** canonical ClickTrail event; POST JSON with `schema_version: 1`.
- **Transport:** `Generic_Collector_Adapter`; outbound requests use WordPress HTTP with unsafe URLs rejected.
- **Auth:** no provider-specific auth is constructed by this class; configure/authenticate the collector separately.
- **Reliability:** shared Dispatcher/Queue/Diagnostics path; runtime retry and purge behavior remains under the
  L1/L2 release gates.
- **Evidence:** `config/feature-registry.json`, `includes/server-side/class-generic-collector-adapter.php`,
  smoke ID `delivery-adapter-generic`.
- **Automation recipe:** Zapier and Make webhook URLs can receive this generic
  JSON path. ClickTrail does not provide provider-specific authentication,
  field mapping, app actions, or native Zapier/Make adapters; verify the
  receiving workflow with a synthetic event before enabling Delivery.

### sGTM — `sgtm`

- **Role:** configured sGTM endpoint relay plus browser compatibility mode.
- **Input:** canonical event; POST JSON with `schema_version: 1`, `collector: sgtm`.
- **Transport:** `Sgtm_Adapter`; GTM web-tag settings also control tagging-server URL, loader, and preview paths.
- **Auth:** no provider authentication is proven by the adapter class; sGTM container/server configuration remains external.
- **Reliability/security:** shared queue/diagnostics path; the sGTM preview SSRF/private-network gate is an open
  release blocker, so do not describe preview as secure-by-default.
- **Evidence:** `includes/server-side/class-sgtm-adapter.php`, `includes/Modules/GTM/class-web-tag.php`,
  smoke ID `delivery-adapter-sgtm`.

### Meta CAPI — `meta_capi`

- **Role:** registry-backed configured-endpoint adapter key.
- **Input:** canonical event; POST JSON with `schema_version: 2`, `collector: meta_capi`.
- **Transport/auth:** the reviewed class does not construct Meta credentials or a verified Meta API request;
  endpoint/account configuration and provider acceptance are unverified.
- **Browser boundary:** Meta/Facebook Pixel is not injected by ClickTrail; use a site-owned GTM tag if needed.
- **Evidence:** `includes/server-side/class-meta-capi-adapter.php`, smoke ID `delivery-adapter-meta`.

### Google Ads / GA4 — `google_ads`

- **Role:** registry-backed configured-endpoint adapter key.
- **Input:** canonical event; POST JSON with `schema_version: 2`, `collector: google_ads`.
- **Transport/auth:** the reviewed class does not construct a verified Google Ads or GA4 API request/auth contract.
- **Browser boundary:** Google tags remain site-owned/GTM-mediated.
- **Browser session join (source-present / runtime-unverified, #135):** `clicutcl-attribution.js` reads `ga_client_id` from `_ga` and `ga_session_id` / `ga_session_number` from the `_ga_<property>` cookie (GS2 and GS1; no numeric fallback; pinned to the `ga4_measurement_id` setting when set, otherwise first usable cookie plus a `ct:diagnostic` `ga_session_property_unpinned` event when several exist), after consent. Format and pin rules: [DATA-MODEL.md](../architecture/DATA-MODEL.md). ClickTrail dataLayer pushes never carry the Google tag's reserved field names flat (`session_id`, `client_id`, `user_id`, `session_number`, `engagement_time_msec`, `timestamp_micros`, `page_location`, `page_referrer`; pushed as `ct_<name>`) and the canonical page view is `ct_page_view`: [EVENT-PIPELINE.md](../architecture/EVENT-PIPELINE.md#datalayer-push-shape). In a GA4 Event tag never use a reserved name as the parameter name, and exclude `ct_page_view` from a catch-all `{{Event}}` tag.
- **Evidence:** `includes/server-side/class-google-ads-adapter.php`, smoke ID `delivery-adapter-google`.

### LinkedIn CAPI — `linkedin_capi`

- **Role:** registry-backed configured-endpoint adapter key.
- **Input:** canonical event; POST JSON with `schema_version: 2`, `collector: linkedin_capi`.
- **Transport/auth:** LinkedIn API acceptance and authentication are not verified by the reviewed class.
- **Browser boundary:** LinkedIn Insight Tag remains site-owned/GTM-mediated.
- **Evidence:** `includes/server-side/class-linkedin-capi-adapter.php`, smoke ID `delivery-adapter-linkedin`.

### Pinterest Conversions API — `pinterest_capi`

- **Role:** registry-backed configured-endpoint adapter key.
- **Input:** canonical event; POST JSON with `schema_version: 2`, `collector: pinterest_capi`.
- **Transport/auth:** Pinterest API acceptance and authentication are not verified by the reviewed class.
- **Browser boundary:** Pinterest Tag remains site-owned/GTM-mediated.
- **Evidence:** `includes/server-side/class-pinterest-capi-adapter.php`, smoke ID `delivery-adapter-pinterest`.

### TikTok Events API — `tiktok_events_api`

- **Role:** registry-backed configured-endpoint adapter key.
- **Input:** canonical event; POST JSON with `schema_version: 2`, `collector: tiktok_events_api`.
- **Transport/auth:** TikTok Events API acceptance and authentication are not verified by the reviewed class.
- **Browser boundary:** TikTok Pixel remains site-owned/GTM-mediated.
- **Evidence:** `includes/server-side/class-tiktok-events-api-adapter.php`, smoke ID `delivery-adapter-tiktok`.

### Reddit — relay-only destination

- **What exists:** Events destination toggle, `rdt_cid` capture, and Reddit source/referrer classification.
- **What does not exist:** native Reddit delivery adapter or direct Reddit Pixel SDK.
- **Correct setup language:** route canonical events through a separately verified collector/GTM relay; do not
  claim that enabling the Reddit toggle sends conversions to Reddit.
- **Evidence:** `config/feature-registry.json`, `config/feature-test-matrix.json`,
  `assets/js/admin-settings-app.js`, `assets/js/clicutcl-attribution.js`; smoke ID `destination-reddit-toggle`.

## Normalized marketing trail envelope

WP browser events, `ct_page_view`, server-side events, and form submissions
now carry a backward-compatible `marketing_trail` envelope. It standardizes
event, trail, anonymous, and lead IDs; first-touch/latest-touch attribution;
click IDs; capture-time consent; site routing; and form provider context.

The envelope is also the contract emitted by the free `@vizuh/clicktrail`
browser package. See [MARKETING-TRAIL-ENVELOPE.md](MARKETING-TRAIL-ENVELOPE.md)
for fields, ID namespaces, and the v1 example. Runtime provider delivery and
WordPress E2E status remain governed by the evidence labels above.

## Integration Pattern Cheatsheet

Form integrations fall into three patterns:

- automatic hidden-field injection: Fluent Forms, and Contact Form 7 for the `ct_<key>` fields the form references (`[hidden ct_gclid]` in the form or `[ct_gclid]` in the mail template; a form that references none gets none) (Fluent Forms native entry/CSV visibility requires configured fields; see below)
- compatible hidden-field population: Gravity Forms, WPForms, Formidable Forms, Forminator, Elementor Forms (Pro) and MetForm hidden fields the site owner adds (`ct_<key>` parameter name, CSS class or label, field key or label, custom CSS class, label or Custom value, `custom_id`, and input name respectively)
- submission-hook and stored-attribution path: Elementor Forms (Pro) and Ninja Forms

Every adapter applies one submit-time rule (Gravity Forms via #137, the others via #142): for each `ct_<key>` field the form carries, a non-empty value for `<key>` in the attribution cookie sent with the submit replaces the posted value; keys the cookie lacks are blanked (1.11.2 kept the posted value; #152 tightened this because a posted value may be another visitor's, served from a page cache). With no readable cookie, posted values are kept only for forms the browser Injector marked with `ct_fill_source=js` (CF7 and Fluent Forms, whose inputs are named `ct_*`); Gravity Forms, Elementor, WPForms, Formidable Forms and Forminator never carry the marker, so without a cookie their `ct_*` fields are blanked. When `should_populate()` is false or required marketing consent is absent, every posted `ct_*` is blanked before the provider stores or mails it (Ninja Forms: the stale `extra` value is removed). `ct_fill_source` is stripped and never stored, logged or forwarded. Server-rendered values are empty by default (filter `clicutcl_render_server_attribution_values`, default `false`), so cached form HTML carries no visitor's attribution and the browser fills it; the submit rule corrects it. Source-present / runtime-unverified for every adapter (PHPUnit and a Node VM test only, no live form plugin or page cache run). Source-present / runtime-unverified for CF7, Fluent Forms, Elementor and WPForms (PHPUnit only, no live form plugin run).

MetForm (#171) applies the same rule on `metform_filter_before_store_form_data`. Its two builders differ in how the browser fill reaches the post: the standalone block builder posts `new FormData( form )`, so a `ct_<key>` Hidden Field block is filled by the Injector (the input is named `ct_*`) and the form carries the `ct_fill_source=js` marker; the Elementor builder posts its own Preact state (the hidden input `value` read once at mount, plus field state), never the DOM the Injector writes later, so its hidden inputs are filled only at submit, it never carries the marker, and without a cookie its `ct_*` fields are blanked.

That distinction matters operationally because teams should not expect every form plugin to receive fields the same way.

## WordPress Integrations

## Forms

Managed by:

- `includes/integrations/class-form-integration-manager.php`

Source-present form adapters (runtime-unverified in this audit):

- Contact Form 7
- Elementor Forms (Pro)
- Fluent Forms
- Formidable Forms (the `hidden` field type and every hook used are in the free plugin; Formidable Pro was not exercised)
- Forminator (free and Pro; a Hidden field marked with the custom CSS class `ct_<key>`; see the Forminator bullet below)
- Gravity Forms
- MetForm (Elementor builder and the standalone block builder; hidden inputs named `ct_<key>` the site owner adds)
- Ninja Forms
- WPForms (the `hidden` field type is Pro-only; on Lite use a Single Line Text field with the `ct_<key>` CSS class, hidden by the site owner's CSS, which is filled at submit; E2E on WordPress 7.1 / Lite 2.0.3.1 verified the submit-time fill, entry meta is not testable in Lite)
- SureForms (free has no Hidden field block; the adapter marks a field of any type whose label is exactly `ct_<key>` or whose slug is `ct-<key>`, for example a Text field hidden by the site owner's CSS, and also records the attribution on every entry without any such field)

Fluent Forms hooks: `fluentform/form_element_start`, `fluentform/insert_response_data` and `fluentform/submission_inserted` on Fluent Forms 5.0.0 or later (or when `FLUENTFORM_VERSION` is undefined); the underscore names (`fluentform_*`) only on 4.x, never both, because current Fluent Forms raises a deprecation notice for a callback on `fluentform_insert_response_data`.

Formidable Forms hooks (verified against the Formidable 7.0 source; none is deprecated there): the filter `frm_get_default_value` (priority 20, `$value, $field, $dynamic_default, $return_array`; only `$dynamic_default === true`, the field default, is a value), the filter `frm_pre_create_entry` (`$values`; `FrmEntry::before_insert_entry_in_database()` calls it before the entry is packaged and stored, so `$values['item_meta']` is the place to rewrite; the callback returns the values, it does not need to touch `$_POST`) and the action `frm_after_create_entry` (`$entry_id, $form_id, $args` with `is_child`).

MetForm hooks, read from MetForm 4.3.4 `core/entries/action.php` (`Action::submit()`, REST `metform/v1/entries/insert/<form id>`; MetForm has no other submit path): `metform_before_store_form_data` (action, raw posted data; the adapter reads the `ct_fill_source` marker there because MetForm's sanitiser drops undeclared keys), `metform_filter_before_store_form_data` (filter, the sanitised data MetForm stores and puts in its admin email; the refresh, present since MetForm 3.4.0, so earlier versions refresh nothing and only log), `save_post_metform-entry` (WordPress core; the entry post ID) and `metform_after_store_form_data` (action; the ClickTrail log and entry meta). None of the four is deprecated, and the adapter registers nothing unless `METFORM_VERSION` or `\MetForm\Plugin` exists. MetForm fires no `elementor_pro/forms/*` hook, so `Elementor_Forms_Adapter` never sees a MetForm submission and one submission is logged once. Calls MetForm makes before it stores the entry (`metform/entries/submit/integrations` and the Pro integrations inside `Action::submit()`) receive the raw posted data: a hook cannot rewrite those, so they see what the browser posted.

SureForms hooks (checked against the SureForms 2.13.0 source and the 1.12.0, 2.0.0, 2.12.8 and 2.12.9 tags): the filter `srfm_before_fields_processing( $form_data )` and the action `srfm_form_submit( $response )`, both present with these signatures from 1.12.0 to 2.13.0 and neither deprecated. `srfm_before_fields_processing` runs in `Form_Submit::handle_form_entry()` after validation, `Field_Validation::strip_unknown_field_keys()` and the unchecked-checkbox backfill and before `process_form_fields()` builds the stored entry and the notification emails, so a refresh there reaches both. `srfm_form_submit` runs once the entry is stored and the notification emails are sent (`$response` has `form_id`, `entry_id` and `data`; on the "do not store entries" compliance path it has no `entry_id`). The newer `srfm_submission_accepted` (2.12.9+) and `srfm_after_submission_process` (fired by a separate browser-triggered `after-submission` REST request) are not used, so one pair of hooks covers every version. SureForms submits through `POST /wp-json/sureforms/v1/submit-form` (a `fetch` from the form page, authenticated by the `X-WP-Submit-Token` header, not by a cookie), so the attribution and consent cookies arrive in `$_COOKIE` on that REST request like on a page view: the consent gate and the cookie-authoritative rule work there unchanged.

Runtime evidence for `1.9.1`: a consent-not-required browser submission on WordPress 6.9, PHP 8.1, and Fluent Forms 6.2.13 stored `ct_ft_source`, `ct_lt_source`, and campaign metadata in `fluentform_submission_meta`. This does not establish the other adapters, consent-required paths, or erasure behavior.

Visibility evidence for `1.10.1` (2026-09-12): on WordPress 7.1, PHP 8.3 and
Fluent Forms 6.2.13 free, a successful browser submission stored first/latest
attribution metadata but the native entry did not display those fields.
Configuring six native hidden fields for first/latest source, medium and
campaign made a subsequent submission visible in the native entry and native
CSV response without plugin code changes. Older entries were not backfilled.
This consent-not-required fixture does not establish cache, consent-required,
erasure, or provider-wide compatibility. Follow the
[lead-form tutorial](../tutorials/01-lead-form-attribution.md) for that explicit
configuration; automatic metadata persistence alone is not native reporting
readiness.

What ClickTrail does:

- auto-add hidden attribution fields for Fluent Forms, and for Contact Form 7 only the mapped `ct_<key>` fields the form's own markup references (a `[hidden ct_gclid]` or any form tag named `ct_gclid`, or a `[ct_gclid]` / `[_raw_ct_gclid]` mail tag in `mail` or `mail_2`; #168)
- persist Fluent Forms attribution in `fluentform_submission_meta`, linked through Fluent Forms' `response_id` column
- populate matching hidden fields already present in Gravity Forms, WPForms, Formidable Forms, Forminator, Elementor Forms (Pro) and MetForm
- recommend that Gravity Forms, WPForms, Formidable Forms, Forminator, Elementor Forms and MetForm users add the hidden fields they want stored or exported
- keep attribution attached to submissions
- dispatch form-related events when applicable
- every adapter logs through `Abstract_Form_Adapter::log_submission()`, which writes one `form_submission` row and dispatches one event per `(provider, form_id, entry_id)`; a repeat, including a truly simultaneous one (atomic claim, #189), returns the first `event_id`; a failed log or dispatch leaves no marker, so a retry proceeds (#140). Fluent Forms' per-process static guard is replaced by this shared path, and its submission meta is written once per entry. Contact Form 7 and Elementor Forms (Pro) have no entry ID at hook time, so they dedupe best-effort on the posted `ct_*` values plus a 10 s time bucket (a repeat within 10 to 20 s is caught, including a simultaneous one across a bucket boundary; best-effort only) (see [EVENT-PIPELINE.md](../architecture/EVENT-PIPELINE.md#4-form-submission-flow)). Browser side, a repeated CF7 `wpcf7mailsent` for the same form and `unitTag` inside 10 s is one `lead`. Source-present / runtime-unverified
- Gravity Forms, WPForms, Fluent Forms, Formidable Forms and Forminator (entry meta), and SureForms (entry `extras`) write `ct_event_id` and `ct_lead_id` into the entry next to the attribution keys; Ninja Forms, Elementor Forms (Pro) and Contact Form 7 store the IDs in the `clicutcl_events` row and expose them through the `clicutcl_form_submission_logged` action (`HOOKS-REFERENCE.md`), so a CRM sync can match a closed deal back to the server-side event and the `clicutcl_events` row (#143); these IDs are not the browser `lead`'s ID. Source-present / runtime-unverified
- for Contact Form 7, record and dispatch through `wpcf7_mail_sent`, after CF7 reports success; validation failure, missing acceptance, spam, abort and mail failure do not enter the conversion logger. This denotes CF7 success (including its configured skip-mail behavior), not recipient delivery or CRM acceptance. Hook-lifecycle regression coverage lives in `tests/unit/CF7SubmissionOutcomeTest.php`; real WordPress/cache/receiver proof remains separate.
- for Contact Form 7, `wpcf7_posted_data` (priority 5, `CF7_Adapter::refresh_posted_data()`) rewrites the posted `ct_*` data from the attribution cookie sent with the submit while CF7 builds the submission, so mail tags such as `[ct_fbclid]`, Flamingo, CRM add-ons and ClickTrail's own `wpcf7_mail_sent` log all read the same submit-time values. Per posted `ct_<key>`: a non-empty cookie key wins, keys the cookie lacks are blanked, no key is added; without a cookie the posted values survive only with the Injector's `ct_fill_source=js` marker, which is stripped (#152). For the form to carry the fields on a landing page, `add_hidden_fields()` renders only the mapped keys the form references (scanned once per form per request from `WPCF7_ContactForm::get_current()`'s `form`, `mail` and `mail_2` properties; an unmapped name such as `ct_company` is never touched) as a `ct_<key>` hidden input, so a form with no `ct_*` reference carries no empty `ct_*` field in HTML, posted data, mail or Flamingo. A form that carries no `ct_*` field still gets ClickTrail's own lead: `on_submission()` logs the cookie payload through the same consent gate, empty unless `clicutcl_render_server_attribution_values` returns `true`. Before 1.10.5 a Google-then-Meta visitor's lead was stored as Google; 1.10.5 to 1.11.0 fixed ClickTrail's log only. Unit coverage: `tests/unit/CF7PostedDataRefreshTest.php`, `tests/unit/CF7SubmissionOutcomeTest.php`.
- for Gravity Forms, `gform_pre_submission` rewrites the posted value of every dynamic-population input whose parameter name starts with `ct_` from the attribution cookie sent with the submit (consent and the per-form toggle apply); keys the cookie lacks are blanked, and without a cookie every such input is blanked (the Injector never marks `input_<id>` forms; consent blocked blanks them too, #152). Gravity Forms fills those inputs server-side through `gform_field_value` only when `clicutcl_render_server_attribution_values` returns `true`; by default it returns GF's own value, so cached HTML is visitor-neutral. The render, which on the landing page runs before the browser writes the cookie, so before this change a visitor who submitted on the page they landed on stored empty ClickTrail fields. The browser runtime cannot refill them because Gravity Forms renders them as `input_<id>`, not under their parameter name. Unit coverage: `tests/unit/GravityFormsSubmissionRefreshTest.php`; no live Gravity Forms run yet.
- for Fluent Forms, `fluentform/insert_response_data` (and the legacy `fluentform_insert_response_data` alias; `Fluent_Forms_Adapter::refresh_response_data()`) rewrites each `ct_<key>` key of the response data per key before Fluent Forms stores the entry (cookie authoritative, absent keys blank, marker rule without a cookie, consent blocked blanks all, #152), replacing the earlier "cookie only when every posted `ct_*` value is empty" fallback, so the native entry, `fluentform_submission_meta` and ClickTrail's log hold the submit-time value. The rewrite is a pure function of (posted, cookie), so the double hook needs no state. Only fields Fluent Forms keeps in the response reach the filter: the inputs ClickTrail echoes at `fluentform/form_element_start` are dropped by Fluent Forms' accepted-field intersection in `SubmissionHandlerService::prepareHandler()` unless they are also configured as native hidden fields named `ct_<key>` (see the visibility evidence above). A form with no `ct_*` field keeps today's record: ClickTrail's log and `fluentform_submission_meta` receive the cookie payload and no provider field is written. Unit coverage: `tests/unit/FluentFormsSubmissionRefreshTest.php`.
- for Elementor Forms, a hidden field whose Advanced > ID (`custom_id`) is `ct_<key>` is filled from the cookie at render through `elementor_pro/forms/render/item/hidden` (`render_hidden_field()`, replacing the `value` render attribute) and refreshed from the submit-time cookie through `elementor_pro/forms/process` (`refresh_record()`, `Form_Record::update_field( $id, 'value', ... )`, verified against Elementor Pro 4.1.1), which runs after validation and before the form actions, so email, webhook and Submissions see the refreshed value; `elementor_pro/forms/new_record` runs after the actions and only logs. The render fill is opt-in through `clicutcl_render_server_attribution_values` (default off, so cached HTML is visitor-neutral); the submit refresh blanks keys the cookie lacks, blanks everything without a cookie and when consent is blocked (#152). The browser runtime cannot fill these inputs because Elementor names them `form_fields[<custom_id>]`. A form with no `ct_*` field keeps today's record: ClickTrail's log receives the cookie payload. Unit coverage: `tests/unit/ElementorFormsSubmissionRefreshTest.php`.
- for WPForms, a field is a ClickTrail field when its CSS Classes (Advanced tab) contain the token `ct_<key>` (recommended; WPForms fields have no name attribute) or its label is exactly `ct_<key>` for a key in `Attribution_Provider::get_field_mapping()` (what earlier docs implied; still accepted; `ct_gclid note` is not a marker). Render fills the hidden field through `wpforms_field_properties` (`render_field_default()`, `inputs.primary.attr.value`; `hidden` fields only, a Pro field type) only when `clicutcl_render_server_attribution_values` returns `true`; submit resolves (cookie authoritative, absent keys and the no-cookie and consent-blocked cases blank, #152) `$fields[<id>]['value']` through `wpforms_process_filter` (`refresh_fields()`), which runs after validation and formatting and before notifications and the entry. The `wpforms_field_value_*` registrations ClickTrail used until 1.11.0 were removed: WPForms Lite 2.0.3.1 (full trunk export) fires no such filter, so those hidden fields always posted empty (#151). Entry meta is still written from the cookie at `wpforms_process_complete` (Pro only); the Diagnostics scan applies the same detection rule. Unit coverage: `tests/unit/WPFormsFieldRefreshTest.php`.
- for Formidable Forms, a field is a ClickTrail field when it is a `hidden` field whose Field Key is `ct_<key>` (Formidable keeps field keys unique across all forms and appends a number to a repeat, so `ct_gclid2` and `ct_gclid3` are accepted) or whose label is exactly `ct_<key>`, for a key in `Attribution_Provider::get_field_mapping()` (`ct_gclid note` and unmapped keys such as `ct_company` are not markers; visible and non-hidden fields are never touched). `<key>` is a ClickTrail key, not the raw query parameter: UTM values live in `ct_lt_source`, `ct_lt_medium`, `ct_lt_campaign`, `ct_lt_term`, `ct_lt_content` (and the `ct_ft_*` first-touch twins), so `utm_content` is `ct_lt_content`; a field named `ct_utm_content` is not a mapped key. Formidable posts every field as `item_meta[<field id>]`, so the browser Injector, which finds inputs by name, never fills these fields and never writes the fill marker: the field is filled at render only when `clicutcl_render_server_attribution_values` returns `true` (`frm_get_default_value`, `render_field_default()`), and at submit `frm_pre_create_entry` (`refresh_entry_values()`) resolves each posted marked field (cookie authoritative, absent keys and the no-cookie and consent-blocked cases blank, #152) before Formidable packages and stores the entry, so the stored entry, the email actions (which build from the stored entry) and ClickTrail's log hold one value. The written value is slashed because Formidable stores `item_meta` slashed and unslashes on read. Entries created on an admin screen (Add entry, import; `is_admin()` and not AJAX) are neither refreshed nor logged; Formidable's own AJAX submit (`admin-ajax.php`) is a front-end submission. `frm_after_create_entry` (`on_submission()`) logs once per stored entry with the entry ID, skipping drafts (`is_draft` 1), spam (4), repeater child entries and consent-blocked submissions; ClickTrail's own lead carries the full consented cookie payload overlaid by the refreshed values, whatever `ct_*` fields the form has. Entry meta is one `frm_item_metas` row with `field_id` 0 and a serialized array (`ct_<key>` pairs, `ct_event_id`, `ct_lead_id`), the shape Formidable uses for its own non-field entry data. Not covered: finishing a saved draft (Formidable updates that entry through `frm_pre_update_entry` / `frm_after_update_entry`, which the adapter does not hook) and front-end entry edits
- for Forminator, a Hidden field is a ClickTrail field when its custom CSS class list (Styling tab) contains the token `ct_<key>` (recommended), its label is exactly `ct_<key>`, or its Default value is "Custom value" set to exactly `ct_<key>`, for a key in `Attribution_Provider::get_field_mapping()` (`ct_gclid note`, `ct_company` and a stale custom value under another default are not markers). Forminator names the input after the field ID (`hidden-1`), so the browser runtime never fills it and, with no cookie, the field is blank (no Injector marker). Render: `forminator_field_hidden_field_value` returns an empty value for a marked field, or the cookie value only when `clicutcl_render_server_attribution_values` returns `true`; a Custom value marker is never printed as the literal `ct_` name. Submit: `forminator_prepared_data` (`refresh_prepared_data()`; present in Forminator 1.17.1, absent in 1.15.0.1, so older versions leave the field blank) writes the cookie value per marked field (cookie authoritative, absent keys and the no-cookie and consent-blocked cases blank, #152) after Forminator handled the Hidden fields and before it sanitizes, validates, stores and mails them; a blank value makes Forminator drop the field from the entry, and a marked field the request did not carry is added. `forminator_custom_form_submit_field_data` (`protect_entry_fields()`) runs after Forminator built the entry fields and changes nothing but the request's own copy of a marked field's settings: when its Default value is Custom value or Embed Post/Page ID, title or URL it is switched to Query parameter, because `handle_hidden_fields_after_entry_save()` rewrites exactly those defaults to the configured value after this filter (the field would store `forminator-static` or the page URL instead of the cookie value). The stored value of every other default is `trim( forminator_replace_variables( '{' . $value . '}' ), '{}' )`, so a cookie value that is a Forminator variable name (`user_ip`, `site_url`, `form_name`) or contains one (`{user_email}`), or that starts or ends with a brace (an unexpanded `{keyword}` ValueTrack template), would be stored changed; `refresh_prepared_data()` runs that same call and leaves such a value out of the field (blank, mailed as `N/A`) instead; the entry meta and ClickTrail's log keep the real value. A field value exactly equal to `submission_id` is replaced by Forminator with the entry's submission ID when it stores the entry, so a cookie value of exactly `submission_id` is not kept in the field (the entry meta and ClickTrail's log keep the real value). Forminator submits through admin-ajax by default and the same filters run for non-AJAX forms. `forminator_custom_form_submit_before_set_fields` (`on_submission()`) runs once the entry row exists: it skips draft, abandoned and spam entries, logs through `log_submission()` with the entry ID (the cookie payload, as for WPForms) and writes `ct_<key>`, `ct_event_id` and `ct_lead_id` to the entry meta with `Forminator_Form_Entry_Model::set_fields()` (write-if-missing on a repeat; none when Forminator is set not to store submissions). When the posted `form_uid` matches an abandoned entry Forminator completes that entry (`updating_entry`), and every `set_fields()` call on it first deletes all of its meta, including Forminator's own call right after this action; the meta is then deferred to `forminator_after_handle_form` (`flush_deferred_meta()`, end of `handle_form()`, after fields, add-ons and mail) and written with the flag lowered for that one call. A Stripe checkout response returns before that action, so that request neither logs the lead nor writes entry meta. Form abandonment is off by default in Forminator (`forminator_form_abandonment_disabled` filter returns `true`); this path is unit-tested only. Hooks read from Forminator 1.58.4 source (`library/fields/hidden.php`, `library/modules/custom-forms/front/front-action.php`); none is a deprecated alias. `tools/qa/e2e` drives free Forminator 1.58.4 (three AJAX forms, one per marker style) through landing, returning visitor, cache and consent scenarios: see `tools/qa/e2e/EVIDENCE-2026-11-02-forminator.md`. Unit coverage: `tests/unit/ForminatorAdapterTest.php`. Non-AJAX forms, Forminator Pro-only fields, Stripe/PayPal pending entries and other Forminator versions are source-present / runtime-unverified
- for MetForm (#171), a hidden input named `ct_<key>` for a key in `Attribution_Provider::get_field_mapping()` is a ClickTrail field: in the Elementor builder, the Submit Button widget's Hidden Input list (Input Name `ct_gclid`, Input Value empty); in the standalone block builder (MetForm 4.1.8 and later, no Elementor needed), a Hidden Field block (Name `ct_gclid`, Value Source Static value, empty). MetForm stores only inputs the form declares, so a `ct_*` input the form does not carry is neither stored nor added by ClickTrail, and a visitor-typed `ct_company` is never touched. `MetForm_Adapter::refresh_form_data()` (`metform_filter_before_store_form_data`) rewrites each posted `ct_<key>` from the submit-time cookie (cookie authoritative, absent keys blank, no-cookie and consent-blocked cases as above), so the stored entry (`metform_entries__form_data`) and the admin email hold the submit-time value; the adapter writes nothing into the rendered form (no render fill, so `clicutcl_render_server_attribution_values` has no effect on MetForm and cached HTML stays visitor-neutral). `on_submission()` (`metform_after_store_form_data`) logs one `form_submission` row per `(metform, form_id, entry_id)` carrying the full consented cookie payload overlaid by the refreshed values (the CF7 rule), then writes `ct_<attribution key>`, `ct_event_id` and `ct_lead_id` into the `metform-entry` post meta (write-if-missing on a repeat). A form with `Store entries` off has no entry ID, so it is logged best-effort without one and no post meta is written. MetForm's admin email lists every declared block-builder field; for the Elementor builder it lists the widget fields and omits the Submit Button hidden inputs (MetForm behavior, not ClickTrail's), while the stored entry holds them. Known limit: MetForm Pro integrations that run before the entry is stored see the raw posted values (see the hooks note above).
- for SureForms, a field is a ClickTrail field when the label encoded in its key (`srfm-<type>-<block id>-lbl-<base64 label>-<slug>`) is exactly `ct_<key>`, or its slug is `ct-<key>` / `ct_<key>`, for a key in `Attribution_Provider::get_field_mapping()` (`ct_gclid note` and `ct_company` are not markers). Free SureForms has no Hidden field block (it is Pro-only), so use a Text field with that label hidden by the site's CSS (a custom CSS class on the block); SureForms' own importers name `srfm/hidden` as a Pro block, and every SureForms field key carries `-lbl-` (the free plugin validates that), so the label rule should cover it too, but the Pro plugin was not run. The field's input name is the SureForms key, not `ct_*`, so the browser Injector never fills it and the adapter renders nothing: the render rule (empty server values, #152) holds trivially and `clicutcl_render_server_attribution_values` has no effect here. At submit, `srfm_before_fields_processing` (`SureForms_Adapter::refresh_form_data()`) resolves each marked field (cookie authoritative, absent keys blank, the posted value kept only with a posted `ct_fill_source=js` and no cookie, consent blocked blanks all, marker stripped, #152) before the entry and the emails are built, so the entry and the mail show the same values. `srfm_form_submit` (`on_submission()`) then logs one `form_submission` row (platform `sureforms`, deduplicated per `(form_id, entry_id)`) holding the consented cookie payload overlaid by the refreshed values, and writes the attribution plus `ct_event_id` / `ct_lead_id` into the entry's `extras` column (SureForms' own free-form array column, merged with what it already holds; a value that is not an array is never replaced). A form with no marked field still gets the log and the `extras`. Runtime evidence (`tools/qa/e2e/EVIDENCE-2026-11-02-sureforms.md`): SureForms 2.13.0 free on WordPress 7.1 / PHP 8.3, Chromium against the harness pod, landing, returning visitor, cache (stale values posted, consent not granted) and consent accept/deny submissions stored the expected values in the entry fields, the entry `extras` and ClickTrail's lead, and the notification mail showed the field; no PHP notice or deprecation. Not run: the Pro Hidden field, other SureForms versions, a real page cache, erasure. The six-case runtime manifest stays unverified
- for Ninja Forms, store attribution in the submission extra data (`extra.clicktrail_attribution`), show it in the submission detail UI, and use the submission hooks rather than automatic hidden-field injection; `ninja_forms_submit_data` already injects the cookie payload at submit time, so Ninja Forms needed no change for #142
- gate form field injection and submission-time ClickTrail/provider metadata writes through the centralized consent policy; denied consent also removes a stale Ninja Forms `extra.clicktrail_attribution` value while preserving unrelated extras

Where teams see value:

- campaign context becomes visible in form entries or submission records
- cached or dynamic form rendering stops breaking attribution as easily
- the same attribution context can feed browser events and optional delivery flows

### Cache and visitor boundary

Cache-key normalization may decide that `/?gclid=A` and `/` share one cached object. It must not make
Visitor A's query data part of the object later served to Visitor B. Form actions, hidden fields, and
other server-rendered markup in cached HTML are response data, not attribution input for the next visitor.

Keep cached HTML visitor-neutral. ClickTrail's own form render obeys this since #152: CF7, Gravity Forms, Fluent Forms, Elementor, WPForms and Forminator emit the `ct_*` fields empty unless the site opts in with `clicutcl_render_server_attribution_values`, so a page cache needs no form-page exclusion and no `Vary` on the `attribution` cookie; the optimizer exclusions below still apply. ClickTrail browser capture reads the actual visitor's
`window.location.search` and first-party state after page load; it never imports campaign parameters from
a form action. The executable smoke regression in `tools/qa/smoke.js` pins the case where Visitor B loads
`/` while cached markup still contains `/?gclid=visitor-a`: Visitor B's ClickTrail payload remains clean.

#### Optimizer exclusions

`includes/integrations/class-optimizer-compat.php` (`Optimizer_Compat`, registered from core bootstrap next to `NitroPack_Compat`) adds `nowprocket data-no-optimize="1" data-no-defer="1" data-noptimize="1" data-cfasync="false"` to the `src` tag of `clicutcl-consent-bridge-js`, `clicutcl-attribution-js`, `clicutcl-consent-js` and `clicutcl-events-js` through `wp_script_attributes` (WordPress 6.5+ `wp_get_script_tag()`), with `script_loader_tag` as string fallback; each attribute is inserted at most once. Vendors: `nowprocket` (WP Rocket Delay JS), `data-no-optimize="1"` and `data-no-defer="1"` (LiteSpeed Cache), `data-noptimize="1"` (Autoptimize), `data-cfasync="false"` (Cloudflare Rocket Loader). Vendor filters registered unconditionally (inert when the vendor is absent; the WP Rocket and LiteSpeed path entries use the real plugin folder name, with `plugins/click-trail-handler/` kept as an extra entry when the folder differs): WP Rocket (`rocket_delay_js_exclusions`, `rocket_exclude_defer_js`, `rocket_exclude_js`), LiteSpeed Cache (`litespeed_optimize_js_excludes`, `litespeed_optm_js_defer_exc`, `litespeed_optm_gm_js_exc`), Autoptimize (`autoptimize_filter_js_exclude`), Perfmatters (`perfmatters_delay_js_exclusions`, `perfmatters_defer_js_exclusions`, `perfmatters_minify_js_exclusions`), SiteGround Speed Optimizer (`sgo_js_minify_exclude`, `sgo_javascript_combine_exclude`, `sgo_js_async_exclude`), and FlyingPress JS minification (`flying_press_exclude_from_minify:js`). The inline `clicktrail-consent-mode` snippet carries `nowprocket data-no-defer="1"`. NitroPack keeps `data-nitropack-exclude="true"` and `nitropack_js_url_exclude` in `NitroPack_Compat`. To check: view the page source, confirm every `clicutcl-*.js` tag carries the attributes, and confirm `window.ClickTrail` is defined before submitting a form. Delay-JS features run scripts on the first interaction (WP Rocket lists `keydown`, `mousemove`, `touchstart`, `scroll`, `click`, `input`, `onsubmit`), so a keyboard-only submit still runs them, too late for the landing touch. FlyingPress documents no attribute and no delay or defer filter, only the minification filter above: add `clicutcl` and `clicktrail-consent-mode` to its exclusion field. Source-present / runtime-unverified.

### Form-readiness evidence contract (M5 first slice)

The source tree contains a pure, versioned presence comparator at
`includes/Intelligence/class-form-readiness-analyzer.php` and one synthetic v1
fixture for each adapter under `tests/fixtures/form-readiness/v1/`.
This contract is **source-present / runtime-unverified**. It is not wired to an
admin endpoint, does not read live submissions, and does not prove that a form
provider durably stored a value.

The companion runtime-evidence harness is `node tools/qa/form-runtime-evidence.js`.
It validates one machine-readable manifest per adapter under
`tests/fixtures/form-runtime/v1/` and requires these six cases: AJAX/cache path,
validation failure, success, consent granted, consent denied, and stored-record
inspection. The current manifests deliberately mark all cases
`runtime_unverified` with `wordpress_plugin_runtime_unavailable`; no pinned
WordPress/form-plugin runtime is present in this checkout. Synthetic fixtures
and source wiring cannot promote an adapter to runtime-verified.

A future manifest may mark a case `verified` only with an artifact, pinned
runtime description, observation date, and bounded assertions. The harness
promotes the adapter only when all six cases are verified. The ledger must also
contain a non-empty runtime test record before a `runtime_verified` status is
allowed.

The report keeps three evidence surfaces separate:

- `provider_record`: a provider-owned entry or submission record
- `hook_payload`: values observed in the provider hook flow; not durability proof
- `clicktrail_event`: ClickTrail's own event record; not provider persistence proof

The comparator accepts field-presence snapshots only. Output is limited to
adapter/pattern IDs, allowlisted `ct_*` field names, bounded enums, booleans,
and counts. Raw attribution values, click/browser/visitor IDs, identity fields,
submission bodies, landing/referrer URLs, and secrets are neither accepted as
comparison data nor returned.

Runtime promotion still requires pinned plugin/version fixtures for the named
hook and provider record, plus consent, cache, AJAX, and browser staging. A
passing synthetic fixture proves only the comparator contract.

## WooCommerce

**Evidence boundary:** the 1.10.0 release adds automated contracts for consent-safe delivery and the WooCommerce order-meta privacy lifecycle.
Pinned local stacks exercised purchase, reload, paid, partial refund, denied-consent transport, and both order-storage modes without provider traffic.
This does not prove browser checkout, provider delivery, dedup concurrency, queue/retry behavior in a live installation, or release compatibility.

Managed by:

- `includes/integrations/class-woocommerce.php`

What the current source paths implement:

- save attribution on checkout
- render attribution in WooCommerce admin
- push purchase event to `dataLayer` only when current visitor consent permits processing, and at most once per order: the thank-you path claims `clicutcl_purchase_lock_<order_id>` with a request-unique token (`wp_cache_add()` with a persistent object cache, else a first-writer-wins `INSERT IGNORE` row `<token>|<time>`; a row older than 30 s is taken over only through a row-conditional delete of the exact stale value) before building or emitting anything, so concurrent thank-you loads emit one `dataLayer` push and one server dispatch; the release after `_clicutcl_tracking_sent` is saved is owner-checked, so a dispatch that outlives the 30 s TTL may overlap with a successor but cannot release the successor's lock (#148, source-present / runtime-unverified)
- optionally emit storefront `view_item`, `view_item_list`, `view_cart`, `add_to_cart`, `remove_from_cart`, and `begin_checkout` browser events
- preserve `item_list_name` and `item_list_index` when list context is available
- push `event_id` (`purchase_<order_id>`, same as the server purchase event) on every thank-you `purchase` push regardless of `woo_enhanced_datalayer` (#139)
- optionally widen Woo `dataLayer` pushes with consent-aware `user_data` for GTM-first setups
- store purchase and milestone trace snapshots on the order for Diagnostics lookup
- emit no thank-you purchase for an order whose paid (else created) date is older than the attribution retention window (its ClickTrail meta has been expired), and keep `_clicutcl_tracking_sent` through subject erase, retention and the Diagnostics purge so a late visit cannot count the order twice; only uninstall removes the marker
- find orders for the personal-data eraser and retention cleanup with one anchored `meta_key REGEXP` clause on `wc_get_orders()` (not one `EXISTS` clause per key, which exceeded MariaDB's 61-table join limit on HPOS, #206)
- optionally dispatch purchase and order-status milestone events into the server-side delivery pipeline

Post-purchase milestone events:

- `order_paid`
- `order_refunded`
- `order_cancelled`

Where teams see value:

- order review stays tied to campaign context
- purchase events can align browser and server-side reporting paths
- list merchandising surfaces can feed richer Woo browser events without adding destination-specific logic
- `view_cart` can be emitted from the cart page, visible mini-cart surfaces, and supported cart-drawer flows when the runtime can resolve current cart contents
- post-purchase milestones follow the same dispatcher, queue, dedup, and diagnostics model as purchases

### WooCommerce attribution handoff

The checkout handoff is an optional, consent-gated source connector. Hosts remain
authoritative for order, payment, identity, and provider delivery truth.

At checkout, ClickTrail writes attribution order metadata only when
`Attribution_Provider::should_populate()` allows processing:

1. Read the consent-gated `ct_attribution` or `attribution` cookie payload.
2. If no cookie payload is available, accept only nonce-verified `ct_*` POST fields.
3. With required consent denied or unresolved, or with an invalid or missing checkout nonce, write no attribution fields.

The metadata is in the database before any gateway redirect:

- Classic checkout: `woocommerce_checkout_create_order`; WooCommerce saves the order right after the hook and before payment processing.
- Checkout Block / Store API: `woocommerce_store_api_checkout_order_processed`; WooCommerce has already saved the order at that point, so ClickTrail saves it again (`WC_Order` CRUD, HPOS-compatible) before the gateway runs.

Orders that some express or smart-button flows create outside these two hooks get no ClickTrail metadata.

The buyer's consent snapshot is stored separately in `_clicutcl_consent` even
when attribution is denied. This snapshot records the checkout-time policy
state; it is not proof of consent for a later request.

Consent source for server-side order events (`order_paid`, `order_refunded`,
`order_refund`, `order_cancelled`, and the server copy of `purchase`):

- the buyer's own front-end request with a live consent state uses that live
  state, so a withdrawal in that request still wins
- wp-admin, REST, cron and WP-CLI requests ignore any consent cookie on the
  request. The `_clicutcl_consent` snapshot decides the dispatch, the identity
  gate (hashed email/phone, IP, user agent) and the consent sent on the event;
  the decision reaches `Dispatcher::dispatch_commerce_event()` explicitly, so a
  shop manager's cookie never decides a buyer's consent
- in those requests geo mode counts as consent-required (the buyer's location
  is unknown there; a snapshot captured where consent was not required already
  reads as granted). A missing or malformed snapshot sends nothing when consent
  is required
- a front-end request without a live consent state (for example a `?wc-api=`
  gateway callback) follows the stored snapshot the same way, so the
  requester's geography never overrides it. Limit: for an order without a
  snapshot (e.g. some express-checkout flows) that request's own consent policy
  applies, including its geography in geo mode; under required consent it
  sends nothing
- a consent-skipped order event writes no trace snapshot; it increments the
  `consent_denied` failure-telemetry counter
- EDD does not use this rule yet (its receipt purchase runs only in the buyer's request)

Canonical order metadata for external connectors:

- `_clicutcl_ft_*` — immutable first-touch fields.
- `_clicutcl_lt_*` — latest-touch fields.
- `_clicutcl_{gclid,fbclid,...}` — provider-scoped click IDs holding the latest-touch value
  (`_clicutcl_lt_<id>` wins; the first-touch copy is only a fallback). Orders saved before 1.10.5
  can hold the first visit's value; order events prefer `_clicutcl_lt_<id>` for them. `ctwa_clid`
  and other provider names remain unchanged.
- `_clicutcl_visitor_id` and `_clicutcl_session_id` — pseudonymous join keys when
  available.

The `_clicutcl_woo_trace_snapshot` diagnostic record and milestone markers are
internal trace state, not an external attribution handoff. Consumers should read
these keys and must not rewrite them. `clicutcl_order_attribution_saved`
receives the `WC_Order` after attribution metadata is written; it is a local
handoff signal, not confirmation of provider delivery, a sale, or reconciliation.

### Woo conversion-readiness contract (M6-A)

`includes/Intelligence/class-woo-readiness-analyzer.php` is a pure, versioned
contract analyzer with 16 synthetic scenarios under
`tests/fixtures/woo-readiness/v1/`. It classifies declared source, stored/live
consent, event-name form, dispatch marker, dedup, queue, value/currency, refund,
classic/HPOS, trace, Diagnostics, and erasure evidence.

The output uses `evidence_scope: contract_only`. It contains fixture IDs,
placeholder event-name forms, closed enums/reason codes, bounded integers such
as `dedup_ttl_days`, constant value-basis labels, and counts. It does not
accept or return order/customer identifiers, identities, attribution values,
IP addresses, user agents, payloads, URLs, or secrets.

The historical M6-A contract records the following issues. Current source now gates
thank-you output before attribution reads, resolves retry consent by queued subject, and
covers allowlisted Woo metadata export/erase/purge/uninstall. Their focused regression checks
do not replace browser/CMP and provider validation. Remaining boundaries include:

- purchase-path skipped dispatch can set the sent marker after the browser consent gate passes
- Dispatcher `Dedup_Store` check/send/mark is non-atomic; the thank-you purchase path is covered by the per-order lock above, server-context milestone paths are not
- queue uniqueness omits destination; strict/geo retries require a durable subject-consent resolver
- classic and HPOS core order paths, refund trace persistence, and HPOS admin hooks are runtime-tested only in isolated synthetic stacks

No Woo hook, order reader/scan, database access, provider request, new
persistence, UI, or AJAX route is added by M6-A. M6-B reproduced the core paths
and blockers on isolated classic and HPOS stacks. Refund trace persistence and
HPOS admin parity were remediated and re-tested; consent and the remaining
runtime gates are still required before release promotion.

## Easy Digital Downloads

**Evidence boundary:** EDD 3.7.3, Store (manual) gateway, one download, Chromium and MariaDB in the disposable `tools/qa/e2e/edd-ci.sh` stack (`EVIDENCE-2026-11-02-edd.md`). Stripe, PayPal, Square and EDD's REST card controllers reach the same `edd_build_order()` but were not run; the block-based receipt, refunds, EDD 2.x payments, EDD Recurring and Software Licensing were not exercised.

Managed by:

- `includes/integrations/class-edd.php`
- `includes/privacy/class-edd-order-privacy.php`
- registration: `clicutcl_init_edd()` in `clicutcl.php` on `plugins_loaded`, not in the `init` priority 20 bootstrap

Hooks, verified against EDD 3.7.3 source (`plugins.svn.wordpress.org/easy-digital-downloads/trunk/`):

| EDD surface | Signature | Used for |
|---|---|---|
| `do_action( 'edd_built_order', $order_id, $order_data )` (`includes/orders/functions/orders.php`, end of `edd_build_order()`, after `edd_insert_payment`) | `int $order_id, array $order_data` | order attribution write |
| `do_action( 'edd_order_receipt_before_table', $order, $edd_receipt_args )` (`templates/shortcode-receipt.php`; the block receipt's `totals.php` fires it with `array()`) | `EDD\Orders\Order $order, array $args` | purchase push and dispatch |
| `edd_update_order_meta( $order_id, $key, $value )`, `edd_get_order_meta( $order_id, $key = '', $single = false )`, `edd_delete_order_meta( $order_id, $key, $value = '' )` | wrappers of `update_metadata( 'edd_order', ... )` | order meta |
| `edd_get_orders( $args )` | `email`, `number`, `offset`, `orderby`, `order`, `meta_query`, `date_created_query` | privacy export, erase, retention, purge |

Why `plugins_loaded`: EDD handles the checkout POST on `init` priority 10 (`edd_post_actions`, and `wp_ajax_edd_process_checkout`), so the order is built before ClickTrail's own `init` priority 20 bootstrap. A hook registered there never sees a normal checkout.

Behavior:

- the order is built while the buyer's request is still running, including for off-site gateways, so the cookie sent with that request is what is stored; nothing is read back from the viewer on the receipt
- `edd_built_order` writes `_clicutcl_consent` (always) and the `_clicutcl_*` attribution keys (only when `Attribution_Provider::should_populate()` allows, and only keys the privacy allowlist recognizes; the provider does not allowlist cookie keys itself)
- requests from cron, WP-CLI and plain admin screens write nothing; `admin-ajax.php` and REST requests count as the buyer's, because EDD checks out through them
- the receipt emits one `purchase` only for a complete `sale` order (`complete`, `completed`, `publish`; refunded and revoked orders excluded), only while current marketing consent is granted, once per order and only for orders created within the retention window (the cookie duration, default 90 days; an older order, or one with an unreadable creation date, emits nothing because retention already expired its data): `Claim_Lock` key `clicutcl_edd_purchase_lock_<order_id>` plus `_clicutcl_tracking_sent`, re-read after the lock is won
- an order that is still `pending` when the receipt loads pushes nothing; the purchase is emitted by a later receipt load once the order is complete (no status hook is used). The customer-request context guard is a denylist: cron, WP-CLI and plain admin screens are excluded, everything else counts as the buyer's request
- the purchase-sent marker `_clicutcl_tracking_sent` holds no personal data and is kept by subject erase, retention and the Diagnostics purge, so a receipt revisited afterwards cannot push or dispatch a second copy; only uninstall removes it
- the browser push and `Dispatcher::dispatch_commerce_event()` share one payload (`event_id` `purchase_edd_<order ID>`, `transaction_id` the EDD order number, value, currency, items, stored first/last touch, checkout consent snapshot). A failed, unqueued dispatch leaves the marker unset so a reload retries
- the dataLayer contract follows the Woo classic contract (`ecommerce.*`, flat `ft_*`/`lt_*` and click IDs, `event_id`); the Woo `woo_enhanced_datalayer` / `woo_include_user_data` settings do not apply to EDD and no `user_data` is pushed
- not implemented: order-status milestones, refunds, a trace snapshot or Diagnostics lookup, and the Woo cookie fallback for orders with no stored attribution
- test-mode orders are tracked like live ones

Privacy lifecycle (the sent marker excepted, see above): `Edd_Order_Privacy` mirrors `Woo_Order_Privacy` (export, erase, bounded retention, Diagnostics purge, uninstall) through `edd_get_orders()` and the `edd_*_order_meta()` functions. It reuses the Woo key allowlist and queries it as one anchored `REGEXP` key clause, because `WP_Meta_Query` joins once per clause and the 107 `EXISTS` clauses of the Woo query fail on MariaDB with "can only use 61 tables in a join". See [DATA-MODEL.md](../architecture/DATA-MODEL.md#easy-digital-downloads-order-meta-surfaces).

## Ultimate Member

Managed by:

- `includes/integrations/class-ultimate-member.php` (active when `UM_VERSION` is defined and `UM()` exists)
- `includes/privacy/class-user-registration-privacy.php`

Hooks verified against Ultimate Member 2.14.1 source (`includes/core/class-form.php`, `includes/core/um-actions-register.php`, `includes/core/class-user.php`), not against other versions:

- `um_submit_form_register` (action, `$post`, `$form_data`; ClickTrail reads neither): fires for every registration form submit; ClickTrail only marks the request
- `um_registration_complete` (action, `$user_id`, `$args`, `$form_data`): fires from `um_after_insert_user()` (`um_user_register` priority 1) after UM stored the submitted details and extra data. `$form_data` is the UM form's own data; it is `null` when a user is added from wp-admin (`UM()->user()->user_register_via_admin()`), which ClickTrail ignores. `$args` contains the submitted password; ClickTrail never reads it.
- `um_after_user_is_created` does not exist in 2.14.1; `um_user_register` (the parent hook) also fires for wp-admin users, so `um_registration_complete` is the one used.

What ClickTrail does on a visitor registration, only when marketing consent allows (denied or undecided: nothing stored, nothing dispatched):

- writes the attribution cookie payload sent with the registration request as user meta, `_clicutcl_<key>` for the keys of `Attribution_Provider::get_field_mapping()` only (see [DATA-MODEL.md](../architecture/DATA-MODEL.md)); nothing is read from `$_POST`, so no hidden fields are needed and a cached registration page cannot leak another visitor's attribution
- dispatches one `sign_up` event through `Dispatcher::dispatch()` with the deterministic ID `um_registration_<user_id>`, `form.platform = ultimate-member`, the UM form ID and an identity resolved from the user's email plus the request IP and user agent; it is recorded as a touch event like every dispatched event, and delivered when server-side delivery is configured
- dedupes with a `Claim_Lock` claim per user (`clicutcl_um_signup_lock_<user_id>`, 30 s, released in a `finally`) and the `_clicutcl_um_signup_sent` user meta marker, so a repeat of the hook, including a simultaneous one, dispatches once; a failed dispatch leaves no marker
- registers the exporter and eraser `clicutcl-user-registration` and covers the meta in retention, the Diagnostics purge and uninstall

Core `sign_up` precedence: core `user_register` already queues a browser `sign_up` follow-up event (`Events_Logger`, see below) for every registration, including UM's. It is kept, because GTM setups read its `dataLayer` push, and aligned: while a UM registration submit is being processed the filter `clicutcl_signup_event_id` gives it the same `um_registration_<user_id>` ID as the server event, so a destination that deduplicates on event ID sees one registration. Any other registration keeps its random `signup_<uuid>` ID. If marketing consent is denied neither event exists.

Not covered: other registration paths (WordPress core `wp-login.php?action=register`, WooCommerce account creation, UM extensions that insert users themselves) keep only the core follow-up event with no attribution on the user; a generic `user_register` fallback was not built.

Status: source connector, runtime-unverified. PHPUnit covers consent, storage, dispatch, dedupe, precedence, exporter, eraser, retention and purge with doubles; `tools/qa/e2e/EVIDENCE-2026-11-02-ultimate-member.md` records one harness run (`um-ci.sh`, 43 checks, 0 FAIL: the real UM registration form, consent accept/deny/undecided, hook replay, wp-admin Add user, real WordPress exporter/eraser/retention/purge) on Ultimate Member 2.14.1 free, WordPress 7.1, PHP 8.3 only, with the ClickTrail banner as consent source and a collector stub.

## WordPress Core Follow-Up Events

Managed by:

- `includes/Modules/Events/class-events-logger.php`

What ClickTrail does:

- capture one-time follow-up events after WordPress core actions such as `wp_login`, `user_register`, and `comment_post`
- queue those events for the next frontend page load
- route them into the same browser event runtime and canonical intake path used by the rest of the browser pipeline when browser event collection is enabled

Where teams see value:

- login and signup milestones can reach the same `dataLayer` and delivery path used by other browser events
- a registration through an Ultimate Member form keeps this `sign_up`, with its event ID aligned to the Ultimate Member integration's server-side `sign_up` (filter `clicutcl_signup_event_id`, see [Ultimate Member](#ultimate-member))
- the follow-up events still respect ClickTrail's consent gate instead of bypassing the unified pipeline

## Consent and CMP Sources

Configured consent sources (source-present; runtime precedence still requires verification):

- ClickTrail plugin banner
- Cookiebot
- OneTrust
- Complianz
- GTM
- custom bridge

Implementation note:

- teams should choose one consent source of truth and wire ClickTrail to that source, rather than trying to let multiple CMP paths compete at runtime

Consent bridge assets:

- `assets/js/clicutcl-consent-bridge.js`
- `assets/js/clicutcl-consent.js`

## Browser Event and Analytics Helpers

## Google Tag Manager

Managed by:

- `includes/Modules/GTM/class-web-tag.php`
- `includes/Modules/GTM/class-gtm-settings.php`

What ClickTrail does:

- optionally inject a GTM container
- support a dedicated sGTM compatibility mode with a tagging-server URL, first-party loader delivery, and custom loader paths
- push browser and purchase events to `window.dataLayer`
- expose a GTM-first setup wizard with preview probes and destination template hints in the Events tab

Important note:

- if the site already injects GTM elsewhere, do not configure GTM injection again in ClickTrail

Implementation note:

- ClickTrail's sGTM mode only changes the loader path and rollout checks; it does not replace the canonical event pipeline with a generic GTM utility layer

Starter-kit contract:

- `assets/gtm-starter-kit.json` maps the ClickTrail `event_id` data-layer field to the Meta template's `eventId` input on every Meta tag
- Meta tags read `marketing_trail.consent.advertising` through `DLV - marketing_trail.consent.advertising`, whose missing-value default is `false`; the PageView tag listens to both `ct_page_view` (WordPress) and `page_view` (JS) so it receives the same event ID and consent envelope
- Google tags retain their native GTM consent behavior; the site's CMP or selected ClickTrail consent source must still publish the required Consent Mode state before publishing the container
- These mappings prove container wiring only. They do not prove Meta/Google acceptance, provider credentials, consent-law compliance, or destination delivery

## HubSpot Embedded Forms

Status: **source-present / runtime-unverified**. It stays that way until a live submission on a real HubSpot form
(updated editor and legacy) shows exactly one `lead` per submission (`specs/003-hubspot-form-capture/`, SC-004).

What ClickTrail does (`assets/js/clicutcl-events.js`, flow details in
[EVENT-PIPELINE.md](../architecture/EVENT-PIPELINE.md#2-browser-event-flow)):

- listens on `window` for HubSpot's documented
  [global form events](https://developers.hubspot.com/docs/api-reference/latest/marketing/forms/global-form-events)
  `hs-form-event:on-submission:success` (one `lead`, `event_id` `hsform_<conversionId>` when
  `HubSpotFormsV4.getFormFromEvent(event).getConversionId()` returns one) and `hs-form-event:on-submission:failed`
  (`form_submit_failed`, never `lead`)
- turns the documented [legacy](https://developers.hubspot.com/docs/cms/start-building/features/forms/legacy-forms)
  `hsFormCallback` / `onFormSubmitted` message into the same `lead`; other legacy callback names are ignored
- pushes through the existing consent gate, reads identifiers only (no field values), and never pushes
  `qualified_lead` from the browser

Boundaries:

- the capture-phase `submit` listener cannot see a `<form>` inside HubSpot's cross-origin iframe, and the
  hidden-field injector cannot reach its fields; the events above are the only capture path for those forms
- legacy messages pass the external-message origin rule: same host, or `clicutcl_iframe_origin_allowlist`, whose
  default now adds `hsforms.net` (the host of HubSpot's form embed scripts) next to `hubspot.com`. HubSpot does not
  document the `event.origin` of iframe-rendered legacy embeds; that is unverified
- writing ClickTrail attribution into HubSpot hidden fields (`setFieldValue`) is not implemented. It waits on live
  proof that the value reaches the contact record, the meaning of the `0-1/` field prefix, and `getForms()`
  readiness (Phase 0 in the spec's tasks)
- this browser `lead` and a HubSpot webhook `lead` for the same submission are separate events; see
  [HubSpot mapping](#hubspot-mapping) before enabling both

## External Form Source Webhooks

Webhook ingress providers (runtime-unverified in this audit):

- Calendly
- HubSpot
- Typeform

Route pattern:

- `POST /wp-json/clicutcl/v2/webhooks/{provider}`

Security:

- provider signature verification
- provider enablement
- replay-window checks on signed provider timestamps (HubSpot v3, native Calendly, ClickTrail relay); HubSpot v1 and
  Typeform carry no timestamp and rely on the signature replay cache and event-ID dedup. See `REST-API.md`

### HubSpot mapping

- Supported source: a HubSpot developer or private app with webhook subscriptions, signed with
  `X-HubSpot-Signature-v3` (v1 accepted only when no v3 header is sent). The provider secret is the app's client
  secret.
- Allowlist (`clicutcl_hubspot_webhook_event_types`, default `contact.creation`, `contact.propertyChange`):
  `contact.creation` records a `lead`; any type added through the filter also records a `lead`. Every other type
  (`contact.deletion`, `contact.merge`, `contact.restore`, `contact.associationChange`, `contact.privacyDeletion`,
  unknown types, payloads without a subscription type) is acknowledged (2xx, `ignored`) without recording an event,
  so HubSpot does not retry it. Before #128 every one of these was recorded as a `lead`.
- `contact.propertyChange` records a `qualified_lead` only when the changed property is `lifecyclestage` and the new
  value is `marketingqualifiedlead` or `salesqualifiedlead`. The event ID includes the stage and HubSpot's `eventId`:
  an MQL and a later SQL, or a later re-entry into a stage, are separate events, while a retry of the same delivery
  is deduplicated. Without an `eventId`, repeats of one stage for one contact dedupe within the dedup TTL
  (`clicutcl_v2_dedup_ttl`). Change the rule with the `clicutcl_hubspot_qualified_lead_rule` filter.
- Every other property change is acknowledged (2xx, `ignored`) without recording an event. Before #107 the dot in
  `contact.propertyChange` was stripped, so every HubSpot webhook, including every property change, was recorded as
  `lead`; sites subscribed to property changes now record fewer, qualified events.
- Batched HubSpot payloads are evaluated item by item. Non-qualified events keep their earlier event IDs, so
  deduplication of retries is unaffected.
- Workflow "Send a webhook" actions are not supported. They sign with v2 (`secret + method + URI + body`), which
  ClickTrail does not verify, so they fail with `401`; their body is the contact record without `subscriptionType`.
  Use an app webhook subscription instead.
- A HubSpot `lead` captured in the browser (form submission on the site) and a webhook `lead` for the same contact
  never deduplicate against each other: `Dedup_Store` is keyed per route (`ingest` for browser intake,
  `webhook_hubspot` for the webhook) and per destination, and the two paths produce different event IDs. Pick one
  source of truth per event, for example the browser form for `lead` and the webhook for `qualified_lead` (remove
  `contact.creation` from the allowlist), or the webhook for both.

### Calendly booking handoff

Calendly keeps only `utm_*` and `salesforce_uuid` from a scheduling URL. When link decoration is enabled,
`calendly.com` is an allowed domain, and marketing consent is granted, ClickTrail decorates Calendly links, inline
widgets (`.calendly-inline-widget[data-url]`), and `Calendly.initPopupWidget` / `initInlineWidget` /
`initBadgeWidget` URLs with:

- the stored last-touch `utm_*` values (values already in the URL are kept)
- `salesforce_uuid=ct1;<click_id>=<value>;c=<0|1>`: one click ID and the live consent decision. The click is
  `attribution_selected_click_id` (the newest ad click across platforms, D3), so Google A then Meta B stamps
  `fbclid=B`. When a selection exists the stamp carries that click or none (a consent-only `ct1;c=1`), never an
  older click from another platform. The webhook parses `gclid`, `gbraid`, `wbraid`, `fbclid`, `msclkid`, `ttclid`,
  `li_fat_id`, `twclid`, `sccid`, `epik` and `dclid`. Payloads without a selection (written before 1.10.5) fall back to the fixed order `gclid`, `gbraid`,
  `wbraid`, `fbclid`, `msclkid`, `ttclid`, `li_fat_id`, `twclid`, `dclid`. `c` is omitted
  when there is no decision (consent not required or unresolved). Raw click-ID parameters are not appended,
  because Calendly drops them. A `salesforce_uuid` already set by the site is never replaced. Decoration is
  re-checked against live consent on every click, so an in-page withdrawal stops it immediately.

Links and inline widgets are decorated from the site's original URL every time (kept in `data-clicutcl-href` /
`data-clicutcl-url`), never from an earlier decoration. A newer touch replaces ClickTrail's parameters instead of
leaving stale ones, and parameters the site wrote itself, including edits made after decoration, are kept. On
consent withdrawal ClickTrail removes the parameters it added from every decorated link. An inline Calendly iframe
or a popup that already loaded keeps the URL it loaded with; the restored `data-url` only affects later renders.

On `invitee.created` the webhook maps `tracking.utm_*` to last touch and parses the stamp back into the click ID
and a consent snapshot, so a consented booking can be delivered when consent is required. Any other
`salesforce_uuid` value is treated as the site's own Salesforce ID and ignored. Inline widgets initialised by
Calendly's script before ClickTrail runs keep their original URL. The `salesforce_uuid` length limit and a live
booking remain unverified.

The invitee email is never sent or queued in clear text. With a `c=1` stamp the event carries only
`identity.hashed_email` (SHA-256 of the trimmed, lowercased address, via `Identity_Resolver`); without `c=1` it
carries no identity, including on sites where consent is not required. The adapter reads no phone number.

## Lifecycle Updates

Route:

- `POST /wp-json/clicutcl/v2/lifecycle/update`

Purpose:

- allow backend or CRM systems to report lifecycle progress into the same canonical pipeline

Where teams see value:

- lifecycle stages such as `qualified_lead` or `client_won` can re-enter the same event model used by browser and form-originated events

## Server-Side Delivery Adapter Registry

Dispatcher:

- `CLICUTCL\Server_Side\Dispatcher`

Registry adapter keys (source-present; not provider-contract verified):

- `generic`
- `sgtm`
- `meta_capi`
- `google_ads`
- `linkedin_capi`
- `pinterest_capi`
- `tiktok_events_api`

Current role of adapters:

- send canonical delivery events to the configured endpoint shape
- share queueing, retry, diagnostics, and consent gates
- stay selectable through the shared feature registry instead of hard-coded admin lists

The registry and smoke IDs prove wiring/document ownership, not provider delivery. See the evidence cards above
and `docs/reference/integration-capabilities.json` before using a provider name in public copy.

Important constraint:

- ClickTrail still uses one selected adapter key at a time. Destination toggles are capability markers and diagnostics inputs, not multi-send fan-out controls or provider-delivery proof.

Operational note:

- `Delivery` is most useful when a real downstream endpoint already exists; it is not required for base attribution capture, form enrichment, or WooCommerce order storage

## Cross-Domain Attribution Helpers

Routes:

- `POST /wp-json/clicutcl/v2/attribution-token/sign`
- `POST /wp-json/clicutcl/v2/attribution-token/verify`

Purpose:

- continue attribution across approved domains or subdomains

Best fit:

- marketing site -> app
- marketing site -> scheduler
- marketing site -> checkout

### Booking and form iframes (embed handoff)

Some booking or form widgets load in an iframe that the site's own script creates (for example a booking popup
that runs `iframe.src = url`). Link decoration never sees that URL, and rewriting `src` after the iframe is in the
page would load it twice. The site's script asks ClickTrail for the URL instead:

```js
var src = window.ClickTrail && window.ClickTrail.decorateEmbedUrl
  ? window.ClickTrail.decorateEmbedUrl(url)
  : url;
iframe.src = src;
```

`decorateEmbedUrl(url)` returns the URL to load:

- with last-touch `utm_*` and one click ID added when the host is listed in **Embedded widget hosts**
  (`embed_allowed_domains`, filter `clicutcl_embed_allowed_domains`) and marketing consent allows it
- the click ID is the D3 selection under its own key (`gclid`, `wbraid`, `gbraid`, `fbclid`, `ScCid`, ...), or none; payloads
  saved before the selection existed use the Calendly order (Google first). One click ID only, so the widget never
  receives an older click from another platform
- parameters already in the URL are never overwritten; if the URL already has any `utm_*`, no stored UTMs are added
  (the click ID still is), so one URL never mixes two campaigns
- unchanged for any other host (exact match: neither a sibling subdomain nor the `www.` variant is allowed
  implicitly), non-https URLs, signed URLs while **Do not modify signed URLs** is on (default), non-string input, or
  when there is no stored attribution
- no visitor ID, session, click history or cross-domain token is added

The widget must read these parameters from its own URL; ClickTrail does not post messages into the iframe.
`window.ClickTrail` exists once ClickTrail has run (DOMContentLoaded, `ct_ready` event, and only after consent when
consent is required); an earlier call falls back to the plain URL. Page-speed plugins that delay JavaScript until the
first interaction (NitroPack, WP Rocket "Delay JS") must exclude ClickTrail's scripts, or a fast click loads ClickTrail
too late.

### Payment provider limitations

Cross-domain decoration cannot reach external hosted-payment pages. These providers process payments on their own domain, and ClickTrail cannot add parameters to their pages:

| Provider | Domain |
|----------|--------|
| Stripe Checkout | `checkout.stripe.com` |
| PayPal | `paypal.com` |
| Mollie | `checkout.mollie.com` |
| Square | `squareup.com` |
| Klarna | `klarna.com` |

Two things keep the pre-checkout attribution through the payment hop:

1. **Order metadata at order creation.** WooCommerce orders get their `_clicutcl_*` attribution when the order is created, before the gateway redirect (see [WooCommerce attribution handoff](#woocommerce-attribution-handoff)). The thank-you page reads that metadata. It does not tag the order from the cookie or from the return referrer.
2. **Ignored payment referrers.** On the return page the browser referrer is the payment host. A referrer on a host listed by `clicutcl_ignored_referrer_hosts`, or on a subdomain of one, counts like a same-site referrer: it creates no first touch and leaves the last touch unchanged (`lt_source`, `lt_medium`, `lt_referrer`, `lt_channel`, `lt_landing_page`, `lt_touch_timestamp`, click IDs). UTMs and click IDs on the return URL still count. Referrers on other external hosts still update the last touch.

Default ignored hosts: `paypal.com`, `stripe.com`, `mollie.com`, `squareup.com`, `square.link`, `klarna.com`, `adyen.com`, `checkout.com`, `mercadopago.com`, `mercadopago.com.br`, `pagseguro.uol.com.br`. Card-issuer 3-D Secure / ACS pages run on bank-specific hosts that are **not** in the default list and that no default list can cover. When your referral reports show one, add it in two places: through the filter ([`clicutcl_ignored_referrer_hosts`](HOOKS-REFERENCE.md#clicutcl_ignored_referrer_hosts)) so ClickTrail keeps the stored touch, and in GA4 unwanted referrals (below) so GA4 keeps the session source.

**Evidence:** `tools/qa/payment-referrer.test.js` (`npm run test:attribution`) runs the attribution script in a Node VM with `requireConsent: false`, so the ignored-referrer behaviour is exercised only on the consent-not-required path. The consent-gated path (required, unresolved or granted consent) is not exercised by that test. Source-present / runtime-unverified in a live store and a live payment gateway.

A buyer who reached checkout with no prior attribution (no UTMs, click ID, or external referrer earlier in the visit) still produces an unattributed order.

GA4 is separate. GA4 reads the referrer itself, so the return page starts a GA4 session sourced to the payment host and the `purchase` event lands in that session. Add the same domains in GA4 under **Admin → Data streams → (web stream) → Configure tag settings → Show all → List unwanted referrals** ([Google help](https://support.google.com/analytics/answer/10327750)). ClickTrail's `purchase` `dataLayer` push carries the stored `ft_*` / `lt_*` fields and click IDs, and a GTM tag can send them as event parameters, but event parameters do not change GA4's session source.

See also: [Cross-Domain Limitations](../guides/IMPLEMENTATION-PLAYBOOK.md#cross-domain-limitations) in the Implementation Playbook.

## WhatsApp

Supported hosts (and the `whatsapp://` protocol):

- `wa.me`
- `whatsapp.com`
- `api.whatsapp.com`
- `web.whatsapp.com`
- `whatsapp://send?...`

What ClickTrail does:

- **Click event** (`assets/js/clicutcl-events.js`, independent of the append setting): a click on an allowlisted WhatsApp link, with consent, pushes one `contact_whatsapp_start` through the `pushEvent()` consent gate. The event carries `link_url` (the link without its `text` parameter and fragment), `phone` (the public business number, digits only, 7 to 15; empty for `wa.me/message/<id>` style links), `contact_type: whatsapp`, `cta_label` and, when the next section applies, `ref_code`. It is deduped per link for 10 seconds (`ClickTrailEvents.markOnce`), mapped to the canonical `contact_whatsapp_start` (funnel stage `mid`) and accepted on the public browser route. No event, marker or request exists without consent. The GTM starter kit trigger and GA4 tag are named `contact_whatsapp_start`.
- **Reference code** (setting **Append attribution to message**, off by default): with consent, the pre-filled text ends with `Ref: CT-<8 base32 chars>` (for example `Ref: CT-K7QZ4M2P`). The code is generated in the browser and registered first through `POST /clicutcl/v2/whatsapp-ref`, only once the visitor interacts with a WhatsApp link (pointer-over, focus, pointer-down or click on it, or the first touch anywhere on a page that holds one; a page view alone registers nothing), together with the visitor's first/last touch, selected click ID and `visitor_id`, and used to decorate the link only once the server confirmed it (a collision is rejected with 409 and retried once). Nothing is appended when no attribution is stored or registration failed, and the click event still fires. The previous `CT:<base64 of the whole store>` text is gone. An administrator resolves a code under **Diagnostics > WhatsApp Reference Lookup** or with `GET /clicutcl/v2/whatsapp-ref/<code>`; an unknown or expired code is a 404. Rows live in `wp_clicutcl_whatsapp_refs` for the attribution retention period (default 90 days), creation is capped at 100 per client IP and 20 per (visitor, IP) pair per 24 hours, and the table at 50 000 rows (the oldest are evicted at the ceiling). Consent withdrawal restores the original link and forgets the code in the browser; see [SECURITY-PRIVACY.md](../guides/SECURITY-PRIVACY.md) for what stays on the server.
- Collision odds: 8 base32 characters are 40 bits, so a new code collides with about `N / 2^40` for `N` live rows (roughly 9e-8 at 100 000 rows).
- Evidence: source-present / runtime-unverified. Node VM and PHPUnit tests cover the event, consent, dedupe, code format, collision retry, restore on withdrawal, route gates, TTL, 404 and the eraser path; a real WhatsApp compose box has not been exercised (`specs/008-whatsapp-handoff/`).

Best fit:

- campaigns that drive users into WhatsApp as the main lead handoff path

## Geo and Region Inputs

ClickTrail does not call an external geo-IP service by default.

Consent geo behavior reads server-provided headers when available, such as:

- `HTTP_CF_IPCOUNTRY`
- `HTTP_X_COUNTRY_CODE`
- `HTTP_GEOIP_COUNTRY_CODE`
- `GEOIP_COUNTRY_CODE`
- `HTTP_CF_REGION_CODE`

## Important Implementation Note

The user-facing admin now groups integrations under `Forms`, `Events`, and `Delivery`, but some of the advanced integration state still
