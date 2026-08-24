# Consent Decision v1 Contract

**State:** M7-B Woo snapshot writer/reader wired; browser, queue/retry, privacy,
and release gates remain open.

`CLICUTCL\Consent\Decision_V1` records decision, policy basis, revision,
capture/expiry timestamps, and a bounded source identifier. `not_required` is a
policy basis, not a fabricated grant. `allows_processing()` returns true only
for a grant or when consent is not required.

`CLICUTCL\Consent\Resolver_V1` applies this precedence:

1. typed administrative override;
2. live CMP;
3. plugin-banner decision;
4. unexpired bridge mirror;
5. unresolved timeout or no evidence.

A newer withdrawal overrides an older grant. Same-revision disagreement denies.
Invalid or expired highest-authority evidence fails closed instead of silently
falling through. Bridge mirrors require an expiry.

M7-B stores a v1 snapshot on new Woo orders and normalizes historical boolean
snapshots on read. Historical state is explicitly labeled
`legacy_unversioned`; `not_required` remains a policy basis rather than a
fabricated subject grant. Dispatcher policy now uses the same server-side
requirement helper as snapshot capture. This slice does not refactor browser
cookies/CMP precedence, gate the top-level purchase `dataLayer`, change retry
rows, or claim compliance.

The fixture contract lives in
`tests/fixtures/consent-decision/v1/scenarios.json`. Classic/HPOS Woo staging,
browser/CMP, queue/retry, erasure, and legal proof remain required. This
contract is not evidence of privacy compliance.
