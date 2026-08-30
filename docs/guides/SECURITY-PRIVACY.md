# Security and Privacy

- **Audience**: contributors, maintainers, reviewers, and security-focused integrators
- **Canonical for**: consent behavior, token handling, replay protection, and secret treatment
- **Update when**: consent flow, auth, signing, secret storage, or privacy behavior changes
- **Historical audit baseline**: plugin code `1.9.0`, commit `a45aa9e`, reviewed 2026-08-19
- **Current release**: plugin code `1.10.0`, with automated consent, queue, WooCommerce privacy, and evidence-contract coverage
- **Runtime verification**: PHP/WordPress/browser/CMP/provider E2E remains incomplete; live behavior is not implied by automated fixtures

ClickTrail is designed to capture attribution and events without treating privacy and delivery as separate concerns.
This document separates policy intent, automated contract coverage, and verified runtime behavior. Version 1.10.0
closes the queued-retry consent check and WooCommerce order-meta lifecycle implementation boundaries; browser,
WordPress, CMP, provider, and staging evidence remain release gates. See the [release-phasing plan](RELEASE-PHASING-AND-INTEGRATION-DOCS.md)
and [integration evidence ledger](../reference/integration-capabilities.json).

## Current security-status blockers

These are not claims that the behavior is fixed. They are release gates for the next runtime changes:

- The browser bridge stores a versioned canonical decision in `localStorage`, mirrors it to
  `ct_consent_state` with its authority timestamp, and treats legacy plugin-cookie and plugin-banner values
  as fallbacks only. A canonical withdrawal clears the plugin cookie and cannot be resurrected by a stale copy
  on reload.
- Same-tab listeners receive `ct:consentResolved`; other tabs receive the canonical decision through the
  browser `storage` event. Browser/CMP and queue/retry coverage below remains a focused VM boundary test,
  not a full browser or WordPress E2E proof.
- Form/Woo posted attribution and purchase dataLayer output still need live consent/revocation tests; queued retries now have a current-consent contract test and implementation guard.
- Woo traces can retain identity metadata; ClickTrail now covers its Woo order-meta lifecycle through an explicit allowlist and automated contract tests, subject to live Woo runtime verification.
- Browser conversion tokens and external form messages do not prove a real action or provider confirmation.
- Webhook identity/timestamp/replay semantics and sGTM preview SSRF still require hardening.

Until these gates pass in PHP/WordPress/browser/provider tests, use `source-present / runtime-unverified`,
not `privacy compliant`, `secure`, `guaranteed`, or `production-ready`.

## Consent Model

Consent settings are managed through `clicutcl_consent_mode`.

Supported behavior modes:

- `strict`
- `relaxed`
- `geo`

Current source behavior (audit status):

- server snapshot capture and `Dispatcher` share one policy requirement helper; when consent mode is disabled, both treat consent as not required rather than reading the legacy hidden gate
- when consent mode is enabled, the runtime asks `Consent_Mode_Settings` whether the current request requires consent
- new Woo orders store a v1 decision snapshot; historical boolean order snapshots remain readable and are explicitly labeled `legacy_unversioned`
- frontend attribution consumes the consent bridge as its primary runtime contract, but stale-cookie/CMP precedence
  and cross-tab synchronization remain runtime test requirements
- consent resolution is normalized through `ct:consentResolved`, with compatibility events still emitted for older listeners
- when consent resolves to denied, local attribution storage is cleared; prior `dataLayer` entries, durable rows,
  order metadata, queued deliveries, and third-party deliveries are not automatically erased
- most browser event paths check consent before pushing tracked events, but follow-up logger, form, and Woo paths
  require independent verification
- initial server-side dispatch checks consent; queue retries currently require a separate pre-send consent check

## Consent Sources

Supported consent sources:

- `auto`
- `plugin`
- `cookiebot`
- `onetrust`
- `complianz`
- `gtm`
- `custom`

When the plugin is the source, ClickTrail can render its own lightweight consent banner.

## Geo Consent Resolution

Region-scoped consent (`geo` mode) must resolve the request's country. Client-supplied
geo headers (`CF-IPCOUNTRY`, `X-Country-Code`, `GeoIP-*`) are spoofable unless a trusted
edge sets them, so they are **not trusted by default** — an unknown country fails safe to
requiring consent.

Resolution order:

1. `clicutcl_request_country_code` filter — authoritative server-resolved country (recommended; e.g. a GeoIP provider). Return a 2-letter ISO code.
2. Request headers — only when `clicutcl_trust_geo_request_headers` is filtered to `true` (opt in when behind a trusted CDN that overwrites these headers).
3. Otherwise unknown → consent required.

## Data Minimization

Current design goals:

- preserve attribution fields needed for marketing and reporting
- avoid storing unnecessary identity data by default
- omit identity data when consent logic requires it
- keep remote failure telemetry aggregated and payload-free
- anonymize the visitor IP at rest in the diagnostic events log via `wp_privacy_anonymize_ip()`; some WooCommerce
  trace snapshots can still copy raw IP/user-agent identity data and require separate retention/erasure handling

Identity exposure is additionally filterable through:

- `clicutcl_identity_fields_allowed`

The personal-data eraser currently targets matching rows from the events table, the structured touch
events table (`clicutcl_touch_events`, matched on the exact hashed-email `visitor_id`), and the server-side
delivery queue (`clicutcl_queue`, matched on raw and SHA-256-hashed email). This is not complete erasure proof:
legacy event matching does not cover every stored hashed identity shape. Woo order metadata now has an
allowlisted export/erase/uninstall lifecycle, but live Woo runtime verification remains open.

## Client Token Security

