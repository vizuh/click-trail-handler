# ClickTrail competitive positioning and acquisition roadmap

Snapshot: **2026-08-22, Europe/Lisbon**
Status: **planning and copy direction; not a release approval**

This document converts the five-competitor review into a product, repository,
README, and client-acquisition plan. It is the commercial and communication
layer around the technical plan:

- [`MASTER-SPECIFICATION.md`](../MASTER-SPECIFICATION.md) remains the detailed
  product and twelve-month source of truth.
- [`ROADMAP.md`](../../ROADMAP.md) remains the implementation backlog.
- [`COMPETITOR-MAP-2026-08-22.md`](COMPETITOR-MAP-2026-08-22.md) remains the
  dated evidence map for HandL UTM Grabber, Stape, WhatConverts, Ruler
  Analytics, and RedTrack.
- [`COMPETITOR-ROADMAP-2026-08-22.md`](COMPETITOR-ROADMAP-2026-08-22.md)
  remains the competitor-informed engineering opportunity backlog.

Nothing in this document upgrades an integration from source-present to
runtime-verified, creates a commercial promise, or marks a future item as
shipped.

## 1. Executive decision

### Narrowest defensible proposition

> **ClickTrail carries observed campaign context from visit to configured
> WordPress conversion boundaries through first-party capture, documented
> first-touch and last-touch rules, and explicit consent and delivery controls.**

Short description:

> **Consent-aware WordPress attribution:** first-party capture of UTMs and
> click IDs, preserved to WooCommerce orders and configured form paths, with
> optional controlled delivery to endpoints the site owner configures.

The proposition is intentionally narrower than an attribution suite. ClickTrail
is a WordPress capture-and-controlled-delivery layer, not:

- an attribution dashboard or multi-touch revenue model;
- hosted server-side GTM infrastructure;
- a lead manager, call tracker, or dynamic-number-insertion platform; or
- an ad-spend optimizer.

These boundaries distinguish ClickTrail from the five reviewed lanes without
claiming that any competitor is defective.

### Buyer segments

1. **Agencies and implementers** that need campaign context inside a form entry
   or WooCommerce order and need a reproducible handoff.
2. **WooCommerce operators** whose paid traffic becomes Direct by conversion
   time.
3. **Consent-constrained teams** that want capture, browser events, and
   delivery to follow one configured consent decision.
4. **GTM/sGTM teams** that need a WordPress-side canonical event source feeding
   an existing stack rather than replacing it.

### Competitive wedges

| Wedge | Evidence status | Message discipline |
| --- | --- | --- |
| One plugin keeps capture, form/Woo boundaries, consent configuration, and optional delivery visible together | Confirmed as product direction; consent/revocation E2E remains gated | Say **one configured control surface**, not “privacy compliant.” |
| Verification is part of the workflow | Confirmed: setup checklist, conflict scan, endpoint test, and Woo trace lookup are present in the documented surface | Say **inspect/verify observed state**, not guaranteed delivery. |
| Honest integration boundaries | Confirmed: ledger vocabulary, GTM-mediated tags, and relay-only Reddit status | Keep “source-present / runtime-unverified” adjacent to provider-named adapter copy. |
| Cache/AJAX and cross-domain failure modes are first-class setup concerns | Confirmed as documented scope; individual paths remain evidence-bounded | Explain the path and its test, not “works everywhere.” |
| WooCommerce depth | Source-present; live E2E is not verified in this baseline | Qualify HPOS, order, purchase, milestone, and trace claims until fixtures pass. |

## 2. Five competitor lanes

The dated competitor map contains the public feature, pricing, review,
complaint, discussion, and troubleshooting evidence. The table below is the
positioning decision, not a market-share ranking.

