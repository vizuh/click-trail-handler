# ClickTrail

![ClickTrail](.github/clicktrail-cover.png)

Attribution usually breaks somewhere between the ad click and the conversion. ClickTrail keeps campaign context alive through cached pages, dynamic forms, cross-domain journeys, repeat visits, and configured consent rules.

ClickTrail keeps the source of the visit, not a profile of the visitor — first-party capture with consent controls. It is a WordPress capture-and-controlled-delivery layer, not an attribution dashboard, hosting platform, lead manager, or ad optimizer. The current security-status blockers and verification boundary are documented in [Security and Privacy](docs/guides/SECURITY-PRIVACY.md).

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

> **Integration verification status (2026-08-19):** The registry and source paths are documented in
> [the integration reference](docs/reference/INTEGRATIONS.md), but PHP/WordPress/provider E2E verification
> was not available for this audit. The platform-named server adapters are currently **source-present /
> runtime-unverified configured-endpoint adapters**. GTM can mediate site-owned platform tags; ClickTrail does
> not inject Meta/Facebook Pixel, Google tag, TikTok Pixel, LinkedIn Insight, Pinterest Tag, or Reddit Pixel
> SDKs. Reddit has a **relay-only** destination toggle and `rdt_cid` capture, not a native delivery adapter.

## What ClickTrail Solves

- Keeps campaign context available for WooCommerce orders when the configured path captures it.
- Persists UTMs and click IDs across the configured attribution journey.
- Provides client-side fallback paths for cached or dynamic forms.
- Provides approved cross-domain continuity paths with documented limitations.
- Consent controls and optional server-side delivery live in one plugin; end-to-end consent/revocation behavior remains under the release gates.

ClickTrail is designed to keep first-touch and last-touch context alive until the point where WordPress actually needs it: WooCommerce orders, form submissions, browser events, and optional downstream delivery.

## Core Capabilities

- **Capture**: first-touch and last-touch attribution, referrers, classic and extended UTMs, click IDs, browser identifiers, retention, and cross-domain continuity.
- **WooCommerce**: order attribution, enriched purchase payloads, thank-you page purchase pushes, optional list-view and cart storefront events, richer Woo `dataLayer` support, and post-purchase milestones.
- **Forms**: automatic hidden-field enrichment for Contact Form 7 and Fluent Forms, compatible hidden-field population for Gravity Forms and WPForms, cached-page fallback, dynamic-content support, and WhatsApp continuity.
- **Events**: browser collection, `dataLayer` pushes, sGTM compatibility mode, webhook intake, lifecycle updates, and optional Woo storefront signals.
- **Delivery**: optional server-side transport, retries, diagnostics, conflict scanning, backup/restore, and a consent gate whose end-to-end edge cases are documented for the next release.

## Releases

The release badge above tracks the current GitHub release. See [changelog.txt](changelog.txt) for the full history and [readme.txt](readme.txt) for the public WordPress.org stable release.

## Documentation By Audience

- **GitHub visitors**: start with [README.en.md](README.en.md) or [README.pt-BR.md](README.pt-BR.md).
- **Contributors and reviewers**: use [CONTRIBUTING.md](CONTRIBUTING.md) or [CONTRIBUTING.pt-BR.md](CONTRIBUTING.pt-BR.md).
- **Engineers and agents**: use [docs/README.md](docs/README.md) and [AGENTS.md](AGENTS.md).
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
