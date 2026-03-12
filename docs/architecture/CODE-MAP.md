# Code Map

- **Audience**: contributors, maintainers, and AI agents
- **Canonical for**: active file layout and compatibility leftovers in the repository
- **Update when**: files move, new subsystems are added, or active/legacy status changes
- **Last verified against version**: `1.3.9`

This map is organized around the active code paths first, then compatibility and maintenance notes.

## Root Files

- `clicutcl.php`: plugin header, constants, bootstrap, activation, deactivation
- `README.md`: GitHub landing page
- `README.en.md`: English product README
- `README.pt-BR.md`: Brazilian Portuguese product README
- `CONTRIBUTING.md`: contributor workflow and docs update matrix
- `CONTRIBUTING.pt-BR.md`: contributor workflow in Brazilian Portuguese
- `AGENTS.md`: neutral AI-agent guidance for the repository
- `readme.txt`: WordPress.org readme
- `INTEGRATIONS.md`: temporary redirect stub to `docs/reference/INTEGRATIONS.md`
- `changelog.txt`: repository release notes
- `uninstall.php`: uninstall cleanup

## Active Runtime Directories

- `.github/`: PR template and GitHub-facing contribution scaffolding

## `includes/`

### Core bootstrap

- `includes/class-clicutcl-core.php`
- `includes/class-autoloader.php`
- `includes/clicutcl-canonical.php`

### Admin

- `includes/admin/class-admin.php`
- `includes/admin/class-log-list-table.php`
- `includes/admin/class-site-health.php`
- `includes/admin/class-clicutcl-woocommerce-admin.php`
- `includes/admin/traits/trait-admin-pages.php`
- `includes/admin/traits/trait-admin-diagnostics-ajax.php`
- `includes/admin/traits/trait-admin-consent-mode.php`

### API

- `includes/api/class-tracking-controller.php`
- `includes/api/traits/trait-tracking-controller-attribution-token.php`
- `includes/api/traits/trait-tracking-controller-security.php`
- `includes/api/traits/trait-tracking-controller-debug.php`

### Tracking internals

- `includes/tracking/class-settings.php`
- `includes/tracking/class-auth.php`
- `includes/tracking/class-eventv2.php`
- `includes/tracking/class-event-translator-v1-to-v2.php`
- `includes/tracking/class-consent-decision.php`
- `includes/tracking/class-identity-resolver.php`
- `includes/tracking/class-dedup-store.php`
- `includes/tracking/class-webhook-auth.php`
- `includes/tracking/webhooks/`

### Server-side delivery

- `includes/server-side/class-dispatcher.php`
- `includes/server-side/class-queue.php`
- `includes/server-side/class-event.php`
- `includes/server-side/class-settings.php`
- adapter classes under `includes/server-side/`

### Integrations

- `includes/integrations/class-form-integration-manager.php`
- `includes/integrations/class-woocommerce.php`
- `includes/integrations/forms/`

### Modules

- `includes/Modules/consent-mode/`
- `includes/Modules/GTM/`
- `includes/Modules/Events/`

### Utilities and DB

- `includes/database/class-installer.php`
- `includes/settings/class-attribution-settings.php`
- `includes/utils/class-attribution.php`
- `includes/utils/class-cleanup.php`
- `includes/privacy/class-privacy-handler.php`

## `assets/`

### Active frontend assets

- `assets/js/clicutcl-attribution.js`
- `assets/js/clicutcl-events.js`
- `assets/js/clicutcl-consent-bridge.js`
- `assets/js/clicutcl-consent.js`
- `assets/css/clicutcl-consent.css`

### Active admin assets

- `assets/js/admin-settings-app.js`
- `assets/js/admin-diagnostics.js`
- `assets/js/admin-sitehealth.js`
- `assets/css/admin.css`

### Branding

- `assets/vizuh-logo.png`

## Active Data Surfaces

- option keys:
  - `clicutcl_attribution_settings`
  - `clicutcl_consent_mode`
  - `clicutcl_gtm`
  - `clicutcl_server_side`
  - `clicutcl_server_side_network`
  - `clicutcl_tracking_v2`
- DB tables:
  - `wp_clicutcl_events`
  - `wp_clicutcl_queue`

## Compatibility and Legacy Surfaces

- `includes/api/class-log-controller.php`: legacy controller present in repo, not bootstrapped by default
- `assets/js/admin-tracking-v2.js`: legacy admin asset still present in repo
- `assets/js/admin-settings.js`: earlier admin helper asset still present in repo
- `assets/css/ct-consent.css`: duplicate consent stylesheet currently present in repo

These files are useful context during maintenance, but they are not the primary active user-facing surfaces.
