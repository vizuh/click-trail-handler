# ClickTrail

Keep campaign context attached to WooCommerce orders, WordPress forms, and event flows across cached pages, dynamic forms, cross-domain journeys, repeat visits, and consent-aware sites.

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
- **WooCommerce**: order attribution, enriched purchase payloads, thank-you page purchase pushes, and optional opt-in storefront events including cart removal.
- **Forms**: automatic hidden-field enrichment for Contact Form 7 and Fluent Forms, compatible hidden-field population for Gravity Forms and WPForms, cached-page fallback, dynamic-content support, and WhatsApp continuity.
- **Events**: browser collection, `dataLayer` pushes, webhook intake, lifecycle updates, and optional Woo storefront signals.
- **Delivery**: optional server-side transport, retries, diagnostics, and consent-aware dispatch.

## Latest Release: 1.4.0

This release makes ClickTrail more WooCommerce-focused without changing the broader plugin architecture.

- WooCommerce HPOS compatibility is declared during bootstrap.
- Purchase payloads now carry richer commerce fields such as subtotal, tax, shipping, discounts, coupon codes, item quantity, SKU, variant, and categories.
- Woo storefront events can be enabled explicitly for `view_item`, `add_to_cart`, `remove_from_cart`, and `begin_checkout`.
- The Events tab now explains Woo order attribution, purchase pushes, storefront events, and where to verify them.
- The release also includes the recent WordPress.org deployment cleanup and privacy/query hardening work.
- Full release notes are available in [changelog.txt](changelog.txt) and the public WordPress listing in [readme.txt](readme.txt).

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
