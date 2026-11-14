# Implementation Plan: FlyingPress Delay JavaScript warning

**Spec**: [spec.md](spec.md) | **Issue**: [#165](https://github.com/vizuh/click-trail-handler/issues/165)

## Summary

Add `Setup_Detector::detect_flyingpress()` (reads `active_plugins`, folder `flying-press`) and `Setup_Detector::flyingpress_delay_warning()`. Call the latter from a new `SiteHealth::test_flyingpress_delay()` test and from `build_conflict_scan_report()` next to the NitroPack finding.

## Decisions

| Decision | Reason |
|---|---|
| Detect through `active_plugins`, not a constant | The answer tracks site state and stays testable in one PHPUnit process; same approach as `detect_cmp()`. |
| Detect "active" only, no option read | No citable FlyingPress source for the option name or shape (research.md R3). A guessed key would silently disable the warning, the failure class of #165. |
| Keywords in `Optimizer_Compat` | One constant for the message and both docs. |

## Repo gates

AGENTS.md docs triggers (integration behaviour, operations/diagnostics): `docs/reference/INTEGRATIONS.md`, `docs/guides/OPERATIONS-RUNBOOK.md`. Changelog is proposed in the PR body, not edited here.
