# Implementation Plan: Conversion Semantics (purchase is paid, the thank-you page is a funnel step)

**Branch**: `feat/conversion-semantics` | **Date**: 2026-11-09 | **Spec**: [spec.md](spec.md) | **Issue**: none yet

## Summary

Five parts, in dependency order. U1: two settings (`purchase_trigger`, `thank_you_lead`) with the cookie-scope default pattern: legacy for existing installs, new behaviour seeded on fresh installs. U2: a new additive funnel-step event `ct_thank_you_view` on the Woo order-received page, the EDD receipt and matched lead-gen thank-you pages, consent-gated, deduped, dataLayer only. U3: WooCommerce `paid` mode: the browser `purchase` only when the order is paid at thank-you time, and a server `purchase` when the order becomes paid (`woocommerce_payment_complete`, status change), sharing `purchase_<order_id>`, the `_clicutcl_tracking_sent` marker (now recording which channels are done), `Claim_Lock` and the queue key, with an order-snapshot consent authority for requests that are not the buyer's, a creation-date retention guard and a `created_via` eligibility rule for renewals and non-checkout orders; EDD follows. U4: lead gen keeps the adapter lead as the conversion and adds `thank_you_lead` (`always` | `fallback` | `never`) with session evidence to avoid two leads. U5: admin copy, wizard, Site Health, docs, migration notes. Out of scope and listed in [research.md](research.md): pending-order browser recovery (Phase 2, R9), a ClickTrail status multiselect (R3), retiring the legacy thank-you `lead`, changing the default for existing installs.

## Technical Context

**Language/Version**: browser ES2019 (no build step); PHP 8.1+
**Primary Dependencies**: none new
**Storage**: no table, no cookie, no new order-meta key. `clicutcl_attribution_settings` gains `purchase_trigger` and `thank_you_lead`. Order meta `_clicutcl_tracking_sent` gains the values `server` and `browser` beside the legacy `yes` (same key, same privacy lifecycle). Session keys `ct_thankyou_view_<order id | path>`, `ct_lead_seen`, `ct_form_submit_seen` (Statistics, cleared on withdrawal).
**Testing**: PHPUnit `tests/unit/WooPaidPurchaseTest.php` (new), `EddIntegrationTest.php` and `WooCommercePurchaseDispatchLockTest.php` extended, a settings test like `CookieScopeSettingsTest.php`; Node VM `tools/qa/thank-you-semantics.test.js` (new, wired into `npm run test:consent`); harness `tools/qa/e2e/woo-purchase-ci.sh` (new) and an extended `edd-ci.sh`; `tools/qa/cmp-transition.test.js` for the storage inventory; `tools/qa/gtm-starter-kit.test.js` if the kit gains a trigger
**Constraints**: existing installs change nothing observable except one additive dataLayer event; no storage before consent; no `Set-Cookie` on page views; no new request per page view; no PII in the step event; no outbound request added by the browser paths

## Repo gates

- AGENTS.md docs triggers (new settings, filters, storage keys, behaviour): `docs/reference/INTEGRATIONS.md`, `docs/reference/HOOKS-REFERENCE.md`, `docs/guides/SETTINGS-AND-ADMIN.md`, `docs/guides/SECURITY-PRIVACY.md` (storage inventory; `tools/qa/cmp-transition.test.js` keeps the key list in sync), `docs/architecture/EVENT-PIPELINE.md`, `docs/architecture/DATA-MODEL.md`, `docs/tutorials/02-woocommerce-order-attribution.md`, `docs/tutorials/04-gtm-starter-kit.md`, `docs/MASTER-SPECIFICATION.md`, `config/feature-test-matrix.json`, `readme.txt`, `README.en.md`, `README.pt-BR.md`, `changelog.txt`.
- Evidence boundary: source-present / runtime-unverified until SC-007 passes; the harness proves harness behaviour only, and it has no card gateway (BACS, cheque and COD only), so a card path is simulated with `payment_complete()` from wp-cli and says so in the evidence file.
- Released behaviour: `order_paid`, `order_refunded`, `order_cancelled`, the thank-you `purchase` in `thankyou` mode, the legacy thank-you `lead` in `always` mode and every existing event name and payload stay as shipped in 1.13.0.

