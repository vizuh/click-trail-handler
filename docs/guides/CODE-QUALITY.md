# Code Quality Notes

- **Audience**: maintainers, reviewers, and cleanup-focused contributors
- **Canonical for**: current maintenance posture, known dead paths, and cleanup hotspots
- **Update when**: legacy paths are removed, major cleanup lands, or quality risks materially change
- **Last verified against version**: `1.8.19`

This document summarizes the current quality posture of the repository and the main maintenance concerns worth watching.

## Current Strengths

- Settings sanitization is schema-based in the core option stores.
- Advanced tracking settings preserve hidden secrets safely for admin clients.
- Server-side delivery is separated into dispatcher, queue, adapters, and diagnostics concerns.
- Browser events, webhook intake, lifecycle updates, and purchase dispatch converge on one shared delivery path.
- The admin experience now presents one primary settings identity instead of exposing internal "v2" terminology to users.
- A lightweight feature registry now reduces hard-coded adapter and destination drift across admin, dispatcher, docs, and QA.
- The repository now includes a structural smoke harness for registry-backed capability checks.

## Current Maintenance Hotspots

## 1. ~~Compatibility admin logic inside `class-admin.php`~~ — resolved

The dead Settings API registrations and renderer callbacks have been removed from `includes/admin/class-admin.php` (2276 → 1383 lines). `register_settings()` (and its `admin_init` hook), `render_settings_page()` and everything only reachable from it (`get_settings_tabs()`, `get_settings_form_config()`, `get_settings_cards()`, `render_settings_status_bar()`, `render_status_pill()`, `render_settings_card()`, `render_settings_save_bar()`), and the field renderers `render_text_field()`, `render_select_field()`, `render_checkbox_field()`, `render_number_field()`, `render_gtm_text_field()` are gone — none had a live caller; `render_settings_page()` was never wired up as any menu page's callback.

The sanitization helpers that back the live unified-settings save path — `sanitize_settings()`, `sanitize_server_side_settings()`, `normalize_settings_input()`, the per-field `sanitize_*` helpers, and `get_attribution_settings_schema()`/`get_attribution_settings_defaults()` — were kept; they're called from `save_unified_admin_settings()` and the diagnostics import/export handlers independent of the removed Settings API scaffolding.

Treat the remainder of the file as runtime-critical; it now backs only the live screens (`render_settings_app_page()`, `logs_page()`, `diagnostics_page()`) and the unified settings save/load path.

## 2. ~~Internal naming that still reflects older architecture~~ — resolved

The option key `clicutcl_tracking_v2` is still the live storage contract (defined in `includes/tracking/class-settings.php` as `Tracking_Settings::OPTION`) and is intentionally preserved. The two AJAX handlers that exposed this name in action strings and nonce actions (`ajax_get_tracking_v2_settings`, `ajax_save_tracking_v2_settings`) have been removed, along with their `add_action` registrations and the `register_setting('clicutcl_tracking_v2')` no-op call.

The string `clicutcl_tracking_v2` no longer appears in any public-facing action name, nonce, or UI surface. It exists only as an internal option key in `class-settings.php` and `uninstall.php`, which is correct.

## 3. Documentation drift risk

The codebase has several distinct domains:

- browser capture
- consent
- canonical tracking
- queue and delivery
- WordPress admin

Because of that, admin or runtime refactors can easily leave docs stale unless the docs are updated in the same pass.

The repository no longer keeps a duplicate redirect layer under `docs/`, so stale links now tend to fail fast instead of silently landing on compatibility stubs.

## 4. Runtime test coverage is still lighter than ideal

The repository now exposes a lightweight smoke harness through `npm run smoke`, backed by `config/feature-registry.json`, `config/feature-test-matrix.json`, and `tools/qa/smoke.js`.

That improves breadth safety, but it is still not a full WordPress runtime or integration test suite. `tests/unit/RestAuthPermissionsTest.php` and `tests/unit/QueueRetryTest.php` (added in 1.8.18) now cover the REST `permission_callback` boundary (client/CRM/webhook token validation) and the queue's pure retry-backoff calculation, but the following areas remain especially sensitive to regressions:

- grouped admin save/load mapping
- form adapter behavior
- queue DB-backed paths (`Queue::process()`, `update_row_failure()`, `mark_row_failed()`, `requeue_failed()`) — all require a live `$wpdb`/WordPress test harness, which the current unit suite deliberately does not stand up
- WooCommerce browser events and milestone hooks
- adapter payload translation against real downstream endpoints

## 5. Repeat-visit attribution (1.10.5 status)

Repro: `node tools/qa/repeat-visit-scenarios.js [plugin-root]` replays visits through the real attribution script and prints what the stored payload, a decorated link and the Calendly stamp carry. Asserted coverage lives in `tools/qa/smoke.js`, `tools/qa/cmp-transition.test.js`, `tests/unit/ClickIdPrecedenceTest.php` and `tests/unit/CF7SubmissionOutcomeTest.php`; `tools/qa/e2e/` replays the full browser, form and Calendly webhook round trip on a throwaway local WordPress.

