# REST API Reference

Namespace coverage in code:

- active: `clicutcl/v2` (registered by `Tracking_Controller`)
- legacy class present: `clicutcl/v1` (`Log_Controller`, not wired by current bootstrap)

## Active API (v2)

Controller: `includes/api/class-tracking-controller.php`.

## 1) Batch Events

- Method: `POST`
- Route: `/wp-json/clicutcl/v2/events/batch`

Auth:

- valid REST nonce (`X-WP-Nonce` / `x_wp_nonce` / `_wpnonce`), or
- signed token (`X-Clicutcl-Token` header or `token` field in JSON body)

Guards:

- feature flag `event_v2` must be enabled
- body max size: `131072` bytes
- max events: `50`
- rate limit default: `60` requests per `60` seconds (filterable)
- optional token nonce replay limit

Request body (single event or batch):

```json
{
  "token": "signed-token",
  "events": [
    {
      "event_name": "lead",
      "event_id": "uuid",
      "event_time": 1739577600,
      "funnel_stage": "bottom",
      "session_id": "sess_x",
      "source_channel": "web",
      "page_context": { "path": "/contact" },
      "attribution": { "gclid": "abc" },
      "consent": { "marketing": true, "analytics": true },
      "lead_context": { "provider": "cf7", "submit_status": "success" },
      "meta": {}
    }
  ]
}
```

Response shape:

```json
{
  "success": true,
  "accepted": 1,
  "duplicates": 0,
  "skipped": 0,
  "errors": []
}
```

## 2) External Webhooks

- Method: `POST`
- Route: `/wp-json/clicutcl/v2/webhooks/{provider}`
- Supported providers: `calendly`, `hubspot`, `typeform`

Auth:

- feature flag `external_webhooks` enabled
- provider enabled in settings
- valid HMAC signature and timestamp

Required headers:

- `x-clicutcl-timestamp` (unix seconds)
- `x-clicutcl-signature` where signature is:
  - `hex(hmac_sha256("timestamp.raw_body", secret))`

Replay protection:

- timestamp drift bounded by replay window setting (default `300` seconds)
- transient replay key check enabled by default

Response (success):

```json
{
  "success": true,
  "duplicate": false,
  "event_id": "mapped-id",
  "event_name": "lead"
}
```

## 3) Lifecycle Updates

- Method: `POST`
- Route: `/wp-json/clicutcl/v2/lifecycle/update`
- Allowed stages:
  - `lead`
  - `book_appointment`
  - `qualified_lead`
  - `client_won`

Auth:

- admin `manage_options`, or
- lifecycle ingestion enabled plus valid CRM token:
  - header `x-clicutcl-crm-token`, or
  - body `token`

Example body:

```json
{
  "token": "crm-token",
  "stage": "qualified_lead",
  "lead_id": "lead_123",
  "provider": "hubspot"
}
```

## 4) Diagnostics

- `GET /wp-json/clicutcl/v2/diagnostics/delivery`
- `GET /wp-json/clicutcl/v2/diagnostics/dedup`

Auth:

- admin capability `manage_options`

Data returned:

- delivery: last error, recent dispatches, failure telemetry
- dedup: dedup check/hit/miss stats by destination

## Legacy API Class (v1)

Class file:

- `includes/api/class-log-controller.php`

Declared routes in that class:

- `POST /wp-json/clicutcl/v1/log`
- `POST /wp-json/clicutcl/v1/wa-click`

Important status:

- current plugin bootstrap does not instantiate/register `Log_Controller`
- these routes are defined in code but not active unless explicitly registered elsewhere

## Key Filters Affecting API Behavior

- `clicutcl_v2_rate_window`
- `clicutcl_v2_rate_limit`
- `clicutcl_v2_token_nonce_limit`
- `clicutcl_v2_trusted_proxies`
- `clicutcl_trusted_proxies`
- `clicutcl_webhook_replay_window`
- `clicutcl_webhook_replay_protection`

