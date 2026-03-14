# Event Pipeline

- **Audience**: contributors, maintainers, and reviewers
- **Canonical for**: browser-to-REST intake, webhook intake, lifecycle ingestion, dedup, and delivery flow
- **Update when**: intake stages, canonical event flow, dedup behavior, or delivery stages change
- **Last verified against version**: `1.5.0`

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
2. submitted attribution is logged to ClickTrail's form event table
3. consent-aware identity is resolved from submitted form fields plus request context
4. the enriched server-side form event is dispatched through the shared delivery pipeline

## 5. External Webhook Intake

Providers:

- Calendly
- HubSpot
- Typeform

Flow:

1. provider webhook hits `/clicutcl/v2/webhooks/{provider}`
2. provider signature is validated
3. replay-window checks run
4. provider payload is mapped into canonical lead or booking events
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
2. thank-you page pushes purchase event into `dataLayer`
3. the optional richer Woo `dataLayer` contract can add `event_id` and consent-aware `user_data`
4. purchase identity is resolved from WooCommerce order data plus request context
5. purchase payload is sent into dispatcher as a server-side event
6. purchase trace snapshots are stored on the order for Diagnostics lookup
7. duplicate purchase sends are prevented with order meta

Woo milestone flow:

1. Woo order status hooks trigger `order_paid`, `order_refunded`, or `order_cancelled`
2. each milestone reuses the purchase payload builder and receives a deterministic event ID
3. trace snapshots are stored on the order before and after dispatch
4. per-milestone order meta prevents repeated sends while queue retry remains the second line of defense

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
