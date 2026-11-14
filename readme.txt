=== ClickTrail – UTM Tracking & Lead Source for Forms & WooCommerce ===
Contributors: hugoc
Author: Vizuh
Author URI: https://vizuh.com
Tags: utm tracking, gclid, lead source, attribution, woocommerce
Requires at least: 6.5
Tested up to: 7.1
Stable tag: 1.14.0
Requires PHP: 8.1
WC requires at least: 10.4.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Saves UTMs and ad click IDs (gclid, fbclid) to every form entry and WooCommerce order. First and last touch, cache-safe, consent-aware.

== Description ==

Someone clicks your ad. Three pages later, they send a form or place an order. By then the campaign is usually gone, and the lead looks like it came from nowhere.

ClickTrail keeps what the visitor arrived with: the UTMs, the ad click ID, the referrer. It carries that context to the form entry or the WooCommerce order, and to a Calendly booking. You open the record and see where it came from.

Works with Contact Form 7, Gravity Forms, WPForms, Elementor Forms, Fluent Forms, Ninja Forms, Formidable Forms, Forminator, MetForm, SureForms, WooCommerce, Easy Digital Downloads, Ultimate Member, Calendly, HubSpot and Typeform. Consent-aware: nothing is stored until the visitor agrees, when your settings require it.

= Start here =

Pick the path that matches your site.

* **WooCommerce store**: turn on Capture and WooCommerce. Orders carry the campaign. Add storefront events later if you want funnel signals.
* **Lead forms**: turn on Capture and Forms, then add the `ct_*` fields you want stored (Fluent Forms gets them on its own). The field setup for each form plugin is under Installation.
* **GTM or server-side GTM**: turn on Capture, Events, and Delivery. Events go to the `dataLayer`, and ClickTrail can send canonical JSON to an endpoint you set.

