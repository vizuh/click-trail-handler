# Portable Tracking Prompt

- **Audience**: maintainers and AI agents porting ClickTrail concepts into other products
- **Status**: derived artifact, not canonical source of truth
- **Derived from**:
  - `docs/architecture/PLUGIN-OVERVIEW.md`
  - `docs/architecture/EVENT-PIPELINE.md`
  - `docs/architecture/DATA-MODEL.md`
  - `docs/guides/SETTINGS-AND-ADMIN.md`
  - `docs/guides/SECURITY-PRIVACY.md`
  - `docs/reference/REST-API.md`
  - `docs/reference/INTEGRATIONS.md`
- **Last derived from version**: `1.3.6`

Use the prompt below when you want another project or AI agent to reproduce the tracking, attribution, privacy, and settings model behind ClickTrail without copying the WordPress-specific implementation literally.

Replace the bracketed placeholders before using it.

## Copy-Paste Prompt

```text
You are implementing the tracking, attribution, and settings subsystem for [project name] in [stack/framework].

Your job is to reproduce the behavior and operating model of a production-grade attribution system like ClickTrail, but adapted to the host project's architecture, naming, storage, UI, and infrastructure conventions.

First inspect the existing codebase. Reuse host patterns where they already solve part of the problem. Do not bolt on a parallel architecture unless the current one cannot support the required behavior. If something in this prompt conflicts with the host stack, keep the behavior and intent, but translate the implementation to the local conventions.

Do not assume a WordPress plugin context, PHP classes, admin pages, or any repository layout such as `docs/`, `includes/`, `assets/`, `app/`, or `src/`. The host project may be a SaaS app, SPA, Next.js app, Laravel app, Rails app, mobile backend, headless CMS, storefront, or custom monolith. Important behavior is defined in this prompt itself, not by any expected folder names from the source project.

Before proposing implementation, perform a discovery and mapping pass for the host project.

Mandatory discovery output:
- identify the runtime stack, frontend stack, backend stack, storage options, job/queue system, and deployment environments
- identify existing analytics surfaces such as `dataLayer`, Segment, RudderStack, custom telemetry, event buses, message queues, or internal tracking helpers
- identify existing forms, lead capture flows, checkout/order flows, CTAs, chat/WhatsApp links, thank-you states, schedulers, and embedded widgets
- identify existing settings surfaces such as admin pages, dashboards, feature-flag panels, environment config, or database-backed settings screens
- identify existing auth, API, webhook, cron, queue, and background-job patterns
- identify existing consent sources, cookie banners, CMP bridges, privacy middleware, or region logic
- identify the existing file/module boundaries where tracking code should live
- identify naming conventions for classes, services, hooks, stores, controllers, routes, jobs, and tests

Telemetry mapping requirements:
- map the required telemetry to the host project's real UI and code, not to imaginary buttons or generic demo elements
- inventory existing buttons, CTAs, forms, links, components, templates, and route transitions that should emit events
- for each telemetry event, name the exact host trigger point: component, class, hook, template, route, controller, selector, or backend job
- prefer instrumentation at stable code boundaries such as component handlers, service methods, or form submission hooks instead of brittle CSS selectors
- if DOM selectors are unavoidable, document the exact selectors, why they are stable enough, and the risk if markup changes
- preserve existing business terminology where it improves alignment, but keep the canonical attribution schema consistent
- if the host project already emits similar events, map to or extend the current event taxonomy instead of creating parallel duplicate events

Module and file-placement rules:
- do not assume the host project has folders dedicated to tracking, integrations, settings, or diagnostics
- if equivalent modules already exist, extend them
- if equivalent modules do not exist, propose the smallest coherent module layout needed for maintainability
- when proposing file placement, explain why each file belongs there in that host project
- if the host project is highly centralized, prefer a small number of well-defined modules over a fake deep folder tree

The subsystem must solve these failures:
- attribution loss across multi-page and multi-session journeys
- cached pages or dynamic/AJAX-rendered forms missing attribution fields
- cross-domain or cross-subdomain journeys losing continuity
- e-commerce or checkout flows losing campaign context
- consent, event collection, and delivery behaving inconsistently

Model the system around four user-facing capability areas:

1. Capture
- enable or disable attribution capture
- store first-touch and last-touch attribution
- support configurable retention
- support approved cross-domain continuity

2. Forms
- keep attribution attached to forms and lead-entry surfaces
- support client-side fallback for cached pages
- support dynamic-content watching
- support overwrite-vs-preserve behavior for hidden fields
- support WhatsApp or external-message attribution continuity when relevant

3. Events
- support browser event collection and analytics-friendly event pushes
- support canonical event intake
- support lifecycle updates from backend/CRM systems
- support destination enablement without coupling everything to one provider

4. Delivery
- support optional server-side transport
- support privacy and consent gating
- support retry queue, diagnostics, and failure telemetry

Use this attribution contract:

Touch-level campaign fields:
- `source`
- `medium`
- `campaign`
- `term`
- `content`
- `utm_id`
- `utm_source_platform`
- `utm_creative_format`
- `utm_marketing_tactic`
- `referrer`
- `landing_page`
- `touch_timestamp`

Supported click IDs:
- `gclid`
- `wbraid`
- `gbraid`
- `fbclid`
- `ttclid`
- `msclkid`
- `twclid`
- `li_fat_id`
- `sccid`
- `epik`

Treat `sc_click_id` as an alias of `sccid`.

Supported browser/platform identifiers when available and allowed by consent:
- `fbc`
- `fbp`
- `ttp`
- `li_gc`
- `ga_client_id`
- `ga_session_id`
- `ga_session_number`

Persist attribution in a canonical flat shape using `ft_` and `lt_` prefixes for first-touch and last-touch values. Also keep the top-level click IDs and browser identifiers for convenience and downstream export. Normalize legacy aliases back to canonical keys on read.

Use a payload shape equivalent to:

{
  "ft_source": "",
  "ft_medium": "",
  "ft_campaign": "",
  "ft_term": "",
  "ft_content": "",
  "ft_utm_id": "",
  "ft_utm_source_platform": "",
  "ft_utm_creative_format": "",
  "ft_utm_marketing_tactic": "",
  "ft_referrer": "",
  "ft_landing_page": "",
  "ft_touch_timestamp": "",
  "lt_source": "",
  "lt_medium": "",
  "lt_campaign": "",
  "lt_term": "",
  "lt_content": "",
  "lt_utm_id": "",
  "lt_utm_source_platform": "",
  "lt_utm_creative_format": "",
  "lt_utm_marketing_tactic": "",
  "lt_referrer": "",
  "lt_landing_page": "",
  "lt_touch_timestamp": "",
  "gclid": "",
  "wbraid": "",
  "gbraid": "",
  "fbclid": "",
  "ttclid": "",
  "msclkid": "",
  "twclid": "",
  "li_fat_id": "",
  "sccid": "",
  "epik": "",
  "fbc": "",
  "fbp": "",
  "ttp": "",
  "li_gc": "",
  "ga_client_id": "",
  "ga_session_id": "",
  "ga_session_number": ""
}

Capture rules:
- if URL campaign parameters or click IDs exist, map them into touch fields
- if tagged parameters do not exist, inspect the external referrer and infer `organic`, `social`, or `referral`
- search engines should map to `organic`
- major social domains should map to `social`
- other external referrers should map to `referral`
- same-site and related-host referrers should not create a new attribution touch
- first touch is set only once unless the host project explicitly supports reset flows
- last touch updates whenever a new valid attribution signal appears
- touch timestamps and landing pages must be stored

Storage rules:
- keep attribution in a first-party, server-readable store such as a cookie or equivalent request-visible storage
- keep a client-side mirror with explicit expiry metadata so cached pages and dynamic forms still work
- tie client-side mirror expiry to the retention setting
- discard legacy local copies that have no expiry metadata instead of reviving them indefinitely
- clear attribution storage when consent resolves to denied

Identity and session rules:
- maintain stable visitor identity when the host project supports it
- maintain session identity separately from attribution
- use a 30-minute inactivity timeout to roll a new session
- expose `session_id`, `session_number`, and `visitor_id` to browser events and downstream payloads

Cross-domain continuity:
- support link decoration only for approved domains
- support a setting to skip modifying already signed URLs
- optionally pass a signed attribution token between approved domains or subdomains
- provide token sign and verify endpoints or equivalent backend helpers
- token verification must enforce allowed hosts and normalize the incoming attribution payload before use

Forms and lead surfaces:
- automatically inject hidden attribution fields where the host form system allows it
- otherwise populate matching hidden fields already present in the form
- support client-side fallback for cached pages
- support dynamic-form observation with a configurable observer target
- support overwrite behavior as a setting
- store attribution with the submission record even when hidden-field injection is not possible
- resolve consent-aware identity from submitted data and request context
- for embedded or external form systems, support webhook intake into the same canonical event pipeline
- if the host project uses component props, state stores, server actions, or API payload builders instead of hidden fields, map attribution into those boundaries directly
- if the host project has no traditional forms, adapt the same attribution contract to the real conversion surfaces it does have

Commerce and conversion flows:
- store attribution on checkout or order creation
- surface attribution in the order/admin detail view when the host project has one
- push purchase events to the analytics event layer when applicable
- optionally dispatch purchase events through the same server-side delivery pipeline
- prevent duplicate purchase dispatches with a durable dedup marker

Browser event collection:
- push browser events to an analytics-friendly event layer such as `window.dataLayer` when relevant
- support events like site search, file download, scroll depth, engagement thresholds, form start, form submit attempt, CTA interactions, and thank-you page lead detection
- browser event collection must remain a separate switch from attribution capture
- telemetry must be attached to the host project's actual UI affordances, route changes, and server-side transitions
- map each event to existing components, templates, pages, handlers, or jobs so the event contract matches what the product really exposes

Canonical event pipeline:
- normalize incoming events into one canonical event schema
- support intake from browser events, form submissions, commerce conversions, provider webhooks, and backend lifecycle updates
- apply auth, request limits, consent rules, identity resolution, normalization, and translation before delivery

Lifecycle and webhook intake:
- support lifecycle updates from backend or CRM systems for stages equivalent to `lead`, `book_appointment`, `qualified_lead`, and `client_won`
- support signed webhook ingestion for external providers
- validate provider signatures
- enforce replay-window checks
- translate provider payloads into the same canonical event pipeline

Delivery behavior:
- server-side delivery must be optional
- if delivery is disabled, attribution capture, form enrichment, and commerce attribution must still work
- browser events may still push to the analytics event layer even if REST/server delivery is off
- if delivery is enabled, validate environment, settings, endpoint, consent, and adapter before dispatch
- support adapter-based delivery so multiple providers can share the same queue, diagnostics, and retry logic

Queue and retry behavior:
- queue failed delivery attempts for retry
- process retries on a background schedule
- use bounded exponential backoff
- use a small due-row batch size per run
- cap maximum retry attempts
- track queue backlog and recent failures in diagnostics

Privacy and consent behavior:
- support consent modes equivalent to `strict`, `relaxed`, and `geo`
- support consent sources equivalent to auto detection, native banner, Cookiebot, OneTrust, Complianz, GTM, or custom bridge
- normalize consent state through one runtime contract
- if consent is denied, clear client attribution storage and block event dispatch that requires consent
- allow attribution and identity data to be minimized when consent rules require it

Security controls:
- use signed client tokens for browser-to-backend event intake when needed
- enforce request size limits and rate limits
- support nonce or replay protection for signed browser tokens
- support trusted proxy configuration for source IP and rate-limit correctness
- treat provider and lifecycle secrets as masked, write-only values in admin responses
- optionally support encryption at rest for stored secrets
- block server-side dispatch by default in local or development environments unless explicitly overridden

Diagnostics and operations:
- keep Logs and Diagnostics as separate operational surfaces rather than burying them inside the main settings flow
- expose endpoint tests, queue backlog, recent dispatches, last-error state, failure telemetry, and a local tracking-data purge action
- keep always-on failure telemetry aggregated and payload-free
- allow temporary debug windows for deeper inspection without making verbose logging permanent
- if the host project does not have dedicated settings or operations pages, map these surfaces to the closest existing dashboard, internal tool, or admin route and state the gap explicitly

Settings model:
- present the main settings UI by capability, not by internal storage names
- use `Capture`, `Forms`, `Events`, and `Delivery` as the primary mental model
- keep operational pages such as `Logs` and `Diagnostics` separate
- do not expose legacy/internal compatibility names in the user-facing UI
- if the host project has no settings UI, define the equivalent contract in environment config, database settings, feature flags, or internal admin tooling
- map real buttons, toggles, inputs, sections, and save flows to the settings contract so telemetry and configuration match what actually exists

Minimum user-facing settings expected by tab:

Capture:
- attribution enabled
- retention days
- link decoration enabled
- allowed domains
- skip signed URLs
- pass cross-domain token

Forms:
- client-side capture fallback
- dynamic-content watching
- replace existing hidden values
- observer target
- WhatsApp tracking enabled
- append attribution to WhatsApp message
- external form/webhook sources

Events:
- browser event collection enabled
- GTM or analytics container ID when relevant
- destination enablement
- lifecycle intake enabled and secured

Delivery:
- server-side transport enabled
- endpoint URL
- adapter selection
- timeout
- remote failure telemetry
- consent mode enabled
- consent behavior mode
- consent regions
- consent source
- consent timeout
- consent cookie name
- advanced security and diagnostics controls

Implementation constraints:
- preserve one clear source of truth per subject
- keep deep technical documentation in English
- update docs whenever public behavior, settings, API routes, storage, privacy behavior, or delivery semantics change
- if a feature cannot be mapped cleanly to the host stack, state the gap explicitly and propose the closest equivalent
- do not assume this source project's file names, option names, route names, screen names, or class names exist in the host project
- keep the ClickTrail behavioral contract, but rename and place code according to the host project's existing architecture
- include enough implementation detail in your answer that another engineer could execute it even if the host project has no equivalent docs folder or architecture map

Deliverables:
1. A short architecture mapping from this contract to the host project.
2. A discovery inventory of the host project's actual components, routes, forms, buttons, handlers, services, jobs, settings surfaces, and telemetry touchpoints.
3. A telemetry mapping matrix that ties each required event and attribution behavior to exact host trigger points.
4. A concrete implementation plan.
5. The exact settings schema and storage model.
6. The runtime flow for attribution capture, forms, events, delivery, consent, and diagnostics.
7. Any new endpoints, jobs, queues, services, or admin/internal surfaces required.
8. Proposed file or module placement based on the host project's real structure, including justification.
9. Tests for capture logic, consent gating, queue retries, and cross-domain continuity.
10. Documentation updates aligned with the implemented behavior, even if the host project currently lacks a formal docs area.

When you answer:
- findings and risks first if reviewing an existing implementation
- otherwise implementation steps first
- call out assumptions explicitly
- call out any host-project gaps where required telemetry cannot be attached cleanly to current buttons, classes, routes, or handlers
- do not invent unsupported integrations and present them as complete
- prefer a production-safe design over a demo-only implementation
```
