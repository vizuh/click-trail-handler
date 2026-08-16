# REST API Reference

- **Audience**: contributors, maintainers, integrators, and reviewers
- **Canonical for**: active routes, auth model, diagnostics endpoints, and REST-side constraints
- **Update when**: routes, auth headers, body limits, or intake behavior changes
- **Last verified against version**: `1.8.14`

Active REST namespace:

- `clicutcl/v2`

Primary controller:

- `includes/api/class-tracking-controller.php`

## Active Routes

## `POST /clicutcl/v2/events/batch`

Purpose:

- receive canonical browser event batches

Used by:

- `assets/js/clicutcl-events.js`

Auth model:

- admin nonce for privileged admin/debug flows, or
- signed client token via `X-Clicutcl-Token` header or `token` in JSON body

Important constraints:

- browser event collection must be enabled
- request body size is capped
- request rate limiting is enforced
- each page token is limited to 20 browser-batch requests by default
- only the browser events emitted by `clicutcl-events.js` are accepted; purchase and lifecycle outcomes use trusted server routes

Allowed canonical browser events:

- behavior: `search`, `view_content`, `scroll_depth`, `key_page_view`, `cta_click`, `contact_call_click`, `contact_chat_start`
- storefront: `view_item`, `view_item_list`, `view_cart`, `add_to_cart`, `remove_from_cart`, `begin_checkout`
- forms and milestones confirmed in the browser: `form_start`, `form_submit_attempt`, `lead`, `book_appointment`, `login`, `sign_up`, `comment_submit`

`purchase`, `qualified_lead`, and `client_won` are rejected by this route and must come from WooCommerce or lifecycle ingestion.

Notes:

- browser-side REST transport is only configured when browser event collection is enabled and delivery transport is available
- browser events can still push to `window.dataLayer` when collection is enabled even if REST transport is not active

## `POST /clicutcl/v2/attribution-token/sign`

Purpose:

- mint a signed cross-domain attribution token

Used by:

- `assets/js/clicutcl-attribution.js`

Auth model:

- page client token in `X-Clicutcl-Token`
- signed attribution token in the JSON `token` field

## `POST /clicutcl/v2/attribution-token/verify`

Purpose:

- verify an incoming attribution token and normalize the allowed attribution payload

Used by:

- cross-domain attribution continuity flow

## `POST /clicutcl/v2/webhooks/{provider}`

Supported providers:

- `calendly`
- `hubspot`
- `typeform`

Purpose:

- accept external lead-source or form-source events and translate them into the canonical pipeline

Auth model:

- native Typeform `Typeform-Signature` verification
- native HubSpot `X-HubSpot-Signature` verification
- ClickTrail timestamp/signature verification for Calendly until a native contract is verified
- replay-window enforcement
- provider enablement and secret checks

HubSpot webhook arrays are processed item by item. Consent-blocked webhook and lifecycle requests still return HTTP success to prevent retries, but their JSON body reports `success: false`, `skipped: true`, and the skip reason.

## `POST /clicutcl/v2/lifecycle/update`

Purpose:

- accept lifecycle updates from backend or CRM systems

Allowed lifecycle stages:

- `lead`
- `book_appointment`
- `qualified_lead`
- `client_won`

Auth model:

- lifecycle token

## `GET /clicutcl/v2/diagnostics/delivery`

Purpose:

- return delivery diagnostics for privileged users

Auth model:

- admin capability check through the controller permission callback

## `GET /clicutcl/v2/diagnostics/dedup`

Purpose:

- inspect dedup diagnostics for privileged users

Auth model:

- admin capability check through the controller permission callback

## Canonical Event Flow

The active REST controller receives canonical events and then routes them into the existing delivery stack:

1. request passes auth and rate-limit checks
2. payload is normalized into `EventV2`
3. consent and identity rules are applied
4. v2 payload is translated into the existing delivery event shape
5. dispatcher sends or queues the event

## Security Controls

Relevant controls exposed by code:

- max batch size
- max request body size
- request rate limiting
- token nonce replay limits
- trusted proxy resolution
- allowed token hosts
- optional subdomain token acceptance
- webhook replay protection

See also:

- [docs/guides/SECURITY-PRIVACY.md](../guides/SECURITY-PRIVACY.md)
- [docs/architecture/EVENT-PIPELINE.md](../architecture/EVENT-PIPELINE.md)

## Legacy API Status

The legacy v1 log controller (`includes/api/class-log-controller.php`) has been removed from the codebase entirely. `clicutcl/v2` via `Tracking_Controller` is the only registered REST surface.

Legacy API status:

- removed from the repository
- not part of the active GitHub-facing product surface
- would require reintroduction from an earlier tag to bring back
