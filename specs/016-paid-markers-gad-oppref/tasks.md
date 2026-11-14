# Tasks: Paid markers `gad_source`, `gad_campaignid` and `oppref`

**Input**: [spec.md](spec.md), [plan.md](plan.md)

- [x] T001 Node VM `tools/qa/paid-markers.test.js` written first, seen failing on the base (4 of 8), wired into `npm run test:attribution`. — `tools/qa/`, `package.json`
- [x] T002 PHPUnit cases written first, seen failing on the base (4). — `tests/unit/ChannelResolverTest.php`, `ClickIdPrecedenceTest.php`, `WooOrderPrivacyTest.php`
- [x] T003 JS: `CLICK_ID_KEYS`, `resolveChannelLabel`, `mapQueryFields`. — `assets/js/clicutcl-attribution.js`
- [x] T004 PHP: `PAID_MARKER_FIELDS` in the field mapping and top-level mirror; Gravity Forms resolver rules. — `includes/Core/class-attribution-provider.php`, `includes/integrations/forms/class-gf-channel-resolver.php`
- [x] T005 Docs: capture lists and order meta. — `docs/reference/INTEGRATIONS.md`, `README.en.md`, `README.pt-BR.md`, `readme.txt`

## Dependencies

T001, T002 → T003, T004. All → T005.
