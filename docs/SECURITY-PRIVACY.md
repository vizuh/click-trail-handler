# Security and Privacy

## Security Model Summary

The plugin treats event ingestion as a public web surface and applies request authenticity checks for v2 event intake.

Core controls:

- signed client token for `/v2/events/batch`
- token host and blog binding checks
- optional nonce replay limit
- webhook HMAC + timestamp verification
- trusted-proxy-aware IP resolution for rate limiting
- rate limiting with per-scope transients
- dedup keys to reduce replay and duplicate writes
- admin AJAX endpoints gated by capability + nonce checks

## v2 Batch Token Auth

Implemented in:

- `includes/tracking/class-auth.php`
- `includes/api/class-tracking-controller.php`

Token claims include:

- `v`
- `iat`
- `exp`
- `site`
- `host`
- `blog`
- `nonce`

Verification checks:

- HMAC signature valid
- not expired
- host allowed by same-host/subdomain/allowlist policy
- blog id matches current site

## Webhook Auth

Implemented in:

- `includes/tracking/class-webhook-auth.php`

Requirements:

- provider secret configured
- `x-clicutcl-timestamp`
- `x-clicutcl-signature`
- signature = `hex(hmac_sha256("timestamp.raw_body", secret))`

Replay protection:

- timestamp drift bounded (default 300s, filterable)
- replay key transient enforcement enabled by default

## Proxy and IP Trust

Controllers use `REMOTE_ADDR` by default.

Forwarded headers are trusted only if `REMOTE_ADDR` matches configured trusted proxy CIDR/IP:

- v2: from tracking settings security trusted proxies + filters
- legacy v1 class: from `clicutcl_trusted_proxies` filter

## Rate Limiting

v2 defaults:

- `60 requests / 60 seconds` per scope+IP

Additional optional limiter:

- token nonce hit cap (`security.token_nonce_limit`, default 0 disabled)

Legacy v1 class defaults (if used):

- `30 requests / 60 seconds`
- target-level and token nonce limits for WA flow

## Consent and Identity

Consent signals:

- cookie `ct_consent`
- server-side consent checks via `CLICUTCL\Server_Side\Consent`

Identity resolver defaults:

- mode `consent_gated_minimal`
- hashes email and phone only when marketing consent allows
- does not persist raw PII in canonical event logs by default path
- optional IP/UA inclusion requires explicit context flag

## Diagnostic Data Guardrails

Debug logs:

- gated by transient debug window
- stored in bounded ring buffers
- includes normalized v2 intake debug ring buffer (admin-only diagnostics use)

Always-on telemetry:

- failure counts only
- no payload bodies
- no raw identity payload

Remote telemetry:

- opt-in only
- action-based extensibility

## Admin Action Protection

Admin-side AJAX actions in `includes/admin/class-admin.php` use:

- `current_user_can( 'manage_options' )`
- `check_ajax_referer(...)`

This applies to diagnostics, debug toggle, tracking v2 settings save/load, and data purge actions.

## Data Minimization Notes

Attribution payload allowlisting is applied at several boundaries:

- canonical event normalization
- legacy attribution subset sanitizer (v1 class)
- settings sanitizers with allowlist+merge patterns
- client-side normalization/sanitization before attribution persistence and form field injection

Cookies and local storage are first-party and used for attribution/identity continuity.

## Legacy Surface Note

`includes/api/class-log-controller.php` contains additional auth and ingestion logic for v1 WA routes. In current bootstrap, this controller is not registered by default, but the class remains in the repository.

Legacy v1 class loading is additionally disabled by default behind:

- `CLICUTCL_ENABLE_LEGACY_V1_API` (default `false`)
