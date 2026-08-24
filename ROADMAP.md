# ClickTrail Roadmap

Product plan for free and Pro development. Covers features, UI/UX, security, data accuracy, and code quality goals.

Last updated: 2026-08-22 (feature roadmap overlay added; future items remain unshipped)

> **Canonical roadmap visibility:** the product passport, evidence states, full 12-month calendar roadmap, unresolved decisions, and release gates live in [docs/MASTER-SPECIFICATION.md](docs/MASTER-SPECIFICATION.md). This file remains the detailed free/Pro implementation backlog and dependency list.

---

## Principles

Every item on this roadmap is evaluated against five criteria before shipping:

- **UI clarity** — does the settings screen explain what the feature does and when it fires, without requiring docs?
- **Messaging** — does the admin copy reflect current product scope, not legacy implementation names?
- **Bug-free** — is the edge case coverage documented in the feature test matrix?
- **Security** — are inputs sanitized, nonces checked, capabilities verified, debug output gated?
- **Data accuracy** — does the feature write what it claims to write, and is it verifiable in diagnostics?

Clean, lean code is a constraint on all of the above, not a separate goal. If an implementation requires a comment to justify its complexity, it should be simplified first.

---

## Next 12 months — feature release overlay

**Window:** September 2026–August 2027.
**Status:** planning; every item below is **Future** unless an existing section
explicitly marks it **Implemented**. Target release numbers remain a decision
for `RELEASING.md`; month labels are planning assumptions.

This overlay adds the next product-facing sequence to the free/Pro backlog. The
master specification remains the evidence and release-gate source of truth.

