# Tasks: FlyingPress Delay JavaScript warning

**Input**: [spec.md](spec.md), [plan.md](plan.md)

- [x] T001 PHPUnit `FlyingPressDelayWarningTest` (written first, seen failing on base 26da3d7). — `tests/unit/FlyingPressDelayWarningTest.php`
- [x] T002 `FLYINGPRESS_DELAY_KEYWORDS` constant. — `includes/integrations/class-optimizer-compat.php`
- [x] T003 `Setup_Detector::detect_flyingpress()` and `flyingpress_delay_warning()`. — `includes/admin/class-setup-detector.php`
- [x] T004 Site Health test `clicutcl_flyingpress_delay`. — `includes/admin/class-sitehealth.php`
- [x] T005 Conflict-scan finding. — `includes/admin/traits/trait-admin-diagnostics-ajax.php`
- [x] T006 Docs. — `docs/guides/OPERATIONS-RUNBOOK.md`, `docs/reference/INTEGRATIONS.md`

## Dependencies

T001 first. T002 → T003 → T004, T005. All → T006.
