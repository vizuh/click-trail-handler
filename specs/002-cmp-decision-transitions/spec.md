# Feature Specification: Attribution Across CMP Decision Transitions

**Feature Branch**: `fix/cmp-decision-transitions`

**Created**: 2026-09-29

**Status**: Implemented in #105. Also fixed the Cookiebot in-page withdrawal and Complianz category parsing bugs found while doing this work.

**Input**: GitHub issue [#103](https://github.com/vizuh/click-trail-handler/issues/103). Some CMPs wipe browser storage at the moment the visitor answers the banner (reproduced in production for Cookiebot in `bfroos/myhb-store#168`). ClickTrail must keep landing-page attribution when consent is granted, leave nothing behind when it is denied, and tell site owners how to classify its storage, all without writing anything before consent.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Accept on the landing page keeps the click (Priority: P1)

A visitor lands with `?gclid=`, the Cookiebot banner is shown, and the visitor clicks "Accept all" on the same page. After Cookiebot's decision-time storage wipe, ClickTrail's attribution holds the `gclid` and survives the next page load.

**Why this priority**: Every paid click is a first visit, and most first visits answer the banner before converting. If this path breaks, paid attribution is lost for consent-required sites.

**Independent Test**: A Playwright probe with a Cookiebot-compatible stub (or a real Cookiebot test domain) wipes every unclassified key right before dispatching `CookiebotOnConsentReady`. It then asserts that the `attribution` cookie and storage hold the `gclid`, and still do after navigating.

**Acceptance Scenarios**:

1. **Given** required consent, a pending banner, and `?gclid=X` in the URL, **When** the CMP wipes storage and then fires its decision event with marketing granted, **Then** ClickTrail's attribution contains `gclid=X`.
2. **Given** step 1 is complete, **When** the visitor loads a second page, **Then** the attribution is still present.

---

### User Story 2 - Reject leaves nothing behind (Priority: P1)

The visitor clicks "Reject". Afterwards no ClickTrail cookie, localStorage or sessionStorage key exists except the consent-state record the bridge needs to remember the refusal.

**Why this priority**: This is a legal requirement. Today `API.install()` runs from `handleConsentDenied()` and writes `localStorage['clicutcl_js_last_seen']` (`assets/js/clicutcl-attribution.js`) even after a rejection. Nothing reads that key.

**Independent Test**: The same probe clicks "Reject" and enumerates `document.cookie`, `localStorage` and `sessionStorage`. The only ClickTrail key allowed is the consent-state key.

**Acceptance Scenarios**:

1. **Given** a pending banner, **When** the visitor rejects, **Then** only the consent-state key remains, and `clicutcl_js_last_seen` is absent.
2. **Given** consent was previously granted, **When** it is withdrawn, **Then** all attribution and identity keys are removed (existing behavior, now asserted in the probe).

---

### User Story 3 - Site owners know how to classify ClickTrail storage (Priority: P2)

A site owner using Cookiebot, OneTrust or Complianz can look up every ClickTrail cookie and storage key, its purpose, and the category it belongs in, so the CMP scanner doesn't list them as "unclassified" and delete them on later consent changes.

**Why this priority**: The myhb-store incident was triggered by an unclassified key. The docs list supported CMP sources but have no key inventory.

**Independent Test**: A docs check that every storage key written in `assets/js/*.js` appears in the classification table. It fails on drift.

**Acceptance Scenarios**:

1. **Given** the docs, **When** a key is added to the JS, **Then** the inventory check fails until the table lists it.

---

### Edge Cases

- The visitor navigates before answering the banner, in required-consent mode. The pre-consent click ID is **not** kept. This is intended, because nothing is stored before consent. Docs and code comments must say so; the Phase 2 comment in `runAttribution()` currently says the opposite.
- The CMP fires `OnAccept` before `OnConsentReady`, or fires both. Capture must be idempotent.
- The decision event fires before `clicutcl-attribution.js` loads. The bridge reads `Cookiebot.hasResponse` synchronously (existing path) and capture uses the current URL.
- `relaxed` mode or consent not required: behavior is unchanged.
- A CMP wipe happens later (a consent change on a subsequent page). Classification docs are the mitigation; ClickTrail doesn't fight the CMP.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: ClickTrail MUST NOT write any cookie or storage key before marketing consent is granted when consent is required. This includes `ct_pending_v1` (already gated since v1.8.13) and `clicutcl_js_last_seen` (currently ungated).
- **FR-002**: After a denial, the only ClickTrail key allowed is the bridge's consent-state record.
- **FR-003**: On grant, capture MUST read the current URL and referrer after the CMP decision event, so writes land after any decision-time wipe.
- **FR-004**: Capture on grant MUST be idempotent across repeated CMP events.
- **FR-005**: The stale Phase 2 comment in `runAttribution()` MUST be corrected to describe the real pre-consent navigation boundary.
- **FR-006**: `docs/guides/SECURITY-PRIVACY.md` MUST contain a storage inventory: every key, where it is stored, its purpose, its lifetime, and the recommended CMP category. `docs/tutorials/03-consent-and-events.md` MUST link to it with Cookiebot, OneTrust and Complianz notes.
- **FR-007**: A repeatable check MUST compare the keys written by the JS with the inventory table.

### Key Entities

- **Storage inventory**: the key name, the medium (cookie, localStorage or sessionStorage), the writer file, the purpose, the lifetime, whether it is written before consent (must be "no" except the consent state), and the recommended category.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: The probe's accept-after-wipe scenario keeps the `gclid` in 100% of runs across Chromium, Firefox and WebKit.
- **SC-002**: The probe's reject scenario finds exactly one ClickTrail key, the consent state.
- **SC-003**: The inventory check passes with no unlisted key.

## Assumptions

- Recording the consent decision itself is strictly necessary (the CMP and the bridge both need it).
- ClickTrail will not ask site owners to classify attribution keys as "Necessary". The inventory recommends Marketing for attribution and click IDs, and Statistics for session and visit counters.
- A real Cookiebot account for live proof is optional. The stub reproduces the documented wipe-before-event ordering observed in `bfroos/myhb-store#168`.