| Month | Feature/update | User outcome | Main repository surfaces | Exit gate | State |
|---|---|---|---|---|---|
| M1 — Sep 2026 | **UTM Identify contract**: review and promote the untracked `Attribution_Readiness_Analyzer`; freeze input/output schema, source precedence, click-ID map, referrer evidence, and privacy boundary | Operators can see what evidence was observed without a guessed campaign value | `includes/Intelligence/`, `tests/unit/AttributionReadinessAnalyzerTest.php`, capability ledger, feature registry | Code review; source-precedence tests; ambiguous multi-platform signals never produce a deterministic suggestion; no runtime/UI claim yet | Future / Decision needed |
| M2 — Oct 2026 | **Attribution Readiness Diagnostics v1**: wire the pure analyzer into a read-only Diagnostics panel and admin endpoint | A site owner can inspect missing, empty, macro, conflict, click-ID, and referrer states on a safe test payload | Diagnostics AJAX/UI, registry, smoke matrix, `INTEGRATIONS.md` | Capability/permission/nonce tests; no raw click-ID value retention; output is redacted and versioned | Future |
| M3 — Nov 2026 | **Deterministic Suggestions v1**: suggest only `utm_source` from exactly one recognized click-ID platform; support validated site aliases; never invent `utm_medium` or `utm_campaign` | Users get a useful next action without the plugin fabricating campaign taxonomy | Analyzer policy, Diagnostics copy, settings help, unit tests | Existing source is never overwritten; multiple platforms yield attention/no auto-suggestion; referrer suggestions remain non-deterministic; unresolved macros block | Future |
| M4 — Dec 2026 | **UTM hygiene and contract checks**: normalize key aliases, detect unresolved macros/empty values, expose first/last-touch precedence, and add a “copy test URL” action rather than live URL mutation | Teams can fix a test campaign safely and understand why a value was selected | Attribution JS, analyzer, setup checklist, test URL builder | No production attribution write or broad internal-link rewrite; source aliases are allowlisted/bounded; browser and PHP outputs agree | Future |
| M5 — Jan 2027 | **Form readiness diagnostics**: extend source analysis to the three form patterns and compare expected, submitted, provider-record, hook-payload, and ClickTrail-event evidence without collapsing them into one “stored” state | Agencies can identify whether a failure is missing fields, hook/storage, cache/AJAX, or source data | Form adapters, Diagnostics, fixture matrix, compatibility docs | CF7/Fluent automatic fields, GF/WPForms matching fields, and Elementor/Ninja hook-storage paths each have a versioned fixture; named storage surfaces remain evidence-qualified; no unsupported builder claim | Source comparator + six synthetic fixtures validated locally; runtime/admin/provider proof Future |
| M6 — Feb 2027 | **Woo conversion readiness**: enrich the order trace with source evidence, consent snapshot, order status, HPOS, event ID, and dedup state | Operators can explain a Direct/empty, duplicate, or missing purchase without guessing | Woo integration, trace snapshot, Diagnostics, HPOS fixtures | Granted/denied checkout, reload, status change, refund, currency/value, HPOS, and duplicate tests; no provider acceptance claim | M6-A validated; M6-B classic/HPOS runtime reproduced; refund trace and HPOS admin fixed; consent emission blocker remains |
| M7 — Mar 2027 | **Consent foundation release**: one authoritative decision across capture, persistence, forms/Woo, browser events, and dispatcher; pre-send retry recheck; owned-data erase/purge completion | Consent denial and withdrawal stay consistent through delayed work and deletion | Consent service, dispatcher, queue, Woo meta, privacy/uninstall handlers | Live grant/deny/stale-cookie/revocation/cross-tab/queued-retry/erasure drills; no “compliant” marketing claim without legal review | M7-A contract validated; M7-B versioned Woo snapshots passed classic/HPOS staging; browser, retry, erasure, and legal gates Future |
| M8 — Apr 2027 | **Delivery integrity release**: action-bound browser conversions, bounded payloads, webhook replay/identity contracts, atomic dedup, sGTM preview private-target rejection | Delivery failures become visible and safely contained instead of silently trusted | Tracking controller, queue, adapters, sGTM preview, redacted diagnostics | Negative fixtures for replay, timeout, 429/5xx, malformed response, duplicate, purge, and private URL; Delivery remains default-off | Future |
| M9 — May 2027 | **Generic collector contract**: first staged runtime-tested delivery path with canonical mapping, auth, consent denial, redaction, retry, idempotency, and response handling | One delivery path can graduate from source-present to runtime-contract-tested | Generic adapter, fixture harness, evidence ledger, release docs | Versioned fixture + staged endpoint evidence; public wording updated only for the tested contract | Future / Cost decision needed |
| M10 — Jun 2027 | **sGTM staging contract**: test ClickTrail → staging tagging server → owned destination flow | sGTM users receive an evidence-backed setup path, without ClickTrail becoming a host | sGTM adapter/preview, staging fixture, diagnostics, runbook | DNS/SSL/CORS/CSP/auth/consent/dedup/retry and response evidence; no secure/reliable guarantee | Future / Infrastructure decision needed |
| M11 — Jul 2027 | **Receipts and customer attribution foundation**: delivery receipts and Woo customer-level first-touch linkage; demand-led provider contract only where paid need repeats | Agencies can hand off event status and renewal attribution as observed records | Queue receipts, Woo customer meta, touch-events store, retention/erase tests | Receipt rows and renewal paths verified; storage/erasure impact measured; no Pro dashboard implied | Future |
| M12 — Aug 2027 | **Read-only reporting/re-audit decision**: evaluate touch-event data quality, scope a Pro report, and decide which provider/reach pages may publish | Product direction follows observed data quality, not competitor feature count | Touch-events queries, report scope, evidence ledger, competitor/content docs | Annual audit; report scope and publish/no-publish decisions recorded; no dashboard launch unless data and ownership gates pass | Future / Decision needed |

### UTM Identify and deterministic suggestion contract

The current analyzer is a **pure, untracked candidate**, not a shipped feature.
Its safe contract should remain explicit:

- **Input:** one attribution payload, optional referrer, current host, and
  bounded site alias policy.
- **Observed evidence:** UTM fields, flattened first/last-touch aliases,
  recognized click-ID *keys* (not their values), and known external referrer
  host.
