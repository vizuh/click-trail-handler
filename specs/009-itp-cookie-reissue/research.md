# Research: Attribution cookie restore and server re-issue (Safari ITP)

Source read on 2026-11-02 (`main` @ `5390c84`). WebKit text was read from the live pages on that date. ClickTrail behaviour below is source-present / runtime-unverified unless a row says it ran in the real-WordPress harness; no Safari or iOS device was used.

Vendor references:

- Tracking Prevention in WebKit: https://webkit.org/tracking-prevention/ (page modified 2023-04-27)
- ITP 2.1 (client-side cookie cap): https://webkit.org/blog/8613/intelligent-tracking-prevention-2-1/
- ITP 2.3 (script-writeable storage, link decoration): https://webkit.org/blog/9521/intelligent-tracking-prevention-2-3/
- RFC 6265 (`Set-Cookie`, `Max-Age`, host-only vs `Domain`): https://www.rfc-editor.org/rfc/rfc6265

## WebKit facts relied on (verbatim)

| # | Quote | Source | What we take from it |
|---|---|---|---|
| W1 | "ITP detects such link decoration and caps the expiry of cookies created in JavaScript on the landing webpage to 24 hours." | tracking-prevention, section "Detection of Cross-Site Tracking Via Link Decoration" | A cookie written by `document.cookie` on a landing page that carries a click ID can live 24 hours. The current page does not spell out the trigger. |
| W2 | "With ITP 2.2, when a webpage is navigated to from a domain classified by ITP and the landing URL has a query string or fragment, the expiry of persistent client-side cookies created on that page is 24 hours." | ITP 2.3 post (describing ITP 2.2) | The trigger: a cross-site navigation from a classified domain to a URL with a query string or fragment. A paid click-ID landing from such a referrer is the case this issue is about. Whether a given landing is classified is up to Safari; we cannot detect it. |
| W3 | "With ITP 2.1, all persistent client-side cookies, i.e. persistent cookies created through document.cookie, are capped to a seven day expiry." | ITP 2.1 post | The historical 7-day cap on the expiry of script-written cookies. |
| W4 | "Only cookies created through document.cookie are affected by this change." | ITP 2.1 post, "Implementation Details" | An HTTP `Set-Cookie` is not subject to W3. This is the premise of U2. |
| W5 | "ITP deletes all cookies created in JavaScript and all other script-writeable storage after 7 days of no user interaction with the website. The latter storage forms are: IndexedDB, LocalStorage, Media keys, SessionStorage, Service Worker registrations and cache" | tracking-prevention, "7-Day Cap on All Script-Writeable Storage" | The current wording is a deletion after 7 days without user interaction (click, tap or keyboard entry; scrolling does not count), and it covers localStorage as well as script-written cookies. |
| W6 | "ITP detects third-party CNAME cloaking and third-party IP address cloaking requests and caps the expiry of any cookies set in the HTTP response to 7 days." | tracking-prevention, "CNAME and Third-Party IP Address Cloaking Defense" | The one cap on HTTP-set cookies. The table on that page lists "No cloaking" and first-party cloaking that matches the top frame host as "No cap"; only a third-party cloaked subresource is capped. The re-issue request goes to the same host as the page, so it is not that case. |
| W7 | "Cookies created through document.cookie cannot be HttpOnly" | ITP 2.1 post | The re-issued cookie stays non-HttpOnly on purpose: `clicutcl-attribution.js` must keep reading and expiring it. |

## What W1-W7 mean for the two user stories

