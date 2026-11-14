# Feature Specification: Sensitive query redaction for stored landing page and referrer

**Feature Branch**: `Atroci/ct-wp-13-query-redaction`

**Created**: 2026-11-14

**Status**: Implemented (Node VM `tools/qa/sensitive-query.test.js`; PHPUnit `SensitiveQueryRedactionTest`, `PaymentReturnAttributionTest`). Browser behaviour is proven in the Node VM only; no real browser or WordPress page load was made.

**Input**: GitHub issue [#232](https://github.com/vizuh/click-trail-handler/issues/232). SDK counterpart: `vizuh/clicktrail-js#83`.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Personal data in the landing URL (Priority: P1)

A visitor lands on `/pricing/?utm_source=mail&utm_medium=email&email=jane%40example.com&token=SECRET123&phone=5551234`. Today that whole URL is stored in `ft_landing_page` and `lt_landing_page` and copied to the cookie, the local-storage mirror, form fields, order meta and server events.

**Independent Test**: `tools/qa/sensitive-query.test.js` runs the real `clicutcl-attribution.js` in a VM and records every cookie, storage and `dataLayer` write.

**Acceptance Scenarios**:

1. **Given** the landing above, **Then** `ft_landing_page` and `lt_landing_page` are `/pricing/?utm_source=mail&utm_medium=email`, and `jane`, `SECRET123` and `5551234` appear in no write.
2. **Given** `user_email=...`, **Then** it is removed (`_<key>` suffix). **Given** `monkey=1` or `keyword=x`, **Then** they are kept.
3. **Given** `key=...` or `api_key=...`, **Then** they are removed. `monkey` is not matched: only the exact name or an `_key` suffix.
4. **Given** `utm_*`, `gclid`, `fbclid`, `gad_source`, `oppref`, `mc_cid`, **Then** they are kept in their original order and encoding, even when the localized list names them.
5. **Given** no parameter remains, **Then** the `?` is dropped. The fragment is never stored.

### User Story 2 - Referrer (Priority: P1)

**Acceptance Scenarios**:

1. **Given** a referrer `https://partner.example.org/review?id=7&email=jane%40example.com&session=S3`, **Then** `lt_referrer` is `https://partner.example.org/review?id=7`.
2. **Given** the pending capture that survives a consent prompt, **Then** its `landingPage`, `referrer` and `params` hold no sensitive keys. The cross-domain `ct_token` stays in `params` because promotion reads it.

### User Story 3 - Cross-domain token and old cookies (Priority: P1)

**Acceptance Scenarios**:

1. **Given** a verified cross-domain token, **Then** the merged `lt_landing_page` is the redacted URL, and landing page or referrer values carried by the verified token data are redacted.
2. **Given** an `attribution` cookie written by an older version with a raw landing page or referrer, **Then** the script returns it redacted, and `Attribution_Provider::sanitize()` redacts it on the server (forms, orders, events), including when the key is the `first_landing_page` alias.

### User Story 5 - Events (Priority: P1)

**Acceptance Scenarios**:

1. **Given** a second same-origin page whose `document.referrer` is the landing URL of scenario 1, **Then** the lead's `marketing_trail.referrer` and `page_context.referrer` are `/pricing/?utm_source=mail&utm_medium=email` (host kept), in the dataLayer push and in the collector request.
2. **Given** the attribution script is not loaded, **Then** the events script sends the referrer without query or fragment.
3. **Given** a payload whose `page.referrer`, `marketing_trail.landing_page`/`referrer` or cookie-derived `lt_`/`ft_` landing page and referrer carry the raw URL, **Then** `Event::normalize()` stores them redacted, before sanitizing, so the retry queue never holds the raw URL.

### User Story 4 - Filterable list (Priority: P2)

**Acceptance Scenarios**:

1. **Given** a `clicutcl_sensitive_query_keys` callback, **Then** its normalized result (lowercase, trimmed, unique strings) is `clicutcl_config.sensitiveQueryKeys` and is the list both sides use.
2. **Given** a filtered list that names `gclid` or `utm_source`, **Then** those are still kept.

## Requirements

- **FR-001**: A parameter is removed when its lowercased, URL-decoded name is in the list or ends with `_<word>` for a word in it. Default words: email, e-mail, mail, phone, tel, mobile, token, access_token, id_token, auth, password, pass, pwd, secret, key, api_key, apikey, code, otp, session, sessionid, sid, signature, sig, nonce, ssn, card, iban.
- **FR-002**: `utm_*`, captured click IDs (`CLICK_ID_KEYS`, `sc_click_id`) and paid markers are never removed.
- **FR-003**: Kept parameters keep order and raw encoding. The `?` is dropped when none remain. The fragment is dropped.
- **FR-004**: Applied before storage at `applyTouch` (first and last touch), `getExternalReferrerDetails`, the cross-domain token merge, `PendingCapture`, and when stored values are read (`sanitizeAttributionData`, `Attribution_Provider::sanitize`).
- **FR-005**: The list is `Attribution_Provider::SENSITIVE_QUERY_KEYS`, filtered by `clicutcl_sensitive_query_keys`, localized as `clicutcl_config.sensitiveQueryKeys`. The script keeps the same default for a page without the localized config.
- **FR-006**: The events script redacts `page_context.referrer` and the `marketing_trail` `landing_page`/`referrer` (including the `document.referrer` fallback) with `window.ClickTrail.redactSensitiveQuery`, and drops the query when that function is absent. `Server_Side\Event::normalize()` redacts `page.referrer` and the trail `landing_page`/`referrer` before sanitizing.
- **FR-007**: No cookie name, option name, REST route or JSON shape changes.

## Out of scope

- Event rows and queue entries already written are not rewritten.
- Rewriting order meta or event rows already stored.
- Matching on values (an address in a parameter named `q`), and names outside the list (`jwt`, `reset`); sites add those with the filter.
- Last-touch replacement (#230, WP-11) and paid markers (#231, WP-12), which share `applyTouch` but are separate behaviour.
