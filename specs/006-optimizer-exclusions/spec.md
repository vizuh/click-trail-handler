# Feature Specification: Optimizer Exclusions For ClickTrail Scripts

**Feature Branch**: `fix/optimizer-exclusions`

**Created**: 2026-10-31

**Status**: Draft, not started. Every claim below is source-present / runtime-unverified until SC-004 runs on a throwaway WordPress.

**Input**: GitHub issue [#153](https://github.com/vizuh/click-trail-handler/issues/153). The exclusion attributes that keep cache, minify and delay-JS plugins away from ClickTrail scripts are only added when Gravity Forms is active, cover three of the four handles, and use an attribute set that WP Rocket does not read. `README.en.md:84` and `readme.txt:81` claim site-wide coverage for Autoptimize, Rocket Loader, WP Rocket and LiteSpeed. Related: [#152](https://github.com/vizuh/click-trail-handler/issues/152) cache-safe hidden fields, [#149](https://github.com/vizuh/click-trail-handler/issues/149) doc drift, competitor review in [../004-submit-time-refresh-parity/research.md](../004-submit-time-refresh-parity/research.md).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Every ClickTrail script tag is excluded on every site (Priority: P1)

A site runs Contact Form 7 (no Gravity Forms) behind WP Rocket with Delay JavaScript execution on. Today `Gf_Minification_Protector` is constructed only inside `Gravity_Forms_Adapter` (`includes/integrations/forms/class-gravity-forms-adapter.php:104`), so no ClickTrail tag carries any exclusion attribute, every ClickTrail script is rewritten to `type="text/rocketlazyloadscript"`, and attribution runs only after the first interaction. A visitor who lands and leaves, or whose first interaction is the submit itself, produces a lead without attribution.

**Why this priority**: the attribution script must run on page load to record the landing touch; the readme already promises this works.

**Independent Test**: PHPUnit renders a tag for each of the four handles through the registered filter with Gravity Forms absent and asserts the attribute set.

**Acceptance Scenarios**:

1. **Given** no form plugin is active, **When** `clicutcl-consent-bridge-js`, `clicutcl-attribution-js`, `clicutcl-consent-js` and `clicutcl-events-js` are printed, **Then** each tag carries the full exclusion set.
2. **Given** Gravity Forms is active, **Then** the same tags carry the same set exactly once (no duplicate attributes from a second registration).
3. **Given** NitroPack is active, **Then** `data-nitropack-exclude="true"` is still present alongside the set.

---

### User Story 2 - The attribute set matches what each optimizer reads (Priority: P1)

The attribute set is `nowprocket data-no-optimize="1" data-no-defer="1" data-noptimize="1" data-cfasync="false"`. Each maps to a vendor-documented or vendor-source opt-out (research.md R2). The current set omits `nowprocket`, the only attribute WP Rocket Delay JS honours; `data-no-defer` is a LiteSpeed attribute, not WP Rocket's as the class docblock claims; `data-no-minify="1"`, shipped since 1.7.0, has no reader in any vendor source or doc read and is dropped.

**Independent Test**: PHPUnit asserts each attribute appears exactly once per tag; research.md records the vendor source for each.

**Acceptance Scenarios**:

1. **Given** a rendered ClickTrail tag, **Then** it contains `nowprocket` as a bare attribute.
2. **Given** a theme or another plugin already added one of the attributes to the tag, **Then** the attribute is not added a second time.
3. **Given** `script_loader_tag` runs twice on the same string (plugins that re-apply the filter), **Then** the output is unchanged on the second pass.

---

### User Story 3 - Filter-based exclusions where the vendor documents a filter (Priority: P2)

Some optimizers decide exclusions from a PHP list, not from tag attributes, or apply attribute checks only in some modes. ClickTrail registers its script URLs (or handles, where the vendor keys on handles) on every vendor filter verified in research.md R3. Unverified filters are not shipped.

**Independent Test**: PHPUnit calls each callback with an empty list and asserts the returned entries.

**Acceptance Scenarios**:

1. **Given** WP Rocket, **When** `rocket_delay_js_exclusions`, `rocket_exclude_defer_js` and `rocket_exclude_js` run, **Then** each list gains the four script paths.
2. **Given** LiteSpeed Cache, **When** `litespeed_optm_js_exc`, `litespeed_optm_js_defer_exc` and `litespeed_optm_gm_js_exc` run, **Then** each list gains the four paths.
3. **Given** Autoptimize, **When** `autoptimize_filter_js_exclude` runs, **Then** the comma-separated string gains the four file names.
4. **Given** Perfmatters, **When** `perfmatters_delay_js_exclusions`, `perfmatters_defer_js_exclusions` and `perfmatters_minify_js_exclusions` run, **Then** each list gains the four file names.
5. **Given** SiteGround Speed Optimizer, **When** `sgo_js_minify_exclude`, `sgo_javascript_combine_exclude` and `sgo_js_async_exclude` run, **Then** each list gains the four handles.
6. **Given** none of these plugins is active, **Then** the callbacks are registered anyway and never run (WordPress ignores filters nobody applies).

---

### User Story 4 - Docs state what is covered and how to check it (Priority: P2)

`README.en.md:84`, `readme.txt:81` and the playbook state the exact attribute set, the filters, the vendors they target, and the verification step: open the page source, confirm each `clicutcl-*.js` tag carries the attributes, and confirm `window.ClickTrail` is defined before submitting. The docs also say that Delay JS runs scripts on the first interaction (WP Rocket lists `keydown`, `mousemove`, `touchstart`, `scroll`, `click`, `input`, `onsubmit`), so a keyboard-only submit still triggers delayed scripts but too late for the landing touch, and that FlyingPress has no documented attribute or filter, only a settings keyword.

**Independent Test**: grep the three files for the attribute string and the `window.ClickTrail` check.

**Acceptance Scenarios**:

1. **Given** the docs, **Then** no doc claims an exclusion that research.md marks unverified.
2. **Given** the readme caching FAQ (`readme.txt:160`), **Then** it names the attributes and the `window.ClickTrail` check.

---

### Edge Cases

- Consent mode disabled and attribution disabled: `clicutcl-consent-bridge-js` is not enqueued (`includes/class-clicutcl-core.php:227-239`). The filters run only for handles that print; no tag, no attribute, no error.
- `clicutcl-events-js` is enqueued only when `clicutcl_should_load_events_js` is true (`class-clicutcl-core.php:226-306`). Same rule.
- `script_loader_tag` receives the whole string WordPress prints for the handle, including `wp_add_inline_script` before/after tags (`WP_Scripts::do_item`, core trunk). The current regex rewrites the first `<script` token, which would be the inline `-js-before` tag if one existed. ClickTrail uses `wp_localize_script` (printed separately as `-js-extra`), so no handle has a before tag today; the design still targets the `src` tag by its `id` instead of position.
- WordPress 6.5+ builds the `src` tag through `wp_get_script_tag()` (`WP_Scripts::do_item`, `class-wp-scripts.php:429` on 6.5 and 6.7), which applies `wp_script_attributes` with `id = "{handle}-js"`, then applies `script_loader_tag` to the full string. Both `defer`-strategy handles (`clicutcl-attribution-js`, `clicutcl-events-js`) and blocking handles go through the same path. The plugin requires WordPress 6.5 (`readme.txt:6`).
- Attribute already present from a theme filter at an earlier priority: a keyed attribute array cannot duplicate; the string path checks for the attribute name before inserting.
- The inline consent default snippet `<script id="clicktrail-consent-mode">` (`includes/Modules/consent-mode/class-consent-mode.php:95-101`) is an inline script WP Rocket Delay JS rewrites like any other (R4). It needs `nowprocket` and `data-no-defer="1"` as well, otherwise `gtag('consent','default', ...)` runs after the first interaction.
- WP Rocket excludes inline scripts whose id contains `js-extra` by default (`dynamic-lists.json`, `delay_js_exclusions`), so `clicutcl_config`, `ctConsentBridgeConfig` and `clicutclEventsConfig` are safe without changes. This is a vendor default, not a contract; the filter list in US3 does not depend on it.
- WP Rocket Defer JS skips tags that already carry `defer` or `async` (`DeferJS::defer_js`), so only the two blocking handles are affected by `rocket_exclude_defer_js`; the filter still lists all four.
- WP Rocket minify matches patterns against the URL path only; delay JS matches against the full tag; defer matches against the `src` URL. One path string `plugins/click-trail-handler/assets/js/clicutcl-attribution.js` satisfies all three.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The exclusion attributes MUST be added to all four ClickTrail front-end handles on every site, registered from core bootstrap next to `NitroPack_Compat::register()` (`includes/class-clicutcl-core.php:178`), not from the Gravity Forms adapter.
- **FR-002**: The attribute set MUST be exactly `nowprocket`, `data-no-optimize="1"`, `data-no-defer="1"`, `data-noptimize="1"`, `data-cfasync="false"`. `data-no-minify="1"` MUST be removed. `data-nitropack-exclude="true"` stays owned by `NitroPack_Compat`.
- **FR-003**: Each attribute MUST appear at most once per tag, regardless of how many times the filter runs or whether another filter added it first.
- **FR-004**: The class MUST be renamed without the `Gf_` prefix and moved out of `includes/integrations/forms/`; `Gravity_Forms_Adapter` MUST no longer instantiate it; its docblock MUST describe the vendor mapping from research.md R2.
- **FR-005**: The plugin MUST register callbacks on every vendor filter marked verified in research.md R3 and MUST NOT register any marked unverified.
- **FR-006**: The inline consent default snippet MUST carry `nowprocket data-no-defer="1"` (R4).
- **FR-007**: `README.en.md:84`, `readme.txt:81` and the caching FAQ at `readme.txt:160`, `docs/guides/IMPLEMENTATION-PLAYBOOK.md` (caching guidance near line 45), `docs/reference/INTEGRATIONS.md` (Cache and visitor boundary, line 232), `changelog.txt` and `docs/architecture/CODE-MAP.md` (integrations list, lines 92-94; it does not list the protector today) MUST describe the shipped set and the verification steps from US4.
- **FR-008**: Unit tests MUST cover US1 to US3; the throwaway WordPress run (SC-004) MUST be recorded before the docs drop the runtime-unverified label.

### Key Entities

- **Exclusion attribute set**: the five attributes in FR-002, applied to the `src` tag of each ClickTrail handle. `data-nitropack-exclude` is separate and only present when NitroPack is active (`class-nitropack-compat.php:42-46`).
- **Vendor filter list**: the per-vendor list entries (URL path, file name or handle) registered through the filters in R3.
- **ClickTrail handles**: `clicutcl-consent-bridge-js`, `clicutcl-attribution-js`, `clicutcl-consent-js`, `clicutcl-events-js` (`class-clicutcl-core.php:230-306`).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: PHPUnit: 4 handles x 5 attributes, each present exactly once, with and without Gravity Forms, after one and after two filter passes.
- **SC-002**: PHPUnit: every registered vendor callback returns the input list plus exactly four ClickTrail entries.
- **SC-003**: PHPUnit: a non-ClickTrail handle is returned byte-identical.
- **SC-004**: Throwaway WordPress (`tools/qa/e2e`) with consent mode and browser event collection enabled so that all four handles are enqueued (`class-clicutcl-core.php:257-295` enqueues the consent and events scripts conditionally): the HTML of the landing page shows all four tags with the five attributes and the consent snippet with `nowprocket`; the run is recorded in research.md and the docs drop runtime-unverified only then. A run with WP Rocket Delay JS enabled on the same pod is a separate, optional proof.

## Assumptions

- Vendor behaviour is taken from the documents and source files listed in research.md as read on 2026-10-31; vendors can change parsing without notice.
- No setting is added; sites that want fewer exclusions use the vendors' own settings.
- FlyingPress exposes no attribute or filter in its public docs (R3); it stays documentation-only.
- Rocket Loader honours `data-cfasync="false"` on the tag (Cloudflare docs, R2); no Cloudflare API work is in scope.
