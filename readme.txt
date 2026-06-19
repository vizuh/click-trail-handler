=== ClickTrail – UTM, Click ID & Ad Tracking (with Consent) ===
Contributors: hugoc
Author: Vizuh
Author URI: https://vizuh.com
Tags: attribution, utm, consent mode, woocommerce, server-side tracking
Requires at least: 6.5
Tested up to: 7.0
Stable tag: 1.8.7
Requires PHP: 8.1
WC requires at least: 10.4.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Consent-aware attribution for WooCommerce, WordPress forms, and event flows. Capture UTMs and click IDs across conversion paths.

== Description ==

Attribution usually breaks somewhere between the ad click and the conversion. ClickTrail makes it survive: cached pages, dynamic forms, multi-page journeys, repeat visits, and consent requirements.

ClickTrail keeps the source of the visit, not a profile of the visitor. Capture is first-party and consent-aware: the plugin does not call external services to identify or enrich visitors, and data only leaves your site through integrations you enable yourself (GTM, webhooks, server-side delivery).

ClickTrail stores first-touch and last-touch attribution from the landing page and keeps it available until the conversion point, where it becomes usable inside WordPress:

* WooCommerce orders
* supported forms
* browser events
* optional server-side delivery

In WooCommerce, ClickTrail stores attribution on the order, pushes enriched purchase events on the thank-you page, and can optionally emit GA4-style storefront events for `view_item`, `view_item_list`, `view_cart`, `add_to_cart`, `remove_from_cart`, and `begin_checkout`, plus post-purchase milestones, through the same ClickTrail pipeline.

= Pick a starting path =

* **WooCommerce store**: enable Capture and WooCommerce. Orders carry campaign context from day one; add storefront events later if you want funnel signals.
* **Lead-gen forms**: enable Capture and Forms. Contact Form 7 and Fluent Forms get hidden fields automatically; Gravity Forms and WPForms fill the `ct_*` fields you add.
* **GTM / sGTM stack**: enable Capture, Events, and Delivery. Browser events push to the `dataLayer`, and the server-side adapters feed sGTM or platform APIs directly.

= What problems it solves =

* **No paid order reports as "direct"**: Paid traffic often ends up looking like direct traffic by the time an order is placed. ClickTrail stores attribution on the order and keeps purchase reporting tied to campaign context.
* **No checkout journey goes dark**: WooCommerce storefront journeys can emit opt-in `view_item`, `view_item_list`, `view_cart`, `add_to_cart`, `remove_from_cart`, and `begin_checkout` signals through the same ClickTrail event layer used elsewhere in the plugin.
* **No cached or AJAX-rendered form drops attribution**: Hidden fields often break on cached pages or dynamically rendered forms. ClickTrail includes client-side fallback and dynamic-content support.
* **No cross-domain hop breaks continuity**: Approved link decoration and attribution tokens keep continuity between domains or subdomains.
* **No consent signal is ignored**: Consent controls, browser events, webhook intake, and server-side transport live in the same plugin and respect the same consent state.

= Core capabilities =

* **Capture**: first-touch and last-touch UTMs, major ad click IDs, and referrers with automatic organic/social/referral fallback when UTMs are absent.
* **WooCommerce**: checkout attribution persistence, thank-you purchase event push, enriched commerce payloads, optional storefront commerce events, and optional order-status milestones.
* **Forms**: automatic hidden-field enrichment for Contact Form 7 and Fluent Forms, compatible hidden-field population for Gravity Forms and WPForms, client-side fallback, dynamic form support, and WhatsApp attribution continuity.
* **Events**: browser event collection with `dataLayer` pushes, canonical REST intake, webhook ingestion, lifecycle updates, one-time WordPress follow-up events such as `login`, `sign_up`, and `comment_submit`, and optional WooCommerce storefront events.
* **Delivery**: optional server-side transport, retry queue, diagnostics, consent-aware dispatch, and failure telemetry.

= Recent additions =

Recent releases extended the Gravity Forms integration with channel classification, merge tags, and per-form controls:

