# Implementation Plan: Attribution Across CMP Decision Transitions

**Branch**: `fix/cmp-decision-transitions` | **Date**: 2026-09-29 | **Spec**: [spec.md](spec.md) | **Issue**: [#103](https://github.com/vizuh/click-trail-handler/issues/103)

## Summary

This is mostly proof and documentation, plus one privacy fix. Gate the Site Health timestamp write behind granted consent, fix the stale pre-consent comment, publish a storage inventory with CMP classification guidance, and add a browser probe that reproduces the Cookiebot wipe-before-event ordering.

## Technical Context

**Language/Version**: browser ES2019; PHP 8.1+ only if the Site Health reader changes
**Testing**: `npm run test:consent` (vm-based, `tools/qa/consent-bridge.test.js`); a new Playwright probe on disposable WordPress (PHP 8.1, MariaDB 11), the same harness as the 1.10.2 release probe
**Constraints**: no pre-consent writes; no change to relaxed or not-required behavior

## Findings driving the plan (from `main` at `b674c15`)

| # | Finding | Location |
|---|---|---|
| F1 | `API.install()` writes `localStorage['clicutcl_js_last_seen']` unconditionally and runs from `handleConsentDenied()` | `assets/js/clicutcl-attribution.js` (`API.install`) |
| F2 | The Phase 2 comment says pending capture covers "navigated away before accepting consent", but `PendingCapture.save()` is gated to not-required or already-granted consent (since `fba8937`, v1.8.13) | `init()` and `runAttribution()` in the same file |
| F3 | The bridge resolves Cookiebot on `CookiebotOnConsentReady` (`once: true`) or `hasResponse` | `assets/js/clicutcl-consent-bridge.js` (`tryCookiebot`) |
| F4 | The docs list supported CMP sources but no storage inventory | `docs/guides/SECURITY-PRIVACY.md` |

## Design

1. **F1**: delete the write. `grep -rn clicutcl_js_last_seen assets includes` finds no reader (the Site Health code in `assets/js/admin-sitehealth.js` and `includes/admin/class-sitehealth.php` never reads it), so it is a dead, ungated storage write. Deleting it is smaller and safer than gating it.
2. **F2**: rewrite the comment to state the boundary. Add the same sentence to `docs/guides/SECURITY-PRIVACY.md`.
3. **Inventory**: a table in `SECURITY-PRIVACY.md` with the keys observed in source: the `attribution` cookie and storage key, `ct_session_id`, `ct_visitor_id`, `ct_session`, `ct_pending_v1`, `ct_consent`, `ct_consent_state`, `ct_consent_v1`, `ct_consent_updated`, `ct_thankyou_lead_*`, and `clicutcl_js_last_seen`. Re-derive the list in T001; do not trust this list blindly.
4. **Inventory check**: `tools/qa/storage-inventory.test.js`. It greps `setItem(`, `setCookie(` and `document.cookie =` writers in `assets/js/*.js`, resolves the constant keys, and diffs them against the table. Wire it into `package.json` and the existing PHP/QA workflow.
5. **Probe**: `tools/qa/cmp-transition.probe.mjs` with a Cookiebot stub that exposes `window.Cookiebot` (`hasResponse`, `consent.marketing`) and, when the banner is clicked, deletes every non-consent key before dispatching `CookiebotOnConsentReady` and then `CookiebotOnAccept` / `CookiebotOnDecline`. It covers the US1/US2 scenarios across three browsers.

## Gates

- AGENTS.md: storage and privacy changes update `DATA-MODEL.md`, `SECURITY-PRIVACY.md` and `OPERATIONS-RUNBOOK.md`.
- `/code-review` before commit.