| Competitor | Their center of gravity | ClickTrail response |
| --- | --- | --- |
| HandL UTM Grabber | Broad WordPress UTM, form, WooCommerce, CRM, and paid-extension surface | Compete on narrower setup patterns, diagnostics, and evidence labels; do not copy unbounded breadth or internal-link mutation. |
| Stape | Hosted sGTM containers, gateways, loaders, and infrastructure | Integrate at the WordPress event boundary; do not become a hosting business. |
| WhatConverts | Lead, call, chat, qualification, and source-management platform | Preserve attribution behind WordPress forms/orders; do not imply call tracking or lead qualification. |
| Ruler Analytics | CRM/revenue matching and multi-touch reporting | Supply a trustworthy WordPress-side event foundation; do not promise revenue modeling or a dashboard. |
| RedTrack | Ad-level links, spend sync, postbacks, and performance optimization | Stop at controlled canonical-event delivery; do not build an optimizer. |

External reviews, support topics, and troubleshooting pages are **reported
signals** only. They are not proof of prevalence, market share, or a general
defect. Refresh the dated map before using competitor-specific facts in public
sales material.

## 3. Product and repository roadmap

Calendar labels are planning assumptions. The technical exit criteria must be
met before a claim is promoted. This table summarizes the twelve-month plan;
the master specification contains the detailed requirements and dependencies.

| Month | Lane | Product/repository outcome | Acquisition outcome | Exit proof | State |
| --- | --- | --- | --- | --- | --- |
| M1 — Sep 2026 | UTM Identify contract | Freeze source precedence, click-ID evidence, ambiguity behavior, and privacy-safe analyzer output | No public claim; prepare an inspectable evidence contract | Focused ambiguity, precedence, and privacy tests | Source candidate validated |
| M2 — Oct 2026 | Read-only readiness diagnostics | Add the bounded Diagnostics endpoint and panel without persistence or provider claims | Operators can inspect a safe test payload | Permission/nonce/bounds and allowlisted-output tests | Source present / runtime unverified |
| M3 — Nov 2026 | Deterministic source suggestions | Suggest only a non-ambiguous `utm_source`; never invent medium or campaign | Clear next action without fabricated taxonomy | Existing source preserved; ambiguity suppresses suggestion | Source present / runtime unverified; staging blocked |
| M4 — Dec 2026 | UTM hygiene | Detect macro/empty/conflict states and provide a copy-only test URL | Safer campaign test guidance without live URL mutation | URL safety fixtures plus browser/PHP parity and WordPress/browser evidence | Source present / runtime unverified; parity/staging blocked |
| M5 — Jan 2027 | Form readiness diagnostics | Compare expected, submitted, and named storage-surface evidence across the three form patterns | Agencies can locate form-path gaps without exposing submission values | Six adapter fixtures plus hook/cache/AJAX and staging proof | Source comparator + six synthetic fixtures validated locally; runtime/admin/provider proof Future |
| M6 — Feb 2027 | Woo conversion readiness | Explain source, consent, status, HPOS, event ID, and dedup state | Evidence-backed Woo implementation handoff | Checkout/status/refund/currency/HPOS/duplicate fixtures | M6-A contract + 16 synthetic scenarios validated locally; M6-B runtime Future |
| M7 — Mar 2027 | Consent foundation | Unify consent across capture, persistence, conversions, browser events, retries, and deletion | Consent story can be published only after live proof | Grant/deny/revocation/cross-tab/retry/erasure drills | M7-A pure decision/resolver contract validated locally; zero production wiring; runtime/legal proof Future |
| M8 — Apr 2027 | Delivery integrity | Harden action binding, payload bounds, replay, retry, dedup, and private-target checks | Delivery failures become visible and contained | Full negative-path fixture set; Delivery remains default-off | Future |
| M9 — May 2027 | Generic collector contract | Contract-test the generic collector end to end | First evidence-backed delivery proof artifact | Versioned fixture plus staged endpoint evidence | Future; test cost decision needed |
| M10 — Jun 2027 | sGTM staging contract | Verify ClickTrail → tagging server → owned destination | Evidence-backed Stape-adjacent setup path without hosting claim | End-to-end staging evidence | Future; infrastructure cost needed |
| M11 — Jul 2027 | Receipts/customer attribution | Add delivery receipts and Woo customer-level attribution; add provider breadth only where demand repeats | Better implementation handoff and renewal attribution evidence | Receipt, retention/erase, and renewal tests; no Pro UI | Future |
| M12 — Aug 2027 | Reporting/re-audit decision | Re-audit touch-event quality and decide Pro report and reach-page scope | Publish only paths whose evidence passed | Annual report and explicit publish/no-publish decisions | Future |

