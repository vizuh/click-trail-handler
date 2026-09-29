# Implementation Plan: Calendly Booking Attribution Handoff

**Branch**: `feat/calendly-booking-handoff` | **Date**: 2026-09-29 | **Spec**: [spec.md](spec.md) | **Issue**: [#102](https://github.com/vizuh/click-trail-handler/issues/102)

> **Superseded design (2026-09-29):** as built, #108 uses the compact `salesforce_uuid` stamp
> (`ct1;<click_id>=<value>;c=<0|1>`) instead of the handoff table, mint route and retention below. The signature
> and webhook-mapping parts shipped in #106 as planned. See research.md for the decision.

## Summary

Mint a short, opaque handoff reference once consent is granted. Put it in Calendly's `salesforce_uuid` (links and widgets) and keep a server-side record of the attribution and consent snapshots. The Calendly webhook resolves the reference back to full attribution. Calendly's native webhook signature is added so no relay is needed.

## Technical Context

**Language/Version**: PHP 8.1+ (WordPress 6.5+), browser ES2019 (no build step)
**Primary Dependencies**: none new
**Storage**: new table `{prefix}clicutcl_handoffs` (DB_VERSION 5)
**Testing**: PHPUnit (`composer test`, 8.1 and 8.5); `npm run smoke`; a new Playwright probe (pattern from the 1.10.2 release probe) against disposable WordPress
**Constraints**: no raw click IDs in third-party URLs; no storage or minting without granted consent; multisite-scoped

## Constitution / repo gates

- AGENTS.md docs update triggers: REST route, storage, retention and integration change, so `REST-API.md`, `HOOKS-REFERENCE.md`, `DATA-MODEL.md`, `SECURITY-PRIVACY.md`, `OPERATIONS-RUNBOOK.md` and `INTEGRATIONS.md` all get updated.
- Commit discipline: `/code-review` before commit (security and privacy path).
- The evidence boundary is unchanged: the Calendly row stays runtime-unverified until SC-004 passes.

## Design

### Server

| Change | File |
|---|---|
| Table plus `DB_VERSION` 5 upgrade | `includes/database/class-installer.php` |
| `Handoff_Store` (`mint()`, `resolve()`, `forget_by_ref()`, `erase_by_hashed_email()`, `purge_expired()`) | new `includes/database/class-handoff-store.php` |
| `POST clicutcl/v2/attribution-handoff` (reuses the sign-route gates, re-checks consent) | `includes/api/class-tracking-controller.php` plus a trait |
| Calendly signature branch | `includes/tracking/class-webhook-auth.php` |
| Read `payload.tracking`, resolve the reference, attach attribution, degrade to UTMs with a `handoff_status` diagnostic | `includes/tracking/webhooks/class-calendlywebhookadapter.php` |
| Expiry batch | `includes/utils/class-cleanup.php` |
| Erasure and export | `includes/privacy/class-privacy-handler.php` |
| Drop the table | `uninstall.php` (respects `clicutcl_preserve_data_on_uninstall`) |

### Browser

| Change | File |
|---|---|
| `Handoff.prepare()` pre-mints after consent is granted when a Calendly link or widget is present | `assets/js/clicutcl-attribution.js` |
| Calendly shaping in `decorateUrl`: `utm_*` plus `salesforce_uuid=<ref>`, no click IDs, host values kept | same file |
| Widget decoration: `.calendly-inline-widget[data-url]`, plus wrapping `Calendly.initInlineWidget` / `initPopupWidget` when present | same file |
| On denial or withdrawal: drop the in-memory ref and call the forget path | same file |

### Contract: webhook → canonical event

```text
payload.tracking.salesforce_uuid  -> Handoff_Store::resolve(ref, blog_id)
    found & not expired           -> attribution = record.attribution; meta.handoff_status = "resolved"
    missing/expired/unknown        -> attribution = { utm_* from tracking }; meta.handoff_status = "<reason>"
payload.email                     -> hashed_email stored on the record (enables erasure)
event_id                          -> unchanged: 'cal_' . md5(payload.uri)
```

## Risks

- The `salesforce_uuid` length limit is unknown. T001 measures it before the format is locked.
- Sites already using `salesforce_uuid` for their own Salesforce sync: the host value wins (FR spec US2-3), so their booking goes unlinked. This is documented as an explicit conflict.
- The widget wrap depends on the Calendly script load order. Fallback: decorate `data-url` before Calendly's script initialises the widget.
