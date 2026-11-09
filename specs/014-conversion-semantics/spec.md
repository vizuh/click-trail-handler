# Feature Specification: Conversion Semantics (purchase is paid, the thank-you page is a funnel step)

**Feature Branch**: `feat/conversion-semantics`

**Created**: 2026-11-09

**Status**: Draft, not started. Every claim about current behaviour is source-present / runtime-unverified: read from `main` @ `b711fcf` (1.13.0 plus the spec-decisions PR #215). Third-party behaviour was read from vendor source and documentation on 2026-11-09 (WooCommerce `trunk`, WooCommerce Subscriptions core `trunk`, EDD `main`, GTM4WP documentation; see [research.md](research.md)), not from the versions the E2E harness pins (WooCommerce 11.2.2, EDD 3.7.3). Nothing here was run in a browser, against a real gateway or against WooCommerce Subscriptions. Proof will come from PHPUnit, Node VM tests and new scenarios in the real-WordPress harness (`tools/qa/e2e/`); live acceptance (SC-007) stays open.

**Input**: owner direction (Hugo, 2026-11-09). ClickTrail serves both ecommerce and lead-gen sites. Reaching a thank-you page does not mean the visitor bought or even submitted a form. In ecommerce a purchase is money paid, that is, a paid order status; the thank-you page is an additional funnel step in both ecommerce and lead gen, not itself the conversion. Today the WooCommerce thank-you hook pushes the browser `purchase` and dispatches the server `purchase` with no payment-state check (`includes/integrations/class-woocommerce.php:224-300`), a paid-order milestone `order_paid` exists beside it (`:365-408`), and the legacy thank-you tracker turns any page visit that matches a site-supplied path into a `lead` (`assets/js/clicutcl-events.js:2213-2237`). Easy Digital Downloads already gates the receipt push on a paid status (`includes/integrations/class-edd.php:118`) but has no server-side path for a customer who never returns.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A card order that is paid at checkout still produces one `purchase` (Priority: P1)

A buyer pays by card; the gateway calls `payment_complete()` during the checkout request, the order is `processing` or `completed`, and the buyer lands on the order-received page. With `purchase_trigger = paid` exactly one browser `purchase` and exactly one server `purchase` (`event_id = purchase_<order_id>`) exist, plus one `ct_thank_you_view`.

**Why this priority**: it is the common path and must not lose revenue; it also exercises the case where the server copy is sent before the thank-you page loads and the browser copy must still be pushed.

**Independent Test**: PHPUnit extends `tests/unit/WooCommercePurchaseDispatchLockTest.php` (order doubles, `Claim_Lock`, captured dispatch calls); harness scenario `paid-card` creates a real order with `payment_complete()` and requests the order-received URL.

**Acceptance Scenarios**:

1. **Given** `purchase_trigger = paid`, consent granted, an order created through checkout (`created_via` `checkout` or `store-api`), **When** `payment_complete()` runs (hooks `woocommerce_payment_complete` and `woocommerce_order_status_changed`), **Then** one server `purchase` is dispatched with `event_id = purchase_<order_id>`, the marker `_clicutcl_tracking_sent` becomes `server`, and the second hook returns on the marker.
2. **Given** scenario 1 happened, **When** the buyer's order-received page loads, **Then** the page pushes `ct_thank_you_view` and one browser `purchase` carrying the same `event_id`, no second server dispatch happens, and the marker becomes `yes`.
3. **Given** scenario 2, **When** the page is reloaded or opened in a second tab, **Then** no second `purchase` is pushed and no second dispatch happens (marker `yes`); `ct_thank_you_view` is not pushed again in the same tab session.
4. **Given** the order is already `processing` when the thank-you page loads (the paid hooks never fired, for example a plugin set the status silently), **Then** the page pushes the browser `purchase` and also performs the server dispatch through the same claim, and the marker becomes `yes`.

---

### User Story 2 - A bank transfer is not a purchase until it is paid (Priority: P1)

A buyer chooses direct bank transfer. WooCommerce puts the order `on-hold` (the Cheque and BACS gateways do this because the payment cannot be verified, [WooCommerce Payment Gateway API](https://developer.woocommerce.com/docs/features/payments/payment-gateway-api)). The thank-you page pushes only `ct_thank_you_view`. Days later an administrator marks the order `processing`; one server `purchase` is dispatched even though the buyer never returns.

**Independent Test**: harness scenario `bacs-then-paid` (real WooCommerce, BACS enabled, order created `on-hold`, thank-you requested, status moved to `processing` through `$order->update_status()`), plus PHPUnit.

**Acceptance Scenarios**:

1. **Given** `paid`, an `on-hold` BACS order and the buyer's thank-you load, **Then** the page pushes `ct_thank_you_view` with `order_status = on-hold` and `payment_state = unpaid`, no `purchase` is pushed, no `purchase` is dispatched, and the marker stays unset.
2. **Given** scenario 1, **When** an administrator changes the status to `processing` or `completed` (request with the administrator's cookies, or WP-CLI, or cron), **Then** one server `purchase` is dispatched with the same `event_id = purchase_<order_id>`, the consent decision uses the order's stored checkout snapshot and not the administrator's cookie (FR-008), and the marker becomes `server`.
3. **Given** scenario 2 and the buyer then reloads the thank-you URL, **Then** the page pushes the browser `purchase` once (marker becomes `yes`), without a second server dispatch.
4. **Given** the order moves `processing` to `completed` afterwards, **Then** nothing more is dispatched for `purchase`; the `order_paid` milestone is also unchanged (once, its own marker).
5. **Given** an order in `pending`, `failed`, `cancelled`, `refunded` or `draft`, or any status outside the paid list, **Then** no `purchase` is pushed or dispatched by any path.

---

### User Story 3 - Two paths, one purchase (Priority: P1)

The thank-you page and the status-change hook can run in the same second (webhook plus returning buyer, double tab, prefetch). The claim, the marker, the dedupe store and the queue key guarantee one server `purchase`.

**Independent Test**: PHPUnit with the option-lock fallback and the object-cache lock (the pattern of `WooCommercePurchaseDispatchLockTest`), plus a `Queue` test for the unique key.

**Acceptance Scenarios**:

1. **Given** two concurrent callers (thank-you and `woocommerce_order_status_changed`) for the same paid order, **Then** exactly one holds `Claim_Lock::acquire( 'clicutcl_purchase_lock_<order_id>' )`, one dispatch occurs, and the loser returns without writing the marker.
2. **Given** the dispatch was queued after a transport error, **When** the other path runs, **Then** `Queue::enqueue` finds the `(purchase, purchase_<order_id>)` row (`includes/database/class-installer.php:178`) and creates no second row.
3. **Given** the dedupe transient has expired (`Dedup_Store` TTL defaults to 7 days), **Then** the marker still stops a re-dispatch.

---

### User Story 4 - Existing installs see no change (Priority: P1)

A site upgrading from 1.13.0 keeps `purchase_trigger = thankyou`: the thank-you page pushes `purchase` and dispatches the server copy exactly as today, with no new server hooks registered. The only difference is the additive `ct_thank_you_view` push.

**Independent Test**: PHPUnit with the key absent from `clicutcl_attribution_settings`; the existing Woo, EDD and DataLayer-contract tests keep passing unchanged.

**Acceptance Scenarios**:

1. **Given** `clicutcl_attribution_settings` has no `purchase_trigger`, **Then** the effective value is `thankyou`, `woocommerce_payment_complete` and `woocommerce_order_status_changed` do not dispatch a `purchase`, and `push_purchase_event()` output (dataLayer object, `event_id`, marker `yes`, trace snapshot) is byte-identical to 1.13.0.
2. **Given** a fresh install (no `clicutcl_attribution_settings`, no `clicutcl_db_version`, ...), **When** the plugin activates, **Then** `seed_fresh_install_defaults()` writes `purchase_trigger = paid` and `thank_you_lead = never`; an install with any sign of earlier use gets neither (`includes/database/class-installer.php:95-111`).
3. **Given** the setup wizard runs on an existing install, **Then** it never writes either key (`includes/admin/class-setup-wizard.php:130-157` only touches `enable_attribution`, `enable_js_injection`, `inject_mutation_observer` and `ga4_measurement_id`).
4. **Given** an existing install with a Google Ads page-view conversion tag on its thank-you page, **Then** nothing changes until the owner chooses `paid`; the admin copy and the migration notes tell them what moves (FR-016, FR-020).

---

### User Story 5 - Renewals and other non-checkout orders are not purchases (Priority: P2)

An automatic WooCommerce Subscriptions renewal, an administrator-created order, a REST or POS import: each reaches a paid status without a shopper checkout. They emit `order_paid` only. A manual "pay renewal" thank-you page shows the step event and no `purchase`.

**Independent Test**: PHPUnit with `created_via` set to `subscription`, `admin`, `rest-api`, `checkout`, `store-api`, `''`. WooCommerce Subscriptions is not in the harness: the renewal case stays source-present / runtime-unverified (SC-007).

**Acceptance Scenarios**:

1. **Given** `paid` and an order with `created_via = subscription` (the value `wcs_create_order_from_subscription()` sets for renewal and resubscribe orders), **When** it becomes paid, **Then** `order_paid` is dispatched (unchanged) and `purchase` is not.
2. **Given** `created_via` is `admin`, `rest-api` or empty, **Then** the same: `order_paid` yes, `purchase` no, thank-you step event yes.
3. **Given** `created_via` is `checkout` or `store-api`, **Then** the order is eligible.
4. **Given** the filter `clicutcl_woocommerce_purchase_created_via` adds a value, **Then** orders with that value are eligible (escape hatch for gateways that create their own orders).
5. **Given** `thankyou` mode, **Then** the rule is not applied (today's behaviour).

---

### User Story 6 - Lead gen: the form submit is the lead, the thank-you page is a step (Priority: P1)

A lead-gen site with a thank-you page registered through `clicutcl_thank_you_matchers`. The adapter or browser listener's lead (`lead`, `lead_submit`, server `form_submission`) is the conversion, pushed at submit time with the adapter's own dedupe. The thank-you page view pushes `ct_thank_you_view` (`context = lead_thank_you`). Whether that page also pushes a `lead` is the setting `thank_you_lead`.

**Independent Test**: Node VM test `tools/qa/thank-you-semantics.test.js` in the style of `tools/qa/form-lead-dedupe.test.js` (real `clicutcl-events.js`, stub consent bridge, `FakeStorage`), run through `npm run test:consent`.

**Acceptance Scenarios**:

1. **Given** a matched path, consent granted, `thank_you_lead = never`, **Then** one `ct_thank_you_view` is pushed with `thank_you.context = lead_thank_you`, no `lead` is pushed, no server event is sent for the step event, and `sessionStorage['ct_thankyou_view_<path>']` stops a reload from pushing again.
2. **Given** `thank_you_lead = always` (existing installs), **Then** the page pushes the step event and then the legacy `lead` (`lead_context.provider = redirect_thank_you`, `submit_status = success`), once per path per tab session, byte-identical to today for the `lead`.
3. **Given** `thank_you_lead = fallback` and an adapter lead or a form submit seen earlier in this tab session (`ct_lead_seen` or `ct_form_submit_seen`), **Then** the page pushes only the step event and the `lead` count for that visit is one (the adapter's).
4. **Given** `fallback` and neither marker (a form with no adapter, a payment-less redirect), **Then** the page pushes the step event and one `lead`, the same one-per-path marker (`ct_thankyou_lead_<path>`) as today.
5. **Given** consent denied or unresolved, **Then** nothing is pushed, no marker is written and `ct_lead_seen` / `ct_form_submit_seen` are not written.
6. **Given** consent withdrawn, **Then** all `ct_thankyou_view_*`, `ct_thankyou_lead_*`, `ct_lead_seen` and `ct_form_submit_seen` keys are removed with the other event markers (`assets/js/clicutcl-events.js:656-663`).
7. **Given** a path listed in the matchers that is also the WooCommerce order-received page, **Then** the JavaScript defers to the PHP push (one step event, `context = order_confirmation`).

---

### User Story 7 - Easy Digital Downloads follows the same model (Priority: P2)

EDD's receipt push is already paid-gated, so `thankyou` keeps today's behaviour. With `paid`, the server `purchase` is also dispatched when the order becomes complete, so a buyer who pays offsite and never reaches the receipt is still counted, and the receipt of a still-pending order pushes `ct_thank_you_view`.

**Independent Test**: PHPUnit extends `tests/unit/EddIntegrationTest.php`; harness `edd-ci.sh` gains a pending-then-complete scenario (`edd_update_order_status()`).

**Acceptance Scenarios**:

1. **Given** `paid`, a `sale` order that moves to `complete` outside the receipt request, **Then** one server `purchase` (`event_id = purchase_edd_<order_id>`) is dispatched with the stored consent snapshot and the marker becomes `server`; the later receipt pushes the browser `purchase` once (marker `yes`) without a second dispatch.
2. **Given** an order of type `refund`, status `refunded`, `failed`, `pending` or `edd_subscription` (Recurring Payments renewal), **Then** no `purchase` is dispatched.
3. **Given** `thankyou`, **Then** the receipt behaves as in 1.13.0 and no hook on the status change dispatches.

---

### User Story 8 - The owner sees the mode and knows what moves (Priority: P2)

The admin card, the setup wizard summary, Site Health and the Diagnostics order trace state which mode is active in plain words; the migration notes list the GTM triggers to move and how to avoid double counting during the switch.

**Acceptance Scenarios**:

1. **Given** the admin Events tab, **Then** the two settings have the copy in FR-016, including the double-counting warning.
2. **Given** wizard step 3 on a site with WooCommerce or EDD, **Then** the summary line reads "Purchases: counted when the order is paid" or "Purchases: counted at the thank-you page" (FR-017).
3. **Given** Site Health, **Then** a `good` test reports both settings and the sentence about the migration doc.
4. **Given** the Diagnostics order trace, **Then** a `purchase` trace shows `source_hook` as `woocommerce_thankyou`, `woocommerce_payment_complete` or `woocommerce_order_status_changed` (the existing trace field, `includes/integrations/class-woocommerce.php:734-757`).

---

### Edge Cases

- **Failed order thank-you today**: WooCommerce prints the "failed" notice and still fires `woocommerce_thankyou` (template `checkout/thankyou.php`, `do_action` after the failed/else block), and `push_purchase_event()` has no status check, so `thankyou` mode pushes a `purchase` for a failed order. `paid` fixes it; `thankyou` keeps it (no silent change), and the step event carries `order_status = failed`.
- **Cash on delivery**: the COD gateway sets `processing` (or `on-hold` for downloadable items, filterable) at checkout ([research.md](research.md) R5); `processing` is in `wc_get_is_paid_statuses()`, so COD counts as paid at checkout in `paid` mode, before any cash is collected. The setting copy says so. A site that wants cash-collected-only narrows the list with `clicutcl_woocommerce_paid_statuses` or `woocommerce_order_is_paid_statuses`.
- **Zero-total orders**: WooCommerce calls `payment_complete()` for orders that need no payment; they reach `processing`/`completed` and count as paid, as today (the zero-value fixture F10 stays valid).
- **Block order-confirmation template**: the Woo block `OrderConfirmation` files read on `trunk` contained no `do_action( 'woocommerce_thankyou' )`; whether the browser push and the step event run on a block-theme order-received page is runtime-unverified. The server `purchase` does not depend on it (a benefit of `paid`).
- **Paid then refunded, reopened**: a refund does not retract a sent `purchase` (unchanged); a refunded order returning to `processing` finds the marker and sends nothing.
- **Switching modes**: `thankyou` to `paid` can dispatch a server `purchase` for an old pending order that is paid later and never had a thank-you push (correct: it was never counted); `paid` to `thankyou` treats a `server` marker as handled (no browser push for it).
- **Orders older than retention**: the retention purge removes consent snapshot and attribution by creation date; the server path refuses orders created before the retention window, the thank-you path keeps its paid-date-first rule (FR-009).
- **A page-view conversion tag in the customer's GTM** is not ClickTrail's; it keeps counting page visits until the customer moves it (migration notes).
- **Administrator with their own consent cookie**: when an administrator marks an order paid with a ClickTrail consent cookie of their own, that cookie must not decide the buyer's consent (FR-008).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: `clicutcl_attribution_settings.purchase_trigger` (`thankyou` | `paid`) MUST be added to `Admin::get_attribution_settings_schema()` with default `thankyou` and a sanitiser that falls back to `thankyou`, exposed through the `capture` group of the admin payload beside `cookie_scope` (`includes/admin/class-admin.php:595-610`, `:995-1005`, `:1165-1190`). A missing key reads as `thankyou`.
- **FR-002**: `thank_you_lead` (`always` | `fallback` | `never`) MUST be added the same way with default `always` (today's behaviour wherever matchers exist). The names and values are proposals (**Hugo decision needed**, [research.md](research.md) R10).
- **FR-003**: `Installer::seed_fresh_install_defaults()` MUST add `purchase_trigger = paid` and `thank_you_lead = never` to the option it already creates for a genuinely fresh install (`includes/database/class-installer.php:95-111`), under the same earlier-use guard, and MUST NOT run from `maybe_upgrade()` or the wizard. Upgrades and reactivations keep the schema defaults.
- **FR-004**: A new generic funnel-step event MUST be pushed to `window.dataLayer` on (a) the WooCommerce order-received page (`context = order_confirmation`), (b) the EDD receipt (`order_confirmation`), (c) a page matched by `clicutcl_thank_you_matchers` (`lead_thank_you`), in every install and mode. Proposed name `ct_thank_you_view` (**Hugo decision needed**: name; the existing `ct_` prefix is used for `ct_page_view` and for renamed Google-reserved fields, `assets/js/clicutcl-events.js:28-29`, `docs/architecture/EVENT-PIPELINE.md:71-74`). Payload: `event`, `event_id`, `datalayer_version`, and `thank_you = { context, order_status, payment_state, transaction_id }`, the last three only for `order_confirmation`. It MUST carry no `ecommerce` object, no value, currency, items, customer field, email, phone, address, URL, path or free text. `order_status` is the order status slug; `payment_state` is `paid` or `unpaid` by the paid-status rule; `transaction_id` is the same order number the `purchase` carries.
- **FR-005**: The step event MUST be consent-gated like `purchase` (PHP: `Consent::marketing_allowed()` and the live-consent check already at `class-woocommerce.php:231-244`; JS: `ClickTrailConsent.isGranted()` before any storage write), MUST be pushed at most once per order per browser tab session (`sessionStorage['ct_thankyou_view_<order_id>']`) or once per matched path per tab session (`ct_thankyou_view_<path>`), and MUST NOT be sent to the server route or any adapter in v1 (it is not added to `EventV2::BROWSER_EVENT_NAMES`; the lead-page push uses a new `pushEvent` option that skips `sendServerEvent`). It is additive: no existing event name or shape changes.
- **FR-006**: With `purchase_trigger = thankyou`, `push_purchase_event()` MUST behave exactly as in 1.13.0 (browser `purchase`, server `purchase`, marker `yes`, trace), and the paid hooks MUST NOT dispatch a `purchase`. EDD likewise.
- **FR-007**: With `purchase_trigger = paid` on WooCommerce:
  (a) A server `purchase` MUST be dispatched when an order that passes the eligibility rule (FR-010) becomes paid: from `woocommerce_payment_complete` and from `woocommerce_order_status_changed` into a status in `paid_statuses()`, where `paid_statuses()` is `wc_get_is_paid_statuses()` passed through the new filter `clicutcl_woocommerce_paid_statuses`. It uses `event_id = purchase_<order_id>`, the existing `build_purchase_payload()`, and `dispatch_order_payload( $order, $payload, 'purchase', <source hook> )`.
  (b) The thank-you page MUST push the browser `purchase` only when the order is in `paid_statuses()` at that moment and is eligible; otherwise it pushes only the step event. When it pushes, and the server channel is not yet done, it MUST also dispatch the server copy through the same function (fallback for silently paid orders).
  (c) Server and browser channels share the marker `_clicutcl_tracking_sent`, whose value records which channels are done: `server`, `browser`, `yes` (both; the legacy value). The server path returns on `server` or `yes`, the browser path on `browser` or `yes`; a write unions the two. The marker stays managed exactly as today (kept through erase, retention and the Diagnostics purge: `includes/privacy/class-woo-order-privacy.php:24-37`).
  (d) Both channels MUST run under `Claim_Lock::acquire( 'clicutcl_purchase_lock_<order_id>' )` (`PURCHASE_LOCK_TTL` 30 s) and re-read the marker after the claim.
  (e) The server channel marks with milestone semantics, `should_mark_milestone_sent( $result, false )` (a skipped `disabled`, `consent_denied` or `missing_endpoint` result does not mark; `duplicate_event`, sent and queued do).
- **FR-008**: Consent for a server `purchase` MUST follow the precedence `Dispatcher::consent_allows()` already uses for events that carry a snapshot (live cookie authoritative when present, else the order's `_clicutcl_consent` snapshot, `includes/server-side/class-dispatcher.php:420-440`), with one addition: when the request is not a customer request (`is_customer_request_context()` is false: admin, REST, cron, WP-CLI, `class-woocommerce.php:1069-1084`), the cookie MUST be ignored and only the order snapshot decides, and the identity block MUST be resolved from the snapshot's marketing flag instead of the live one (`resolve_purchase_identity()` passes live `Consent::marketing_allowed()` today, `:1131`). A missing snapshot with consent required fails closed (`consent_denied`); consent not required sends. Implementation: an explicit `meta.consent_authority = order_snapshot` carried on the payload and read by the dispatcher; whether `order_paid` and the other milestones adopt it is **Hugo decision needed** (they share the code path; today an administrator's own consent cookie decides them).
- **FR-009**: The server channel MUST refuse an order whose creation date is older than `Woo_Order_Privacy::retention_days()` (the retention purge removes the consent snapshot and attribution by `date_created`, `includes/privacy/class-woo-order-privacy.php:369-380`); an unreadable creation date also refuses. The thank-you channel keeps `is_within_retention()` (paid date first, then created, `:308-316`).
- **FR-010**: Eligibility: in `paid` mode an order is eligible for `purchase` only when `$order->get_created_via()` is `checkout` or `store-api` (the values WooCommerce sets for classic and Store API checkout) or a value added through `clicutcl_woocommerce_purchase_created_via`. `subscription` (renewal and resubscribe orders), `admin`, `rest-api` and empty are not eligible; they still emit `order_paid`, the step event on their thank-you page, and nothing else. Source-present / runtime-unverified for Subscriptions (not in the harness).
- **FR-011**: Never a `purchase` in `paid` mode: any status outside `paid_statuses()`, including `pending`, `on-hold`, `failed`, `cancelled`, `refunded`, `draft`, `trash` and custom statuses. COD follows WooCommerce's definition (documented caveat). Refund and cancel milestones are unchanged and do not retract a sent `purchase`.
- **FR-012**: `order_paid` MUST stay unchanged in every mode: same hooks, `event_id = order_paid_<order_id>`, own marker `_clicutcl_woo_milestone_sent_order_paid`, no retention or eligibility guard. `purchase` and `order_paid` are distinct events; both are dispatched for an eligible order.
- **FR-013**: EDD: with `paid`, a server `purchase` MUST be dispatched when a `sale` order transitions into `complete`, `completed` or `publish` (the three statuses `PAID_STATUSES` holds, `includes/integrations/class-edd.php:59`) outside the receipt request, using `event_id = purchase_edd_<order_id>`, the stored consent snapshot under FR-008's rule, the same marker values and `Claim_Lock` (`PURCHASE_LOCK_PREFIX`), and FR-009's creation-date guard. Renewal orders are excluded by construction (`edd_subscription` is not in `PAID_STATUSES`), refund-type orders by the `sale` check. The hook is `edd_update_payment_status( $order_id, $new_status, $old_status )`, the action EDD's own completion handler uses (`includes/payments/actions.php:245` on `main`); it MUST be confirmed against EDD 3.7.3 in the harness before it is relied on.
- **FR-014**: Lead gen: the form adapters' and browser listeners' lead events (`form_submission` server rows from `Abstract_Form_Adapter`, `lead` and `lead_submit` from the scripts) remain the conversion, unchanged. `trackThankYouLead()` MUST push the step event first (FR-004), then apply `thank_you_lead`: `always` pushes the legacy `lead` as today; `fallback` pushes it only when neither `ct_lead_seen` nor `ct_form_submit_seen` exists; `never` pushes none. The legacy `lead` keeps its payload and its `ct_thankyou_lead_<path>` marker.
- **FR-015**: Markers for FR-014: `ct_lead_seen` (written by `pushEvent()` after the consent check when a `lead` other than `redirect_thank_you` is pushed, and by `pushDL()` in `clicutcl-attribution.js` for `lead_submit`) and `ct_form_submit_seen` (written by the existing capture-phase `submit` listener, `clicutcl-events.js:844`, after the consent check). Both are session-scoped, hold no value or form content (a timestamp and a provider token at most), are cleared on consent withdrawal, and are added to the storage inventory and `tools/qa/cmp-transition.test.js`.
- **FR-016**: Admin copy (Events tab, beside the Woo toggles; stored in the attribution settings): **Purchase counts when** "The order is paid (recommended)" / "The visitor reaches the thank-you page (legacy)". Help: paid means the order reaches a WooCommerce paid status (processing or completed) or an EDD completed status; bank transfers and orders awaiting payment are not counted until they are paid; cash on delivery counts when WooCommerce marks it processing, before the cash is collected; the thank-you page is reported separately as `ct_thank_you_view`. Warning: "Changing this changes your reported revenue. Before you switch, move any Google Tag Manager trigger or ad conversion that fires on the thank-you page URL to the `purchase` event, or you will count the same sale twice." **Thank-you page counts as a lead** with the three options and the warning that `always` counts a page visit.
- **FR-017**: Surfaces: wizard step 3 summary adds one part when WooCommerce or EDD is active (`includes/admin/class-setup-wizard.php:544-560`); Site Health gains `test_conversion_semantics()` (status `good` in both modes, informational) beside `test_cookie_seen` (`add_tests()`, `includes/admin/class-sitehealth.php:36-55`); Diagnostics keeps the order-trace lookup and shows the `purchase` trace's `source_hook` and the marker value (**Hugo decision needed**: whether legacy mode should report `recommended` with a link to the migration notes).
- **FR-018**: Storage: no new cookie, table or order-meta key. New session keys `ct_thankyou_view_<id|path>`, `ct_lead_seen`, `ct_form_submit_seen` (Statistics category, like `ct_thankyou_lead_<path>`, `docs/guides/SECURITY-PRIVACY.md:114`). The marker keeps its key; its new values `server` and `browser` are non-personal.
- **FR-019**: Docs MUST be updated: `docs/reference/INTEGRATIONS.md` (Woo purchase flow, milestone section, EDD, lead gen), `docs/reference/HOOKS-REFERENCE.md` (`clicutcl_woocommerce_paid_statuses`, `clicutcl_woocommerce_purchase_created_via`, the matcher filter note), `docs/guides/SETTINGS-AND-ADMIN.md`, `docs/guides/SECURITY-PRIVACY.md` (storage inventory, consent authority), `docs/architecture/EVENT-PIPELINE.md` and `DATA-MODEL.md` (marker values, step event), `docs/tutorials/02-woocommerce-order-attribution.md`, `docs/tutorials/04-gtm-starter-kit.md`, `docs/MASTER-SPECIFICATION.md` (Woo journey, `CT-020`), `config/feature-test-matrix.json`, `readme.txt`, `README.en.md`, `changelog.txt`, and a migration section ([plan.md](plan.md), Migration notes).
- **FR-020**: Phase 2, not part of v1: pending-order browser recovery ([research.md](research.md) R9). v1 relies on server delivery for buyers who never return and on a reload of the thank-you URL for buyers who do.

### Key Entities

- **Purchase trigger**: `thankyou` (legacy, default for existing installs) or `paid` (default for fresh installs).
- **Thank-you lead mode**: `always`, `fallback` or `never`.
- **Step event**: `ct_thank_you_view` with `thank_you.context` `order_confirmation` or `lead_thank_you`.
- **Purchase marker**: order meta `_clicutcl_tracking_sent`, values `''`, `server`, `browser`, `yes`.
- **Paid statuses**: `wc_get_is_paid_statuses()` filtered by `clicutcl_woocommerce_paid_statuses`; EDD's `PAID_STATUSES`.

## Success Criteria *(mandatory)*

- **SC-001**: PHPUnit (Woo): paid at checkout then thank-you (US1), on-hold then paid by an administrator with an unrelated consent cookie present (US2, FR-008), thank-you refresh and double tab (US3), concurrent thank-you and status change on both lock backends, queued dispatch then second path, `thankyou` mode unchanged (US4, byte-compare of the pushed object), renewal and non-checkout `created_via` values (US5), refunded and cancelled, failed-order thank-you in both modes, COD `processing`, zero-total, retention (server refuses by creation date, thank-you keeps paid-date-first), consent withheld at checkout, no snapshot with consent required, marker values and their privacy retention.
- **SC-002**: PHPUnit (EDD): pending then complete via `edd_update_payment_status`, refund and renewal-status orders, receipt after server dispatch, `thankyou` unchanged.
- **SC-003**: Node VM: the US6 scenarios, the markers, withdrawal clearing, the order-received deferral and `pushEvent` with the no-server option; `npm run test:consent` runs it.
- **SC-004**: Harness (`tools/qa/e2e/`, under `flock /tmp/ct-e2e.lock`): `woo-purchase-ci.sh` real WooCommerce, BACS on-hold then paid, card path through `payment_complete()`, COD, failed order, thank-you refresh; `edd-ci.sh` gains pending then complete; evidence files `EVIDENCE-<date>-*.md`.
- **SC-005**: No existing test changes meaning: `WooCommercePurchaseDispatchLockTest`, `WooCommerceDataLayerContractTest`, `WooCommerceConsentBoundaryTest`, `EddIntegrationTest`, `tools/qa/gtm-starter-kit.test.js`, `tools/qa/cmp-transition.test.js` (with the three new keys added).
- **SC-006**: Docs, changelog and readme wording per FR-019; the migration notes name the triggers to move.
- **SC-007**: Live (open): a real card order, a real bank-transfer order and a Subscriptions renewal on a disposable site, and a block-theme order-received page, produce the events described. Until then the model stays source-present / runtime-unverified.

## Assumptions

- Server-side delivery is configured for the server `purchase` to leave the site; without it the browser `purchase` is the only visible copy and `paid` still gates it (the touch-event row is written either way, `Dispatcher::dispatch()` calls `Touch_Events_Store::record()` before the enabled check, `includes/server-side/class-dispatcher.php:145-149`).
- `purchase` and `order_paid` already reach ad platforms through the customer's own GTM or collector mapping; ClickTrail posts the whole event to the endpoint and does not rename it per platform (`class-meta-capi-adapter.php:58-82`), so how a downstream tag treats a second paid-time `purchase` is the customer's configuration, which the migration notes cover.
- The browser `purchase` and the server `purchase` carry the same `event_id`, as today, so platform-side dedupe (Meta Pixel plus CAPI) keeps working.
