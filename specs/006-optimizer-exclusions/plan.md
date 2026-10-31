# Implementation Plan: Optimizer Exclusions For ClickTrail Scripts

**Branch**: `fix/optimizer-exclusions` | **Date**: 2026-10-31 | **Spec**: [spec.md](spec.md) | **Issue**: [#153](https://github.com/vizuh/click-trail-handler/issues/153)

## Summary

Move the script-tag exclusion logic from the Gravity Forms adapter into a core compatibility class registered at bootstrap, correct the attribute set to what each vendor documents, add the vendor PHP filters that are verified in research.md, mark the inline consent snippet, and make the docs say exactly that.

## Technical Context

**Language/Version**: PHP 8.1+, WordPress 6.5+ (`readme.txt:6`)
**Primary Dependencies**: none new; vendor filters are plain `add_filter` calls that are inert when the vendor is absent
**Storage**: none
**Testing**: PHPUnit `tests/unit/OptimizerExclusionsTest.php` (new; bootstrap stubs `apply_filters`, tests stub `add_filter` as `tests/unit/CF7SubmissionOutcomeTest.php:15` does); `tools/qa/e2e` HTML dump for SC-004
**Constraints**: no settings; no behaviour change for sites without optimizers beyond extra attributes on four tags and one inline snippet

## Repo gates

- AGENTS.md docs triggers: `README.en.md`, `readme.txt` (feature line and caching FAQ), `changelog.txt`, `docs/guides/IMPLEMENTATION-PLAYBOOK.md`, `docs/reference/INTEGRATIONS.md`, `docs/architecture/CODE-MAP.md`, `docs/MASTER-SPECIFICATION-CHANGE-RECORD.md`. No new public hook of our own, so `HOOKS-REFERENCE.md` is unchanged; `SETTINGS-AND-ADMIN.md` is unchanged.
- The diagnostics conflict scan (`includes/admin/traits/trait-admin-diagnostics-ajax.php:1035-1064`) already detects WP Rocket, LiteSpeed, Autoptimize, NitroPack and Cloudflare; its wording stays, no new finding.
- Evidence boundary: attributes and filters are source-present / runtime-unverified until SC-004 is recorded.

## Design

### Server: new `includes/integrations/class-optimizer-compat.php`

| Change | Detail |
|---|---|
| Class `CLICUTCL\Integrations\Optimizer_Compat` (static, mirrors `NitroPack_Compat`) | Replaces `Gf_Minification_Protector`; the old file is deleted and `class-gravity-forms-adapter.php:104` plus its docblock at line 8 drop the reference |
| `register()` | Always registers (no vendor constant gate; attributes are harmless). Hooks `wp_script_attributes` (primary) and `script_loader_tag` (string fallback and idempotency guard) for `HANDLES`; registers every verified vendor filter |
| `HANDLES` | the four front-end handles; `clicutcl-events-js` added |
| `ATTRIBUTES` | `array( 'nowprocket' => true, 'data-no-optimize' => '1', 'data-no-defer' => '1', 'data-noptimize' => '1', 'data-cfasync' => 'false' )` |
| `add_attributes( array $attr )` on `wp_script_attributes` | If `$attr['id']` is `{handle}-js` for one of `HANDLES`, merge `ATTRIBUTES` with `$attr + ATTRIBUTES` (existing keys win). Keyed array means no duplicates and the attributes land on the `src` tag only |
| `mark_tag( string $tag, string $handle )` on `script_loader_tag` | For `HANDLES` only: for each attribute name absent from the tag (word-boundary match), insert after the `<script` token whose `id="{handle}-js"`; otherwise leave the tag. Covers hosts that print tags without `wp_get_script_tag()` and any plugin that re-applies the filter |
| Vendor filters | `exclude_paths( array $list )` adds the four `plugins/click-trail-handler/assets/js/clicutcl-*.js` paths (relative, so they also match WP Rocket's minified copies and CDN rewrites); `exclude_files( array $list )` adds the four file names; `exclude_handles( array $list )` adds the four handles; `exclude_csv( string $list )` for Autoptimize. Registered per the table in research.md R3 |
| Consent snippet | `class-consent-mode.php:95` echoes `<script id="clicktrail-consent-mode" nowprocket data-no-defer="1">` |

### Admin and bootstrap

| Change | File |
|---|---|
| `Optimizer_Compat::register();` after `NitroPack_Compat::register();` | `includes/class-clicutcl-core.php:178` |
| Remove `new Gf_Minification_Protector();` and the docblock mention | `includes/integrations/forms/class-gravity-forms-adapter.php:8,104` |
| Delete `includes/integrations/forms/class-gf-minification-protector.php` | autoloader resolves the new path from the namespace (`includes/class-autoloader.php`) |

### Contract: rendered tags and filter outputs

```text
<script src=".../clicutcl-attribution.js?ver=1.11.x" id="clicutcl-attribution-js" defer data-wp-strategy="defer"
        nowprocket data-no-optimize="1" data-no-defer="1" data-noptimize="1" data-cfasync="false"
        [data-nitropack-exclude="true"]></script>
same five attributes on clicutcl-consent-bridge-js, clicutcl-consent-js, clicutcl-events-js
<script id="clicktrail-consent-mode" nowprocket data-no-defer="1">window.dataLayer=...;gtag('consent','default',...)</script>

rocket_delay_js_exclusions, rocket_exclude_defer_js, rocket_exclude_js,
litespeed_optm_js_exc, litespeed_optm_js_defer_exc, litespeed_optm_gm_js_exc
    -> list + 4 paths  "plugins/click-trail-handler/assets/js/clicutcl-<name>.js"
perfmatters_delay_js_exclusions, perfmatters_defer_js_exclusions, perfmatters_minify_js_exclusions
    -> list + 4 file names "clicutcl-<name>.js"
autoptimize_filter_js_exclude -> csv + ",clicutcl-<name>.js" x4
sgo_js_minify_exclude, sgo_javascript_combine_exclude, sgo_js_async_exclude
    -> list + 4 handles
Ready signal for operators: window.ClickTrail defined (assets/js/clicutcl-attribution.js:1063) and ct_ready dispatched (:1084)
```

### Tests (`tests/unit/OptimizerExclusionsTest.php`)

- Render the WordPress 6.5 tag shape for each handle (`wp_get_script_tag` output with `id`, `defer data-wp-strategy="defer"` where applicable), pass through `add_attributes` then `mark_tag`, assert each attribute count is 1 (`substr_count`), repeat `mark_tag` on the output and assert identity; prepend a theme-added `data-cfasync="false"` and assert count stays 1.
- Run with and without `class_exists('GFForms')` stubbed; the result must not depend on it.
- Call every vendor callback with an empty list and assert the four entries; call with a list that already contains them and assert no duplicates.
- A foreign handle (`jquery-core`) returns byte-identical.

## Risks

- `nowprocket` as a boolean attribute: `wp_get_script_tag` prints `nowprocket` bare for `true`; the string fallback must also insert it bare. A `nowprocket="1"` would still match WP Rocket's regex but differs from the vendor example.
- WP Rocket Delay JS matches `nowprocket` as a substring of the whole tag; any script whose URL contains that word is excluded too. Not a ClickTrail concern.
- Sites with Delay JS enabled and a manual exclusion already in place will see no change; sites that relied on attribution running late (never intended) will see the landing touch recorded earlier.
- Perfmatters and FlyingPress inline delay handling is not documented in the pages read; the consent snippet relies on `nowprocket` (WP Rocket) and `data-no-defer` (LiteSpeed) only.
- Removing `Gf_Minification_Protector` is a class rename; nothing outside the adapter references it (grep across `includes/`, `tests/`, `docs/` on 2026-10-31 found only the adapter, the changelog and the change record).