* **Channel classification**: every GF entry now receives a `ct_ft_channel` value — a human-readable label such as Google Ads, ChatGPT, or Mailchimp — derived from click IDs, UTM parameters, or referrer context. A server-side fallback covers sessions where JS attribution was unavailable.
* **Expanded click ID capture**: six additional click IDs (Reddit `rdt_cid`, Pinterest `pin_cid`, Snapchat `snap_cid`, Mailchimp `mc_cid` / `mc_eid`, and Display & Video 360 `dclid`) are now captured and stored.
* **Merge tags**: nine `{clicutcl_*}` merge tags are available in GF notifications and confirmations, including `{clicutcl_channel}`, `{clicutcl_click_id}`, and seven UTM-based tags.
* **Per-form toggle**: attribution tracking can be enabled or disabled per form via a dedicated ClickTrail section in Gravity Forms form settings.
* **Admin QA mode**: attribution data is stored in `sessionStorage` only when a `manage_options` user is logged in, preventing admin browsing from appearing in attribution reports.
* **sessionStorage fallback**: attribution capture now falls back to `sessionStorage` when the browser blocks cookies.
* **Minification protection**: ClickTrail script tags carry exclusion attributes recognised by Autoptimize, Cloudflare Rocket Loader, WP Rocket, and LiteSpeed Cache (corrected attribute set in 1.7.0).

= Current admin structure =

The main settings experience is organized by capability:

* Capture
* Forms
* Events
* Delivery

Operational screens stay separate:

* Logs
* Diagnostics

= Supported integrations =

* **Forms**: Contact Form 7, Elementor Forms (Pro), Fluent Forms, Gravity Forms, Ninja Forms, WPForms
* **Commerce**: WooCommerce
* **CMP sources**: ClickTrail banner, Cookiebot, OneTrust, Complianz, GTM, custom
* **Webhook providers**: Calendly, HubSpot, Typeform
* **Server-side adapters**: Generic collector, sGTM, Meta CAPI, Google Ads / GA4, LinkedIn CAPI, Pinterest Conversions API, TikTok Events API

= Forms behavior by plugin =

* **Contact Form 7 and Fluent Forms**: ClickTrail can add hidden attribution fields automatically.
* **Gravity Forms and WPForms**: ClickTrail can populate matching hidden fields you add to the form.
* **Recommended for Gravity Forms and WPForms**: add the hidden fields you want stored or exported, and ClickTrail will fill them.
* **Elementor Forms (Pro)**: ClickTrail uses the available submission hooks and attribution fallback, not automatic hidden-field injection.
* **Ninja Forms**: ClickTrail stores attribution with the submission and surfaces it in the submission record, not as automatic hidden-field injection.

= Supported click IDs =

* Google: `gclid`, `wbraid`, `gbraid`
* Meta: `fbclid`
* TikTok: `ttclid`
* Microsoft: `msclkid`
* X / Twitter: `twclid`
* LinkedIn: `li_fat_id`
* Snapchat: `sccid`
* Pinterest: `epik`

= Additional capture fields =

* Extended UTMs: `utm_id`, `utm_source_platform`, `utm_creative_format`, `utm_marketing_tactic`
* Browser/platform identifiers: `fbc`, `fbp`, `_ttp`, `li_gc`, `ga_client_id`, `ga_session_id`

== Installation ==

= Before you configure anything =

ClickTrail can be rolled out in layers. A basic attribution setup for forms or WooCommerce does not require server-side delivery on day one.

* If you only want attribution inside WooCommerce or forms, you can leave server-side delivery disabled.
* If your site already loads Google Tag Manager, do not add the GTM container ID again in ClickTrail.
* If you use Gravity Forms or WPForms, add the `ct_*` hidden fields you want stored or exported before testing.
* If your site has consent requirements, decide whether ClickTrail or your existing CMP should be the consent source.

= Recommended first setup =

