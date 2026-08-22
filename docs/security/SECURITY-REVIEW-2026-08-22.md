# ClickTrail security review — 2026-08-22

## Scope and method

Reviewed ClickTrail internal `1.9.0` at commit `a45aa9e`.

- OpenRouter `stealth/ox-alpha` performed an advisory source review. Its output was treated as untrusted review notes, not as evidence.
- Findings below were accepted only after tracing the local call paths and checking the exact files.
- Browser token integrity, deduplication concurrency, and retention are recorded as open risks; they are not silently included in this release patch.

## Executive verdict

Three confirmed control gaps were fixed in this branch: sGTM preview SSRF filtering, lifecycle-token rate limiting, and consent enforcement before queued delivery. The WordPress.org `1.8.17` candidate must receive the same runtime patch before publication; its backlog commit contains the pre-fix paths.

No confirmed unauthenticated code-execution, SQL-injection, or admin XSS finding was found. Admin diagnostic HTML is escaped server-side before the existing `innerHTML` sinks receive it.

## Findings

### CT-SEC-001 — Queued delivery bypassed consent gate

- Severity: Medium; release blocker for consent claims
- Confidence: Confirmed
- Location: `includes/server-side/class-queue.php:315-321`; `includes/server-side/class-dispatcher.php:422`
- Precondition: A retry row exists and queue processing runs while consent is required but the stored event has no granted marketing-consent snapshot.
- Evidence: Before this patch, `Queue::process_row()` reconstructed the event and called `$adapter->send( $event )` without calling the shared `Dispatcher::consent_allows()` gate. The normal dispatch path did call that gate.
- Impact: A queued event could leave through a configured destination after consent policy changed or after a consent-less server event was persisted.
- Fix: `process_row()` now deletes and skips a row when `Dispatcher::consent_allows( $event )` is false. The shared gate is public because queue processing is a separate delivery entrypoint.
- Regression: `tests/unit/QueueRetryTest.php::test_consent_gate_rejects_queued_event_without_marketing_consent`.
- Remaining limit: An event with an explicit granted snapshot is intentionally treated as granted during cron processing; true post-capture withdrawal requires queue identity/revocation coordination and remains a separate product task.

### CT-SEC-002 — sGTM preview could probe internal URLs

- Severity: Medium
- Confidence: Confirmed
- Location: `includes/admin/traits/trait-admin-diagnostics-ajax.php:970-1003`
- Precondition: A `manage_options` user with a valid settings nonce invokes the preview using a posted loader or collector URL.
- Evidence: The preview used `wp_remote_request()` on posted URLs without `reject_unsafe_urls`; saved endpoint sanitization did not protect this unsaved preview payload.
- Impact: Authenticated server-side requests to loopback, link-local, or private-network targets, plus a reachability oracle in the returned status/error.
- Fix: Preview now rejects URLs failing `wp_http_validate_url()` and sets `reject_unsafe_urls => true` on the request.
- Regression: Add a WordPress HTTP mock covering loopback rejection and the transport argument before enabling preview in production.

### CT-SEC-003 — Lifecycle bearer-token route had no rate limit

- Severity: Medium
- Confidence: Confirmed
- Location: `includes/api/class-tracking-controller.php:638-673`
- Precondition: Lifecycle ingestion is enabled and an attacker can reach the public REST route.
- Evidence: The route checked body size and compared the bearer token with `hash_equals()`, but did not call the existing per-IP `check_rate_limit()` helper used by other token routes.
- Impact: Unlimited online guessing when an operator chooses a weak CRM token; success permits forged lifecycle conversion stages.
- Fix: `lifecycle_permissions_check()` now applies `check_rate_limit( 'lifecycle_update' )` before token extraction.
- Regression: `tests/unit/RestAuthPermissionsTest.php::test_lifecycle_wrong_token_is_rate_limited`.

### CT-SEC-004 — Public browser token can authorize forged bottom-funnel events

