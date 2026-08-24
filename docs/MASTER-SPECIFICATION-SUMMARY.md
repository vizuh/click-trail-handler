# ClickTrail Compressed Product Specification

**Document type:** Executive/compressed specification
**Specification version:** `0.1.0`
**Document state:** **Confirmed** as a summary of the current master specification; unresolved items remain labelled below
**Last updated:** 2026-08-22 (Europe/Lisbon)
**Canonical detail:** [MASTER-SPECIFICATION.md](MASTER-SPECIFICATION.md)
**Full change history:** [MASTER-SPECIFICATION-CHANGE-RECORD.md](MASTER-SPECIFICATION-CHANGE-RECORD.md)

**State application rule:** a section-level state applies to its material text until another state marker; every table row carries its own State. Unresolved inferences remain explicitly separated from confirmed evidence.

## Product Passport

| Field | Value | State |
|---|---|---|
| Product | ClickTrail WordPress attribution and ad-tracking plugin | Confirmed |
| Purpose | Preserve consent-aware first/last-touch campaign context through WordPress forms, WooCommerce, browser events, and optional downstream delivery | Confirmed |
| Users | Agencies, lead-generation teams, WooCommerce stores, implementers, and operators | Confirmed |
| Scope | WordPress sites globally; pt-BR and de_DE admin localization exists. Legal/geographic deployment scope is not defined | Confirmed / Decision needed |
| Owner | Vizuh is the publisher; named product/technical owners are not assigned | Confirmed / Decision needed |
| Current status | Code header 1.9.0; docs baseline 1.9.0 at `a45aa9e`; current work is truth-containment (L0), with runtime/provider verification gates still open | Confirmed |
| Spec version | 0.1.0 | Confirmed |
| Pricing | No confirmed Pro price, quota, SLA, or margin model in repository evidence | Decision needed |

## What the product must do

**State: Confirmed.** ClickTrail captures UTMs, click IDs, referrer/source context, and permitted browser identifiers; persists attribution under consent rules; exposes it to supported forms and WooCommerce; emits browser/dataLayer and lifecycle events; and optionally sends canonical events through configured server-side adapters with deduplication, queueing, retries, and diagnostics.

**State: Confirmed.** The product is a WordPress capture and delivery layer, not a general attribution SaaS, CDP, ad-platform SDK bundle, or multi-touch reporting dashboard. GTM-mediated platform tags are site-owned, not native ClickTrail adapters. Reddit is relay-only in the current evidence ledger.

## Core workflow