1. Install the plugin through WordPress or upload it to `/wp-content/plugins/click-trail-handler/`.
2. Activate the plugin.
3. Open **ClickTrail > Settings**.
4. In **Capture**:
   * keep attribution enabled
   * choose a retention window that matches your sales cycle
   * enable cross-domain continuity only if visitors move between approved domains or subdomains
5. In **Forms**:
   * enable only the integrations you actually use
   * for Contact Form 7 and Fluent Forms, ClickTrail can add attribution hidden fields automatically
   * for Gravity Forms and WPForms, add the matching `ct_*` hidden fields you want to preserve, such as `ct_ft_source`, `ct_lt_source`, or `ct_gclid`
6. In **Events**:
   * leave browser events enabled only if you want `dataLayer` pushes and on-site event capture
   * enable **WooCommerce storefront events** only if you want `view_item`, `view_item_list`, `view_cart`, `add_to_cart`, `remove_from_cart`, and `begin_checkout` in the browser event layer
   * enable the richer Woo `dataLayer` contract only if you want `event_id` and consent-aware `user_data` for GTM-first flows
   * add a GTM container ID only if your site does not already inject GTM somewhere else
   * switch GTM to **sGTM compatibility mode** when you want a tagging-server URL, first-party script delivery, or a custom loader path, then run the preview checks before rollout
7. In **Delivery**:
   * leave server-side delivery off if you do not have a collector, sGTM, or advertising endpoint yet
   * if you do use server-side delivery, configure the adapter, endpoint, and timeout here
   * if consent is required, choose the correct consent source and mode before going live
8. Open **ClickTrail > Diagnostics** and run the relevant checks, especially Endpoint Test, Conflict Scan, and Woo Order Trace Lookup when applicable.

= How to verify your setup =

1. Visit your site with a test URL such as `?utm_source=test&utm_medium=cpc&utm_campaign=clicktrail-install-check`.
2. Browse to another page, then place a test WooCommerce order or submit a supported form.
3. Confirm the expected result:
   * the WooCommerce order or form entry contains attribution values
   * Woo purchase events appear in your GTM preview or `dataLayer`
   * if Woo storefront events are enabled, `view_item`, `view_item_list`, `view_cart`, `add_to_cart`, `remove_from_cart`, and `begin_checkout` appear in GTM preview or the `dataLayer`
   * if sGTM mode is enabled, the Events-tab preview checks reach the configured loader or collector URLs
   * Diagnostics and Logs show intake or delivery activity if **Delivery** is enabled

= Good default rollout =

Start with **Capture** plus the forms or WooCommerce integrations you already use. Add **Events** next if you want browser analytics signals. Add **Delivery** only when you are ready to send data to a collector or advertising endpoint.

== Frequently Asked Questions ==

= Where does WooCommerce attribution appear? =

ClickTrail stores attribution on the WooCommerce order. The plugin also adds Woo attribution views inside the Woo admin experience where supported, and purchase events carry the same campaign context into the `dataLayer` and optional server-side delivery.

= Does ClickTrail support WooCommerce HPOS? =

ClickTrail now declares compatibility with WooCommerce custom order tables (HPOS) and keeps WooCommerce runtime storage on Woo order APIs for order attribution and purchase tracking.

= What do the WooCommerce storefront events do? =

When you enable **WooCommerce storefront events** in the Events tab, ClickTrail emits `view_item`, `view_item_list`, `view_cart`, `add_to_cart`, `remove_from_cart`, and `begin_checkout` through the same browser event layer used for other ClickTrail events. They are off by default on upgrades.

= What does sGTM mode change? =

sGTM mode changes how ClickTrail loads the GTM container and how the Events tab validates a GTM-first rollout. You can configure a tagging-server URL, first-party script delivery, or a custom loader path, then run preview checks before switching Delivery to the sGTM adapter when needed.

= Does ClickTrail replace GA4 or GTM? =

No. ClickTrail complements them. It preserves attribution inside WordPress, pushes event data to the `dataLayer`, and can optionally deliver events through its server-side pipeline.