- **Source precedence:** define and fixture the intended `last touch > direct
  value > first touch` order before UI promotion; the candidate implementation
  and its comment must agree.
- **Deterministic inference:** a single recognized click ID may suggest a
  canonical `utm_source` platform key (`gclid` → `google`, `li_fat_id` →
  `linkedin`, and so on). A configured alias may replace the displayed value,
  but not the evidence basis.
- **Non-deterministic evidence:** a referrer can produce a candidate source;
  it must never imply paid medium or campaign.
- **Never invent:** `utm_medium` and `utm_campaign` remain configuration
  requests when absent. The analyzer must not manufacture taxonomy from a click
  ID.
- **Conflict behavior:** an observed `utm_source` is never overwritten; a
  mismatch with click-ID evidence becomes an attention issue. Multiple platform
  click IDs must suppress automatic source suggestion until the operator
  resolves the ambiguity.
- **Safety behavior:** unresolved macros and non-scalar values block the field;
  bounded values are returned only where needed for the operator’s own UTM
  payload; no URL rewriting, persistence, provider verification, or delivery
  status is inferred.
- **UI behavior:** first release is read-only. “Copy test URL” or “use in test
  recipe” is safer than silently changing a live campaign URL, cookie, order, or
  form entry.

### Feature promotion rule

A feature moves from candidate to public only when its source path, output
contract, focused tests, permission boundary, evidence-ledger entry, smoke ID,
release note, and user-facing documentation agree. A deterministic suggestion is
not a delivery verification feature, and an analyzer score is not a claim that
an upstream provider accepted an event.

---

## Free — In Progress

These are confirmed, scoped, and being actively worked.

### 1. Consent-gated pending capture
**Status:** Implemented (`assets/js/clicutcl-attribution.js`)

`ct_pending_v1` is written only when consent is not required or marketing
consent is already resolved and granted. Required-but-unresolved consent does
not write attribution to `sessionStorage`; denial clears pending attribution.
This means a landing-page signal can be lost when consent is first granted on a
later page. M7 must resolve that product trade-off without introducing
pre-consent persistence by accident.

**Verify:** `npm run smoke` checks the source gate around `PendingCapture.save()`.

---

### 2. MutationObserver DNI skip
**Status:** Implemented (`assets/js/clicutcl-attribution.js`)

The MutationObserver that watches for dynamically inserted links now bails early when every new anchor in the mutation has a skippable scheme (`tel:`, `mailto:`, `#`, `javascript:`). Eliminates wasted debounce cycles caused by Dynamic Number Insertion swaps from call tracking tools (CallRail, CallTrackingMetrics, WhatConverts).

---

### 3. GF / WPForms setup diagnostic
**Status:** Implemented (`trait-admin-diagnostics-ajax.php` `check_form_attribution_fields()`, wired into the conflict scan)

**Problem:** Gravity Forms and WPForms require manually added `ct_*` hidden fields. If those fields are absent, attribution silently fails — no error, no warning.

**Fix:** Add a `check_form_attribution_fields()` check in `trait-admin-diagnostics-ajax.php`, wired into the existing `ajax_conflict_scan()` response. Uses `GFAPI::get_forms()` and `wpforms()->form->get()` to scan active forms for `ct_*` fields. Returns a structured result: which forms have fields, which are missing, with a direct edit link per form.

**UI goal:** Warning surfaces in the Diagnostics conflict scan section, same pattern as existing cache/plugin conflict items. Tone `warn`, not `error` — the integration can still work if the user adds fields after reading the warning.

**Scope:** ~60 lines in one file. No new AJAX endpoint.

---

### 4. Third-party checkout limitation
**Status:** Implemented (`cross_domain_decoration` setup-checklist item covers the guidance incl. the Stripe/PayPal/Mollie caveat). The "decoration will not fire without approved domains" warning was dropped — subdomains are now decorated automatically without the allowed list.

**Problem:** Cross-domain link decoration cannot cover external payment domains (Stripe, PayPal, Mollie). Attribution survives these redirects only if the cookie was written before checkout. This is documented nowhere visible to the user.

