# ClickTrail Technical Documentation

- **Audience**: implementers, contributors, maintainers, support teams, reviewers, and AI agents
- **Canonical for**: engineering navigation, adoption guidance, and source-of-truth lookup
- **Update when**: docs move, ownership changes, or a new subsystem or rollout pattern needs a canonical reference
- **Source baseline**: plugin code `1.9.0`, commit `a45aa9e`
- **Runtime verification**: not completed in the 2026-08-19 audit; PHP/WordPress/provider E2E tooling was unavailable

This is the docs home for GitHub readers. Use it to find the right document by role, task, or rollout goal. Canonical docs live under `docs/architecture`, `docs/guides`, and `docs/reference`.

> **Current verification boundary:** the integration registry proves source wiring, not production provider support. Read the [integration capability ledger](reference/integration-capabilities.json) and [integration reference](reference/INTEGRATIONS.md) before treating any adapter or destination as available. Reddit is relay-only in the current source; GTM-mediated platform tags are not native ClickTrail adapters.

## Start Here

If you are new to the plugin, read these in order:

1. [MASTER-SPECIFICATION.md](MASTER-SPECIFICATION.md): complete product, technical, semantic, safety, business, roadmap, and version source of truth
2. [MASTER-SPECIFICATION-SUMMARY.md](MASTER-SPECIFICATION-SUMMARY.md): compressed executive view
3. [MASTER-SPECIFICATION-CHANGE-RECORD.md](MASTER-SPECIFICATION-CHANGE-RECORD.md): permanent version and specification change record
4. [guides/PHASE-EXECUTION-LEDGER.md](guides/PHASE-EXECUTION-LEDGER.md): evidence-gated M1–M12 implementation and phase-version record
5. [architecture/PLUGIN-OVERVIEW.md](architecture/PLUGIN-OVERVIEW.md): what ClickTrail does, how the runtime is divided, and where value shows up
6. [guides/IMPLEMENTATION-PLAYBOOK.md](guides/IMPLEMENTATION-PLAYBOOK.md): how teams usually roll out Capture, Forms, Events, and Delivery in practice
7. [guides/SETTINGS-AND-ADMIN.md](guides/SETTINGS-AND-ADMIN.md): how the current admin UI maps to stored settings and operational surfaces
8. [guides/USE-CASES.md](guides/USE-CASES.md): choose a rollout pattern by conversion surface
9. [tutorials/README.md](tutorials/README.md): follow a bounded setup tutorial

## Choose Docs by Goal

## I want to deploy the plugin on a real site

- [guides/IMPLEMENTATION-PLAYBOOK.md](guides/IMPLEMENTATION-PLAYBOOK.md): phased rollout patterns for lead-gen, WooCommerce, cross-domain, consent-aware, and server-side setups
- [guides/USE-CASES.md](guides/USE-CASES.md): public use-case matrix with enable, validate, and boundary notes
- [tutorials/README.md](tutorials/README.md): short implementation tutorials for forms, WooCommerce, and consent-aware events
- [guides/SETTINGS-AND-ADMIN.md](guides/SETTINGS-AND-ADMIN.md): current settings IA and option mapping
- [reference/INTEGRATIONS.md](reference/INTEGRATIONS.md): evidence-labelled forms, commerce, consent, webhook, GTM, platform, and delivery integration paths
- [reference/integration-capabilities.json](reference/integration-capabilities.json): machine-readable status, source evidence, smoke IDs, and known limitations
- [guides/COMPETITOR-MAP-2026-08-22.md](guides/COMPETITOR-MAP-2026-08-22.md): five-competitor features, reviews, complaints, discussions, and issue signals
- [guides/COMPETITOR-ROADMAP-2026-08-22.md](guides/COMPETITOR-ROADMAP-2026-08-22.md): public competitor signals, integration patterns, and consulting-first roadmap
- [guides/COMPETITOR-GTM-2026-08-22.md](guides/COMPETITOR-GTM-2026-08-22.md): public acquisition, activation, agency, and partner motions
- [guides/COMPETITIVE-POSITIONING-AND-ACQUISITION-ROADMAP-2026-08-22.md](guides/COMPETITIVE-POSITIONING-AND-ACQUISITION-ROADMAP-2026-08-22.md): proposition, README copy, twelve-month commercial overlay, and client-acquisition plan
- [RELEASE-PHASING-AND-INTEGRATION-DOCS.md](guides/RELEASE-PHASING-AND-INTEGRATION-DOCS.md): separately gated documentation, privacy, delivery, adapter, and reach releases

## I want to understand the runtime architecture