- **The issue text says the cookie is capped at 7 days while "the localStorage mirror may still hold it".** W5 says localStorage is deleted on the same 7-day-without-interaction rule as script-written cookies. So the mirror outlives the cookie only when the cookie is cut shorter than the mirror: the 24-hour cap of W1/W2 (cookie gone after a day, mirror kept up to 7 days of non-interaction) and ITP versions or builds that cap the cookie's expiry (W3) rather than purge on inactivity. After 7 days with no interaction both are gone, and the only copy that can survive is an HTTP-set cookie (W4). **U1 (restore) therefore covers days 1-7 of the 24-hour case; U2 (server re-issue) is what covers returns after 7 days.** The spec states both limits rather than promising a full week-plus recovery from localStorage alone.
- **What stays capped after the fix.** A cookie the script writes after a re-issue (a new touch, a restore) is script-written again: capped by W1/W5. That is why every script write of the attribution cookie sets a pending marker and triggers a new re-issue (U2 trigger rule). `ct_visitor_id`, `ct_session_id`, `ct_session` and every other script-written plugin cookie are not re-issued by this feature (out of scope; the issue names `attribution`; `ct_visitor_id` was mentioned in the task as a candidate and is recorded under R6 as a follow-up) and stay script-capped.
- **Only user interaction resets the clock.** A visitor who returns, does not click, tap or type, and leaves is not "interacted" in W5's sense. Nothing in this feature can change that.
- **Safari-only?** W1-W5 describe WebKit. Firefox ETP and Brave have their own, different, caps; nothing here was tested there.
- **Fetch responses.** WebKit's text says "cookies set in the HTTP response" (W6) and "created through document.cookie" (W4). A `Set-Cookie` on a same-origin `fetch()` response is an HTTP response. [INFERENCE] Safari treats it like any HTTP-set cookie; this was not run in Safari.

## Current state (source)