Browser event intake uses signed client tokens.

Relevant controls:

- token TTL
- token nonce replay limit
- allowed token hosts
- optional subdomain token acceptance

Browser event intake also enforces:

- request size limits
- rate limits
- nonce replay controls

## Cross-Domain Attribution Tokens

Routes:

- `/clicutcl/v2/attribution-token/sign`
- `/clicutcl/v2/attribution-token/verify`

Security properties:

- signed payload
- allowed-host checks
- subdomain acceptance is filterable
- attribution payload is normalized before use
- both `/sign` and `/verify` require the page-embedded signed client token (verify is only meaningful on the install that signed the token)

## Webhook Security

Webhook providers use signed request verification.

Controls include:

- provider enablement
- provider secret resolution
- replay-window enforcement
- optional replay protection filter control

Verification hardening:

- Typeform uses its native base64 HMAC `Typeform-Signature`
- HubSpot uses its native SHA-256 `X-HubSpot-Signature`
- Calendly currently retains ClickTrail's timestamped HMAC contract until its native signing format is verified;
  the adapter's raw identity, provider timestamp, and downstream retention behavior still require review
- every signature is compared on the raw value with constant-time `hash_equals`
- provider secrets are stored verbatim (not truncated or whitespace-stripped), so long/base64/structured secrets verify correctly
- replay protection uses an atomic `wp_cache_add()` claim where a persistent object cache exists, falling back to a durable DB transient

Supported providers:

- Calendly
- HubSpot
- Typeform

## Queue, Revocation, and Retention Boundary

The initial dispatcher consent decision is not the same as a complete lifecycle privacy guarantee. Version 1.10.0
adds the implementation and automated contract coverage for the queue retry consent check and the allowlisted
WooCommerce order-meta lifecycle. Live runtime evidence is still required to prove that:

- consent withdrawal blocks queued sends immediately before adapter invocation in a WordPress installation;
- browser dataLayer history, pending capture, ClickTrail tables, Woo order metadata, debug buffers, and provider
  deliveries have an explicit documented behavior;
- retention is independent of the attribution cookie duration and cleanup continues when one table is unavailable;
- purge/export/erase/uninstall cover every ClickTrail-owned storage key in a live installation; expired Woo metadata
  is processed in bounded cleanup batches.

## Trusted Proxies and Request Identity

Trusted proxy handling matters for:

- rate limiting
- diagnostics
- request-source normalization

Relevant filters:

- `clicutcl_v2_trusted_proxies`
- `clicutcl_trusted_proxies`

## Secret Storage

Advanced provider and lifecycle secrets are stored in `clicutcl_tracking_v2`.

Admin-facing behavior:

- secrets are masked before being returned to the UI
- blank or masked values preserve the existing secret
- explicit clear semantics are supported internally

Optional hardening:

- `encrypt_secrets_at_rest`
- `clicutcl_encrypt_settings_secrets`

When `encrypt_secrets_at_rest` is enabled but the server lacks OpenSSL AES-256-GCM, the
toggle is inert (secrets stay plaintext); an admin notice surfaces this so it does not
fail silently.

**Settings backup export contains unmasked secrets.** The "Export Backup" action (Settings
> Diagnostics) reads secrets via the decrypted/unmasked accessor, not the masked one used by
the admin UI, so the downloaded JSON contains the Calendly/HubSpot/Typeform webhook secrets
and the CRM lifecycle token in cleartext — even when `encrypt_secrets_at_rest` is on. The
action is capability- and nonce-gated, so this is not an access-control issue, but the
downloaded file itself should be handled like a credentials file: store it in a secrets
manager or encrypted storage, never commit it to a repository, and avoid sending it over
unencrypted channels. The admin UI carries an on-screen warning to this effect as of 1.8.14.

## Environment Safeguards

Server-side dispatch is blocked by default in:

- `local`
- `development`

Override hook:

- `clicutcl_dispatch_in_environment`

This helps prevent accidental dispatches against live platforms from cloned development environments.

## Diagnostics Privacy Posture

Always-on delivery telemetry stores:

- aggregated hourly failure counts

It does not store:

- full request payloads
- raw personal data

Debug windows can temporarily increase visibility for troubleshooting, but production behavior is intentionally limited.

### Form-readiness comparator boundary

The M5 presence comparator is redacted by construction. It accepts field-name
and presence snapshots, not attribution or submission values. Its report may
contain only:

- a closed adapter and pattern ID
- allowlisted `ct_*` field names derived from the attribution field mapping
- named evidence states for provider record, hook payload, and ClickTrail event
- closed reason/status enums, booleans, and counts

It must not accept or return raw attribution values, click IDs, browser or
visitor identifiers, identity fields, full submissions, landing/referrer URLs,
secrets, or provider records. Extra request properties are ignored and never
echoed. No live lookup or new persistence surface exists in the first slice.

### Woo-readiness comparator boundary

The M6-A comparator is also redacted by construction. Its inputs are closed
synthetic enums. Its output is limited to fixture IDs, placeholder event-name
forms, contract status/reason enums, bounded integers such as `dedup_ttl_days`,
constant value-basis labels, and counts. Unknown properties are ignored
and invalid enum values fail closed.

It must not accept or return real order/refund/customer IDs, identities,
attribution values, IP addresses, user agents, purchase payloads, URLs, or
secrets. It performs no WordPress/WooCommerce lookup and creates no storage.

The contract records the remaining privacy-lifecycle verification work:
identity-bearing canonical Woo trace snapshots and Diagnostics surfaces have an
allowlisted erase, purge, export, and uninstall path, but live Woo runtime verification remains open.
Runtime work remains blocked until that boundary is verified.