**Fix (two parts):**

Settings UI — when `enable_link_decoration` is on but `link_allowed_domains` is empty or fewer than two entries, add a secondary `warn` checklist item: "Cross-domain decoration is on but no approved domains are listed — link decoration will not fire." One addition to the checklist array in `class-admin.php`.

Docs — add an explicit "Cross-domain limitations" section to `docs/guides/IMPLEMENTATION-PLAYBOOK.md` and `docs/reference/INTEGRATIONS.md` naming external payment providers as non-decoratable by design, and explaining what attribution behavior to expect on the return URL.

---

### 5. Action Scheduler migration
**Status:** Implemented (`class-queue.php` `ensure_schedule()`/`clear_schedule()` use Action Scheduler with a WP-cron fallback; `uninstall.php` cancels AS actions)

**Problem:** The server-side delivery retry queue runs on WP-cron. On shared hosting, WP-cron is unreliable (traffic-triggered, can be disabled). Queue backs up silently.

**Fix:** In `class-queue.php`, detect Action Scheduler availability via `function_exists('as_schedule_single_action')` (present whenever WooCommerce is active, which covers the primary user base). Use AS when available; fall back to WP-cron otherwise. Update `uninstall.php` with `as_unschedule_all_actions('clicktrail-delivery')` guarded by the same check. Do not add AS as a Composer dependency — bundling it risks version conflicts with WooCommerce's own copy.

**Scope:** Two files changed in code, one doc update.

---

## Free — Backlog

Confirmed direction, not yet scoped into a sprint.

### UI/messaging audit
The plugin header Description in `clicutcl.php` was updated (2026-05-05). A full pass is needed across:
- All settings tab descriptions and field labels
- The setup checklist copy in Settings
- Diagnostics screen section headers
- Any remaining references to "Tracking v2" in UI-facing strings (option keys are intentionally preserved; user-visible strings are not)

Goal: a user who has never read the docs should be able to configure the plugin correctly from the settings screen alone.

### Consent timing — inline help
The two-phase capture fix resolves the data loss. The settings screen should add a one-line contextual note under the consent source selector explaining that ClickTrail buffers attribution to sessionStorage before consent fires, so landing page UTMs are preserved even when the banner is accepted on a later page. Reduces support questions from users who see the consent gate behaviour as a bug. **(Implemented in v1.8.12.)**

### Call tracking conflict scan
The conflict scan in Diagnostics currently checks for cache plugins and known JS conflicts. Add detection for active call tracking scripts (CallRail, CTM, WhatConverts) by checking for known global variables (`window.CallTrk`, `window.__ctml`, etc.) or script src patterns. When detected, surface an informational note: "A call tracking script was detected. ClickTrail skips tel: link decoration automatically. No action needed unless you are seeing unexpected behaviour."

### PHPCS / code quality
CI runs PHPCS on every push. Outstanding findings should be resolved to zero warnings, not suppressed. Any `phpcs:ignore` comment that is not load-bearing should be removed. This is a maintenance pass, not a feature — schedule it alongside a version bump.

---

## Pro — Foundation (build before UI)

These items must be in place before any Pro reporting or agency feature can ship. They have no user-visible surface in free but must be collecting data before Pro launches, otherwise early Pro users see empty dashboards.

### Touch-events table
**Status:** Source-present in 1.9.0; runtime data quality remains unverified.

`{prefix}clicutcl_touch_events` provides the queryable storage prerequisite for
future reporting. Collection does not authorize a dashboard. M7 consent and
erasure behavior, staging inserts, retention growth, and M12 data-quality review
must pass before a reporting surface is approved.

### Customer-level attribution
Write attribution to WooCommerce customer meta (`_clicutcl_ft_source`, etc.) on first conversion, in addition to the order. Renewal events on a different order ID can then trace back to the original acquisition source for LTV tracking. One additional `update_user_meta()` call in the WooCommerce order handler.