### Repository priorities

**P0 — now, documentation-only**

1. Keep one qualification convention across `README.md`, `README.en.md`,
   `README.pt-BR.md`, and `readme.txt`.
2. Reconcile the supported click-ID list and the historical adapter wording;
   do not rewrite historical changelog facts without an explicit correction
   note.
3. Resolve or explicitly label A-2 (pre-consent pending behavior), A-3
   (registry vocabulary), and A-4 (stale doc baselines); keep the A-1 tree/docs
   resolution recorded.
4. Align the stable tag only after the release owner verifies the authoritative
   WordPress.org/SVN state. Until then, do not publish “latest” claims.
5. Keep the readiness analyzer out of public copy until its untracked files
   are reviewed, committed, registered, permission-gated, and covered by smoke
   tests. Even after promotion, it must not be described as delivery or consent
   verification.

**P1 — next, still proof-led**

- publish a client handoff template with expected/observed payloads, consent
  states, version, and rollback ownership;
- build a compatibility matrix for WordPress, PHP, builders, form plugins,
  WooCommerce, and cache layers;
- add form and Woo contract fixtures for hidden fields, AJAX, validation,
  redirects, HPOS, status changes, reloads, and currency/value;
- document migrations from narrow UTM plugins without one-click compatibility
  promises; and
- make the install-to-proof recipe prominent in WordPress.org copy.

**P2 — gated reach and breadth**

- provider-specific pages only after L3 fixtures;
- Pro reporting/dashboard scope only after the M12 decision;
- readiness diagnostics only after commit, review, UI/registry wiring,
  ambiguity/no-persistence tests, and smoke coverage; and
- partner/agency materials only after support ownership and capacity are known.

**Do not build to match the competitors:** hosting, call tracking/DNI, lead
qualification, CRM revenue modeling/MMM, ad-spend optimization, non-WordPress
commerce platforms, or native Reddit delivery without a separate demand and
proof decision.

### Product Hunt demand overlay

Product Hunt discussions and reviews reinforce the existing roadmap rather
than justify a new product lane. Treat them as reported demand signals, not
proof of prevalence. Supporting evidence and forum links live in
`docs/guides/PRODUCT-HUNT-LAUNCH-AND-FORUM-PLAN-2026-08-23.md`.

| Priority | Reported pain or expectation | Existing ClickTrail lane | Minimum product outcome | Proof before public claim |
| --- | --- | --- | --- | --- |
| PH-0 | Campaign context disappears before the WordPress conversion and later appears as Direct | M1 Identify, M5 forms, M6 Woo | Show the observed first-touch and last-touch evidence on the final supported form entry or order without inventing a source | Precedence and ambiguity fixtures plus one clean-site form or Woo runtime recording |
| PH-1 | Users cannot tell whether attribution was captured, configured, verified, delivered, or blocked | M2 diagnostics, M5 forms, M6 Woo, M8 delivery | Use distinct evidence states and one concrete next action for each state | Permission, redaction, no-persistence, blocked-state, and runtime UI checks |
| PH-2 | Analytics and attribution setup feels too complex | M2 diagnostics, P1 install-to-proof | Make the first safe test path obvious from activation to one synthetic conversion record | Clean install, guided test, successful record inspection, and rollback check |
| PH-3 | Buyers want the attribution method explained clearly | M1 Identify contract | Expose source precedence, first-touch versus last-touch behavior, ambiguity, and unknown handling in plain language | Contract tests and matching admin, README, and WordPress.org wording |
| PH-4 | Agencies need evidence they can hand to a client | P1 handoff template, M5 forms, M6 Woo | Produce a redacted implementation proof containing version, expected and observed fields, consent state, evidence state, and owner | Export or copy output contains no values, secrets, PII, or unsupported delivery claim |
| PH-5 | UTM spreadsheets and inconsistent campaign naming create avoidable errors | M4 UTM hygiene | Detect empty, macro, and conflict states and offer a copy-only safe test URL | URL safety fixtures and PHP/browser parity proof |
| PH-6 | Privacy and consent claims trigger immediate technical questions | M7 consent foundation | Explain what capture, persistence, conversion, browser event, retry, and deletion do in each consent state | Grant, deny, revocation, cross-tab, retry, and erasure drills plus legal review before compliance wording |
| PH-7 | Cross-domain and provider claims are difficult to trust | M8 delivery, M9 collector, M10 sGTM | Separate configured capability from staged delivery proof and show unknown states honestly | Approved-domain negative tests, receipts, and provider-specific staged evidence |

