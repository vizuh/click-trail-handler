# Settings and Admin Reference

- **Audience**: contributors, maintainers, designers, and reviewers
- **Canonical for**: admin IA, screen structure, option mapping, and settings save behavior
- **Update when**: tabs, settings grouping, screen slugs, or admin save contracts change
- **Last verified against version**: `1.5.2`

This document describes the active admin experience and how it maps back to the stored option keys.

## Menu Structure

Defined in `includes/admin/class-admin.php`.

Top-level menu:

- `ClickTrail` -> `page=clicutcl-settings`

Submenus:

- `Settings`
- `Logs`
- `Diagnostics`

Multisite only:

- `ClickTrail Network`

## Current Settings IA

The main settings surface is a unified admin app mounted into `#clicutcl-admin-settings-root`.

Above the tabbed cards, the app now renders a read-only setup checklist for:

- Capture
- Consent Guidance
- Forms
- Events
- Delivery
- sGTM
- Woo

Checklist rows with a mapped target are clickable. They switch to the relevant tab and scroll to the matching section card:

- `Capture` -> `Capture` tab -> `Core tracking`
- `Consent Guidance` -> `Delivery` tab -> `Privacy & consent`
- `Forms` -> `Forms` tab -> `On-site form capture`
- `Events` -> `Events` tab -> `Event collection`
- `Delivery` -> `Delivery` tab -> `Server-side transport`
- `sGTM` -> `Events` tab -> `sGTM setup wizard`
- `Woo` -> `Events` tab -> `WooCommerce`

Tab order:

1. `Capture`
2. `Forms`
3. `Events`
4. `Delivery`

Operational screens stay separate:

- `Logs`
- `Diagnostics`

The settings screen requires JavaScript in `wp-admin`.

## Tab Responsibilities

## Capture

Purpose:

- enable attribution
- configure retention
- manage cross-domain continuity

Primary user-facing controls:

- attribution enablement
- attribution retention
- link decoration
- allowed domains
- skip signed URLs
- pass cross-domain token

## Forms

Purpose:

- keep attribution attached to forms and lead-entry surfaces

Primary controls:

- client-side capture fallback
- dynamic-content watching
- overwrite behavior
- WhatsApp tracking
- external form source webhooks
- advanced observer selector

## Events

Purpose:

- configure browser event collection, WooCommerce event behavior, and the unified event pipeline

Primary controls:

- browser event collection
- GTM container ID
- GTM loader mode
- tagging server URL
- first-party script delivery
- custom loader path
- sGTM setup wizard with preview checks and destination template hints
- WooCommerce storefront events
- richer Woo `dataLayer` contract and optional consent-aware `user_data`
- registry-driven destination toggles
- destination enablement
- lifecycle update intake

WooCommerce guidance now lives inside the `Events` tab rather than in a separate Woo settings screen. That card explains:

- where WooCommerce order attribution is stored
- how purchase pushes work on the thank-you page
- what the optional storefront events setting does, including `view_item`, `view_item_list`, `view_cart`, `add_to_cart`, `remove_from_cart`, and `begin_checkout`
- what the richer Woo `dataLayer` contract adds for GTM-first setups
- where to verify Woo attribution and event output

## Delivery

Purpose:

- manage transport, privacy, and operational safeguards

Primary controls:

- server-side transport
- registry-driven adapter selection
- timeout and failure telemetry
- consent mode
- advanced delivery and security settings
- delivery health summary with links to Logs and Diagnostics

## Legacy URL Compatibility

The active tab resolver preserves older URLs and maps them forward:

- `general`, `attribution` -> `capture`
- `whatsapp`, `channels` -> `forms`
- `gtm`, `integrations`, `tracking`, `trackingv2`, `advanced` -> `events`
- `server`, `server-side`, `consent`, `privacy`, `destinations` -> `delivery`

When `tab=trackingv2` is used, the app routes to `Events` and shows a migration notice.

## Admin Assets

Primary settings assets:

- `assets/js/admin-settings-app.js`
- `assets/css/admin.css`

Other admin assets:

- `assets/js/admin-diagnostics.js`
- `assets/js/admin-sitehealth.js`

## Option Stores

The current UI is capability-based, but persistence still fans out into the existing option keys.

## `clicutcl_attribution_settings`

Used by:

- Capture
- parts of Forms

