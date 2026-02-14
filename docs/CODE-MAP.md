# Code Map (File by File)

This is a source map of plugin files and their responsibilities.

## Root

- `clicutcl.php` - plugin header, constants, bootstrap, activation/deactivation, core init
- `uninstall.php` - uninstall cleanup for options/cron/queue table
- `README.md` - public project readme
- `readme.txt` - WordPress.org plugin readme
- `changelog.txt` - release changelog
- `LICENSE` - GPL license text
- `readme_header_update.txt` - auxiliary release/edit artifact

## Assets

## JavaScript

- `assets/js/clicutcl-attribution.js`
  - attribution capture, cookie/localStorage persistence, hidden field injection
  - cross-domain link decoration and compact token encode/decode
  - basic bot filtering and WhatsApp attribution append
- `assets/js/clicutcl-events.js`
  - behavioral + lead-gen event tracking
  - dataLayer push
  - canonical v2 event transport to `/v2/events/batch`
  - thank-you matcher and iframe message ingestion
- `assets/js/clicutcl-consent.js`
  - consent banner UI and `ct_consent` cookie management
  - consent updates to dataLayer/gtag
- `assets/js/ct-consent.js`
  - alternate consent script variant (legacy/simple, not enqueued by default)
- `assets/js/admin-sitehealth.js`
  - admin heartbeat ping for Site Health test
- `assets/js/admin-diagnostics.js`
  - diagnostics page AJAX actions (endpoint test, debug toggle, payload copy)
- `assets/js/admin-tracking-v2.js`
  - Gutenberg-native tracking v2 settings UI (`wp-element`, `wp-components`, `wp-i18n`)

## CSS

- `assets/css/admin.css` - admin settings/diagnostics styles
- `assets/css/clicutcl-consent.css` - consent banner styles
- `assets/css/ct-consent.css` - alternate consent styles

## Media

- `assets/vizuh-logo.png` - project logo

## Includes

## Bootstrap and Core

- `includes/class-autoloader.php` - namespace autoloader with path-case fallbacks
- `includes/class-clicutcl-core.php` - runtime composition: modules, hooks, script enqueue, v2 route registration
- `includes/clicutcl-canonical.php` - canonical URL tracking-parameter stripping filters

## Core Namespace

- `includes/Core/class-context.php` - plugin context helper (path/url/basename)
- `includes/Core/Storage/class-setting.php` - base settings storage abstraction
- `includes/Core/class-attribution-provider.php` - consent-aware attribution payload provider

## Admin

- `includes/admin/class-admin.php` - menu, tabs, settings registration, sanitizers, ajax handlers, diagnostics/log screens
- `includes/admin/class-site-health.php` - Site Health tests and heartbeat endpoint
- `includes/admin/class-log-list-table.php` - WP_List_Table implementation for event logs
- `includes/admin/class-clicutcl-woocommerce-admin.php` - WooCommerce admin column + attribution meta box

## API

- `includes/api/class-tracking-controller.php`
  - active REST v2 controller
  - routes: events batch, provider webhooks, lifecycle, diagnostics
  - token auth, rate limits, proxy-aware IP extraction
- `includes/api/class-log-controller.php`
  - legacy REST v1 controller class with WA logging/token logic
  - class exists but is not registered by current core bootstrap

## Database

- `includes/database/class-installer.php` - table creation, readiness flags, initial option seeding

## Tracking v2 Domain

- `includes/tracking/class-canonicaleventinterfacev2.php` - canonical event interface contract
- `includes/tracking/class-eventv2.php` - canonical event v2 normalize/validate implementation
- `includes/tracking/class-event-translator-v1-to-v2.php` - translator from legacy payloads to v2 schema
- `includes/tracking/class-auth.php` - signed client token mint/verify
- `includes/tracking/class-dedup-store.php` - transient-based dedup and dedup stats
- `includes/tracking/class-settings.php` - tracking v2 settings defaults/sanitize/helpers
- `includes/tracking/class-consentdecisioninterface.php` - consent decision contract
- `includes/tracking/class-consent-decision.php` - consent decision implementation
- `includes/tracking/class-identityresolverinterface.php` - identity resolver contract
- `includes/tracking/class-identity-resolver.php` - consent-gated identity hashing policy
- `includes/tracking/class-destinationadapterinterfacev2.php` - destination adapter contract (v2 mapping/send)
- `includes/tracking/class-webhookprovideradapterinterface.php` - provider webhook adapter contract
- `includes/tracking/class-webhook-auth.php` - webhook HMAC and replay verification

