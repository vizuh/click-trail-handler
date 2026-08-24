# Marketing trail envelope

ClickTrail WP emits a backward-compatible flat event and a normalized
`marketing_trail` object on browser dataLayer events, server-side events, and
form submissions. The JS package emits the same object from
`@vizuh/clicktrail/browser`.

Envelope v1 fields:

- `event_id`: unique event ID with `evt_` namespace.
- `trail_id`: stable trail namespace derived from the persistent visitor ID.
- `anonymous_id`: anonymous visitor namespace.
- `lead_id`: generated for lead/form events.
- `workspace_id` and `site_id`: optional routing identifiers.
- `event_name`, `occurred_at`, landing page, referrer, latest-touch source,
  medium and campaign with first-touch fallback.
- `click_ids`: normalized advertising identifiers.
- `consent`: capture-time `analytics` and `advertising` state.
- `form`: provider and form ID when a form is involved.

The free WP plugin keeps its existing event shape and adapter behavior. The
free JS package keeps its flat payload and adds the envelope to each delivered
event and to injected `ct_trail_id` form fields. Persistent visitor identity
requires the host to enable the package storage adapter.

Example:

```json
{
  "event_id": "evt_...",
  "trail_id": "trl_...",
  "anonymous_id": "anon_...",
  "lead_id": "lead_...",
  "workspace_id": "ws_...",
  "site_id": "site_...",
  "event_name": "lead_submitted",
  "occurred_at": "2026-08-24T16:30:00Z",
  "landing_page": "/botox-consultation",
  "referrer": "https://google.com/",
  "source": "google",
  "medium": "cpc",
  "campaign": "botox_new_york",
  "click_ids": { "gclid": "..." },
  "consent": { "analytics": true, "advertising": true },
  "form": { "provider": "elementor", "form_id": "consultation" }
}
```
