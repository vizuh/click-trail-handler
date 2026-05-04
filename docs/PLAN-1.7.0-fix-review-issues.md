# ClickTrail 1.7.0 — Fix all 10 review issues

## Acceptance criteria (one checkable sentence)

ClickTrail 1.7.0 ships with all 10 review issues from the 1.6.0 review (I-1, I-2, M-1 through M-8) fixed; the 8-step verification gate at the bottom of this document passes; no public method signatures break; no entry-meta keys, hook names, filter names, or merge-tag syntax change; `Gravity_Forms_Adapter` drops below 400 lines via three extracted classes whose constructors share a single memoized `Attribution_Settings` instance.

---

## Open questions (3 — please answer before I execute)

These are independent branch points where the plan literally forks.

### Q1 — When no source signal is present, return `'Unknown'` (current) or `'Direct'` (your test #9 expects)?

The current PHP `resolve_channel_fallback()` returns `'Unknown'` for missing signals. Your verification test #9 says "No source signals at all → `Direct`". Choosing `'Direct'` is a **stored-data behavior change**: existing reports / automations keying on `ct_ft_channel == 'Unknown'` would silently break. Test #10 (internal-referrer-as-direct) carries the same implication.

| Option | Behavior change? | Aligns with test as written | Cost |
|---|---|---|---|
| A — keep `'Unknown'` | No | Test #9, #10 must be rewritten to expect `'Unknown'` | Test edit, no data change |
| B — switch to `'Direct'` | Yes | Test as written passes | One-line note in changelog about classification rename |

**My recommendation: A** (no breaking change in stored values; the label "Unknown" is honest about it being unclassified).

### Q2 — New `Gf_Channel_Resolver` referrer-aware (mirror JS) or PHP-fallback-only (current)?

The JS resolver classifies referrers (`gemini.google.com → Gemini`, etc.). The PHP `resolve_channel_fallback()` is referrer-blind — it only looks at the captured payload and gets called when `ft_channel` is empty. Your tests #5/#6/#7 are referrer-based, so the new PHP resolver has to accept a referrer string.

| Option | Scope | Risk |
|---|---|---|
| A — `resolve($payload, $referrer = '')`, mirrors JS rules | Larger; ~80 lines of referrer logic ported from JS | Two resolvers can drift; would want a comment in JS pointing to PHP and vice-versa |
| B — keep PHP referrer-blind, drop tests #5/#6/#7/#10 from M-7 | Smaller | Test coverage goal weaker |

**My recommendation: A** — port the JS rules. The duplication is the price of having a server-side fallback that's actually equivalent to the client-side path. Add a one-line comment in each pointing to the other to keep them in sync.

### Q3 — PHPUnit: add to composer require-dev, or use standalone phar?

`composer.json` currently has only `phpcs` / `wpcs` / `phpcompatibility` in require-dev. PHPUnit isn't installed.

| Option | Steps | Footprint |
|---|---|---|
| A — `composer require --dev phpunit/phpunit:^9.6` | One-time `composer install` on your machine | +5MB in `vendor/` (already excluded from ZIP) |
| B — Standalone `phpunit-9.phar` checked into `.tools/` | Self-contained, no composer step | `.tools/` already excluded from ZIP, +5MB on disk |

**My recommendation: A** — composer is the canonical way; you already have a composer setup and the ZIP exclusion of `vendor/` is correct.

### Note on prompt vs. file reality

Your prompt mentions `assets/js/clicutcl-tracking.js`. The actual runtime file is `assets/js/clicutcl-attribution.js` (handle `clicutcl-attribution-js`). I'll use the real path. No question — flagging only.

---

## Phases (commit-by-commit, in execution order)

Each phase is one commit with message format `clicktrail 1.7.0: <issue-id> <one-line>`.

### Phase 0 — PHPUnit bootstrap (no issue ID)

