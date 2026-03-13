# ClickTrail

ClickTrail is a WordPress attribution plugin for sites that need reliable marketing source data to survive real-world user journeys, especially when WooCommerce orders or lead forms happen several pages after the landing page.

It is built for the problems that usually break attribution in production:

- cached pages
- dynamic or AJAX-loaded forms
- multi-page and multi-session journeys
- cross-domain flows
- consent-aware tracking requirements
- optional server-side delivery

Instead of capturing a UTM once and hoping it survives, ClickTrail keeps first-touch and last-touch context available until WooCommerce orders, forms, browser events, or downstream delivery flows actually need it.

## What ClickTrail Does

ClickTrail captures first-touch and last-touch attribution, keeps it available across the visit lifecycle, and makes that data usable where conversions actually happen inside WordPress.

It combines:

- attribution capture
- WooCommerce order attribution and purchase enrichment
- form enrichment
- browser event collection
- consent-aware tracking controls
- optional server-side transport with retries and diagnostics

That means you can start with campaign-aware WooCommerce orders or reliable form attribution first, then add browser events, consent integrations, or server-side delivery when your setup actually needs them.

## Latest Release Notes (1.4.0)

Version `1.4.0` strengthens the WooCommerce layer while keeping ClickTrail's broader attribution and delivery architecture intact.

- **WooCommerce HPOS declaration**: ClickTrail now declares compatibility with WooCommerce custom order tables during bootstrap and keeps order-tracking state on Woo order APIs.
- **Richer purchase payloads**: Woo purchase events now keep the old keys while adding `subtotal`, `tax_total`, `shipping_total`, `discount_total`, `discount_codes`, `status`, `order_currency`, `item_quantity`, plus richer item detail such as `product_id`, `sku`, `variant`, and `categories`.
- **Opt-in storefront events**: Woo storefront `view_item`, `add_to_cart`, `remove_from_cart`, and `begin_checkout` events can now be enabled explicitly through the existing ClickTrail browser event layer.
- **Clearer admin guidance**: the Events tab now includes a WooCommerce card that explains order attribution storage, purchase event pushes, optional storefront events, and where to verify them.
- **Recent hardening included**: the release also carries the WordPress.org deployment cleanup, Plugin Check/privacy-query fixes, and debug visibility improvements from the current maintenance pass.

For the full release history, see [changelog.txt](changelog.txt). The same public release notes are mirrored in [readme.txt](readme.txt) for the WordPress.org plugin page.

## Problems It Solves

### 1. Lost campaign attribution in WordPress

Users land with UTMs or ad click IDs, browse a few pages, and convert later. Other visitors arrive from organic search or social referrals with no tags at all. Without persistence, the conversion record loses the original source.

ClickTrail keeps the source trail available through forms, checkout, and event payloads.

### 2. Cached and dynamic pages breaking hidden fields

Many attribution plugins rely on server-rendered hidden fields only. That breaks when pages are cached or forms are injected after page load.

ClickTrail includes a client-side capture fallback and dynamic-content watching so attribution still reaches supported forms and matching hidden fields.

### 3. WooCommerce orders with weak or missing source data

Paid traffic often ends up looking like direct traffic in order records.

ClickTrail stores attribution on the order, pushes enriched purchase data to the dataLayer, and can optionally extend the same Woo journey into `view_item`, `add_to_cart`, `remove_from_cart`, `begin_checkout`, and server-side dispatch.

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

### Forms

- Automatic hidden-field enrichment for Contact Form 7 and Fluent Forms
- Compatible hidden-field population for Gravity Forms and WPForms when matching hidden fields are present
- Recommended for Gravity Forms and WPForms: add the hidden fields you want stored or exported, and ClickTrail will fill them
- Client-side fallback for cached pages
- Dynamic form detection
- Optional replacement of existing attribution values
- WhatsApp attribution append support
- External form source webhook intake for supported providers

### Events

