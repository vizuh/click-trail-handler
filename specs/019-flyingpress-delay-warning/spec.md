# Feature Specification: FlyingPress Delay JavaScript warning

**Feature Branch**: `Atroci/ct-wp-15-flyingpress-warning`

**Created**: 2026-11-14

**Status**: Implemented (PHPUnit `FlyingPressDelayWarningTest`). Source-present / runtime-unverified: no real FlyingPress was run.

**Input**: GitHub issue [#165](https://github.com/vizuh/click-trail-handler/issues/165), FlyingPress part only. FlyingPress delays JavaScript by default and has no filter for Delay JS (see [../006-optimizer-exclusions/research.md](../006-optimizer-exclusions/research.md), R3), so `Optimizer_Compat` cannot exclude the four ClickTrail scripts. Without the exclusion the landing touch is recorded after the first interaction, or not at all.

## User Scenarios & Testing

### User Story 1 - Site owner is told which keywords to exclude (Priority: P1)

A site owner runs FlyingPress with Delay JavaScript on. Site Health and the Diagnostics conflict scan show a warning that names the keywords to paste into FlyingPress.

**Independent Test**: PHPUnit `tests/unit/FlyingPressDelayWarningTest.php`.

**Acceptance Scenarios**:

1. **Given** `flying-press/flying-press.php` in `active_plugins`, **Then** `Setup_Detector::flyingpress_delay_warning()` returns text naming `clicutcl` and `clicktrail-consent-mode`, Site Health reports `recommended` with the same text, and the conflict scan adds a `warn` finding.
2. **Given** FlyingPress is not active (including a look-alike folder such as `flying-press-helper`), **Then** the warning is an empty string, Site Health reports `good`, and the scan adds no finding.

## Requirements

- **FR-001**: One shared check, `Setup_Detector::flyingpress_delay_warning()`, feeds both surfaces (same pattern as `detect_call_tracking_labels()` delegating to `Setup_Detector::detect_call_tracking()`); no new detector class.
- **FR-002**: Keywords live in one constant, `Optimizer_Compat::FLYINGPRESS_DELAY_KEYWORDS`. FlyingPress matches partial keywords against the whole `<script>` tag, so `clicutcl` covers the four files and `clicktrail-consent-mode` the inline snippet.
- **FR-003**: No new vendor filter is registered; the existing `flying_press_exclude_from_minify:js` stays.
- **FR-004**: Docs: `docs/guides/OPERATIONS-RUNBOOK.md` (failure pattern 1, conflict scan list) and `docs/reference/INTEGRATIONS.md` (optimizer exclusions).

## Known limitation

FlyingPress ships no public source and documents no readable option for Delay JavaScript or its exclusion list, so the check cannot tell "Delay JS off" or "keywords already present" from the opposite. It warns whenever FlyingPress is active and does not clear after the keywords are added. Reading the option is a follow-up once its name and shape can be cited from FlyingPress source or docs. Network-activated FlyingPress on multisite is not detected (`active_plugins` only).
