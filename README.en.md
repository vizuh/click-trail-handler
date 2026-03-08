# ClickTrail

ClickTrail is a WordPress attribution and tracking plugin for sites that need reliable marketing source data inside forms, WooCommerce orders, and event pipelines.

It is built for the problems that usually break attribution in real projects:

- cached pages
- dynamic or AJAX-loaded forms
- multi-page and multi-session journeys
- cross-domain flows
- consent-aware tracking requirements
- optional server-side delivery

## What ClickTrail Does

ClickTrail captures first-touch and last-touch attribution, keeps it available across the visit lifecycle, and makes that data usable where conversions actually happen.

It combines:

- attribution capture
- form enrichment
- WooCommerce order attribution
- browser event collection
- consent-aware tracking controls
- optional server-side transport with retries and diagnostics

## Problems It Solves

### 1. Lost campaign attribution in WordPress

Users land with UTMs or ad click IDs, browse a few pages, and convert later. Without persistence, the conversion record loses the original source.

ClickTrail keeps the source trail available through forms, checkout, and event payloads.

### 2. Cached and dynamic pages breaking hidden fields

Many attribution plugins rely on server-rendered hidden fields only. That breaks when pages are cached or forms are injected after page load.

ClickTrail includes a client-side capture fallback and dynamic-content watching so attribution still reaches supported forms and matching hidden fields.

### 3. WooCommerce orders with weak or missing source data

Paid traffic often ends up looking like direct traffic in order records.

ClickTrail stores attribution on the order and pushes purchase data to the dataLayer, with optional server-side dispatch.

### 4. Cross-domain journeys losing continuity

If users move between marketing site, app, scheduler, or checkout domain, attribution often resets.

ClickTrail supports approved cross-domain link decoration and token-based attribution continuity.

### 5. Consent and delivery living in separate tools

Teams often need privacy controls, event delivery, and attribution logic to agree with each other.

ClickTrail keeps consent, event intake, and delivery configuration in the same plugin.

## Core Capabilities

### Capture

- First-touch and last-touch UTMs, including `utm_id`, `utm_source_platform`, `utm_creative_format`, and `utm_marketing_tactic`
- Referrer capture
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
- Search, file download, scroll depth, time-on-page, and lead-gen interaction events
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
- WooCommerce purchase event push to `dataLayer`
- Optional server-side purchase dispatch

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

1. Upload the plugin to `/wp-content/plugins/click-trail-handler/` or install it through WordPress.
2. Activate the plugin.
3. Open `ClickTrail > Settings`.
4. Configure the areas you use:
   - `Capture` for attribution basics
   - `Forms` for form enrichment
   - `Events` for browser events and destinations
   - `Delivery` for server-side transport and privacy
5. Validate the setup from `ClickTrail > Diagnostics`.

## Typical Use Cases

- Agencies that need attribution inside lead forms
- WooCommerce stores that want campaign-aware order data
- Sites with aggressive caching or dynamic form rendering
- Businesses running multi-domain funnels
- Teams that need browser and server-side tracking in one WordPress plugin

## Repository Docs

- [Technical documentation index](docs/README.md)
- [Contributor guide](CONTRIBUTING.md)
- [Integrations reference](docs/reference/INTEGRATIONS.md)
- [WordPress.org readme](readme.txt)

## Notes on Current Architecture

- The public admin UI no longer uses "Tracking v2" terminology.
- Internally, some runtime settings still live in the `clicutcl_tracking_v2` option for backward compatibility.
- The legacy v1 API controller remains in the repository but is disabled by default unless `CLICUTCL_ENABLE_LEGACY_V1_API` is explicitly enabled.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
