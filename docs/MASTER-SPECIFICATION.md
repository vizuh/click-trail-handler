# ClickTrail Master Product Specification

**Document type:** Complete master specification (current evidence baseline; canonical product source of truth)
**Prepared:** 2026-08-22 (Europe/Lisbon)
**Prepared by:** Single OpenRouter Ox Alpha documentation pass, then parent-agent repository review; this provenance is not product authority
**Specification document version:** `0.1.0`
**Last updated:** 2026-08-22 (Europe/Lisbon)
**Document state:** Confirmed as the current repository-evidence synthesis; unresolved claims remain explicitly labelled and are not release approval
**Repository evidence baseline:** plugin code `1.9.0` at commit `a45aa9e` (audit date 2026-08-19)
**Current checkout at drafting time:** branch `docs/integration-evidence-boundaries`, HEAD `c9dedab`
**Runtime verification status:** PHP/WordPress/provider/browser E2E verification was **not completed** in the 2026-08-19 audit (`docs/reference/integration-capabilities.json`, `docs/guides/SECURITY-PRIVACY.md`)

> **Working-tree caution:** files that exist only as uncommitted changes at HEAD `c9dedab` are **not** described as shipped anywhere in this document. The audited docs baseline is `a45aa9e`; any delta between `a45aa9e` and `c9dedab` was not itemized in the supplied evidence, so runtime-code claims in this document inherit the `a45aa9e` baseline and carry an **[Assumption]** that no undocumented runtime change intervenes. This assumption must be re-verified before any release decision (**Decision needed**).

---

## 0. How to Read This Document

### 0.1 State legend (used on every material claim and every requirement row)

Exactly one of:

| State | Meaning |
|---|---|
| **Confirmed** | Directly evidenced by repository files, code, or machine-readable registries supplied to this draft. |
| **Assumption** | Reasonably inferred from evidence but not independently proven (e.g., behavior between doc baselines). |
| **Decision needed** | A genuine open choice or missing evidence that a human owner must resolve; no invention has been made. |
| **Future** | Planned direction with no current implementation; must never be described as shipped. |
| **Blocked** | Gated on evidence, another work item, or an external party; cannot proceed as-is. |
| **Deprecated** | Present historically or in compatibility surfaces; not part of the active product truth going forward. |

**State application rule:** a state marker on a section heading applies to the material paragraphs and lists immediately below it until another explicit state marker appears; every master-sheet, semantic-contract, safety-register, roadmap, and change-record row carries its own State cell. If a sentence combines evidence and an unresolved inference, the unresolved portion is marked separately rather than upgraded by the confirmed portion.

### 0.2 Separate lifecycle status vocabulary (orthogonal to State)

`Proposed` · `Confirmed` · `Blocked` · `Deprecated` · `Completed`

### 0.3 Source-of-truth precedence rule

When sources disagree, the following precedence applies (highest first). Lower-precedence sources must never upgrade a higher-precedence claim:

1. **Runtime source code at the reviewed baseline** (`a45aa9e`; re-verify at each new baseline) — including `config/feature-registry.json` as machine-readable wiring truth.
2. **Integration evidence ledger** — `docs/reference/integration-capabilities.json` (authoritative for public integration/evidence status).
3. **Canonical architecture/reference docs** — `docs/architecture/*` (storage truth: `DATA-MODEL.md`; pipeline truth: `EVENT-PIPELINE.md`), `docs/reference/REST-API.md`, `docs/reference/HOOKS-REFERENCE.md`, `docs/reference/FEATURE-REGISTRY.md`.
4. **Operational/guidance docs** — `docs/guides/*` (runbook, security-privacy, settings-admin, test matrix, release phasing).
5. **Product readmes** — `README.en.md`, `README.pt-BR.md`, `README.md`, `readme.txt` (positioning; must not outrun layers 1–4).
6. **Changelog** — `changelog.txt` (historical record; superseded by later entries).
7. **This specification** — a synthesis; wherever it conflicts with layers 1–6, layers 1–6 win and this document must be corrected.
8. **Chat/AI output** — never product truth; this document exists precisely to end chat-as-source-of-truth.

Known documentation conflicts discovered during synthesis are registered in Appendix A and marked **Decision needed**; they are not silently resolved.

---

## Product Passport