| Surface | File | Finding |
|---|---|---|
| Attribution cookie write | `assets/js/clicutcl-attribution.js` `Store.setCookie` (~647-678) | `document.cookie` only; `expires` from `days`; `; domain=` from `cookieDomainAttr()` (host-only fallback on mismatch or a refused probe); `path=/; SameSite=Lax[; Secure]`. Values over `MAX_COOKIE_VALUE_BYTES = 3800` (encoded) are removed instead. |
| Mirror | `Store.getLocalData` / `setLocalData` (~752-815), key `CONFIG.cookieName` | Envelope `{v:1, savedAt, expiresAt, data}`, `expiresAt = now + cookie_days`; expired or legacy envelopes are removed on read. The mirror keeps `click_id_history`, the cookie never does (`saveData` strips it). |
| Read path | `Store.getData` (~842-868) | cookie > localStorage > sessionStorage merge; a cookie with no mirror re-creates the mirror; nothing re-creates a missing cookie. |
| Boot | `ClickTrailAttribution.init()` (~2188), `runAttribution()` (~2257) | Consent is resolved first (`liveConsentAllows()`); `runAttribution()` reads storage, may `saveData()`, then runs `Injector`, `Decorator` and the `dataLayer` push. |
| Server readers | `Attribution_Provider::get_payload()` | Reads `$_COOKIE['attribution']` (then legacy `ct_attribution`), `json_decode`, `sanitize()`. No cookie, no payload. |
| Server writers | `Events_Logger::set_event_cookie()` (1 minute, HttpOnly, consent gated), `set_admin_qa_cookie()` | No server write of `attribution` exists today. |
| REST | `Tracking_Controller` (`includes/api/`): `events/batch`, `attribution-token/sign`, `attribution-token/verify`, `whatsapp-ref`, `webhooks/*`, `lifecycle/update`, `diagnostics/*` | All gated by the page-embedded client token (`X-Clicutcl-Token`, 7-day default TTL) and a per-IP rate limit. `whatsapp-ref` is also consent gated from the request cookie via `Consent::is_required()` + `Consent::marketing_allowed()`. |
| Cookie domain | `Cookie_Scope::resolve_for_site()` (#154) | One PHP answer already localized as `cookieDomain`; JS adds a suffix guard and a probe. |
| Cookie value shape | `sanitizeAttributionData()` (JS) | Keys `[a-z0-9_]`, at most 64 chars; string values at most 256 chars with control characters removed; booleans and finite numbers kept; no nesting. |

## R1 — Which request carries the re-issue

**Decision**: a new `POST /clicutcl/v2/cookie-refresh`, sent by `clicutcl-attribution.js` only when a script write of the attribution cookie is pending.
**Alternatives rejected**:

- `events/batch`: only exists while browser-event collection is on; one request per event batch, gated per event, with dedupe and event-store side effects; and it is not tied to the cookie having just been script-written. Coupling cookie lifetime to the events module would make the fix silently absent on installs with events off.
- `attribution-token/sign`: only used when cross-domain tokens are on, and its body is the token allowlist, not the cookie.
- A page-render `Set-Cookie`: forbidden. A response that sets a cookie is not cached by Cloudflare and similar CDNs (#164, S8). The route is a REST `POST`, which no page cache stores.

**Rationale**: smallest surface: one tiny route, no store, no side effect other than the response header. It reuses the gates every sibling route has (rate limit, client token) plus the `whatsapp-ref` consent gate.

## R2 — When to send (event-driven, not periodic)

**Decision**: a `localStorage['ct_cookie_reissue']` marker is written whenever the script writes the attribution cookie (and live consent allows). The next `runAttribution()` (same page, after `saveData()`) sends one request when the marker is set, the cookie is present and the last attempt is over one hour old; success (or a definitive 403) clears the marker.
**Rationale**: the server cookie replaces the script cookie, so it is needed exactly when the script wrote one. A fixed "every N days" timer would also work but renews `Max-Age` on every tick, extending retention for any visitor who keeps returning without a new touch; the event-driven rule renews it only when the script itself would have rewritten the cookie. The one-hour retry floor bounds traffic when the route keeps answering 401 (stale cached token), 429 or 5xx.

## R3 — Sliding retention

**Decision**: the re-issue sets `Max-Age = cookie_days * 86400` from the moment of the re-issue, the same as the script's own `expires = now + cookie_days` at its write. Because a re-issue only follows a script write (R2), the cookie's life is never longer than the script would have given it.
**Rationale**: the issue fixes `Max-Age=<cookie_days*86400>`. Clamping to the mirror's remaining time was considered and dropped: it would make the wire value differ from the setting by a few seconds on every request and add a client-supplied input.

## R4 — What the server may echo

**Decision**: re-issue the **request's own** `attribution` cookie byte for byte (after `rawurlencode`, matching `encodeURIComponent` on decode), and only when it passes a strict schema check; otherwise no header.
**Rejected**: re-serialising through `sanitize_attribution_token_data()` (an allowlist of 56 UTM and click-ID keys: it would drop timestamps, landing page, referrer, channel and selected-click-ID fields, so the value would not be "the same") or through `Attribution_Provider::sanitize()` (generic, but it adds derived keys such as `sc_click_id` and normalised click IDs: "no new data added" would fail). A strict check without rewriting is the only option that satisfies both.
**Schema**: JSON object, 1-128 keys, key `^[a-z0-9_]{1,64}$`, values string (at most 256 characters, no control characters), integer, float or boolean; no arrays or objects; encoded size at most 3800 bytes (`MAX_COOKIE_VALUE_BYTES`); no unsubstituted `{{...}}` macro (`Attribution_Provider::sanitize()` drops these, the script never writes them).

## R5 — Consent

**Decision**: consent is read from the request's consent cookie only (`Consent::is_required()` and `Consent::marketing_allowed()`), never from the body, exactly as `whatsapp-ref` does. With `require_consent` on and no granted marketing consent the route answers 403 and sets nothing.
**Rationale**: the same gate already protects the other consent-gated route; a body claim would let a client opt itself in.

## R6 — Open questions and deferred

- `ct_visitor_id` is also script-written and capped; it was listed in the task text. It is a single opaque ID that PHP reads in `get_payload()`. Re-issuing it needs its own validation rule and a second `Set-Cookie`; left as a follow-up (listed in the PR).
- The mirror and the pending marker live in `localStorage`, which W5 deletes together with script cookies after 7 days of no interaction; a visitor returning after that has nothing client-side and, if never re-issued (feature off, request failed), nothing at all.
- `Max-Age` precision: PHP sends the value computed at request time; a client clock skew does not matter for `Max-Age` (relative), which is also why `Expires` is not sent.
- Real Safari behaviour (a re-issued cookie surviving 7 days of non-interaction, W1/W2 on a classified landing) is not exercised anywhere in this repository's harness; runtime-unverified.
