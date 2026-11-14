# Feature Specification: Paid markers `gad_source`, `gad_campaignid` and `oppref`

**Feature Branch**: `Atroci/ct-wp-12-gad-oppref`

**Created**: 2026-11-14

**Status**: Implemented (Node VM `tools/qa/paid-markers.test.js`; PHPUnit `ChannelResolverTest`, `ClickIdPrecedenceTest`, `WooOrderPrivacyTest`). Browser behaviour is proven in the Node VM only; no real Google Ads or ChatGPT Ads click was made.

**Input**: GitHub issue [#231](https://github.com/vizuh/click-trail-handler/issues/231). SDK counterpart: `vizuh/clicktrail-js#82`.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Google Ads visit without a `gclid` (Priority: P1)

Safari link tracking protection, Brave or an ad blocker strip `gclid`, but `gad_source` and `gad_campaignid` survive. Today such a visit from `google.com` is stored as Google Organic.

**Independent Test**: `tools/qa/paid-markers.test.js` runs the real `clicutcl-attribution.js` in a VM.

**Acceptance Scenarios**:

1. **Given** a landing with only `?gad_source=1&gad_campaignid=999` from `https://www.google.com/`, **Then** `gad_source`, `lt_gad_source`, `ft_gad_source` (and the same three for `gad_campaignid`) are stored, `lt_channel` and `ft_channel` are `Google Ads`, and `lt_medium` is `cpc`.
2. **Given** the same landing with an explicit `utm_medium`, **Then** that medium is kept.
3. **Given** `gclid` next to `gad_source`, **Then** the channel is `Google Ads` and the selected click ID is the `gclid`.

### User Story 2 - ChatGPT Ads (Priority: P1)

`oppref` marks a click from a ChatGPT ad. Today it is stored as `chatgpt.com / referral`, the same as an unpaid referral.

**Acceptance Scenarios**:

1. **Given** `?oppref=abc123` from `https://chatgpt.com/`, **Then** `oppref`, `lt_oppref`, `ft_oppref` are stored, `lt_channel` is `ChatGPT Ads`, `lt_medium` is `cpc`, and `lt_source` is `chatgpt`.
2. **Given** an explicit `utm_source` or `utm_medium`, **Then** those are kept.
3. **Given** a chatgpt.com referral without `oppref`, **Then** the channel stays `ChatGPT` with source `chatgpt.com` and medium `referral`. Other AI organic labels are unchanged.

### User Story 3 - Markers are not click IDs (Priority: P1)

**Acceptance Scenarios**:

1. **Given** a landing with only `gad_source` and/or `oppref`, **Then** `attribution_selected_click_id` is never set and `click_id_history` gets no entry.
2. **Given** a stored `gclid` selection and a later `gad_source`-only landing, **Then** the selection stays the `gclid`.
3. **Given** the markers, **Then** they are not in `Attribution_Provider::get_click_id_fields()`, the D3 list, the Calendly stamp or ad-platform adapters.

### User Story 4 - Server side (Priority: P1)

**Acceptance Scenarios**:

1. **Given** the `attribution` cookie holds the markers, **Then** `Attribution_Provider::get_field_mapping()` lists `gad_source`, `ft_gad_source`, `lt_gad_source` (same for `gad_campaignid` and `oppref`), so forms fill `ct_gad_source`, `ct_lt_gad_source` and so on, and Woo/EDD order meta stores `_clicutcl_gad_source` and its `ft_`/`lt_` forms.
2. **Given** those order meta keys, **Then** `Woo_Order_Privacy::is_managed_meta_key()` accepts them (export, erase and retention cover them), because the managed keys derive from the field mapping.
3. **Given** a payload with `lt_gad_source`, **Then** `Attribution_Provider::sanitize()` mirrors it to the top-level key, last touch winning.
4. **Given** `gad_source` or `oppref` in a Gravity Forms payload, **Then** `Gf_Channel_Resolver` returns `Google Ads` or `ChatGPT Ads`; `gad_campaignid` alone is no signal; a click ID such as `gclid` outranks `oppref`.

## Requirements

- **FR-001**: The three keys are captured from the URL, 128 characters at most, top level plus `ft_`/`lt_`.
- **FR-002**: `gclid`, `gbraid`, `wbraid` or `gad_source` give `Google Ads`; `oppref` gives `ChatGPT Ads` (after the other paid click IDs).
- **FR-003**: Medium `cpc` is added only when the touch has no `utm_medium` and carries `gclid`, `gbraid`, `wbraid`, `gad_source` or `oppref`; source `chatgpt` only when it has no `utm_source` (`oppref`). A `gad_source` landing has no source field (like a bare `gclid`), so `lt_source` and `lt_referrer` stay empty.
- **FR-004**: The markers are never selectable click IDs and never reach the Calendly stamp.
- **FR-005**: `assets/gtm-starter-kit.json` is unchanged.

## Out of scope

- A default source for Google click IDs and `gad_source`.
- `applyTouch` stale-field behaviour (WP-11, #230).
- The signed attribution token allowlist and outbound link decoration.
