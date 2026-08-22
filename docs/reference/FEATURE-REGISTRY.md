# Feature Registry

- **Audience**: contributors, maintainers, reviewers, and release engineers
- **Canonical for**: internal capability registry, destination metadata, adapter metadata, and smoke-test IDs
- **Update when**: a capability, destination, adapter, docs target, or smoke ID is added, renamed, or removed
- **Source baseline**: plugin code `1.9.0`, commit `a45aa9e`
- **Runtime verification**: structural registry/smoke checks only; provider E2E was not completed

This document describes the internal feature registry used to keep ClickTrail's growing surface area aligned across admin UI, diagnostics, docs, and QA.

The registry is not a public API or a provider-support certificate. It is an internal source of truth for
capability metadata, admin wiring, adapter class selection, and smoke ownership. A registry entry proves
that ClickTrail knows about a key; it does not prove provider credentials, payload acceptance, consent
transitions, retry safety, or purge behavior.

For public status and provider boundaries, use [`INTEGRATIONS.md`](INTEGRATIONS.md) and the
[`integration-capabilities.json`](integration-capabilities.json) evidence ledger.

## Source Files

Primary machine-readable source:

- `config/feature-registry.json`

Primary runtime accessor:

- `includes/support/class-feature-registry.php`

Current consumers include:

- `includes/admin/class-admin.php`
- `includes/admin/traits/trait-admin-pages.php`
- `includes/admin/traits/trait-admin-diagnostics-ajax.php`
- `includes/server-side/class-dispatcher.php`
- `includes/tracking/class-settings.php`
- `tools/qa/smoke.js`

## Registry Sections

## `delivery_adapters`

Defines the selectable server-side adapter menu and dispatcher mapping. Public documentation must label
these entries `source-present / runtime-unverified` until provider contract tests pass.

Each entry declares:

- `label`
- `class`
- `support_level`
- `runtime_surface`
- `destination`
- `docs_target`
- `smoke_test_ids`

Current shipped keys:

- `generic`
- `sgtm`
- `meta_capi`
- `google_ads`
- `linkedin_capi`
- `pinterest_capi`
- `tiktok_events_api`

## `destinations`

Defines the destination toggles shown in `Settings > Events`. A destination toggle may be a relay marker
rather than a native provider delivery path; Reddit is currently `relay_only` with no adapter key.

Each entry declares:

- `label`
- `support_level`
- `runtime_surface`
- `enablement_path`
- `adapter_keys`
- `docs_target`
- `smoke_test_ids`

Current shipped keys:

- `meta`
- `google`
- `linkedin`
- `reddit`
- `pinterest`
- `tiktok`

## `features`

Defines higher-level capabilities that need a stable owner across docs and QA.

Each entry declares:

- `label`
- `support_level`
- `runtime_surface`
- `enablement_path`
- `docs_target`
- `smoke_test_ids`

Current examples:

- `capture_core`
- `forms_capture`
- `browser_pipeline`
- `woo_storefront_events`
- `woo_list_tracking`
- `woo_order_milestones`
- `diagnostics_conflict_scan`
- `diagnostics_woo_lookup`
- `settings_backup_restore`
- `queue_retry_semantics`

## What the Registry Drives

The registry currently drives:

- adapter and destination labels in the unified settings app
- adapter validation and instantiation allowlists
- setup-checklist and conflict-scan labels
- diagnostics adapter display labels
- docs ownership references via `docs_target`
- smoke coverage IDs consumed by `config/feature-test-matrix.json`

## Change Rules

When adding or changing a registry entry:

1. update `config/feature-registry.json`
2. update the relevant canonical docs referenced by `docs_target`
3. add or update the matching smoke-test entries in `config/feature-test-matrix.json`
4. run `npm run smoke`

Avoid using the registry as a generic plugin-module loader. ClickTrail still uses explicit runtime boundaries and should not drift toward a dynamic "load everything from metadata" architecture.