- Severity: Medium integrity risk; not patched in this release
- Confidence: Confirmed mechanism, deployment risk depends on route use
- Location: `includes/tracking/class-auth.php:23-55`; `includes/tracking/class-eventv2.php:22-43`; `includes/api/class-tracking-controller.php:332-357`
- Precondition: An attacker reads a public page token and submits browser-accepted `lead` or `book_appointment` events.
- Impact: Forged conversions can pollute first-party delivery and downstream ad optimization. This is distinct from a secret leak: browser tokens are intentionally public.
- Recommendation: Bind bottom-funnel browser events to a server-verifiable session or form submission proof, then run an end-to-end compatibility test for thank-you redirects and cross-domain flows. Do not shorten the token TTL alone and call this solved.

### CT-SEC-005 — Deduplication is check-then-mark

- Severity: Low
- Confidence: Confirmed by inspection
- Location: `includes/tracking/class-dedup-store.php:63-98`; callers in `includes/api/class-tracking-controller.php:344`, `includes/server-side/class-dispatcher.php:195`, and `includes/server-side/class-queue.php:346`
- Impact: Concurrent duplicate submissions can both pass the read before either write. Destination-side event IDs reduce blast radius, but duplicate conversion dispatch remains possible.
- Recommendation: Add an atomic claim primitive and release it only for skipped/failed dispatches. Keep outside this release patch.

### CT-SEC-006 — Daily retention cleanup can fall behind event volume

- Severity: Low compliance drift
- Confidence: Confirmed
- Location: `includes/utils/class-cleanup.php:51-75`
- Impact: One `DELETE ... LIMIT 1000` per table per daily run can leave expired touch/event rows past the configured retention window on busy sites.
- Recommendation: Use a bounded loop or time budget and add a seeded cleanup test. Keep outside this release patch.

## GSC impressions → ClickTrail product gaps

Search Console snapshot supplied for 2026-05-20 through 2026-08-19 shows demand that ClickTrail does not yet explain or prove clearly:

1. **Offline conversion tracking / CRM.** `/services/offline-conversion-tracking` had 291 impressions and no clicks; related queries include `offline conversion tracking` (62), `crm offline conversion tracking` (29), and platform/software/vendor variants. ClickTrail already has lifecycle intake and click-ID capture, but its public integration contract does not show a complete CRM → lifecycle → offline-conversion handoff with `gclid`, lead ID, stage, value, consent, event time, and idempotency. Add that schema and tested examples. Do not claim native Google Ads upload until it exists and is verified.
2. **Form attribution.** `/clicktrail/integrations/contact-form-7` had 115 impressions; WPForms, Elementor, Ninja Forms, and WooCommerce pages also receive impressions with no clicks. The product behavior differs by integration: CF7/Fluent can inject fields, Gravity/WPForms need matching hidden fields, Elementor uses submission hooks, and Ninja stores attribution in the submission. Give each page one verified setup example and a clear automatic/manual boundary.
3. **Consent and server-side tracking.** `/services/server-side-tracking-consent` had 45 impressions and `server side tracking consent` had 29. Lead with the fixed queue/preview boundaries, state what remains open, and avoid “privacy complete” language until post-capture withdrawal and identity erasure are tested end to end.
4. **Google Sheets CRM templates.** `/crm-template` had 346 impressions and zero clicks; `google sheets crm template` had 91 and `crm template google sheets` 77. This is FunnelSheet demand, not evidence of a ClickTrail-native Sheets integration. Cross-link honestly: FunnelSheet can be the operational CRM surface; ClickTrail supplies attribution and lifecycle fields through form/CRM/webhook handoff.
5. **WhatsApp and GA4/GTM.** WhatsApp tracking, `auditoria gtm`, and `ga4 sprint` queries show adjacent demand. The next valuable ClickTrail asset is a measured WhatsApp → CRM lifecycle recipe and a GTM/sGTM validation checklist, not another generic tracking article.

## Release gate

The exact WordPress.org `1.8.17` backlog commit is `aa2c40f`. It must be rebuilt with CT-SEC-001, CT-SEC-002, and CT-SEC-003 applied, then pass PHP syntax, PHPUnit, PHPCS, package-content, activation, and public smoke checks. SVN publication is not certified from the unpatched historical commit.
