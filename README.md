# ClickTrail

![ClickTrail](.github/clicktrail-cover.png)

**Keep the campaign attached to the conversion.**

Someone clicks your ad. Three pages later, they send a form or place an order. By then the campaign is usually gone, and the lead looks like it came from nowhere.

ClickTrail keeps what the visitor arrived with: the UTMs, the ad click ID, the referrer. It carries that context to the form entry or the WooCommerce order, and to a Calendly booking. You open the record and see where it came from.

ClickTrail records what arrived. It does not decide what caused the sale.

[![GitHub release](https://img.shields.io/github/v/release/vizuh/click-trail-handler?label=version&color=blue)](https://github.com/vizuh/click-trail-handler/releases)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-blue)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/WordPress-6.5%2B-blue)](https://wordpress.org/)
[![GitHub stars](https://img.shields.io/github/stars/vizuh/click-trail-handler?style=social)](https://github.com/vizuh/click-trail-handler/stargazers)

<p>
  <img src="assets/vizuh-logo.png" alt="Vizuh logo" width="120">
</p>

[Read in English](README.en.md)  
[Leia em Portugues (Brasil)](README.pt-BR.md)  
[Contributor Guide](CONTRIBUTING.md)  
[Guia de Contribuicao](CONTRIBUTING.pt-BR.md)  
[Technical Docs](docs/README.md)  
[WordPress Readme](readme.txt)

## Start here

Pick the path that matches your site.

- **WooCommerce store**: turn on Capture and WooCommerce. Orders carry the campaign. Add storefront events later if you want funnel signals.
- **Lead forms**: turn on Capture and Forms. Contact Form 7 and Fluent Forms get hidden fields on their own. Gravity Forms and WPForms fill the `ct_*` fields you add.
- **GTM or server-side GTM**: turn on Capture, Events, and Delivery. Events go to the `dataLayer`, and ClickTrail can send canonical JSON to an endpoint you set.

Then place one test order or send one test form, and look at what was stored. The [use-case guide](docs/guides/USE-CASES.md) and [tutorials](docs/tutorials/README.md) walk through each path.

## What it does

ClickTrail only acts on the paths you turn on and set up in its settings. That is what "configured" means everywhere in these docs.

- Capture: first-touch and last-touch UTMs, ad click IDs, and the referrer, with organic, social, and referral fallback when there are no UTMs. When a visitor comes back through a new ad, the newest click is the one that travels. Context lives in first-party storage, falls back to `sessionStorage` when cookies are blocked, and your own admin browsing stays out of it.
- WooCommerce: attribution saved on the order, an enriched purchase event on the thank-you page, optional storefront events (`view_item`, `view_item_list`, `view_cart`, `add_to_cart`, `remove_from_cart`, `begin_checkout`), and optional order-status milestones. Order metadata has export, erase, retention, and uninstall handling.
- Forms: Contact Form 7 and Fluent Forms get hidden fields automatically. Gravity Forms and WPForms fill the `ct_*` fields you add; Gravity Forms also gets a channel label, nine `{clicutcl_*}` merge tags, and a per-form switch. Elementor Forms (Pro) and Ninja Forms keep attribution with the submission record. A client-side fallback covers cached pages and forms that load late, and WhatsApp links can carry the context too.
- Bookings and your other domains: links to domains you approve carry the campaign, with a signed token between your own sites. Calendly booking links hold the newest click and the visitor's consent decision, and the signed Calendly webhook records the booking as `book_appointment` with its UTMs.
- Events and delivery: browser events to the `dataLayer`, a REST intake, webhooks from Calendly, HubSpot, and Typeform, lifecycle updates, and one-time WordPress events such as `login`, `sign_up`, and `comment_submit`. Optional server-side delivery adds a retry queue and diagnostics.

## Consent and privacy

When consent is required, nothing is stored until the visitor agrees. ClickTrail listens to its own banner, Cookiebot, OneTrust, Complianz, GTM, or a custom source, or detects the source for you. ClickTrail follows Cookiebot and Complianz decisions, including changes the visitor makes on the page. On withdrawal, ClickTrail clears its storage and removes the parameters it added to links. Queued deliveries check current consent again right before they are sent, and the consent decision stays in sync across tabs. Consent behavior can be strict, relaxed, or geo-based. ClickTrail helps you implement consent; compliance still depends on your legal requirements and your settings.

## What it does not do

- It does not prove which click caused a sale, and it does not decide which channel deserves the revenue.
- It does not resolve one person's identity across devices.
- It is not an attribution dashboard, a hosted server-side GTM platform, a lead manager, or an ad optimizer. It works alongside GA4 and GTM. For call tracking, lead scoring, or ad-spend optimization, pair it with a specialist tool.
- It does not install ad pixels. The Meta Pixel, Google tag, TikTok Pixel, LinkedIn Insight Tag, Pinterest Tag, and Reddit Pixel stay yours, usually through GTM.
- Server-side delivery sends JSON to the endpoint you configure. The adapters named after Meta, Google Ads / GA4, LinkedIn, Pinterest, and TikTok exist in the code, but we have not verified that any provider accepts them end to end. Reddit is a relay-only destination.
- We have not yet verified a live Calendly booking round trip.

Having code for an integration is not proof that it works in production. Current status lives in the [integration reference](docs/reference/INTEGRATIONS.md) and the [machine-readable ledger](docs/reference/integration-capabilities.json). The [release-phasing plan](docs/guides/RELEASE-PHASING-AND-INTEGRATION-DOCS.md) shows what gets checked next.

The current release is 1.11.0 on GitHub and WordPress.org. The full reference (click IDs, integrations, installation) lives in [README.en.md](README.en.md); history is in the [changelog](changelog.txt).

## Documentation By Audience

- **GitHub visitors**: start with [README.en.md](README.en.md) or [README.pt-BR.md](README.pt-BR.md).
- **Contributors and reviewers**: use [CONTRIBUTING.md](CONTRIBUTING.md) or [CONTRIBUTING.pt-BR.md](CONTRIBUTING.pt-BR.md).
- **Engineers and agents**: use [docs/README.md](docs/README.md).
- **Implementation teams**: start with [use cases](docs/guides/USE-CASES.md) and the [tutorial index](docs/tutorials/README.md).

## Repository Map

- [docs/README.md](docs/README.md): engineering index by task and subsystem
- [docs/guides/IMPLEMENTATION-PLAYBOOK.md](docs/guides/IMPLEMENTATION-PLAYBOOK.md): practical rollout guide for lead-gen, WooCommerce, cross-domain, consent-aware, and server-side setups
- [docs/architecture/PLUGIN-OVERVIEW.md](docs/architecture/PLUGIN-OVERVIEW.md): runtime architecture and module map
- [docs/reference/INTEGRATIONS.md](docs/reference/INTEGRATIONS.md): evidence-labelled forms, commerce, consent, webhook, GTM, platform, and delivery paths
- [docs/reference/integration-capabilities.json](docs/reference/integration-capabilities.json): machine-readable integration status and evidence ledger
- [docs/guides/RELEASE-PHASING-AND-INTEGRATION-DOCS.md](docs/guides/RELEASE-PHASING-AND-INTEGRATION-DOCS.md): separately gated release plan
- [docs/guides/COMPETITIVE-POSITIONING-AND-ACQUISITION-ROADMAP-2026-08-22.md](docs/guides/COMPETITIVE-POSITIONING-AND-ACQUISITION-ROADMAP-2026-08-22.md): proposition, copy, and client-acquisition roadmap
- [docs/guides/SETTINGS-AND-ADMIN.md](docs/guides/SETTINGS-AND-ADMIN.md): current admin IA and option mapping
- [changelog.txt](changelog.txt): full plain-English release history aligned with the WordPress readme
- [.github/PULL_REQUEST_TEMPLATE.md](.github/PULL_REQUEST_TEMPLATE.md): PR checklist for repo changes

## Contributor Quick Start

1. Read [CONTRIBUTING.md](CONTRIBUTING.md).
2. Use [docs/README.md](docs/README.md) to find the canonical doc for the area you will change.
3. Keep product docs, technical docs, and changelog entries aligned with the implementation.

## Requirements

- WordPress 6.5+
- PHP 8.1+

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
