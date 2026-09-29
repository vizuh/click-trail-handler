# Feature Specification: Calendly Booking Attribution Handoff

**Feature Branch**: `feat/calendly-booking-handoff`

**Created**: 2026-09-29

**Status**: Implemented in #106 and #108 with the compact `salesforce_uuid` stamp instead of the handoff record (see research.md). A live Calendly booking (SC-004) is still pending.

**Input**: GitHub issue [#102](https://github.com/vizuh/click-trail-handler/issues/102). When a Calendly booking is the durable conversion record, ClickTrail must connect it to the consented visit that produced it, without pushing raw click IDs through Calendly.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A paid click reaches the booking record (Priority: P1)

A visitor lands from Google Ads (`?gclid=`), grants marketing consent, and books through a Calendly link on the site. When Calendly's `invitee.created` webhook reaches ClickTrail, the canonical `book_appointment` event carries the original first-touch and last-touch attribution, including the `gclid`.

**Why this priority**: This is the whole value of the handoff. Offline-conversion chains stay silent without it. Public production evidence: `bfroos/myhb-store#126` reports 739 of 749 Calendly bookings missing their attribution stamp.

**Independent Test**: Decorate a Calendly URL in a browser test, extract the ClickTrail reference from `salesforce_uuid`, replay a signed `invitee.created` fixture containing that `tracking` block, and assert that the canonical event includes the original `gclid`.

**Acceptance Scenarios**:

1. **Given** consent is granted and the attribution holds a `gclid`, **When** the visitor clicks a `calendly.com` link, **Then** the URL carries `utm_*` and a ClickTrail reference in `salesforce_uuid`, and carries no raw click-ID parameters.
2. **Given** a signed `invitee.created` payload whose `tracking.salesforce_uuid` holds a valid reference, **When** the webhook is processed, **Then** the canonical event's attribution matches the stored first/last touch, including `gclid`, `gbraid` or `wbraid` when present.
3. **Given** the reference is unknown, expired or forged, **When** the webhook is processed, **Then** the event is still recorded, with only the `tracking.utm_*` values Calendly preserved and a diagnostic reason. It does not fail.

---

### User Story 2 - Calendly embeds are decorated too (Priority: P2)

A site uses Calendly's inline or popup widget instead of a plain link. The widget URL is decorated the same way.

**Why this priority**: Many booking pages use the embed rather than a link, and anchor-click decoration never reaches those.

**Independent Test**: Load a page with an inline widget container (`.calendly-inline-widget[data-url]`) and a `Calendly.initPopupWidget({ url })` call. Assert both URLs carry the reference once consent is granted.

**Acceptance Scenarios**:

1. **Given** an inline widget `data-url` and granted consent, **When** the page initialises, **Then** the widget loads with `utm_*` and `salesforce_uuid` set.
2. **Given** the host calls `Calendly.initPopupWidget` or `initInlineWidget` after ClickTrail loads, **When** consent is granted, **Then** the URL passed to Calendly is decorated.
3. **Given** the host already set `salesforce_uuid` or a `utm_*` key, **When** decoration runs, **Then** the host's value is kept (never overwritten).

---

### User Story 3 - Calendly can post directly to ClickTrail (Priority: P2)

A site owner registers ClickTrail's webhook URL in a Calendly webhook subscription with a signing key. Calendly's native signature is accepted, so no relay is needed.

**Why this priority**: `Webhook_Auth::verify_request()` has provider branches for Typeform and HubSpot only. Calendly falls through to ClickTrail's own `x-clicutcl-*` scheme, which Calendly cannot produce.

**Independent Test**: A PHPUnit fixture signed with `Calendly-Webhook-Signature: t=<ts>,v1=<hmac>` passes, and tampered, stale or missing signatures fail.

**Acceptance Scenarios**:

1. **Given** a body signed per Calendly's scheme with the configured key, **When** it is posted, **Then** authentication passes.
2. **Given** a timestamp outside the replay window or a mismatched signature, **When** it is posted, **Then** a 401 is returned and nothing is dispatched.

---

### Edge Cases

- Consent is denied, pending or withdrawn: no decoration, and nothing is minted or stored. A withdrawal after decoration invalidates the stored handoff record.
- The visitor books days after clicking the link: the reference must still resolve within the configured retention window.
- One visitor books several times: each booking resolves the same reference, and the event is deduplicated by the Calendly invitee URI (as it is today).
- The reference is present but attribution was erased by a privacy request: the event is recorded with no attribution.
- Multisite: a reference minted on blog A must not resolve on blog B.
- Calendly's `salesforce_uuid` length limit is unknown (see research.md R2).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: For Calendly destinations, the decorator MUST set `salesforce_uuid` to an opaque ClickTrail handoff reference and MUST NOT append raw click-ID parameters.
- **FR-002**: The decorator MUST keep appending `utm_*` for Calendly, which Calendly preserves in `tracking`.
- **FR-003**: Decoration MUST run only when marketing consent is resolved and granted, which is the existing decorator gate.
- **FR-004**: Inline widget `data-url` attributes and the URLs passed to `Calendly.initInlineWidget` / `initPopupWidget` MUST be decorated under the same rules.
- **FR-005**: The server MUST resolve a handoff reference to the attribution snapshot and the consent snapshot captured when the reference was minted.
- **FR-006**: `CalendlyWebhookAdapter::map_to_canonical()` MUST read `payload.tracking.{utm_*, salesforce_uuid}` and attach the resolved attribution to the canonical event.
- **FR-007**: An unresolvable reference MUST degrade to `tracking.utm_*` only, with a diagnostic reason. It MUST NOT drop the event.
- **FR-008**: `Webhook_Auth` MUST verify Calendly's native `Calendly-Webhook-Signature` (timestamp plus HMAC-SHA256) with a replay window.
- **FR-009**: Handoff records MUST be erased by the existing privacy exporter/eraser and removed by the daily cleanup after the retention window.
- **FR-010**: `docs/reference/INTEGRATIONS.md`, `REST-API.md`, `DATA-MODEL.md` and `SECURITY-PRIVACY.md` MUST describe the new record, the header and the retention. The Calendly row stays runtime-unverified until a live booking is proven.

### Key Entities

- **Handoff reference**: a short, random, URL-safe identifier. It carries no attribution itself and is scoped to one blog.
- **Handoff record**: a server-side row keyed by the reference. It holds the attribution snapshot (first/last touch and click IDs), the consent snapshot at mint time, `blog_id`, `created_at` and `expires_at`.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In the replay test, 100% of `invitee.created` fixtures with a valid reference produce an event whose `gclid` equals the landing-page value.
- **SC-002**: 0 raw click-ID parameters appear in any decorated Calendly URL across the browser test matrix.
- **SC-003**: 0 handoff records are created while consent is pending or denied.
- **SC-004**: A live Calendly test booking on a disposable site shows the reference in `tracking.salesforce_uuid` and a resolved event in diagnostics. Until that passes, the integration keeps its runtime-unverified label.

## Assumptions

- Calendly keeps `utm_campaign`, `utm_source`, `utm_medium`, `utm_content`, `utm_term` and `salesforce_uuid` from the scheduling URL in the invitee's `tracking` object, and drops other parameters. This must be re-verified against current Calendly docs (research.md R1).
- The link decorator's allowed-domain configuration already governs which outbound hosts are decorated. Calendly is added only when the site owner allows it.
- Offline-conversion upload to ad platforms is out of scope. This feature ends at an attributed canonical event.
