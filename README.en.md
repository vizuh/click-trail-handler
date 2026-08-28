# ClickTrail

[![Support](https://img.shields.io/badge/support-active-brightgreen.svg)](https://github.com/vizuh/click-trail-handler)
[![Release](https://img.shields.io/github/v/release/vizuh/click-trail-handler?label=release&color=blue)](https://github.com/vizuh/click-trail-handler/releases)
[![WordPress tested](https://img.shields.io/badge/WordPress-v7.1%20tested-3858e9.svg)](https://wordpress.org)
[![License](https://img.shields.io/badge/license-GPL--2.0-orange.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

[![PHP Linting](https://github.com/vizuh/click-trail-handler/actions/workflows/php-lint.yml/badge.svg)](https://github.com/vizuh/click-trail-handler/actions/workflows/php-lint.yml)
[![PHPUnit Tests](https://github.com/vizuh/click-trail-handler/actions/workflows/phpunit.yml/badge.svg)](https://github.com/vizuh/click-trail-handler/actions/workflows/phpunit.yml)
[![CodeQL](https://github.com/vizuh/click-trail-handler/actions/workflows/codeql.yml/badge.svg)](https://github.com/vizuh/click-trail-handler/actions/workflows/codeql.yml)
[![Dependency Review](https://github.com/vizuh/click-trail-handler/actions/workflows/dependency-review.yml/badge.svg)](https://github.com/vizuh/click-trail-handler/actions/workflows/dependency-review.yml)

![ClickTrail](.github/clicktrail-cover.png)

> **Integration verification status (2026-08-19):** The source registry shows wiring, not production provider support.
> PHP/WordPress/provider E2E verification was unavailable for this audit. Platform-named server adapters are
> **source-present / runtime-unverified configured-endpoint adapters**. GTM can mediate site-owned provider tags;
> ClickTrail does not inject Meta/Facebook Pixel, Google tag, TikTok Pixel, LinkedIn Insight, Pinterest Tag, or
> Reddit Pixel SDKs. Reddit has a **relay-only** destination toggle and `rdt_cid` capture, not a native delivery
> adapter. See the [integration evidence ledger](docs/reference/integration-capabilities.json) and
> [integration reference](docs/reference/INTEGRATIONS.md).

Attribution usually breaks somewhere between the ad click and the conversion. ClickTrail keeps campaign context alive through the journey to the WordPress conversion.

ClickTrail is a WordPress attribution plugin for sites that need campaign source data to remain available through real-world journeys, especially when WooCommerce orders or lead forms happen several pages after the landing page.

**What ClickTrail is not:** it is not an attribution dashboard, a hosted server-side GTM platform, a lead manager, or an ad optimizer. It complements GA4 and GTM. Browser tags remain site-owned, while configured-endpoint server adapters remain source-present/runtime-unverified in the current baseline.

It is built for the problems that usually break attribution in production:

- cached pages
- dynamic or AJAX-loaded forms
- multi-page and multi-session journeys
- cross-domain flows
- consent-controlled tracking requirements, with current edge cases documented in [Security and Privacy](docs/guides/SECURITY-PRIVACY.md)
- optional server-side delivery, subject to the current runtime verification boundary

Instead of capturing a UTM once and hoping it survives, ClickTrail keeps first-touch and last-touch context available until WooCommerce orders, forms, browser events, or downstream delivery flows actually need it.

ClickTrail keeps the source of the visit, not a profile of the visitor. Capture is first-party and includes consent controls; the plugin does not call external services to identify or enrich visitors by default, and data leaves your site only through integrations you enable. Review the current security-status blockers before treating any path as privacy-complete.

## What ClickTrail Does

ClickTrail captures first-touch and last-touch attribution, keeps it available across the visit lifecycle, and makes that data usable where conversions actually happen inside WordPress.

It combines:

- attribution capture
- WooCommerce order attribution and purchase enrichment
- form enrichment
- browser event collection
- consent controls with documented runtime verification boundaries
- optional server-side transport with retries and diagnostics

That means you can start with campaign-aware WooCommerce orders or form attribution first, then add browser events, consent integrations, or server-side delivery when your setup actually needs them.

## Release Notes

The release badge above tracks the current GitHub release. See [changelog.txt](changelog.txt) for the full history and [readme.txt](readme.txt) for the public WordPress.org stable release.

## Problems It Solves

### 1. Lost campaign attribution in WordPress

Users land with UTMs or ad click IDs, browse a few pages, and convert later. Other visitors arrive from organic search or social referrals with no tags at all. Without persistence, the conversion record loses the original source.

ClickTrail keeps the source trail available through forms, checkout, and event payloads.

### 2. Cached and dynamic pages breaking hidden fields

Many attribution plugins rely on server-rendered hidden fields only. That breaks when pages are cached or forms are injected after page load.

ClickTrail includes a client-side capture fallback and dynamic-content watching so attribution still reaches configured forms and matching hidden fields.

### 3. WooCommerce orders with weak or missing source data

Paid traffic often ends up looking like direct traffic in order records.

ClickTrail stores attribution on the order, pushes enriched purchase data to the dataLayer, and can optionally extend the same Woo journey into `view_item`, `view_item_list`, `view_cart`, `add_to_cart`, `remove_from_cart`, `begin_checkout`, richer Woo `user_data`, post-purchase milestones, and server-side dispatch.

### 4. Cross-domain journeys losing continuity

If users move between marketing site, app, scheduler, or checkout domain, attribution often resets.

ClickTrail supports approved cross-domain link decoration and token-based attribution continuity.

### 5. Consent and delivery living in separate tools

Teams often need privacy controls, event delivery, and attribution logic to agree with each other.

ClickTrail keeps consent, event intake, and delivery configuration in the same plugin.

## Core Capabilities

### Capture

- First-touch and last-touch UTMs, including `utm_id`, `utm_source_platform`, `utm_creative_format`, and `utm_marketing_tactic`
- Referrer capture with automatic organic, social, and referral fallback when UTMs are absent
- Major ad click ID and first-party ad/browser identifier capture
- Configurable attribution retention
- Cross-domain link decoration
- Optional attribution token continuity

Supported click IDs include:

- `gclid`
- `wbraid`
- `gbraid`
- `fbclid`
- `ttclid`
- `msclkid`
- `twclid`
- `li_fat_id`
- `sccid`
- `epik`

Additional browser identifiers include:

- `fbc`
- `fbp`
- `_ttp`
- `li_gc`
- `ga_client_id`
- `ga_session_id`

### Forms (source connectors; runtime-unverified in this audit)

ClickTrail connects to forms in three documented patterns. Confirm which pattern applies before testing:

1. **Automatic hidden fields** — Contact Form 7 and Fluent Forms receive attribution fields through the documented path.
2. **Matching hidden fields** — Gravity Forms and WPForms populate the `ct_*` fields you add to the form.
3. **Submission storage** — Elementor Forms (Pro) and Ninja Forms attach attribution to the submission record instead of injecting hidden fields.

- Automatic hidden-field enrichment for Contact Form 7 and Fluent Forms
- Compatible hidden-field population for Gravity Forms and WPForms when matching hidden fields are present
- Recommended for Gravity Forms and WPForms: add the hidden fields you want stored or exported, and ClickTrail will fill them
- Client-side fallback for cached pages
- Dynamic form detection
- Optional replacement of existing attribution values
- WhatsApp attribution append support
- External form source webhook intake for documented providers; runtime verification remains separate

### Events

- Browser event collection
- GA4-friendly `dataLayer` pushes
- Search, file download, scroll depth, time-on-page, lead-gen interaction events, and one-time WordPress follow-up events such as `login`, `sign_up`, and `comment_submit`
- Optional Woo storefront `view_item_list` with `item_list_name` and `item_list_index` context
- Optional richer Woo `dataLayer` contract with a consent-sensitive `user_data` branch; runtime edge cases remain under the release gates
- Lifecycle update intake for downstream CRM / backend workflows
- Unified canonical event pipeline behind the scenes

### Delivery

- Optional server-side transport
- Retry queue with backoff
- Delivery diagnostics and failure telemetry
- Dispatcher consent gating with documented edge cases
- Queue backlog visibility and endpoint tests

## Integration inventory and status

### WordPress and frontend

- WordPress 6.5+
- PHP 8.1+
- Built-in consent banner when using the plugin as consent source
- GTM container injection when needed
- sGTM compatibility mode with tagging-server URL, first-party script delivery, and custom loader support

### Forms sources (source-present / runtime-unverified)

- Contact Form 7
- Elementor Forms (Pro)
- Fluent Forms
- Gravity Forms
- Ninja Forms
- WPForms

Form behavior by plugin:

- Contact Form 7 and Fluent Forms can receive hidden attribution fields automatically
- Gravity Forms and WPForms can populate matching hidden fields you add to the form
- Elementor Forms (Pro) use their submission hooks and attribution fallback instead of automatic hidden-field injection
- Ninja Forms stores attribution with the submission record and surfaces it in the submission detail UI instead of automatic hidden-field injection

### Commerce source (runtime-unverified in this audit)

- WooCommerce order attribution
- WooCommerce enriched purchase event push to `dataLayer`
- Optional Woo storefront events for `view_item`, `view_item_list`, `view_cart`, `add_to_cart`, `remove_from_cart`, and `begin_checkout`
- Optional richer Woo `dataLayer` contract for GTM-first setups
- Optional server-side purchase dispatch
- WooCommerce HPOS compatibility declaration for order storage/tracking

### Webhook ingress sources (source-present / runtime-unverified)

- Calendly
- HubSpot
- Typeform

### Server-side adapter keys (source-present / runtime-unverified)

- Generic collector — configured endpoint relay
- sGTM — configured endpoint relay; preview SSRF hardening remains open
- Meta CAPI — adapter key present; provider API/auth contract not runtime-verified
- Google Ads / GA4 — adapter key present; provider API/auth contract not runtime-verified
- LinkedIn CAPI — adapter key present; provider API/auth contract not runtime-verified
- Pinterest Conversions API — adapter key present; provider API/auth contract not runtime-verified
- TikTok Events API — adapter key present; provider API/auth contract not runtime-verified

These classes currently serialize canonical JSON to a configured endpoint. They are not turnkey provider
SDK/API integrations until provider-specific fixtures and staged delivery evidence pass.

### Browser and GTM-mediated destinations

- Google Tag Manager and site-owned dataLayer configuration
- Meta/Facebook Pixel, Google tag/GA4, TikTok Pixel, LinkedIn Insight, Pinterest Tag, and Reddit Pixel only
  through a site-owned GTM/container setup; ClickTrail does not inject these SDKs
- Reddit destination toggle and `rdt_cid` capture are relay-only; no native Reddit delivery adapter is present

See the [full capability matrix](docs/reference/INTEGRATIONS.md#capability-matrix) for forms, WooCommerce,
webhook ingress, consent sources, and evidence IDs.

## Admin Experience

The main settings experience is organized by capability instead of internal implementation names:

- **Capture**: source capture, retention, and cross-domain continuity
- **Forms**: on-site form diagnostics, WhatsApp, and external form sources
- **Events**: browser event collection, GTM, destinations, and lifecycle updates
- **Delivery**: server-side transport, privacy, and operational safeguards

Separate operational screens remain available for:

- **Logs**
- **Diagnostics**

Operational tooling now includes:

- a read-only setup checklist in Settings
- an interactive conflict scan
- settings backup export and restore
- Woo order trace lookup for stored purchase and milestone payloads

This keeps the main configuration flow focused while still exposing queue health and debugging tools when needed.

## Privacy and Consent

ClickTrail contains consent controls for attribution and event handling, but the current audit found unresolved edge cases across legacy consent state, revocation, queues, WooCommerce, forms, and dataLayer output.

- Consent mode can be enabled or disabled.
- Consent behavior supports `strict`, `relaxed`, and `geo`.
- CMP source can be auto-detected or set to plugin, Cookiebot, OneTrust, Complianz, GTM, or custom.
- The plugin can run its own lightweight consent banner when configured as the consent source.

ClickTrail helps with privacy-aware implementation, but compliance still depends on your legal requirements and configuration choices.

## Installation

### Before you start

ClickTrail does not need to be fully enabled on day one. A basic forms or WooCommerce attribution setup can work without server-side delivery.

- If you only need attribution inside forms or WooCommerce, leave server-side delivery off for now.
- If your site already injects Google Tag Manager, do not enter the GTM container ID again in ClickTrail.
- If you use Gravity Forms or WPForms, add the `ct_*` hidden fields you want stored or exported before testing.
- If your site has consent requirements, decide whether ClickTrail or your existing CMP should be the source of truth.

### Recommended first setup

1. Install the plugin through WordPress or upload it to `/wp-content/plugins/click-trail-handler/`.
2. Activate the plugin and open `ClickTrail > Settings`.
3. In `Capture`, keep attribution enabled, choose a retention window that matches your sales cycle, and enable cross-domain continuity only when visitors actually move between approved domains or subdomains.
4. In `Forms`, enable only the integrations you use. Contact Form 7 and Fluent Forms can receive hidden attribution fields automatically. Gravity Forms and WPForms should have the matching `ct_*` hidden fields you want to preserve, such as `ct_ft_source`, `ct_lt_source`, or `ct_gclid`.
5. In `Events`, leave browser events enabled only if you want `dataLayer` pushes and on-site event capture. Enable Woo storefront events only if you want `view_item`, `view_item_list`, `view_cart`, `add_to_cart`, `remove_from_cart`, and `begin_checkout`. Turn on the richer Woo `dataLayer` contract only if you want `event_id` and consent-aware `user_data` for GTM-first flows. Add a GTM container ID only if your site does not already inject GTM elsewhere.
6. In `Delivery`, leave server-side delivery off unless you already have a collector, sGTM, or advertising endpoint ready. If consent is required, choose the correct consent source and mode before going live.
7. Open `ClickTrail > Diagnostics` and run the relevant checks.

### How to confirm it is working

1. Visit your site with a test URL such as `?utm_source=test&utm_medium=cpc&utm_campaign=clicktrail-install-check`.
2. Browse to another page, then submit a configured form or place a test WooCommerce order.
3. Confirm the result you expect:
   - the form entry or WooCommerce order contains attribution values
   - browser events appear in your GTM preview or `dataLayer` if `Events` is enabled
   - Diagnostics and Logs show event intake or delivery activity if `Delivery` is enabled

### Good default rollout

Start with `Capture` and the integrations you already use. Add `Events` next if you want browser analytics signals. Add `Delivery` only when you are ready to send data to a collector or advertising endpoint.

## Typical Use Cases

- [Agencies and service businesses that need attribution inside lead forms](docs/guides/USE-CASES.md#lead-generation-forms)
- [WooCommerce stores that want campaign-aware order data](docs/guides/USE-CASES.md#woocommerce-orders)
- [Sites with aggressive caching or dynamic form rendering](docs/guides/USE-CASES.md#cached-and-dynamic-forms)
- [Businesses running approved multi-domain funnels](docs/guides/USE-CASES.md#approved-multi-domain-funnels)
- [Teams aligning capture with an existing consent source](docs/guides/USE-CASES.md#consent-aware-sites)

If you need call tracking, lead scoring, multi-touch revenue modeling, or ad-spend optimization, ClickTrail is not that tool; pair it with the specialist platform you already use.

## Tutorials

- [Lead form attribution](docs/tutorials/01-lead-form-attribution.md)
- [WooCommerce order attribution](docs/tutorials/02-woocommerce-order-attribution.md)
- [Consent and browser events](docs/tutorials/03-consent-and-events.md)

## Release phasing and evidence

The [release-phasing plan](docs/guides/RELEASE-PHASING-AND-INTEGRATION-DOCS.md) separates truth-containment
docs, consent/privacy remediation, delivery integrity, provider-contract releases, and later reach work.

## Repository Docs

- [Implementation playbook](docs/guides/IMPLEMENTATION-PLAYBOOK.md)
- [Technical documentation index](docs/README.md)
- [Contributor guide](CONTRIBUTING.md)
- [Integrations reference](docs/reference/INTEGRATIONS.md)
- [Full changelog](changelog.txt)
- [WordPress.org readme](readme.txt)
- [Competitive positioning and acquisition roadmap](docs/guides/COMPETITIVE-POSITIONING-AND-ACQUISITION-ROADMAP-2026-08-22.md)

## Notes on Current Architecture

- The public admin UI no longer uses "Tracking v2" terminology.
- Internally, some runtime settings still live in the `clicutcl_tracking_v2` option for backward compatibility.
- The legacy v1 log controller has been removed; `clicutcl/v2` is the only active REST namespace. See [REST API](docs/reference/REST-API.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
