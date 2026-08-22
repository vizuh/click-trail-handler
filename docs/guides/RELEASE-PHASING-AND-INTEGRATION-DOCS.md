# ClickTrail Release Phasing and Integration Documentation
## Plan v1 · 2026-08-19

This plan converts the static security/privacy audit into separately gated releases. It is a
planning and documentation artifact; it does not change runtime behavior.

## Current evidence boundary

- Source baseline: plugin code `1.9.0`, commit `a45aa9e`.
- PHP, WordPress, provider-account, browser/CMP, and end-to-end delivery tests were not available in
the audit environment.
- `config/feature-registry.json` proves internal wiring and smoke ownership. It does not prove that a
provider accepts a payload, that credentials are correct, or that consent/retry/purge behavior is safe.
- The machine-readable documentation ledger is
  [`../reference/integration-capabilities.json`](../reference/integration-capabilities.json).
- Reddit has a relay-only destination toggle and `rdt_cid` capture, but no native delivery adapter was
observed. GTM can mediate provider tags; ClickTrail does not inject platform pixel SDKs.

Public documentation must use these statuses:

- **Source-present / runtime-unverified** — code and registry path exist; live behavior is not proven.
- **GTM-mediated** — ClickTrail exposes browser/dataLayer signals; the site owns provider tag setup.
- **Relay-only** — a destination marker/toggle exists, but no native ClickTrail delivery adapter exists.
- **Webhook ingress** — provider data enters ClickTrail; this is not outbound provider delivery.
- **Not observed / planned / unsupported** — never describe as available.

## Release lanes

### L0 — Truth containment and documentation parity

**Scope:** capability ledger, integration matrix/cards, README/readme status corrections, security-status
notice, release metadata reconciliation, and a claims/evidence checklist.

**Allowed:** documentation and documentation QA only. No PHP, JS, runtime configuration, schema, or
provider expansion changes.

**Gate:** every public provider claim links to a ledger ID, source path, verification date, and explicit
runtime status. No `privacy compliant`, `secure`, `guaranteed`, `reliable`, `all platforms`, or native
Reddit claim without passing evidence. Unsupported and unverified states must be visible in the first
screenful of the page.

### L1 — Consent and data-boundary foundation

**Scope:** one authoritative consent service; remove the stale `require_consent` split; independently gate
form/Woo ingress and browser dataLayer output; recheck consent before queue send; reconcile Woo order-meta
export/erase/uninstall; make purge and retention complete; minimize stored identity/URL data.

**Required evidence:** PHP unit tests plus live WordPress/browser tests for grant, deny, stale cookie,
revocation, cross-tab state, queued retry after withdrawal, Woo checkout/thank-you, every form adapter,
and data erasure drills.

**No provider expansion belongs in this release.**

### L2 — Delivery and conversion integrity

**Scope:** action-bound browser conversion events; confirmed booking/purchase semantics; webhook identity
hashing and provider timestamp/replay contracts; bounded timestamps/fields; atomic deduplication;
transport retry/error behavior; sGTM preview SSRF hardening; destination-specific payload minimization.

**Required evidence:** negative-path fixtures for denial, replay, malformed provider responses, timeout,
429/5xx, duplicate events, purge, and private-network URLs, plus staged provider smoke tests.

### L3 — Provider contract releases

One provider or transport per release. A provider can move from `source-present / runtime-unverified`
to public **runtime-contract-tested** only after a versioned fixture proves the canonical event mapping,
authentication, consent denial, redaction, retry, idempotency, and provider response handling.

The current platform-named adapter classes serialize canonical JSON to configured endpoints; they should
not be marketed as turnkey native APIs until their provider contracts are implemented and tested. Reddit
remains relay-only until a native adapter is deliberately implemented and independently tested.

### L4 — Reach and visibility

Only after L1–L3 evidence passes for the relevant path:

- publish searchable provider pages and setup snippets;
- add provider-specific screenshots/templates;
- publish reach/visibility claims tied to the exact tested event set;
- measure qualified documentation visits, setup completion, and support incidents—not unverified conversion
promises.

## Release kill criteria

Do not publish a public integration claim or production-ready release if any advertised path can:

- send or enqueue data after consent withdrawal or bypass the authoritative consent source;
- retry without a consent check, mislabel booking/purchase success, or create an unbound conversion;
- retain identity/order data outside documented purge/export/erase scope;
- accept replayed or unbounded provider events;
- reach a private/internal URL through sGTM preview;
- lack runtime evidence beyond registry/source presence; or
- appear as supported/native after a qualifier-stripping search snippet or copied setup excerpt.

## Next artifact

Finish the L0 documentation patch by synchronizing `../reference/INTEGRATIONS.md`, the three product
readmes, `readme.txt`, `SECURITY-PRIVACY.md`, and `changelog.txt` against the ledger, then run
`npm run smoke` and a documentation link/JSON validation pass. This patch must show no executable runtime
changes.