## Scope decisions (confirm before T002)

| Part | Decision | Why |
|---|---|---|
| Settings | `purchase_trigger` and `thank_you_lead` in the attribution option; legacy defaults in the schema, new values seeded on fresh installs | the cookie-scope pattern; a missing key reads the legacy value, so an upgrade changes nothing |
| Step event | `ct_thank_you_view`, dataLayer only, no server copy, no value | additive; cannot be mistaken for revenue; no new browser-route name |
| Paid definition | `wc_get_is_paid_statuses()` through `clicutcl_woocommerce_paid_statuses`; EDD `PAID_STATUSES` | Woo's own definition, already used by `order_paid` |
| Server `purchase` | from the same two hooks as `order_paid`, new function (not `maybe_dispatch_order_milestone()`), same `event_id`, lock and marker | the milestone helper writes a different marker, takes no lock and has no retention guard |
| Marker | one key, values `server` / `browser` / `yes` | no new key to add to the privacy lifecycle; legacy `yes` still means both |
| Consent | order snapshot decides outside customer requests; identity follows | an administrator's own cookie must not decide a buyer's consent |
| Eligibility | `created_via` allowlist `checkout`, `store-api` plus filter | renewals and non-checkout orders get `order_paid` only |
| Lead gen | adapter lead stays the conversion; `thank_you_lead` `always` / `fallback` / `never` | opt-in promotion with session evidence; legacy kept |
| Recovery | Phase 2 | the server `purchase` already covers buyers who never return |
| Names | proposals, **Hugo decision needed** | [research.md](research.md) open questions 1, 6 |

## Design

### Server

| Change | File |
|---|---|
| Schema entries `purchase_trigger` (default `thankyou`) and `thank_you_lead` (default `always`) with sanitisers; admin payload `capture.purchase_trigger`, `capture.thank_you_lead`; save mapping | `includes/admin/class-admin.php` near `:1183` (`cookie_scope` schema), `:602` (payload), `:1001` (save input) |
| Seed `purchase_trigger = paid`, `thank_you_lead = never` inside the existing fresh-install branch; never from `maybe_upgrade()` or the wizard | `includes/database/class-installer.php:95-111` (`seed_fresh_install_defaults()`) |
| Readers `Attribution_Settings::get_purchase_trigger()`, `get_thank_you_lead()` (missing or invalid reads the legacy value) | `includes/settings/class-attribution-settings.php` |
| `paid_statuses()` (filter `clicutcl_woocommerce_paid_statuses` over `wc_get_is_paid_statuses()`), `is_purchase_eligible( $order )` (`created_via` allowlist, filter `clicutcl_woocommerce_purchase_created_via`), `is_within_server_retention( $order )` (creation date only), marker helpers `marker_has_channel()` / `marker_with_channel()` | `includes/integrations/class-woocommerce.php` next to `is_within_retention()` (`:308`) |
| `maybe_dispatch_purchase( $order, $source_hook )`: guards (mode `paid`, paid status, eligible, retention, marker), `Claim_Lock::acquire( 'clicutcl_purchase_lock_<id>' )`, re-read marker, `build_purchase_payload()`, filter `clicutcl_woocommerce_purchase_payload` (as the thank-you path applies it), `dispatch_order_payload( ..., 'purchase', $source_hook )`, mark with `should_mark_milestone_sent( $result, false )`, release; called from `track_paid_milestone()` and `track_paid_milestone_from_status_change()` after the `order_paid` call so the milestone is untouched | same file, `:365-408` |
| `push_purchase_event()` restructure: after the consent checks push the step event; `thankyou` mode continues with the unchanged body; `paid` mode applies paid status, eligibility, retention, lock, then browser push and, when the server channel is open, `maybe_dispatch_purchase( $order, 'woocommerce_thankyou' )` inline; writes the unioned marker | same file, `:224-300` |
| Step event builder `build_thank_you_datalayer( $order )` (FR-004 payload) and the inline script with the `sessionStorage` guard written only after the PHP consent gate | same file; the script prints beside the purchase script |
| Consent authority: `meta.consent_authority = order_snapshot` set by `dispatch_order_payload()` when `! is_customer_request_context()`; read by `Dispatcher::consent_allows()`; `resolve_purchase_identity()` takes the marketing flag from the snapshot in the same case | `class-woocommerce.php:644`, `:1092`; `includes/server-side/class-dispatcher.php:420-440` |
| EDD: `edd_update_payment_status` handler `on_status_change( $order_id, $new_status, $old_status )`, the same helpers (own copies, EDD has no shared base), marker values, `Claim_Lock` (`PURCHASE_LOCK_PREFIX`), creation-date guard, snapshot consent; the receipt pushes the step event and applies the `paid` branch | `includes/integrations/class-edd.php:64-71`, `:113-186` |
| Localize `orderReceived` (bool) in the Woo events config so the script defers on order-received pages | `includes/class-clicutcl-core.php:568-` (`build_woocommerce_events_config()`), consumed at `assets/js/clicutcl-events.js:2106` |
| Localize `thankYouLead` (`always` / `fallback` / `never`) | `includes/class-clicutcl-core.php:333` (next to `thankYouMatchers`) |
| Site Health `test_conversion_semantics()` and its `add_tests()` entry, with a hint when a block theme's order-confirmation template lacks the "Additional information" block (W6) | `includes/admin/class-sitehealth.php:36-55` |
| Wizard step 3 summary part | `includes/admin/class-setup-wizard.php:544-560` |
| Readiness analyzer: allow `purchase` after a `payment_complete` / `paid_status_change` transition; new fixtures beside F06/F07 | `includes/Intelligence/class-woo-readiness-analyzer.php`, `tests/fixtures/woo-readiness/v1/` |

