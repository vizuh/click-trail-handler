# ClickTrail Implementation Playbook

- **Audience**: implementation engineers, solution architects, technical PMs, support teams, and agency delivery teams
- **Canonical for**: recommended rollout patterns, phased adoption, ownership handoff, and practical validation steps
- **Update when**: capability boundaries, rollout guidance, or recommended setup patterns change
- **Last verified against version**: `1.3.9`

Use this guide when a team asks "How should we actually deploy ClickTrail?" Start here before diving into the deeper architecture and reference docs.

## Core Rollout Principles

1. Do not enable everything on day one.
2. Start with the conversion surfaces you already own: forms or WooCommerce.
3. Treat `Capture`, `Forms`, `Events`, and `Delivery` as separate capabilities, not one mandatory bundle.
4. Choose one consent source of truth.
5. Do not inject GTM twice.

In practice, most teams get value from ClickTrail in this order:

1. preserve attribution
2. make it visible at the conversion point
3. add browser event context
4. add server-side delivery only when a destination is ready

## What Each Capability Gives You

## Capture

Use when:

- you want UTMs, click IDs, and referrers to survive past the landing page
- the site has multi-page journeys, repeat visits, or cross-domain handoffs

What teams get:

- first-touch and last-touch attribution stored in first-party client storage
- fallback source classification for organic, social, and referral traffic
- optional cross-domain continuity

## Forms

Use when:

- the main conversions happen through lead forms
- the site uses cached pages or dynamically rendered forms

What teams get:

- attribution attached to supported form submissions
- fallback field population when server-rendered hidden fields are not enough
- form-specific submission storage depending on the provider

## Events

Use when:

- the team wants browser-side behavioral signals in `dataLayer`
- GTM or analytics tooling needs search, scroll, engagement, or lead-gen events

What teams get:

- browser event collection
- canonical event payload generation
- optional REST intake for delivery-ready events

## Delivery

Use when:

- the team already has a collector, sGTM endpoint, or ad-platform delivery target
- operations wants retries, queue visibility, and delivery diagnostics

What teams get:

- server-side dispatch
- queueing and retries
- endpoint health checks
- operational visibility through Logs and Diagnostics

## Common Rollout Patterns

## 1. Lead-Gen Site With Forms Only

Best for:

- agencies
- B2B marketing sites
- service businesses using lead forms as the primary conversion point

Enable:

- `Capture`
- `Forms`

Usually leave off at first:

- `Delivery`, unless a server-side destination already exists

Recommended form pattern:

- Contact Form 7 and Fluent Forms: let ClickTrail add hidden fields automatically
- Gravity Forms and WPForms: add the `ct_*` hidden fields you want stored or exported
- Elementor Forms (Pro): rely on submission hooks and fallback attribution
- Ninja Forms: rely on submission storage, not automatic hidden-field injection

Validation:

1. Visit a page with a test UTM URL.
2. Navigate to another page.
3. Submit a supported form.
4. Confirm attribution appears in the form entry or submission record.

Primary benefit:

- the team can see campaign context where leads are reviewed, not just in analytics tools

## 2. WooCommerce Store

Best for:

- stores that want campaign-aware order context inside WordPress
- teams that need purchase attribution without building a full server-side pipeline first

Enable:

- `Capture`
- WooCommerce integration

Optional next step:

- `Events` for purchase-related browser signals
- `Delivery` for server-side purchase dispatch

Validation:

1. Visit the store with a tagged campaign URL.
2. Browse products and complete a test order.
3. Confirm the order stores attribution.
4. If browser events are enabled, confirm purchase-related signals in GTM preview or `dataLayer`.

Primary benefit:

- orders stop collapsing into "Direct" inside operational workflows

## 3. Multi-Domain Funnel

Best for:

- marketing site -> app
- marketing site -> scheduler
- marketing site -> checkout on another domain or subdomain

Enable:

- `Capture`
- cross-domain continuity only for approved domains

Recommended sequence:

1. configure allowed domains carefully
2. enable link decoration or signed token continuity only where needed
3. verify the receiving domain preserves attribution instead of resetting it

Validation:

1. Enter the first domain with a tagged URL.
2. Follow the real cross-domain journey.
3. Confirm the final form or order still has the original source trail.

Primary benefit:

- attribution survives the funnel instead of restarting at each domain boundary

## 4. Consent-Aware Site With an Existing CMP

Best for:

- sites already using Cookiebot, OneTrust, Complianz, GTM, or a custom CMP flow

Enable:

- `Capture`
- consent mode only if the site needs consent-aware gating

Recommended approach:

1. keep one consent source of truth
2. point ClickTrail at the CMP already used by the site
3. validate both granted and denied flows before launch

Validation:

1. test with consent granted
2. test with consent denied
3. confirm attribution and browser events behave according to the configured mode

Primary benefit:

- attribution, events, and delivery follow the same consent decision instead of drifting apart

## 5. Server-Side Delivery Rollout

Best for:

- teams already operating sGTM, a collector, or downstream ad-platform delivery
- teams that need retries, queueing, and delivery diagnostics

Enable:

- `Delivery`

Only after:

- endpoint URL is ready
- adapter choice is clear
- consent behavior is understood

Recommended rollout:

1. enable delivery in staging first
2. run endpoint health checks
3. validate one successful event path
4. confirm queue retries behave as expected on forced failures
5. then enable in production

Validation:

1. confirm `Diagnostics > Endpoint Test` succeeds
2. send a known test event
3. verify Logs and Diagnostics show the expected result

Primary benefit:

- delivery becomes operationally visible instead of being a black box

## Ownership Model for Teams

Typical ownership split:

- marketing or analytics team: campaign taxonomy, GTM use, reporting expectations
- implementation engineer: setup, field mapping, consent wiring, diagnostics
- operations or support: endpoint health, queue behavior, failure handling

Hand-off checklist:

1. document which capabilities are enabled
2. document which form plugins or commerce paths are in scope
3. document whether GTM is injected by ClickTrail or elsewhere
4. document the consent source of truth
5. document whether server-side delivery is enabled and who owns the endpoint

## Where to Look for Value

If the implementation is working, teams should see value in at least one of these places:

- form entry records contain attribution context
- WooCommerce orders contain attribution context
- `window.dataLayer` receives ClickTrail browser events
- Logs and Diagnostics show server-side delivery activity
- cross-domain journeys stop resetting source attribution

## Common Mistakes to Avoid

- enabling GTM injection when GTM already loads elsewhere
- turning on server-side delivery before the endpoint is ready
- expecting Gravity Forms or WPForms to store attribution without adding the hidden fields you actually want
- treating browser events and server-side delivery as the same toggle
- enabling consent mode without validating the real CMP integration path

## Next Docs by Need

- architecture: [../architecture/PLUGIN-OVERVIEW.md](../architecture/PLUGIN-OVERVIEW.md)
- settings and option mapping: [SETTINGS-AND-ADMIN.md](SETTINGS-AND-ADMIN.md)
- integrations and provider behavior: [../reference/INTEGRATIONS.md](../reference/INTEGRATIONS.md)
- operations and troubleshooting: [OPERATIONS-RUNBOOK.md](OPERATIONS-RUNBOOK.md)
- REST routes and auth model: [../reference/REST-API.md](../reference/REST-API.md)