#### Execution order

1. Finish PH-0 and PH-3 together because the conversion record is not useful
   unless the attribution rule and unknown behavior are explicit.
2. Promote PH-1 next because evidence-state clarity is ClickTrail's strongest
   Product Hunt differentiation and already aligns with the readiness work.
3. Ship PH-2 only as a thin install-to-proof path over existing diagnostics.
   Do not add a second onboarding system.
4. Build PH-4 from the same redacted diagnostic output. Do not create a report
   engine before a copy or export format proves insufficient.
5. Keep PH-5 in M4, PH-6 in M7, and PH-7 behind delivery and provider gates.

#### First implementation slice

The first bounded build is **conversion evidence clarity**, not a dashboard:

- label first-touch, last-touch, and unknown evidence separately;
- show where the observed evidence will be stored for one supported form or
  WooCommerce path;
- distinguish captured, configured, verified, and blocked;
- provide one safe next action for a blocked or unverified state; and
- keep values, identifiers, provider success, and compliance conclusions out
  of the UI.

Acceptance requires one focused contract test, one admin smoke check, and no
change to capture, consent, or delivery behavior.

### Release gates

| Lane | Promotion gate | Rollback/containment |
| --- | --- | --- |
| L0 docs | Smoke, links, JSON, and zero executable diff | Revert documentation only; do not imply runtime remediation |
| L1 consent | Zero-send-on-withdrawal and complete owned-data erasure drill | Keep claims at the prior evidence status |
| L2 delivery | Private-target rejection, replay/idempotency, retry, redaction, and failure fixtures | Keep Delivery default-off and downgrade ledger status |
| L3 provider | Per-provider versioned fixture plus staged delivery evidence | Keep the adapter configured-endpoint/runtime-unverified |
| Packaging | Release archive contains `config/feature-registry.json` and passes smoke | Republish or retain the prior installable tag |

## 4. Cross-repository message constitution

This section is the canonical message contract for public ClickTrail repository
copy. English defines the meaning. Translations may adapt phrasing, but may not
broaden a claim.

### Category, promise, and belief

- **Category:** first-party acquisition-context capture.
- **Shared promise:** Carry the right acquisition context from visit to
  conversion.
- **Core problem:** campaign context observed on arrival is often missing when
  a form, order, or application event is created later.
- **New belief:** a conversion record can use campaign context only if the
  implementation carries that context to its conversion boundary.
- **Mechanism:** observe source data, apply explicit first-touch and last-touch
  rules, persist it under host control, and attach it at configured boundaries.

“Right” means the context selected by documented precedence and merge rules. It
does not mean that ClickTrail proves causation, identifies a person across
devices, or decides which channel deserves revenue credit.

### Repository responsibility matrix

| Surface | Lead message | Evidence required | Do not imply |
| --- | --- | --- | --- |
| WordPress plugin | Carry campaign context to configured WordPress form and WooCommerce boundaries | Integration ledger, source path, focused smoke/test, and explicit runtime status | Complete attribution, universal form support, provider acceptance, or privacy compliance |
| JavaScript engine | Parse and merge observed acquisition context deterministically; persist or attach it only through host-configured adapters | Golden fixtures, unit tests, browser probe, and package status | Causal attribution, identity resolution, ad-platform configuration, or certified delivery |
| PHP and framework adapters | Carry request attribution through the named framework boundary | Repository source, focused tests, and one working example | A dashboard, complete commerce lifecycle, or support beyond the named adapter |
| GTM templates | Expose or forward the documented canonical fields inside GTM | Template permissions, tests, and example container contract | Pixel injection, vendor account setup, or destination success |
| Other integrations | State one supported handoff and its exact limits | Source, test, release, and example status from that repository | Parity with WordPress or JavaScript unless parity is tested |

