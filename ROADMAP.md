# ClickTrail Roadmap

Product plan for free and Pro development. Covers features, UI/UX, security, data accuracy, and code quality goals.

Last updated: 2026-05-05

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

## Free — In Progress

These are confirmed, scoped, and being actively worked.

### 1. Two-phase consent capture
**Status:** Implemented (`assets/js/clicutcl-attribution.js`)

UTMs and click IDs are now buffered to `sessionStorage` (`ct_pending_v1`) immediately on page load before consent fires. On consent grant, the pending buffer is promoted to the attribution cookie. First-touch is preserved even when the user navigates away from the landing page before accepting the consent banner.

Edge cases covered: returning user with existing consent, CMP fires before script loads, page 2 acceptance, new tab (known limitation — documented).

**Verify:** Feature test matrix entry `consent-pending-capture`.

---

### 2. MutationObserver DNI skip
**Status:** Implemented (`assets/js/clicutcl-attribution.js`)

The MutationObserver that watches for dynamically inserted links now bails early when every new anchor in the mutation has a skippable scheme (`tel:`, `mailto:`, `#`, `javascript:`). Eliminates wasted debounce cycles caused by Dynamic Number Insertion swaps from call tracking tools (CallRail, CallTrackingMetrics, WhatConverts).

---

### 3. GF / WPForms setup diagnostic
**Status:** Planned

**Problem:** Gravity Forms and WPForms require manually added `ct_*` hidden fields. If those fields are absent, attribution silently fails — no error, no warning.

**Fix:** Add a `check_form_attribution_fields()` check in `trait-admin-diagnostics-ajax.php`, wired into the existing `ajax_conflict_scan()` response. Uses `GFAPI::get_forms()` and `wpforms()->form->get()` to scan active forms for `ct_*` fields. Returns a structured result: which forms have fields, which are missing, with a direct edit link per form.

**UI goal:** Warning surfaces in the Diagnostics conflict scan section, same pattern as existing cache/plugin conflict items. Tone `warn`, not `error` — the integration can still work if the user adds fields after reading the warning.

**Scope:** ~60 lines in one file. No new AJAX endpoint.

---

### 4. Third-party checkout limitation
**Status:** Planned

**Problem:** Cross-domain link decoration cannot cover external payment domains (Stripe, PayPal, Mollie). Attribution survives these redirects only if the cookie was written before checkout. This is documented nowhere visible to the user.

**Fix (two parts):**

Settings UI — when `enable_link_decoration` is on but `link_allowed_domains` is empty or fewer than two entries, add a secondary `warn` checklist item: "Cross-domain decoration is on but no approved domains are listed — link decoration will not fire." One addition to the checklist array in `class-admin.php`.

Docs — add an explicit "Cross-domain limitations" section to `docs/guides/IMPLEMENTATION-PLAYBOOK.md` and `docs/reference/INTEGRATIONS.md` naming external payment providers as non-decoratable by design, and explaining what attribution behavior to expect on the return URL.

---

### 5. Action Scheduler migration
**Status:** Planned

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
The two-phase capture fix resolves the data loss. The settings screen should add a one-line contextual note under the consent source selector explaining that ClickTrail buffers attribution to sessionStorage before consent fires, so landing page UTMs are preserved even when the banner is accepted on a later page. Reduces support questions from users who see the consent gate behaviour as a bug.

### Call tracking conflict scan
The conflict scan in Diagnostics currently checks for cache plugins and known JS conflicts. Add detection for active call tracking scripts (CallRail, CTM, WhatConverts) by checking for known global variables (`window.CallTrk`, `window.__ctml`, etc.) or script src patterns. When detected, surface an informational note: "A call tracking script was detected. ClickTrail skips tel: link decoration automatically. No action needed unless you are seeing unexpected behaviour."

### PHPCS / code quality
CI runs PHPCS on every push. Outstanding findings should be resolved to zero warnings, not suppressed. Any `phpcs:ignore` comment that is not load-bearing should be removed. This is a maintenance pass, not a feature — schedule it alongside a version bump.

---

## Pro — Foundation (build before UI)

These items must be in place before any Pro reporting or agency feature can ship. They have no user-visible surface in free but must be collecting data before Pro launches, otherwise early Pro users see empty dashboards.

