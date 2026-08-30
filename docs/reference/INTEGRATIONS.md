# ClickTrail Integrations Reference

- **Audience**: contributors, maintainers, reviewers, and solution engineers
- **Canonical for**: integration roles, source evidence, status boundaries, providers, forms, webhooks, GTM, and delivery adapter keys
- **Update when**: integration support level, adapter list, provider contract, evidence status, or capability messaging changes
- **Provider-wide audit baseline**: plugin code `1.9.0`, commit `a45aa9e`, reviewed 2026-08-19
- **Runtime verification**: provider-wide E2E remains incomplete; bounded WooCommerce and Fluent Forms evidence is recorded below
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
| Forms | Source connector / runtime-unverified overall | CF7, Elementor Pro, Fluent, Gravity, Ninja, and WPForms paths; bounded Fluent Forms submission-storage evidence | Other adapters; Fluent Forms consent-required and erasure paths |
| WooCommerce | Source connector / runtime-unverified | Order attribution, storefront events, purchase/milestones, traces | Purchase consent/dataLayer, order-meta cleanup, and retry-marker proof |
| Calendly, HubSpot, Typeform | Webhook ingress / runtime-unverified | Signed inbound routes and canonical translation | Provider timestamp, identity minimization, replay, consent, and E2E proof |

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

- automatic hidden-field injection: Contact Form 7 and Fluent Forms
- compatible hidden-field population: Gravity Forms and WPForms
- submission-hook and stored-attribution path: Elementor Forms (Pro) and Ninja Forms

That distinction matters operationally because teams should not expect every form plugin to receive fields the same way.

## WordPress Integrations

## Forms

Managed by:

- `includes/integrations/class-form-integration-manager.php`

Source-present form adapters (runtime-unverified in this audit):

- Contact Form 7
- Elementor Forms (Pro)
- Fluent Forms
- Gravity Forms
- Ninja Forms
- WPForms

Runtime evidence for `1.9.1`: a consent-not-required browser submission on WordPress 6.9, PHP 8.1, and Fluent Forms 6.2.13 stored `ct_ft_source`, `ct_lt_source`, and campaign metadata in `fluentform_submission_meta`. This does not establish the other adapters, consent-required paths, or erasure behavior.

What ClickTrail does:

- auto-add hidden attribution fields for Contact Form 7 and Fluent Forms
- persist Fluent Forms attribution in `fluentform_submission_meta`, linked through Fluent Forms' `response_id` column
- populate matching hidden fields already present in Gravity Forms and WPForms
- recommend that Gravity Forms and WPForms users add the hidden fields they want stored or exported
- keep attribution attached to submissions
- dispatch form-related events when applicable
- for Elementor Forms, log submissions through Elementor Pro's official `elementor_pro/forms/new_record` hook and read matching `ct_*` hidden fields when they are present, with cookie fallback when they are not
- for Ninja Forms, store attribution in the submission extra data (`extra.clicktrail_attribution`), show it in the submission detail UI, and use the submission hooks rather than automatic hidden-field injection

Where teams see value:

- campaign context becomes visible in form entries or submission records
- cached or dynamic form rendering stops breaking attribution as easily
- the same attribution context can feed browser events and optional delivery flows

### Form-readiness evidence contract (M5 first slice)

The source tree contains a pure, versioned presence comparator at
`includes/Intelligence/class-form-readiness-analyzer.php` and one synthetic v1
fixture for each of the six adapters under `tests/fixtures/form-readiness/v1/`.
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

**Evidence boundary:** classic/HPOS synthetic runtime reproduced; consent
remediation pending. Pinned local stacks exercised purchase, reload, paid,
partial refund, denied-consent transport, and both order-storage modes without provider traffic.
This does not prove browser checkout, provider delivery, dedup concurrency,
queue/retry, privacy-lifecycle closure, or release compatibility.

Managed by:

- `includes/integrations/class-woocommerce.php`

What the current source paths implement:

- save attribution on checkout
- render attribution in WooCommerce admin
- push purchase event to `dataLayer`
- optionally emit storefront `view_item`, `view_item_list`, `view_cart`, `add_to_cart`, `remove_from_cart`, and `begin_checkout` browser events
- preserve `item_list_name` and `item_list_index` when list context is available
- optionally widen Woo `dataLayer` pushes with `event_id` and consent-aware `user_data` for GTM-first setups
- store purchase and milestone trace snapshots on the order for Diagnostics lookup
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

The contract records these source-level blockers without remediating them:

- purchase `dataLayer` lacks a top-level consent gate and identity reads live-cookie consent
- purchase-path skipped dispatch can set the sent marker
- dedup check/send/mark is non-atomic
- queued replay does not re-check consent and queue uniqueness omits destination
- identity-bearing Woo trace metadata lacks complete erasure/purge/uninstall coverage
- classic and HPOS core order paths, refund trace persistence, and HPOS admin hooks are runtime-tested only in isolated synthetic stacks

No Woo hook, order reader/scan, database access, provider request, new
persistence, UI, or AJAX route is added by M6-A. M6-B reproduced the core paths
and blockers on isolated classic and HPOS stacks. Refund trace persistence and
HPOS admin parity were remediated and re-tested; consent and the remaining
runtime gates are still required before release promotion.

## WordPress Core Follow-Up Events

Managed by:

- `includes/Modules/Events/class-events-logger.php`

What ClickTrail does:

- capture one-time follow-up events after WordPress core actions such as `wp_login`, `user_register`, and `comment_post`
- queue those events for the next frontend page load
- route them into the same browser event runtime and canonical intake path used by the rest of the browser pipeline when browser event collection is enabled

Where teams see value:

- login and signup milestones can reach the same `dataLayer` and delivery path used by other browser events
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
- replay-window checks

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

### Payment provider limitations

Cross-domain decoration cannot reach external hosted-payment pages. These providers process payments on their own domain and strip any injected query parameters before the user reaches them:

| Provider | Domain |
|----------|--------|
| Stripe Checkout | `checkout.stripe.com` |
| PayPal | `paypal.com` |
| Mollie | `checkout.mollie.com` |
| Square | `squareup.com` |

Attribution survives these redirects only via the attribution cookie written before checkout. On return to your confirmation page, ClickTrail reads the cookie and tags the order as normal.

If the user had no prior cookie when entering the payment flow, the order will be unattributed. This is a documented limitation of cookie-based cross-domain attribution — not a bug.

See also: [Cross-Domain Limitations](../guides/IMPLEMENTATION-PLAYBOOK.md#cross-domain-limitations) in the Implementation Playbook.

## WhatsApp

Supported hosts:

- `wa.me`
- `whatsapp.com`
- `api.whatsapp.com`
- `web.whatsapp.com`

What ClickTrail does:

- preserve attribution continuity in WhatsApp links
- optionally append attribution context to pre-filled messages

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
