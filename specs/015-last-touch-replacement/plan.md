# Implementation Plan: A new touch replaces the whole last touch

**Branch**: `Atroci/ct-wp-11-last-touch` | **Date**: 2026-11-14 | **Spec**: [spec.md](spec.md) | **Issue**: [#230](https://github.com/vizuh/click-trail-handler/issues/230)

## Technical Context

**Language/Version**: browser ES2019 (one script changes: `assets/js/clicutcl-attribution.js`); no PHP change
**Primary Dependencies**: none new
**Storage**: no new key; the `attribution` cookie and its localStorage mirror keep their shape, only fewer `lt_*` keys are stored
**Testing**: Node VM `tools/qa/last-touch-replacement.test.js` (in `npm run test:attribution`); `npm run test:consent`; `tools/qa/smoke.js`; full PHPUnit (872 tests)
**Constraints**: first touch write-once; top-level click IDs and `click_id_history` newest-per-key; no consent path change

## Approach

Two changes. First, in `Attribution.applyTouch()` (`assets/js/clicutcl-attribution.js`): when `prefix === 'lt'`, delete the touch fields and click IDs of the old last touch before writing the new one. The key list is built from the existing `TOUCH_FIELD_KEYS` and `CLICK_ID_KEYS` constants plus `channel` and `referrer` (the two fields `resolveTouch` adds outside `TOUCH_QUERY_FIELD_MAP`).

`runAttribution()` already calls `applyTouch('lt', ...)` only when `touch.hasSignal`, so direct visits, ignored payment referrers and same-site referrers never reach it. `mergeTopLevelIdentifiers` and `applyClickIdSelection` run outside `applyTouch`, so top-level click IDs and `click_id_history` keep newest-per-key.

Second, in `Store.getData()`: when both the cookie and the localStorage mirror exist, take all `lt_*` keys from the copy with the newest `lt_touch_timestamp` (cookie on a tie) and merge everything else as before. Needed because sibling subdomains on one `cookieDomain` cookie each keep their own mirror; a per-key merge brought the deleted `lt_*` keys back and the next save wrote them into the shared cookie (review round 1, PR #234). `ft_*` needs no such rule: it is write-once, so the copies agree.

## Why not clear in `runAttribution()`

`applyTouch` is the single writer of `lt_*` touch fields; clearing there keeps the replacement next to the write and out of the cross-domain token and consent paths.

## Verification

`node tools/qa/last-touch-replacement.test.js` (fails on the base commit, passes after), `npm run test:consent`, `npm run test:attribution`, full PHPUnit (872 tests).

## Risks

A reader that relied on a stale `lt_campaign` or `lt_<click id>` now sees it absent. PHP readers use empty-checked lookups. Rollback: revert the commit; stored cookies hold no new shape.
