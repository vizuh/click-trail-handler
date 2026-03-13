# ClickTrail

Keep attribution attached to forms, WooCommerce orders, and event flows across cached pages, dynamic forms, cross-domain journeys, repeat visits, and consent-aware sites.

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

ClickTrail is designed to keep first-touch and last-touch context alive until the point where WordPress actually needs it: form submissions, WooCommerce orders, browser events, and optional downstream delivery.

## Core Capabilities

- **Capture**: first-touch and last-touch attribution, referrers, classic and extended UTMs, click IDs, browser identifiers, retention, and cross-domain continuity.
- **Forms**: automatic hidden-field enrichment for Contact Form 7 and Fluent Forms, compatible hidden-field population for Gravity Forms and WPForms, cached-page fallback, dynamic-content support, and WhatsApp continuity.
- **Events**: browser collection, `dataLayer` pushes, webhook intake, and lifecycle updates.
- **Delivery**: optional server-side transport, retries, diagnostics, and consent-aware dispatch.

## Latest Release: 1.3.9

This release is mainly about making ClickTrail easier to trust on production sites.

- WordPress privacy export and erasure flows now match personal data more carefully, so cleanup requests are less likely to over-match unrelated event rows.
- Large privacy erasure jobs now delete events in batches instead of one row at a time, which reduces database overhead on larger sites.
- Frequently read settings are cached, the consent bridge only loads when the page actually needs it, and the bootstrap fallback no longer re-checks the same file paths every request.
- Debugging is clearer when something breaks: invalid attribution-token payloads and privacy-erasure database failures now leave better clues in debug mode.
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
