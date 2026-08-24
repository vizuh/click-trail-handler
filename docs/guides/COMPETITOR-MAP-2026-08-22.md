# ClickTrail competitor map

Snapshot: **2026-08-22, Europe/Lisbon**
Status: **public-surface research; not a market-share ranking, product-quality verdict, or release approval**

## Executive read

ClickTrail sits at the boundary between a WordPress site and the form, order,
event, or delivery system that needs attribution. The five products below are
its most useful comparison set because they cover the main substitute decisions:

1. **[HandL UTM Grabber](https://wordpress.org/plugins/handl-utm-grabber/)** — the
   closest direct WordPress attribution competitor;
2. **[Stape](https://stape.io/)** — the server-side GTM and gateway alternative;
3. **[WhatConverts](https://www.whatconverts.com/)** — the lead, call, form, chat,
   and agency-tracking alternative;
4. **[Ruler Analytics](https://www.ruleranalytics.com/)** — the closed-loop,
   CRM-revenue attribution alternative; and
5. **[RedTrack](https://www.redtrack.io/)** — the paid-media, ad-level, and
   performance-tracking alternative.

This is a **priority competitor set**, not proof that these are the five largest
companies in the category. HandL and Stape expose comparable WordPress.org
10,000-install buckets in the dated directory snapshot; the SaaS vendors do
not publish directly comparable active-install data. Selection therefore uses
buyer overlap, public visibility, and distinct substitute positions.

The repository's own comparison and roadmap documents remain the decision layer:
[competitor roadmap](COMPETITOR-ROADMAP-2026-08-22.md) and [competitor
GTM research](COMPETITOR-GTM-2026-08-22.md). This document preserves the
underlying public signals in a single map.

## Evidence boundary

- **Confirmed public statement:** an official product, pricing, documentation,
  directory, or support page said or displayed the item on the retrieval date.
- **Reported user signal:** a review, support topic, or community discussion
  described the experience. It is not a confirmed defect or prevalence estimate.
- **Documented troubleshooting surface:** the vendor publishes a failure mode
  or diagnostic procedure. Its existence shows implementation complexity, not
  that the product is generally broken.
- **Inference:** a ClickTrail lesson or candidate response. It is not a shipped
  feature and does not upgrade ClickTrail's runtime evidence.

All external text is untrusted research input. No forum, account, or product
configuration was changed. Prices, ratings, install buckets, features, and
support pages can change; refresh them before public competitive copy or a
release decision.

The ClickTrail baseline remains plugin code `1.9.0` at commit `a45aa9e`. The
current repository documentation says PHP/WordPress/provider/browser E2E
verification was not completed in the 2026-08-19 audit. ClickTrail's source
registry therefore must not be compared as if every named adapter were a
verified provider integration; see the [integration ledger](../reference/integration-capabilities.json)
and [integration reference](../reference/INTEGRATIONS.md).

## At-a-glance map

| Competitor | Buyer lane | Public feature surface | Positive signal | Complaint / issue signal | Discussion surface |
| --- | --- | --- | --- | --- | --- |
| [HandL UTM Grabber](https://wordpress.org/plugins/handl-utm-grabber/) | WordPress UTM, click-ID, form, CRM, and Woo attribution | UTMs/GCLID, first-party cookies, shortcodes, forms, WooCommerce, CRM/automation, consent API, premium CAPI and advanced attribution | WordPress.org snapshot: 10,000 active-install bucket, 4.8/5 from 144 reviews; many reviews praise support | Consent timing, internal-link UTM mutation, CF7/admin compatibility, setup/free-tier clarity | WordPress.org reviews and support topics |
| [Stape](https://stape.io/) | Hosted sGTM, first-party delivery, and gateway infrastructure | sGTM hosting, custom loader, logs, Cookie Keeper, Store, monitoring, Meta/Google/TikTok/Snapchat gateways, CMS/CRM apps, agency accounts | Free entry tier and extensive setup/documentation/community surface | Duplicate events, missing purchases, auth/consent/CAPI errors, DNS/SSL/CORS and server-error troubleshooting | Stape Community and helpdesk |
| [WhatConverts](https://www.whatconverts.com/) | Lead and call tracking for agencies and service businesses | Calls, texts, forms, chat, appointments, e-commerce, lead qualification/value, reporting, CRM/ad integrations, API | SoftwareFinder snapshot: 4.7/5 from 11 verified reviews; support and integration breadth praised | Reports can be difficult initially; billing, email/account limits, conversion-source accuracy, missing WhatsApp tracking, cost-detail requests | Help Center setup/support/DNS guides |
| [Ruler Analytics](https://www.ruleranalytics.com/) | Closed-loop marketing, CRM revenue, call/form/chat attribution | First-party tracking, forms/calls/chat, offline revenue, CRM/BI, multi-touch/data-driven/impression attribution, MMM, budget scenarios, AI | Aggregated reviews praise onboarding, CRM/revenue attribution, and online/offline tracking | Slow or dated UI, limited dashboard/reporting functions, access mediated by account manager, data discrepancies from tagging/time zones/blockers/refunds | Help Center FAQs, setup guides, data-discrepancy article |
| [RedTrack](https://www.redtrack.io/) | Paid-media, affiliate, e-commerce, ad-level attribution, and optimization | Tracking links, conversion API, ad-spend sync, rules, Ads Manager, AI Copilot, customer journey/cohorts, e-commerce/CRM/affiliate integrations | SaaSworthy snapshot: 3.5/5 from 24 aggregated ratings; reviews praise attribution depth and responsive support | Price/panel concerns, app refresh bugs, timezone friction, click/conversion/cost mismatches, duplicate/status complexity | Help Center, Telegram, Discord, roadmap, system status |

The positive and negative columns are signals to investigate, not scores that
establish a winner.

## 1. HandL UTM Grabber — direct WordPress substitute

### Product and features — confirmed public statements

The [WordPress.org product page](https://wordpress.org/plugins/handl-utm-grabber/)
presents HandL as a WordPress attribution plugin. Its public surface includes:

- core UTM fields and `gclid`, first-party cookie storage, referrer and landing
  page context, and shortcodes;
- form and WooCommerce enrichment, with CRM/automation routing through named
  integrations and Zapier/Make;
- compatibility claims for Contact Form 7, Gravity Forms, Ninja Forms,
  Elementor, WPForms, Formidable Forms, Fluent Forms, Forminator, and others;
- consent handling through WP Consent API and compatible consent managers; and
- a paid V3 boundary that the page associates with Facebook CAPI workflows,
  extra click IDs, first/last-touch fields, configurable retention,
  cross-domain/iframe passing, server/client tracking, S2S postbacks, and
  expanded integrations.

These are public product statements, not ClickTrail's verification of HandL's
runtime behavior.

### Reviews and complaints — reported signals

The [WordPress.org review index](https://wordpress.org/support/plugin/handl-utm-grabber/reviews/)
showed **144 reviews** and an average of **4.8/5** at retrieval, including 137
five-star, one four-star, one three-star, one two-star, and four one-star
reviews. Positive reviews repeatedly praise the plugin and especially the
maintainer's response time, for example [Great plugin, outstanding
support](https://wordpress.org/support/topic/great-plugin-outstanding-support-65/).

The low or critical sample included these distinct signals:

| Signal | What the public report said | Interpretation |
| --- | --- | --- |
| Consent timing | A [consent thread](https://wordpress.org/support/topic/suppressing-cookies-prior-to-consent/) reported attribution cookies appearing before marketing consent. The thread was later marked resolved after a WP Consent API update and enabling the relevant HandL setting. | A real implementation/debugging path, not an unresolved general defect. Discoverability of the setting and cache/client timing still matter. |
| Internal-link mutation | A [review thread](https://wordpress.org/support/topic/this-plug-in-breaks-tracking/) objected that appending UTMs to internal CTAs can start new analytics sessions. | The risk is scope/configuration of link decoration; it is not evidence that every HandL installation breaks analytics. |
| Setup and free/premium boundary | A [“doesn’t work out of the box” report](https://wordpress.org/support/topic/doesnt-work-out-of-the-box-13/) described unclear documentation and an Elementor feature unavailable in the free version; the author replied that only some forms were supported in that version. | Activation expectations and an exact compatibility matrix are part of the product. |
| Update compatibility | A [CF7 update report](https://wordpress.org/support/topic/plugin-update-v-2-9-3-is-breaking-contact-form-7-plugin/) described an update interfering with CF7 administration. | Version-specific compatibility needs a reproducible fixture and a release note, not a blanket conclusion. |

### Issues and discussions — public surface

HandL's public issue surface is unusually visible because the WordPress.org
review and support forums expose both praise and failure reports. The most
relevant recurring test prompts are consent before capture, cached-page form
fields, internal-link decoration, builder/admin compatibility, and the free-to-
paid feature boundary.

### ClickTrail implication — inference

Match the direct competitor's install-to-proof path, but make the boundary
explicit: capture, persistence, form/order extraction, and delivery should each
have a named expected payload and a safe diagnostic. Do not copy broad link
mutation or describe a consent toggle as proof of end-to-end privacy without
running ClickTrail's own denied-consent and delayed-dispatch checks.

## 2. Stape — server-side GTM and gateway substitute

### Product and features — confirmed public statements

Stape's [home page](https://stape.io/) and [documentation index](https://stape.io/helpdesk/documentation)
position it as hosted server-side tracking rather than a WordPress attribution
record. The public feature surface includes:

- server-side Google Tag Manager hosting, custom domains/loaders, CDN delivery,
  logs, monitoring, anonymization, Cookie Keeper, bot/ad-blocker information,
  and Stape Store;
- dedicated gateways for Meta CAPI, Google Analytics/Ads, TikTok Events API,
  Snapchat, and related destinations;
- CMS applications including WordPress, Shopify, Magento, BigCommerce, Wix,
  PrestaShop, Shopware, and others, plus CRM applications;
- setup assistants, tracking checkers, templates, APIs, and developer tooling;
  and
- agency accounts that manage client subaccounts, billing, team access, and a
  partner path. The [agency guide](https://stape.io/helpdesk/documentation/agency-account-overview)
  states that containers and gateways live on client subaccounts rather than
  the agency account itself.

The [pricing page](https://stape.io/price) showed a free tier up to 10,000
requests and paid request-volume tiers at retrieval. These limits are volatile
commercial facts, not a ClickTrail cost benchmark.

### Reviews and complaints — reported or unavailable signals

The adjacent [Stape WordPress.org record](https://wordpress.org/plugins/gtm-server-side/)
showed a 10,000 active-install bucket and four reviews in the dated directory
snapshot. A separate [SaaSworthy listing](https://www.saasworthy.com/product/stape-io/reviews)
said “Be The First One To Review”; [CrowdReviews](https://www.crowdreviews.com/stape)
showed zero reviews. G2 and Trustpilot pages were not usable in this bounded
retrieval (anti-bot or robots restrictions), which is a research limitation,
not a negative product finding.

### Issues and discussions — documented and reported signals

The [Stape Community](https://community.stape.io/) exposes implementation
questions rather than a controlled defect database. Examples retrieved in the
snapshot include:

- [sGTM receiving the same event twice or
  trice](https://community.stape.io/t/sgtm-receiving-the-same-event-twice-or-trice/3298)
  and [Duplicated Events](https://community.stape.io/t/duplicated-events/3318);
- [Shopify Grow sandboxed checkout purchase not
  appearing](https://community.stape.io/t/shopify-grow-sandboxed-checkout-purchase-in-pixel-datalayer-but-not/3451);
- [Data Manager missing auth token](https://community.stape.io/t/data-manager-missing-auth-token-error/3501);
- [Stape Data Client client-ID generation and
  consent](https://community.stape.io/t/stapes-data-client-client-id-generation-and-consent/4086);
- [Meta deduplication not working on
  pageviews](https://community.stape.io/t/meta-deduplication-not-working-on-pageviews/3620); and
- [AddToCart tracking while Purchase and Checkout are
  missing](https://community.stape.io/t/addtocart-tracks-but-purchase-checkout-missing/3490).

Stape's own troubleshooting index lists hosting failures involving request
receipt, custom-subdomain verification, CORS/CSP, 400/403/404/500 responses,
Cloudflare, SSL, DNS, and browser blocking in [Hosting Errors
Troubleshooting](https://stape.io/helpdesk/documentation/sgtm/hosting-errors).
The [Meta CAPIG errors guide](https://stape.io/helpdesk/documentation/gateways/capig-errors)
separately documents claim/account errors, invalid credentials, missing event
names, and SSL cipher errors. These pages prove the operational failure modes
are anticipated; they do not prove prevalence.

### ClickTrail implication — inference

Stape demonstrates the value of a clear diagnostic and setup-assistant motion,
not a reason for ClickTrail to become a hosting company. ClickTrail should show
safe event identity, consent state, queue/retry state, and redacted delivery
status before making any server-side recovery claim. Browser/server
deduplication and cross-domain ownership must be tested together.

## 3. WhatConverts — lead and agency tracking substitute

### Product and features — confirmed public statements

WhatConverts' [product page](https://www.whatconverts.com/) and [integration
introduction](https://www.whatconverts.com/help/docs/getting-started/explore-whatconverts-products/introduction-to-integrations/)
show a broad lead-tracking workflow:

- dynamic number insertion and call/text tracking;
- form, chat, appointment, e-commerce, and transaction tracking;
- lead search, qualification, value/sales fields, export, notifications, and
  real-time/custom reporting;
- Google Ads, Meta, CRMs, schedulers, forms, chat tools, and a vendor-claimed
  catalogue of more than 1,000 integrations; and
- API access, agency/master-account structures, a WordPress/GTM setup path,
  system-status page, and a 14-day free trial. The [public pricing page](https://www.whatconverts.com/pricing/)
  showed single-account plans from $30/month for Call Tracking through
  $160/month for Elite, plus agency tiers, at retrieval.

Its core distinction from ClickTrail is the hosted lead manager and call
tracking layer. ClickTrail remains a WordPress capture/delivery layer, not a
replacement for that whole SaaS dashboard.

### Reviews and complaints — reported signals

[SoftwareFinder](https://softwarefinder.com/marketing-software/whatconverts/reviews)
showed **4.7/5 from 11 verified reviews** in the snapshot: 73% five-star and
27% four-star, with displayed satisfaction scores of 8 for ease of use, 10 for
value, 9 for support, and 8 for functionality. The positive pattern was
integration breadth, responsive support, lead reporting, and a smooth WordPress
setup.

The same review sample reported these limitations:

- reports were initially difficult to understand;
- the billing system could be improved;
- agency white-label setup was constrained by a one-email-per-agency-account
  limitation;
- WhatsApp Business conversation tracking was requested;
- one reviewer reported difficulty tracking conversions and their source or
  keyword despite speaking with sales; and
- reviewers requested more detailed conversion-cost information and described
  the powerful feature set as somewhat difficult to learn.

[CrowdReviews](https://www.crowdreviews.com/whatconverts) showed 4.55/5 from
11 reviews but labels the vendor profile unverified and reported no negative
reviews. That is a corroborating public signal, not an independent prevalence
study.

### Issues and discussions — documented surface

WhatConverts does not expose a comparable open support forum in the retrieved
surface; its [Technical Support guide](https://www.whatconverts.com/help/docs/whatconverts-admin/technical-support/)
and help-center navigation instead make setup and troubleshooting first-class
workflows. Relevant public procedures include:

- [Troubleshooting DNS for white-label instances](https://www.whatconverts.com/help/docs/whatconverts-admin/troubleshooting-dns-configuration-for-white-label-instances/),
  which exposes the operational burden of agency domains and DNS;
- [simulating Google Ads clicks to test tracking numbers](https://www.whatconverts.com/help/docs/integrations/google-ads-integration/simulate-google-ads-clicks-to-test-tracking-numbers/);
  and
- tracking-code, WordPress, GTM, call-number, form, chat, appointment, and
  transaction setup articles visible in the help-center index.

### ClickTrail implication — inference

WhatConverts validates the buyer value of a verified lead with source and value,
not an account merely being configured. ClickTrail can borrow the evidence-led
activation and agency handoff pattern while keeping scope narrow: a synthetic
click-to-form/order trace, exact submitted/stored/dispatched values, and a
client-safe rollback note.

## 4. Ruler Analytics — closed-loop revenue attribution substitute

### Product and features — confirmed public statements

Ruler's [home page](https://www.ruleranalytics.com/) and [integrations page](https://www.ruleranalytics.com/integrations/)
position a unified marketing-measurement platform with:

- first-party website, form, call, and live-chat tracking;
- offline conversion and CRM revenue matching;
- first-click, last-click, linear, position-based, data-driven, impression,
  and multi-touch attribution claims;
- BI/data-warehouse destinations, ad-platform integrations, WooCommerce and
  other commerce sources, and a vendor-claimed 1,500+ integrations;
- marketing mix modelling, revenue analytics, predictive budget scenarios, and
  an AI analyst/media-planner surface on the current marketing page.

The [pricing page](https://www.ruleranalytics.com/pricing/) showed traffic-based
plans starting at £299/month for up to 10,000 monthly visits, with higher bands
for 50,000, 100,000, and above 100,000 visits. The page calls these indicative
prices scaled by traffic, product, data, and integration requirements.

### Reviews and complaints — reported signals

The [SaaSworthy review listing](https://www.saasworthy.com/product/ruler-analytics/reviews)
aggregated **5/5 from two ratings**, both marked as sourced from G2 in the
listing. The small sample praised lead/revenue detail, CRM integrations,
marketing ROI visibility, implementation, and support. It also reported:

- websites or the platform sometimes hanging or loading slowly;
- a dashboard/UI that could be improved or felt dated;
- account access rights being directed through an account manager;
- limited dashboard comparisons across time periods;
- reporting often being sent to a CRM or Google Data Studio for a more complete
  view; and
- a request for easier Google/Facebook Ads integration for ROI calculation.

[TrustRadius](https://www.trustradius.com/products/ruler-analytics/reviews)
showed no review rows in this retrieval but displayed synthesized insights about
proactive onboarding support, improved attribution, and online/offline tracking.
Because the page itself says the insights may use third-party data, treat them
as directional rather than a score.

### Issues and discussions — documented surface

Ruler's public [Data Discrepancies guide](https://help.ruleranalytics.com/en/articles/9639449-data-discrepancies)
explicitly lists the causes operators should check:

- UTC versus internal time zones;
- currency/exchange-rate differences;
- missing tags or untracked conversion points;
- cookies and JavaScript blockers;
- off-site changes such as cancellations, returns, and refunds not updating
  automatically; and
- offline leads that were never captured by the Ruler setup and therefore remain
  unmatched.

The setup guides also show implementation dependencies. The [WordPress guide](https://help.ruleranalytics.com/en/articles/1930251-how-to-implement-ruler-analytics-on-a-wordpress-cms-platform)
requires a footer JavaScript installation and wrapping phone numbers for dynamic
number insertion; the [tracking test](https://help.ruleranalytics.com/en/articles/5496450-how-to-test-my-ruler-analytics-tracking-is-setup-correctly-on-my-website)
uses a debug bookmark to highlight detected forms and phone numbers. These are
useful diagnostics, but they also make missing tags and incomplete setup easy
failure modes.

### ClickTrail implication — inference

Ruler shows the demand for closed-loop revenue context, but also why a small
WordPress layer must state the exact capture boundary. ClickTrail should not
claim a revenue model or provider integration from a source path alone. It
should prove the site hook, consent snapshot, order/form value, event ID, and
post-dispatch result first; richer attribution models remain a separate product
decision.

## 5. RedTrack — performance and ad-level tracking substitute

### Product and features — confirmed public statements

RedTrack's [platform page](https://www.redtrack.io/) and [integration overview](https://www.redtrack.io/integrations/overview/)
position it between ad spend and revenue. The public surface includes:

- ad-level tracking and attribution through tracking links and first-party
  cookies;
- server-to-server Conversion API delivery to major ad platforms;
- ad-spend/cost synchronisation, campaign rules, smart distribution, Ads
  Manager, and multi-account/team operation;
- AI Copilot dashboards, customer journeys, cohorts, product/e-commerce
  reporting, and attribution modelling; and
- affiliate-network, e-commerce, CRM, call-tracker, GTM, and BI connections,
  including a documented [WooCommerce integration](https://www.redtrack.io/integrations/woocommerce/).

The [pricing page](https://www.redtrack.io/pricing/) showed a 14-day trial and
multiple event/revenue-volume plans. It also displayed a $69/month Builder
entry point at retrieval; plan contents and pricing are volatile and are not
used as a market-size claim.

### Reviews and complaints — reported signals

The [SaaSworthy listing](https://www.saasworthy.com/product/redtrack-io/reviews)
aggregated **3.5/5 from 24 ratings**, with 58.3% marked excellent and 33.3%
marked terrible. The listing combines material sourced from G2 and Trustpilot,
and the direct G2/Capterra/Trustpilot pages were not all independently
retrievable in this bounded pass. Treat the aggregation as directional.

Positive reports praised conversion/link attribution, affiliate tracking,
multivariate reporting, ad-cost synchronisation, server-to-server tracking,
responsive support, and onboarding. Reported limitations included:

- pricing being expensive or increasing over time;
- some panels needing improvement;
- a buggy mobile-app refresh flow that required logout/login;
- timezone differences during setup;
- a request for manual data correction; and
- the general complexity of configuring S2S conversion tracking.

### Issues and discussions — documented surface

RedTrack's home page links to a [Help Center](https://help.redtrack.io/knowledgebase/kb/),
[Telegram group](https://t.me/redtrack), [Discord server](https://discord.gg/TucpPrUB2),
[public roadmap](https://roadmap.redtrack.io/), and [system status](https://status.redtrack.dev/status/general/).
The help center makes the following failure classes explicit:

| Public issue guide | Failure mode exposed |
| --- | --- |
| [Clicks inconsistency](https://help.redtrack.io/knowledgebase/kb/conversion-tracking/clicks-inconsistency/) | Traffic-source versus RedTrack click totals, no clicks, or attribution to the wrong campaign; checks include parameters, scripts, time zones, bots, filters, redirects, and tracking domains. |
| [Conversions inconsistency](https://help.redtrack.io/knowledgebase/kb/conversion-tracking/conversion-inconsistency-in-redtrack/) | Wrong dynamic parameters, expired integrations, custom-domain/SSL problems, caps, duplicate-postback modes, server issues, time zones, and setup mistakes; it also explains that ad-platform attribution windows and cross-device behavior can make a 100% match impossible. |
| [Conversion types, statuses, duplicates, and roles](https://help.redtrack.io/knowledgebase/kb/conversion-tracking/tracking-event-types/) | Case-sensitive event names, API restrictions on statuses, duplicate postback modes, and role assignment that affects purchases, revenue, ROAS, cohorts, and product reports. |
| [Cost/ad-spend troubleshooting](https://help.redtrack.io/knowledgebase/kb/cost-tracking/cost-ad-spend-troubleshoot/) | Missing or incorrect costs caused by disconnected accounts, missing IDs, wrong parameter roles, cost model, time zones, currencies, reconnects, or mismatched click counts. |
| [GTM advertiser setup](https://help.redtrack.io/knowledgebase/kb/automation/e-com-integrations/gtm-setup-for-advertiser-tracking/) | Advanced HTML/JavaScript/GTM work is explicitly described as outside RedTrack Support and requires an existing domain, cookie variable, universal script, conversion script, and correctly timed trigger. |

### ClickTrail implication — inference

RedTrack is a reminder that event names, identities, deduplication, cost/value,
time zones, and delivery status form one contract. ClickTrail should keep those
fields explicit and observable, but should not copy a paid-media optimization
surface before its own WordPress form/order evidence is reliable.

## Cross-competitor complaint and issue themes

| Theme | Public evidence | Safe ClickTrail response |
| --- | --- | --- |
| Consent and cookie timing | HandL consent thread; Stape consent/client-ID discussions; Ruler cookie/blocker documentation | Capture, persistence, queueing, and dispatch each need the same explicit consent decision. Test denial before and after a delayed send. |
| Cache, AJAX, and dynamic boundaries | HandL form/cache complaints; Ruler's tag/debug procedure; WhatConverts setup guides | Test cached pages, injected forms, hidden fields, validation failure, and successful submission with expected payloads. |
| Server/browser deduplication | Stape duplicate-event topics; RedTrack duplicate postback/event-role documentation | Use a canonical event ID and prove one intentional event across reload, retry, browser, and server paths. |
| Attribution discrepancies | Ruler time-zone/tag/refund guidance; RedTrack click/conversion/cost guides; WhatConverts source/keyword review signal | Expose source, timestamp, currency, value, consent snapshot, and correlation ID; never promise perfect cross-platform agreement. |
| Domain, SSL, and infrastructure setup | Stape hosting/CAPIG errors; WhatConverts white-label DNS; RedTrack custom tracking-domain checks | Treat endpoint, DNS, SSL, and credential checks as explicit setup states; diagnostics must not reveal secrets. |
| Compatibility and version drift | HandL CF7/admin report; Ruler WordPress footer/number wrapping; RedTrack advanced GTM setup | Maintain a version matrix and a focused fixture for every claimed adapter or recipe. |
| Agency scale and support | WhatConverts agency/account limits; Stape subaccount model; Ruler onboarding/account-manager signals; RedTrack team/community paths | Sell an evidence-backed implementation handoff before promising agency resale, SLA, or broad connector coverage. |

## What this map does not prove

- A review count is not active usage, market share, or product reliability.
- A support topic is not a reproduced defect or a prevalence estimate.
- A vendor troubleshooting article is not an admission that the product is
  generally broken.
- A feature page is not proof that an integration works in ClickTrail's or the
  vendor's specific runtime configuration.
- A blocked review site is not a negative review.
- No ClickTrail roadmap item is promoted merely because a competitor advertises
  it. Current candidates and exit proofs remain in the [competitor roadmap](COMPETITOR-ROADMAP-2026-08-22.md).

## Source register

### ClickTrail and selection context

- Local cross-repository directory snapshot: `www/products/FunnelSheet/funnelsheet-site/docs/content/clicktrail-research/2026-08-22/WORDPRESS-ORG-COMPETITOR-MAP.md` *(detailed 30-plugin sample)*
- [ClickTrail integration ledger](../reference/integration-capabilities.json)
- [ClickTrail integration reference](../reference/INTEGRATIONS.md)
- [ClickTrail competitor roadmap](COMPETITOR-ROADMAP-2026-08-22.md)
- [ClickTrail competitor GTM research](COMPETITOR-GTM-2026-08-22.md)

### HandL

- [Product page](https://wordpress.org/plugins/handl-utm-grabber/)
- [Reviews](https://wordpress.org/support/plugin/handl-utm-grabber/reviews/)
- [Support forum](https://wordpress.org/support/plugin/handl-utm-grabber/)
- [Consent thread](https://wordpress.org/support/topic/suppressing-cookies-prior-to-consent/)

### Stape

- [Product page](https://stape.io/)
- [Pricing](https://stape.io/price)
- [Documentation](https://stape.io/helpdesk/documentation)
- [Agency account guide](https://stape.io/helpdesk/documentation/agency-account-overview)
- [Community](https://community.stape.io/)
- [WordPress.org server-side plugin](https://wordpress.org/plugins/gtm-server-side/)

### WhatConverts

- [Product page](https://www.whatconverts.com/)
- [Pricing](https://www.whatconverts.com/pricing/)
- [Integrations](https://www.whatconverts.com/help/docs/getting-started/explore-whatconverts-products/introduction-to-integrations/)
- [SoftwareFinder reviews](https://softwarefinder.com/marketing-software/whatconverts/reviews)
- [Technical support](https://www.whatconverts.com/help/docs/whatconverts-admin/technical-support/)

### Ruler Analytics

- [Product page](https://www.ruleranalytics.com/)
- [Pricing](https://www.ruleranalytics.com/pricing/)
- [Integrations](https://www.ruleranalytics.com/integrations/)
- [SaaSworthy review aggregation](https://www.saasworthy.com/product/ruler-analytics/reviews)
- [TrustRadius review surface](https://www.trustradius.com/products/ruler-analytics/reviews)
- [Data discrepancies](https://help.ruleranalytics.com/en/articles/9639449-data-discrepancies)
- [WordPress setup](https://help.ruleranalytics.com/en/articles/1930251-how-to-implement-ruler-analytics-on-a-wordpress-cms-platform)

### RedTrack

- [Product page](https://www.redtrack.io/)
- [Pricing](https://www.redtrack.io/pricing/)
- [Integrations](https://www.redtrack.io/integrations/overview/)
- [SaaSworthy review aggregation](https://www.saasworthy.com/product/redtrack-io/reviews)
- [Help Center](https://help.redtrack.io/knowledgebase/kb/)
- [Telegram group](https://t.me/redtrack)
- [Discord server](https://discord.gg/TucpPrUB2)

Retrieval date for this map: **2026-08-22**. Refresh public pages before
reusing any figure, review count, price, or feature statement.
