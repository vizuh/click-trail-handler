# REST API Reference

- **Audience**: contributors, maintainers, integrators, and reviewers
- **Canonical for**: active routes, auth model, diagnostics endpoints, and REST-side constraints
- **Update when**: routes, auth headers, body limits, or intake behavior changes
- **Last verified against version**: `1.3.9`

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
- token nonce replay limits can be enforced

Notes:

- browser-side REST transport is only configured when browser event collection is enabled and delivery transport is available
- browser events can still push to `window.dataLayer` when collection is enabled even if REST transport is not active

## `POST /clicutcl/v2/attribution-token/sign`

Purpose:

- mint a signed cross-domain attribution token

Used by:

- `assets/js/clicutcl-attribution.js`

Auth model:

- signed client token or admin/debug flow as allowed by the controller

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

- webhook signature verification
- replay-window enforcement
- provider enablement and secret checks

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

`includes/api/class-log-controller.php` still exists in the repository, but the current bootstrap registers only `Tracking_Controller`.

Legacy API status:

- disabled by default
- not part of the active GitHub-facing product surface
- can only be reintroduced intentionally via code-level changes
