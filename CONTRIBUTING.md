# Contributing to ClickTrail

ClickTrail has three documentation audiences:

- GitHub visitors and evaluators
- contributors and reviewers
- AI agents and automation

Before changing code or docs, find the canonical reference for the area you are touching.

## Start Here

1. Read [README.md](README.md) for the repo entry points.
2. Use [docs/README.md](docs/README.md) to find the correct technical source of truth.
3. Check [.github/PULL_REQUEST_TEMPLATE.md](.github/PULL_REQUEST_TEMPLATE.md) before opening a PR.

## Local Workflow

1. Work from the current plugin state, not from stale docs or older screenshots.
2. Prefer small, reviewable changes with clear intent.
3. Keep user-facing copy aligned across `README.en.md`, `README.pt-BR.md`, and `readme.txt` when the product message changes.
4. Update `changelog.txt` when the change is release-note worthy.

## Local Setup and Checks

Prerequisites:

- PHP `8.1+`
- Composer
- npm
- a local WordPress install where this repo is available as a plugin

Recommended bootstrap:

1. Clone or symlink the repository into your local WordPress `wp-content/plugins/` directory.
2. Run `composer install` from the repo root to install coding standards and contributor tooling.
3. Activate the plugin in WordPress and confirm the admin screens load without fatal errors.
4. Use `docs/README.md` to find the canonical docs for the subsystem you plan to change before editing code.

Recommended checks before opening a PR:

- Run `composer phpcs` for the repository baseline coding-standard check.
- Run the affected flow manually in WordPress because the repo does not currently ship an automated PHP or JS test suite.
- Run `npm run make-zip` when your change affects packaging, release prep, or you want to validate the distributable plugin build. This command wraps `tools/release/make-zip.ps1`.

Manual validation examples:

- attribution or consent change: visit a URL with UTMs, confirm the plugin captures attribution, and verify consent-dependent behavior
- form integration change: submit a supported form and confirm the mapped attribution fields or event logs look correct
- server-side delivery change: inspect queue behavior, retry behavior, and diagnostics/log output for the affected adapter or endpoint

## Expectations for Pull Requests

- Explain the problem and the chosen approach.
- Call out compatibility, migration, or runtime behavior changes.
- Include screenshots for admin UI changes.
- Say what was tested and what could not be tested.
- Update docs in the same PR when the repository truth changes.

## Docs Update Matrix

- Admin or settings changes: update [docs/guides/SETTINGS-AND-ADMIN.md](docs/guides/SETTINGS-AND-ADMIN.md) and any affected readme copy.
- API or webhook changes: update [docs/reference/REST-API.md](docs/reference/REST-API.md) and [docs/reference/HOOKS-REFERENCE.md](docs/reference/HOOKS-REFERENCE.md).
- Storage, cookies, queue, or retention changes: update [docs/architecture/DATA-MODEL.md](docs/architecture/DATA-MODEL.md), [docs/guides/SECURITY-PRIVACY.md](docs/guides/SECURITY-PRIVACY.md), and [docs/guides/OPERATIONS-RUNBOOK.md](docs/guides/OPERATIONS-RUNBOOK.md).
- Integration changes: update [docs/reference/INTEGRATIONS.md](docs/reference/INTEGRATIONS.md) and the product readmes when the change is user-facing.
- Architecture or boot flow changes: update [docs/architecture/PLUGIN-OVERVIEW.md](docs/architecture/PLUGIN-OVERVIEW.md) and [docs/architecture/CODE-MAP.md](docs/architecture/CODE-MAP.md).
- Quality, cleanup, or dead-code posture changes: update [docs/guides/CODE-QUALITY.md](docs/guides/CODE-QUALITY.md) when the repository maintenance posture changes.

## Source of Truth Rules

- Product positioning belongs in `README.en.md`, `README.pt-BR.md`, and `readme.txt`.
- Admin IA belongs in `docs/guides/SETTINGS-AND-ADMIN.md`.
- Route and auth truth belongs in `docs/reference/REST-API.md`.
- Storage and queue truth belongs in `docs/architecture/DATA-MODEL.md`.
- Integration support truth belongs in `docs/reference/INTEGRATIONS.md`.

## Practical Review Checklist

- Does the code match the current docs?
- Can a fresh contributor follow the setup and checks above without guessing hidden prerequisites?
- Does the PR template have every section filled meaningfully?
- Are any screenshots or gifs needed for admin changes?
- Did the change create or remove a maintenance hotspot that should be reflected in `CODE-QUALITY.md`?
- Are redirect stubs or moved-doc links still valid?
