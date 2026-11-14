# Implementation Plan: Paid markers `gad_source`, `gad_campaignid` and `oppref`

**Branch**: `Atroci/ct-wp-12-gad-oppref` | **Date**: 2026-11-14 | **Spec**: [spec.md](spec.md) | **Issue**: [#231](https://github.com/vizuh/click-trail-handler/issues/231)

## Summary

The markers join `CLICK_ID_KEYS` in `clicutcl-attribution.js`, which already drives query parsing (128-character cap), top-level plus `ft_`/`lt_` storage, the hidden-field map and the first-touch check. They stay out of `D3_CLICK_ID_KEYS`, so `applyClickIdSelection`, `selectedClickIdKey` and the Calendly stamp never see them. `resolveChannelLabel` and `mapQueryFields` carry the channel and medium rules. On the server a separate `PAID_MARKER_FIELDS` list in `Attribution_Provider` feeds `get_field_mapping()` and the top-level mirror, but not `get_click_id_fields()`, so `EventV2`, Woo and EDD click-ID paths and ad adapters never treat them as click IDs. `Woo_Order_Privacy::managed_key_families()` derives from the field mapping, so no privacy code changes.

## Decision: default medium

`cpc` is added when the URL has no `utm_medium` and carries `gclid`, `gbraid`, `wbraid`, `gad_source` or `oppref`, as the unit requirement reads. This also changes a bare `gclid` landing: `lt_medium` was empty, it is now `cpc` (and `lt_source` stays empty, as before). No existing test pinned the empty medium; `tools/qa/smoke.js` and `repeat-visit-scenarios.js` all pass `utm_medium`. Rollback: drop the `gclid`/`gbraid`/`wbraid` terms from that one condition.

## Repo gates

AGENTS.md integration-behaviour trigger: `docs/reference/INTEGRATIONS.md`, `README.en.md`, `README.pt-BR.md`, `readme.txt` (capture lists). No storage layout, REST or hook change. `changelog.txt` is not edited (one proposed line in the PR body).

## Files

| Change | File |
|---|---|
| key list, channel rules, default medium/source | `assets/js/clicutcl-attribution.js` |
| `PAID_MARKER_FIELDS`, field mapping, top-level mirror | `includes/Core/class-attribution-provider.php` |
| same channel rules | `includes/integrations/forms/class-gf-channel-resolver.php` |
| Node VM test, wired into `test:attribution` | `tools/qa/paid-markers.test.js`, `package.json` |
| PHPUnit | `tests/unit/ChannelResolverTest.php`, `ClickIdPrecedenceTest.php`, `WooOrderPrivacyTest.php` |
