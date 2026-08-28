# AGENTS.md

Repo-neutral guidance for AI coding agents working in ClickTrail.

## Purpose

Use this file as the first stop for planning, review, and implementation work in this repository.

It defines:

- the documentation map
- the source of truth for each subject
- when docs must be updated
- safe defaults for review and planning

`CLAUDE.md` is only a tool-specific adapter. Repo truth lives here.

## Repository Map

- `README.md`: short GitHub landing page
- `README.en.md`: canonical product readme
- `README.pt-BR.md`: Portuguese product readme
- `readme.txt`: WordPress.org readme
- `docs/README.md`: engineering index by task
- `docs/architecture/`: runtime, storage, event flow, file map
- `docs/reference/`: routes, hooks, integrations
- `docs/guides/`: admin IA, security, operations, quality
- `.github/PULL_REQUEST_TEMPLATE.md`: PR checklist

## Documentation Ownership

- Product positioning and supported-value messaging:
  - `README.en.md`
  - `README.pt-BR.md`
  - `readme.txt`
- Admin IA, settings layout, and option mapping:
  - `docs/guides/SETTINGS-AND-ADMIN.md`
- Runtime architecture and boot flow:
  - `docs/architecture/PLUGIN-OVERVIEW.md`
  - `docs/architecture/CODE-MAP.md`
- API, routes, and auth surface:
  - `docs/reference/REST-API.md`
  - `docs/reference/HOOKS-REFERENCE.md`
- Storage, cookies, queue, and persistence:
  - `docs/architecture/DATA-MODEL.md`
- Integrations, providers, adapters, and supported surfaces:
  - `docs/reference/INTEGRATIONS.md`
- Operations, diagnostics, and failure handling:
  - `docs/guides/OPERATIONS-RUNBOOK.md`
- Current maintenance posture and cleanup hotspots:
  - `docs/guides/CODE-QUALITY.md`

## Docs Update Triggers

- Admin or settings changes:
  - update `docs/guides/SETTINGS-AND-ADMIN.md`
  - update relevant readme copy if user-facing
- API, webhook, or auth changes:
  - update `docs/reference/REST-API.md`
  - update `docs/reference/HOOKS-REFERENCE.md`
- Storage, cookie, queue, or retention changes:
  - update `docs/architecture/DATA-MODEL.md`
  - update `docs/guides/SECURITY-PRIVACY.md`
  - update `docs/guides/OPERATIONS-RUNBOOK.md`
- Integration behavior changes:
  - update `docs/reference/INTEGRATIONS.md`
  - update product readmes when claims or support levels change
- Architecture, bootstrap, or file-layout changes:
  - update `docs/architecture/PLUGIN-OVERVIEW.md`
  - update `docs/architecture/CODE-MAP.md`
- Quality, cleanup, or dead-code changes that affect repo posture:
  - update `docs/guides/CODE-QUALITY.md`

## Safe Defaults for Agents

- Prefer existing canonical docs over guessing from stale comments or screenshots.
- When reviewing, prioritize runtime correctness, regressions, maintenance risk, and docs drift.
- When planning, leave one clear source of truth per subject instead of duplicating explanations across files.
- Keep deep technical docs in English.
- Keep contributor entry docs and product entry docs aligned across English and Portuguese.
- Do not treat redirect stubs as canonical docs.

## Review Defaults

- Findings first, ordered by severity.
- Include file references for concrete issues.
- Call out missing tests or validation gaps explicitly.
- If implementation changed the public behavior, verify the matching docs were updated.
