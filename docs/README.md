# ClickTrail Technical Documentation

- **Audience**: implementers, contributors, maintainers, support teams, reviewers, and AI agents
- **Canonical for**: engineering navigation, adoption guidance, and source-of-truth lookup
- **Update when**: docs move, ownership changes, or a new subsystem or rollout pattern needs a canonical reference
- **Last verified against version**: `1.3.9`

This is the docs home for GitHub readers. Use it to find the right document by role, task, or rollout goal. Redirect stubs in the root of `docs/` are compatibility links only; the canonical docs live under `docs/architecture`, `docs/guides`, and `docs/reference`.

## Start Here

If you are new to the plugin, read these in order:

1. [architecture/PLUGIN-OVERVIEW.md](architecture/PLUGIN-OVERVIEW.md): what ClickTrail does, how the runtime is divided, and where value shows up
2. [guides/IMPLEMENTATION-PLAYBOOK.md](guides/IMPLEMENTATION-PLAYBOOK.md): how teams usually roll out Capture, Forms, Events, and Delivery in practice
3. [guides/SETTINGS-AND-ADMIN.md](guides/SETTINGS-AND-ADMIN.md): how the current admin UI maps to stored settings and operational surfaces

## Choose Docs by Goal

## I want to deploy the plugin on a real site

- [guides/IMPLEMENTATION-PLAYBOOK.md](guides/IMPLEMENTATION-PLAYBOOK.md): phased rollout patterns for lead-gen, WooCommerce, cross-domain, consent-aware, and server-side setups
- [guides/SETTINGS-AND-ADMIN.md](guides/SETTINGS-AND-ADMIN.md): current settings IA and option mapping
- [reference/INTEGRATIONS.md](reference/INTEGRATIONS.md): supported forms, commerce, consent, webhook, and delivery integrations

## I want to understand the runtime architecture

- [architecture/PLUGIN-OVERVIEW.md](architecture/PLUGIN-OVERVIEW.md): plugin scope, bootstrap flow, capability model, and active vs compatibility paths
- [architecture/EVENT-PIPELINE.md](architecture/EVENT-PIPELINE.md): browser, form, webhook, lifecycle, WooCommerce, and delivery flow
- [architecture/DATA-MODEL.md](architecture/DATA-MODEL.md): options, tables, cookies, transients, cron hooks, and persistence surfaces
- [architecture/CODE-MAP.md](architecture/CODE-MAP.md): active file layout and compatibility leftovers

## I want to change admin UI, settings, or UX behavior

- [guides/SETTINGS-AND-ADMIN.md](guides/SETTINGS-AND-ADMIN.md): admin IA, tab responsibilities, option stores, and compatibility URLs
- [guides/CODE-QUALITY.md](guides/CODE-QUALITY.md): maintenance hotspots and known cleanup risks

## I want to extend routes, hooks, or integrations

- [reference/REST-API.md](reference/REST-API.md): active routes, auth model, and diagnostics endpoints
- [reference/HOOKS-REFERENCE.md](reference/HOOKS-REFERENCE.md): public actions and filters
- [reference/INTEGRATIONS.md](reference/INTEGRATIONS.md): current support model and integration-specific implementation notes

## I need to operate, debug, or support a live install

- [guides/OPERATIONS-RUNBOOK.md](guides/OPERATIONS-RUNBOOK.md): queue behavior, endpoint tests, diagnostics, and common failure patterns
- [guides/SECURITY-PRIVACY.md](guides/SECURITY-PRIVACY.md): consent, token auth, replay protection, privacy boundaries, and secret handling
- [architecture/DATA-MODEL.md](architecture/DATA-MODEL.md): storage surfaces and persisted state

## I want to reuse the model in another project

- [guides/TRACKING-ATTRIBUTION-PORTABLE-PROMPT.md](guides/TRACKING-ATTRIBUTION-PORTABLE-PROMPT.md): copy-paste prompt for porting the tracking, attribution, privacy, and settings model into another codebase

## Choose Docs by Role

- implementation engineer: start with [guides/IMPLEMENTATION-PLAYBOOK.md](guides/IMPLEMENTATION-PLAYBOOK.md)
- solution architect or technical PM: start with [architecture/PLUGIN-OVERVIEW.md](architecture/PLUGIN-OVERVIEW.md)
- support or operations: start with [guides/OPERATIONS-RUNBOOK.md](guides/OPERATIONS-RUNBOOK.md)
- contributor or reviewer: start with [architecture/CODE-MAP.md](architecture/CODE-MAP.md) and [../CONTRIBUTING.md](../CONTRIBUTING.md)

## Prepare a Pull Request

- [../CONTRIBUTING.md](../CONTRIBUTING.md): contributor workflow and docs update matrix
- [../CONTRIBUTING.pt-BR.md](../CONTRIBUTING.pt-BR.md): contributor workflow in Brazilian Portuguese
- [../AGENTS.md](../AGENTS.md): repo-neutral agent guidance
- [../.github/PULL_REQUEST_TEMPLATE.md](../.github/PULL_REQUEST_TEMPLATE.md): PR checklist

## Source of Truth Rules

- Product positioning belongs in the repo and WordPress readmes, not here.
- Rollout guidance belongs in `guides/IMPLEMENTATION-PLAYBOOK.md`.
- Admin truth belongs in `guides/SETTINGS-AND-ADMIN.md`.
- API truth belongs in `reference/REST-API.md`.
- Storage truth belongs in `architecture/DATA-MODEL.md`.
- Integration truth belongs in `reference/INTEGRATIONS.md`.