### Browser

| Change | Where |
|---|---|
| `pushEvent( name, params, options )` option `server: false` skips `sendServerEvent()` | `assets/js/clicutcl-events.js:88-119` |
| `trackThankYouLead()`: after the consent check, push `ct_thank_you_view` once (`ct_thankyou_view_<path>`) with `thank_you.context = lead_thank_you`; return when `wooCommerce.orderReceived`; then branch on `thankYouLead`: `always` runs today's body, `fallback` runs it only when `ct_lead_seen` and `ct_form_submit_seen` are absent, `never` stops | `:2213-2237` |
| `ct_lead_seen` written inside `pushEvent()` after the consent check when the name is `lead` and the provider is not `redirect_thank_you`; written by `pushDL()` for `lead_submit` | `:88-119`; `assets/js/clicutcl-attribution.js:2720-2730` |
| `ct_form_submit_seen` written in the capture-phase `submit` listener after the consent check | `assets/js/clicutcl-events.js:844-862` |
| `clearIdentity()` also removes the `ct_thankyou_view_` prefix, `ct_lead_seen`, `ct_form_submit_seen` | `:656-663` |

### Admin copy

| Where | Copy |
|---|---|
| Events tab, section "Conversion semantics", select **Purchase counts when** | `The order is paid (recommended)` / `The visitor reaches the thank-you page (legacy)`. Help: "A purchase is reported when the order reaches a paid status: processing or completed in WooCommerce, completed in Easy Digital Downloads. Bank transfers and orders waiting for payment are not counted until they are paid. Cash on delivery counts when WooCommerce marks it processing, which is before the cash is collected. The thank-you page is reported separately as `ct_thank_you_view`." Warning: "Changing this changes your reported revenue. Before you switch, move any Google Tag Manager trigger or ad conversion that fires on the thank-you page URL to the `purchase` event, or the same sale is counted twice." |
| select **Thank-you page counts as a lead** | `Never: only the form submit is a lead (recommended)` / `Only when no form submit was seen in this visit` / `Always (legacy)`. Help: "Pages added with `clicutcl_thank_you_matchers` are always reported as `ct_thank_you_view`. Choose Only when no form submit was seen if some of your forms have no ClickTrail integration; Always counts every visit to the page as a lead, including reloads in a new tab." |
| Wizard step 3 | "Purchases: counted when the order is paid" or "Purchases: counted at the thank-you page" |
| Site Health | label `ClickTrail: Conversion semantics`; `good`; "Purchase counts when the order is paid. Thank-you page counts as a lead: <value>." or the legacy sentence plus "See the migration notes in the ClickTrail documentation." |

