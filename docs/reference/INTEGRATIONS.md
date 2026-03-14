# ClickTrail Integrations Reference

- **Audience**: contributors, maintainers, reviewers, and solution engineers
- **Canonical for**: supported integrations, providers, CMP sources, webhook sources, and delivery adapters
- **Update when**: integration support level, adapter list, provider list, or capability messaging changes
- **Last verified against version**: `1.5.2`

This document lists the active integrations and external-facing connection points in the current codebase.

Use this file when a team needs to answer two questions:

1. "Is this platform or provider supported?"
2. "How does ClickTrail attach value to it in practice?"

For rollout guidance by site type, see [../guides/IMPLEMENTATION-PLAYBOOK.md](../guides/IMPLEMENTATION-PLAYBOOK.md).

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

Supported form adapters:

- Contact Form 7
- Elementor Forms (Pro)
- Fluent Forms
- Gravity Forms
- Ninja Forms
- WPForms

What ClickTrail does:

- auto-add hidden attribution fields for Contact Form 7 and Fluent Forms
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

## WooCommerce

Managed by:

- `includes/integrations/class-woocommerce.php`

What ClickTrail does:

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

Supported consent sources:

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

## External Form Source Webhooks

Supported providers:

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

## Server-Side Delivery Adapters

Dispatcher:

- `CLICUTCL\Server_Side\Dispatcher`

Supported adapter keys:

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

Important constraint:

- ClickTrail still uses one selected native adapter at a time. Destination toggles are capability markers and diagnostics inputs, not multi-send fan-out controls.

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

The user-facing admin now groups integrations under `Forms`, `Events`, and `Delivery`, but some of the advanced integration state still lives internally in `clicutcl_tracking_v2` for backward compatibility.
