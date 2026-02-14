# Event Pipeline

This document covers the runtime path for tracking data from browser capture to server dispatch.

## 1) Browser Capture Layer

Primary scripts:

- `assets/js/clicutcl-attribution.js`
- `assets/js/clicutcl-events.js`

Attribution script responsibilities:

- parse query params (`utm_*`, `gclid`, `fbclid`, `msclkid`, `ttclid`, `wbraid`, `gbraid`)
- maintain first-touch and last-touch state
- normalize click IDs to canonical keys when derivable
- persist attribution in cookie and localStorage (`attribution` key by default)
- inject hidden fields into forms (`ct_*` mapping)
- optional outbound link decoration with allowlist
- optional compact token append for cross-domain continuity

Events script responsibilities:

- pushes behavioral and lead-gen events to `window.dataLayer`
- maps event stream to canonical v2 event names
- sends batch payload to `/clicutcl/v2/events/batch` when URL and token are provided
- includes `session_id`, `visitor_id`, `event_id`

## 2) Canonical Event Schema (v2)

Schema class: `includes/tracking/class-eventv2.php`.

Required top-level fields:

- `event_name`
- `event_id`
- `event_time`
- `funnel_stage`
- `session_id`
- `source_channel`
- `page_context` (array)
- `attribution` (array)
- `consent` (array with `marketing` and `analytics`)
- `meta` (array)

Optional context fields:

- `lead_context`
- `commerce_context`
- `identity`
- `delivery_context`

Normalization behavior:

- sanitizes scalar and nested array values
- adds `meta.schema_version = 2`
- adds `meta.plugin_version` when plugin constant exists
- derives canonical click IDs from `lt_*` then `ft_*` when canonical key is missing

## 3) Legacy Translation Boundary

Translator class: `includes/tracking/class-event-translator-v1-to-v2.php`.

Inputs accepted:

- canonical-like payloads
- legacy event keys (`event`, `timestamp`, `ts`, `page`, etc.)

Translator outputs:

- canonical v2 payload via `EventV2::normalize()`
- inferred `funnel_stage` (`top`, `mid`, `bottom`, `unknown`)
- inferred `intent_level` in `meta.segments.intent_level`

Lead-gen stage support in translator:

- `lead`
- `book_appointment`
- `qualified_lead`
- `client_won`

## 4) v2 Intake Endpoint

Controller: `includes/api/class-tracking-controller.php`.

Endpoint:

- `POST /wp-json/clicutcl/v2/events/batch`

Intake flow:

1. feature flag check (`event_v2`)
2. body size cap (`131072` bytes)
3. rate limit check
4. auth:
   - valid `wp_rest` nonce, or
   - valid signed client token (`x-clicutcl-token` header or `token` in body)
5. optional token nonce replay limiter
6. translate each raw event to canonical
7. validate canonical schema
8. dedup check (`destination=ingest`)
9. consent-aware identity resolution
10. dispatch through server-side dispatcher
11. mark dedup key on success

Batch caps:

- max `50` events per request

## 5) Consent and Identity Policy

Consent decision:

- `includes/tracking/class-consent-decision.php`

Identity resolver:

- `includes/tracking/class-identity-resolver.php`

Default mode:

- `consent_gated_minimal`

Identity behavior:

- if marketing not allowed, returns empty identity payload
- if allowed, hashes email/phone (`sha256`) when valid
- optional IP/UA passthrough only when context explicitly includes `include_ip_ua`

## 6) Deduplication

Store class: `includes/tracking/class-dedup-store.php`.

Mechanics:

- transient key prefix: `clicutcl_v2_dup_`
- key hash: `md5(destination|event_name|event_id)`
- default TTL from settings (default 7 days, bounded)
- dedup stats tracked in separate transient

Used at:

- v2 ingest boundary (`destination=ingest`)
- dispatcher per destination adapter key
- queue replays before send

## 7) Dispatch and Retry

Dispatcher: `includes/server-side/class-dispatcher.php`.

Dispatch path:

1. check server-side enabled (`clicutcl_server_side.enabled`)
2. require endpoint URL
3. consent gate (marketing consent if required)
4. build adapter by configured key
5. dedup check by adapter destination + event key
6. send event via adapter
7. on failure:
   - record last error
   - record failure telemetry
   - enqueue for retry
8. on success:
   - mark dedup key
   - optionally add debug dispatch log entry

Queue:

- class: `includes/server-side/class-queue.php`
- table: `wp_clicutcl_queue`
- cron hook: `clicutcl_dispatch_queue` (every 5 minutes custom schedule)
- retry policy: exponential backoff (`60 * 2^attempt`, capped at 3600)
- max attempts: `5`

## 8) Delivery Diagnostics

Available diagnostics:

- last error transient
- recent dispatch ring buffer (debug-window gated)
- always-on failure telemetry buckets (aggregated only, no payload)
- dedup stats via v2 diagnostics endpoint

Failure telemetry design:

- local-only by default
- optional remote reporting through action hook when explicitly enabled
- no payload bodies and no raw PII