= Does it work only with WooCommerce? =

No. WooCommerce is one supported conversion surface, but ClickTrail also supports lead forms, external webhook providers, and broader attribution capture for WordPress sites.

= What happens if my site uses aggressive caching? =

ClickTrail includes a client-side fallback and dynamic-content support so attribution can still reach supported form fields when server-rendered fields are not enough.

= Do I need to add hidden fields to every form? =

No. Contact Form 7 and Fluent Forms can receive attribution hidden fields automatically. Gravity Forms and WPForms work best when you add the matching `ct_*` hidden fields you want stored or exported. Elementor Forms (Pro) and Ninja Forms use their submission hooks and stored attribution paths rather than automatic hidden-field injection.

= Can I use it without server-side delivery? =

Yes. Attribution capture, WooCommerce order attribution, purchase event pushes, and form enrichment all work without enabling server-side delivery.

= Is consent mode required? =

No. Consent mode is optional. When enabled, ClickTrail can gate attribution and event handling according to the configured consent behavior.

= Can I keep using my existing consent platform? =

Yes. ClickTrail can listen to its own banner, Cookiebot, OneTrust, Complianz, GTM, or a custom source. You do not need to replace an existing CMP just to use the plugin.

== Screenshots ==

1. WooCommerce order source visibility inside the Woo order list.
2. WooCommerce order attribution detail for reviewing campaign context on a specific order.
3. Unified ClickTrail settings organized into Capture, Forms, Events, and Delivery.
4. Diagnostics and delivery health for verifying event intake and transport behavior.

== Changelog ==

= 1.8.12 =
*   **UX**: clearer in-app explanation of how ClickTrail preserves landing-page attribution across the consent banner.

= 1.8.11 =
*   **Maintenance**: internal code cleanup. No functional changes.

= 1.8.10 =
*   **Security: server-side delivery endpoint is validated against SSRF**: The collector / server-side GTM endpoint URL is now checked on save (internal, loopback, and non-public addresses are rejected), and every outbound delivery request rejects unsafe URLs, so a misconfigured or hostile endpoint can no longer be pointed at internal network resources.
*   **Security: webhook signature and secret handling hardened**: Inbound webhook signatures are compared on the raw value with strict format validation, and provider secrets are stored exactly as entered (they are no longer truncated or whitespace-stripped, which previously could corrupt long secrets and break verification). Replay protection is now race-safe on sites with a persistent object cache.
*   **Security: cross-domain attribution-token verification now requires the page token**, matching the signing endpoint and removing an unauthenticated request path.
*   **Privacy: visitor IP is anonymized in the diagnostic event log** (the full IP is still used only transiently for server-side conversion matching). The personal-data eraser now also clears queued server-side delivery records.
*   **Privacy: geo-based consent no longer trusts client-supplied country headers by default**, since those headers can be spoofed unless set by a trusted CDN. An unknown region now safely defaults to requiring consent. Sites behind a trusted edge can re-enable header trust with the `clicutcl_trust_geo_request_headers` filter, or provide an authoritative country with `clicutcl_request_country_code`.
*   **Security: Gravity Forms attribution merge tags are always HTML-escaped** in output.
*   **Admin: a warning now appears when "encrypt secrets at rest" is enabled but the server cannot support it**, so secrets are not silently stored unencrypted.

= 1.8.9 =
*   **i18n: German (de_DE) JavaScript translations now load**: Added the script-translation JSON for the React settings app. Without this file, the de_DE locale shipped in v1.8.7 only translated PHP-side strings; the JS-side React UI (Capture/Forms/Events/Delivery tabs) stayed in English. Generated via `wp i18n make-json` for `assets/js/admin-settings-app.js`.