## Contract

```text
thank-you page, Woo or EDD (consent granted)   -> dataLayer { event:'ct_thank_you_view', event_id:'thank_you_view_<order_id>', datalayer_version:'2.0.0',
                                                              thank_you:{ context:'order_confirmation', order_status:'on-hold', payment_state:'unpaid', transaction_id:'<order number>' } }
matched lead page (consent granted)            -> dataLayer { event:'ct_thank_you_view', event_id:<generated>, thank_you:{ context:'lead_thank_you' } }   (no server copy)

purchase_trigger = thankyou                    -> unchanged: thank-you push + server purchase, marker 'yes'
purchase_trigger = paid
  paid + eligible, checkout request            -> server purchase  event_id purchase_<order_id>, marker 'server'
  thank-you, order paid + eligible             -> dataLayer purchase (same event_id) if marker lacks browser; server purchase if marker lacks server; marker 'yes'
  on-hold / pending / failed / cancelled / ... -> step event only
  renewal / admin / rest-api order             -> order_paid only (+ step event on its thank-you page)
order_paid                                     -> unchanged in every mode (event_id order_paid_<order_id>, marker _clicutcl_woo_milestone_sent_order_paid)

marker _clicutcl_tracking_sent                 '' | 'server' | 'browser' | 'yes'   (legacy truthy test keeps meaning "handled")
consent, server purchase                       customer request: live cookie else snapshot (as today); other request: snapshot only
lead (thank_you_lead)                          always: legacy push | fallback: only if no ct_lead_seen / ct_form_submit_seen | never: none
```

## Migration notes for customer GTM containers

Draft for `docs/tutorials/04-gtm-starter-kit.md`, `INTEGRATIONS.md` and the changelog.

