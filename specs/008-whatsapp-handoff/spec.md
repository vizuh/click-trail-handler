# Feature Specification: WhatsApp Handoff (click event + short reference code)

**Feature Branch**: `feat/whatsapp-handoff`

**Created**: 2026-11-02

**Status**: Implemented in source (PR for #145); live acceptance SC-003 open. Every claim below is source-present / runtime-unverified: the current behaviour was read from `main` @ `805a721` (1.12.0); the new behaviour is covered by Node VM and PHPUnit tests, and nothing has been exercised against a real WhatsApp client. The WordPress E2E harness (`tools/qa/e2e/`) was run only to prove no regression, it does not cover this feature.

**Input**: GitHub issue [#145](https://github.com/vizuh/click-trail-handler/issues/145). `initWhatsAppListener` (`assets/js/clicutcl-attribution.js`) appends `CT:<base64 of the whole store>` to the wa.me `text`. That text is long, exposes the full payload in the chat, and nothing on the server maps it back. No browser event fires on the click: `tel:` gives `contact_call_click` and `data-chat-trigger` gives `contact_chat_start` (`assets/js/clicutcl-events.js`), nothing produces `contact_whatsapp_start`, while `assets/gtm-starter-kit.json` shipped a `whatsapp_click` trigger without a producer. Related: [../001-calendly-booking-handoff/research.md](../001-calendly-booking-handoff/research.md) (the superseded server-handoff design this one borrows the pre-mint and gate pattern from).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A WhatsApp click is an event (Priority: P1)

A visitor with consent clicks a link to `wa.me`, `api.whatsapp.com`, `web.whatsapp.com`, `whatsapp.com` or a `whatsapp://` URL. ClickTrail pushes one `contact_whatsapp_start` to the `dataLayer` and, when server transport is on, to the v2 intake, carrying the link and the public business number.

**Why this priority**: it is the only WhatsApp signal that does not depend on the append setting, and the GTM starter kit already waits for it.

**Independent Test**: Node VM test (`tools/qa/whatsapp-handoff.test.js`) loading the real `clicutcl-events.js`: click an anchor to `https://wa.me/351912345678?text=Hi` with consent granted and assert one `contact_whatsapp_start` with `link_url`, `phone = "351912345678"`, `contact_type = "whatsapp"`.

**Acceptance Scenarios**:

1. **Given** consent granted and an anchor whose host is in the WhatsApp allowlist (or whose protocol is `whatsapp:`), **When** it is clicked, **Then** exactly one `contact_whatsapp_start` is pushed, with `link_url` (the URL without its `text` parameter and without a fragment) and `phone` (digits only, 7 to 15 of them, taken from the `wa.me/<digits>` path or the `phone` query parameter; empty when the link carries none).
2. **Given** consent denied or not yet resolved, **Then** nothing is pushed and no dedupe marker is left behind, and the URL is untouched.
3. **Given** the same link is clicked twice inside the dedupe window, **Then** the second click pushes nothing (`ClickTrailEvents.markOnce`), while a click on a different WhatsApp link, or after the window, pushes again.
4. **Given** the canonical v2 event, **Then** `buildCanonicalEvent` maps `contact_whatsapp_start` to `event_name = contact_whatsapp_start`, `funnel_stage = mid`, and `EventV2::BROWSER_EVENT_NAMES` accepts it on the public browser route.
5. **Given** the GTM starter kit, **Then** the trigger and GA4 tag are named `contact_whatsapp_start` (was `whatsapp_click`), built from `assets/build-starter-kit.py`.

---

### User Story 2 - The prefilled text carries a short reference code (Priority: P1, only when the setting is on)

With `whatsapp_append_attribution` on and consent granted, the prefilled message ends with `Ref: CT-<8 base32 chars>` instead of the base64 blob, and the server can map the code back to the visitor's attribution.

**Independent Test**: the same VM harness plus PHPUnit (`tests/unit/WhatsappRefTest.php`).

**Acceptance Scenarios**:

1. **Given** the setting on, consent granted, a stored attribution and a registered code, **When** a WhatsApp link is clicked, **Then** the href's `text` ends with `Ref: CT-` plus 8 characters from `A-Z2-7`, the base64 `CT:` blob is absent, and a second click does not stack another reference (the original href is kept in `data-clicutcl-href`).
2. **Given** consent is withdrawn after decoration, **Then** `Decorator.restore()` returns the original href, a later click adds nothing, and the registered code is forgotten.
3. **Given** the setting on but no attribution is stored, or the code could not be registered, **Then** the link stays untouched (no unmapped reference is ever sent) and the click event still fires, without `ref_code`.
4. **Given** the setting off (default), **Then** no code is generated, no request is sent and no href changes; the click event of User Story 1 still fires.

---

### User Story 3 - The server maps a code back to the attribution (Priority: P1)

**Acceptance Scenarios**:

1. **Given** a client token, consent (or consent not required), the setting on, and a valid `{code, visitor_id, selected_click_id_key, data}` body, **When** `POST /clicutcl/v2/whatsapp-ref` runs, **Then** a row is stored with the code, `visitor_id`, the first-touch / last-touch / click-ID snapshot, the selected click ID, `created_at` and `expires_at = created_at + attribution retention` (`cookie_days`, default 90), and the response is `{success: true, code, expires_at}`.
2. **Given** a code that already exists, **Then** the route answers 409 `whatsapp_ref_collision`, the existing row is untouched, and the client generates a new code and retries exactly once.
3. **Given** no client token, a bad token, a body over 16 KB, a rate-limited caller, the setting off, or required-but-denied consent, **Then** the route answers 401 / 413 / 429 / 403 and writes nothing.
4. **Given** an administrator (`manage_options`), **When** `GET /clicutcl/v2/whatsapp-ref/<code>` runs (or the Diagnostics lookup box is used), **Then** the snapshot is returned. **Given** an unknown or expired code, **Then** the answer is 404 `whatsapp_ref_not_found`. **Given** a non-administrator, **Then** 401 / 403.
5. **Given** the daily cleanup, **Then** expired rows (and rows older than the current retention) are deleted in bounded batches; **given** the privacy eraser for an email whose logged events carry a `visitor_id`, **then** the reference rows of those visitor IDs are deleted; **given** uninstall, **then** the table is dropped unless `clicutcl_preserve_data_on_uninstall`.

---

### Edge Cases

- A click before the code is confirmed leaves the link undecorated; the event still fires. The code is requested on the first pointer-over, focus or pointer-down on an allowlisted link, on a click, or on the first touch anywhere on a page that holds one (a tap is too short to register in time). A page view alone registers nothing, so visitors who never interact create no row.
- One code is reused for the rest of the tab session while the attribution snapshot is unchanged (`sessionStorage`); a changed snapshot mints a new code. Row growth is bounded by distinct attributions per tab session, not by page views.
- A cached page shares one client token across visitors; the per-token-nonce limit (20 per IP) and the per-IP rate limit bound a single caller.
- `wa.me/message/<id>` and `chat.whatsapp.com` style links carry no digits: `phone` is empty. Group-invite hosts are not in the allowlist.
- Collision probability: 8 characters of base32 is 40 bits. For `N` live rows the chance that a new code collides is about `N / 2^40`: roughly 9e-8 at 100 000 rows and 9e-7 at one million. Pre-minting makes the retry effective (the link is decorated only with a confirmed code), so a collision never produces a wrong mapping.
- `crypto.getRandomValues` missing: no code is generated, no fallback to `Math.random`.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: `clicutcl-events.js` MUST push `contact_whatsapp_start` for a click on an anchor whose hostname is `wa.me`, `whatsapp.com`, `api.whatsapp.com` or `web.whatsapp.com`, or whose protocol is `whatsapp:`, through `pushEvent` (consent gate), after `markOnce` dedupe that runs only when consent is granted.
- **FR-002**: The event MUST carry `link_url`, `phone` (digits only, may be empty), `contact_type = whatsapp`, `cta_label`, and `ref_code` when the anchor was decorated with one.
- **FR-003**: `EventV2::BROWSER_EVENT_NAMES` and `buildCanonicalEvent` MUST include `contact_whatsapp_start` (funnel stage `mid`).
- **FR-004**: `assets/build-starter-kit.py` MUST name the WhatsApp trigger and GA4 tag `contact_whatsapp_start`; `assets/gtm-starter-kit.json` MUST be regenerated from it.
- **FR-005**: With `enable_whatsapp` and `whatsapp_append_attribution` on and live consent, `initWhatsAppListener` MUST append `Ref: CT-<8 chars of A-Z2-7>` to `text`, generated with `crypto.getRandomValues` and registered on the server first; it MUST NOT append the base64 store; the setting default MUST stay off.
- **FR-006**: `POST /clicutcl/v2/whatsapp-ref` MUST follow the `attribution-token/sign` gates (feature flag, body size, per-IP rate limit, client token, per-token-nonce limit) plus the server-side consent gate (`Consent::is_required()` and `Consent::marketing_allowed()`), validate the code (`^[A-Z2-7]{8}$`, exactly 8), refuse past 20 live rows per `visitor_id` (429) and 50 000 rows in the table (503), both filterable, sanitise the attribution through the existing signed-token allowlist, and answer 409 on collision.
- **FR-007**: Rows MUST live in `{prefix}clicutcl_whatsapp_refs` (`DB_VERSION` 5), expire at `created_at + cookie_days`, be purged by `Cleanup::run_cleanup()` and the Diagnostics purge, be exported and erased by `Privacy_Handler` through the visitor IDs found in the subject's logged events (one matching rule), and be dropped by `uninstall.php`.
- **FR-008**: `GET /clicutcl/v2/whatsapp-ref/<code>` MUST require `manage_options`, accept the code with or without the `CT-` prefix and in any case, and return 404 for an unknown or expired code. The Diagnostics page MUST offer the same lookup.
- **FR-009**: Docs MUST be updated: `INTEGRATIONS.md`, `MASTER-SPECIFICATION.md` CT-013, `HOOKS-REFERENCE.md`, `REST-API.md`, `SECURITY-PRIVACY.md` (storage and retention), `DATA-MODEL.md`, `SETTINGS-AND-ADMIN.md`, `readme.txt`, `README.en.md`, `changelog.txt`.

### Key Entities

- **Reference code**: `CT-` plus 8 characters of `A-Z2-7`; stored without the prefix.
- **Reference row**: `code`, `blog_id`, `visitor_id`, `selected_click_id_key` / `selected_click_id`, `snapshot` JSON (`first_touch`, `last_touch`, `click_ids`), `created_at`, `expires_at`.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: VM test: one click on an allowlisted link with consent yields exactly one `contact_whatsapp_start`; denied consent yields zero; a repeat inside the window yields zero; the prefilled text matches `/Ref: CT-[A-Z2-7]{8}$/` and contains no `CT:` blob; withdrawal restores the original href byte for byte.
- **SC-002**: PHPUnit: the create route rejects (no token, bad token, oversize, setting off, denied consent), accepts a valid body, returns 409 on a repeated code, stores `expires_at = created_at + retention`; lookup returns 404 for unknown and expired codes and is `manage_options`-only; the eraser path deletes the rows of the visitor IDs it is given; the Diagnostics purge, cleanup and uninstall reference the table.
- **SC-003**: Live (open): a real click on a `wa.me` link from a consented tagged landing shows the `Ref:` in the WhatsApp compose box and the admin lookup returns the click ID. Until this passes the feature stays source-present / runtime-unverified.

## Assumptions

- The WhatsApp client keeps the `text` parameter as typed message text; a visitor may edit or delete the `Ref:` line, in which case the mapping is simply lost.
- The business number in the link is public and is not personal data about the visitor.
- `home_url()` host and the page that serves the link share one origin with the REST route (same-origin `fetch` with cookies, as the existing tracking calls).
