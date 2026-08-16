# ClickTrail — Security Hardening and Repo Cleanup
## Plan v1 · 2026-08-16

A manual security review of the plugin was completed 2026-08-16, scoped to `clicutcl.php`,
`uninstall.php`, all of `includes/`, `assets/js/*`, and `config/`. Excluded as non-runtime:
`WP/` (WordPress.org SVN tree, used as evidence), `dist/` (release artifacts, used as evidence),
`.claude/` + `.claude-flow/` (removed in this release), `vendor/` (dev-only Composer deps). All
findings below are verified with direct evidence (WordPress.org SVN tags, built release zips,
live code paths) — this document records the findings and what shipped, it is not itself a
re-audit.

This release (1.8.14) also restarts the documented "3-version buffer" between GitHub and
WordPress.org: at review time, local `main` and GitHub `origin/main` were found byte-identical
to the WordPress.org `Stable tag: 1.8.13` SVN tag, i.e. zero buffer, even though `RELEASING.md`
documents that GitHub should ship immediately while WP.org stays 3 releases behind after 3-5
days of staging. 1.8.14 ships to GitHub only; WordPress.org's Stable tag stays at 1.8.13 until
this version and at least two more have shipped and soaked on GitHub.

---

## Acceptance criteria (done when all pass)

1. `config/feature-registry.json` is present in the built 1.8.14 release zip (verified by
   unzipping and checking for the file).
2. The Export Backup admin control carries an explicit unmasked-secrets warning, and
   `docs/guides/SECURITY-PRIVACY.md` documents the same behavior.
3. `docs/reference/REST-API.md` no longer claims the removed legacy log controller "still
   exists in the repository".
4. Claude Flow is removed from local disk with zero git history impact (nothing tracked was
   ever touched).
5. GitHub Spec Kit is bootstrapped (`.claude/skills/speckit-*`, `.specify/`), `CLAUDE.md`'s
   Claude Flow Notes section is updated, and both new directories are correctly tracked in git
   and excluded from the WP.org build via `.distignore`.
6. `clicutcl.php` version header and `CLICUTCL_VERSION` are bumped to 1.8.14; `readme.txt`
   `Stable tag` stays at 1.8.13; `changelog.txt` and `readme.txt`'s changelog both carry a new
   1.8.14 entry.
7. `dist/releases/1.8.14/click-trail-handler-1.8.14.zip` is built and confirmed to contain
   `config/feature-registry.json`.

---

## Findings

### [Medium] `config/feature-registry.json` is missing from every shipped release

`Feature_Registry::all()` (`includes/support/class-feature-registry.php`) reads
`config/feature-registry.json` at RUNTIME to resolve delivery-adapter classes/labels and the
destination toggle list. `.distignore` excluded the whole `config/` directory (added in commit
`48e6d17`, grouped under a generic "Project config" comment alongside genuine build tooling
like `composer.json`), sweeping up this runtime-required data file too.

Verified directly: `dist/click-trail-handler-1.8.13.zip` (130 files, zero `config/` entries),
`dist/releases/{1.8.10,1.8.11,1.8.12}/*.zip` (same), and — most importantly — the live
WordPress.org SVN tag `WP/click-trail-handler/tags/1.8.13/`, which
`WP/click-trail-handler/trunk/readme.txt`'s `Stable tag: 1.8.13` confirms is the version
WordPress.org actually serves to real installs today. All are missing `config/`.

Effect: `Feature_Registry::adapter_class()` returns `''` for every adapter key in production,
so `Dispatcher::build_adapter()` (`includes/server-side/class-dispatcher.php`) silently falls
back to `Generic_Collector_Adapter` for every destination selection (nothing fatals — there's a
hardcoded allowlist fallback for the adapter *keys*, but not for the *classes*). Separately,
`includes/admin/class-admin.php` builds the "Delivery adapter" dropdown and "Destinations"
toggle list directly from the same empty registry, so those admin UI sections render empty on
any WP.org-installed site. Fixed in 1.8.14 by removing the `config/` line from `.distignore`.

### [Low] Settings-backup export writes plaintext secrets with no operator warning

`ajax_export_settings_backup()` (`includes/admin/traits/trait-admin-diagnostics-ajax.php`) uses
`Tracking_Settings::get()` (decrypted/unmasked) rather than `get_for_admin()` (masked), so the
downloaded JSON backup contains the Calendly/HubSpot/Typeform webhook secrets and the CRM
lifecycle token in cleartext, even when `encrypt_secrets_at_rest` is on. The action is correctly
capability+nonce gated, so this is not an access-control bug — but there was no on-screen
warning telling the admin the file is a credentials file. Fixed in 1.8.14 with an explicit
warning string near the Export control and a documentation note in
`docs/guides/SECURITY-PRIVACY.md`. The underlying masking *behavior* is unchanged (that's a
product decision, not made here).

### [Low / hardening idea — NOT fixed in 1.8.14, deferred] HubSpot/Typeform webhook replay
protection has no timestamp freshness bound

`Webhook_Auth::verify_request()` (`includes/tracking/class-webhook-auth.php`) uses each
provider's native signature scheme; HubSpot v1 and Typeform's HMAC don't include a timestamp in
the signed material (unlike the Calendly/custom branch, which does). Replay defense relies
solely on a cache/transient claim bounded by `webhook_replay_window` (60-3600s). This mirrors
the providers' own native formats, not a ClickTrail-introduced flaw, and is a hardening
opportunity rather than an active vulnerability. Tracked as follow-up work, not in this release.

