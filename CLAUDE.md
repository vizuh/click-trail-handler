# Claude Adapter

This repository uses [AGENTS.md](AGENTS.md) as the neutral source of truth for AI and coding agents.

Read `AGENTS.md` first for:

- repository structure
- documentation ownership
- doc update triggers
- review and planning defaults

Keep this file limited to Claude Flow or Claude-specific operating notes.

## Claude Flow Notes

- Claude Flow was removed 2026-08-16. It was always local-only tooling — never shipped or
  committed to this repository.
- `.claude/` now holds GitHub Spec Kit's Claude integration (`.claude/skills/speckit-*`).
- `.specify/` holds the Spec Kit templates and workflow.
- Both are dev-only and already excluded from the WordPress.org release via `.distignore`.
- Do not treat `CLAUDE.md` as the canonical repo guide.
- When planning or reviewing, follow the same docs-update matrix defined in `AGENTS.md`.

<!-- SPECKIT START -->
For additional context about technologies to be used, project structure,
shell commands, and other important information, read the current plan
<!-- SPECKIT END -->