| Field | Value | State |
|---|---|---|
| Product name | ClickTrail – UTM, Click ID & Ad Tracking (with Consent) | Confirmed (`clicutcl.php`) |
| One-line purpose | Consent-aware marketing attribution for WordPress: captures UTMs, click IDs, referrers; enriches WooCommerce orders and forms; collects browser events; optional server-side delivery | Confirmed (`clicutcl.php` header; `README.en.md`) |
| Plugin code version (header) | `1.9.0` (`CLICUTCL_VERSION`) | Confirmed (`clicutcl.php`) |
| Docs/source baseline | `1.9.0` at commit `a45aa9e`, reviewed 2026-08-19 | Confirmed (`docs/README.md`, `docs/reference/INTEGRATIONS.md`, `integration-capabilities.json`) |
| Current checkout | Branch `docs/integration-evidence-boundaries`, HEAD `c9dedab`; uncommitted files excluded from all "shipped" claims | Confirmed (drafting context) |
| Author / owner | Vizuh (`clicutcl.php`); individual component owners: **Unassigned — Decision needed** | Confirmed / Decision needed |
| License | GPL-2.0-or-later | Confirmed (`clicutcl.php`, `LICENSE` per `README.en.md`) |
| Requires | WordPress 6.5+, PHP 8.1+; WooCommerce 10.4.2+ required for WP 7.0 compatibility | Confirmed (`clicutcl.php`, changelog 1.8.5) |
| Tested up to | WordPress 7.0 (badge + readme claims) | Confirmed (`README.en.md`, changelog 1.8.5) |
| Distribution | GitHub releases primary; WordPress.org stable tag remains `1.8.13` per a 3-version buffer policy referenced in changelog notes (`RELEASING.md` referenced but not supplied — Decision needed to verify) | Confirmed (changelog notes) / Decision needed |
| Problem being solved | Campaign/source context is lost between ad click, navigation, forms, checkout, consent, and downstream delivery; ClickTrail keeps the source available at the WordPress conversion boundary | Confirmed (`README.en.md`) |
| Intended users | Agencies, lead-generation teams, WooCommerce stores, implementers, and operators responsible for consent-aware WordPress measurement | Confirmed (`README.en.md`) |
| Market / geographic scope | WordPress sites globally; current localized admin artifacts include pt-BR and de_DE. Legal/geographic deployment scope is not specified and requires review | Confirmed / Decision needed |
| Product owner | Vizuh is the product publisher; named product and technical owners are not assigned in the evidence | Confirmed / Decision needed |
| Current phase | Truth-containment documentation only (lane L0); no runtime remediation claimed | Confirmed (`README.en.md`, `RELEASE-PHASING-AND-INTEGRATION-DOCS.md`) |
| Specification version | `0.1.0` | Confirmed (this document) |
| Last update | 2026-08-22 (Europe/Lisbon) | Confirmed (this document) |
| Related links | [source code](../); [technical docs](README.md); [architecture](architecture/PLUGIN-OVERVIEW.md); [integration ledger](reference/integration-capabilities.json); [roadmap](../ROADMAP.md); [competitor planning evidence](guides/COMPETITOR-ROADMAP-2026-08-22.md) | Confirmed |
| Integration evidence boundary | Platform-named server adapters are **source-present / runtime-unverified configured-endpoint adapters**, not turnkey provider integrations. Reddit is **relay-only**. GTM-mediated platform tags are **not native ClickTrail adapters**; ClickTrail injects no platform pixel SDKs | Confirmed (`README.en.md`, `INTEGRATIONS.md`, ledger) |
| Pricing | No confirmed public pricing or quotas found in supplied evidence — **Unknown / not priced** | Decision needed |
| Compliance posture | Privacy-aware by design; **no legal-compliance or privacy certification is asserted anywhere in this document**; legal verification is **Decision needed** | Decision needed |
| Master sheet | 61 permanent-ID rows — see [Master Specification Sheet](#master-specification-sheet) | Confirmed |

---

## Section 1 — Purpose and Scope

### 1.1 Purpose **[Confirmed]**

Attribution usually breaks between the ad click and the conversion. ClickTrail keeps first-touch and last-touch campaign context available until WooCommerce orders, forms, browser events, or downstream delivery flows actually need it (`README.en.md`). It stores *the source of the visit, not a profile of the visitor*; capture is first-party, consent-controlled, and no external enrichment service is called by default (`README.en.md`).

### 1.2 Problems solved **[Confirmed]** (`README.en.md`)

1. Lost campaign attribution across multi-page/multi-session journeys.
2. Cached/dynamic pages breaking hidden-field enrichment (client-side fallback + MutationObserver watching).
3. WooCommerce orders with weak/missing source data (order attribution + enriched purchase `dataLayer` push).
4. Cross-domain journeys losing continuity (link decoration + optional signed attribution tokens).
5. Consent and delivery living in separate tools (consent, intake, delivery configured in one plugin).

### 1.3 Capability areas **[Confirmed]** (`PLUGIN-OVERVIEW.md`, `SETTINGS-AND-ADMIN.md`)

- **Capture** — attribution collection, retention, cross-domain continuity.
- **Forms** — form enrichment, WhatsApp continuity, external form-source intake.
- **Events** — browser event collection, GTM helpers, destinations, lifecycle updates.
- **Delivery** — server-side transport, privacy, queue health, diagnostics.

Internal note: part of advanced runtime state still lives in the `clicutcl_tracking_v2` option for backward compatibility; "Tracking v2" is not user-facing terminology **[Confirmed]** (`PLUGIN-OVERVIEW.md`, `SETTINGS-AND-ADMIN.md`).

### 1.4 Explicit non-goals **[Confirmed]** (`ROADMAP.md` — "Not Building")

More form-plugin integrations as a Pro gate; more server-side adapters gated on Pro; Shopify/Magento/PrestaShop support; multi-touch model-selection UI in free (collection may be free); Hyros/RedTrack-style performance tracking. Do not add without revisiting the decision.

### 1.5 Adoption layering **[Confirmed]** (`README.en.md`, `IMPLEMENTATION-PLAYBOOK.md` via `docs/README.md`)

1. Preserve attribution → 2. expose in forms/WooCommerce → 3. add browser events → 4. add server-side delivery when an operational endpoint exists. Base attribution, form enrichment, and Woo order storage work with delivery off **[Confirmed]** (`EVENT-PIPELINE.md` "Important Runtime Distinction").

### 1.6 Scope boundary of this document **[Confirmed]**

This specification records product truth as evidenced at baseline `a45aa9e`. It does not assert runtime verification, provider support, legal compliance, prices, SLAs, or completion of future roadmap work.

---

## Section 2 — Users, Journeys, and Workflows

### 2.1 Target users **[Confirmed]** (`README.en.md`)

Agencies needing attribution inside lead forms; WooCommerce stores wanting campaign-aware order data; stores wanting richer purchase payloads without replacing their tracking stack; sites with aggressive caching or dynamic form rendering; multi-domain funnels; teams wanting browser + server-side tracking in one plugin.

### 2.2 Primary journeys **[Confirmed unless noted]**

| Journey | Flow | State |
|---|---|---|
| Lead-gen form journey | Visitor lands with UTMs/click ID → attribution persisted first-party → form hidden fields populated (auto for CF7/Fluent; matching-field population for GF/WPForms; submission-record storage for Elementor/Ninja) → submission logged and optionally dispatched server-side | Confirmed (`EVENT-PIPELINE.md`, `INTEGRATIONS.md`) |
| WooCommerce purchase journey | Checkout attribution saved on order → thank-you page pushes purchase to `dataLayer` → optional storefront events → optional server-side purchase dispatch with dedup + trace snapshots → milestones (`order_paid`/`refunded`/`cancelled`) reuse the same pipeline | Confirmed (`EVENT-PIPELINE.md`) |
| Cross-domain journey | Approved-domain link decoration; sibling-subdomain passthrough automatic; optional signed token via `/sign` + `/verify`; hosted payment pages (Stripe/PayPal/Mollie/Square) are **non-decoratable by design** — survival depends on the pre-checkout cookie | Confirmed (`INTEGRATIONS.md`, changelog 1.7.9) |
| Consent-gated journey | Consent resolved via bridge (`ct:consentResolved`) → granted: capture/events proceed; denied: local attribution storage cleared (downstream copies **not** auto-erased — documented boundary) | Confirmed (`SECURITY-PRIVACY.md`) |
| Operator journey | Settings checklist → Diagnostics (endpoint test, conflict scan, backup, Woo trace lookup, debug window, purge) → Logs | Confirmed (`OPERATIONS-RUNBOOK.md`) |

### 2.3 Recommended first setup **[Confirmed]** (`README.en.md`)

Install → activate → `ClickTrail > Settings` → configure Capture (retention to match sales cycle; cross-domain only when visitors truly cross domains) → Forms (enable only used integrations; GF/WPForms need `ct_*` hidden fields added first) → Events (browser events/dataLayer; Woo storefront events; richer Woo contract; GTM container ID only if the site does not already inject GTM) → Delivery (leave off unless a collector/sGTM/ad endpoint exists; choose consent source before go-live) → run Diagnostics.

### 2.4 Verification workflow ("confirm it is working") **[Confirmed]** (`README.en.md`)

Visit with `?utm_source=test&utm_medium=cpc&utm_campaign=clicktrail-install-check` → browse → submit a configured form or place a test Woo order → confirm attribution values in the form entry/order, events in GTM preview/`dataLayer` (if Events enabled), and intake/delivery activity in Diagnostics/Logs (if Delivery enabled).

### 2.5 Known journey limitations **[Confirmed]**

- New-tab consent acceptance is a documented limitation of two-phase capture design (`ROADMAP.md`).
- Hosted-payment redirects lose decoration by design; no prior cookie ⇒ unattributed order (`INTEGRATIONS.md`).
- Consent-denied orders show empty attribution by design; Diagnostics explains this via the checkout-time consent snapshot (1.8.16, closes issue #44).

---

## Section 3 — Functional Requirements

Full testable rows live in the [Master Specification Sheet](#master-specification-sheet). Narrative summary of requirement clusters (each cluster maps to one or more `CT-xxx` rows):

1. **Attribution capture** — first/last-touch UTMs including extended GA-style fields (`utm_id`, `utm_source_platform`, `utm_creative_format`, `utm_marketing_tactic`); click IDs (`gclid`, `wbraid`, `gbraid`, `fbclid`, `ttclid`, `msclkid`, `twclid`, `li_fat_id`, `sccid`, `epik`); browser identifiers (`fbc`, `fbp`, `_ttp`, `li_gc`, `ga_client_id`, `ga_session_id`); referrer fallback (organic/social/referral) when tagged signals are absent; configurable retention; cross-domain decoration; optional token continuity **[Confirmed]** (`README.en.md`, `DATA-MODEL.md`).
2. **Forms** — CF7/Fluent automatic hidden fields; GF/WPForms matching-hidden-field population with per-form toggle (`clicutcl_gf_tracking_enabled`) and channel-label-as-stored-data rule; Elementor/Ninja submission-hook paths; client-side fallback; dynamic-content watching; optional overwrite; WhatsApp append; external form-source webhooks **[Confirmed]** (`INTEGRATIONS.md`, `HOOKS-REFERENCE.md`).
3. **Events** — browser collection (search, download, scroll depth, engagement, lead-gen interactions, one-time `login`/`sign_up`/`comment_submit`); Woo storefront events with `item_list_name`/`item_list_index` inheritance; richer Woo `dataLayer` contract (`event_id`, consent-aware `user_data`); canonical REST intake with a strict browser-event allowlist; lifecycle intake **[Confirmed]** (`EVENT-PIPELINE.md`, `REST-API.md`).
4. **Delivery** — dispatcher with environment/settings/endpoint/consent validation; registry-backed adapter selection; dedup; queue retries (every 5 min, batch 10, max 5 attempts, exponential backoff capped at 1 h); Action Scheduler with WP-cron fallback; diagnostics ring buffers; failure telemetry **[Confirmed]** (`EVENT-PIPELINE.md`, `OPERATIONS-RUNBOOK.md`, changelog 1.8.11 reconciliation).
5. **Consent** — modes `strict`/`relaxed`/`geo`; CMP sources auto/plugin/Cookiebot/OneTrust/Complianz/GTM/custom; built-in lightweight banner; geo fails safe to requiring consent; documented unresolved edge cases (see Section 5) **[Confirmed]** (`SECURITY-PRIVACY.md`).
6. **Diagnostics & operations** — setup checklist, conflict scan (incl. call-tracking detection, 1.8.17), endpoint test, backup export/restore, Woo order trace lookup, debug windows, queue backlog, failure telemetry, local purge **[Confirmed]** (`OPERATIONS-RUNBOOK.md`, `SETTINGS-AND-ADMIN.md`).
7. **Storage & lifecycle** — five main option stores + `clicutcl_tracking_v2`; tables `wp_clicutcl_events`, `wp_clicutcl_queue`, `wp_clicutcl_touch_events` (new in 1.9.0); Woo order-meta trace/markers; cookies/localStorage/sessionStorage surfaces; daily cleanup; uninstall drops tables unless `clicutcl_preserve_data_on_uninstall` **[Confirmed]** (`DATA-MODEL.md`).

**Future intelligence candidate:** the untracked `Attribution_Readiness_Analyzer`
would provide read-only UTM identification, click-ID platform evidence,
referrer candidates, missing-field/macro/conflict states, and deterministic
source-only suggestions. It is not a current requirement, public feature, score,
delivery verifier, or consent verifier; promotion follows the feature roadmap
and requires review, tests, registry/smoke wiring, and a no-persistence UI
contract.

---

## Section 4 — Technical and Semantic Specifications

### 4.1 Architecture **[Confirmed]** (`PLUGIN-OVERVIEW.md`, `CODE-MAP.md`, `clicutcl.php`)

Bootstrap: constants → Composer autoloader (if present) → plugin autoloader + Context fallback → activation/deactivation hooks → `clicutcl_init()` on `init` (priority 20) → preflight class checks (admin error notice, never fatal) → `CLICUTCL\Plugin` instantiated. Activation creates/updates tables, writes DB-readiness flags, seeds `clicutcl_tracking_v2` defaults, schedules daily cleanup + queue cron, sets an activation-redirect transient for the setup wizard. Deactivation clears both crons. WooCommerce HPOS (`custom_order_tables`) compatibility declared on `before_woocommerce_init`.

Major components: core bootstrap (`includes/class-clicutcl-core.php`); frontend attribution (`assets/js/clicutcl-attribution.js`); browser events (`assets/js/clicutcl-events.js`); consent/GTM modules (`includes/Modules/consent-mode/`, `includes/Modules/GTM/`, consent bridge/banner JS); canonical tracking pipeline (`includes/tracking/`: EventV2, translator, identity resolver, consent decision, dedup store, webhook auth); REST intake (`includes/api/class-tracking-controller.php`); server-side delivery (`includes/server-side/`: dispatcher, queue, adapters); WordPress integrations (`includes/integrations/`: form manager, WooCommerce); utilities/DB (`class-installer.php`, cleanup, privacy handler); admin (`class-admin.php`, unified settings app `assets/js/admin-settings-app.js`, logs list table, site health, diagnostics AJAX traits).

Legacy/compatibility surfaces: internal `clicutcl_tracking_v2` option name and older admin assets present but unused by the active settings screen. The legacy v1 log controller is absent; `clicutcl/v2` is the only registered REST namespace **[Confirmed]** (`CODE-MAP.md`, `PLUGIN-OVERVIEW.md`, `REST-API.md`).

### 4.2 Storage entities and options **[Confirmed]** (`DATA-MODEL.md`)

- **Options:** `clicutcl_attribution_settings`, `clicutcl_consent_mode`, `clicutcl_gtm`, `clicutcl_server_side`, `clicutcl_server_side_network`, `clicutcl_tracking_v2` (advanced flags, destinations `meta/google/linkedin/reddit/pinterest/tiktok`, provider secrets, lifecycle ingestion, security/diagnostics tuning). Operational keys: `clicutcl_last_error`, `clicutcl_dispatch_log`, DB-readiness flags (`clicutcl_db_ready*`, per-table ready/checked keys), `clicutcl_db_version` (schema version; `Installer::maybe_upgrade()` re-runs `dbDelta` until it matches `Installer::DB_VERSION`).
- **Tables:** `wp_clicutcl_events` (JSON-blob admin Logs / GDPR-export source; form submission payloads), `wp_clicutcl_queue` (retry rows: id, event_name, event_id, adapter, endpoint, payload, attempts, next_attempt_at, last_error, created_at), `wp_clicutcl_touch_events` (added 1.9.0; structured queryable touch log for the future Pro reporting layer; written from `Dispatcher::dispatch()` ahead of enablement gates; consent-gated via `Dispatcher::consent_allows()`; pseudonymous `visitor_id` = SHA-256 hashed email else session ID; 90-day default retention via `clicutcl_daily_cleanup`; exported/erased by `Privacy_Handler` matched on hashed email).
- **Woo order meta:** `_clicutcl_tracking_sent` (purchase dedup marker, written only after successful/skipped/confirmed-queued attempt), `_clicutcl_woo_trace_snapshot` (purchase + milestone traces: event_name, event_id, source_hook, attempted_at, payload snapshot, dispatch result summary), `_clicutcl_woo_milestone_sent_{event_name}`.
- **Client storage:** cookie `attribution` (legacy `ct_attribution` on older installs); localStorage TTL-bound mirror with explicit expiry tied to `cookie_days` (legacy mirrors without expiry discarded); consent cookie `ct_consent`; session/visitor fallbacks `ct_session_id`/`ct_visitor_id` (created only after marketing consent; removed on denial); structured session cookie `ct_session`; pending-capture buffer `ct_pending_v1` in sessionStorage (see Appendix A conflict on pre-consent buffering).
- **Transients:** `clicutcl_debug_until`, `clicutcl_dispatch_buffer`, `clicutcl_last_error`, `clicutcl_failure_telemetry`, `clicutcl_failure_flush_lock`, `clicutcl_health_check_result`, `clicutcl_queue_lock`, `clicutcl_v2_events_buffer`, plus dynamic families for rate limiting, token replay/nonce guards, dedup markers.
- **Secrets:** stored in `clicutcl_tracking_v2`; masked in admin responses; write-only updates; optional encryption at rest (`encrypt_secrets_at_rest` / `clicutcl_encrypt_settings_secrets`); inert-toggle admin notice when OpenSSL AES-256-GCM unavailable (1.8.10).

### 4.3 Routes **[Confirmed]** (`REST-API.md`)

Namespace `clicutcl/v2`, controller `includes/api/class-tracking-controller.php`:

| Route | Purpose | Auth |
|---|---|---|
| `POST /clicutcl/v2/events/batch` | Canonical browser event batches | Admin nonce (privileged flows) or signed client token (`X-Clicutcl-Token` / body `token`); browser collection must be enabled; body-size cap; rate limiting; ≤20 batch requests per page token by default; strict browser-event allowlist (`purchase`, `qualified_lead`, `client_won` rejected) |
| `POST /clicutcl/v2/attribution-token/sign` | Mint signed cross-domain token | Page client token |
| `POST /clicutcl/v2/attribution-token/verify` | Verify incoming token, normalize payload | Page client token required (enforced since 1.8.10) |
| `POST /clicutcl/v2/webhooks/{provider}` | Calendly / HubSpot / Typeform ingress | Native Typeform `Typeform-Signature` (base64 HMAC); native HubSpot `X-HubSpot-Signature` (SHA-256); ClickTrail timestamped HMAC for Calendly until native format verified; replay-window enforcement; raw-value constant-time `hash_equals`; secrets stored verbatim; atomic replay claim via `wp_cache_add()` with durable-transient fallback |
| `POST /clicutcl/v2/lifecycle/update` | CRM/backend lifecycle updates | Lifecycle token (`hash_equals`-gated); stages `lead`, `book_appointment`, `qualified_lead`, `client_won` |
| `GET /clicutcl/v2/diagnostics/delivery` | Delivery diagnostics | Admin capability via permission callback |
| `GET /clicutcl/v2/diagnostics/dedup` | Dedup diagnostics | Admin capability |

Consent-blocked webhook/lifecycle requests return HTTP success with `success:false, skipped:true` + reason (prevents provider retry storms) **[Confirmed]** (`REST-API.md`).

Admin AJAX actions: `clicutcl_get_admin_settings`, `clicutcl_save_admin_settings`, `clicutcl_sgtm_preview_check`, `clicutcl_test_endpoint`, `clicutcl_conflict_scan`, `clicutcl_export_settings_backup`, `clicutcl_import_settings_backup`, `clicutcl_lookup_woo_order_trace`, `clicutcl_toggle_debug`, `clicutcl_purge_tracking_data` **[Confirmed]** (`SETTINGS-AND-ADMIN.md`, `OPERATIONS-RUNBOOK.md`).

### 4.4 Hooks (public surface summary) **[Confirmed]** (`HOOKS-REFERENCE.md`)

Filters: loading (`clicutcl_should_load_events_js`), thank-you matchers (`clicutcl_thank_you_matchers`), iframe origins (`clicutcl_iframe_origin_allowlist`), consent defaults/regions (`clicutcl_consent_defaults`, `clicutcl_consent_region_defaults`), identity fields (`clicutcl_identity_fields_allowed`), token/TTL/rate/replay tuning (`clicutcl_v2_token_ttl`, `clicutcl_attribution_token_ttl`, `clicutcl_v2_allow_subdomain_tokens`, `clicutcl_v2_allowed_token_hosts`, `clicutcl_v2_rate_window`, `clicutcl_v2_rate_limit`, `clicutcl_v2_token_nonce_limit`), webhooks (`clicutcl_webhook_replay_window`, `clicutcl_webhook_replay_protection`, `clicutcl_external_provider_secret`, `clicutcl_external_provider_enabled`), lifecycle/secrets (`clicutcl_lifecycle_token`, `clicutcl_encrypt_settings_secrets`), proxies (`clicutcl_v2_trusted_proxies`, `clicutcl_trusted_proxies`), diagnostics buffers/telemetry (`clicutcl_v2_event_buffer_size`, `clicutcl_diag_*`, `clicutcl_failure_telemetry_*`), dedup/queue retention (`clicutcl_v2_dedup_ttl`, `clicutcl_queue_retention_days`), misc (`clicutcl_cookie_name`, `clicutcl_preserve_data_on_uninstall`), geo (`clicutcl_request_country_code`, `clicutcl_trust_geo_request_headers` — added 1.8.10), Gravity Forms (`clicutcl_gf_tracking_enabled`, `clicutcl_gf_channel_label`, `clicutcl_gf_merge_tag_value`, `clicutcl_gf_merge_tag_formatted_value`, `clicutcl_gf_merge_tag_default_value`). Action: `clicutcl_failure_telemetry_remote`. Woo-side action: `clicutcl_order_attribution_saved` (added 1.8.0).

GF channel labels are **stored data, deliberately not wrapped in `__()`** so cross-locale reporting stays consistent; localize display via `clicutcl_gf_channel_label` or the reporting layer, never the text domain **[Confirmed]** (`HOOKS-REFERENCE.md`).

### 4.5 Integrations and evidence boundary **[Confirmed]** (`INTEGRATIONS.md`, ledger)

Status vocabulary: **source-present/runtime-unverified**, **GTM-mediated**, **relay-only**, **webhook ingress**, **source connector/runtime-unverified**, **not observed/planned/unsupported**. The registry proves wiring and smoke ownership — not credentials, provider acceptance, consent transitions, retry safety, or deletion behavior.

- Delivery adapter keys (all source-present/runtime-unverified): `generic` (schema_version 1), `sgtm` (schema_version 1, `collector: sgtm`; preview SSRF hardening open), `meta_capi`, `google_ads`, `linkedin_capi`, `pinterest_capi`, `tiktok_events_api` (schema_version 2). Classes serialize canonical JSON to a configured endpoint; no provider auth/acceptance constructed. One selected adapter at a time; destination toggles are capability markers, not fan-out controls.
- Destinations: `meta`, `google`, `linkedin`, `reddit` (**relay_only**, no adapter key), `pinterest`, `tiktok`. Meta Pixel, Google tag/GA4, TikTok Pixel, LinkedIn Insight, Pinterest Tag, Reddit Pixel exist only through site-owned GTM containers; ClickTrail injects none of these SDKs.
- Forms (source connectors, runtime-unverified): CF7, Elementor Forms (Pro), Fluent, Gravity, Ninja, WPForms — three patterns: automatic hidden-field injection (CF7/Fluent), compatible hidden-field population (GF/WPForms), submission-hook/stored-attribution (Elementor via `elementor_pro/forms/new_record`; Ninja via submission extra data `extra.clicktrail_attribution` + detail UI).
- WooCommerce (runtime-unverified): order attribution, enriched purchase push, storefront events, milestones, traces, HPOS declaration.
- Webhook ingress (runtime-unverified): Calendly, HubSpot, Typeform.
- Consent sources (source-present; runtime precedence unverified): plugin banner, Cookiebot, OneTrust, Complianz, GTM, custom bridge. Teams should pick **one** consent source of truth.
- Geo inputs: no external geo-IP service by default; reads server-provided headers when available/trusted (`HTTP_CF_IPCOUNTRY`, `HTTP_X_COUNTRY_CODE`, `HTTP_GEOIP_COUNTRY_CODE`, `GEOIP_COUNTRY_CODE`, `HTTP_CF_REGION_CODE`) — untrusted by default since 1.8.10.

Registry-vocabulary tension: `config/feature-registry.json` labels platform adapters `support_level: native_delivery` while the ledger and INTEGRATIONS.md mandate `source-present/runtime-unverified` for public claims — flagged in Appendix A, **Decision needed**.

### 4.6 Permissions model **[Confirmed]** (`REST-API.md`, `SETTINGS-AND-ADMIN.md`, changelog 1.8.18)

- Admin screens/AJAX: `manage_options` (or equivalent capability checks); settings save/load and diagnostics handlers nonce-gated.
- REST: per-route permission callbacks — signed client tokens for browser/attribution-token routes (TTL, host allowlist, optional subdomain acceptance, nonce replay limits); CRM lifecycle token; webhook signature verification; admin capability for diagnostics GETs. Unit-covered by `tests/unit/RestAuthPermissionsTest.php` (1.8.18).
- GTM lead-magnet AJAX hardened with `manage_options` (1.8.10; class disabled at runtime and excluded from WP.org build since 1.8.8).

### 4.7 Consent and data boundaries **[Confirmed with open gates]** (`SECURITY-PRIVACY.md`)

- Consent Mode enable/disable; modes `strict`/`relaxed`/`geo`; CMP timeout; consent cookie metadata; normalization via `ct:consentResolved` with compatibility events for older listeners.
- Denied consent ⇒ attribution cookie + localStorage mirror cleared; browser identifiers removed; pending attribution removed. Prior `dataLayer` entries, durable rows, order metadata, queued deliveries, and third-party deliveries are **not automatically erased** — documented boundary, not a bug claim.
- Initial dispatch checks consent; **queue retries currently require a separate pre-send consent check that is not yet proven** — release gate.
- Dispatcher vs browser/core disagreement when Consent Mode is disabled and legacy `require_consent` saved — unresolved mismatch, release gate (post-1.8.8 default-off fix narrowed this; full reconciliation pending).
- Data minimization: IP anonymized at rest in the diagnostic events log via `wp_privacy_anonymize_ip()` (full IP still flows to server-side dispatch for CAPI match quality); remote failure telemetry aggregated and payload-free; identity exposure filterable via `clicutcl_identity_fields_allowed`.
- Eraser scope today: matching rows in `clicutcl_events`, `clicutcl_touch_events` (exact hashed-email match), `clicutcl_queue` (raw + SHA-256 email). **Not complete erasure proof**: legacy hashed-identity shapes, and no verified generic export/erase/uninstall coverage for Woo order-meta trace/attribution keys — release gate.
- Session/visitor identifiers created only after marketing consent; removed on denial/withdrawal.

### 4.8 Queue and cron **[Confirmed]** (`EVENT-PIPELINE.md`, `OPERATIONS-RUNBOOK.md`, changelog 1.8.11)

- Cron hooks: `clicutcl_daily_cleanup` (daily), `clicutcl_dispatch_queue` (every 5 minutes).
- Queue: batch 10 due rows/run; max 5 attempts; exponential backoff capped at 1 hour; lock transient `clicutcl_queue_lock`; Action Scheduler used when available (`as_schedule_single_action` detected; no Composer bundling to avoid conflicts with Woo's copy) with WP-cron fallback; `uninstall.php` cancels AS actions guarded by the same check.
- Retention: events per attribution retention days; touch events same (default 90); queue rows per `clicutcl_queue_retention_days` filter (default 7 days per runbook).
- Retry path (`Queue::process_row()`) calls the adapter directly and never re-enters `Dispatcher::dispatch()` — no duplicate touch-event rows **[Confirmed]** (changelog 1.9.0).
- Woo sent markers written only after successful/skipped/confirmed-queued attempts; error-without-queue-row ⇒ marker unset ⇒ original hook path can retry **[Confirmed]** (`OPERATIONS-RUNBOOK.md`).

### 4.9 Diagnostics, backups, deployment, tests **[Confirmed]** (`OPERATIONS-RUNBOOK.md`, `FEATURE-TEST-MATRIX.md`, `CODE-QUALITY.md`)

- Diagnostics: endpoint test (`Dispatcher::health_check()`), conflict scan (cache plugins, call-tracking scripts informational, Woo storefront without Woo, sGTM misconfigurations, adapter/destination mismatches, GTM overlap, delivery-without-endpoint), settings backup export/restore (five option stores; restore runs live-admin sanitizers), Woo order trace lookup (stored snapshots + live queue state; consent-suppression explanation since 1.8.16), debug window (`clicutcl_debug_until`), queue backlog, failure telemetry, recent dispatches, local purge.
- Backups: export contains **unmasked secrets in cleartext** (capability/nonce-gated; on-screen warning since 1.8.14) — handle like a credentials file.
- Deployment: `.distignore` governs WP.org SVN build; 1.8.14 restored `config/feature-registry.json` to the package after it was silently missing from every shipped release up to and including WP.org tag `1.8.13` (adapter dropdown/destination toggles rendered empty; dispatcher fell back to Generic Collector).
- Tests/CI: PHPCS (php-lint.yml), PHPUnit (phpunit.yml), CodeQL, Dependency Review; `composer phpcompat` (PHPCompatibilityWP, testVersion 8.1−) since 1.8.5; `node --check` on JS; `npm run smoke` structural harness over `config/feature-registry.json` + `config/feature-test-matrix.json` via `tools/qa/smoke.js` (registry/docs/code/smoke alignment; consent-bridge JS boundary checks; ROADMAP cites 37+ registry-backed IDs). Unit tests cover REST auth boundary and pure queue backoff math (1.8.18) and `Touch_Events_Store::build_row()` (1.9.0); DB-backed queue paths and live Woo/form/provider behavior remain manual-QA territory.
- Quality gates before tagging (ROADMAP): PHPCS zero warnings; PHPUnit green on PHP 8.1/8.2/8.3; JS syntax; smoke green; security checks (nonce/capability/sanitization/debug-gating/escaping); data-accuracy manual checks (cookie writes, Woo order meta `_clicutcl_ft_source`, form entry meta); UI/messaging checks (no internal key names or "Tracking v2" in labels).

### 4.10 Semantic contract

Exact meanings, creators, required data, reversibility, legal next states, actor, and audit requirement for key events/statuses. "Legal next states" = the only downstream states permitted by current design. Where a generic concept is not implemented, it is labeled **Future / Decision needed** — never described as shipped.

| Event / Status | Exact meaning | Creator | Required data | Reversibility | Legal next states | Actor | Audit requirement |
|---|---|---|---|---|---|---|---|
| Marketing consent **granted** | A resolved decision permitting attribution persistence, browser events, and dispatch for this visitor/request | Consent bridge/CMP resolution normalized via `ct:consentResolved` | Consent categories (analytics/marketing preserved), consent-cookie name, CMP source | Reversible by withdrawal (local capture cleared on denial) | Capture persists; events push; dispatch allowed; touch-event rows written | Site visitor (via CMP/plugin banner) | Consent snapshot stored at Woo checkout (`CONSENT_META_KEY`); consent state observable in Diagnostics |
| Marketing consent **denied / withdrawn** | Decision blocking capture persistence and dispatch; triggers local cleanup | Same as above | Same | Terminal for local capture until re-grant | Local cookie/mirror/identifiers cleared; **prior dataLayer entries, durable rows, order meta, queued sends, third-party deliveries are NOT auto-erased** (documented boundary) | Site visitor | Denial must be observable; queued-send blocking after withdrawal is a **Blocked** release gate |
| Consent **unresolved** (required, no decision) | No persistent capture buffering occurs while consent is required and unresolved | Consent bridge | Mode setting, CMP timeout | N/A | No attribution cookie write; no persistent pending buffer (see Appendix A conflict on `ct_pending_v1`) | Site visitor | Behavior must match `DATA-MODEL.md`; runtime-unverified |
| Browser canonical event **accepted** | An allowlisted browser event entered the canonical pipeline via `/events/batch` | `clicutcl-events.js` → Tracking_Controller | Signed client token, canonical payload, enabled browser collection | Not reversible post-intake; dedup applies downstream | Normalization → consent/identity rules → translation → dispatch/queue | Visitor browser | Debug-window intake buffer (`clicutcl_v2_events_buffer`); rate/nonce limits enforced |
| `purchase` | A completed WooCommerce order reported as a conversion; **trusted server route only** (rejected on browser batch route) | Woo integration (thank-you/dispatch path) | Order ID, amount, currency, attribution shape, consent snapshot, identity resolution | Dedup marker `_clicutcl_tracking_sent` prevents resend; marker written only after success/skip/confirmed-queue | Dispatched / queued / failed / skipped; milestone events may follow | Server (Woo hooks) | Trace snapshot `_clicutcl_woo_trace_snapshot` on order; Diagnostics Woo lookup |
| `order_paid` / `order_refunded` / `order_cancelled` | Post-purchase order-status milestones reusing the purchase payload builder with deterministic event IDs | Woo order-status hooks | Order context, milestone name | Per-milestone marker `_clicutcl_woo_milestone_sent_{name}` after success/skip/confirmed-queue | Same dispatch states as purchase | Server (status change/admin/cron) | Before/after trace snapshots on order; queue retry is second line of defense |
| `lead` (form) | A form submission carrying attribution, logged and optionally dispatched | Form adapters via shared pipeline | Submitted `ct_*` fields or fallback attribution, consent-aware identity | Entry-meta values persist in the form plugin's own store (separate privacy boundary) | Logged to events table; dispatched/queued/skipped | Visitor (submitter) | Events-table row incl. IP-anonymized identity copy; form-plugin retention is out of ClickTrail's purge scope (boundary) |
| `book_appointment` | Booking confirmation event; allowed as browser-confirmed event and as lifecycle stage | Browser runtime or lifecycle caller | Token-authenticated payload | As per route | Dispatch path | Visitor or CRM/backend | Route-appropriate auth audit |
| `qualified_lead` / `client_won` | CRM/backend-reported lifecycle progress; **lifecycle route only** (rejected on browser batch route) | External system via `POST /lifecycle/update` | Lifecycle token, validated stage | Not reversible via ClickTrail | Canonical event → dispatcher | CRM/backend (token holder) | Token binding/identity/consent provenance tests are a **Blocked** gate |
| Webhook event **received** | Provider-originated lead/booking translated into canonical pipeline | Webhook adapters (Calendly/HubSpot/Typeform) | Valid signature, replay-window freshness, provider enablement + secret | Not reversible; duplicates blocked by replay protection | Canonical event → dispatcher; consent-blocked ⇒ HTTP success + `skipped:true` | Provider (authenticated request) | Signature/replay controls unit-covered; provider timestamp/identity-minimization proofs are **Blocked** gates |
| Delivery attempt **success** | Adapter POST accepted by endpoint; dedup marker stored; success logged | Dispatcher/adapter | Endpoint, adapter, canonical payload, consent pass | Dedup prevents duplicate send | Terminal (success) | Server | Dispatch buffer + failure telemetry aggregates |
| Delivery attempt **skipped** | Consent or environment gate prevented send; counted, not retried as failure | Dispatcher | Consent decision / environment | N/A | Terminal (skipped) | Server | Skips surfaced in diagnostics; consent-provenance tests pending (**Blocked**) |
| Delivery attempt **queued** | Failure eligible for retry; row inserted with attempts/backoff | Dispatcher → Queue | event_name/event_id/adapter/endpoint/payload | Row removable via retention/purge | Retry (≤5) → success or exhausted-failure | Server | Queue backlog visible; retry-after-withdrawal consent recheck is a **Blocked** gate |
| Delivery attempt **failed (exhausted)** | Max attempts reached; row retained per retention policy | Queue | Attempt history, last_error | Purgeable | Terminal (failure); Pro "resubmit" is **Future** | Server | Failure telemetry (aggregated hourly counts, payload-free) |
| Touch-event row **written** | Structured queryable row recorded for every event source from `Dispatcher::dispatch()`, ahead of enablement gates; skipped entirely when marketing consent required-and-not-granted | `Touch_Events_Store::record()` via Dispatcher | Canonical event fields mapped to columns (both Woo nested and flat `ft_*`/`lt_*` shapes) | Deleted by retention (90-day default) and GDPR eraser (hashed-email match); session-ID-only rows unreachable by email-keyed erasure (inherent limitation, documented) | Read by future Pro reporting (**Future**); no free-tier UI reads it yet | Server | Export/erase paginated independently of events table; covered by `TouchEventsStoreTest` (pure slice) |
| Attribution token **signed / verified** | Cross-domain continuity credential minted and later verified on the signing install | `/attribution-token/sign`, `/verify` | Page client token on both routes (verify enforced since 1.8.10); host allowlist; TTL | Token expires (filterable TTL) | Decorated links carry token; verify normalizes allowed payload | Visitor browser + server | Nonce replay limits; allowed-host checks |
| Generic typed event (`touch`/`conversion`/`renewal`/`call` per roadmap schema) | **Not implemented as such** — the shipped schema uses `event_name` + `funnel_stage` (`top`/`mid`/`bottom`/`unknown`) instead; a `call` event type does not exist | — (proposed in `ROADMAP.md`) | — | — | — | — | Naming/type-model reconciliation is **Decision needed**; **Future** |

---

## Section 5 — Security, Safety, Privacy, and Compliance

### 5.1 Posture statement **[Confirmed]**

ClickTrail contains consent controls for attribution and event handling; the 2026-08-19 audit found unresolved edge cases across legacy consent state, revocation, queues, WooCommerce, forms, and dataLayer output (`README.en.md`, `SECURITY-PRIVACY.md`). Until the listed gates pass in PHP/WordPress/browser/provider tests, the correct public vocabulary is `source-present / runtime-unverified` — **never** `privacy compliant`, `secure`, `guaranteed`, or `production-ready`. **No legal compliance or privacy certification is asserted in this document; legal verification is Decision needed.**

### 5.2 Current security-status blockers (release gates) **[Confirmed as gates]** (`SECURITY-PRIVACY.md`)

1. Consent Mode disabled + legacy `require_consent` can disagree between browser/core and `Dispatcher`.
2. Stale plugin consent cookies can outrank a current CMP decision; cross-tab synchronization and revocation incomplete.
3. Form/Woo posted attribution, Woo order metadata, purchase dataLayer output, and queued retries need independent consent/revocation tests.
4. Woo traces can retain identity metadata; purge/export/erase/uninstall does not yet cover every Woo order-meta key.
5. Browser conversion tokens and external form messages do not prove a real action or provider confirmation.
6. Webhook identity/timestamp/replay semantics and sGTM preview SSRF still require hardening.

### 5.3 Kill criteria (do not publish a claim/release if any advertised path…) **[Confirmed]** (`RELEASE-PHASING-AND-INTEGRATION-DOCS.md`)

…sends/enqueues data after consent withdrawal or bypasses the authoritative consent source; retries without a consent check; mislabels booking/purchase success; creates an unbound conversion; retains identity/order data outside documented purge scope; accepts replayed/unbounded provider events; reaches private/internal URLs via sGTM preview; lacks runtime evidence beyond registry presence; or appears supported/native after qualifier-stripping snippets.

### 5.4 Safety register

Format: Risk → Prevention → Detection → Response → Responsible person. No named individuals appear in evidence; responsible persons are role placeholders marked **Decision needed**.

| ID | Risk | Prevention | Detection | Response | Responsible person |
|---|---|---|---|---|---|
| SR-01 | Data sent/enqueued after consent withdrawal (queue retry lacks proven pre-send consent recheck) | Authoritative single consent service; re-check immediately before adapter invocation (L1 scope) | Zero-send assertions in consent-withdrawal-before-retry tests | Block release until pass; kill criterion active | Release owner — **Decision needed** |
| SR-02 | sGTM preview reaching private/internal URLs (SSRF) | `wp_http_validate_url()` + scheme allowlist on save; `reject_unsafe_urls => true` at request time (1.8.10) | Negative-path fixtures for private-network URLs; preview probe checks | Complete preview SSRF hardening before L2 exit; do not describe preview as secure-by-default | Delivery maintainer — **Decision needed** |
| SR-03 | Identity/order data retained outside documented purge/export/erase scope (Woo order-meta keys) | Complete ClickTrail-owned key inventory; retention independent of cookie duration; cleanup continues if one table unavailable | Export/erase/uninstall drills covering every key | Extend `Privacy_Handler`/`uninstall.php`; block release until drills pass | Privacy owner — **Decision needed** |
| SR-04 | Settings-backup export leaks cleartext secrets (webhook secrets, lifecycle token) even with encryption on | Capability + nonce gating; on-screen warning (1.8.14); docs guidance | Code review; export round-trip QA | Treat downloaded file as credentials (secrets manager/encrypted storage; never commit); consider masked-export option — **Decision needed** | Admin-tooling owner — **Decision needed** |
| SR-05 | Stale plugin consent cookie outranks current CMP decision; cross-tab desync | Single consent authority; CMP precedence rules (L1) | Cross-tab, stale-cookie, revocation browser tests | Remediate in L1; until then document boundary | Consent owner — **Decision needed** |
| SR-06 | Webhook spoofing/replay | Native Typeform/HubSpot signatures; Calendly timestamped HMAC until native verified; raw-value constant-time `hash_equals`; verbatim secret storage; replay window; atomic `wp_cache_add()` claim with durable fallback | `BoundarySecurityTest`; replay fixtures | Rotate provider secrets; reject malformed signatures (exactly-64-hex rule) | Integrations owner — **Decision needed** |
| SR-07 | Concurrent-request rate-limit race allowing bursts | Atomic `wp_cache_add()`/`wp_cache_incr()` under persistent object cache (1.8.15) | Unit/concurrency tests; telemetry | Transient fallback acknowledged as best-effort; require object cache for strict limits | API owner — **Decision needed** |
| SR-08 | Spoofed client geo headers flipping region-scoped consent | Headers untrusted by default; unknown country fails safe to requiring consent; opt-in `clicutcl_trust_geo_request_headers`; authoritative `clicutcl_request_country_code` filter | Consent-resolution tests per mode | Sites relying on headers must set a filter (breaking-change note, 1.8.10) | Consent owner — **Decision needed** |
| SR-09 | Accidental dispatch from cloned dev/staging environments | Dispatch blocked by default in `local`/`development`; override only via `clicutcl_dispatch_in_environment` | Environment preflight in dispatcher | Conscious override with documented intent | Ops owner — **Decision needed** |
| SR-10 | Public claims outrunning evidence (marketing/compliance risk) | Ledger-gated claims; kill criteria; status vocabulary mandatory | Docs QA, link/JSON validation, snippet review | Revert offending copy; L0 gate blocks publication | Docs owner — **Decision needed** |
| SR-11 | Silent plaintext secret storage when encryption unsupported | Admin notice when AES-256-GCM unavailable (1.8.10) | Notice visibility check on install | Enable OpenSSL or accept plaintext knowingly | Security owner — **Decision needed** |
| SR-12 | Shipped package missing runtime-critical config (recurrence of 1.8.14 `.distignore` defect) | `config/` shipped again; packaging checks | Post-build zip inspection; adapter dropdown/destination list render check | Restore file; hotfix release | Release owner — **Decision needed** |
| SR-13 | Unsubstituted ad-platform macros polluting attribution/destinations | `{{...}}` regex rejection symmetric client+server (1.8.8) | Capture tests with macro URLs | Values dropped at both capture paths | Capture owner — **Decision needed** |

### 5.5 Compliance boundaries **[Decision needed throughout]**

- GDPR export/erase exists for events, touch-events, and queue tables with documented inherent limitations (session-ID-only visitors unreachable by email-keyed erasure) **[Confirmed as behavior; completeness not certified]**.
- Woo order-meta export/erase/uninstall coverage incomplete — **Blocked** gate.
- No assertion is made about GDPR/CCPA/ePrivacy compliance, data-processing agreements, or certifications. Legal review of consent modes, retention defaults, and cross-border delivery is **Decision needed**.

---

## Section 6 — Plans, Costs, and Business Specifications

### 6.1 Distribution and licensing **[Confirmed]**

Free, open-source plugin under GPL-2.0-or-later, distributed via GitHub releases; WordPress.org copy maintained in `readme.txt` with stable tag lagging per a 3-version soak buffer (policy documented in `RELEASING.md`, which was not supplied — **Decision needed** to verify the policy text). A Pro tier is planned in `ROADMAP.md` (foundation → features ordering) but nothing Pro is shipped or priced in evidence.

### 6.2 Pricing and quotas **[Decision needed — nothing invented]**

No confirmed public pricing, quota, metering, or packaging information exists in the supplied evidence. Every commercial number below is recorded as Unknown/not priced rather than invented.

| Item | Amount | Currency | Period | Tax treatment | Confidence | Source | Date |
|---|---|---|---|---|---|---|---|
| Free plugin price | Not priced (free) | n/a | n/a | n/a | Confirmed (GPL distribution model) | `README.en.md`, `clicutcl.php` | 2026-08-22 |
| Pro tier price | Unknown — not priced | Unknown | Unknown | Unknown — Decision needed | No evidence | Absent from all supplied files | 2026-08-22 |
| Usage quotas / event volume limits | Unknown — none documented | n/a | n/a | n/a | No evidence | Absent | 2026-08-22 |
| Setup cost (one-time) | Placeholder — Decision needed | TBD | One-time | TBD | None | — | 2026-08-22 |
| Recurring cost (hosting/infra/licenses) | Placeholder — Decision needed | TBD | Monthly/annual | TBD | None | — | 2026-08-22 |
| Variable cost (per-event/per-delivery) | Placeholder — Decision needed | TBD | Per unit | TBD | None | — | 2026-08-22 |
| Support cost model | Placeholder — Decision needed | TBD | Ongoing | TBD | None | — | 2026-08-22 |
| Margin model | Placeholder — Decision needed | TBD | — | TBD | None | — | 2026-08-22 |

### 6.3 Business constraints from evidence **[Confirmed]**

- Gating delivery adapters on Pro would create a compliance liability — explicitly avoided (`ROADMAP.md`).
- Consulting-first operating model: map frontend/hook/consent/storage/destination per client, run synthetic traces, ship client-safe handoffs; promote capabilities only on repeated paid demand (`COMPETITOR-ROADMAP-2026-08-22.md`).
- Competitor intelligence (2026-08-22 snapshot: 30 plugin records, 61 reviews, 116 support topics) is planning evidence, not release approval; P0 = consent/identity/truthful delivery, P1 = compatibility evidence and consulting adapters, P2 = optional breadth only after paid demand.

---

## Section 7 — Versions, Roadmap, and Spec Updates

### 7.1 Current production / source / packaging status **[Confirmed]**

| Surface | Value | State |
|---|---|---|
| Plugin header version | 1.9.0 | Confirmed (`clicutcl.php`) |
| Docs/source baseline | 1.9.0 @ `a45aa9e` (audited 2026-08-19) | Confirmed (`docs/README.md`) |
| Latest tagged release with details | 1.9.0 (GitHub only) | Confirmed (`changelog.txt`) |
| WordPress.org stable tag | 1.8.13 (3-version buffer; GitHub-only shipping for 1.8.14→1.9.0) | Confirmed (changelog notes) |
| Release-metadata alignment | README, WP.org stable tag, and generated release artifacts still need alignment before next package | Confirmed (`README.en.md`) |
| Current phase | Truth-containment documentation only (L0); no runtime remediation claimed | Confirmed |
| Working tree | Branch `docs/integration-evidence-boundaries` @ `c9dedab`; uncommitted files not described as shipped | Confirmed (context) |

### 7.2 Open decisions **[all Decision needed]**

1. Assign named owners to every master-sheet row and safety-register responsibility.
2. Reconcile pre-consent pending-buffer behavior (`ct_pending_v1` — Appendix A-2).
3. Align `feature-registry.json` `support_level` vocabulary with ledger statuses (Appendix A-3).
4. Confirm `RELEASING.md` 3-version buffer policy text and stable-tag promotion timing.
5. Pro pricing, packaging, quotas, and margin model.
6. Legal/compliance verification (GDPR et al.) — no certification asserted.
7. Native Reddit adapter: build or permanently keep relay-only.
8. Masked (secret-free) settings-backup export option.
9. Calendly native signing-format verification.
10. Generic event-type model naming (roadmap `touch/conversion/renewal/call` vs shipped `event_name`+`funnel_stage`).
11. Refresh stale doc baselines (`PLUGIN-OVERVIEW.md` cites 1.8.5/1.7.0; `REST-API.md` 1.8.14; several guides pre-1.9.0).

### 7.3 Blocked release gates **[Blocked]** (from §5.2 + ledger known-limits)

Consent-withdrawal-before-queue-retry zero-send proof · Woo order-meta export/erase/uninstall coverage · sGTM preview SSRF hardening · stale-consent precedence/cross-tab revocation · browser conversion-token action proof · webhook identity/timestamp/replay contracts · provider contract fixtures + staged delivery per adapter (before any `native`/turnkey claim) · Dispatcher `require_consent` mismatch reconciliation · public claims of privacy compliance / complete deletion / guaranteed delivery / native Reddit / secure sGTM preview / reliable booking-purchase behavior.

### 7.4 Migration, rollback, and breaking-change rules **[Confirmed unless noted]**

- **Schema upgrades:** `DB_VERSION` bumps drive `Installer::maybe_upgrade()` `dbDelta` on every boot (1→2 earlier; 2→3 in 1.9.0 for `clicutcl_touch_events`); readiness flags per table.
- **Breaking changes on record:** (a) 1.8.10 — client geo headers no longer trusted by default; sites must set `clicutcl_request_country_code` or `clicutcl_trust_geo_request_headers`; (b) 1.8.8 — consent gate no longer implicitly ON when Consent Mode disabled; (c) legacy v1 REST API removed (verified against the current tree; reintroduction would require an earlier tag); (d) 1.8.14 — packaging fix restoring `config/feature-registry.json` (behavioral change for WP.org installs that had empty adapter UI).
- **Rollback:** restore previous release tag; options/tables persist; uninstall is the destructive path (drops tables/options/transients/cron unless `clicutcl_preserve_data_on_uninstall`).
- **Uninstall:** removes options, clears scheduled hooks and transients, drops queue/events/touch-events tables by default; preservation override filter respected.
- **Compat policy:** WP 6.5+/PHP 8.1+ enforced; WC 10.4.2+ required for WP 7.0; HPOS declared; `phpcompat` static deprecation net paired with real runtime smoke before publishing.

### 7.5 Twelve-month roadmap (M1–M12)

Calendar mapping follows the product-facing feature and code sequence in `ROADMAP.md`; release phasing and evidence promotion remain governed by `RELEASE-PHASING-AND-INTEGRATION-DOCS.md`. Month labels are planning assumptions. Nothing here is shipped merely because its source exists, and each item remains gated on the stated proof.

| Month | Feature/code lane | Objective | Dependencies | Exit proof (gate) | State | Cost / operating impact |
|---|---|---|---|---|---|---|
| M1 (Sep 2026) | UTM Identify contract | Freeze the pure readiness analyzer schema, source precedence, click-ID map, referrer evidence, ambiguity behavior, and privacy boundary | Code review and focused fixtures | Ambiguous multi-platform signals suppress deterministic suggestions; precedence and redaction tests pass; no runtime/UI claim | Source candidate validated; no shipped claim | Low; focused code review and tests |
| M2 (Oct 2026) | Attribution Readiness Diagnostics v1 | Wire the analyzer into a privileged, read-only Diagnostics endpoint and panel | M1 contract | Permission/nonce/bounds tests; versioned allowlisted output; no raw click-ID retention or delivery claim | Source present / runtime unverified | Low-medium; admin and test work |
| M3 (Nov 2026) | Deterministic Suggestions v1 | Suggest only `utm_source` from one unambiguous recognized platform, with bounded request-scoped aliases | M2 diagnostics contract | Existing source is never overwritten; multiple-platform signals suppress suggestions; no medium/campaign invention | Source present / runtime unverified; promotion blocked on staging | Low-medium; policy and UI tests |
| M4 (Dec 2026) | UTM hygiene and contract checks | Detect macro/empty/conflict states and provide a copy-only test URL without mutating live URLs | M3 policy | URL-builder safety fixtures; source aliases bounded; browser/PHP parity and WordPress/browser proof | Source present / runtime unverified; promotion blocked on parity and staging | Medium; browser/PHP parity work |
| M5 (Jan 2027) | Form readiness diagnostics | Compare expected, submitted, and named storage-surface evidence across automatic-hidden, matching-field, and hook-storage form patterns | M4 proof; form contract decision; pinned adapter fixtures | Six adapter fixtures, hook/cache/AJAX evidence, privacy-safe output, no unsupported builder claim | Source comparator + six synthetic fixtures validated locally; runtime/admin/provider proof Future | Medium; form-plugin staging resources needed |
| M6 (Feb 2027) | Woo conversion readiness | Explain source evidence, consent snapshot, order status, HPOS, event ID, and dedup state | M5 evidence vocabulary; Woo staging | Granted/denied checkout, reload, status, refund, currency/value, HPOS, and duplicate fixtures | M6-A validated; M6-B classic/HPOS runtime reproduced; refund trace and HPOS admin fixed; consent-marker blocker remains | Medium; consent authority and remaining Woo gates required |
| M7 (Mar 2027) | Consent foundation release | Use one authoritative consent decision across capture, persistence, forms/Woo, browser events, and dispatch; re-check before retry; complete owned-data erase/purge | M5–M6 diagnostic evidence; privacy owner | Live grant/deny/stale-cookie/revocation/cross-tab/queued-retry/erasure drills; no compliance claim without legal review | M7-A validated; M7-B Woo v1 snapshots passed classic/HPOS staging; browser/retry/erasure/legal proof Future | High; browser/CMP/privacy testing |
| M8 (Apr 2027) | Delivery integrity release | Bind browser conversions to actions; bound payloads; harden replay, retries, dedup, and sGTM preview target validation | M7 consent authority | Negative fixtures for replay, timeout, 429/5xx, malformed response, duplicate, purge, and private targets | Future | Medium-high; Delivery remains default-off |
| M9 (May 2027) | Generic collector contract | Promote one delivery path through canonical mapping, auth, consent, redaction, retry, idempotency, and response fixtures | M8 integrity gates | Versioned fixture plus staged endpoint evidence; public wording updated only for the tested contract | Future / cost decision needed | Provider test cost TBD |
| M10 (Jun 2027) | sGTM staging contract | Verify ClickTrail → staging tagging server → owned destination without becoming a host | M9 contract pattern; staging infrastructure | DNS/SSL/CORS/CSP/auth/consent/dedup/retry and response evidence | Future / infrastructure decision needed | Staging cost TBD |
| M11 (Jul 2027) | Receipts and customer attribution foundation | Add delivery receipts and Woo customer-level first-touch linkage; add other provider contracts only where demand repeats | M9–M10 evidence; touch-events table | Receipt rows, retention/erase behavior, and renewal attribution verified; no Pro dashboard claim | Future | Dev plus monitored storage growth |
| M12 (Aug 2027) | Read-only reporting/re-audit decision | Re-audit touch-event quality and decide Pro report scope and which provider/reach pages may publish | All prior evidence gates | Annual evidence report and explicit publish/no-publish decisions; no dashboard launch without data and ownership proof | Future / decision needed | Audit effort; decision record |

**Roadmap alignment notes:** the touch-events table is a shipped storage prerequisite, not dashboard approval. M12 decides reporting scope after consent, erasure, staging, and data-quality gates. Call tracking remains third-party conflict diagnostics only; ClickTrail does not build DNI or call-attribution intake. Multi-touch model-selection UI stays Pro if reporting is approved; collection stays free. Items in "Not Building" are excluded. Competitor-derived candidates (P0/P1/P2 in `COMPETITOR-ROADMAP-2026-08-22.md`) fold into L1/L2 scope; P2 breadth requires repeated paid demand.

### 7.6 Version and change table (every changelog heading)

Shipped-to: G = GitHub release; W = WordPress.org stable tag. This table is the current specification index; the complete, verbatim release-history record for all 50 changelog headings is maintained in [MASTER-SPECIFICATION-CHANGE-RECORD.md](MASTER-SPECIFICATION-CHANGE-RECORD.md).

| Version | Shipped to | Change type | Concise entry | Status | State |
|---|---|---|---|---|---|
| Unreleased | — (working tree) | Documentation-only | Machine-readable integration capability ledger added; integration reference, readmes, security/privacy notes, release-phasing plan synchronized. Provider-status correction: platform-named server classes documented as source-present/configured-endpoint adapters (runtime-unverified); GTM-mediated tags separate; Reddit relay-only. Release gate: claims of privacy compliance, complete deletion, guaranteed delivery, native Reddit, secure sGTM preview, reliable booking/purchase blocked pending evidence. No runtime behavior change. | Proposed | Confirmed |
| 1.9.0 | G | Runtime (feature) | New permanent `{prefix}clicutcl_touch_events` table — structured queryable touch log, the Pro-reporting foundation; written from `Dispatcher::dispatch()` ahead of enablement gates; consent-gated; pseudonymous visitor_id (SHA-256 email else session); 90-day retention; GDPR export/erase; DB_VERSION 2→3; uninstall drops it; unit tests for pure `build_row()`; docs updated. Minor bump per versioning policy. Ships GitHub only. | Completed | Confirmed |
| 1.8.19 | G | Runtime (maintenance) | Removed dead Settings API code from `class-admin.php` (2276→1383 lines); kept sanitizers backing the live unified save path. No functional/user-facing change. | Completed | Confirmed |
| 1.8.18 | G | Tests only | Added `RestAuthPermissionsTest` (REST permission callbacks, token cases, sign/verify round trip) and `QueueRetryTest` (backoff math, payload redaction); bootstrap stubs extended; no production code change. | Completed | Confirmed |
| 1.8.17 | G | Runtime (diagnostics UX) | Conflict Scan detects active call-tracking scripts (CallRail/CTM/WhatConverts/Retreaver/Infinity) as informational notes; stale Conflict Scan description corrected. | Completed | Confirmed |
| 1.8.16 | G | Runtime (diagnostics UX) | Woo order trace lookup explains consent-suppressed attribution using the checkout-time consent snapshot (closes issue #44); read-only change. | Completed | Confirmed |
| 1.8.15 | G | Runtime (security hardening) | Atomic per-IP rate limiter under persistent object cache (`wp_cache_add`/`wp_cache_incr`); 255-char length cap on classic attribution capture values. | Completed | Confirmed |
| 1.8.14 | G | Runtime (packaging/security/docs) | Fixed `config/feature-registry.json` missing from every shipped release incl. WP.org tag 1.8.13 (adapter dropdown/destinations were empty; dispatcher silently fell back to Generic Collector); explicit unmasked-secrets warning on settings-backup export; REST-API.md stale legacy-controller reference corrected; local-only dev tooling removed. | Completed | Confirmed |
| 1.8.13 | G / W (stable) | Runtime (security/privacy) | Browser intake canonical allowlist (purchase/qualified_lead/client_won restricted to trusted routes; 20-request nonce limit per page token); consent correctness + withdrawal cleanup (categories preserved, lazy identifiers, denial cleanup); attribution reliability (cookie encoding/budget, token sent separately on verify); native Typeform/HubSpot webhook signatures, HubSpot arrays itemized, truthful `skipped` responses; retry payload strips raw IP/UA; dead tracking-v2 AJAX surfaces removed; boundary tests added. | Completed | Confirmed |
| 1.8.12 | G | UX copy | Consent-source help text explains sessionStorage buffering preserving landing-page UTMs across later-page banner acceptance (English fallback on de_DE/pt_BR until translations regenerate). | Completed | Confirmed |
| 1.8.11 | G | Maintenance/docs | Removed unused locals in setup-checklist builder; `ROADMAP.md` reconciled against shipped code (GF/WPForms diagnostic, cross-domain checklist, AS migration, call-tracking scan implemented). No functional change. | Completed | Confirmed |
| 1.8.10 | G | Runtime (security/privacy; breaking) | SSRF: endpoint validated (`wp_http_validate_url`, http(s)-only, private/loopback/link-local/port/userinfo rejected) + `reject_unsafe_urls` in all seven adapters; webhook signature read raw + 64-hex validation, secrets stored verbatim, atomic replay claim; `/attribution-token/verify` now requires client token; IP anonymized at rest in events log; queue eraser deletes by raw+hashed email; **geo headers untrusted by default** (breaking — filters added); GF merge tags always escaped; inert secret-encryption admin notice; GTM lead-magnet capability checks. | Completed | Confirmed |
| 1.8.9 | G | i18n | German script-translation JSON added so de_DE admin UI renders translated (200+ entries). | Completed | Confirmed |
| 1.8.8 | G | Runtime (security/fix/housekeeping) | Ad-platform `{{macro}}` rejection symmetric client/server; consent gate no longer defaults ON when Consent Mode disabled (legacy `require_consent` implicit-true removed); GTM Starter Kit banner disabled at runtime + distignored; `.distignore` cleanup. Sourced from stashed WIP triage. | Completed | Confirmed |
| 1.8.7 | G | i18n | pt_BR translations regenerated (533 translated + 81 fuzzy); de_DE locale added (614 strings, formal register); reproducible POT pipeline committed. | Completed | Confirmed |
| 1.8.6 | G | Runtime (admin UX) | Third-party promotional banners suppressed on all ClickTrail admin screens (global suppressor replacing wizard-only version); admin CSS consistency pass (design tokens, spacing, duplicate block removed). | Completed | Confirmed |
| 1.8.5 | G | Runtime (compat/tooling) | WordPress 7.0 compatibility declared (tested-up-to; WC ≥10.4.2 header); dead pre-6.3 script-args fallback removed; `phpcompat` CI workflow added (PHPCompatibilityWP, 8.1−). | Completed | Confirmed |
| 1.8.2 | G | UI polish | Wizard button/icon alignment fixes; custom monochrome SVG sidebar icon. | Completed | Confirmed |
| 1.8.1 | G | Lint only | PHPCS false-positive scoping (`phpcs:disable/enable`, scoped ignore) in Woo integration + core; no logic change. | Completed | Confirmed |
| 1.8.0 | G | Runtime (features) | GTM Starter Kit lead magnet (dismissible banner, Brevo opt-in subscribe, signed 10-minute download link, 16 DL variables/5 constants/7 triggers/11 tags/Consent Mode v2 defaults); `clicutcl_order_attribution_saved` action added. | Completed | Confirmed |
| 1.7.9 | G | Runtime (cross-domain UX) | Sibling-subdomain passthrough via registrable-domain (eTLD+1) comparison incl. 2-part TLDs; Woo checkout-subdomain checklist attention state; misleading cross-domain checklist copy corrected. | Completed | Confirmed |
| 1.7.8 | — | — | Heading present in `changelog.txt` index; detailed body not included in supplied evidence — consult `changelog.txt`. | Completed (existence Confirmed; content not in evidence) | Confirmed |
| 1.7.7 | — | — | Same as above. | Completed (existence Confirmed; content not in evidence) | Confirmed |
| 1.7.6 | — | — | Same as above. | Completed (existence Confirmed; content not in evidence) | Confirmed |
| 1.7.5 | — | — | Same as above. | Completed (existence Confirmed; content not in evidence) | Confirmed |
| 1.7.4 | — | — | Same as above. | Completed (existence Confirmed; content not in evidence) | Confirmed |
| 1.7.3 | — | — | Same as above. | Completed (existence Confirmed; content not in evidence) | Confirmed |
| 1.7.2 | — | — | Same as above. | Completed (existence Confirmed; content not in evidence) | Confirmed |
| 1.7.1 | — | — | Same as above. (Later evidence references a wizard-scoped notice suppressor added here, replaced globally in 1.8.6.) | Completed (existence Confirmed; content not in evidence) | Confirmed |
| 1.7.0 | — | — | Same as above. (Later docs indicate the 1.7 line raised WP requirement to 6.5+.) | Completed (existence Confirmed; content not in evidence) | Confirmed |
| 1.5.2 | — | — | Heading present; body not in evidence. (pt_BR .po regeneration dated here per 1.8.7 notes.) | Completed (existence Confirmed; content not in evidence) | Confirmed |
| 1.5.1 | — | — | Heading present; body not in evidence. | Completed (existence Confirmed; content not in evidence) | Confirmed |
| 1.5.0 | — | — | Heading present; body not in evidence. | Completed (existence Confirmed; content not in evidence) | Confirmed |
| 1.3.9 → 1.3.0 (10 headings) | — | — | Headings present; bodies not in evidence. | Completed (existence Confirmed; content not in evidence) | Confirmed |
| 1.2.3 → 1.2.0 (4 headings) | — | — | Headings present; bodies not in evidence. | Completed (existence Confirmed; content not in evidence) | Confirmed |
| 1.1.1, 1.1.0 | — | — | Headings present; bodies not in evidence. | Completed (existence Confirmed; content not in evidence) | Confirmed |
| 1.0.0 | — | — | Heading present; body not in evidence. | Completed (existence Confirmed; content not in evidence) | Confirmed |

Documentation-only vs runtime distinction is preserved per-row above; the entire Unreleased entry is documentation-only and must not be presented as a runtime fix.

---
## Master Specification Sheet

Permanent IDs. `Last updated` = 2026-08-22 for all rows. Owners are unassigned in evidence — **Decision needed** throughout. Cost is Unknown/not priced unless a concrete operating impact is evidenced.

| ID | Section | Subject | Summary | Full specification | Type | State | Status | Priority | Version | Owner | Dependencies | Acceptance criteria | Risk | Cost | Source | Last updated | Notes |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| CT-001 | 1 Purpose | Product purpose & positioning | Consent-aware WordPress attribution preserving first/last-touch context to conversion | Captures UTMs/click IDs/referrers first-party; enriches Woo orders + forms; browser events; optional server-side delivery; stores visit source, not visitor profiles; no external enrichment by default | Functional | Confirmed | Confirmed | P0 | 1.9.0 | Unassigned — Decision needed | — | Positioning copy matches runtime capability vocabulary in ledger | Mispositioned claims vs evidence | Unknown — not priced | `README.en.md`; `clicutcl.php` | 2026-08-22 | Public copy must not outrun ledger statuses |
| CT-002 | 1 Purpose | Explicit non-goals | Committed exclusions prevent scope drift | No Pro-gated form integrations or delivery adapters; no Shopify/Magento/PrestaShop; no free multi-touch model UI; no Hyros/RedTrack-style tracking | Constraint | Confirmed | Confirmed | P1 | — | Unassigned — Decision needed | — | PRs touching these areas rejected or decision revisited first | Scope creep | — | `ROADMAP.md` (Not Building) | 2026-08-22 | Revisit deliberately, never silently |
| CT-003 | 1 Purpose | Runtime requirements | Minimum platform versions | WP 6.5+; PHP 8.1+; WC 10.4.2+ required for WP 7.0 compat; HPOS declared | Constraint | Confirmed | Confirmed | P0 | 1.8.5 | Unassigned — Decision needed | WordPress/Woo | Install activates cleanly on minimum stack; phpcompat CI green | Breakage on unsupported stacks | — | `clicutcl.php`; changelog 1.8.5 | 2026-08-22 | Badge claims WP 7.0 tested |
| CT-004 | 2 Users | Target users & journeys | Six documented buyer/journey profiles | Agencies (lead forms); Woo stores (campaign-aware orders; richer payloads); cached/dynamic sites; multi-domain funnels; unified browser+server teams | Functional | Confirmed | Confirmed | P1 | — | Unassigned — Decision needed | CT-007..CT-028 | Each journey executable per playbook | Journey-doc drift | — | `README.en.md` | 2026-08-22 | — |
| CT-005 | 2 Users | Progressive rollout | Layered adoption without day-one delivery | Capture → Forms → Events → Delivery; base attribution/forms/Woo work with delivery off | Functional | Confirmed | Confirmed | P1 | — | Unassigned — Decision needed | CT-024 | Delivery-off install passes CT-006 checks | Users believe delivery is required | — | `README.en.md`; `EVENT-PIPELINE.md` | 2026-08-22 | — |
| CT-006 | 2 Users | Install verification workflow | Deterministic post-install check | Test UTM URL → browse → submit form/place test order → verify attribution in entry/order, events in dataLayer/GTM preview, activity in Diagnostics/Logs | Operational | Confirmed | Confirmed | P1 | — | Unassigned — Decision needed | CT-007, CT-014..CT-021, CT-031 | All three confirmations observable on clean install | Silent misconfiguration | — | `README.en.md`; `OPERATIONS-RUNBOOK.md` | 2026-08-22 | — |
| CT-007 | 3 Functional | Attribution capture core | First/last-touch UTMs incl. extended GA fields + referrer fallback | `utm_id/source_platform/creative_format/marketing_tactic` under `ft_*`/`lt_*`; organic/social/referral inference when tags absent; retention configurable; overwrite optional | Functional | Confirmed | Confirmed (runtime-unverified E2E) | P0 | 1.9.0 | Unassigned — Decision needed | CT-009, CT-029 | Cookie contains ft_/lt_ values after tagged visit; fallback fires on untagged referral | Source loss | — | `README.en.md`; `DATA-MODEL.md`; `assets/js/clicutcl-attribution.js` | 2026-08-22 | Legacy `first_*`/`last_*` aliases normalized on read |
| CT-008 | 3 Functional | Click-ID & identifier coverage | 10 click IDs + 6 browser identifiers | gclid, wbraid, gbraid, fbclid, ttclid, msclkid, twclid, li_fat_id, sccid, epik; fbc, fbp, _ttp, li_gc, ga_client_id, ga_session_id at payload top level when permitted by consent | Functional | Confirmed | Confirmed (runtime-unverified E2E) | P0 | 1.9.0 | Unassigned — Decision needed | CT-007, CT-029 | Each ID captured from test URL and persisted per consent state | Missing ad-platform signal | — | `README.en.md`; `DATA-MODEL.md` | 2026-08-22 | — |
| CT-009 | 3 Functional | Client-side persistence & expiry | Cookie + TTL-bound localStorage mirror | Cookie `attribution` (legacy `ct_attribution`); mirror carries explicit expiry tied to `cookie_days`; legacy mirrors without expiry discarded; denied consent clears both | Functional | Confirmed | Confirmed | P0 | 1.9.0 | Unassigned — Decision needed | CT-007, CT-029 | Mirror expires with cookie_days; denied consent leaves no local attribution | Stale indefinite revival; privacy | — | `DATA-MODEL.md` | 2026-08-22 | — |
| CT-010 | 3 Functional | Consent-gated pending capture | Required-but-unresolved consent writes no `ct_pending_v1`; granted/not-required paths may use pending capture | `ClickTrailAttribution::init()` gates `PendingCapture.save()` behind not-required or resolved marketing grant; denial clears pending storage | Functional | Confirmed | Confirmed | P1 | 1.8.13 | Unassigned — Decision needed | CT-029 | `npm run smoke` verifies the source gate; live later-page consent trade-off remains an M7 product decision | Landing-page signal can be lost before later-page grant | — | `ROADMAP.md`; `DATA-MODEL.md`; changelog 1.8.13 | 2026-08-23 | A-2 documentation conflict resolved; no runtime change |
| CT-011 | 3 Functional | Cross-domain decoration | Approved-domain decoration + automatic sibling-subdomain passthrough | eTLD+1 comparison incl. 2-part TLDs decorates siblings with zero config; allowed-domains list for separate domains; skip-signed-URL option; hosted payments (Stripe/PayPal/Mollie/Square) non-decoratable by design | Functional | Confirmed | Confirmed | P0 | 1.7.9 | Unassigned — Decision needed | CT-012 | Decoration fires cross-domain in staging; payment-return order attributed iff pre-checkout cookie existed | Continuity loss on hosted checkout (documented limitation) | — | `INTEGRATIONS.md`; changelog 1.7.9 | 2026-08-22 | Checklist warns when decoration on but <2 approved domains |
| CT-012 | 3 Functional | Attribution-token continuity | Signed cross-domain tokens | `/v2/attribution-token/sign` + `/verify`; both require page client token (verify enforced 1.8.10); host allowlist; filterable subdomain acceptance; TTL filters; payload normalized on verify | Functional | Confirmed | Confirmed | P1 | 1.8.10 | Unassigned — Decision needed | CT-039 | Sign/verify round trip passes `RestAuthPermissionsTest`; foreign-host tokens rejected | Token forgery/replay | — | `REST-API.md`; `SECURITY-PRIVACY.md` | 2026-08-22 | — |
| CT-013 | 3 Functional | WhatsApp attribution append | Continuity into wa.me links | Hosts wa.me, whatsapp.com, api.whatsapp.com, web.whatsapp.com; optional attribution append to pre-filled messages | Functional | Confirmed | Confirmed (runtime-unverified E2E) | P2 | — | Unassigned — Decision needed | CT-007 | Appended context present on test wa.me link | — | — | `INTEGRATIONS.md` | 2026-08-22 | — |
| CT-014 | 3 Functional | Forms: automatic hidden fields | CF7 + Fluent Forms auto-injection | Hidden attribution fields added automatically; client-side fallback; dynamic-content watching; optional overwrite of existing values | Functional | Confirmed | Confirmed (runtime-unverified E2E) | P0 | — | Unassigned — Decision needed | CT-007, CT-009 | Submission stores attribution with cache enabled + AJAX-rendered form | Cached-page field loss | — | `INTEGRATIONS.md`; `EVENT-PIPELINE.md` | 2026-08-22 | Pattern: automatic injection |
| CT-015 | 3 Functional | Forms: matching hidden fields | GF + WPForms populate user-added `ct_*` fields | Users add desired `ct_*` fields (e.g. `ct_ft_source`, `ct_lt_source`, `ct_gclid`); per-form toggle via `clicutcl_gf_tracking_enabled`; GF entry-meta declared for all forms (values only when enabled); channel labels stored untranslated for cross-locale consistency; merge tags `{clicutcl_*}` with value/format/default filters; setup diagnostic scans for missing fields (warn severity) | Functional | Confirmed | Confirmed (runtime-unverified E2E) | P0 | 1.8.11 | Unassigned — Decision needed | CT-007, CT-032 | Diagnostic lists forms missing `ct_*` fields with edit links; disabled form yields empty ct_* values; merge tags escaped (1.8.10) | Silent attribution failure when fields absent | — | `INTEGRATIONS.md`; `HOOKS-REFERENCE.md`; `ROADMAP.md` | 2026-08-22 | Smoke IDs gf-* (8) in registry |
| CT-016 | 3 Functional | Forms: submission-hook paths | Elementor Pro + Ninja Forms stored-attribution model | Elementor: `elementor_pro/forms/new_record` hook, reads matching `ct_*` fields with cookie fallback; Ninja: attribution in submission extra data `extra.clicktrail_attribution`, shown in detail UI; no automatic hidden-field injection | Functional | Confirmed | Confirmed (runtime-unverified E2E) | P1 | — | Unassigned — Decision needed | CT-007 | Submission record carries attribution for both adapters | Expectation mismatch vs hidden-field plugins | — | `INTEGRATIONS.md` | 2026-08-22 | Pattern: submission-hook/storage |
| CT-017 | 3 Functional | Woo order attribution & HPOS | Campaign context on orders | Attribution saved at checkout; rendered in classic and HPOS Woo admin; HPOS (`custom_order_tables`) declared; WC ≥10.4.2 for WP 7.0 | Functional | Confirmed | Synthetic classic/HPOS runtime confirmed; browser E2E Future | P0 | — | Unassigned — Decision needed | CT-007 | Test order contains `_clicutcl_ft_source` (quality gate) | Paid traffic looks direct | — | `INTEGRATIONS.md`; `clicutcl.php`; changelog 1.8.5 | 2026-08-23 | Consent-denied checkout intentionally yields empty attribution (explained in Diagnostics since 1.8.16) |
| CT-018 | 3 Functional | Woo storefront events | Optional browser commerce signals | view_item, view_item_list, view_cart, add_to_cart, remove_from_cart, begin_checkout; `item_list_name`/`item_list_index` resolution + inheritance; view_cart from cart page/mini-cart/drawers when resolvable | Functional | Confirmed | Confirmed (runtime-unverified E2E) | P1 | — | Unassigned — Decision needed | CT-021 | Smoke IDs woo-view-item/add-to-cart/remove-from-cart/begin-checkout/view-item-list pass; list context inherited by add-to-cart | Event noise if misconfigured | — | `EVENT-PIPELINE.md`; `INTEGRATIONS.md` | 2026-08-22 | Flag: `woocommerce_storefront_events` |
| CT-019 | 3 Functional | Richer Woo dataLayer contract | GTM-first purchase payloads | Optional `event_id` + consent-aware `user_data` on thank-you purchase pushes; toggles `woo_enhanced_datalayer`, `woo_include_user_data` in `clicutcl_gtm` | Functional | Confirmed | Confirmed (runtime-unverified E2E) | P1 | — | Unassigned — Decision needed | CT-017, CT-029 | Purchase push carries event_id; user_data present only per consent | Identity exposure without consent proof (gate) | — | `DATA-MODEL.md`; `SETTINGS-AND-ADMIN.md` | 2026-08-22 | Runtime edge cases under release gates |
| CT-020 | 3 Functional | Woo milestones, traces & sent markers | Post-purchase lifecycle with truthful markers | Milestones order_paid/refunded/cancelled reuse purchase builder with deterministic event IDs; trace snapshots before/after dispatch on `_clicutcl_woo_trace_snapshot`; markers written only after success/skip/confirmed-queue; error-without-queue-row ⇒ marker unset ⇒ hook-path retry possible | Functional | Confirmed | Confirmed (runtime-unverified E2E) | P0 | — | Unassigned — Decision needed | CT-024, CT-031 | Smoke IDs woo-order-paid/refunded/cancelled + diagnostics-woo-lookup pass; marker semantics verified in staging | Duplicate or lost milestone sends | — | `EVENT-PIPELINE.md`; `OPERATIONS-RUNBOOK.md` | 2026-08-22 | — |
| CT-021 | 3 Functional | Browser events & canonical allowlist | Unified browser pipeline with strict intake | Search, download, scroll depth, engagement, lead-gen CTA, form_start/submit_attempt, thank-you lead detection, one-time login/sign_up/comment_submit (queued to next page load); batch posted to `/events/batch` when transport available; allowlist rejects purchase/qualified_lead/client_won; ≤20 requests/page token | Functional | Confirmed | Confirmed | P0 | 1.8.13 | Unassigned — Decision needed | CT-039, CT-040 | Smoke `signed-intake-gate` passes; disallowed events rejected server-side | Spoofed conversions via browser route | — | `REST-API.md`; `EVENT-PIPELINE.md` | 2026-08-22 | Filter `clicutcl_should_load_events_js` affects asset load only, not capability gate |
| CT-022 | 3 Functional | Webhook ingress | Signed provider lead/booking intake | Calendly (CT timestamped HMAC until native verified), HubSpot (native X-HubSpot-Signature), Typeform (native base64 HMAC); replay window; verbatim secrets; atomic replay claim; arrays itemized (HubSpot); consent-blocked ⇒ HTTP 200 + skipped:true | Functional | Confirmed | Confirmed (runtime-unverified E2E) | P1 | 1.8.13 | Unassigned — Decision needed | CT-040 | Signature fixtures pass; replayed request rejected; skip reason returned | Spoofed/replayed leads | — | `REST-API.md`; `SECURITY-PRIVACY.md` | 2026-08-22 | Provider timestamp/identity-minimization proofs are Blocked gates |
| CT-023 | 3 Functional | Lifecycle update intake | CRM/backend stage reporting | `POST /v2/lifecycle/update`; stages lead, book_appointment, qualified_lead, client_won; lifecycle token (hash_equals); canonical event enters dispatcher | Functional | Confirmed | Confirmed (runtime-unverified E2E) | P1 | — | Unassigned — Decision needed | CT-024 | Wrong/missing token rejected (unit-covered); valid stage creates canonical event | Token leakage/impersonation | — | `REST-API.md` | 2026-08-22 | Token binding/consent provenance tests Blocked |
| CT-024 | 3 Functional | Dispatch & retry queue | Server-side transport with truthful retry semantics | Dispatcher validates environment/settings/endpoint/consent; registry adapter selection; dedup; queue every 5 min, batch 10, max 5 attempts, backoff exp capped 1h, lock `clicutcl_queue_lock`; retention 7d filterable; retry path bypasses dispatch() (no dup touch rows) | Functional | Confirmed | Confirmed (DB paths runtime-unverified) | P0 | 1.9.0 | Unassigned — Decision needed | CT-025, CT-037 | Smoke `queue-retry-semantics` passes; backlog drains in staging; markers per CT-020 | Silent queue backup on shared hosting | — | `EVENT-PIPELINE.md`; `OPERATIONS-RUNBOOK.md` | 2026-08-22 | Pre-send consent recheck on retry is a Blocked gate |
| CT-025 | 3 Functional | Action Scheduler migration | Reliable queue scheduling | AS used when `as_schedule_single_action` exists (Woo active); WP-cron fallback; no Composer bundling; uninstall cancels AS actions guarded | Functional | Confirmed | Confirmed | P1 | 1.8.11 | Unassigned — Decision needed | CT-024 | Queue processes on low-traffic host with Woo active | Cron starvation | — | `ROADMAP.md`; changelog 1.8.11 | 2026-08-22 | — |
| CT-026 | 3/4 Functional | Delivery adapter registry & evidence boundary | 7 adapter keys, all source-present/runtime-unverified | generic (sv1), sgtm (sv1, collector:sgtm), meta_capi/google_ads/linkedin_capi/pinterest_capi/tiktok_events_api (sv2); classes serialize canonical JSON to configured endpoint; no provider auth/acceptance; one adapter at a time; toggles ≠ fan-out | Functional | Confirmed | Confirmed | P0 | 1.9.0 | Unassigned — Decision needed | CT-024, CT-041 | Smoke delivery-adapter-* (7) pass; public copy carries runtime-unverified qualifier | Providers marketed as turnkey (kill criterion) | — | `INTEGRATIONS.md`; ledger; `config/feature-registry.json` | 2026-08-22 | Registry `support_level:native_delivery` label conflicts with ledger — Appendix A-3 |
| CT-027 | 3 Functional | Reddit relay-only destination | Toggle + rdt_cid capture, no native adapter | Destination key `reddit` support_level relay_only, adapter_keys []; rdt_cid capture + Reddit source classification; no Reddit delivery claim permitted | Functional | Confirmed | Confirmed | P1 | — | Unassigned — Decision needed | CT-026 | Smoke `destination-reddit-toggle` passes; no Reddit adapter class exists | False native-support claim | — | ledger `destination.reddit`; `config/feature-registry.json` | 2026-08-22 | Native adapter = deliberate future decision |
| CT-028 | 3 Functional | GTM injection & sGTM compatibility | Container injection + tagging-server mode | Optional GTM container; sGTM mode: tagging-server URL, first-party loader, custom loader path; wizard with preview probes + template hints (`clicutcl_sgtm_preview_check`); never double-inject when site already loads GTM; loader-only change, not a generic GTM utility layer | Functional | Confirmed | Confirmed (preview security open) | P1 | — | Unassigned — Decision needed | CT-026, CT-041 | Preview probes reach configured URLs in staging; conflict scan flags sGTM misconfigs | Double-tagging; preview SSRF (SR-02) | — | `INTEGRATIONS.md`; `SETTINGS-AND-ADMIN.md` | 2026-08-22 | Platform pixels remain site-owned/GTM-mediated |
| CT-029 | 3 Functional | Consent modes, sources & banner | Consent configuration surface | Modes strict/relaxed/geo; CMP sources auto/plugin/cookiebot/onetrust/complianz/gtm/custom; plugin banner when plugin is source; normalization via ct:consentResolved + compat events; one source of truth recommended | Functional | Confirmed | Confirmed (precedence runtime-unverified) | P0 | — | Unassigned — Decision needed | CT-010, CT-030 | Banner renders when configured; bridge resolves CMP state; denied clears local capture | Consent-state disagreement (SR-05) | — | `SECURITY-PRIVACY.md`; `INTEGRATIONS.md` | 2026-08-22 | Stale-cookie precedence + cross-tab = Blocked gates |
| CT-030 | 3 Functional | Geo consent fail-safe | Region consent cannot be spoofed via headers | Headers untrusted by default (1.8.10 breaking); unknown country ⇒ consent required; authoritative `clicutcl_request_country_code` filter; opt-in `clicutcl_trust_geo_request_headers` for trusted CDN | Functional | Confirmed | Confirmed | P0 | 1.8.10 | Unassigned — Decision needed | CT-029 | Spoofed header does not grant consent; filter-provided country resolves region mode | Region bypass | — | `SECURITY-PRIVACY.md`; changelog 1.8.10 | 2026-08-22 | Breaking change — migration note required |
| CT-031 | 3 Functional | Diagnostics & operations tooling | Operator visibility suite | Endpoint test (health_check); conflict scan (cache, call-tracking info, Woo-without-Woo, sGTM misconfig, adapter/destination mismatch, GTM overlap, delivery-no-endpoint); backup export/import; Woo order trace lookup (+consent explanation); debug window; backlog; failure telemetry; recent dispatches; local purge | Operational | Confirmed | Confirmed | P0 | 1.8.17 | Unassigned — Decision needed | CT-020, CT-032, CT-033 | All AJAX actions nonce+capability gated; smoke diagnostics-* pass | Blind operations | — | `OPERATIONS-RUNBOOK.md`; `SETTINGS-AND-ADMIN.md` | 2026-08-22 | Visible Diagnostics result ≠ privacy/exactly-once proof |
| CT-032 | 3 Functional | Setup checklist | Read-only rollout readiness | Checklist rows for Capture/Consent Guidance/Forms/Events/Delivery/sGTM/Woo; clickable to tab+section; GF/WPForms missing-fields warn; cross-domain warnings incl. payment caveat | Operational | Confirmed | Confirmed | P1 | — | Unassigned — Decision needed | CT-031 | Checklist reflects clean-install expected state (quality gate) | Misleading readiness signal | — | `SETTINGS-AND-ADMIN.md`; `ROADMAP.md` | 2026-08-22 | — |
| CT-033 | 3 Functional | Settings backup export/restore | Config portability with secret caveat | Covers five option stores; restore runs live-admin sanitizers; export contains unmasked secrets in cleartext (capability/nonce-gated; on-screen warning since 1.8.14) | Operational | Confirmed | Confirmed | P1 | 1.8.14 | Unassigned — Decision needed | CT-042 | Round trip restores sanitized settings; warning displayed | Credential leak via exported file (SR-04) | — | `OPERATIONS-RUNBOOK.md`; changelog 1.8.14 | 2026-08-22 | Masked-export option = Decision needed |
| CT-034 | 3 Functional | Admin IA & legacy URL mapping | Capability-based settings app | Tabs Capture/Forms/Events/Delivery; separate Logs/Diagnostics; checklist above cards; legacy tab params mapped forward (trackingv2 → Events + migration notice); requires JS; network screen classic form | Functional | Confirmed | Confirmed | P1 | — | Unassigned — Decision needed | — | Old bookmarked URLs land on correct tab; no "Tracking v2" in user-facing strings | User confusion on upgrade | — | `SETTINGS-AND-ADMIN.md` | 2026-08-22 | Option key `clicutcl_tracking_v2` intentionally preserved internally |
| CT-035 | 3 Functional | Multisite network defaults | Network-wide transport defaults | `clicutcl_server_side_network`; site-level `use_network` override; ClickTrail Network menu (multisite only) | Functional | Confirmed | Confirmed (runtime-unverified E2E) | P2 | — | Unassigned — Decision needed | CT-024 | Network defaults inherited; site override respected | Cross-site config drift | — | `SETTINGS-AND-ADMIN.md`; `DATA-MODEL.md` | 2026-08-22 | — |
| CT-036 | 3 Functional | Localization pt-BR/de_DE | Translated admin experience | pt_BR po/mo (533 translated + 81 fuzzy) + script JSONs; de_DE po/mo + script JSON (1.8.9); reproducible POT pipeline; consent-help English fallback until regen (1.8.12) | Non-functional | Confirmed | Confirmed | P2 | 1.8.12 | Unassigned — Decision needed | — | Admin renders translated on de_DE/pt_BR sites | Translation drift each release | — | changelogs 1.8.7/1.8.9/1.8.12 | 2026-08-22 | Channel labels deliberately untranslated (stored data) |
| CT-037 | 4 Technical | Touch-events table (Pro foundation) | Queryable structured touch log, always-on write | `{prefix}clicutcl_touch_events` (1.9.0, DB_VERSION 3); written from dispatch() ahead of enablement gates; consent-gated skip; visitor_id = SHA-256 email else session_id; funnel_stage top/mid/bottom/unknown; touch_* derived current-touch + ft_*; indexes visitor/order/blog/created; 90-day retention; GDPR export/erase by hashed email; no free-tier UI reads it | Technical | Confirmed | Confirmed (insert path runtime-unverified) | P0 | 1.9.0 | Unassigned — Decision needed | CT-024, CT-038 | `TouchEventsStoreTest` passes; rows appear for each source in staging; eraser removes matched rows | Storage growth; erasure blind spot for session-only visitors (inherent, documented) | Storage cost TBD — Decision needed | changelog 1.9.0; `DATA-MODEL.md` | 2026-08-22 | Deliberately separate from `clicutcl_events`; coexist permanently |
| CT-038 | 4 Technical | Events log table & eraser scope | JSON-blob logs + partial erasure truth | `clicutcl_events` backs Logs screen, GDPR exporter, form submission logging; IP anonymized at rest (1.8.10); eraser targets events + touch_events (exact hash) + queue (raw/hash email); legacy hashed shapes + Woo order meta NOT fully covered | Technical | Confirmed | Confirmed | P0 | 1.8.10 | Unassigned — Decision needed | CT-037, CT-050 | Eraser removes matched rows; export paginates both tables | Incomplete deletion claim risk (kill criterion) | — | `DATA-MODEL.md`; `SECURITY-PRIVACY.md` | 2026-08-22 | Completeness is a Blocked gate, not a certified state |
| CT-039 | 4 Technical | REST v2 surface & auth model | Single registered REST namespace | 7 routes per §4.3; per-route permission callbacks; legacy v1 log controller absent from current tree; `clicutcl/v2` via `Tracking_Controller` is the only registered REST surface | Technical | Confirmed | Confirmed | P0 | 1.8.14 | Unassigned — Decision needed | CT-040 | `RestAuthPermissionsTest` green; only clicutcl/v2 registered | Auth bypass; stale route documentation | — | `REST-API.md`; `CODE-MAP.md`; `README.en.md`; changelog 1.8.14 | 2026-08-22 | Resolved by tree inspection and docs sync |
| CT-040 | 4 Technical | Intake security controls | Abuse resistance on public endpoints | Body-size caps; rate limiting (atomic under object cache, 1.8.15); token TTL/nonce replay limits; allowed hosts; optional subdomain tokens; trusted-proxy filters; webhook replay window + atomic claim | Non-functional | Confirmed | Confirmed | P0 | 1.8.15 | Unassigned — Decision needed | CT-039 | Burst cannot exceed soft limit under object cache; replayed webhook rejected | DoS/burst abuse | — | `REST-API.md`; `SECURITY-PRIVACY.md`; changelog 1.8.15 | 2026-08-22 | Transient fallback best-effort without object cache |
| CT-041 | 4 Technical | SSRF protections | Outbound URL safety | Save-time `wp_http_validate_url` + scheme allowlist; request-time `reject_unsafe_urls` in all 7 adapters (1.8.10); sGTM preview SSRF hardening remains an open release blocker | Non-functional | Confirmed (adapters) / Blocked (sGTM preview) | Confirmed | P0 | 1.8.10 | Unassigned — Decision needed | CT-026, CT-028 | Private/loopback/link-local URLs rejected at save and request time; preview fixtures negative-path green (pending) | Internal network reach (SR-02) | — | changelog 1.8.10; ledger `delivery.sgtm` | 2026-08-22 | Preview not secure-by-default until hardened |
| CT-042 | 4 Technical | Secret storage & masking | Write-only secrets with optional encryption | Secrets in `clicutcl_tracking_v2`; masked to UI; blank/masked preserves existing; optional AES-256-GCM at rest with inert-toggle admin notice; backup export bypasses masking (warning) | Non-functional | Confirmed | Confirmed | P0 | 1.8.14 | Unassigned — Decision needed | CT-033, CT-043 | Masked values never echo secrets; notice shows when OpenSSL absent | Plaintext secrets at rest / in exports (SR-04, SR-11) | — | `SECURITY-PRIVACY.md`; changelogs 1.8.10/1.8.14 | 2026-08-22 | — |
| CT-043 | 4 Technical | Environment safeguards | Clone-environment dispatch prevention | Dispatch blocked by default in `local`/`development`; override via `clicutcl_dispatch_in_environment` | Non-functional | Confirmed | Confirmed | P1 | — | Unassigned — Decision needed | CT-024 | Staging clone makes no outbound provider calls by default | Accidental live dispatch (SR-09) | — | `SECURITY-PRIVACY.md` | 2026-08-22 | — |
| CT-044 | 4 Technical | Capture hygiene | Input sanity at both capture paths | `{{macro}}` rejection symmetric client/server (1.8.8); 255-char cap on classic capture values (1.8.15); 128-char cap on token path; cookie-budget rejection discards stale oversized values | Non-functional | Confirmed | Confirmed | P1 | 1.8.15 | Unassigned — Decision needed | CT-007 | Macro URLs yield no stored campaign names; oversize values truncated/rejected | Report pollution; cookie overflow | — | changelogs 1.8.8/1.8.15 | 2026-08-22 | — |
| CT-045 | 4 Technical | Feature registry & smoke harness | Wiring truth + structural regression net | `config/feature-registry.json` drives admin labels, adapter allowlists, checklist/conflict labels, docs ownership, smoke IDs; `npm run smoke` verifies registry/docs/code/matrix alignment + consent-bridge JS boundary; ROADMAP cites 37+ IDs; registry ≠ provider certificate | Technical | Confirmed | Confirmed | P0 | 1.9.0 | Unassigned — Decision needed | CT-046 | Smoke green before tag; new capability ships with registry+matrix+docs entries | Breadth regressions; false provider confidence | — | `FEATURE-REGISTRY.md`; `FEATURE-TEST-MATRIX.md` | 2026-08-22 | 1.8.14 proved packaging of this file is release-critical |
| CT-046 | 4 Technical | CI quality gates | Pre-tag gate set | PHPCS zero warnings; PHPUnit green PHP 8.1/8.2/8.3; CodeQL; Dependency Review; phpcompat (8.1−); node --check; npm run smoke; manual data-accuracy + UI/messaging checks per ROADMAP | Non-functional | Confirmed | Confirmed | P0 | 1.8.18 | Unassigned — Decision needed | CT-045 | All gates green on release PR | Regressions reaching tags | — | `ROADMAP.md`; `CODE-QUALITY.md` | 2026-08-22 | DB-backed queue paths need live $wpdb harness — known gap |
| CT-047 | 4 Technical | Uninstall & data preservation | Destructive-by-default cleanup | Removes options, clears hooks/transients, drops queue/events/touch_events tables; `clicutcl_preserve_data_on_uninstall` preserves; AS actions cancelled guarded | Technical | Confirmed | Confirmed | P1 | 1.9.0 | Unassigned — Decision needed | CT-037 | Fresh install→uninstall leaves no ClickTrail tables/options; preserve-filter keeps them | Accidental data loss on uninstall | — | `DATA-MODEL.md`; `OPERATIONS-RUNBOOK.md` | 2026-08-22 | Woo order-meta keys not covered — see CT-050 |
| CT-048 | 4 Technical | Packaging integrity | Release ZIP completeness | `.distignore` governs WP.org build; `config/` restored to package (1.8.14) after silent omission broke adapter resolution on all installs ≤1.8.13; lead-magnet/dev assets excluded deliberately | Operational | Confirmed | Confirmed | P0 | 1.8.14 | Unassigned — Decision needed | CT-045 | Post-build zip contains feature-registry.json; adapter dropdown populates on WP.org-equivalent install | Broken adapter selection in production (SR-12) | — | changelog 1.8.14 | 2026-08-22 | Add packaging check to release checklist — Decision needed |
| CT-049 | 5 Safety | Gate: consent withdrawal vs queue retry | Delayed sends must respect withdrawal | Queue retries currently lack proven pre-send consent recheck; L1 requires authoritative consent service + immediate pre-adapter recheck | Safety | Blocked | Blocked | P0 | — | Unassigned — Decision needed | CT-024, CT-029 | Withdrawal-before-retry test asserts zero sends/enqueues | Post-withdrawal data egress (SR-01) | — | `SECURITY-PRIVACY.md`; phasing plan L1 | 2026-08-22 | Kill criterion active |
| CT-050 | 5 Safety | Gate: Woo order-meta privacy coverage | Trace/attribution keys outside purge scope | `_clicutcl_woo_trace_snapshot`, attribution fields, consent snapshots, milestone markers need export/erase/retention/purge/uninstall handling; not yet verified complete | Safety | Blocked | Blocked | P0 | — | Unassigned — Decision needed | CT-038, CT-047 | Erase drill covers every ClickTrail-owned Woo key without cross-tenant leakage | Retained personal data (SR-03) | — | `DATA-MODEL.md` retention boundary; `SECURITY-PRIVACY.md` | 2026-08-22 | — |
| CT-051 | 5 Safety | Gate: consent authority & stale cookies | One decision must win everywhere | Consent Mode disabled + legacy require_consent disagree (Dispatcher); stale plugin cookie can outrank CMP; cross-tab sync/revocation incomplete | Safety | Blocked | Blocked | P0 | — | Unassigned — Decision needed | CT-029 | Cross-tab/stale-cookie/revocation browser tests pass; single authority proven | Consent bypass (SR-05) | — | `SECURITY-PRIVACY.md` | 2026-08-22 | — |
| CT-052 | 5 Safety | Gate: provider contract fixtures | No turnkey claims before staged proof | Each adapter needs versioned fixture proving mapping, auth, consent denial, redaction, retry, idempotency, response handling + staged delivery before leaving source-present/runtime-unverified | Safety | Blocked | Blocked | P0 | — | Unassigned — Decision needed | CT-026 | Ledger entry upgraded per provider with fixture + staged evidence | False provider support claims (SR-10) | Provider account costs TBD | Phasing plan L3; ledger | 2026-08-22 | One provider per release |
| CT-053 | 5 Safety | Claims containment rule | Public copy bounded by ledger | Every public provider claim links to ledger ID + source path + verification date + runtime status; forbidden words without passing evidence: privacy compliant, secure, guaranteed, reliable, all platforms, native Reddit | Constraint | Confirmed | Confirmed | P0 | — | Unassigned — Decision needed | CT-026, CT-052 | L0 gate: no unqualified claim in first screenful; docs QA passes | Reputational/legal exposure (SR-10) | — | `RELEASE-PHASING-AND-INTEGRATION-DOCS.md` | 2026-08-22 | Applies to readmes, support answers, screenshots |
| CT-054 | 6 Business | Free distribution & licensing | GPL free plugin, GitHub-first | GPL-2.0-or-later; GitHub releases primary; WP.org stable lags (1.8.13) per 3-version buffer (RELEASING.md unsupplied — verify) | Commercial | Confirmed / Decision needed (policy text) | Confirmed | P1 | 1.9.0 | Unassigned — Decision needed | CT-048 | Stable-tag promotion follows documented buffer; artifacts aligned | Premature WP.org promotion | — | `README.en.md`; changelog notes | 2026-08-22 | Release-metadata alignment outstanding |
| CT-055 | 6 Business | Pro tier pricing | No pricing evidence exists | Pro planned (foundation → features) but price/quota/packaging unknown; all commercial numbers Unknown/not priced; tax treatment undecided | Commercial | Decision needed | Proposed | P1 | — | Unassigned — Decision needed | CT-056..CT-061 scope | Pricing decision recorded with currency/period/tax/confidence/source/date | Revenue model vacuum; invented-price risk | Unknown — not priced | Absent from all supplied evidence | 2026-08-22 | Never publish invented numbers |
| CT-056 | 7 Roadmap | Pro: attribution reporting dashboard | Core Pro feature reading touch-events table | Revenue by channel/source/campaign; FT vs LT comparison; date ranges; conversion counts/rates; new `ClickTrail > Reports` screen; read-only queries; no external service | Roadmap | Future | Proposed | P1 | — | Unassigned — Decision needed | CT-037 data maturity | Dashboard renders non-empty on seeded data; queries read-only | Empty-dashboard launch risk | Dev cost TBD | `ROADMAP.md` Pro Features #1 | 2026-08-22 | Depends on events table (shipped 1.9.0) |
| CT-057 | 7 Roadmap | Pro: subscriptions/LTV + customer-level attribution | Renewal revenue traced to acquisition | Write attribution to Woo customer meta on first conversion; hook `woocommerce_subscription_renewal_payment_complete`; LTV-by-channel view | Roadmap | Future | Proposed | P2 | — | Unassigned — Decision needed | CT-056 | Renewal order maps to original acquisition source in report | Misattributed LTV | Dev cost TBD | `ROADMAP.md` Foundation + Features #2 | 2026-08-22 | — |
| CT-058 | 7 Roadmap | Pro: conversion recovery + delivery receipts | Failed-delivery surfacing and replay | Receipt rows (event ID, adapter, timestamp, HTTP status, response snippet); Pro "Delivery accuracy" tab; per-event Resubmit capped at 3 attempts with backoff | Roadmap | Future | Proposed | P2 | — | Unassigned — Decision needed | CT-024 | Receipts populated for dispatched events; resubmit honors cap | Replay abuse | Dev cost TBD | `ROADMAP.md` Foundation + Features #3 | 2026-08-22 | Consent recheck must apply to resubmits — tie to CT-049 |
| CT-059 | 7 Roadmap | Pro: CRM field mapping UI | Schema-flexible CRM routing | Map `ct_ft_source` → e.g. HubSpot `hs_analytics_source`; conditional routing rules; depends only on Pro license gate | Roadmap | Future | Proposed | P2 | — | Unassigned — Decision needed | CT-023 | Mapping produces correctly-named CRM fields in staging | Mapping errors corrupt CRM data | Dev cost TBD | `ROADMAP.md` Features #4 | 2026-08-22 | — |
| CT-060 | 7 Roadmap | Multi-site/agency mode | Later-cycle agency operations | Multi-site network mode with per-site overrides, central diagnostics, and white-label; build only on repeated paid demand | Roadmap | Future | Proposed | P3 | — | Unassigned — Decision needed | CT-056 | Network mode passes multisite tests | Premature infrastructure build | Dev cost TBD | `ROADMAP.md` Feature #5 | 2026-08-23 | Call tracking removed; third-party DNI remains diagnostic-only |
| CT-061 | 7 Roadmap | Stable-tag discrepancy management | Version truth alignment | Header 1.9.0; docs baseline 1.9.0@a45aa9e; WP.org stable 1.8.13; artifacts need alignment before next package; GitHub-only shipping continues meanwhile | Operational | Confirmed | Confirmed | P0 | 1.9.0 | Unassigned — Decision needed | CT-048, CT-054 | Published surfaces state identical version truth; buffer policy documented | User confusion; support burden | — | `README.en.md`; changelog notes | 2026-08-22 | Explicit discrepancy preserved, not papered over |

---

## Appendix A — Documentation Conflicts Register

| # | Conflict | Claim A (source) | Claim B (source) | Resolution status |
|---|---|---|---|---|
| A-1 | Legacy v1 API existence | "Removed from the codebase entirely; `clicutcl/v2` is the only registered REST surface" (`docs/reference/REST-API.md`, corrected in changelog 1.8.14) | Historical stale wording in prior `README.en.md`/`CODE-MAP.md` versions claimed a disabled controller remained | **Resolved 2026-08-22** — current tree has no legacy controller; `README.en.md`, `README.pt-BR.md`, and `CODE-MAP.md` now match the REST reference |
| A-2 | Pre-consent pending attribution buffering | Historical ROADMAP wording said `ct_pending_v1` was written before consent | `ClickTrailAttribution::init()` writes pending capture only when consent is not required or resolved marketing consent is granted; denial clears it | **Resolved 2026-08-23** — ROADMAP now matches code, DATA-MODEL, changelog 1.8.13, and the smoke source gate |
| A-3 | Adapter support-level vocabulary | `config/feature-registry.json` declares `support_level: native_delivery` for meta_capi/google_ads/linkedin_capi/pinterest_capi/tiktok_events_api | Ledger + INTEGRATIONS.md mandate `source-present / runtime-unverified` for all public claims; adapter classes construct no provider auth | **Decision needed** — rename registry levels (e.g., `configured_endpoint_adapter`) or add explicit runtime-verification field; ledger remains authoritative for public claims either way |
| A-4 | Stale doc baselines | `PLUGIN-OVERVIEW.md` states "Current codebase version: 1.7.0", last verified 1.8.5; several guides verified below 1.9.0 | Actual header/baseline 1.9.0 | **Decision needed** — refresh "Last verified against" stamps in the L0 pass (M1) |

---

## Closing Control Statement

This specification contains no invented functionality, prices, SLAs, legal conclusions, provider-verification claims, or roadmap completions. The compressed view is [MASTER-SPECIFICATION-SUMMARY.md](MASTER-SPECIFICATION-SUMMARY.md); the full chronological record is [MASTER-SPECIFICATION-CHANGE-RECORD.md](MASTER-SPECIFICATION-CHANGE-RECORD.md). Every material claim carries a State; every requirement row carries State + lifecycle Status; all sources are inline repository paths; conflicts are registered, not resolved silently. The document is durable product truth only to the extent it is kept synchronized with the precedence chain in §0.3 — the next required action is the M1 L0 synchronization pass with named owners assigned (**Decision needed**).
