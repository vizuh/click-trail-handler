# Plugin Overview

- **Audience**: contributors, maintainers, and reviewers
- **Canonical for**: runtime scope, bootstrap flow, subsystem ownership, and active vs compatibility paths
- **Update when**: boot flow, module boundaries, or major runtime responsibilities change
- **Last verified against version**: `1.3.5`

## Product Summary

ClickTrail is a WordPress attribution and tracking plugin focused on preserving conversion context and making it usable across forms, WooCommerce, browser events, and optional server-side delivery.

The plugin is designed to solve practical attribution failures:

- source loss across multi-page and multi-session journeys
- cached pages stripping hidden-field behavior
- AJAX-rendered or dynamic forms missing attribution values
- cross-domain journeys losing continuity
- consent-aware sites needing attribution and delivery to agree

Current codebase version: `1.3.5`.

Runtime requirements:

- WordPress `6.5+`
- PHP `8.1+`

## High-Level Capability Model

The current admin and documentation model uses four capability areas:

- **Capture**: attribution collection, retention, and cross-domain continuity
- **Forms**: form enrichment, WhatsApp continuity, and external form-source intake
- **Events**: browser event collection, GTM helpers, destinations, and lifecycle updates
- **Delivery**: server-side transport, privacy, queue health, and diagnostics

The runtime still stores part of the advanced settings in `clicutcl_tracking_v2`, but that is an internal compatibility detail, not a public product concept.

## Bootstrap and Lifecycle

Main entry point: `clicutcl.php`

Bootstrap sequence:

1. Define plugin constants and compatibility helpers.
2. Load Composer autoloader when present.
3. Load the plugin autoloader.
4. Instantiate `CLICUTCL\Plugin`.
5. Register activation and deactivation hooks.
6. Run admin and public hooks.

Activation:

- creates or updates `clicutcl_events` and `clicutcl_queue`
- writes DB readiness flags
- seeds `clicutcl_tracking_v2` defaults when missing
- schedules daily cleanup
- ensures the queue cron exists

Deactivation:

- clears cleanup cron
- clears queue cron

## Major Runtime Components

## 1. Core bootstrap

`includes/class-clicutcl-core.php`

Responsibilities:

- boot admin surfaces
- register public scripts
- register REST routes
- initialize consent, GTM, cleanup, queue, form integrations, and WooCommerce integration

## 2. Frontend attribution

`assets/js/clicutcl-attribution.js`

Responsibilities:

- capture UTMs, referrers, and click IDs
- persist attribution in first-party client storage
- populate supported form fields
- decorate approved cross-domain links
- handle WhatsApp attribution append behavior
- mint and use attribution-token helper endpoints when enabled

## 3. Browser events

`assets/js/clicutcl-events.js`

Responsibilities:

- push events to `window.dataLayer`
- collect search, file download, scroll, engagement, and lead-gen signals
- build canonical payloads for REST intake
- send batch events only when event transport is available

## 4. Consent and GTM helpers

`includes/Modules/consent-mode/`
`includes/Modules/GTM/`
`assets/js/clicutcl-consent-bridge.js`
`assets/js/clicutcl-consent.js`

Responsibilities:

- determine whether consent is required
- bridge consent state into runtime behavior
- optionally render the plugin consent banner
- optionally inject a GTM container snippet

## 5. Canonical tracking pipeline

`includes/tracking/`

Responsibilities:

- client token minting and verification
- canonical event schema
- consent-aware identity resolution
- webhook auth
- dedup storage
- masked admin-safe settings access

## 6. REST intake

`includes/api/class-tracking-controller.php`

Active routes:

- `POST /clicutcl/v2/events/batch`
- `POST /clicutcl/v2/attribution-token/sign`
- `POST /clicutcl/v2/attribution-token/verify`
- `POST /clicutcl/v2/webhooks/{provider}`
- `POST /clicutcl/v2/lifecycle/update`
- `GET /clicutcl/v2/diagnostics/delivery`
- `GET /clicutcl/v2/diagnostics/dedup`

## 7. Server-side delivery

`includes/server-side/`

Responsibilities:

- adapter selection
- delivery dispatch
- queue retries with backoff
- endpoint health checks
- failure telemetry
- delivery diagnostics ring buffers

## 8. WordPress integrations

`includes/integrations/`

Responsibilities:

- supported form adapters
- WooCommerce order attribution
- WooCommerce purchase event pushes
- optional purchase dispatch into the delivery pipeline

## Admin Surfaces

Main admin class: `includes/admin/class-admin.php`

Primary screens:

- `ClickTrail > Settings`
- `ClickTrail > Logs`
- `ClickTrail > Diagnostics`

Multisite only:

- `ClickTrail Network`

The current settings screen is a unified JavaScript app rendered inside `wp-admin` and loaded from `assets/js/admin-settings-app.js`.

## Active vs Legacy

Active:

- unified settings app
- v2 REST controller
- current server-side queue and diagnostics flow

Legacy or compatibility-only:

- `includes/api/class-log-controller.php`
- internal `clicutcl_tracking_v2` option name
- some older admin assets still present in the repository but not used by the active settings screen
