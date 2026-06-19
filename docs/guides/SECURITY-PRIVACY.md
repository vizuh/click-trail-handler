# Security and Privacy

- **Audience**: contributors, maintainers, reviewers, and security-focused integrators
- **Canonical for**: consent behavior, token handling, replay protection, and secret treatment
- **Update when**: consent flow, auth, signing, secret storage, or privacy behavior changes
- **Last verified against version**: `1.8.10`

ClickTrail is designed to capture attribution and events without treating privacy and delivery as separate concerns.

## Consent Model

Consent settings are managed through `clicutcl_consent_mode`.

Supported behavior modes:

- `strict`
- `relaxed`
- `geo`

Runtime behavior:

- if consent mode is disabled, attribution uses the legacy required-consent fallback logic
- if consent mode is enabled, the runtime asks `Consent_Mode_Settings` whether the current request requires consent
- frontend attribution now consumes the consent bridge as its primary runtime contract instead of hardcoding the legacy plugin cookie path
- consent resolution is normalized through `ct:consentResolved`, with compatibility events still emitted for older listeners
- when consent resolves to denied, client-side attribution storage is cleared so previously captured values are not reused
- browser event collection checks consent before pushing tracked events
- server-side dispatch checks consent before sending events

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
- anonymize the visitor IP at rest in the diagnostic events log via `wp_privacy_anonymize_ip()`; the full IP is used only transiently for server-side delivery (CAPI match quality)

Identity exposure is additionally filterable through:

- `clicutcl_identity_fields_allowed`

The personal-data eraser removes matching rows from the events table **and** the
server-side delivery queue (`clicutcl_queue`, matched on raw and SHA-256-hashed email).

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

- the signature header is compared raw (not sanitized) and validated as 64 lowercase hex chars before the constant-time `hash_equals`
- provider secrets are stored verbatim (not truncated or whitespace-stripped), so long/base64/structured secrets verify correctly
- replay protection uses an atomic `wp_cache_add()` claim where a persistent object cache exists, falling back to a durable DB transient

Supported providers:

- Calendly
- HubSpot
- Typeform

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
