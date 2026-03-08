# ClickTrail

Keep attribution attached to forms, WooCommerce orders, and event flows across cached pages, dynamic forms, cross-domain journeys, and consent-aware sites.

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

- UTMs and click IDs disappear after the landing page.
- Cached or dynamic forms submit without attribution.
- WooCommerce orders lose campaign context.
- Cross-domain flows reset the source trail.
- Teams need consent-aware capture and optional server-side delivery in one plugin.

## Core Capabilities

- **Capture**: first-touch and last-touch attribution, referrers, classic and extended UTMs, click IDs, browser identifiers, retention, and cross-domain continuity.
- **Forms**: supported form enrichment, cached-page fallback, dynamic-content support, and WhatsApp continuity.
- **Events**: browser collection, `dataLayer` pushes, webhook intake, and lifecycle updates.
- **Delivery**: optional server-side transport, retries, diagnostics, and consent-aware dispatch.

## Documentation By Audience

- **GitHub visitors**: start with [README.en.md](README.en.md) or [README.pt-BR.md](README.pt-BR.md).
- **Contributors and reviewers**: use [CONTRIBUTING.md](CONTRIBUTING.md) or [CONTRIBUTING.pt-BR.md](CONTRIBUTING.pt-BR.md).
- **Engineers and agents**: use [docs/README.md](docs/README.md) and [AGENTS.md](AGENTS.md).

## Repository Map

- [docs/README.md](docs/README.md): engineering index by task and subsystem
- [docs/architecture/PLUGIN-OVERVIEW.md](docs/architecture/PLUGIN-OVERVIEW.md): runtime architecture and module map
- [docs/reference/INTEGRATIONS.md](docs/reference/INTEGRATIONS.md): forms, commerce, consent, webhook, and delivery integrations
- [docs/guides/SETTINGS-AND-ADMIN.md](docs/guides/SETTINGS-AND-ADMIN.md): current admin IA and option mapping
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