## Tracking v2 Webhook Adapters

- `includes/tracking/webhooks/class-calendlywebhookadapter.php` - Calendly webhook -> canonical event mapping
- `includes/tracking/webhooks/class-hubspotwebhookadapter.php` - HubSpot webhook -> canonical event mapping
- `includes/tracking/webhooks/class-typeformwebhookadapter.php` - Typeform webhook -> canonical event mapping

## Server-side Transport

- `includes/server-side/class-settings.php` - effective server-side settings resolution (site/network)
- `includes/server-side/class-consent.php` - server-side consent cookie helper
- `includes/server-side/class-event.php` - legacy canonical event object for dispatcher adapters
- `includes/server-side/class-adapter-interface.php` - adapter contract
- `includes/server-side/class-adapter-result.php` - adapter result model
- `includes/server-side/class-dispatcher.php`
  - dispatch orchestration, adapter selection, dedup checks, queue handoff
  - diagnostics logging and failure telemetry
- `includes/server-side/class-queue.php`
  - retry queue enqueue/process logic
  - schedule, lock, backoff, table readiness handling
- `includes/server-side/class-generic-collector-adapter.php` - generic JSON POST adapter
- `includes/server-side/class-sgtm-adapter.php` - sGTM relay adapter
- `includes/server-side/class-meta-capi-adapter.php` - Meta adapter scaffold relay
- `includes/server-side/class-google-ads-adapter.php` - Google adapter scaffold relay
- `includes/server-side/class-linkedin-capi-adapter.php` - LinkedIn adapter scaffold relay

## Integrations

- `includes/integrations/class-form-integration-manager.php` - adapter discovery and activation
- `includes/integrations/class-form-integrations.php` - deprecated legacy integration class
- `includes/integrations/class-woocommerce.php` - WooCommerce checkout/order attribution and purchase events

## Form Adapter Framework

- `includes/integrations/forms/interface-form-adapter.php` - form adapter interface
- `includes/integrations/forms/abstract-form-adapter.php` - shared form adapter behavior
- `includes/integrations/forms/class-cf7-adapter.php` - Contact Form 7 adapter
- `includes/integrations/forms/class-gravity-forms-adapter.php` - Gravity Forms adapter
- `includes/integrations/forms/class-fluent-forms-adapter.php` - Fluent Forms adapter
- `includes/integrations/forms/class-ninja-forms-adapter.php` - Ninja Forms adapter
- `includes/integrations/forms/class-wpforms-adapter.php` - WPForms adapter

## Modules

### Consent Mode Module

- `includes/Modules/consent-mode/class-consent-mode.php` - consent mode registration and gtag consent snippet
- `includes/Modules/consent-mode/class-consent-mode-settings.php` - consent mode option model and sanitize rules
- `includes/Modules/consent-mode/class-regions.php` - default region list helper

### GTM Module

- `includes/Modules/GTM/class-web-tag.php` - GTM script + noscript rendering
- `includes/Modules/GTM/class-gtm-settings.php` - GTM setting validation and storage

### Events Module

- `includes/Modules/Events/class-events-logger.php`
  - server-originated event cookie bridge
  - pushes login/signup/comment events to dataLayer on next page load

## Settings Helpers

- `includes/settings/class-attribution-settings.php` - attribution option getters and compatibility helpers

## Utility Classes

- `includes/utils/class-attribution.php` - attribution cookie read/sanitize helper
- `includes/utils/class-cleanup.php` - scheduled cleanup of old events/queue rows

## Languages

- `languages/README.md` - translation folder notes
- `languages/click-trail-handler-pt_BR.po` - Portuguese (Brazil) translation source
