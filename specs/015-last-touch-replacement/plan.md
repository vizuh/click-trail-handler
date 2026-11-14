# Plan: A new touch replaces the whole last touch

**Input**: [spec.md](spec.md)

## Approach

Single change in `Attribution.applyTouch()` (`assets/js/clicutcl-attribution.js`): when `prefix === 'lt'`, delete the touch fields and click IDs of the old last touch before writing the new one. The key list is built from the existing `TOUCH_FIELD_KEYS` and `CLICK_ID_KEYS` constants plus `channel` and `referrer` (the two fields `resolveTouch` adds outside `TOUCH_QUERY_FIELD_MAP`).

`runAttribution()` already calls `applyTouch('lt', ...)` only when `touch.hasSignal`, so direct visits, ignored payment referrers and same-site referrers never reach it. `mergeTopLevelIdentifiers` and `applyClickIdSelection` run outside `applyTouch`, so top-level click IDs and `click_id_history` keep newest-per-key.

## Why not clear in `runAttribution()`

`applyTouch` is the single writer of `lt_*` touch fields; clearing there keeps the replacement next to the write and out of the cross-domain token and consent paths.

## Verification

`node tools/qa/last-touch-replacement.test.js` (fails on the base commit, passes after), `npm run test:consent`, `npm run test:attribution`, full PHPUnit (872 tests).

## Risks

A reader that relied on a stale `lt_campaign` or `lt_<click id>` now sees it absent. PHP readers use empty-checked lookups. Rollback: revert the commit; stored cookies hold no new shape.
