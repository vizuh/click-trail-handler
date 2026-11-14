# Feature Specification: A new touch replaces the whole last touch

**Feature Branch**: `Atroci/ct-wp-11-last-touch`

**Created**: 2026-11-14

**Status**: Implemented (Node VM `tools/qa/last-touch-replacement.test.js`, wired into `npm run test:attribution`).

**Input**: GitHub issue [#230](https://github.com/vizuh/click-trail-handler/issues/230). `Attribution.applyTouch('lt', ...)` in `assets/js/clicutcl-attribution.js` wrote only the fields the new touch carried, so every `lt_*` field and `lt_<click id>` the new touch lacked kept the previous campaign's value and the stored last touch mixed two visits.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - The last touch is exactly the new touch (Priority: P1)

A returning visitor whose stored last touch is a Google Ads click (`gclid=G1`, `utm_campaign=spring`) arrives from Google organic, from a newsletter link, or from a Meta ad. The stored last touch describes only that new visit.

**Independent Test**: Node VM with the real `clicutcl-attribution.js` and a fake document/cookie jar (pattern of `tools/qa/payment-referrer.test.js`).

**Acceptance Scenarios**:

1. **Given** the stored Google Ads touch, **When** the visitor arrives from `https://www.google.com/` with no parameters, **Then** the `lt_` keys are exactly `lt_source`, `lt_medium`, `lt_channel`, `lt_referrer`, `lt_landing_page`, `lt_touch_timestamp`.
2. **Given** the same stored touch, **When** the visitor arrives with `?utm_source=newsletter&utm_medium=email`, **Then** no `lt_campaign`, `lt_term`, `lt_content`, `lt_utm_*`, `lt_referrer` or `lt_<click id>` remains.
3. **Given** the same stored touch, **When** the visitor arrives with `?fbclid=F1`, **Then** `lt_fbclid=F1` is the only `lt_` click ID and no `lt_source`, `lt_medium`, `lt_campaign` or `lt_gclid` remains.

### User Story 2 - Everything outside the last touch is unchanged (Priority: P1)

**Acceptance Scenarios**:

1. First touch (`ft_*`) is write-once.
2. Top-level click IDs (`gclid`, `fbclid`, ...) and `click_id_history` keep newest-per-key; a Meta touch does not remove the top-level `gclid`.
3. A direct visit, an ignored payment referrer and a same-site referrer never form a touch: every `lt_*` value, including `lt_landing_page` and `lt_touch_timestamp`, is unchanged.
4. Selected-click-ID logic and the cross-domain token merge are unchanged.
5. Denied consent still clears stored attribution and stores nothing.

## Requirements

- **FR-001**: When `applyTouch('lt', ...)` runs, it first deletes every `lt_<key>` for each key in `TOUCH_FIELD_KEYS` (derived from `TOUCH_QUERY_FIELD_MAP`), `channel`, `referrer` and every key in `CLICK_ID_KEYS`, plus the legacy `lt_sc_click_id`, then writes the new touch. No second hand-maintained field list.
- **FR-002**: `applyTouch('ft', ...)` is unchanged.
- **FR-004**: `Store.getData()` reads the last touch as one unit. When the cookie and the localStorage mirror both exist, every `lt_*` key comes from the copy with the newest `lt_touch_timestamp` (the cookie on a tie); the two copies are never merged key by key for `lt_*`. Without this, a sibling subdomain on a shared `cookieDomain` cookie re-reads the deleted `lt_*` keys from its own mirror and writes them back into the shared cookie on its next save. All other keys keep the existing merge (cookie over localStorage over sessionStorage). Review round 1 of PR #234 found this gap; the fix widens the scope from `applyTouch`/`runAttribution` to the one read helper.
- **FR-003**: PHP readers (`Attribution_Provider`, WooCommerce/EDD `collect_order_attribution`, form adapters) already read `lt_*` through empty-checked lookups; no PHP change.

### Edge Cases

- **Shared `cookieDomain` cookie, per-origin localStorage**: `www.` mirrors the Google Ads touch, `shop.` replaces it with a newsletter touch, then `www.` has a direct visit and a new `_fbp`. The shared cookie must keep exactly the newsletter touch at every step (test `a sibling subdomain does not bring the old last touch back...`).

## Parity

Matches `@vizuh/clicktrail-core` `mergeAttributionTouch` for UTM fields and its opt-in `clearLastTouchClickIds` for click IDs.

## Out of scope

Channel resolution and key-list changes (WP-12), landing-page query redaction (#232), `gad_source` / `oppref` (#231).
