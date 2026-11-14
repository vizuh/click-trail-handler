# Implementation Plan: Sensitive query redaction for stored landing page and referrer

**Branch**: `Atroci/ct-wp-13-query-redaction` | **Date**: 2026-11-14 | **Spec**: [spec.md](spec.md) | **Issue**: [#232](https://github.com/vizuh/click-trail-handler/issues/232)

## Summary

One pure function, `redactSensitiveQuery(url)`, in `clicutcl-attribution.js` cuts the fragment and drops sensitive parameters from the raw query string, so kept parameters keep their order and encoding. It runs where a URL is written (`applyTouch` for `ft` and `lt`, `getExternalReferrerDetails`, the cross-domain token merge, `PendingCapture`) and where stored values are read (`sanitizeAttributionData` for the four URL fields), which cleans old cookies and values returned by token verification. `PendingCapture.params` drops sensitive keys too, except the cross-domain token that promotion reads back.

`Attribution_Provider` holds the default list (`SENSITIVE_QUERY_KEYS`), the `clicutcl_sensitive_query_keys` filter in `get_sensitive_query_keys()`, and the PHP twin `redact_sensitive_query()`. `sanitize()` calls it on the four URL fields before `sanitize_text_field()`, because core strips percent-encoded octets and would otherwise turn `jane%40example.com` into text the key match cannot see. `Plugin::build_attribution_config()` localizes the list as `sensitiveQueryKeys`.

## Decisions

- **Match rule**: exact lowercased name or `_<word>` suffix. `monkey`, `keyword`, `tokens` stay. Names are URL-decoded before matching, so `%74oken` is caught.
- **Protected keys**: `utm_*`, `CLICK_ID_KEYS`, `sc_click_id`, paid markers. Checked before the list, so a filter cannot remove attribution data.
- **Default in two places**: the script keeps a copy of the default list for pages without the localized config (fail closed). `tools/qa/sensitive-query.test.js` reads the PHP constant and checks the script's default redacts every entry, so the two cannot drift silently.
- **Fragment**: dropped everywhere. The plain `window.location.href` stored before included it.
- **`ct_token`** ends in `_token` and is therefore removed from the stored landing URL. Cross-domain promotion reads it from the parsed params, not from the stored URL.
- **Existing test changed**: `payment-referrer.test.js` expected the WooCommerce `?key=wc_order_abc` in the stored landing page. The order key is a secret, so the expectation now has no query.
- **Filter `@since`**: `1.14.0` is an assumption about the next release; adjust at release time.

## Repo gates

AGENTS.md storage/cookie trigger: `docs/guides/SECURITY-PRIVACY.md` (done). Hook trigger: `docs/reference/HOOKS-REFERENCE.md` (done). `docs/architecture/DATA-MODEL.md` and `docs/guides/OPERATIONS-RUNBOOK.md` are outside this unit's file list; the stored fields and names do not change, only their content. `changelog.txt` is not edited (one proposed line in the PR body).

## Files

| Change | File |
|---|---|
| `redactSensitiveQuery`, default list, call sites | `assets/js/clicutcl-attribution.js` |
| constant, filter, `redact_sensitive_query()`, `sanitize()` | `includes/Core/class-attribution-provider.php` |
| localize `sensitiveQueryKeys` | `includes/class-clicutcl-core.php` |
| Node VM test, wired into `test:attribution`; one expectation updated | `tools/qa/sensitive-query.test.js`, `tools/qa/payment-referrer.test.js`, `package.json` |
| PHPUnit | `tests/unit/SensitiveQueryRedactionTest.php`, `tests/unit/PaymentReturnAttributionTest.php` |
| docs | `docs/guides/SECURITY-PRIVACY.md`, `docs/reference/HOOKS-REFERENCE.md` |
