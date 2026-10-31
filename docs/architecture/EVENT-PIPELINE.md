# Event Pipeline

- **Audience**: contributors, maintainers, and reviewers
- **Canonical for**: browser-to-REST intake, webhook intake, lifecycle ingestion, dedup, and delivery flow
- **Update when**: intake stages, canonical event flow, dedup behavior, or delivery stages change
- **Last verified against version**: `1.8.5`

ClickTrail uses one unified event pipeline behind the admin UI, even though the data can enter the system from different sources.

## Intake Sources

There are five main intake paths:

1. browser attribution and browser events
2. form submission integrations
3. WooCommerce purchase dispatch
4. external provider webhooks
5. lifecycle updates from CRM or backend systems

## 1. Browser Attribution Flow

Primary script:

- `assets/js/clicutcl-attribution.js`

Responsibilities:

- read UTMs and supported click IDs from the URL plus referrer context from the pageview
- normalize attribution values
- infer organic, social, or referral source/medium from external referrers when tagged campaign signals are absent
- store first-touch and last-touch context
- populate supported form fields
- decorate approved outbound links
- support WhatsApp attribution append behavior

Cross-domain continuation can optionally use:

- `POST /clicutcl/v2/attribution-token/sign`
- `POST /clicutcl/v2/attribution-token/verify`

## 2. Browser Event Flow

Primary script:

- `assets/js/clicutcl-events.js`

Browser events currently include:

- site search
- file download
- scroll depth
- user engagement time thresholds
- one-time WordPress follow-up events such as `login`, `sign_up`, and `comment_submit`
- form start
- form submit attempt
- selected lead-gen CTA interactions
- WooCommerce storefront signals including `view_item`, `view_item_list`, `view_cart`, `add_to_cart`, `remove_from_cart`, and `begin_checkout` when the storefront-events flag is enabled
- thank-you page lead detection
- external message bridge events for supported embedded providers
- HubSpot embedded form outcomes (`lead`, `form_submit_failed`), see below

Flow:

1. event is created in the browser
2. consent is checked
3. event is pushed to `window.dataLayer`
4. if REST delivery is configured, a canonical event payload is posted to `/clicutcl/v2/events/batch`

Woo list-view specifics:

- product loops, related products, upsells, cross-sells, widgets, and supported Woo blocks can resolve `item_list_name`
- list views fire once per detected container
- downstream add-to-cart events can inherit `item_list_name` and `item_list_index` when the click came from a tracked list
- when the richer Woo `dataLayer` contract is enabled, Woo browser events can also carry consent-aware `user_data` identifiers for GTM-first setups
- `view_cart` can come from the cart page itself, visible mini-cart surfaces, or supported drawer flows when the browser runtime can resolve the current cart payload from Woo blocks, fragments, or DOM cart rows