### README sequence

GitHub readers are normally solution-aware. Use this order:

1. State the concrete context-loss problem.
2. Name the repository's one job.
3. Show the smallest working example or proof path.
4. State important limits beside the claim they qualify.
5. Give one next action.

Do not open with a feature inventory, an abstract infrastructure category, or
a claim that ClickTrail knows which click caused a sale.

### Vocabulary and claim rules

Prefer: **acquisition context**, **observed**, **first touch**, **last touch**,
**conversion boundary**, **configured**, **tested**, **verified**, and
**runtime-unverified**.

Do not publish:

- “The sale teaches the ad.” That belongs to Apointoo's outcome-feedback story,
  not ClickTrail's capture layer.
- “proves which click created the sale,” “complete attribution,” “all
  platforms,” “perfect matching,” or equivalent causal or universal claims;
- identity-resolution, revenue-feedback, optimization, dashboard, hosting, or
  lead-management claims; or
- “privacy compliant,” “GDPR-ready,” “secure,” “guaranteed,” or “reliable” as
  unqualified marketing claims.

### Translation rules

- Treat the English sentence as the semantic source, not as a word-for-word
  template.
- Use natural terms for the locale while preserving the same subject, action,
  object, qualifiers, and evidence status.
- Keep package names, code identifiers, event names, fields, commands, URLs,
  and evidence-state labels unchanged.
- Do not add proof, support, performance, compliance, or release claims during
  translation.
- Avoid em dashes in public copy.

### Paste-ready repository leads

**Shared:** Carry the right acquisition context from visit to conversion.

**WordPress:** ClickTrail captures first-touch and last-touch campaign context
in first-party storage and makes it available at configured WordPress form and
WooCommerce boundaries.

**JavaScript:** ClickTrail JS parses and merges observed acquisition context
deterministically, then lets the host persist or attach it through explicit
browser and server adapters.

**Required boundary:** ClickTrail preserves observed acquisition context. It
does not prove causation, resolve customer identity, configure ad platforms, or
certify downstream delivery.

### WordPress.org copy direction

Keep the short description factual:

> First-party campaign-context capture for configured WooCommerce and WordPress
> form paths, with explicit consent and delivery controls.

Lead the description with the problem and install-to-proof recipe, then show
the three form patterns. Keep platform/provider names in the evidence-qualified
integration section. Do not use review counts, “all platforms,” “native Reddit,”
“privacy compliant,” “secure,” “guaranteed,” or “reliable” as marketing claims.

The historical `1.5.0` adapter line is retained for changelog history. The
standing note above the changelog must explicitly say that historical wording
does not replace current ledger status and that the platform-named paths are
configured-endpoint relays pending runtime verification.

### Agency/client implementation offer

> **ClickTrail implementation and verification handoff**
>
> We map your forms, WooCommerce path, cross-domain hops, consent source, and
> configured destination; run a synthetic click-to-conversion trace; and hand
> you the expected and observed payloads, consent states tested, enabled
> capabilities, version, rollback steps, and ownership boundaries. Where an
> upstream provider path is not runtime-verified, we say so and verify only
> what your site actually demonstrates.

This is proposed service copy. Do not publish pricing, duration, SLA,
dedicated-support, white-label, or outcome promises until commercial ownership
and support capacity are decided.

## 5. Client-acquisition system

### Install-to-proof funnel

1. **Discovery:** WordPress.org copy and search-oriented pages for CF7 UTM,
   WooCommerce order attribution, GCLID/WooCommerce, consent-aware WordPress
   tracking, and attribution verification.
2. **Activation:** tagged test URL → browse → submit a configured form or place
   a test order → inspect the entry/order, `dataLayer`/GTM preview, and
   Diagnostics.
3. **Proof artifact:** one-page trace containing URL, expected values, observed
   values, event/correlation IDs, consent state, and redacted diagnostics.
4. **Implementation offer:** agency handoff using the offer above, with no
   unsupported white-label/resale promise.