### [Informational — NOT fixed in 1.8.14, deferred] Non-atomic per-IP rate limiter

`check_rate_limit()` (`includes/api/traits/trait-tracking-controller-security.php`) does a
read-then-write on a transient (`get_transient` then `set_transient($hit+1)`), which is not
atomic — concurrent requests from the same IP in the same window can both pass, letting a burst
slightly exceed the configured soft limit. `Webhook_Auth::verify_request()` one file away
already demonstrates the correct atomic pattern (`wp_cache_add()` claim); reuse it here for
consistency. Low impact (soft abuse control layered under other limits, not an auth boundary).
Tracked as follow-up work, not in this release.

### [Informational — NOT fixed in 1.8.14, deferred] No per-field length cap on the classic
attribution capture path

`Core\Attribution_Provider::sanitize()` applies `sanitize_text_field()` to every UTM/click-ID
field but nothing bounds length before persistence into the `ct_attribution` cookie, session
storage, and WooCommerce order meta — unlike the `/attribution-token/sign` REST path, which caps
each field at 128 chars. Marginal storage-hygiene concern; bounded in practice by browser cookie
size limits and typical request-line length limits. Tracked as follow-up work, not in this
release.

### [Docs drift, non-security] `docs/reference/REST-API.md` referenced a removed file

The doc stated `includes/api/class-log-controller.php` "still exists in the repository" as a
disabled legacy route. The file does not exist anywhere in the current tree. Fixed in 1.8.14.

---

## Shipped in 1.8.14

- **`.distignore` packaging fix** (finding: Medium `config/` exclusion). Removed the `config/`
  line so `config/feature-registry.json` ships again. `composer.json`, `composer.lock`,
  `vendor/`, `phpcs.xml.dist`, and `phpunit.xml.dist` remain excluded (genuinely dev-only).
- **Docs-drift fix** in `docs/reference/REST-API.md`: the Legacy API Status section now states
  the legacy v1 log controller has been removed from the codebase entirely, and `clicutcl/v2`
  via `Tracking_Controller` is the only registered REST surface. "Last verified against
  version" bumped to `1.8.14`.
- **Backup-export secret warning** (finding: Low, no operator warning). Added an explicit
  warning sentence next to the "Export Backup" control in
  `includes/admin/traits/trait-admin-pages.php` (also corrected an inaccurate "masked secrets"
  claim in the existing card description), and a documentation paragraph in the Secret Storage
  section of `docs/guides/SECURITY-PRIVACY.md`. `ajax_export_settings_backup()`'s masking logic
  itself is unchanged — out of scope for this release.

---

## Repo hygiene (not a security fix)

- **Claude Flow removal**: `.claude`, `.claude-flow`, `.mcp.json` were confirmed to have zero
  tracked files (`git ls-files` returned nothing for all three) — fully covered by
  `.gitignore` and never committed or pushed. Removed from local disk only; `git status` showed
  no change from this step.
- **GitHub Spec Kit bootstrap**: ran `specify init --here --integration claude --script sh
  --force` (specify CLI v0.10.2), matching the flags already used across sibling HugoOS
  projects (see `apointoo-wp-capture/.specify/init-options.json`). This installed
  `.claude/skills/speckit-*` and `.specify/` (templates, scripts, workflow registry). Spec-kit
  appended a `<!-- SPECKIT START --> ... <!-- SPECKIT END -->` marker block to `CLAUDE.md`
  without touching the hand-written Claude Flow Notes section rewritten in this same release —
  no manual restoration was needed.
  - Both `.claude/skills/speckit-*` and `.specify/` are tracked in git (mirroring every sibling
    HugoOS project with spec-kit installed) and excluded from the WordPress.org release via two
    new `.distignore` lines (`.claude` already existed; added `.specify`, and removed the now-
    stale `.claude-flow` line since that directory no longer exists).
  - `.gitignore` previously blanket-ignored `.claude/` and `.claude-flow/`; since spec-kit's
    `.claude/skills/*` output is meant to be committed (confirmed against sibling repos), those
    two stale ignore rules were removed and replaced with `.specify/feature.json` (the spec-kit
    ephemeral active-feature-directory file), mirroring `apointoo-wp-capture/.gitignore`.
  - `AGENTS.md` is gitignored/untracked in this repository and was not present in this git
    worktree at all before or after the spec-kit run (untracked files are not copied into a
    fresh `git worktree add` checkout) — this is expected, not something spec-kit touched.

---

## Deferred — tracked for next

1. **Non-atomic per-IP rate limiter.** File: `includes/api/traits/trait-tracking-controller-
   security.php`, function `check_rate_limit()`. Replace the `get_transient` / `set_transient`
   read-then-write with the atomic `wp_cache_add()` claim pattern already used in
   `includes/tracking/class-webhook-auth.php`'s `verify_request()`.
2. **No per-field length cap on the classic attribution capture path.** File:
   `includes/Core/class-attribution-provider.php`, function `sanitize()`. Add a length cap per
   UTM/click-ID field (mirror the 128-char cap already enforced on the `/attribution-token/sign`
   REST path) before persistence into the `ct_attribution` cookie, session storage, and
   WooCommerce order meta.

(The HubSpot/Typeform webhook replay timestamp-freshness idea is a hardening opportunity noted
above under Findings, not a concrete tracked item — no ClickTrail-side code change was
identified as the fix; it would require a provider-side format change that doesn't exist today.)
