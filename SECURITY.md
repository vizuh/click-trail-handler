# Security policy

ClickTrail handles attribution identifiers, consent state, form submissions,
and optional server-side delivery configuration.

## Report a vulnerability

Please use a private [GitHub Security Advisory](https://github.com/vizuh/click-trail-handler/security/advisories/new).
Do not disclose an unpatched vulnerability in a public issue.

Include the affected version, WordPress/PHP versions, reproduction steps,
impact, and any safe mitigation. Do not include real visitor data or secrets.

## Development boundary

- Nonces and capability checks are required for privileged admin/AJAX paths.
- Sanitize on input and escape on output; preserve secrets exactly once stored.
- Consent state gates browser persistence and server-side identity enrichment.
- Do not commit credentials, customer data, generated release ZIPs, or `vendor/`.
- Composer, GitHub Actions, CodeQL, OSV, and Scorecard checks are release gates.