5. **Support loop:** answer public support questions with reproducible steps,
   version boundary, and the relevant ledger entry. Treat support topics as
   learning inputs, not prevalence data.
6. **Referral/partner motion:** Future; defer until P0/P1 stability and support
   capacity are known.

Activation means **first synthetic event observed**, not an install, pageview,
or account creation.

### Content that can acquire without competitor disparagement

- How to verify that WordPress attribution survived to a form or order.
- Why paid WooCommerce orders appear as Direct and how to inspect order-meta
  attribution.
- Cross-domain attribution and hosted checkout: what a configured path can and
  cannot preserve.
- One consent decision for capture, events, and delivery.
- Portuguese-Brazilian versions of the two highest-intent guides.

Do not publish provider-specific setup pages until the relevant L3 gate passes.

### 30/60/90-day execution

| Window | Deliverable | State/owner |
| --- | --- | --- |
| 0–30 days | Apply P0 copy and metadata corrections; resolve/label A-2–A-4 and retain the A-1 resolution; publish the changelog correction note; refresh stale doc stamps | Owner decision needed |
| 31–60 days | Publish handoff and compatibility templates; choose a privacy-consistent activation telemetry design; start a support-response playbook | Owner decision needed |
| 61–90 days | Assign L1 consent owners; decide readiness-analyzer promotion; refresh the competitor snapshot before any comparison page | Owner decision needed |

No install, download, conversion, revenue, or partner targets are set: the
repository contains no baseline from which to derive defensible numbers.

### Measurement guardrails

Track only activation/support signals needed to improve onboarding, and keep
product telemetry separate from client campaign payloads. The telemetry
mechanism itself must respect the same consent posture ClickTrail advertises;
that mechanism is a **Decision needed** before instrumentation ships.

Candidate events:

- docs/search entry;
- recipe viewed;
- plugin installed;
- first capture configured;
- first synthetic event observed;
- first verified delivery (only after the corresponding evidence gate);
- support question;
- migration/audit request;
- repeat implementation; and
- partner referral (Future).

## 6. Open decisions and claim blockers

| Decision | Evidence/owner needed | Claim blocked until resolved |
| --- | --- | --- |
| Stable tag and release metadata | Release owner verifies WordPress.org/SVN and `RELEASING.md` | “Latest version” or stable-tag claims |
| A-2 pending-capture behavior | Code verification and schema reconciliation | Public statements about pre-consent buffering |
| A-3 registry vocabulary and A-4 stale docs | Integrations/docs owner sync | “Native adapter” or stale-version wording |
| Pricing, quotas, tax, margin, and packaging | Business inputs | Any price, trial, quota, or plan promise |
| Legal/privacy posture | External legal review | “GDPR-ready,” “privacy compliant,” or certification claims |
| Native Reddit delivery | Demand and fixture-cost decision | Any native Reddit conversion-delivery claim |
| Secret-free backup export | Security trade-off review | “Safe to share” backup claim |
| Calendly native signing | Provider documentation and E2E fixture | Native Calendly-signature claim |
| Readiness analyzer | Commit/review/UI/registry decision | Any analyzer or score feature claim |
| Product telemetry | Privacy-consistent design and owner | Activation-volume or analytics claims |
| Agency support boundary | Capacity and commercial owner | SLA, response-time, dedicated-support, or resale promises |
| Provider test budget | Business owner | Timeline or “verified Meta/Google/TikTok” promise |

The standing kill criterion is simple: if public copy says **privacy
compliant, secure, guaranteed, reliable, all platforms, native Reddit, secure
sGTM preview, or complete deletion** without the matching evidence record, stop
publication and return the claim to the current ledger state.

## 7. Verification checklist for this document

- [ ] All public feature claims point to a current repository source or an
      evidence-ledger entry.
- [ ] Competitor review/support signals are labelled as reported signals.
- [ ] No pricing, quota, SLA, legal conclusion, market-share, or runtime
      provider claim has been invented.
- [ ] README copy uses the same qualification convention as the ledger.
- [ ] `npm run smoke` passes after any copy or registry change.
- [ ] `git diff --check` passes.
- [ ] Any release claim is rechecked against the package and WordPress.org state.
