# Tasks: Sensitive query redaction for stored landing page and referrer

**Input**: [spec.md](spec.md), [plan.md](plan.md)

- [x] T001 Node VM `tools/qa/sensitive-query.test.js` written first, seen failing on the base (14 of 14), wired into `npm run test:attribution`. — `tools/qa/`, `package.json`
- [x] T002 PHPUnit `SensitiveQueryRedactionTest` and the config-localize case written first, seen failing on the base (9 of 10; the one that passes is the "other fields stay alone" guard). — `tests/unit/`
- [x] T003 JS: `redactSensitiveQuery` at `applyTouch`, `getExternalReferrerDetails`, token merge, `PendingCapture`, `sanitizeAttributionData`. — `assets/js/clicutcl-attribution.js`
- [x] T004 PHP: constant, `clicutcl_sensitive_query_keys` filter, `redact_sensitive_query()`, `sanitize()`, localize. — `includes/Core/class-attribution-provider.php`, `includes/class-clicutcl-core.php`
- [x] T005 Docs: redaction rules and the hook. — `docs/guides/SECURITY-PRIVACY.md`, `docs/reference/HOOKS-REFERENCE.md`

## Dependencies

T001, T002 → T003, T004. All → T005.

## Events (round 3; files outside the unit's original list, widened because the acceptance names events)

- [x] T006 Events: the `document.referrer` fallback (`marketing_trail.referrer`, `page_context.referrer`) is redacted in `assets/js/clicutcl-events.js` via `window.ClickTrail.redactSensitiveQuery` (query dropped when attribution is not loaded), and `landing_page` / `referrer` / `page.referrer` in `includes/server-side/class-event.php` via `Attribution_Provider::redact_sensitive_query()`. Found by the round 1 review: on a second same-origin page with no stored referrer, `document.referrer` is the raw landing URL. Tests written first and seen failing: 2 Node VM cases (`tools/qa/sensitive-query.test.js`) and `SensitiveQueryRedactionTest::test_event_redacts_the_page_referrer_and_the_trail_envelope`. — `assets/js/clicutcl-events.js`, `assets/js/clicutcl-attribution.js`, `includes/server-side/class-event.php`
- [x] T007 Docs: events paragraph. — `docs/guides/SECURITY-PRIVACY.md`, `docs/reference/HOOKS-REFERENCE.md`