1. **What does not change**: the dataLayer event is still named `purchase` and its shape is unchanged. A tag on the `purchase` custom event (the starter kit's `CE - purchase`: GA4 purchase, Google Ads purchase, Meta Pixel `Purchase`) needs no edit. Browser and server copies keep the same `event_id` (`purchase_<order_id>`), so Meta Pixel and CAPI still dedupe.
2. **What moves**: any trigger that fires on a thank-you or order-received page URL counts page visits, not paid orders: bank-transfer and failed-order pages, reloads, and (in the starter kit) the `Google Ads - Thank You Conversion` tag on `Page View - Thank You Page`. For ecommerce, move it to the `purchase` event trigger. For lead gen, move it to the lead event of the form, or keep it knowing it counts page views.
3. **Order of operations**: (a) in GTM, point the conversion at the `purchase` event and pause the thank-you page-view tag in the same published version; this is safe in both modes because `purchase` fires in both; (b) then switch **Purchase counts when** to the order is paid. Never leave a page-view conversion and a `purchase` conversion live for the same action, or a card order that is paid at checkout is counted twice.
4. **What will look different after the switch**: on-hold, pending and failed orders stop counting; cash on delivery counts at `processing`; the server `purchase` can arrive when an administrator marks the order paid, days after the visit (check the ad platform's conversion window and its own dedupe against `event_id` before relying on it); a renewal or administrator-created order counts as `order_paid`, not `purchase`.
5. **New event**: `ct_thank_you_view` is pushed on thank-you pages in every install. A trigger that matches every custom event will see one more event name; it carries no value.
6. **Rolling back**: set the option back to the thank-you page. `purchase` events already sent are not retracted. An order whose marker is `server` (paid while in `paid` mode) is treated as handled in legacy mode, so its thank-you page pushes no second `purchase`.
7. **Lead gen**: with the legacy `always`, a site that has a form integration and a matched thank-you page counts one lead at submit and another at the page. Choose one source per form: keep the form lead and set the page to `Never`, or set `Only when no form submit was seen` if some forms have no integration.

## Changelog and readme wording (drafts)

- **Changelog** (`= Unreleased =`): "Conversion semantics (`specs/014-conversion-semantics/`): new attribution settings `purchase_trigger` (`thankyou` | `paid`) and `thank_you_lead` (`always` | `fallback` | `never`). Existing installs keep `thankyou` and `always`; fresh installs are seeded `paid` and `never`. With `paid`, a WooCommerce or Easy Digital Downloads `purchase` is reported when the order is paid, from the browser at the thank-you page and from the server when the order becomes paid (including buyers who never return), deduped on `purchase_<order_id>`; bank transfers and other unpaid orders are not purchases. Subscription renewals and administrator or API orders emit `order_paid` only. New additive dataLayer event `ct_thank_you_view` on Woo and EDD confirmation pages and on pages matched by `clicutcl_thank_you_matchers`. Behaviour change for anyone who switches to `paid`: reported revenue changes; see the migration notes."
- **readme.txt / README.en.md**: "Purchases are reported when the order is paid (new installs), not when the thank-you page is opened. The thank-you page is a funnel step (`ct_thank_you_view`). Existing sites keep the old behaviour until you choose otherwise."

## Test plan

**PHPUnit** (`tests/unit/WooPaidPurchaseTest.php`; shared order and dispatcher doubles move from `WooCommercePurchaseDispatchLockTest.php` to `tests/helpers/woo-doubles.php`, as EDD's live in `tests/helpers/edd-doubles.php`):

| Case | Asserts |
|---|---|
| paid at thank-you | `paid`, order `processing`, eligible: one browser purchase, one server dispatch, marker `yes`, step event pushed |
| paid at checkout, then thank-you | hook dispatch marker `server`; thank-you pushes browser purchase only; marker `yes`; one dispatch in total |
| on-hold then paid by status change | thank-you: step only, no dispatch, marker unset; status change: one dispatch `purchase_<id>`; reload: browser purchase once; exactly one `purchase` per channel |
| administrator cookie | status change with a denied and with a granted `ct_consent_state` present in the request and the opposite snapshot on the order: the snapshot decides; identity follows the snapshot |
| thank-you refresh | second load pushes nothing; step event guard in the page script |
| concurrent thank-you and status change | object-cache lock and option-lock fallback: one dispatch; loser writes no marker; stale takeover as the existing lock tests |
| queued then second path | `Queue::enqueue` unique key; no second row |
| `thankyou` mode | key absent and key `thankyou`: output byte-identical, no hook dispatch; failed-order thank-you still pushes (documented) |
| `paid` and failed, pending, cancelled, refunded, draft | no push, no dispatch, step event carries the status |
| COD | `processing` counts; `on-hold` COD (downloadable) does not; filter narrows to `completed` |
| renewal and non-checkout | `created_via` `subscription`, `admin`, `rest-api`, `''`: `order_paid` only; `checkout`, `store-api`, filter-added: eligible |
| refunded after paid | no retraction, marker kept, reopened order does not re-dispatch |
| retention | order created before the window: server refuses, thank-you keeps paid-date-first; unreadable date refuses; marker survives erase and retention (`WooOrderPrivacyTest`) |
| consent | withheld at checkout: nothing sent, marker unset; no snapshot with consent required: `consent_denied`; consent not required: sends |
| milestones | `order_paid` payload, marker and event id identical in both modes |
| settings | missing key reads legacy; seed writes `paid` / `never` only when fresh, never on upgrade, never from the wizard; invalid values fall back |
| EDD | pending then complete via the hook: one dispatch; `refunded`, `failed`, `pending`, `edd_subscription`, type `refund`: none; receipt after dispatch pushes browser once; `thankyou` unchanged; renewal rule |

**Node VM** (`tools/qa/thank-you-semantics.test.js`, real `clicutcl-events.js`, `FakeStorage`, stub bridge): step event once per path and on reload; consent denied writes nothing; `never`, `always`, `fallback` with each marker; `lead_submit` and `lead` write `ct_lead_seen`; submit writes `ct_form_submit_seen`; withdrawal clears all keys; `orderReceived` defers; `pushEvent( ..., { server: false } )` sends no beacon; `always` output equals the pre-change `lead`.

**Harness** (`tools/qa/e2e/woo-purchase-ci.sh`, `woo_purchase.py`, `verify_woo_purchase.py`, modelled on `woo-privacy-ci.sh` and `edd-ci.sh`; real WooCommerce 11.2.2, BACS, cheque and COD enabled, server delivery to the capture endpoint so `ct-capture.jsonl` records each dispatch; run under `flock /tmp/ct-e2e.lock`):

| Scenario | What it does | Observes |
|---|---|---|
| `hook-probe` | request a real order-received page of a fresh order on the classic shortcode checkout and on a block checkout with the default blockified template, then on a block template with the "Additional information" block removed | whether our `woocommerce_thankyou` callback output (step event, purchase) renders in each (W6) |
| `bacs-then-paid` | Chromium checkout with BACS, thank-you page, then `$order->update_status( 'processing' )` through wp-cli and again through an authenticated wp-admin edit with an opposite consent cookie | step event only at first; one captured `purchase_<id>` after payment; consent per snapshot; reload pushes browser `purchase` once |
| `card-simulated` | order created with `created_via = checkout`, `payment_complete()` from wp-cli (no card gateway exists in the harness), then the thank-you page | one server `purchase`, one browser `purchase` with the same `event_id`, marker `yes` |
| `cod` | Chromium checkout with COD | order `processing`, purchase counted, document the caveat |
| `failed` | order `failed`, thank-you page, both modes | `thankyou` pushes `purchase`, `paid` does not |
| `refresh-tabs` | two parallel requests of the thank-you URL for a paid order | one dispatch, one push |
| `legacy-unchanged` | `purchase_trigger = thankyou` | thank-you dispatch as in 1.13.0 |
| `retention` | order created 200 days ago, paid now | server refuses, record result |
| `edd-pending-complete` (in `edd-ci.sh`) | pending `sale` order, `edd_update_order_status( $id, 'complete' )` | one captured `purchase_edd_<id>`; confirms the hook (W9); receipt then pushes browser once |

## Risks

- **Revenue changes when an owner switches.** That is the intent; the copy, the changelog and the migration notes say so, and the default for existing installs does not move.
- **Two channels, one marker.** A bug in the union logic would either suppress the browser `purchase` (marker truthy after a server dispatch) or let a second server dispatch through. The four dedupe layers (marker, lock, `Dedup_Store`, queue key) are independent on purpose, and PHPUnit covers each pair.
- **Consent authority change touches the dispatcher.** `Dispatcher::consent_allows()` is shared by every event. The new branch is keyed on an explicit payload flag only set by the Woo and EDD purchase paths, so no other event changes; whether the milestones adopt it is open question 7.
- **A queued `purchase` on a consent-required site** waits on `clicutcl_queue_consent_snapshot` (default null, `class-queue.php:282-306`), exactly as every queued event does today. This spec does not fix it; sites that need queue retries under consent must supply the filter. Flagged in the changelog.
- **Eligibility allowlist can under-count** a gateway that creates orders with its own `created_via`; the filter and `order_paid` cover it, and the harness evidence should record the values seen.
- **Block order-received template** runs `woocommerce_thankyou` through the "Additional information" block (W6, source-present); a customised block template that removes that block silently drops the legacy `purchase` push and the step event while the server `purchase` still works. Mitigation: documented caveat, a Site Health hint (T006) and the `hook-probe` harness scenario on both checkout types; runtime-unverified until it passes.
- **EDD hook** is unverified for completion (W9); T013 proves it in the harness before the handler is relied on.
- **Session evidence for leads is coarse.** Any form submit in the tab suppresses the fallback lead (research R10).
- **Order of `woocommerce_payment_complete` and the status hook**: both run in one request, the status hook first (W2); the lock and marker make the order irrelevant, and a test pins it.
- **Source versions**: WooCommerce, Subscriptions and EDD were read at `trunk` / `main`, not the pinned harness versions; the harness run is what promotes any row.
