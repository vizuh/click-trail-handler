# Research: Optimizer Exclusions For ClickTrail Scripts

Vendor docs and sources read on 2026-10-31 with the read tool:
- WP Rocket Delay JS: https://docs.wp-rocket.me/article/1349-delay-javascript-execution
- WP Rocket Defer JS exclusions: https://docs.wp-rocket.me/article/976-exclude-files-from-defer-js
- WP Rocket source (develop): `inc/Engine/Optimization/DelayJS/HTML.php`, `inc/Engine/Optimization/DeferJS/DeferJS.php`, `inc/Engine/Optimization/Minify/JS/AbstractJSOptimization.php`; `dynamic-lists.json` (trunk)
- LiteSpeed Page Optimization: https://docs.litespeedtech.com/lscache/lscwp/pageopt/, API: https://docs.litespeedtech.com/lscache/lscwp/api/, source (master): `src/optimize.cls.php`
- Autoptimize source (master): `classes/autoptimizeScripts.php`
- Perfmatters filters: https://perfmatters.io/docs/filters/
- SiteGround Speed Optimizer custom filters: https://www.siteground.com/tutorials/wordpress/speed-optimizer/custom-filters
- FlyingPress Delay All JavaScript: https://docs.flyingpress.com/en/articles/11406701-delay-all-javascript
- Cloudflare Rocket Loader: https://developers.cloudflare.com/speed/optimization/content/rocket-loader/ignore-javascripts/
- WordPress core `class-wp-scripts.php` (trunk, 6.5, 6.7) and `script-loader.php` (trunk)
- Competitor review for the broader form-reliability work: [../004-submit-time-refresh-parity/research.md](../004-submit-time-refresh-parity/research.md)

## Current state (source, `main` at `ef6a20b`, 1.11.0)

| Surface | File | Finding |
|---|---|---|
| Attribute injection | `includes/integrations/forms/class-gf-minification-protector.php:38` | `data-no-optimize="1" data-noptimize="1" data-cfasync="false" data-no-defer="1" data-no-minify="1"`; no `nowprocket`. Docblock lines 12-13 attribute `data-no-defer` / `data-no-minify` to WP Rocket; WP Rocket does not read them (R2). |
| Handles | same file, lines 45-49 | three handles; `clicutcl-events-js` missing |
| Registration | `class-gravity-forms-adapter.php:104` | `new Gf_Minification_Protector()` inside the adapter; no Gravity Forms, no attributes |
| Idempotency | `class-gf-minification-protector.php:75` | returns early when `data-noptimize` is present; regex at 82-87 rewrites the first `<script` token |
| NitroPack | `includes/integrations/class-nitropack-compat.php:35-56` | four handles, `nitropack_js_url_exclude` plus `data-nitropack-exclude="true"`, gated on `NITROPACK_VERSION`; registered at `includes/class-clicutcl-core.php:178` |
| Enqueues | `includes/class-clicutcl-core.php:230-306` | bridge and consent banner blocking; attribution and events `defer` strategy via `clicutcl_script_args( true, 'defer' )` (`clicutcl.php:50`) |
| Consent snippet | `includes/Modules/consent-mode/class-consent-mode.php:95-101` | raw `echo '<script id="clicktrail-consent-mode">'`, no attributes |
| Docs | `README.en.md:84`, `readme.txt:81`, `readme.txt:334` | claim site-wide exclusions for Autoptimize, Rocket Loader, WP Rocket, LiteSpeed |
| Diagnostics | `trait-admin-diagnostics-ajax.php:1035-1064` | detects WP Rocket, LiteSpeed, WP Super Cache, Autoptimize, NitroPack, WP Engine, Cloudflare |
| CODE-MAP | `docs/architecture/CODE-MAP.md:92-94` | lists `integrations/forms/` only; the protector is not named |
| Ready signal | `assets/js/clicutcl-attribution.js:1063,1084` | `window.ClickTrail` assigned, `ct_ready` dispatched |

## R1 - Where the tag is built

