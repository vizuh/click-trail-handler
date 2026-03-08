# ClickTrail Integrations Reference

- **Audience**: contributors, maintainers, reviewers, and solution engineers
- **Canonical for**: supported integrations, providers, CMP sources, webhook sources, and delivery adapters
- **Update when**: integration support level, adapter list, provider list, or capability messaging changes
- **Last verified against version**: `1.3.5`

This document lists the active integrations and external-facing connection points in the current codebase.

## WordPress Integrations

## Forms

Managed by:

- `includes/integrations/class-form-integration-manager.php`

Supported form adapters:

- Contact Form 7
- Fluent Forms
- Gravity Forms
- Ninja Forms
- WPForms

What ClickTrail does:

- inject or populate attribution fields
- keep attribution attached to submissions
- dispatch form-related events when applicable

## WooCommerce

Managed by:

- `includes/integrations/class-woocommerce.php`

What ClickTrail does:

- save attribution on checkout
- render attribution in WooCommerce admin
- push purchase event to `dataLayer`
- optionally dispatch purchase events into the server-side delivery pipeline

## Consent and CMP Sources

Supported consent sources:

- ClickTrail plugin banner
- Cookiebot
- OneTrust
- Complianz
- GTM
- custom bridge

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
- push browser and purchase events to `window.dataLayer`

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

## Server-Side Delivery Adapters

Dispatcher:

- `CLICUTCL\Server_Side\Dispatcher`

Supported adapter keys:

- `generic`
- `sgtm`
- `meta_capi`
- `google_ads`
- `linkedin_capi`

Current role of adapters:

- send canonical delivery events to the configured endpoint shape
- share queueing, retry, diagnostics, and consent gates

## Cross-Domain Attribution Helpers

Routes:

- `POST /wp-json/clicutcl/v2/attribution-token/sign`
- `POST /wp-json/clicutcl/v2/attribution-token/verify`

Purpose:

- continue attribution across approved domains or subdomains

## WhatsApp

Supported hosts:

- `wa.me`
- `whatsapp.com`
- `api.whatsapp.com`
- `web.whatsapp.com`

What ClickTrail does:

- preserve attribution continuity in WhatsApp links
- optionally append attribution context to pre-filled messages

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
