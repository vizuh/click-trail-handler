# ClickTrail Technical Documentation

This folder documents the current plugin implementation in this repository (version `1.3.2`).

## Read This First

- `docs/PLUGIN-OVERVIEW.md` - product and runtime overview
- `docs/EVENT-PIPELINE.md` - end-to-end event flow (browser -> canonical -> dispatch)
- `docs/REST-API.md` - v2 API contracts and auth
- `docs/SETTINGS-AND-ADMIN.md` - all settings surfaces and admin UI behavior
- `docs/DATA-MODEL.md` - options, cookies, transients, DB tables, cron
- `docs/SECURITY-PRIVACY.md` - trust model, consent, identity handling
- `docs/HOOKS-REFERENCE.md` - custom filters/actions
- `docs/OPERATIONS-RUNBOOK.md` - install, migration, diagnostics, queue ops
- `docs/CODE-MAP.md` - file-by-file code map

## Scope Notes

- This documentation is grounded in code under:
  - `clicutcl.php`
  - `includes/`
  - `assets/`
  - `uninstall.php`
- Legacy classes that exist in code but are not wired into current bootstrap are documented and explicitly marked as legacy.