= 1.8.8 =
*   **Security**: Reject unsubstituted ad-platform dynamic-parameter macros (Facebook `{{campaign.name}}`, `{{adset.name}}`, etc.) during attribution capture. These placeholders appear literally in landing-page URLs when ads aren't served through the ad platform, and previously flowed into attribution storage and downstream destinations as if they were real campaign names. Applied to both PHP server-side sanitizer and the client-side `sanitizeValue()` helper.
*   **Fix**: Consent gate no longer defaults to ON when Consent Mode is disabled. Two paths previously read a removed legacy `require_consent` option (default TRUE) — on any site without Consent Mode + a CMP, that implicit default silently blocked all attribution. Now the gate is only active when Consent Mode is explicitly enabled.
*   **Housekeeping**: GTM Starter Kit lead-magnet banner disabled in-plugin; the kit is now distributed via the website. The class file stays for future re-activation; the runtime init() call is commented out and the source files added to `.distignore` so they're not bundled in the WP.org SVN release.

= 1.8.7 =
*   **Brazilian Portuguese (pt_BR) translations refreshed**: Regenerated from current source code (was last updated at v1.5.2). 533 strings translated, 81 strings carried over from the prior translation but flagged for human review where the underlying source string changed.
*   **German (de_DE) translations added**: Full translation of all 614 strings — first locale beyond Portuguese.

= 1.8.6 =
*   **Block third-party promotional banners**: Bundle-sale ads and other plugin notices can no longer inject promotional banners into the ClickTrail wizard, settings, logs, or diagnostics pages.
*   **Admin UI consistency pass**: Expanded design tokens (status colors, background scale, 8px spacing scale), aligned card and section header padding, removed a duplicated 90-line GTM-offer CSS block.

= 1.8.5 =
*   **WordPress 7.0 compatibility**: Tested up to WordPress 7.0 "Armstrong" (released 2026-05-20). Requires WooCommerce 10.4.2 or later for WP 7.0 compatibility.
*   **Script enqueue refactor**: Removed dead pre-WP-6.3 fallback from `clicutcl_script_args()`. All supported installs (WP 6.5+) now use the array-form registration and optional `defer`/`async` strategy keys.
*   **Deprecation scan in CI**: Added `composer phpcompat` and a PHPCompatibilityWP GitHub Actions workflow to flag PHP and WordPress deprecated/removed API usage on every push.

= 1.7.2 =
*   **NitroPack compatibility**: ClickTrail now detects NitroPack and automatically attempts to exclude its scripts from NitroPack's "Postpone JS" feature via two mechanisms: the `nitropack_js_url_exclude` filter and the `data-nitropack-exclude` HTML attribute on script tags. Without this, postponed scripts can cause empty UTM data on leads when users navigate away before interacting with the page.
*   **NitroPack diagnostic warning**: The Diagnostics conflict scan now surfaces a `warn` finding when NitroPack is active, with instructions to verify script exclusions in NitroPack → Optimization → JavaScript → Script exclusions.
*   **pt-BR playbook — webhook middleware pattern**: Expanded the Padrão 5 section in the Portuguese Implementation Playbook to document the three-layer webhook architecture (script → webhook middleware → CRM), the complete field reference for Google Ads and Meta Ads attribution, PipeRun-specific notes on custom field IDs, and common errors including the silent field-drop behaviour when CRM fields do not exist before the first webhook call.

