=== ClickTrail – UTM, Click ID & Ad Tracking (with Consent) ===
Contributors: hugoc
Author: Vizuh
Author URI: https://vizuh.com
Tags: attribution, utm, consent mode, woocommerce, server-side tracking
Requires at least: 6.5
Tested up to: 6.9
Stable tag: 1.4.0
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Consent-aware attribution for WooCommerce orders, WordPress forms, and event flows. Preserve UTMs and click IDs across real user journeys, push enriched purchase events, and optionally extend Woo storefront events into ClickTrail's unified pipeline.

== Description ==

ClickTrail helps WooCommerce stores and lead-generation sites stop losing campaign context between the landing page and the conversion.

For WooCommerce specifically, ClickTrail keeps attribution attached to the order, pushes enriched purchase events on the thank-you page, and can optionally emit GA4-style storefront events for `view_item`, `add_to_cart`, `remove_from_cart`, and `begin_checkout` through the same browser event layer.

It is built for the parts of attribution that usually break in production: cached pages, dynamic forms, multi-page journeys, repeat visits, consent requirements, and optional server-side delivery.

Instead of only reading the landing-page URL and hoping the data survives, ClickTrail keeps first-touch and last-touch context available until the conversion point and makes that context usable inside WordPress.

It captures first-touch and last-touch source data, keeps it available during the user journey, and makes that data usable where conversions actually happen:

* WooCommerce orders
* supported forms
* browser events
* optional server-side delivery

That lets teams start with reliable order or form attribution first, then add browser events, consent handling, or server-side transport later when they actually need them.

= What problems it solves =

* **WooCommerce orders losing source data**: Paid traffic often ends up looking like direct traffic by the time an order is placed. ClickTrail stores attribution on the order and keeps purchase reporting tied to campaign context.
* **Checkout continuity breaking before purchase**: WooCommerce storefront journeys can now emit opt-in `view_item`, `add_to_cart`, `remove_from_cart`, and `begin_checkout` signals through the same ClickTrail event layer used elsewhere in the plugin.
* **Cached or dynamic forms**: Hidden fields often break on cached pages or AJAX-rendered forms. ClickTrail includes client-side fallback and dynamic-content support.
* **Cross-domain breaks**: Approved link decoration and attribution tokens help keep continuity between domains or subdomains.
* **Consent and transport complexity**: Consent controls, browser events, webhook intake, and server-side transport live in the same plugin.

= Core capabilities =

* **Capture**: first-touch and last-touch UTMs, major ad click IDs, and referrers with automatic organic/social/referral fallback when UTMs are absent.
* **WooCommerce**: checkout attribution persistence, thank-you purchase event push, enriched commerce payloads, and optional storefront commerce events.
* **Forms**: automatic hidden-field enrichment for Contact Form 7 and Fluent Forms, compatible hidden-field population for Gravity Forms and WPForms, client-side fallback, dynamic form support, and WhatsApp attribution continuity.
* **Events**: browser event collection with `dataLayer` pushes, canonical REST intake, webhook ingestion, lifecycle updates, one-time WordPress follow-up events such as `login`, `sign_up`, and `comment_submit`, and optional WooCommerce storefront events.
* **Delivery**: optional server-side transport, retry queue, diagnostics, consent-aware dispatch, and failure telemetry.

= What is new in 1.4.0 =

This release makes ClickTrail more WooCommerce-focused without changing the underlying architecture:

* **WooCommerce HPOS compatibility declaration**: ClickTrail now declares compatibility with WooCommerce custom order tables during bootstrap and keeps order-level attribution logic on Woo APIs.
* **Richer purchase payloads**: purchase events now include additive commerce fields such as `subtotal`, `tax_total`, `shipping_total`, `discount_total`, `discount_codes`, `status`, `order_currency`, `item_quantity`, plus richer item detail such as `product_id`, `sku`, `variant`, and `categories`.
* **Optional Woo storefront events**: sites can opt in to `view_item`, `add_to_cart`, `remove_from_cart`, and `begin_checkout` through the existing ClickTrail browser event layer. Existing installs keep this off until enabled.
* **Clearer WooCommerce guidance in Settings**: the Events tab now explains where Woo attribution is stored, how purchase events flow, how storefront events work, and where to verify them.
* **Deployment and privacy hardening**: this release also packages the recent WordPress.org deployment cleanup, Plugin Check fixes, privacy-query hardening, and debug visibility improvements.

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
* **Server-side adapters**: Generic collector, sGTM, Meta CAPI, Google Ads / GA4, LinkedIn CAPI

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
   * enable **WooCommerce storefront events** only if you want `view_item`, `add_to_cart`, `remove_from_cart`, and `begin_checkout` in the browser event layer
   * add a GTM container ID only if your site does not already inject GTM somewhere else
7. In **Delivery**:
   * leave server-side delivery off if you do not have a collector, sGTM, or advertising endpoint yet
   * if you do use server-side delivery, configure the adapter, endpoint, and timeout here
   * if consent is required, choose the correct consent source and mode before going live
8. Open **ClickTrail > Diagnostics** and run the relevant checks.

= How to verify your setup =

1. Visit your site with a test URL such as `?utm_source=test&utm_medium=cpc&utm_campaign=clicktrail-install-check`.
2. Browse to another page, then place a test WooCommerce order or submit a supported form.
3. Confirm the expected result:
   * the WooCommerce order or form entry contains attribution values
   * Woo purchase events appear in your GTM preview or `dataLayer`
   * if Woo storefront events are enabled, `view_item`, `add_to_cart`, `remove_from_cart`, and `begin_checkout` appear in GTM preview or the `dataLayer`
   * Diagnostics and Logs show intake or delivery activity if **Delivery** is enabled

= Good default rollout =

Start with **Capture** plus the forms or WooCommerce integrations you already use. Add **Events** next if you want browser analytics signals. Add **Delivery** only when you are ready to send data to a collector or advertising endpoint.

== Frequently Asked Questions ==

= Where does WooCommerce attribution appear? =

ClickTrail stores attribution on the WooCommerce order. The plugin also adds Woo attribution views inside the Woo admin experience where supported, and purchase events carry the same campaign context into the `dataLayer` and optional server-side delivery.

= Does ClickTrail support WooCommerce HPOS? =

ClickTrail now declares compatibility with WooCommerce custom order tables (HPOS) and keeps WooCommerce runtime storage on Woo order APIs for order attribution and purchase tracking.

= What do the WooCommerce storefront events do? =

When you enable **WooCommerce storefront events** in the Events tab, ClickTrail emits `view_item`, `add_to_cart`, `remove_from_cart`, and `begin_checkout` through the same browser event layer used for other ClickTrail events. They are off by default on upgrades.

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

= 1.4.0 =
* Declared WooCommerce HPOS compatibility during bootstrap and kept WooCommerce order tracking on Woo order APIs.
* Enriched WooCommerce purchase payloads with additive order totals, coupon/status data, richer item detail, and customer/order metadata.
* Added opt-in WooCommerce storefront events for `view_item`, `add_to_cart`, `remove_from_cart`, and `begin_checkout` through the existing browser event pipeline.
* Added clearer WooCommerce guidance to the Events tab in the unified settings app.
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

Older release notes remain available in `changelog.txt`.

== Upgrade Notice ==

= 1.4.0 =
Recommended update. This release makes ClickTrail more WooCommerce-focused without breaking the existing architecture: HPOS compatibility is declared, Woo purchase payloads are richer, storefront events are available as an opt-in setting, and the recent privacy/deployment fixes are included in the same build.
