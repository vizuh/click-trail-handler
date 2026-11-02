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

Re-verified 2026-11-02 against current vendor source or docs (#165). "Source" rows were read from the vendor repository or the WordPress.org plugin SVN trunk; "doc" rows from the vendor documentation page (Perfmatters and FlyingPress ship no public source).

| Filter | Vendor / feature | Status | Argument / return |
|---|---|---|---|
| `rocket_delay_js_exclusions` | WP Rocket Delay JS | [verified in source, https://github.com/wp-media/wp-rocket `develop`, `inc/Engine/Optimization/DelayJS/HTML.php:119`: `$this->excluded = wpm_apply_filters_typed( 'string[]', 'rocket_delay_js_exclusions', $this->excluded );`] | array of patterns matched against the whole tag; string[] return |
| `rocket_exclude_defer_js` | WP Rocket Load JavaScript deferred | [verified in source, `inc/Engine/Optimization/DeferJS/DeferJS.php:209`: `$exclude_defer_js = apply_filters( 'rocket_exclude_defer_js', $exclude_defer_js );`, docblock "An array of URLs for the JS files to be excluded"] | array |
| `rocket_exclude_js` | WP Rocket Minify/Combine JS | [verified in source, `inc/Engine/Optimization/Minify/JS/AbstractJSOptimization.php:69`: `$excluded_files = (array) apply_filters( 'rocket_exclude_js', $excluded_files );`] | array |
| `litespeed_optimize_js_excludes` | LiteSpeed JS Excludes (minify/combine) | [verified in source, https://plugins.svn.wordpress.org/litespeed-cache/trunk/ `src/optimize.cls.php:849`: `$excludes = apply_filters('litespeed_optimize_js_excludes', $this->conf(self::O_OPTM_JS_EXC));`; API doc https://docs.litespeedtech.com/lscache/lscwp/api/: "apply_filters( 'litespeed_optimize_js_excludes', array $list ); ... Return array ... Adds files to JS Excludes"] | array of URI fragments. **Corrected**: the plugin shipped `litespeed_optm_js_exc`, which appears nowhere in LiteSpeed trunk or its API doc (`grep -rn litespeed_optm_js_exc` returns nothing), so that hook was a no-op. |
| `litespeed_optm_js_defer_exc` | LiteSpeed JS Deferred/Delayed Excludes | [verified in source, `src/optimize.cls.php:109`: `$this->cfg_js_defer_exc = apply_filters('litespeed_optm_js_defer_exc', $this->conf(self::O_OPTM_JS_DEFER_EXC));`; same API doc] | array |
| `litespeed_optm_gm_js_exc` | LiteSpeed Guest Mode JS Excludes | [verified in source, `src/optimize.cls.php:100`: `apply_filters('litespeed_optm_gm_js_exc', $this->conf(self::O_OPTM_GM_JS_EXC))`; same API doc] | array |
| `autoptimize_filter_js_exclude` | Autoptimize JS exclusions | [verified in source, https://plugins.svn.wordpress.org/autoptimize/trunk/ `classes/autoptimizeScripts.php:298`: `$exclude_js = apply_filters( 'autoptimize_filter_js_exclude', $exclude_js, $this->content );`, where `$exclude_js = $options['js_exclude']`] | comma-separated string; second argument is the page HTML (ignored) |
| `perfmatters_delay_js_exclusions` | Perfmatters Delay JS | [verified in doc https://perfmatters.io/docs/filters/ section `perfmatters_delay_js_exclusions`: `add_filter('perfmatters_delay_js_exclusions', function($exclusions) { ... $exclusions[] = 'jquery.min.js'; ... return $exclusions; });`] | array of keyword strings, returned as array |
| `perfmatters_defer_js_exclusions` | Perfmatters Defer JS | [verified in doc, same page: `add_filter('perfmatters_defer_js_exclusions', function($exclusions) { ... $exclusions[] = 'hooks.min.js'; ... return $exclusions; });`] | same |
| `perfmatters_minify_js_exclusions` | Perfmatters Minify JS | [verified in doc, same page: `add_filter('perfmatters_minify_js_exclusions', function($exclusions) { ... $exclusions[] = 'example.js'; ... return $exclusions; });`] | same |
| `sgo_js_minify_exclude` | SiteGround Speed Optimizer minify | [verified in source, https://plugins.svn.wordpress.org/sg-cachepress/trunk/ `core/Minifier/Minifier.php:145`: `$excluded_scripts = apply_filters( 'sgo_js_minify_exclude', $this->js_ignore_list );` compared with `in_array( $handle, $excluded_scripts )`; doc https://www.siteground.com/tutorials/wordpress/speed-optimizer/custom-filters: `$exclude_list[] = 'script-handle';`] | array of script handles |
| `sgo_javascript_combine_exclude` | SiteGround combine | [verified in source, `core/Combinator/Js_Combinator.php:646`, `apply_filters( 'sgo_javascript_combine_exclude', array_merge( ... ) )` into `$excluded_handles`; same doc] | array of script handles |
| `sgo_js_async_exclude` | SiteGround async load | [verified in source, `core/Front_End_Optimization/Front_End_Optimization.php:226`: `$excluded_scripts = apply_filters( 'sgo_js_async_exclude', $this->blacklisted_async_scripts );` compared with `in_array( $handle, $excluded_scripts )`; same doc] | array of script handles |
| `flying_press_exclude_from_minify:js` | FlyingPress JS minification | [verified in doc https://docs.flyingpress.com/en/articles/11405981-exclude-files-from-javascript-minification: "you can exclude it using the `flying_press_exclude_from_minify:js` filter", example `add_filter('flying_press_exclude_from_minify:js', function ($exclude_keywords) { return [ 'slider.js', ... ]; });`, "partial and case-sensitive" match on the file URL] | array of keywords; the callback appends and returns the array. Minification only. |
| FlyingPress Delay / Defer | FlyingPress Delay All JavaScript | [unverified as filter, not hooked] https://docs.flyingpress.com/en/articles/11406701-delay-all-javascript documents only a settings keyword list ("FlyingPress checks the full `<script>` tag - src, id, and inline content ... any partial keyword"); the Developer Reference collection (https://docs.flyingpress.com/en/collections/12930853-developer-reference, 15 articles) lists purge, preload, cache, role, self-hosted-domain and the two minification filters, none for delay or defer; the plugin is not on WordPress.org SVN, so no source was readable. Docs say: add `clicutcl` and `clicktrail-consent-mode` to the FlyingPress exclusion field. |

Shape note: Perfmatters publishes no source; its doc pages show the callback signature and array return only. Whether Perfmatters matches the keywords against `src` or the whole tag is not stated; the entries are file names, which appear in the `src` either way.

**Decision**: register the 14 verified filters; SiteGround receives handles, Autoptimize a CSV string, WP Rocket and LiteSpeed install-folder paths (R3a), the rest file names. FlyingPress Delay and Defer stay documentation-only.

## R3a - Install folder (#166)

`exclude_paths()` builds `plugins/<basename( dirname( CLICUTCL_PLUGIN_MAIN_FILE ) )>/assets/js/clicutcl-*.js`, so a renamed or GitHub-zip folder (`click-trail-handler-main`) is excluded under its own name; the `click-trail-handler` literal is appended only when the real folder differs.

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