= 1.7.1 =
*   **Setup Wizard**: Added a 3-step onboarding wizard that fires automatically on first activation. Step 1 auto-detects active form plugins, WooCommerce, CMPs, and caching layers. Step 2 collects the GA4 Measurement ID. Step 3 confirms attribution is active with a quick-test link. All external admin notices are suppressed while the wizard is open. A permanent "Setup Wizard" link is added to the plugin action row on the Plugins screen.
*   **Activation fix**: `Setup_Wizard::init()` now registers before the preflight class check so the activation redirect fires reliably on all environments.
*   **Two-phase consent capture**: UTMs and click IDs are now buffered to `sessionStorage` immediately on page load before any consent banner fires. On consent grant the pending buffer is promoted to the attribution cookie, preserving first-touch even when the user accepts the banner on a later page.
*   **Call tracking MutationObserver skip**: The MutationObserver watching for dynamically inserted links now bails early when every new anchor has a skippable scheme (`tel:`, `mailto:`, `#`). Eliminates wasted debounce cycles from Dynamic Number Insertion tools such as CallRail, CallTrackingMetrics, and WhatConverts.
*   **GF / WPForms attribution field diagnostic**: The Diagnostics conflict scan now checks every active Gravity Forms and WPForms form for `ct_*` hidden fields. Forms without attribution fields surface a warning with a direct edit link. No new AJAX endpoints.
*   **Cross-domain decoration checklist warning**: The setup checklist now shows a `warn` state when link decoration is on but no allowed domains are listed, and an informational note about external payment providers when decoration is correctly configured.
*   **Cross-domain limitations documentation**: Added a "Cross-Domain Limitations" section to the Implementation Playbook and a payment provider table to the Integrations reference covering Stripe, PayPal, Mollie, and Square.
*   **Portuguese (pt-BR) Implementation Playbook**: Added `IMPLEMENTATION-PLAYBOOK.pt-BR.md` covering all rollout patterns including webhook/CRM integrations, the `window.ClickTrail` JS API field reference, and external checkout limitations.
*   **WP.org compliance**: Plugin zip folder renamed from `cth` to `click-trail-handler` to match the WordPress.org plugin slug requirement.
*   **Elementor Forms popup fix**: Attribution data is now injected reliably when an Elementor popup opens and when new form inputs appear in the DOM after initial page load.
*   **CI**: Fixed CodeQL workflow — removed PHP from the language matrix and updated action refs to v4.
= 1.7.0 =
* Hardening release. No user-visible feature changes; addresses ten findings from the 1.6.0 internal code review.
* Fixed admin QA mode cache-poisoning risk: `adminQaMode` is no longer baked into the localized attribution config, where a full-page cache plugin could capture it from an admin-viewed response and serve it to anonymous visitors. It is now a 1-hour `clicutcl_admin_qa` cookie set on `init` only for logged-in `manage_options` users, which cache plugins correctly exclude.
* Fixed minification-exclusion attributes: the previous set advertised support for WP Rocket and LiteSpeed but used non-existent attribute names. Replaced with the canonical attributes each tool actually reads: `data-no-optimize`, `data-noptimize`, `data-cfasync`, `data-no-defer`, `data-no-minify`.
* Replaced `str_replace(' src=', ...)` injection with regex-based injection after the opening `<script` token, robust to attribute order and leading/trailing whitespace.
* Refactored `Gravity_Forms_Adapter` from ~660 lines into four focused classes: `Gf_Channel_Resolver`, `Gf_Form_Settings_Tab`, `Gf_Merge_Tags`, `Gf_Minification_Protector`. The adapter is now a thin coordinator under 350 lines. All public method signatures preserved; `resolve_channel_fallback()` kept as a backward-compat shim.
* Memoized `Attribution_Settings` instance inside the adapter and shared it with the extracted classes — fewer redundant `Option_Cache` reads per request.
* Added PHPUnit unit-test suite for `Gf_Channel_Resolver` covering 10 classification rules, including the `gemini.google.com` precedence over Google Organic and the fbclid+paid-medium gate.
* Added documentation in HOOKS-REFERENCE.md clarifying that `ct_*` entry meta is registered for all forms but values are gated per-form, and that channel labels are stored data values (not UI strings) and should not be wrapped with `__()`.
* Added inline comments documenting fail-open semantics when GF form context is unavailable, and the GF 2.5+ single-arg signature of `gform_pre_form_settings_save`.