Form-success `lead` dedupe (source-present / runtime-unverified, #140):

- `markOnce(key, windowMs)` records a key and reports whether it is new; a repeat inside the window does not refresh it. `pushLeadOnce()` checks consent first (a denied attempt leaves no marker), then `markOnce()`, then `pushEvent('lead', ...)`; a dropped repeat is silent and never pushes `form_submit_failed`
- Contact Form 7 `wpcf7mailsent`: key `lead|cf7|<contactFormId>|<unitTag>`, 10 s window, so a double-fired hook or retried AJAX send is one `lead` while a different form or a different instance of the same form (different `unitTag`) still pushes
- generic external lead messages (`typeform-submit`, `clicutcl.lead`, ...): a message carrying an ID dedupes on that ID for the page's lifetime; one without an ID dedupes per provider inside the 10 s window
- HubSpot uses the same helper (see below); the thank-you page lead keeps its per-path `sessionStorage` marker

HubSpot embedded forms (source-present / runtime-unverified):

- listeners for HubSpot's documented `hs-form-event:on-submission:success` and `hs-form-event:on-submission:failed` window events are registered when the script loads, before consent resolves; they do nothing until the tracker exists, and every push still goes through the `pushEvent()` consent gate
- success pushes one `lead` with `form_provider: hubspot`, `form_id` from `event.detail.formId`, and `lead_context.provider: hubspot`; `event_id` is `hsform_<conversionId>` when `HubSpotFormsV4.getFormFromEvent(event).getConversionId()` returns one, otherwise `hsform_<formId>|<instanceId>|<page nonce>` (the nonce keeps the server dedup key unique across visitors, because instance IDs may repeat)
- failure pushes `form_submit_failed` (`submit_status: failed`), never `lead`
- a legacy `message` with `type: hsFormCallback` and `eventName: onFormSubmitted` pushes the same `lead` with `form_id` from the message `id`; it passes the same origin rule as the other external messages (same host or `clicutcl_iframe_origin_allowlist`), and other `hsFormCallback` event names are ignored
- a v4 event and a legacy message for the same form within 10 seconds, or a repeated dispatch of the same submission, produce one `lead`
- the browser never pushes `qualified_lead`; that stage stays on the HubSpot webhook and lifecycle routes
- writing attribution into HubSpot hidden fields is not implemented (blocked on live proof, `specs/003-hubspot-form-capture/tasks.md`)

## 3. Canonical Intake and Normalization

Controller:

- `includes/api/class-tracking-controller.php`

Key components:

- `CLICUTCL\Tracking\EventV2`
- `CLICUTCL\Tracking\Event_Translator_V1_To_V2`
- `CLICUTCL\Tracking\Identity_Resolver`
- `CLICUTCL\Tracking\Consent_Decision`

What happens:

- payloads are authenticated
- request limits are enforced
- canonical fields are normalized
- consent and identity rules are applied
- payloads are translated into the delivery event shape when needed

## 4. Form Submission Flow

Integrations:

- `includes/integrations/forms/*`

Flow:

1. ClickTrail injects or populates attribution where the form integration supports it
2. submitted attribution is logged to ClickTrail's form event table, once per entry: `Abstract_Form_Adapter::log_submission()` marks a key in `Dedup_Store` (destination `form_submission`, event name = provider) before writing, and a repeat writes no second `form_submission` row and dispatches no second event, returning the first call's `event_id` (#140)
3. consent-aware identity is resolved from submitted form fields plus request context
4. the enriched server-side form event is dispatched through the shared delivery pipeline

Form dedupe key (`log_submission()`):

- providers with an entry ID (Gravity Forms, WPForms, Fluent Forms, Ninja Forms when its Save action stored a submission ID): `(site, provider, form_id, entry_id)`, held for a day
- providers without one (Contact Form 7, Elementor Forms Pro): **best-effort** `(site, provider, form_id, sha1 of the submitted ct_* attribution values plus the email/phone found in the posted data, 10 s time bucket)`. A repeat that crosses a bucket boundary, or whose posted `ct_*` values differ, counts as a new submission; two visitors posting identical values inside one bucket count as one
- the `event_id` is `form_` plus the first 32 hex characters of the key's sha1, so it is the same on every call for an entry
- the `Dedup_Store` read-then-write is not atomic: sequential repeats (double click, retried AJAX, a hook firing twice) are caught, two truly simultaneous requests can both pass
- consent is checked before the dedupe mark; a gated call marks nothing

## 5. External Webhook Intake

Providers:

- Calendly
- HubSpot
- Typeform

Flow:

1. provider webhook hits `/clicutcl/v2/webhooks/{provider}`
2. provider signature is validated; for HubSpot v3, native Calendly and the ClickTrail relay the signed provider
   timestamp must fall inside the replay window (HubSpot v1 and Typeform carry no timestamp)
3. the signature replay cache rejects a repeat within the replay window
4. valid non-conversion events (Calendly cancellations, HubSpot types off the allowlist, unqualified HubSpot
   property changes) are acknowledged as `ignored`; the rest are mapped into canonical lead or booking events
5. event enters the same dispatcher path as browser-originated events

## 6. Lifecycle Update Intake

Route:

- `POST /clicutcl/v2/lifecycle/update`

Use case:

- let backend systems or CRMs report movement through lifecycle stages such as `qualified_lead` or `client_won`

Flow:

1. request provides lifecycle token
2. stage is validated
3. canonical event is created
4. event enters dispatcher

## 7. WooCommerce Purchase Flow

Integration:

- `includes/integrations/class-woocommerce.php`

Flow:

1. attribution is saved on checkout
2. thank-you page pushes purchase event into `dataLayer`, always carrying `event_id` equal to the server purchase event ID (`purchase_<order_id>`) so browser and server copies dedupe (#139)
3. the optional richer Woo `dataLayer` contract can add consent-aware `user_data`
4. purchase identity is resolved from WooCommerce order data plus request context
5. purchase payload is sent into dispatcher as a server-side event
6. purchase trace snapshots are stored on the order for Diagnostics lookup
7. duplicate purchase sends are prevented with order meta

Woo milestone flow:

1. Woo order status hooks trigger `order_paid`, `order_refunded`, or `order_cancelled`
2. each milestone reuses the purchase payload builder and receives a deterministic event ID
3. trace snapshots are stored on the order before and after dispatch
4. per-milestone order meta is only written after a successful, skipped, or confirmed queued attempt, so queue retry remains the second line of defense without suppressing unqueued failures

## 8. Dispatch and Queue

Dispatcher:

- `includes/server-side/class-dispatcher.php`

Queue:

- `includes/server-side/class-queue.php`

Flow:

1. dispatcher validates environment, settings, endpoint, and consent
2. adapter is selected from the registry-backed allowlist
3. dedup check runs
4. send attempt happens
5. success is logged and dedup marker is stored
6. failures are logged and queued for retry when applicable

Queue behavior:

- cron hook: `clicutcl_dispatch_queue`
- interval: every 5 minutes
- batch size: 10 due rows per run
- max attempts: 5
- exponential backoff capped at 1 hour

## 9. Diagnostics and Debugging

Delivery diagnostics:

- setup checklist in Settings for rollout readiness
- conflict scan in Diagnostics
- recent dispatch buffer
- last error snapshot
- aggregated failure telemetry
- queue backlog stats
- Woo order trace lookup backed by stored order snapshots

Intake diagnostics:

- v2 event debug buffer during debug windows

## Important Runtime Distinction

If server-side delivery is disabled:

- attribution capture still works
- form enrichment still works
- WooCommerce order attribution still works
- browser events can still push to `dataLayer` when browser event collection is enabled
- queue processing and delivery dispatch do not run
