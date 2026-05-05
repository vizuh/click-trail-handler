# ClickTrail

Keep campaign context attached to WooCommerce orders, WordPress forms, and event flows across cached pages, dynamic forms, cross-domain journeys, repeat visits, and consent-aware sites.

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

## What ClickTrail Solves

- WooCommerce orders lose campaign context before revenue is reported.
- UTMs and click IDs disappear after the landing page.
- Cached or dynamic forms submit without attribution.
- Cross-domain flows reset the source trail.
- Teams need consent-aware capture and optional server-side delivery in one plugin.

ClickTrail is designed to keep first-touch and last-touch context alive until the point where WordPress actually needs it: WooCommerce orders, form submissions, browser events, and optional downstream delivery.

## Core Capabilities

- **Capture**: first-touch and last-touch attribution, referrers, classic and extended UTMs, click IDs, browser identifiers, retention, and cross-domain continuity.
- **WooCommerce**: order attribution, enriched purchase payloads, thank-you page purchase pushes, optional list-view and cart storefront events, richer Woo `dataLayer` support, and post-purchase milestones.
- **Forms**: automatic hidden-field enrichment for Contact Form 7 and Fluent Forms, compatible hidden-field population for Gravity Forms and WPForms, cached-page fallback, dynamic-content support, and WhatsApp continuity.
- **Events**: browser collection, `dataLayer` pushes, sGTM compatibility mode, webhook intake, lifecycle updates, and optional Woo storefront signals.
- **Delivery**: optional server-side transport, retries, diagnostics, conflict scanning, backup/restore, and consent-aware dispatch.

## Latest Release: 1.7.0

- GF helper classes committed (Gf_Channel_Resolver, Gf_Form_Settings_Tab, Gf_Merge_Tags, Gf_Minification_Protector)
- Admin QA cookie priority fix; `wp_logout` now clears it immediately
- `lt_channel` server-side fallback added alongside `ft_channel`
- Channel classifier extended: `dclid` → Display & Video 360, `epik` → Pinterest Ads, `sccid` → Snapchat Ads
- `visitor_id` + `session_id` persisted to GF entry meta and WooCommerce order meta
- Legacy code removed: dead v1 API controller, URL alias remapping, `enable_consent_banner`, `log_whatsapp_clicks()` stub
- Full release notes: [changelog.txt](changelog.txt)

## Documentation By Audience

- **GitHub visitors**: start with [README.en.md](README.en.md) or [README.pt-BR.md](README.pt-BR.md).
- **Contributors and reviewers**: use [CONTRIBUTING.md](CONTRIBUTING.md) or [CONTRIBUTING.pt-BR.md](CONTRIBUTING.pt-BR.md).
- **Engineers and agents**: use [docs/README.md](docs/README.md) and [AGENTS.md](AGENTS.md).

## Repository Map

- [docs/README.md](docs/README.md): engineering index by task and subsystem
- [docs/guides/IMPLEMENTATION-PLAYBOOK.md](docs/guides/IMPLEMENTATION-PLAYBOOK.md): practical rollout guide for lead-gen, WooCommerce, cross-domain, consent-aware, and server-side setups
- [docs/architecture/PLUGIN-OVERVIEW.md](docs/architecture/PLUGIN-OVERVIEW.md): runtime architecture and module map
- [docs/reference/INTEGRATIONS.md](docs/reference/INTEGRATIONS.md): forms, commerce, consent, webhook, and delivery integrations
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
