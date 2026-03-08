=== ClickTrail - UTM, Click ID & Ad Tracking (with Consent) ===
Contributors: hugoc
Author: Vizuh
Author URI: https://vizuh.com
Tags: attribution, utm, consent mode, woocommerce, server-side tracking
Requires at least: 6.5
Tested up to: 6.9
Stable tag: 1.3.5
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Consent-aware attribution for WordPress and WooCommerce. Keep UTMs and click IDs attached to forms, orders, and events across cached pages, dynamic forms, and multi-step journeys.

== Description ==

ClickTrail helps WordPress sites stop losing campaign attribution before the conversion happens.

It captures first-touch and last-touch source data, keeps it available during the user journey, and makes that data usable where conversions actually happen:

* supported forms
* WooCommerce orders
* browser events
* optional server-side delivery

= What problems it solves =

* **Lost UTMs and click IDs**: Visitors arrive from paid traffic, browse a few pages, and convert later. ClickTrail preserves attribution instead of letting the conversion fall back to "Direct".
* **Cached or dynamic forms**: Hidden fields often break on cached pages or AJAX-rendered forms. ClickTrail includes client-side fallback and dynamic-content support.
* **Weak WooCommerce source data**: Orders can retain attribution metadata and purchase events can be enriched with campaign context.
* **Cross-domain breaks**: Approved link decoration and attribution tokens help keep continuity between domains or subdomains.
* **Consent and transport complexity**: Consent controls, browser events, webhook intake, and server-side transport live in the same plugin.

= Core capabilities =

* **Capture**: first-touch and last-touch UTMs, referrers, and major ad click IDs with configurable retention.
* **Forms**: hidden field enrichment for supported form plugins, client-side fallback, dynamic form support, and WhatsApp attribution continuity.
* **Events**: browser event collection with `dataLayer` pushes, canonical REST intake, webhook ingestion, and lifecycle updates.
* **Delivery**: optional server-side transport, retry queue, diagnostics, consent-aware dispatch, and failure telemetry.

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

1. Upload the plugin to `/wp-content/plugins/click-trail-handler/` or install it through WordPress.
2. Activate the plugin.
3. Open **ClickTrail > Settings**.
4. Configure the capabilities you use:
   * **Capture** for attribution basics and cross-domain continuity
   * **Forms** for form enrichment and WhatsApp behavior
   * **Events** for browser events, GTM, and destinations
   * **Delivery** for server-side transport and consent controls
5. Use **ClickTrail > Diagnostics** to validate the endpoint, queue health, and recent delivery state.

== Frequently Asked Questions ==

= Does ClickTrail replace GA4 or GTM? =

No. ClickTrail complements them. It preserves attribution inside WordPress and can push event data to the `dataLayer`. It can also deliver events through its optional server-side pipeline.

= Does it work only with WooCommerce? =

No. WooCommerce is one supported conversion surface, but ClickTrail also supports lead forms and external webhook providers.

= What happens if my site uses aggressive caching? =

ClickTrail includes a client-side fallback and dynamic-content support so attribution can still reach supported form fields when server-rendered fields are not enough.

= Can I use it without server-side delivery? =

Yes. Attribution capture, form enrichment, WooCommerce order attribution, and browser event collection can still be used without enabling server-side delivery.

= Is consent mode required? =

No. Consent mode is optional. When enabled, ClickTrail can gate attribution and event handling according to the configured consent behavior.

= What consent modes are supported? =

`strict`, `relaxed`, and `geo`.

= Is there still a "Tracking v2" screen? =

No user-facing screen uses that label anymore. The current admin UI is organized by capability. Some internal storage keys still keep that legacy name for backward compatibility.

== Screenshots ==

1. Unified ClickTrail settings organized into Capture, Forms, Events, and Delivery.
2. Capture settings for attribution retention and cross-domain continuity.
3. Forms settings for cached-page fallback, WhatsApp, and external form sources.
4. Delivery settings showing server-side transport, consent controls, and delivery health summary.

== Changelog ==

= 1.3.5 =
* Unified the main settings experience into capability-based tabs: Capture, Forms, Events, and Delivery.
* Removed user-facing "Tracking v2" terminology from the main admin flow while keeping backward-compatible internal storage.
* Added grouped admin load/save endpoints for the unified settings app.
* Improved admin copy, visual hierarchy, and operational summaries.
* Fixed checkbox persistence issues in the settings screen.
* Fixed the Fluent Forms integration hook signature for `fluentform_form_element_start`.

= 1.3.2 =
* Security hardening for signed-token authorization and trusted-proxy-aware request handling.
* Reduced diagnostics hot-path overhead and tightened WhatsApp ingestion behavior.
* Scoped admin asset loading to relevant screens.

= 1.3.1 =
* Added conditional loading for the browser events script.
* Hardened WooCommerce boot checks.

= 1.3.0 =
* Added client-side form field injection for cached pages.
* Added cross-domain link decoration.
* Added site health diagnostics and dashboard status surfaces.

Older release notes remain available in `changelog.txt`.

== Upgrade Notice ==

= 1.3.5 =
Recommended update. The admin experience is now organized by capability, old user-facing "Tracking v2" language is gone, grouped settings saves are more reliable, and the Fluent Forms adapter hook was corrected.