- Browser event collection
- GA4-friendly `dataLayer` pushes
- Search, file download, scroll depth, time-on-page, lead-gen interaction events, and one-time WordPress follow-up events such as `login`, `sign_up`, and `comment_submit`
- Lifecycle update intake for downstream CRM / backend workflows
- Unified canonical event pipeline behind the scenes

### Delivery

- Optional server-side transport
- Retry queue with backoff
- Delivery diagnostics and failure telemetry
- Consent-aware dispatch gating
- Queue backlog visibility and endpoint tests

## Supported Integrations

### WordPress and frontend

- WordPress 6.5+
- PHP 8.1+
- Built-in consent banner when using the plugin as consent source
- GTM container injection when needed

### Forms

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

### Commerce

- WooCommerce order attribution
- WooCommerce enriched purchase event push to `dataLayer`
- Optional Woo storefront events for `view_item`, `add_to_cart`, `remove_from_cart`, and `begin_checkout`
- Optional server-side purchase dispatch
- WooCommerce HPOS compatibility declaration for order storage/tracking

### External providers

- Calendly
- HubSpot
- Typeform

### Server-side adapters

- Generic collector
- sGTM
- Meta CAPI
- Google Ads / GA4
- LinkedIn CAPI

## Admin Experience

The main settings experience is organized by capability instead of internal implementation names:

- **Capture**: source capture, retention, and cross-domain continuity
- **Forms**: on-site form reliability, WhatsApp, and external form sources
- **Events**: browser event collection, GTM, destinations, and lifecycle updates
- **Delivery**: server-side transport, privacy, and operational safeguards

Separate operational screens remain available for:

- **Logs**
- **Diagnostics**

This keeps the main configuration flow focused while still exposing queue health and debugging tools when needed.

## Privacy and Consent

ClickTrail supports consent-aware attribution and event handling.

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
5. In `Events`, leave browser events enabled only if you want `dataLayer` pushes and on-site event capture. Enable Woo storefront events only if you want `view_item`, `add_to_cart`, `remove_from_cart`, and `begin_checkout`. Add a GTM container ID only if your site does not already inject GTM elsewhere.
6. In `Delivery`, leave server-side delivery off unless you already have a collector, sGTM, or advertising endpoint ready. If consent is required, choose the correct consent source and mode before going live.
7. Open `ClickTrail > Diagnostics` and run the relevant checks.

### How to confirm it is working

1. Visit your site with a test URL such as `?utm_source=test&utm_medium=cpc&utm_campaign=clicktrail-install-check`.
2. Browse to another page, then submit a supported form or place a test WooCommerce order.
3. Confirm the result you expect:
   - the form entry or WooCommerce order contains attribution values
   - browser events appear in your GTM preview or `dataLayer` if `Events` is enabled
   - Diagnostics and Logs show event intake or delivery activity if `Delivery` is enabled

### Good default rollout

Start with `Capture` and the integrations you already use. Add `Events` next if you want browser analytics signals. Add `Delivery` only when you are ready to send data to a collector or advertising endpoint.

## Typical Use Cases

- Agencies that need attribution inside lead forms
- WooCommerce stores that want campaign-aware order data
- WooCommerce stores that want richer purchase payloads without replacing their existing tracking stack
- Sites with aggressive caching or dynamic form rendering
- Businesses running multi-domain funnels
- Teams that need browser and server-side tracking in one WordPress plugin

## Repository Docs

- [Implementation playbook](docs/guides/IMPLEMENTATION-PLAYBOOK.md)
- [Technical documentation index](docs/README.md)
- [Contributor guide](CONTRIBUTING.md)
- [Integrations reference](docs/reference/INTEGRATIONS.md)
- [Full changelog](changelog.txt)
- [WordPress.org readme](readme.txt)

## Notes on Current Architecture

- The public admin UI no longer uses "Tracking v2" terminology.
- Internally, some runtime settings still live in the `clicutcl_tracking_v2` option for backward compatibility.
- The legacy v1 API controller remains in the repository but is disabled by default unless `CLICUTCL_ENABLE_LEGACY_V1_API` is explicitly enabled.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
