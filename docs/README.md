# ClickTrail Technical Documentation

- **Audience**: contributors, reviewers, maintainers, and AI agents
- **Canonical for**: engineering navigation and source-of-truth lookup
- **Update when**: docs move, ownership changes, or a new subsystem requires a canonical reference
- **Last verified against version**: `1.3.5`

Use this file to find the correct technical document by task. Do not treat redirect stubs as canonical sources.

## Understand the Architecture

- [docs/architecture/PLUGIN-OVERVIEW.md](architecture/PLUGIN-OVERVIEW.md): plugin scope, bootstrap flow, major runtime components, active vs compatibility paths
- [docs/architecture/EVENT-PIPELINE.md](architecture/EVENT-PIPELINE.md): browser events, webhooks, lifecycle intake, dedup, and delivery flow
- [docs/architecture/DATA-MODEL.md](architecture/DATA-MODEL.md): options, tables, cookies, transients, cron hooks, and persistence surfaces
- [docs/architecture/CODE-MAP.md](architecture/CODE-MAP.md): active file layout and compatibility leftovers

## Change Admin UI or Settings

- [docs/guides/SETTINGS-AND-ADMIN.md](guides/SETTINGS-AND-ADMIN.md): current admin IA, option mapping, save model, screen surfaces
- [docs/guides/CODE-QUALITY.md](guides/CODE-QUALITY.md): maintenance hotspots and known cleanup risks

## Change Routes, Hooks, or Integrations

- [docs/reference/REST-API.md](reference/REST-API.md): active routes, auth model, and diagnostics endpoints
- [docs/reference/HOOKS-REFERENCE.md](reference/HOOKS-REFERENCE.md): public actions and filters
- [docs/reference/INTEGRATIONS.md](reference/INTEGRATIONS.md): supported form, commerce, consent, webhook, and delivery integrations

## Debug Delivery, Privacy, or Runtime Failures

- [docs/guides/OPERATIONS-RUNBOOK.md](guides/OPERATIONS-RUNBOOK.md): queue behavior, activation checks, endpoint tests, and failure patterns
- [docs/guides/SECURITY-PRIVACY.md](guides/SECURITY-PRIVACY.md): consent, token auth, replay protection, and secret handling
- [docs/architecture/DATA-MODEL.md](architecture/DATA-MODEL.md): cookies, queue tables, and stored state

## Prepare a Pull Request

- [../CONTRIBUTING.md](../CONTRIBUTING.md): contributor workflow and docs update matrix
- [../CONTRIBUTING.pt-BR.md](../CONTRIBUTING.pt-BR.md): contributor workflow in Brazilian Portuguese
- [../AGENTS.md](../AGENTS.md): repo-neutral agent guidance
- [../.github/PULL_REQUEST_TEMPLATE.md](../.github/PULL_REQUEST_TEMPLATE.md): PR checklist

## Source of Truth Rules

- Product positioning belongs in the readmes, not here.
- Admin truth belongs in `guides/SETTINGS-AND-ADMIN.md`.
- API truth belongs in `reference/REST-API.md`.
- Storage truth belongs in `architecture/DATA-MODEL.md`.
- Integration truth belongs in `reference/INTEGRATIONS.md`.