Relevant keys:

- `enable_attribution`
- `cookie_days`
- `enable_js_injection`
- `inject_mutation_observer`
- `inject_overwrite`
- `inject_observer_target`
- `enable_link_decoration`
- `link_allowed_domains`
- `link_skip_signed`
- `enable_cross_domain_token`
- `enable_whatsapp`
- `whatsapp_append_attribution`

Sanitizer:

- `Admin::sanitize_settings()`

## `clicutcl_consent_mode`

Used by:

- Delivery -> Privacy & consent

Managed by:

- `CLICUTCL\Modules\Consent_Mode\Consent_Mode_Settings`

Key fields:

- `enabled`
- `mode`
- `regions`
- `cmp_source`
- `cmp_timeout_ms`
- `cookie_name`
- `gcm_analytics_key`

## `clicutcl_gtm`

Used by:

- Events -> GTM and Woo `dataLayer`

Key fields:

- `container_id`
- `mode`
- `tagging_server_url`
- `first_party_script`
- `custom_loader_enabled`
- `custom_loader_url`
- `woo_enhanced_datalayer`
- `woo_include_user_data`

## `clicutcl_server_side`

Used by:

- Delivery -> Server-side transport

Key fields:

- `enabled`
- `endpoint_url`
- `adapter`
- `timeout`
- `use_network`
- `remote_failure_telemetry`

Multisite network key:

- `clicutcl_server_side_network`

## `clicutcl_tracking_v2`

Used internally by:

- advanced event and delivery settings
- WooCommerce storefront events flag
- destination enablement
- external provider secrets
- lifecycle token
- dedup, security, and diagnostics tuning

This option remains active for backward compatibility even though "Tracking v2" is no longer user-facing.

Relevant feature flags now include:

- `event_v2`
- `woocommerce_storefront_events`
- `external_webhooks`
- `connector_native`
- `diagnostics_v2`
- `lifecycle_ingestion`

Relevant destination keys now include:

- `meta`
- `google`
- `linkedin`
- `reddit`
- `pinterest`
- `tiktok`

## Unified Settings AJAX

The active settings screen uses these admin AJAX actions:

- `wp_ajax_clicutcl_get_admin_settings`
- `wp_ajax_clicutcl_save_admin_settings`
- `wp_ajax_clicutcl_sgtm_preview_check`

Implemented in:

- `includes/admin/traits/trait-admin-diagnostics-ajax.php`

The grouped payload is translated back into:

- `clicutcl_attribution_settings`
- `clicutcl_consent_mode`
- `clicutcl_gtm`
- `clicutcl_server_side`
- `clicutcl_tracking_v2`

The admin payload also includes:

- registry-backed adapter and destination metadata
- setup checklist rows
- delivery operations summary data

The sGTM setup wizard uses:

- `wp_ajax_clicutcl_sgtm_preview_check`

That action evaluates the current unsaved Events payload, probes the configured loader and collector URLs when possible, and returns destination template hints for GTM-first rollout checks.

## Logs Screen

Screen:

- `page=clicutcl-logs`

Backed by:

- `includes/admin/class-log-list-table.php`
- `wp_clicutcl_events`

Purpose:

- inspect stored event records

## Diagnostics Screen

Screen:

- `page=clicutcl-diagnostics`

Primary functions:

- endpoint test
- conflict scan
- settings backup export
- settings backup restore
- Woo order trace lookup
- debug-window toggle
- queue backlog visibility
- failure telemetry summary
- recent dispatches
- local tracking data purge

Key AJAX actions:

- `clicutcl_test_endpoint`
- `clicutcl_conflict_scan`
- `clicutcl_export_settings_backup`
- `clicutcl_import_settings_backup`
- `clicutcl_lookup_woo_order_trace`
- `clicutcl_toggle_debug`
- `clicutcl_purge_tracking_data`

The Woo order trace lookup is diagnostics-only. It reads stored purchase and milestone snapshots from Woo order meta and augments them with live queue state.

## Dashboard and Site Health

Additional admin surfaces:

- dashboard widget: quick status summary
- Site Health tests:
  - cache/conflict detection
  - admin heartbeat visibility
  - attribution cookie visibility

## Network Settings

Multisite only.

Purpose:

- manage network-wide server-side transport defaults

The network screen still uses a classic WordPress settings form rather than the unified settings app.