= 1.6.0 =
* Added full channel classification to Gravity Forms entries: `ct_ft_channel` stores a human-readable label (Google Ads, ChatGPT, Mailchimp, etc.) derived from click IDs, UTM parameters, or referrer context; server-side fallback computes the label when JS is unavailable.
* Added six new click IDs to the capture schema: `rdt_cid` (Reddit), `pin_cid` (Pinterest), `snap_cid` (Snapchat), `mc_cid` and `mc_eid` (Mailchimp), and `dclid` (Display & Video 360).
* Added nine `{clicutcl_*}` merge tags for Gravity Forms notifications and confirmations, including `{clicutcl_channel}`, `{clicutcl_click_id}`, and seven UTM-based tags.
* Added per-form attribution tracking toggle in Gravity Forms form settings, with a global default option and `clicutcl_gf_tracking_enabled` filter for developer overrides.
* Added admin QA mode: attribution data is written to `sessionStorage` only when a `manage_options` user is logged in, preventing admin browsing from polluting attribution records.
* Added `sessionStorage` fallback for attribution capture when browser cookies are blocked.
* Added minification-exclusion data attributes to ClickTrail script tags to prevent cache and optimization plugins from deferring or bundling them.
* Added entry-edit safety for Gravity Forms: `ct_*` attribution meta is excluded from the editable fields screen and restored automatically if cleared during a manual entry edit.

= 1.5.2 =
* Normalized mixed line endings in core PHP handlers to keep standards checks deterministic across environments.
* Resolved the remaining PHPCS findings in the consent, attribution-token, and privacy handlers.
* Kept runtime behavior unchanged from `1.5.1`.

= 1.5.1 =
* Aligned the public plugin version to `1.5.1` across release surfaces.
* Cleaned up public changelog wording to keep it competitor-neutral and product-focused.
* Kept runtime behavior unchanged from `1.5.0`.

= 1.5.0 =
* Declared WooCommerce HPOS compatibility during bootstrap and kept Woo order tracking on Woo order APIs.
* Enriched WooCommerce purchase payloads with additive order totals, coupon/status data, richer item detail, and customer/order metadata.
* Added opt-in WooCommerce storefront events for `view_item`, `view_item_list`, `view_cart`, `add_to_cart`, `remove_from_cart`, and `begin_checkout`, including richer product-list context.
* Added a dedicated sGTM compatibility mode with tagging-server URL support, first-party or custom-loader GTM delivery, and preview checks in the Events tab.
* Added Woo order milestone delivery for `order_paid`, `order_refunded`, and `order_cancelled`, plus Diagnostics trace lookup for stored payload snapshots.
* Added setup checklist, conflict scan, backup restore, and Woo order trace lookup in the admin surfaces.
* Added Pinterest Conversions API and TikTok Events API as first-class native delivery adapters.
* Added registry-backed QA/docs alignment for the expanded destination and diagnostics surface.
* Included the recent WordPress.org deployment cleanup, Plugin Check fixes, privacy-query hardening, and better debug visibility.

= 1.3.9 =
* Made WordPress privacy export and erasure safer by escaping `user_id` fragments used inside `LIKE`-based event matching.
* Improved large-site privacy erasure performance by deleting matched event rows in batches.
* Added lightweight caching for frequently read plugin settings.
* Stopped loading the frontend consent bridge script on pages that do not need attribution capture, consent handling, or browser events.
* Added clearer debug output for invalid attribution-token payloads and for database-level failures during privacy erasure.

= 1.3.8 =
* Added a smarter referrer fallback for visits that arrive without UTMs or click IDs.
* ClickTrail now classifies common search, social, and external referral traffic into first-touch and last-touch `source` / `medium` values.

= 1.3.7 =
* Introduced dedicated session management with a 30-minute inactivity model and separate session storage.
* Added client-side and server-side session helpers so forms, purchases, and event payloads can include consistent session information.

= 1.3.6 =
* Added native Elementor Forms support and completed the Ninja Forms submission-storage path.
* Expanded the capture schema to include newer UTM fields and browser/platform identifiers.
* Split browser event collection from browser event transport and moved frontend attribution onto the shared consent bridge.

= 1.3.5 =
* Rebuilt the main settings experience around four capability-based tabs: Capture, Forms, Events, and Delivery.
* Removed user-facing "Tracking v2" language from the main admin flow while keeping backward-compatible internal storage where needed.

Older release notes remain available in `changelog.t