- [architecture/PLUGIN-OVERVIEW.md](architecture/PLUGIN-OVERVIEW.md): plugin scope, bootstrap flow, capability model, and active vs compatibility paths
- [architecture/EVENT-PIPELINE.md](architecture/EVENT-PIPELINE.md): browser, form, webhook, lifecycle, WooCommerce, and delivery flow
- [architecture/DATA-MODEL.md](architecture/DATA-MODEL.md): options, tables, cookies, transients, cron hooks, and persistence surfaces
- [architecture/CODE-MAP.md](architecture/CODE-MAP.md): active file layout and compatibility leftovers
- `.understand-anything/knowledge-graph.json`: machine-readable knowledge graph of the codebase (nodes, edges, architectural layers, guided tour) — open with `/understand-dashboard`

## I want to change admin UI, settings, or UX behavior

- [guides/SETTINGS-AND-ADMIN.md](guides/SETTINGS-AND-ADMIN.md): admin IA, tab responsibilities, option stores, and compatibility URLs
- [guides/FEATURE-TEST-MATRIX.md](guides/FEATURE-TEST-MATRIX.md): smoke coverage map for admin, WooCommerce, delivery, and diagnostics capabilities
- [guides/CODE-QUALITY.md](guides/CODE-QUALITY.md): maintenance hotspots and known cleanup risks

## I want to benchmark ClickTrail against another tracking plugin

- [reference/INTEGRATIONS.md](reference/INTEGRATIONS.md): current evidence-labelled capability boundaries and adapter roles
- [guides/RELEASE-PHASING-AND-INTEGRATION-DOCS.md](guides/RELEASE-PHASING-AND-INTEGRATION-DOCS.md): release gates before making comparative or reach claims

## I want to extend routes, hooks, or integrations

- [reference/REST-API.md](reference/REST-API.md): active routes, auth model, and diagnostics endpoints
- [reference/HOOKS-REFERENCE.md](reference/HOOKS-REFERENCE.md): public actions and filters
- [reference/INTEGRATIONS.md](reference/INTEGRATIONS.md): current evidence-labelled integration model and implementation notes
- [reference/integration-capabilities.json](reference/integration-capabilities.json): capability/evidence ledger used before public claims
- [reference/FEATURE-REGISTRY.md](reference/FEATURE-REGISTRY.md): internal capability and destination registry used by admin, dispatcher, docs, and smoke coverage

## I need to operate, debug, or support a live install

- [guides/OPERATIONS-RUNBOOK.md](guides/OPERATIONS-RUNBOOK.md): queue behavior, endpoint tests, diagnostics, and common failure patterns
- [guides/SECURITY-PRIVACY.md](guides/SECURITY-PRIVACY.md): consent, token auth, replay protection, privacy boundaries, and secret handling
- [guides/CONSENT-DECISION-V1.md](guides/CONSENT-DECISION-V1.md): unreferenced M7-A decision vocabulary, precedence, and conformance boundary
- [architecture/DATA-MODEL.md](architecture/DATA-MODEL.md): storage surfaces and persisted state

## I want to reuse the model in another project

- [guides/TRACKING-ATTRIBUTION-PORTABLE-PROMPT.md](guides/TRACKING-ATTRIBUTION-PORTABLE-PROMPT.md): copy-paste prompt for porting the tracking, attribution, privacy, and settings model into another codebase

## Choose Docs by Role

- implementation engineer: start with [guides/IMPLEMENTATION-PLAYBOOK.md](guides/IMPLEMENTATION-PLAYBOOK.md)
- solution architect or technical PM: start with [architecture/PLUGIN-OVERVIEW.md](architecture/PLUGIN-OVERVIEW.md)
- support or operations: start with [guides/OPERATIONS-RUNBOOK.md](guides/OPERATIONS-RUNBOOK.md)
- contributor or reviewer: start with [architecture/CODE-MAP.md](architecture/CODE-MAP.md) and [../CONTRIBUTING.md](../CONTRIBUTING.md)

## Prepare a Pull Request

- [../CONTRIBUTING.md](../CONTRIBUTING.md): contributor workflow and docs update matrix
- [../CONTRIBUTING.pt-BR.md](../CONTRIBUTING.pt-BR.md): contributor workflow in Brazilian Portuguese
- [../AGENTS.md](../AGENTS.md): repo-neutral agent guidance
- [../.github/PULL_REQUEST_TEMPLATE.md](../.github/PULL_REQUEST_TEMPLATE.md): PR checklist

## Source of Truth Rules

- Product passport, scope, functional requirements, semantic contract, safety gates, business unknowns, 12-month roadmap, and specification history belong in the [master specification set](MASTER-SPECIFICATION.md), with the summary and change record kept alongside it.
- Product positioning belongs in the repo and WordPress readmes, not here.
- Rollout guidance belongs in `guides/IMPLEMENTATION-PLAYBOOK.md`.
- Admin truth belongs in `guides/SETTINGS-AND-ADMIN.md`.
- API truth belongs in `reference/REST-API.md`.
- Storage truth belongs in `architecture/DATA-MODEL.md`.
- Integration truth belongs in `reference/INTEGRATIONS.md` and its machine-readable evidence ledger.
- Public integration claims must not outrun the ledger's runtime status or the release gates in the phasing plan.
