# Research: Cache-Safe Hidden Fields

Vendor docs read on 2026-10-31:

- WP Rocket dynamic and mandatory cookies: https://docs.wp-rocket.me/article/1313-create-different-cache-files-with-dynamic-and-mandatory-cookies
- WP Rocket never cache cookies: https://docs.wp-rocket.me/article/1382-never-cache-cookies
- LiteSpeed Cache settings (Do Not Cache Cookies, Vary Cookies): https://docs.litespeedtech.com/lscache/lscwp/cache/
- Cloudflare APO query parameters and cookies: https://developers.cloudflare.com/automatic-platform-optimization/reference/query-parameters/
- WP Engine server and browser cache: https://wpengine.com/support/cache/
- NitroPack exclude by cookie: https://support.nitropack.io/en/articles/8390301-exclude-from-optimization-by-cookie
- Kinsta edge caching: https://kinsta.com/docs/wordpress-hosting/caching/edge-caching

Competitor review of submit-time behaviour: [`../004-submit-time-refresh-parity/research.md`](../004-submit-time-refresh-parity/research.md).

## Current state (source, `main` at `ef6a20b`, 1.11.0 + #137)

| Surface | File | Finding |
|---|---|---|
| Cookie read | `includes/Core/class-attribution-provider.php:133-176` | `get_payload()` returns `array()` when `should_populate()` is false; reads `attribution` then `ct_attribution`; `json_decode` failure or empty array returns `array()`. |
| Consent gate | `class-attribution-provider.php:183-216` | `should_populate()` is `true` when consent mode is off or not required; otherwise reads the consent cookie; missing cookie returns `false`. |
| CF7 render | `includes/integrations/forms/class-cf7-adapter.php:43,55-67` | `wpcf7_form_hidden_fields` adds `ct_<key> => value` for every cookie key. Cookie values land in HTML. |
| CF7 submit | `class-cf7-adapter.php:46,93-133` | `wpcf7_mail_sent`; returns early at `:94-96` when `should_populate()` is false; collects posted `ct_*` (`:117-123`), then `array_merge( $attribution, cookie )` at `:129`: cookie keys win, keys absent from the cookie keep the posted value. Only ClickTrail's log sees this; `wpcf7_posted_data` is never refreshed. |
| GF render | `class-gravity-forms-adapter.php:96,135-154` | `gform_field_value` returns `$payload[ $key ]` for `ct_*` parameter names. Cookie values land in HTML. |
| GF submit | `class-gravity-forms-adapter.php:97,165-211` | `gform_pre_submission` → `refresh_submitted_fields()`; consent early return `:169-171`; empty payload return `:178-180`; per input: key absent or empty in cookie → `continue` (`:204-206`, posted kept); else `$_POST['input_*']` overwritten (`:208`). |
| Fluent render | `class-fluent-forms-adapter.php:51,55,64-86` | Echoes `<input type="hidden" name="ct_*" value="...">` from the cookie; returns without output when payload is empty (`:70-72`). |
| Fluent submit | `class-fluent-forms-adapter.php:52,56,112-146` | `fluentform/submission_inserted` (after persistence); consent early return `:113-115`; posted `ct_*` collected `:132-137`; cookie used only `if ( empty( $attribution ) )` (`:140-142`). |
| Elementor submit | `class-elementor-forms-adapter.php:46,66-96` | `elementor_pro/forms/new_record`; no render hook; same all-empty fallback (`:86-88`); inputs named `form_fields[<id>]`. |
| WPForms submit | `class-wpforms-adapter.php:118-128` | Entry meta written from the cookie only; posted `ct_*` not read. Render filter is dead (#151). |
| Ninja | `class-ninja-forms-adapter.php:126-137` | Cookie injected server-side at submit; posted `ct_*` not read. |
| Log gate | `class-abstract-form-adapter.php:59-70` | `log_submission()` re-checks consent and skips empty attribution. |
| Injector find | `assets/js/clicutcl-attribution.js:1090-1093` | Exact `name="ct_*"` match via `querySelectorAll`; GF `input_N`, Elementor `form_fields[...]`, WPForms `wpforms[fields][N]` are not found. |
| Injector fill | `clicutcl-attribution.js:1146-1186` | `run()` requires `injectEnabled` and live consent; `setIfEmpty()` treats `ct_*` as ClickTrail's own (`:1152`); no marker written today. |
| Injector triggers | `clicutcl-attribution.js:1204-1220` | DOMContentLoaded, `wpcf7init`, MutationObserver when `injectMutationObserver`. |
| Config | `includes/class-clicutcl-core.php:411-413` | `injectEnabled` from `enable_js_injection` (default true), `injectOverwrite` default false, `injectMutationObserver` default true. |
| Existing cache note | `docs/reference/INTEGRATIONS.md:232-241` | "Keep cached HTML visitor-neutral" is stated for query data and form actions, but hidden-field values from the cookie contradict it. |

## R1 — Which cache layers vary on the `attribution` cookie by default

| Layer | Default behaviour for an arbitrary first-party cookie such as `attribution` | Evidence |
|---|---|---|
| WP Rocket | Does not vary. Varying needs `rocket_cache_dynamic_cookies` (one file per cookie value) or `rocket_cache_mandatory_cookies`; the doc says a plugin should "use JavaScript" for per-visitor content. "Never Cache Cookies" bypasses cache for listed cookie names only. Both are opt-in and "not compatible with additional layers of cache" (APO, Cache Everything, Varnish). | [vendor doc read] |
| LiteSpeed Cache | Does not vary. "Vary Cookies" is empty by default and documented for "a third-party plugin [that] uses cookies to change page content"; "Do Not Cache Cookies" is a bypass list. | [vendor doc read] |
| Cloudflare APO | Does not vary. Bypass only for cookie prefixes `wp-`, `wordpress`, `comment_`, `woocommerce_`, `xf_`, `edd_`, `jetpack`, `yith_*`, `wpsc_`, `ecwid`, `ec_`, `bookly`. Serves cache with `gclid`, `utm_*`, `fbclid` etc. in the query string, so the landing request itself can be a cache hit. | [vendor doc read] |
| WP Engine | Server (Varnish) cache excludes pages where a cookie containing `wordpress_` is set plus Woo cookies; custom cookie exclusions only via Support. Edge Full Page Cache "will not respect custom cache exclusions for cookies" and documents that PHP-personalized content "might cache on the first user and display this message incorrectly to the next user(s)". | [vendor doc read] |
| NitroPack | Does not vary. "Exclude from Optimization by Cookie" is a bypass (no cache for matching cookie), not a vary; WordPress defaults cover login and cart cookies only. | [vendor doc read] |
| Kinsta | Server cache excludes "common private URIs and cookies" (logged-in, cart); Edge Caching does not honour "custom cookies and URIs" exclusions. No per-cookie vary documented. | [vendor doc read, page partially rendered] |

**Decision**: assume no layer varies on `attribution`. Make rendered HTML visitor-neutral instead of asking operators to configure every layer (which WP Rocket itself says does not compose with a CDN cache).

## R2 — Empty fields rather than no fields

**Decision**: keep emitting the `ct_*` names with empty values.
**Rationale**: `Injector.findInputs()` only fills inputs that already exist with exact `ct_*` names; removing them would turn CF7 and Fluent into "add the fields yourself" providers. Empty values are also what GF already shows on a landing-page submit (#137), so the submit rule already has to handle them.

## R3 — Cookie is authoritative, absent keys are blanked

**Decision**: when a readable cookie is present, the stored set is exactly the cookie's set.
**Rationale**: the only source of a posted value for a key the cookie lacks is HTML rendered from somebody's cookie, or a browser fill from storage that was later cleared. Keeping it (GF `:204-205`, CF7 `:129`) is what lets A's values survive on B's submit. The "cookie blocked" case is handled by R4.

## R4 — `ct_fill_source=js` marker

**Decision**: the Injector writes one hidden `ct_fill_source=js` per form it fills; without a cookie, posted values are kept only when the marker is posted.
**Rationale**: `Store.setCookie` and the Injector run in the same browser, so "no cookie but marker" means the cookie was blocked or stripped in transit, which is the case CF7's comment at `:126-128` protects. "No cookie and no marker" is cached HTML with no JavaScript fill: blank. The marker is not a trust token; posted values were already submitter-controlled.
**GF, Elementor, WPForms**: inputs are not named `ct_*`, the Injector cannot reach them, no marker is ever present; the rule degrades to cookie-only, which matches their current submit paths (GF overwrites from cookie; WPForms and Ninja never read posted values).

## R5 — Consent-blocked submits blank, not skip

**Decision**: the early returns at GF `:169-171`, CF7 `:94-96`, Fluent `:113-115` move after a blanking pass.
**Rationale**: returning early leaves cached values on their way into the provider's entry and mail. Blanking first costs one loop and satisfies SC-002.

## R6 — Not verified (open)

- Which pre-persistence hook each of CF7, Fluent Forms, Elementor and WPForms exposes for rewriting posted `ct_*` is decided in spec 004; this spec assumes one exists per provider.
- Whether Fluent Forms re-renders through `fluentform/form_element_start` on AJAX-loaded forms (conversational and popup) so the empty fields are present for the Injector.
- Whether GF conditional-logic or multi-page re-renders call `gform_field_value` again on page changes (affects only the filter-on path).
- Kinsta page was fetched as a client-rendered document; the quoted sentences came from embedded page data, not a clean render.
- No live run on a cached site yet; SC-001 and SC-004 stay runtime-unverified.