### Events table
**This is the most important architectural decision in this roadmap.**

All Pro reporting features — attribution dashboard, LTV, conversion recovery — require a queryable log of every touch event per visitor. The current architecture writes to order meta and the attribution cookie. Neither is queryable at scale.

Schema:

```sql
CREATE TABLE {prefix}clicutcl_events (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    blog_id       BIGINT UNSIGNED NOT NULL DEFAULT 1,
    visitor_id    VARCHAR(64)  NOT NULL,
    session_id    VARCHAR(64)  NOT NULL,
    event_type    VARCHAR(32)  NOT NULL,  -- touch | conversion | renewal | call
    source        VARCHAR(128) DEFAULT NULL,
    medium        VARCHAR(128) DEFAULT NULL,
    campaign      VARCHAR(255) DEFAULT NULL,
    channel       VARCHAR(64)  DEFAULT NULL,
    ft_source     VARCHAR(128) DEFAULT NULL,
    ft_medium     VARCHAR(128) DEFAULT NULL,
    ft_campaign   VARCHAR(255) DEFAULT NULL,
    order_id      BIGINT UNSIGNED DEFAULT NULL,
    amount        DECIMAL(12,4) DEFAULT NULL,
    currency      VARCHAR(8)   DEFAULT NULL,
    created_at    DATETIME     NOT NULL,
    INDEX idx_visitor  (visitor_id),
    INDEX idx_order    (order_id),
    INDEX idx_blog     (blog_id),
    INDEX idx_created  (created_at)
);
```

Write to this table in the free version silently on every touch event and conversion. Do not expose the data in free UI. The Pro reporting layer is a read-only query layer on top of this table.

Add a retention policy (default 90 days, matching cookie retention) and a cleanup cron. Add table creation to the plugin activation hook and cleanup to `uninstall.php`.

### Customer-level attribution
Write attribution to WooCommerce customer meta (`_clicutcl_ft_source`, etc.) on first conversion, in addition to the order. Renewal events on a different order ID can then trace back to the original acquisition source for LTV tracking. One additional `update_user_meta()` call in the WooCommerce order handler.

### Delivery receipts
Add a receipt row to the queue when a server-side event is dispatched: event ID, adapter name, timestamp, HTTP status, response snippet. Currently only failure deltas are tracked. Receipts are the foundation for the Pro conversion recovery feature.

---

## Pro — Features

Ordered by dependency. Earlier items must ship before later ones.

### 1. Attribution reporting dashboard
Depends on: events table.

Revenue by channel, source, campaign. First-touch vs last-touch comparison. Date range selector. Conversion count and conversion rate per channel. This is the core Pro feature — the thing the events table was built to power.

Delivered as a new WP admin screen under `ClickTrail > Reports`. Read-only queries against the events table. No external service dependency.

### 2. WooCommerce Subscriptions / LTV
Depends on: events table, customer-level attribution.

Hook into `woocommerce_subscription_renewal_payment_complete`. Tag renewal revenue to the original order's attribution via customer meta. Surface in the reporting dashboard as a "lifetime value by channel" view.

### 3. Conversion recovery
Depends on: delivery receipts.

Surface failed delivery attempts in a Pro "Delivery accuracy" tab in Diagnostics. Show event ID, adapter, timestamp, response code. Add a "Resubmit" button per failed event that replays the stored payload against the current endpoint config. Cap resubmission to 3 attempts per event with exponential backoff.

### 4. CRM field mapping UI
Depends on: nothing except Pro license gate.

The server-side adapters currently use fixed schemas. A Pro UI lets the user map attribution fields to CRM-specific field names: `ct_ft_source` → HubSpot `hs_analytics_source`, for example. Conditional routing: "if utm_medium = paid, route to deal pipeline; otherwise route to contact." This is the feature that makes ClickTrail useful for B2B agencies passing lead attribution into CRM.

### 5. Call tracking webhook intake
Depends on: events table.

A Pro REST endpoint (`/wp-json/clicktrail/v1/call-complete`) that accepts inbound webhooks from CallRail, CallTrackingMetrics, and WhatConverts on call completion. Matches the call record to ClickTrail attribution by visitor session or landing page URL. Writes a `call` event row to the events table. Surfaces in the reporting dashboard alongside web conversions.

### 6. Multi-site / agency mode
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