- Add `phpunit/phpunit:^9.6` to `composer.json` require-dev.
- Run `composer install` in repo root.
- Create `tests/unit/` directory.
- Create `tests/bootstrap.php` with stubs for `apply_filters()` (pass-through), `sanitize_text_field()` (trim + strip control chars), and `__()` / `esc_html()` if needed.
- Create `phpunit.xml.dist` at repo root pointing at `tests/unit/`.
- Add `tests/` to `phpcs.xml.dist` exclusion (don't lint test files).
- Update `tools/release/make-zip.ps1` to exclude `tests` directory.

Commit: `clicktrail 1.7.0: setup phpunit harness`

### Phase 1 — I-1: kill server-baked `adminQaMode`, set admin cookie

**File: `includes/class-clicutcl-core.php`**
- Remove the `'adminQaMode' => current_user_can('manage_options'),` line from `build_attribution_config()`.
- Add a new `set_admin_qa_cookie()` method hooked on `init` priority 1.
- Method body: if `is_user_logged_in() && current_user_can('manage_options')`, call `setcookie( 'clicutcl_admin_qa', '1', [ 'expires' => time() + 3600, 'path' => COOKIEPATH, 'secure' => is_ssl(), 'httponly' => false, 'samesite' => 'Lax' ] )`. The `is_user_logged_in()` gate ensures the `Set-Cookie` header is only emitted in uncacheable responses.
- Register on `init` so the cookie lands in headers before any output.

**File: `assets/js/clicutcl-attribution.js`**
- Replace `if (CONFIG.adminQaMode) { ... }` (two occurrences in `getData` and `saveData`) with a call to a new `isAdminQaMode()` helper.
- Helper reads `document.cookie`, looks for `clicutcl_admin_qa=1`. Cached at module scope on first call so subsequent calls are O(1).
- Both read and write paths use the same helper — symmetric.

Commit: `clicktrail 1.7.0: I-1 move adminQaMode from localized config to short-TTL cookie`

### Phase 2 — M-6: extract three classes

**Order matters: extract before changing the extracted code (I-2/M-1 land in the new file).**

**New file: `includes/integrations/forms/class-gf-channel-resolver.php`**
- Class `Gf_Channel_Resolver`.
- Public: `resolve(array $payload, string $referrer = ''): string` — single entry point.
- Private helpers: `host_matches_domain()`, `host_matches_label()`, `normalize_hostname()`, `parse_url_safely()` — direct ports of JS equivalents.
- Constants for paid click ID priority list, AI assistant domains, search engines, social platforms, email platform sources.
- No GF dependency. No WP dependency beyond `sanitize_text_field()` and `apply_filters()` (which the test bootstrap stubs).
- Per Q1/Q2: pending answers, default to recommendations (port JS rules; return `'Unknown'` for null case).

**New file: `includes/integrations/forms/class-gf-minification-protector.php`**
- Class `Gf_Minification_Protector`.
- Constructor: `__construct()` — registers `script_loader_tag` filter on construction.
- Public: `add_attrs(string $tag, string $handle): string` — the existing logic, but with I-2 + M-1 fixes (next phase).
- Constant `EXCLUSION_ATTRS` holding the corrected attribute set.
- Constant `CT_HANDLES` array.

**New file: `includes/integrations/forms/class-gf-merge-tags.php`**
- Class `Gf_Merge_Tags`.
- Constructor: `__construct(Gravity_Forms_Adapter $adapter)` — needs the adapter for the `is_tracking_enabled_for_form()` check, registers `gform_custom_merge_tags` and `gform_replace_merge_tags` hooks.
- Public: `register($merge_tags, $form_id, $fields, $element_id)`, `replace($text, $form, $entry, $url_encode, $esc_html, $nl2br, $format)`.
- Private: `format_value()` (was `format_merge_tag_value`).
- Constants for `MERGE_TAGS` and `CLICK_ID_META_KEYS` move here.

**File: `includes/integrations/forms/class-gravity-forms-adapter.php`**
- Add `private ?Attribution_Settings $settings_cache = null;` and a private `get_settings(): Attribution_Settings` lazy getter (M-2).
- Add `private ?Gf_Channel_Resolver $channel_resolver = null;` and a getter.
- In `register_hooks()`, instantiate `new Gf_Minification_Protector()` (self-registers) and `new Gf_Merge_Tags($this)` (self-registers).
- Remove the now-extracted methods: `resolve_channel_fallback()`, `add_minification_exclusion_attrs()`, `register_merge_tags()`, `replace_merge_tags()`, `format_merge_tag_value()`.
- Remove the now-unused `MERGE_TAGS` and `CLICK_ID_META_KEYS` class constants.
- Remove the `script_loader_tag` and merge-tag `add_filter()` lines from `register_hooks()`.
- **Backward-compat shim:** keep `public function resolve_channel_fallback(array $payload): string` as a thin delegator to `$this->channel_resolver->resolve($payload, '')` so any external code calling it still works.
- `is_tracking_enabled_for_form()` reuses memoized `$this->get_settings()` instead of `new Attribution_Settings()`.
- `add_form_settings_tab()` reuses memoized `$this->get_settings()`.

**File: `includes/integrations/class-form-integration-manager.php`**
- Add `'CLICUTCL\Integrations\Forms\Gf_Channel_Resolver' => 'forms/class-gf-channel-resolver.php',` etc. to the `$potential_adapters` map IF the autoloader doesn't pick them up. Verify first by checking how the existing autoloader resolves namespaces.

Commit: `clicktrail 1.7.0: M-6 extract channel resolver, merge tags, minification protector`

### Phase 3 — I-2 + M-1: fix minification attributes (in the new file)

**File: `includes/integrations/forms/class-gf-minification-protector.php`** (created in Phase 2)
- Replace the broken attribute set with: `data-no-optimize="1" data-noptimize="1" data-cfasync="false" data-no-defer="1" data-no-minify="1"`.
- Replace `str_replace(' src=', ...)` with `preg_replace('/(<script\b)/', '$1 ' . self::EXCLUSION_ATTRS, $tag, 1)`. This injects the attrs immediately after the opening `<script` token, robust to leading/trailing whitespace and any attribute order.
- Add a guard: if `preg_replace` returns null (regex failure), return `$tag` unchanged.

Commit: `clicktrail 1.7.0: I-2 M-1 correct minification attrs and use regex injection`

### Phase 4 — M-7: ChannelResolverTest

**New file: `tests/unit/ChannelResolverTest.php`**

Cases (10):
1. `gclid='abc'`, no medium → `Google Ads`
2. `fbclid='abc'`, `medium='cpc'` → `Facebook Ads`
3. `fbclid='abc'`, no paid medium → NOT `Facebook Ads` (asserts `assertNotEquals`)
4. `msclkid='abc'` → `Microsoft Ads`
5. Empty payload, referrer `https://gemini.google.com/foo` → `Gemini`
6. Empty payload, referrer `https://www.google.com/search?q=x` → `Google Organic`
7. Empty payload, referrer `https://chatgpt.com/c/abc` → `ChatGPT`
8. `gclid='abc'`, referrer `https://www.google.com/search?q=x` → `Google Ads` (click ID wins)
9. Empty payload, no referrer → per Q1, either `Unknown` (recommended) or `Direct`
10. Empty payload, referrer matching current site host (stub via `WP_HOME` constant in bootstrap) → per Q1; recommended: `Unknown`

If Q1 = A (Unknown): tests #9 and #10 expect `'Unknown'`.
If Q1 = B (Direct): tests #9 and #10 expect `'Direct'`, and `Gf_Channel_Resolver::resolve()` returns `'Direct'` in those cases.

Bootstrap stubs: `apply_filters` (pass-through), `sanitize_text_field` (trim + control-char strip).

Commit: `clicktrail 1.7.0: M-7 unit tests for channel resolver`

### Phase 5 — M-2 / M-3 / M-4 / M-5 / M-8: minor touches and doc updates

**File: `includes/integrations/forms/class-gravity-forms-adapter.php`**
- M-3: one-line comment above the `0 !== $form_id && !$this->is_tracking_enabled_for_form(...)` checks in `populate_fields_dynamic` and `replace_merge_tags` (after `replace_merge_tags` moves to `Gf_Merge_Tags`, the comment goes there). Comment text: `// Fail-open when form context is unavailable: continue with default behavior rather than break the integration.`
- M-8: comment above the `gform_pre_form_settings_save` `add_filter` line: `// GF 2.5+ passes only $form to this filter; the single-arg signature is intentional. (GF 2.4 passed ($form, $form_id) — extra args are ignored.)`

**File: `docs/reference/HOOKS-REFERENCE.md`**
- M-4: paragraph noting `register_entry_meta` registers `ct_*` keys for ALL forms regardless of per-form toggle, and that values are gated at `on_submission` time. Place under the Gravity Forms section.
- M-5: paragraph stating channel labels (`Google Ads`, `Microsoft Ads`, etc.) are stored as English data values for cross-locale reporting consistency, NOT UI strings, and should not be wrapped with `__()`. Place near the `clicutcl_gf_channel_label` filter doc.
- Bump "Last verified against version" from `1.6.0` to `1.7.0`.

Commit: `clicktrail 1.7.0: M-2 M-3 M-4 M-5 M-8 minor touches and doc clarifications`

### Phase 6 — Version bump and ZIP

**Files:**
- `clicutcl.php`: `Version: 1.6.0` → `Version: 1.7.0`, `define('CLICUTCL_VERSION', '1.6.0')` → `'1.7.0'`.
- `readme.txt`: `Stable tag: 1.6.0` → `1.7.0`. Add `= 1.7.0 =` changelog entry summarizing the fixes (all behind-the-scenes; no user-visible feature changes; corrects minification attribute names; admin QA mode now uses cookie instead of localized config so cache plugins can't leak it). Add `= 1.7.0 =` Upgrade Notice. Update "What is new" section if appropriate.
- `readme.txt` minification claim: rewrite the 1.6.0 line to drop "WP Rocket" if the corrected attrs don't hit it, OR keep WP Rocket and accurately reflect what the new attrs do. Per Phase 3, the new set includes `data-no-defer="1"` (WP Rocket delay-JS) and `data-no-minify="1"` (WP Rocket minify exclusion), so the WP Rocket claim is justified. Just need to drop the broken `data-wprocket-exclude` mention if it's anywhere in the docs.

**Build:** Run `npm run make-zip`. Confirm output at `dist/click-trail-handler-1.7.0.zip`, size still ~330KB ± 50KB.

Commit: `clicktrail 1.7.0: bump version and rebuild dist`

---

## Verification (8 gates — all must pass)

Mirror of your spec, run at the end and reported in full.

1. `pwsh -NoProfile -Command ".tools\php-8.3.29\php.exe vendor\bin\phpcs --standard=phpcs.xml.dist"` → no new violations vs. 1.6.0 baseline.
2. `pwsh -NoProfile -Command ".tools\php-8.3.29\php.exe vendor\bin\phpunit tests\unit\ChannelResolverTest.php"` → 10/10 green.
3. `(Get-Content includes\integrations\forms\class-gravity-forms-adapter.php).Count` → returns < 400.
4. `Select-String -Path includes\class-clicutcl-core.php -Pattern "wp_localize_script.*adminQaMode"` → returns 0 matches.
5. `Select-String -Path includes\integrations\forms\class-gf-minification-protector.php -Pattern "data-wprocket-exclude|data-litespeed-no-optimize"` → returns 0 matches.
6. `Select-String -Path includes\integrations\forms\class-gf-minification-protector.php -Pattern "data-noptimize|data-no-defer|data-no-minify"` → returns ≥ 3 matches.
7. PHP syntax check: `Get-ChildItem includes,tests -Recurse -Filter *.php | ForEach-Object { .tools\php-8.3.29\php.exe -l $_.FullName }` → all `No syntax errors detected`.
8. Smoke install: load the new ZIP into a clean WP test site, render a GF form (or use existing if available), submit, confirm entry has `ct_ft_channel`, confirm no fatals in `wp-content/debug.log`. **This step requires manual confirmation by you.**

If any of 1–7 fail, I stop and report. Step 8 is yours to confirm post-build.

---

## Out of scope (will not touch)

- Any file not listed in the Files section of your prompt.
- Comment cleanup, formatting fixes, or naming changes in untouched code.
- The `Attribution_Settings` class beyond memoizing the instance via getter.
- The other form adapters (CF7, Fluent, etc.).
- The events JS file or any other JS beyond `clicutcl-attribution.js`.
- The 1.6.0 ZIP (left in `dist/` as-is).

---

## Rollback plan

Each phase is one commit. If a phase fails verification, `git revert <hash>` of that commit restores the previous state. The 1.6.0 ZIP remains in `dist/` and on disk untouched throughout.
