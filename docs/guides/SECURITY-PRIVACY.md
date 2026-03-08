# Security and Privacy

- **Audience**: contributors, maintainers, reviewers, and security-focused integrators
- **Canonical for**: consent behavior, token handling, replay protection, and secret treatment
- **Update when**: consent flow, auth, signing, secret storage, or privacy behavior changes
- **Last verified against version**: `1.3.5`

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

## Data Minimization

Current design goals:

- preserve attribution fields needed for marketing and reporting
- avoid storing unnecessary identity data by default
- omit identity data when consent logic requires it
- keep remote failure telemetry aggregated and payload-free

Identity exposure is additionally filterable through:

- `clicutcl_identity_fields_allowed`

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

## Webhook Security

Webhook providers use signed request verification.

Controls include:

- provider enablement
- provider secret resolution
- replay-window enforcement
- optional replay protection filter control

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
