# Tasks: Attribution cookie restore and server re-issue (Safari ITP)

**Input**: [spec.md](spec.md), [plan.md](plan.md), [research.md](research.md)

## Phase 1 — Server (US2, US3)

- [x] T001 `Cookie_Scope::covers()` and `Attribution_Cookie_Reissue` (validate, build header, emit). — `includes/Core/class-cookie-scope.php`, `includes/Core/class-attribution-cookie-reissue.php`
- [x] T002 Route `POST /clicutcl/v2/cookie-refresh` with the gate order of FR-003. — `includes/api/class-tracking-controller.php`
- [x] T003 Setting `attribution_cookie_reissue` (schema, mapping, `Attribution_Settings` getter, Capture toggle), seed rename `seed_fresh_install_defaults()`, localized `cookieReissueUrl`, token minting, Site Health line. — `includes/admin/class-admin.php`, `includes/settings/class-attribution-settings.php`, `includes/database/class-installer.php`, `clicutcl.php`, `includes/class-clicutcl-core.php`, `includes/admin/class-sitehealth.php`, `assets/js/admin-settings-app.js`
- [x] T004 PHPUnit `AttributionCookieReissueTest` (written first, seen failing) and the seed updates in `CookieScopeSettingsTest`. — `tests/unit/`

## Phase 2 — Browser (US1, US4)

- [x] T005 Restore from the mirror, pending marker, `reissueCookie()`, marker removal on `clearData()`. — `assets/js/clicutcl-attribution.js`
- [x] T006 Node VM `tools/qa/cookie-reissue.test.js` in `npm run test:attribution` (written first, seen failing); `ct_cookie_reissue` in the storage inventory so `cmp-transition.test.js` stays green. — `tools/qa/`, `package.json`, `docs/guides/SECURITY-PRIVACY.md`

## Phase 3 — Proof and docs

- [x] T007 E2E (done: 55 checks, 0 failures; S8 132 PASS) `tools/qa/e2e/cookie-reissue.sh` + `cookie_reissue.py`: wire shape, lifetime, single cookie, no page `Set-Cookie`; run `ci.sh` and `adapters-ci.sh` (S8) under `flock /tmp/ct-e2e.lock`. — `tools/qa/e2e/`
- [x] T008 Docs per FR-009, spec status line. — docs, readmes, `changelog.txt`

## Dependencies

T001 → T002 → T004. T003 → T005. T005 → T006. T002, T005 → T007. All → T008.
