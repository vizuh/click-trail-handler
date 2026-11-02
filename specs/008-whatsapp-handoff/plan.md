# Implementation Plan: WhatsApp Handoff (click event + short reference code)

**Branch**: `feat/whatsapp-handoff` | **Date**: 2026-11-02 | **Spec**: [spec.md](spec.md) | **Issue**: [#145](https://github.com/vizuh/click-trail-handler/issues/145)

## Summary

`clicutcl-events.js` pushes `contact_whatsapp_start` for allowlisted WhatsApp links (consent-gated, `markOnce` dedupe). With the append setting on, `clicutcl-attribution.js` pre-registers a client-generated `CT-<8 base32>` code through a new `POST /clicutcl/v2/whatsapp-ref` (client token, consent, rate limit, size limits), stored in a new `clicutcl_whatsapp_refs` table with a TTL equal to attribution retention, and decorates the link's `text` with `Ref: CT-...` instead of the base64 store. Admins resolve a code through `GET /clicutcl/v2/whatsapp-ref/<code>` or a Diagnostics lookup card. Cleanup, the Diagnostics purge, the privacy eraser and uninstall cover the table.

## Technical Context

**Language/Version**: browser ES2019 (no build step); PHP 8.1+
**Primary Dependencies**: none new
**Storage**: new table `{prefix}clicutcl_whatsapp_refs` (`DB_VERSION` 5); `sessionStorage['ct_wa_ref']` holds the confirmed code for the tab session (Marketing category, cleared on withdrawal)
**Testing**: `tools/qa/whatsapp-handoff.test.js` (Node VM, real `clicutcl-attribution.js` and `clicutcl-events.js`) wired into `npm run test:attribution` and `npm run test:consent`; PHPUnit `tests/unit/WhatsappRefTest.php`; `tools/qa/gtm-starter-kit.test.js` gains the trigger assertion; `npm run smoke`; `bash tools/qa/e2e/ci.sh` and `adapters-ci.sh` as no-regression gates
**Constraints**: setting stays off by default; no event or request without consent; never decorate with an unconfirmed code; no new dependency

## Repo gates

- AGENTS.md docs triggers (API/auth, storage/retention, integration behaviour): `docs/reference/REST-API.md`, `docs/reference/HOOKS-REFERENCE.md`, `docs/architecture/DATA-MODEL.md`, `docs/guides/SECURITY-PRIVACY.md`, `docs/guides/OPERATIONS-RUNBOOK.md` (DB version), `docs/guides/SETTINGS-AND-ADMIN.md`, `docs/reference/INTEGRATIONS.md`, `docs/MASTER-SPECIFICATION.md` (CT-013), `docs/architecture/CODE-MAP.md`, `readme.txt`, `README.en.md`, `changelog.txt`.
- Evidence boundary: source-present / runtime-unverified until SC-003 passes.

## Design

### Table vs touch-events reuse

New table; reasons in [research.md](research.md) R5 (unique code key, per-row expiry, visitor index; touch events has none of them). Follows how `clicutcl_touch_events` was added: `DB_VERSION` bump, dbDelta, readiness options, daily cleanup, privacy handler, uninstall.

```text
clicutcl_whatsapp_refs
  id bigint pk | blog_id bigint default 1 | code varchar(16) | visitor_id varchar(64)
  selected_click_id_key varchar(16) | selected_click_id varchar(128)
  snapshot text  -- {"first_touch":{}, "last_touch":{}, "click_ids":{}}
  created_at datetime | expires_at datetime
  UNIQUE (blog_id, code) | KEY visitor_id | KEY expires_at
```

### Server

| Change | File |
|---|---|
| `Whatsapp_Ref_Store`: `normalize_code`, `build_snapshot`, `build_row` (pure, takes `now` and retention), `create` (`created` / `collision` / `unavailable`), `find` (expired and unknown give `null`), `purge_expired`, `delete_by_visitor_ids`, `visitor_ids_from_event_rows`, `retention_days` | `includes/database/class-whatsapp-ref-store.php` (new) |
| Table, readiness options, `DB_VERSION` 5, version recorded only when the new table is ready too | `includes/database/class-installer.php` |
| Routes `POST /whatsapp-ref` (`create_whatsapp_ref`, `whatsapp_ref_create_permissions_check`) and `GET /whatsapp-ref/<code>` (`lookup_whatsapp_ref`, existing `diagnostics_permissions_check`) | `includes/api/class-tracking-controller.php` |
| `eventsToken` minted also when the append setting is on; localize `whatsappRefUrl` | `includes/class-clicutcl-core.php` |
| `contact_whatsapp_start` browser event name | `includes/tracking/class-eventv2.php` |
| `Whatsapp_Ref_Store::purge_expired()` after the touch-events block | `includes/utils/class-cleanup.php` |
| Eraser: select `event_data`, collect visitor IDs, delete reference rows, merge the result | `includes/privacy/class-privacy-handler.php` |
| Drop table and readiness options | `uninstall.php` |
| Truncate in the manual purge; AJAX `clicutcl_lookup_whatsapp_ref`; lookup card and JS | `trait-admin-diagnostics-ajax.php`, `class-admin.php`, `trait-admin-pages.php`, `assets/js/admin-diagnostics.js` |

### Browser

| Change | Where |
|---|---|
| WhatsApp branch before the booking branch: allowlist or `whatsapp:`, `link_url`, `phone`, `markOnce` after the consent check, `pushEvent( 'contact_whatsapp_start', ... )` | `assets/js/clicutcl-events.js` click handler |
| `contact_whatsapp_start` in `buildCanonicalEvent` map | same, `buildCanonicalEvent` |
| `WhatsAppRef` (generate, mint with one retry, session reuse by snapshot hash, forget on withdrawal) replaces `getEncoded()` use; `initWhatsAppListener` decorates only with a confirmed code, sets `data-clicutcl-wa-ref`, supports `whatsapp:` | `assets/js/clicutcl-attribution.js` |
| `Decorator.restore()` also removes `data-clicutcl-wa-ref`; `handleConsentDenied` clears the stored code; `window.ClickTrail.getWhatsAppRef()` returns the confirmed code (the events script reads it); `Decorator.setHref` also accepts `whatsapp:` so withdrawal restores such links | same |

### Contract

```text
click (consent, allowlisted link)  -> dataLayer {event:'contact_whatsapp_start', link_url, phone, contact_type:'whatsapp', ref_code?}
append on, page has WhatsApp link  -> POST /clicutcl/v2/whatsapp-ref {token, code, visitor_id, selected_click_id_key, data}
   201-like 200 {success, code, expires_at} | 409 collision -> client retries once with a new code | 4xx/5xx -> no decoration
click after confirmation           -> href text = <text>\n\nRef: CT-ABCD2345 ; data-clicutcl-wa-ref = CT-ABCD2345
admin GET /whatsapp-ref/CT-ABCD2345 -> {code, created_at, expires_at, visitor_id, selected_click_id, attribution:{first_touch,last_touch,click_ids}} | 404
```

## Risks

- A click before the code is confirmed sends an undecorated link (event still fires). Mitigated by session reuse and the warm-up triggers (pointer-over, focus, pointer-down, first touch on a page with a WhatsApp link); no wrong mapping is possible.
- Rows are created on public requests: bounded by the body cap, the rate limit, the nonce limit, the consent gate and session reuse.
- `DB_VERSION` 5 may collide with another branch that also bumps it; the integrator renumbers.
- The eraser only reaches visitors whose email appears in logged form events (research R9).