`Visitor arrives with campaign context` → `consent resolves` → `capture/persist if allowed` → `form or Woo boundary reads attribution` → `canonical event is normalized` → `dataLayer and/or configured delivery` → `dedup/queue/diagnostics` → `operator verifies or hands off.

| Stage | Required condition | Result | State |
|---|---|---|---|
| Capture | Valid input and consent policy | First/last-touch attribution and click IDs are available within documented storage limits | Confirmed |
| Forms/Woo | Supported hook/adapter and configured fields | Submission/order carries documented attribution | Confirmed; runtime E2E unverified |
| Events | Browser collection/dataLayer enabled or trusted server/lifecycle route | Canonical event enters the pipeline | Confirmed |
| Delivery | Endpoint, adapter, environment, consent, and dedup checks pass | Send, skip, or queue with observable result | Confirmed; provider acceptance unverified |
| Withdrawal | Consent denial or withdrawal | Local identifiers and pending capture are cleared; downstream purge is not automatic | Confirmed boundary; completeness blocked |

## Essential capability set

| Capability | Current truth | State |
|---|---|---|
| Attribution capture and retention | First/last-touch UTMs, extended UTM fields, click IDs, referrer fallback, configurable retention | Confirmed; runtime E2E unverified |
| Consent | strict/relaxed/geo modes, plugin/CMP/GTM/custom sources, fail-safe unknown geo | Confirmed; precedence/revocation gates open |
| Form support | CF7/Fluent automatic fields; GF/WPForms matching fields; Elementor/Ninja stored-attribution paths | Confirmed; runtime E2E unverified |
| WooCommerce | order attribution, purchase/dataLayer output, storefront events, milestones, traces, HPOS declaration | Confirmed; runtime E2E unverified |
| Browser and lifecycle events | Strict browser allowlist, signed intake, lifecycle route for trusted stages | Confirmed |
| Server delivery | Registry-backed configured-endpoint adapters, dedup, queue, retries, diagnostics | Confirmed; provider E2E unverified |
| Structured touch log | `wp_clicutcl_touch_events`, DB version 3, 90-day default retention, consent-gated writes | Confirmed; DB insertion path unverified |
| Operations | settings checklist, diagnostics, endpoint/conflict tests, backup/restore, trace lookup, queue and failure telemetry | Confirmed |

## Architecture summary

**State: Confirmed.** A WordPress/PHP plugin bootstraps through `clicutcl_init()`, loads frontend attribution and event JavaScript, normalizes intake through the canonical tracking pipeline, stores options/tables/order metadata, and dispatches optional server-side events through a registry-backed adapter and queue. The legacy v1 log controller is absent from the current tree; `clicutcl/v2` is the only active REST namespace per the canonical REST reference.

Primary persistence surfaces: `clicutcl_attribution_settings`, `clicutcl_consent_mode`, `clicutcl_gtm`, `clicutcl_server_side`, `clicutcl_server_side_network`, `clicutcl_tracking_v2`; tables `wp_clicutcl_events`, `wp_clicutcl_queue`, `wp_clicutcl_touch_events`; browser cookie/localStorage/sessionStorage; Woo order metadata and trace markers. See [DATA-MODEL.md](architecture/DATA-MODEL.md).

## Principal safety requirements

| Risk/control | Requirement | State |
|---|---|---|
| Consent withdrawal | A delayed queue retry must re-check authoritative consent immediately before adapter invocation | Blocked release gate |
| Privacy deletion | Export/erase/uninstall must cover every ClickTrail-owned Woo order-meta field, not only tables | Blocked release gate |
| SSRF | sGTM preview and delivery endpoints must reject private/internal targets at save and request time | Adapter protection confirmed; preview hardening blocked |
| Provider claims | Registry presence must not be described as provider authentication, acceptance, reliable delivery, or native support | Confirmed control |
| Secrets | Admin masks secrets, backup export warns that it contains cleartext secrets, and secret values must never be committed/logged | Confirmed control; masked export decision needed |
| Abuse | Tokens, signatures, replay windows, nonce limits, body limits, rate limits, capabilities, and nonces must be enforced | Confirmed controls; provider fixtures pending |
| Environment | Cloned local/development environments must not dispatch by default | Confirmed |
| Compliance | Legal verification is required; no GDPR/CCPA/ePrivacy certification is asserted | Decision needed |

## Plans and costs

**State: Confirmed.** The free plugin is GPL-2.0-or-later and GitHub-first; Pro reporting and agency capabilities are future roadmap items. No confirmed customer pricing, usage quota, infrastructure budget, support SLA, tax treatment, variable event cost, revenue target, or break-even number is present in the evidence. These values must be decided with currency, billing period, tax treatment, confidence, source, and last-checked date before publication.

## Twelve-month roadmap

The month mapping follows the product-facing feature/code sequence in `ROADMAP.md`; evidence promotion remains governed by `docs/guides/RELEASE-PHASING-AND-INTEGRATION-DOCS.md`. Month labels are planning assumptions. Nothing below is shipped merely because source exists.

| Month | Focus | Exit proof | State |
|---|---|---|---|
| M1 Sep 2026 | UTM Identify contract | Source-precedence, ambiguity, and privacy tests; no runtime/UI claim | Source candidate validated |
| M2 Oct | Read-only Attribution Readiness Diagnostics | Permission/nonce/bounds and allowlisted-output tests; no click-ID retention | Source present / runtime unverified |
| M3 Nov | Deterministic source-only suggestions | Existing source preserved; ambiguity suppresses suggestion; no medium/campaign invention | Source present / runtime unverified; staging blocked |
| M4 Dec | UTM hygiene and copy-only test URL | URL safety fixtures plus browser/PHP parity and WordPress/browser evidence | Source present / runtime unverified; parity/staging blocked |
| M5 Jan 2027 | Form readiness diagnostics | Six adapter fixtures plus hook/cache/AJAX and staging evidence; privacy-safe named storage surfaces | Source comparator + six synthetic fixtures validated locally; runtime/admin/provider proof Future |
| M6 Feb | Woo conversion readiness | Consent/order-status/HPOS/refund/dedup fixtures and staging trace | M6-A validated; M6-B classic/HPOS runtime reproduced; refund trace and HPOS admin fixed; consent-marker blocker remains |
| M7 Mar | Authoritative consent foundation | Grant/deny/revocation/cross-tab/retry/erasure drills; no unsupported compliance claim | M7-A contract validated; M7-B Woo v1 snapshots passed classic/HPOS staging; browser/retry/erasure/legal proof Future |
| M8 Apr | Delivery integrity | Replay/retry/dedup/private-target negative fixtures; Delivery stays default-off | Future |
| M9 May | Generic collector contract | Versioned fixture and staged endpoint proof | Future; cost decision needed |
| M10 Jun | sGTM staging contract | End-to-end staging evidence without hosting claim | Future; infrastructure decision needed |
| M11 Jul | Delivery receipts and Woo customer attribution | Receipt, retention/erase, and renewal attribution tests; no Pro UI | Future |
| M12 Aug | Reporting/re-audit decision | Annual evidence report and explicit publish/no-publish decision | Future; decision needed |

## Main unresolved decisions

1. Assign product, technical, privacy, release, and support owners.
2. Reconcile pre-consent `ct_pending_v1` behavior against `DATA-MODEL.md`.
3. Align feature-registry support vocabulary with the evidence ledger.
4. Confirm stable-tag buffer policy and align release artifacts.
5. Decide Pro price, quotas, packaging, tax, support, and margin model.
6. Complete legal review and deletion/retention coverage.
7. Decide whether Reddit remains relay-only.
8. Decide whether backup export can be secret-free.
9. Promote the readiness analyzer only after review, registry/UI wiring, ambiguity tests, and no-persistence proof.
10. Verify Calendly’s native signature format and provider contracts.

## Control rule

**State: Confirmed.** The master specification is the authority; this compressed document is a navigation aid. Runtime code, the integration ledger, canonical architecture/reference docs, operational guides, readmes, and changelog take precedence over AI output. Any unresolved conflict stays labelled until a human owner records a decision.