| Scenario | 1.10.4 | 1.10.5 |
|---|---|---|
| Google A, then Google B | link and stamp send A (frozen top-level copy) | B everywhere; `ft_gclid` keeps A |
| Google A, then Meta B | stamp sends `gclid=A` | stamp sends `fbclid=B` (D3 selection); link carries both IDs, per platform newest |
| Google A, then UTM-only visit | `gclid=A` next to the new UTMs | unchanged: D3 keeps the newest click until a newer one arrives |
| Google A, then direct visit | no new touch; A kept | unchanged |
| Consent withdrawn after a link was decorated | link kept the click ID | original URL restored; re-grant decorates again |
| Both `attribution` and legacy `ct_attribution` cookies | PHP read the legacy one | both runtimes read `attribution` first |
| Google A, then Meta B, then CF7 submit on that landing page | lead stored as Google (`lt_campaign` of the first visit, no `fbclid`) | lead stored as Meta with `fbclid` and selection `FB` (local end-to-end run) |
| Same visitor books through the decorated Calendly link (replayed webhook) | stamp `gclid=A` | `book_appointment` dispatched with `fbclid=B`, no `gclid` |

Still open (pre-existing, not changed by 1.10.5):

- **Medium. Stale last-touch fields.** `applyTouch('lt', ...)` writes only non-empty fields, so a later touch without `utm_term` keeps the previous touch's `lt_term` (Google A with `utm_term=kw`, then a newsletter visit, leaves `lt_term=kw` beside `lt_campaign=n1`). `clicktrail-js` overwrites every `lt_` field per touch; no parity ruling covers it yet because the parity fixtures are single-visit.
- **Medium. Link carries every platform's newest click.** After Google A then Meta B, a decorated link sends `utm_campaign=<meta campaign>` with both `gclid=A` and `fbclid=B`. D3 allows keeping both; a downstream reader that pairs `gclid` with the link's UTMs credits the wrong campaign. Readers should use `attribution_selected_click_id`.
- **Medium. Plain-name inputs mix touches.** `utm_*` hidden fields and the Gravity Forms `{clicutcl_utm_*}` merge tags read first touch, while `gclid` / `ct_gclid` and `{clicutcl_click_id}` read the latest click.
- **Low. Cross-domain token.** The token carries top-level click IDs only (no `lt_`, no selection), the receiving side merges fill-if-empty, and the PHP sign allowlist drops `rdt_cid`, `pin_cid`, `snap_cid`, `mc_cid`, `mc_eid`, `dclid`.
- **Low. Already-loaded embeds.** An inline Calendly iframe or popup that loaded before a withdrawal keeps the URL it loaded with; ClickTrail cannot recall what a loaded third-party frame received. Popups opened and widgets rendered after the withdrawal get the site's URL only (`cmp-transition.test.js`). A site edit to an inline widget's `data-url` after decoration is reset to the stored original on the next widget pass.
- **Low. Re-grant listeners.** A same-page re-grant re-runs install, so link-decoration handlers and MutationObservers are added again (handlers are idempotent; the submit listener is registered once).
- **Low, unreproduced. Cross-tab writes.** Both write paths re-read storage right before saving, which removes stale-snapshot writes, but a read-modify-write across two tabs is not atomic. No failing case is known.
- **Unverified.** A live Calendly booking (does Calendly keep the `salesforce_uuid` stamp end to end). `clicktrail-js` parity was run against this branch with the pin check bypassed in a throwaway worktree: 15 match, 10 ruled, 0 new findings, same as plugin `main`; `attribution_selected_click_id`/`_reason` now match the engine and only `click_id_history` (kept out of `getData()`) stays a ruled D3 difference. The runner still pins plugin `ead6682` and needs a deliberate re-pin to the merged commit.

## Static Compatibility Check (CI)

The repository runs two PHP static checks on every push and pull request:

- `php-lint.yml` — WordPress-Core/Extra/Docs standards via `composer phpcs`.
- `phpcompatibility.yml` — PHP and WordPress deprecated/removed API surface via `composer phpcompat` (uses `PHPCompatibilityWP`, `testVersion 8.1-`).

Run either locally before pushing:

```
composer phpcs        # coding standards
composer phpcompat    # PHP + WP deprecation surface
```

`phpcompat` flags deprecated and removed symbols against whatever WordPress version range the installed `phpcompatibility-wp` ruleset codifies — there is no per-WP `testVersion` flag; the ruleset itself is the version. Treat it as a static safety net during WP-version bumps (it catches removed functions that ship with the installed ruleset), not as a behavioral test. Pair it with a real WP runtime smoke test before publishing.

## Suggested Review Checklist for Future Changes

- Does the change affect the capability-based admin IA?
- Does it alter any of the five main option stores?
- Does it change REST auth or token behavior?
- Does it impact queue retry or diagnostics retention?
- Does it affect form adapters or WooCommerce attribution persistence?
- Does it require a feature-registry or smoke-matrix update?
- Do the GitHub docs and WordPress `readme.txt` still describe the current state correctly?
