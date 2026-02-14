# Plugin Overview

## What ClickTrail Does

ClickTrail is a WordPress attribution and tracking plugin focused on:

- first-touch and last-touch attribution capture
- consent-aware client tracking
- lead-gen event capture in the browser
- server-side dispatch with retry queue
- hybrid transport (native + GTM-compatible dataLayer stream)

Version in this codebase: `1.3.2`.

Runtime baseline from plugin header:

- WordPress `6.5+`
- PHP `8.1+`

## Bootstrap and Lifecycle

Main entrypoint: `clicutcl.php`.

Bootstrap sequence:

1. Defines constants (`CLICUTCL_VERSION`, paths, nonce action, etc.).
2. Loads Composer autoloader if present.
3. Loads plugin autoloader (`includes/class-autoloader.php`).
4. Hard-fallback loads `CLICUTCL\Core\Context` if namespace path mapping fails.
5. Loads core class and canonical URL helpers.
6. Registers activation/deactivation hooks.
7. On `init`, instantiates `CLICUTCL_Core` and runs modules.

Activation (`register_activation_hook`):

- creates/updates DB tables via `CLICUTCL\Database\Installer::run()`
- schedules `clicutcl_daily_cleanup`
- ensures queue cron schedule (`CLICUTCL\Server_Side\Queue::ensure_schedule()`)

Deactivation:

- clears cleanup cron hook
- clears queue cron hook

## Major Runtime Components

## 1) Core and Module Wiring

`includes/class-clicutcl-core.php`:

- initializes admin module (`CLICUTCL\Admin\Admin`)
- initializes consent mode module
- initializes GTM module
- initializes events logger module
- registers v2 REST controller (`CLICUTCL\Api\Tracking_Controller`)
- initializes form integration manager
- initializes WooCommerce integration if WooCommerce is active
- enqueues frontend scripts and localized config

## 2) Frontend Attribution and Events

Frontend scripts:

- `assets/js/clicutcl-attribution.js`
- `assets/js/clicutcl-events.js`
- `assets/js/clicutcl-consent.js`

Current behavior:

- captures UTM and click IDs
- persists attribution to cookie/localStorage
- injects attribution fields into forms (cache-resilient)
- decorates outbound cross-domain links (allowlist-based)
- creates first-party session/visitor IDs
- pushes page/form/engagement events to `window.dataLayer`
- sends canonical batch events to `/wp-json/clicutcl/v2/events/batch` when transport is enabled

## 3) Tracking v2 Canonical Layer

Tracking v2 namespace: `includes/tracking/`.

Key pieces:

- canonical schema: `EventV2`
- v1 -> v2 translator: `Event_Translator_V1_To_V2`
- signed client token auth: `Auth`
- webhook HMAC auth: `Webhook_Auth`
- consent policy + identity resolver
- dedup store and stats
- v2 settings surface (`clicutcl_tracking_v2`)

## 4) Server-side Dispatch and Queue

Server-side namespace: `includes/server-side/`.

Key pieces:

- dispatcher (`Dispatcher`)
- adapter interface/result contracts
- adapters (`generic`, `sgtm`, `meta_capi`, `google_ads`, `linkedin_capi`)
- retry queue (`Queue`) with exponential backoff and cron processing
- consent gate for dispatch
- diagnostics ring buffer and always-on failure telemetry buckets

## 5) Integrations

Form adapters:

- Contact Form 7
- Gravity Forms
- Fluent Forms
- Ninja Forms
- WPForms

WooCommerce integration:

- order meta attribution persistence
- purchase event push to dataLayer
- server dispatch for purchase events
- admin order column/meta box enhancements

## 6) Admin UX and Diagnostics

Admin main class: `includes/admin/class-admin.php`.

Provides:

- settings pages and tabs
- tracking v2 Gutenberg-native admin screen (`@wordpress/components`)
- diagnostics page (endpoint health, debug toggle, failure telemetry, recent dispatches)
- logs page (`WP_List_Table` over `clicutcl_events`)
- dashboard widget
- multisite network settings for server-side transport

## 7) Canonical URL Cleanup

`includes/clicutcl-canonical.php` removes known tracking params from canonical URLs through:

- `wpseo_canonical`
- `get_canonical_url`

## Implementation Status (Current Code)

Implemented in code:

- canonical event v2 intake and translation
- signed token check for v2 batch endpoint
- trusted-proxy-aware IP handling
- dedup and retry queue
- webhook adapters (Calendly, HubSpot, Typeform)
- diagnostics endpoints and admin UI

Present but partially scaffolded:

- native destination adapters are transport scaffolds (generic HTTP relay shape)
- destination-specific mapping/auth logic for each ad platform is not fully specialized yet

Legacy surface in repository:

- `includes/api/class-log-controller.php` defines v1 WA logging routes and auth logic
- current core bootstrap registers only `Tracking_Controller` (v2)
- v1 controller exists in code but is not wired by default
- v1 class loading is explicitly disabled unless `CLICUTCL_ENABLE_LEGACY_V1_API` is set to `true`