Then place one test order or send one test form, and look at what was stored. The [use-case guide](https://github.com/vizuh/click-trail-handler/blob/main/docs/guides/USE-CASES.md) and [tutorials](https://github.com/vizuh/click-trail-handler/blob/main/docs/tutorials/README.md) walk through each path.

= What it does =

ClickTrail only acts on the paths you turn on and set up in its settings. That is what "configured" means everywhere in this readme.

* Capture: first-touch and last-touch UTMs, ad click IDs, and the referrer, with organic, social, and referral fallback when there are no UTMs. When a visitor comes back through a new ad, the newest click is the one that travels. Context lives in first-party storage, falls back to `sessionStorage` when cookies are blocked, and your own admin browsing stays out of it.
* WooCommerce: attribution saved on the order before an off-site payment redirect (classic and block checkout), with returns from payment hosts such as PayPal or Stripe never overwriting the stored touch, an enriched purchase event on the thank-you page, optional storefront events (`view_item`, `view_item_list`, `view_cart`, `add_to_cart`, `remove_from_cart`, `begin_checkout`), and optional order-status milestones. Order metadata has export, erase, retention, and uninstall handling.
* Easy Digital Downloads (3.0+): attribution saved on the order when it is created, which is before an off-site payment such as Stripe or PayPal, and one `purchase` pushed from the order confirmation receipt with `event_id` `purchase_edd_<order ID>`. Reloading the receipt does not push it again. Nothing is stored or pushed without the consent your settings require. Order metadata has export, erase, retention, and uninstall handling.
* Ultimate Member: a registration through an Ultimate Member form stores the visitor's attribution on the new user (`_clicutcl_*` user meta, only with marketing consent) and sends one `sign_up` event. Users registered from wp-admin get nothing. The meta has export, erase, retention and uninstall handling.
* Forms: Fluent Forms gets hidden fields automatically; Contact Form 7 fills the `ct_*` tags you reference (`[hidden ct_gclid]` or `[ct_gclid]` in the mail). Gravity Forms, WPForms, Formidable Forms and Forminator fill the `ct_*` fields you add; Gravity Forms also gets a channel label, nine `{clicutcl_*}` merge tags, and a per-form switch. Elementor Forms (Pro) and Ninja Forms keep attribution with the submission record. The server renders those fields empty so a page cache never serves one visitor's attribution to another (the `clicutcl_render_server_attribution_values` filter opts back in on sites without a cache); the browser fills them after load, the submit-time cookie corrects them, and keys the cookie lacks are blanked rather than kept. Cached form pages need no exclusion. A client-side fallback covers cached pages and forms that load late, and WhatsApp links can carry the context too.
* WhatsApp: a click on a `wa.me`, `api.whatsapp.com` or `whatsapp://` link pushes one `contact_whatsapp_start` event (with consent). With **Append attribution to message** on (off by default) the pre-filled text ends with a short `Ref: CT-XXXXXXXX` code instead of the attribution payload; an administrator pastes it into Diagnostics to see the stored first/last touch and click ID.
* Bookings and your other domains: links to domains you approve carry the campaign, with a signed token between your own sites. Calendly booking links hold the newest click and the visitor's consent decision, and the signed Calendly webhook records the booking as `book_appointment` with its UTMs. Booking or form iframes your own script loads can carry the campaign too, through `window.ClickTrail.decorateEmbedUrl()`, for hosts you list.
* Events and delivery: browser events to the `dataLayer`, a REST intake, webhooks from Calendly, HubSpot, and Typeform, lifecycle updates, and one-time WordPress events such as `login`, `sign_up`, and `comment_submit`. Optional server-side delivery adds a retry queue and diagnostics.

= Consent and privacy =

When consent is required, nothing is stored until the visitor agrees. ClickTrail listens to its own banner, Cookiebot, OneTrust, Complianz, GTM, or a custom source, or detects the source for you. ClickTrail follows Cookiebot and Complianz decisions, including changes the visitor makes on the page. On withdrawal, ClickTrail clears its storage and removes the parameters it added to links. Queued deliveries check current consent again right before they are sent, and the consent decision stays in sync across tabs. Consent behavior can be strict, relaxed, or geo-based. ClickTrail helps you implement consent; compliance still depends on your legal requirements and your settings.

= What it does not do =

* It does not prove which click caused a sale, and it does not decide which channel deserves the revenue.
* It does not resolve one person's identity across devices.
* It is not an attribution dashboard, a hosted server-side GTM platform, a lead manager, or an ad optimizer. It works alongside GA4 and GTM. For call tracking, lead scoring, or ad-spend optimization, pair it with a specialist tool.
* It does not install ad pixels. The Meta Pixel, Google tag, TikTok Pixel, LinkedIn Insight Tag, Pinterest Tag, and Reddit Pixel stay yours, usually through GTM.
* Server-side delivery sends JSON to the endpoint you configure. The adapters named after Meta, Google Ads / GA4, LinkedIn, Pinterest, and TikTok exist in the code, but we have not verified that any provider accepts them end to end. Reddit is a relay-only destination.
* We have not yet verified a live Calendly booking round trip, Easy Digital Downloads with the Stripe or PayPal gateways (only the Store gateway was run), or Ultimate Member beyond one pinned test run.

Having code for an integration is not proof that it works in production. Current status lives in the [integration reference](https://github.com/vizuh/click-trail-handler/blob/main/docs/reference/INTEGRATIONS.md) and the [machine-readable ledger](https://github.com/vizuh/click-trail-handler/blob/main/docs/reference/integration-capabilities.json). The [release-phasing plan](https://github.com/vizuh/click-trail-handler/blob/main/docs/guides/RELEASE-PHASING-AND-INTEGRATION-DOCS.md) shows what gets checked next.

= Supported click IDs =

* Google: `gclid`, `wbraid`, `gbraid`
* Meta: `fbclid`
* TikTok: `ttclid`
* Microsoft: `msclkid`
* X / Twitter: `twclid`
* LinkedIn: `li_fat_id`
* Snapchat: `sccid`, `snap_cid`
* Pinterest: `epik`, `pin_cid`
* Reddit: `rdt_cid`
* Mailchimp: `mc_cid`, `mc_eid`
* Display & Video 360: `dclid`

Paid markers are captured as touch fields too, but are never the selected click ID: `gad_source` and `gad_campaignid` (Google Ads without a `gclid`, labelled `Google Ads`) and `oppref` (labelled `ChatGPT Ads`).

= Integrations =

* **Forms**: Contact Form 7, Elementor Forms (Pro), Fluent Forms, Formidable Forms, Forminator, Gravity Forms, MetForm, Ninja Forms, SureForms, WPForms
* **Commerce**: WooCommerce, Easy Digital Downloads (3.0+)
* **Registration**: Ultimate Member
* **Consent sources**: ClickTrail banner, Cookiebot, OneTrust, Complianz, GTM, custom
* **Webhooks**: Calendly, HubSpot, Typeform
* **Server-side adapter keys**: generic collector, sGTM, Meta CAPI, Google Ads / GA4, LinkedIn CAPI, Pinterest Conversions API, TikTok Events API (status above)
* **Extra fields captured**: `utm_id`, `utm_source_platform`, `utm_creative_format`, `utm_marketing_tactic`, `fbc`, `fbp`, `_ttp`, `li_gc`, `ga_client_id`, `ga_session_id`
* **Caching and minification**: on every site, the four ClickTrail script tags (`clicutcl-consent-bridge-js`, `clicutcl-attribution-js`, `clicutcl-consent-js`, `clicutcl-events-js`) carry `nowprocket data-no-optimize="1" data-no-defer="1" data-noptimize="1" data-cfasync="false"`: `nowprocket` (WP Rocket Delay JS), `data-no-optimize="1"` and `data-no-defer="1"` (LiteSpeed Cache), `data-noptimize="1"` (Autoptimize), `data-cfasync="false"` (Cloudflare Rocket Loader). The same scripts are registered on the exclusion filters of WP Rocket (`rocket_delay_js_exclusions`, `rocket_exclude_defer_js`, `rocket_exclude_js`), LiteSpeed Cache (`litespeed_optimize_js_excludes`, `litespeed_optm_js_defer_exc`, `litespeed_optm_gm_js_exc`), Autoptimize (`autoptimize_filter_js_exclude`), Perfmatters (`perfmatters_delay_js_exclusions`, `perfmatters_defer_js_exclusions`, `perfmatters_minify_js_exclusions`), SiteGround Speed Optimizer (`sgo_js_minify_exclude`, `sgo_javascript_combine_exclude`, `sgo_js_async_exclude`), and FlyingPress JS minification (`flying_press_exclude_from_minify:js`); NitroPack adds `data-nitropack-exclude="true"` and `nitropack_js_url_exclude` when active. The inline consent-default snippet carries `nowprocket data-no-defer="1"`. To check: view the page source, confirm every `clicutcl-*.js` tag carries the attributes, and confirm `window.ClickTrail` is defined before submitting a form. Delay-JS features run scripts on the first interaction (WP Rocket lists `keydown`, `mousemove`, `touchstart`, `scroll`, `click`, `input`, `onsubmit`), so a keyboard-only submit still runs them, too late for the landing touch. FlyingPress documents no attribute and no delay or defer filter, only the minification filter above: add `clicutcl` and `clicktrail-consent-mode` to its exclusion field. Source-present / runtime-unverified.

== Installation ==

= Before you configure anything =

ClickTrail can be rolled out in layers. A basic attribution setup for forms or WooCommerce does not require server-side delivery on day one.

* If you only want attribution inside WooCommerce or forms, you can leave server-side delivery disabled.
* If your site already loads Google Tag Manager, do not add the GTM container ID again in ClickTrail.
* If you use Gravity Forms, WPForms or Formidable Forms, add the `ct_*` hidden fields you want stored or exported before testing.
* If your site has consent requirements, decide whether ClickTrail or your existing CMP should be the consent source.

= Recommended first setup =

1. Install the plugin through WordPress or upload it to `/wp-content/plugins/click-trail-handler/`.
2. Activate the plugin.
3. Open **ClickTrail > Settings**.
4. In **Capture**:
   * keep attribution enabled
   * choose a retention window that matches your sales cycle
   * set Cookie scope to Registrable domain if visitors move between www, the bare domain and other subdomains of your site (host only keeps cookies on one exact host)
   * leave **Re-issue the attribution cookie from the server** on (default on new installs, off on upgraded ones) so Safari does not drop the attribution cookie after a day or a week; your cache or firewall must not block or cache `POST /wp-json/clicutcl/v2/cookie-refresh`
   * enable cross-domain continuity only if visitors move between approved domains or subdomains
5. In **Forms**:
   * enable only the integrations you actually use
   * for Fluent Forms, ClickTrail can add attribution hidden fields automatically; for Contact Form 7, it fills the `ct_*` tags the form or mail references
   * for Gravity Forms, WPForms, Formidable Forms, Forminator and Elementor Forms (Pro), add the matching `ct_*` hidden fields you want to preserve, such as `ct_ft_source`, `ct_lt_source`, or `ct_gclid` (latest click; use `ct_ft_gclid` or `ct_lt_gclid` to pin a touch). Gravity Forms reads the parameter name, WPForms and Forminator a CSS class (or a label) of `ct_<key>`, Formidable Forms the Field Key (or a label) of `ct_<key>`, Elementor the field ID
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
2. Browse to another page, then place a test WooCommerce order or submit a configured form.
3. Confirm the expected result:
   * the WooCommerce order or form entry contains attribution values
   * Woo purchase events appear in your GTM preview or `dataLayer`
   * if Woo storefront events are enabled, `view_item`, `view_item_list`, `view_cart`, `add_to_cart`, `remove_from_cart`, and `begin_checkout` appear in GTM preview or the `dataLayer`
   * if sGTM mode is enabled, the Events-tab preview checks reach the configured loader or collector URLs
   * Diagnostics and Logs show intake or delivery activity if **Delivery** is enabled

= Good default rollout =

Start with **Capture** plus the forms or WooCommerce integrations you already use. Add **Events** next if you want browser analytics signals. Add **Delivery** only when you are ready to send data to a collector or advertising endpoint.

= Form fields =

Fluent Forms gets hidden fields on its own; Contact Form 7 fills the `ct_*` tags you reference (`[hidden ct_gclid]` in the form or `[ct_gclid]` in the mail). Gravity Forms, WPForms, Formidable Forms, Forminator and MetForm fill the `ct_*` fields you add (WPForms Lite has no Hidden field: use a Single Line Text field with the CSS class `ct_<key>` and hide it with CSS; Formidable Forms: a Hidden field whose Field Key is `ct_<key>`; Forminator: a Hidden field with the custom CSS class `ct_<key>`; in MetForm, name a hidden input `ct_<key>`).

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

No. WooCommerce is one documented conversion surface; ClickTrail also has lead-form, webhook-ingress, and broader WordPress attribution paths.

= What happens if my site uses aggressive caching? =

ClickTrail renders form attribution fields empty on the server, so cached form pages carry no visitor's data and do not need to be excluded from the page cache; its client-side script fills the visitor's own values after load, and the submit-time cookie corrects them. Minify, combine, defer and delay-JS features are handled too: every ClickTrail script tag carries `nowprocket data-no-optimize="1" data-no-defer="1" data-noptimize="1" data-cfasync="false"` and the scripts are listed on the exclusion filters of WP Rocket (`rocket_delay_js_exclusions`, `rocket_exclude_defer_js`, `rocket_exclude_js`), LiteSpeed Cache (`litespeed_optimize_js_excludes`, `litespeed_optm_js_defer_exc`, `litespeed_optm_gm_js_exc`), Autoptimize (`autoptimize_filter_js_exclude`), Perfmatters (`perfmatters_delay_js_exclusions`, `perfmatters_defer_js_exclusions`, `perfmatters_minify_js_exclusions`), SiteGround Speed Optimizer (`sgo_js_minify_exclude`, `sgo_javascript_combine_exclude`, `sgo_js_async_exclude`), and FlyingPress JS minification (`flying_press_exclude_from_minify:js`). To check: view the page source, confirm every `clicutcl-*.js` tag carries the attributes, and confirm `window.ClickTrail` is defined before submitting a form. Delay-JS features run scripts on the first interaction (WP Rocket lists `keydown`, `mousemove`, `touchstart`, `scroll`, `click`, `input`, `onsubmit`), so a keyboard-only submit still runs them, too late for the landing touch. FlyingPress documents no attribute and no delay or defer filter, only the minification filter above: add `clicutcl` and `clicktrail-consent-mode` to its exclusion field. Source-present / runtime-unverified.

= Do visitors with JavaScript disabled or blocked get attribution? =

No. Only the browser script writes the attribution cookie, and no PHP code reads UTMs or click IDs from the URL, so a lead from a visitor with JavaScript disabled or blocked (strict blockers, some corporate proxies) has no attribution, unless an earlier visit with JavaScript left the cookie. ClickTrail renders form fields empty on the server so cached pages never carry one visitor's values to another (#152); the trade-off is that no-JS visitors are not covered. The opt-in filter `clicutcl_render_server_attribution_values` does not change that: it only writes the values of an existing cookie into form HTML, on sites without a page cache, and never captures anything from the URL.

= Do I need to add hidden fields to every form? =

No. Fluent Forms can receive attribution hidden fields automatically; Contact Form 7 renders a field only for each `ct_*` tag the form or its mail references (`[hidden ct_gclid]`, `[ct_gclid]`). Gravity Forms, WPForms, Formidable Forms, Forminator and Elementor Forms (Pro) work best when you add the matching `ct_*` hidden fields you want stored or exported: Gravity Forms matches the parameter name, WPForms a CSS class `ct_<key>` (a Hidden field needs WPForms Pro; on Lite use a Single Line Text field hidden with CSS; a label of exactly `ct_<key>` also works), Formidable Forms the Field Key of a Hidden field (`ct_<key>`; a label of exactly `ct_<key>` also works), Forminator a Hidden field with the custom CSS class `ct_<key>`, Elementor the hidden field's ID. Every adapter refreshes those fields from the cookie sent with the submit, so a form submitted on the landing page stores that visit's click. Elementor Forms (Pro) and Ninja Forms also keep attribution with the submission record.

= Can I use it without server-side delivery? =

Yes. Attribution capture, WooCommerce order attribution, purchase event pushes, and form enrichment all work without enabling server-side delivery.

= Is consent mode required? =

Consent mode is optional in configuration. When enabled, ClickTrail applies the configured behavior; the current audit found a legacy `require_consent` mismatch when mode is disabled, so optional mode is not a compliance or delivery guarantee until the next runtime release passes its tests.

= Can I keep using my existing consent platform? =

Yes. ClickTrail can listen to its own banner, Cookiebot, OneTrust, Complianz, GTM, or a custom source. You do not need to replace an existing CMP just to use the plugin.

== Screenshots ==

1. Settings overview: setup checklist, status summary, and capture controls.
2. Forms: client-side capture for cached and dynamic pages, WhatsApp, and external form sources.
3. Events: browser event collection and Google Tag Manager loading options.
4. Delivery: optional server-side transport and privacy and consent controls.
5. Diagnostics: queue, dispatch, and error status with endpoint test and conflict scan.

== Changelog ==

= 1.14.0 =
* Fix: a new visit now replaces the whole last touch. Before, a Meta or organic visit after a Google Ads click kept the earlier campaign, source and `gclid` in the last touch, so leads and orders mixed two campaigns. First touch and the newest value of each click ID are unchanged. (#230)
* New: `gad_source`, `gad_campaignid` and `oppref` are captured. A Google Ads visit whose `gclid` was stripped (Safari link protection, Brave, ad blockers) is labelled Google Ads instead of Google Organic, and ChatGPT Ads (`oppref`) is labelled separately from unpaid ChatGPT referrals. They reach `ct_*` form fields and order meta, and are never chosen as the click ID for conversion upload. Visible changes: a Google Ads click without UTMs (`gclid`, `gbraid`, `wbraid`) now stores medium `cpc` instead of an empty medium; an `oppref` visit stores source `chatgpt`; a `gad_source` landing no longer records `google` as source or the Google referrer. (#231)
* Privacy: query parameters such as email, phone, token, password, key, code and session are removed from the stored landing page and referrer (cookie, form fields, order meta and browser events); UTMs and click IDs are kept. Names ending in one of these words are removed too (for example `promo_code`, `coupon_code`, `user_email`, `gift_card`), and the `#fragment` is always dropped; use the filter `clicutcl_sensitive_query_keys` to keep a parameter your reports need. (#232)
* New: Site Health and Diagnostics warn when FlyingPress is active and list the Delay JavaScript exclusion keywords to add. (#165)
* Docs: visitors with JavaScript disabled or blocked get no attribution (fields render empty for cache safety and the cookie is written by the browser); `clicutcl_render_server_attribution_values` only renders an existing cookie. (#233)

= 1.13.1 =
* Security: follow-up login, sign-up and comment event cookies are HMAC-signed and limited to their own event; pages that print one are sent no-cache headers.
* Security: Calendly webhook bookings no longer send or queue the invitee's raw email. With a `c=1` consent stamp only a SHA-256 hash is sent; without one (including on sites where consent is not required) the booking carries no email at all.
* Fix: WooCommerce order milestones, refunds and cancellations triggered from wp-admin, REST, cron, WP-CLI or cookieless gateway callbacks follow the buyer's checkout consent snapshot, not the consent cookie of whoever triggered the request.
* Fix: WooCommerce orders store only allowlisted attribution keys from the cookie, and order milestone markers survive retention, privacy erasure and the Diagnostics purge, so `order_paid`, `order_refunded` and `order_cancelled` are not re-sent after them.
* Fix: browser events, token signing and WhatsApp references are no longer dropped after 20 requests per cached page and IP. Default caps are now 120 browser-event and 30 sign/WhatsApp-reference requests per token, IP and session per hour, and the Delivery > Advanced field is relabelled "Maximum requests per token and session (hourly, 0 = default)" (0 always meant the default). Gate rejections are counted in failure telemetry.
* Fix: Save settings no longer corrupts secrets, selectors and URLs (percent-escapes, angle brackets, spaces, backslashes) and rejects an undecodable payload with an error instead of saving an empty configuration; backup import no longer strips backslashes.
* Fix: server-side delivery has a circuit breaker (a down collector no longer slows every request), webhook and lifecycle routes answer 200 with `queued: true` instead of 500 when an event is queued for retry, the retry queue drains in pages within a 45 s budget instead of 10 rows per run, and expired pending rows and consent stalls show in failure telemetry.
* Fix: the retention cleanup cron re-schedules itself when missing (including network-activated sites), and uninstall removes every ClickTrail option, table and scheduled task on every site of a network.
* Performance: dedup diagnostics counters are buffered per request and written at most every 10 seconds (on busy sites the Diagnostics hit/miss numbers are a sample); touch-event inserts no longer probe the table on every event.

= 1.13.0 =
* Fixed: two simultaneous submissions or purchase notifications could both be recorded on sites without a persistent object cache.
* Important: if you use Google Tag Manager, ClickTrail's dataLayer pushes no longer carry `session_id`, `client_id`, `user_id`, `session_number`, `engagement_time_msec`, `timestamp_micros`, `page_location` and `page_referrer` under those names. They are now sent as `ct_session_id`, `ct_client_id` and so on, and the page view event is `ct_page_view`. Custom GTM variables that read the old names must be updated; the bundled starter kit already is.
* Important: Contact Form 7 only gets hidden fields for `ct_` tags that the form or its mail references. Add `[hidden ct_gclid]` to the form to get the value into mail and CRM add-ons. ClickTrail's own record is unaffected.
* Important: attribution fields rendered by the server are now empty and are filled in the visitor's browser, so page caches can no longer serve one visitor's campaign to another.
* Important: new installs default to cookie scope "registrable" (the cookie is shared across subdomains, including staging sites) and cookie re-issue on. Existing installs keep their current behaviour.
* Important: behind a trusted proxy, the visitor IP is now taken from the rightmost untrusted `X-Forwarded-For` hop. Sites reachable only through Cloudflare can opt in to `CF-Connecting-IP` with the filter `clicutcl_trust_cf_connecting_ip`.
* Important: the WooCommerce and Easy Digital Downloads thank-you page no longer pushes a purchase for orders older than the data retention window.
* New: Easy Digital Downloads orders now carry attribution and send a purchase event.
* New: Ultimate Member registrations now carry attribution and send a sign-up event, with privacy export, erase and retention support.
* New: Formidable Forms, Forminator, SureForms and MetForm are supported: add a hidden field named `ct_<key>`, for example `ct_gclid`.
* New: clicks on WhatsApp links send a `contact_whatsapp_start` event. The optional "Append attribution to message" setting (still off by default) now adds a short reference code instead of a long encoded block.
* New: cookie scope setting (this host only, shared across subdomains, or a custom domain) on the Capture tab.
* Safari: the attribution cookie is re-issued after Safari's tracking prevention shortens it.
* Fix: Google Analytics 4 session cookies in the newer GS2 format are now read correctly.
* Fix: WooCommerce privacy erase and retention cleanup now work on stores using High-Performance Order Storage.
* Fix: no cookie is set on a visitor's first page view, so CDNs can keep caching the page.
* Security: WhatsApp reference requests are rate limited per visitor address, the server-side GTM test only fetches safe URLs, values posted by forms are length-capped, and leftover form locks are cleaned up.
* Privacy: the personal data exporter and eraser now match an email or user exactly instead of by partial text.
* Forms: simultaneous duplicate form submissions are caught even across a time boundary.

Older releases: see the [full changelog](https://github.com/vizuh/click-trail-handler/blob/main/changelog.txt). The entries below are historical release notes. They do not replace the current source/evidence status in `docs/reference/INTEGRATIONS.md` and `docs/guides/SECURITY-PRIVACY.md`; earlier claims may describe intended behavior that still requires current runtime verification. In particular, older wording that calls platform-named server paths “first-class native delivery adapters” predates the current evidence classification: those paths are configured-endpoint relays whose provider authentication, acceptance, and runtime delivery remain unverified.

== Upgrade Notice ==

= 1.14.0 =
A new visit replaces the whole last touch. Google Ads clicks without UTMs now store medium cpc, gad_source and oppref are captured, and email, phone, token and similar parameters are removed from stored URLs.
