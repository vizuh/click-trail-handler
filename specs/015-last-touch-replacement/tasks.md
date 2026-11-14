# Tasks: A new touch replaces the whole last touch

**Input**: [spec.md](spec.md), [plan.md](plan.md)

- [x] T001 Node VM `tools/qa/last-touch-replacement.test.js` written first, seen failing on the base commit (5 of 9 checks), added to `npm run test:attribution`. — `tools/qa/`, `package.json`
- [x] T002 Clear `lt_` touch fields and click IDs in `applyTouch('lt', ...)` before the write. — `assets/js/clicutcl-attribution.js`
- [x] T003 Docs: touch model bullet in `docs/architecture/DATA-MODEL.md`. — `docs/architecture/DATA-MODEL.md`

## Dependencies

T001 → T002 → T003.
