# Tasks: No-JavaScript attribution boundary (documentation)

**Input**: [spec.md](spec.md), [plan.md](plan.md)

- [x] T001 Verify the claims against `includes/Core/class-attribution-provider.php`, `includes/integrations/forms/class-abstract-form-adapter.php`, `includes/Core/class-attribution-cookie-reissue.php` and a grep for PHP reads of campaign parameters. (read-only)
- [x] T002 `docs/guides/SECURITY-PRIVACY.md`: no-JS boundary and render-filter paragraphs.
- [x] T003 `docs/reference/INTEGRATIONS.md`: paragraph after the submit-time rule.
- [x] T004 `README.en.md` and `README.pt-BR.md`: one bullet each under what it does not do.
- [x] T005 `readme.txt`: one FAQ entry (changelog and stable tag untouched).
- [x] T006 Run `node -e "require('./tools/qa/smoke.js')"` and check `git diff --stat`.

## Dependencies

T001 before T002-T005. T002-T005 before T006.