**Verified in core source**: `WP_Scripts::do_item` builds the `src` tag with `wp_get_script_tag( $attr )` (`class-wp-scripts.php:429` on 6.5 and 6.7; `$attr` has `src`, `id = "{handle}-js"`, the strategy flag and `data-wp-strategy`), then applies `script_loader_tag` to translations + before + src tag + after (trunk lines 404-416). `wp_get_script_tag` applies `wp_script_attributes` (`script-loader.php:3287`); boolean `true` prints a bare attribute. Localized data is printed separately by `print_extra_script` as `{handle}-js-extra`.

**Decision**: primary path `wp_script_attributes` keyed on `id`; `script_loader_tag` kept as a string fallback with a per-attribute presence check. Target the `<script` token carrying `id="{handle}-js"`, not the first token.

## R2 - Attribute set

| Attribute | Vendor | Evidence |
|---|---|---|
| `nowprocket` | WP Rocket Delay JS | [verified in vendor doc https://docs.wp-rocket.me/article/1349-delay-javascript-execution] Troubleshoot section: "adding the `nowprocket` attribute to inline scripts or scripts in external files". Source: `nowprocket` is the first entry of `delay_js_exclusions` in `dynamic-lists.json`; `HTML::replace_scripts` matches each pattern against the whole tag. |
| `data-no-minify="1"` | none found | [unverified, dropped] Not on the LiteSpeed pages read and absent from LiteSpeed `src/optimize.cls.php`, WP Rocket and Autoptimize sources. Shipped since 1.7.0 (`readme.txt:334`) with no reader. Removed by this spec; README must stop crediting it to WP Rocket. |
| `data-no-optimize="1"` | LiteSpeed JS minify/combine/defer | [verified in vendor source `src/optimize.cls.php:880,1133,1167`] `if (!empty($attrs['data-no-optimize'])) continue;` in the JS optimizer loops. Not on the `pageopt` doc page read. Source line 883 also skips tags with `data-cfasync="false"`. |
| `data-noptimize="1"` | Autoptimize | [verified in vendor source `classes/autoptimizeScripts.php`] `'data-noptimize'` is in the `$dontmove` list (line 60); any tag containing it is neither merged nor moved. |
| `data-cfasync="false"` | Cloudflare Rocket Loader | [verified in vendor doc https://developers.cloudflare.com/speed/optimization/content/rocket-loader/ignore-javascripts/] page contains `data-cfasync="false"` as the opt-out. |

**Decision**: ship the five verified attributes and remove `data-no-minify`. The class docblock credits each attribute to the vendor above; docs list the same five.

## R3 - Vendor filters

| Filter | Vendor / feature | Status | Entry type |
|---|---|---|---|
| `rocket_delay_js_exclusions` | WP Rocket Delay JS | [verified in vendor source `inc/Engine/Optimization/DelayJS/HTML.php`, `wpm_apply_filters_typed( 'string[]', 'rocket_delay_js_exclusions', ... )`] | regex pattern matched against the whole tag; path string |
| `rocket_exclude_defer_js` | WP Rocket Load JavaScript deferred | [verified in vendor source `inc/Engine/Optimization/DeferJS/DeferJS.php`, `apply_filters( 'rocket_exclude_defer_js', ... )`; the doc at https://docs.wp-rocket.me/article/976-exclude-files-from-defer-js describes the same list] | pattern matched against `src` URL |
| `rocket_exclude_js` | WP Rocket Minify/Combine JS | [verified in vendor source `inc/Engine/Optimization/Minify/JS/AbstractJSOptimization.php`, `apply_filters( 'rocket_exclude_js', ... )`] | pattern matched against URL path |
| `litespeed_optm_js_exc` | LiteSpeed JS Excludes (minify/combine) | [verified in vendor doc https://docs.litespeedtech.com/lscache/lscwp/api/] | full or partial URI, no wildcards |
| `litespeed_optm_js_defer_exc` | LiteSpeed JS Deferred/Delayed Excludes | [verified in vendor doc https://docs.litespeedtech.com/lscache/lscwp/api/] | same |
| `litespeed_optm_gm_js_exc` | LiteSpeed Guest Mode JS Excludes | [verified in vendor doc https://docs.litespeedtech.com/lscache/lscwp/api/] | same |
| `autoptimize_filter_js_exclude` | Autoptimize JS exclusions | [verified in vendor source `classes/autoptimizeScripts.php:273`, `apply_filters( 'autoptimize_filter_js_exclude', $exclude_js, $this->content )`] | comma-separated string of substrings |
| `perfmatters_delay_js_exclusions` | Perfmatters Delay JS | [verified in vendor doc https://perfmatters.io/docs/filters/] | array of keywords |
| `perfmatters_defer_js_exclusions` | Perfmatters Defer JS | [verified in vendor doc https://perfmatters.io/docs/filters/] | array of keywords |
| `perfmatters_minify_js_exclusions` | Perfmatters Minify JS | [verified in vendor doc https://perfmatters.io/docs/filters/] | array of keywords |
| `sgo_js_minify_exclude` | SiteGround Speed Optimizer minify | [verified in vendor doc https://www.siteground.com/tutorials/wordpress/speed-optimizer/custom-filters] | array of script handles |
| `sgo_javascript_combine_exclude` | SiteGround combine | [verified in vendor doc, same page] | array of script handles |
| `sgo_js_async_exclude` | SiteGround async load | [verified in vendor doc, same page] | array of script handles |
| FlyingPress | Delay All JavaScript | [unverified, do not ship] The doc read documents only a settings keyword list ("checks the full `<script>` tag: src, id, and inline content"); no attribute or PHP filter is documented. Docs say: add `clicutcl` and `clicktrail-consent-mode` to the FlyingPress exclusion field. |

**Decision**: register the 13 verified filters; SiteGround receives handles, Autoptimize a CSV string, the rest paths or file names as in plan.md.

## R4 - Inline scripts

**Verified**: WP Rocket Delay JS rewrites inline scripts too (doc: "all JavaScript files and inline scripts"); `nowprocket` on an inline tag opts it out (doc example). `dynamic-lists.json` excludes `js-extra` by default, so the three `wp_localize_script` blocks are safe today. LiteSpeed documents `data-no-defer="1"` for "elements in HTML", not only external files.
**Decision**: the consent default snippet gets `nowprocket data-no-defer="1"`. Nothing else inline is ClickTrail-owned.

## R5 - Interaction events and the keyboard-only submit

**Verified in vendor doc** (WP Rocket, Technical notes): delayed scripts run on `keydown`, `keyup`, `mousedown`, `mouseup`, `mousemove`, `mouseover`, `mouseout`, `touchmove`, `touchstart`, `touchend`, `touchcancel`, `wheel`, `click`, `dblclick`, `input`, `onblur`, `onclick`, `oncontextmenu`, `ondblclick`, `onfocus`, `onmousedown`, `onmouseenter`, `onmouseleave`, `onmousemove`, `onmouseout`, `onmouseover`, `onmouseup`, `onmousewheel`, `onscroll`, `onsubmit`. A keyboard-only path (tab, type, Enter) triggers `keydown` before submit, so the script executes, but after the page's landing touch should have been recorded. Documentation wording for US4 follows from this.

## Open questions

- Does WP Rocket's minified-copy URL (`/wp-content/cache/min/1/wp-content/plugins/click-trail-handler/...`) still match `rocket_delay_js_exclusions` after minify rewrote the `src`? The chosen path string is relative for that reason; SC-004 with WP Rocket installed would confirm.
- Does Perfmatters match delay exclusions against inline content as well as `src`? Not stated on the filters page; the consent snippet relies on `nowprocket` and `data-no-defer` only.
- SC-004 ran on 2026-10-31 without an optimizer (`tools/qa/e2e/EVIDENCE-2026-10-31.md`, scenario 5): the served HTML of a page with consent mode, browser events and a GTM container id carries all four `clicutcl-*-js-js` src tags with the five attributes exactly once and the consent snippet with `nowprocket data-no-defer="1"` before the GTM bootstrap (#147). The runtime-unverified label stays on the optimizer behaviour itself: no optimizer was installed.
- The earlier note, kept for the optimizer part: the e2e pod has no optimizer installed. An optional second pod with WP Rocket Delay JS on is the only way to see `type="text/rocketlazyloadscript"` absent from ClickTrail tags.