### Delivery receipts
Add a receipt row to the queue when a server-side event is dispatched: event ID, adapter name, timestamp, HTTP status, response snippet. Currently only failure deltas are tracked. Receipts are the foundation for the Pro conversion recovery feature.

---

## Pro — Features

Ordered by dependency. Earlier items must ship before later ones.

### 1. Attribution reporting dashboard
**Status:** Decision deferred to M12.

Depends on: verified touch-events data quality and explicit product ownership.

Revenue by channel, source, campaign. First-touch vs last-touch comparison. Date range selector. Conversion count and conversion rate per channel. This is the core Pro feature — the thing the events table was built to power.

If approved at M12, deliver as a read-only WP admin screen under
`ClickTrail > Reports`. Do not build an empty dashboard merely because storage
exists.

### 2. WooCommerce Subscriptions / LTV
Depends on: events table, customer-level attribution.

Hook into `woocommerce_subscription_renewal_payment_complete`. Tag renewal revenue to the original order's attribution via customer meta. Surface in the reporting dashboard as a "lifetime value by channel" view.

### 3. Conversion recovery
Depends on: delivery receipts.

Surface failed delivery attempts in a Pro "Delivery accuracy" tab in Diagnostics. Show event ID, adapter, timestamp, response code. Add a "Resubmit" button per failed event that replays the stored payload against the current endpoint config. Cap resubmission to 3 attempts per event with exponential backoff.

### 4. CRM field mapping UI
Depends on: nothing except Pro license gate.

The server-side adapters currently use fixed schemas. A Pro UI lets the user map attribution fields to CRM-specific field names: `ct_ft_source` → HubSpot `hs_analytics_source`, for example. Conditional routing: "if utm_medium = paid, route to deal pipeline; otherwise route to contact." This is the feature that makes ClickTrail useful for B2B agencies passing lead attribution into CRM.

### 5. Multi-site / agency mode
Depends on: all above Pro features, license layer.

Network-level install with per-site configuration overrides. Central diagnostics view across all sites in the network. White-label mode (remove ClickTrail branding from admin UI). This is the last Pro feature to build — it is infrastructure, not product. Build it when there are agency customers asking for it, not before.

---

## Quality Gates

Every release must pass these before tagging:

**Code**
- PHPCS: zero warnings (no new `phpcs:ignore` without documented justification)
- PHPUnit: all tests pass on PHP 8.1, 8.2, 8.3
- JS syntax: `node --check` on all JS files
- Smoke test: `npm run smoke` passes all 37+ registry-backed IDs

**Security**
- All AJAX handlers: nonce checked, capability checked (`manage_options` or equivalent)
- All option saves: sanitized through registered sanitize callbacks
- All debug output: gated behind `WP_DEBUG`
- All user-supplied values reaching output: escaped at render time (`esc_html`, `esc_attr`, `wp_kses`)

**Data accuracy**
- Attribution cookie writes verified in manual test: same-page accept, page-2 accept, returning user, consent disabled
- WooCommerce order meta verified: order contains `_clicutcl_ft_source` after test purchase with UTM
- Form submission verified: at least one supported form plugin confirmed writing attribution to entry meta

**UI/messaging**
- No settings field label references internal option key names or legacy "Tracking v2" terminology
- Setup checklist shows expected state after a clean install with no configuration
- Diagnostics screen shows a green result after the recommended first setup is complete

---

## Not Building

These are explicitly out of scope. Do not add without revisiting this decision:

- **More form plugin integrations** — table stakes, should stay free and grow with demand. Not a Pro feature.
- **More server-side adapters** — same reasoning. Gating delivery adapters on Pro creates a compliance liability.
- **Shopify / Magento / PrestaShop support** — different platforms, different codebases, different support surface. Not a WordPress plugin decision.
- **Multi-touch attribution models in free** — the data collection (events table) can be free; the model selection UI is Pro.
- **Hyros / RedTrack-style performance tracking** — out of ClickTrail's lane. Different buyer, different price point, different infrastructure requirement.
