=== ClickTrail – UTM, Click ID & Ad Tracking (with Consent) ===
Contributors: hugoc
Author: Vizuh
Author URI: https://vizuh.com
Tags: attribution, utm, consent mode, woocommerce, server-side tracking
Requires at least: 6.5
Tested up to: 6.9
Stable tag: 1.3.9
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Consent-aware attribution for WordPress forms, WooCommerce, and event flows. Preserves UTMs and click IDs across real user journeys.

== Description ==

ClickTrail helps WordPress sites stop losing campaign attribution before the conversion happens.

It is built for the parts of attribution that usually break in production: cached pages, dynamic forms, multi-page journeys, repeat visits, consent requirements, and optional server-side delivery.

Instead of only reading the landing-page URL and hoping the data survives, ClickTrail keeps first-touch and last-touch context available until the conversion point and makes that context usable inside WordPress.

It captures first-touch and last-touch source data, keeps it available during the user journey, and makes that data usable where conversions actually happen:

* supported forms
* WooCommerce orders
* browser events
* optional server-side delivery

That lets teams start with a simple attribution rollout and add browser events, consent handling, or server-side transport later when they actually need them.

= What problems it solves =

* **Lost UTMs and click IDs**: Visitors arrive from paid traffic, browse a few pages, and convert later. Other visitors arrive from search or social referrals without tags. ClickTrail preserves attribution instead of letting the conversion fall back to "Direct".
* **Cached or dynamic forms**: Hidden fields often break on cached pages or AJAX-rendered forms. ClickTrail includes client-side fallback and dynamic-content support.
* **Weak WooCommerce source data**: Orders can retain attribution metadata and purchase events can be enriched with campaign context.
* **Cross-domain breaks**: Approved link decoration and attribution tokens help keep continuity between domains or subdomains.
* **Consent and transport complexity**: Consent controls, browser events, webhook intake, and server-side transport live in the same plugin.

= Core capabilities =

* **Capture**: first-touch and last-touch UTMs, major ad click IDs, and referrers with automatic organic/social/referral fallback when UTMs are absent.
* **Forms**: automatic hidden-field enrichment for Contact Form 7 and Fluent Forms, compatible hidden-field population for Gravity Forms and WPForms, client-side fallback, dynamic form support, and WhatsApp attribution continuity.
* **Events**: browser event collection with `dataLayer` pushes, canonical REST intake, webhook ingestion, and lifecycle updates.
* **Delivery**: optional server-side transport, retry queue, diagnostics, consent-aware dispatch, and failure telemetry.

= What is new in 1.3.9 =

This release focuses on three things visitors and site owners usually care about most: safer privacy handling, lower runtime overhead, and clearer troubleshooting when something goes wrong.

* **Safer WordPress privacy requests**: ClickTrail now matches personal-data records more carefully during WordPress export/erase requests. Stored `user_id` fragments used in event lookups are escaped correctly, which reduces the risk of wildcard-style over-matching when a site owner erases a person’s tracking history.
* **Faster privacy cleanup on larger sites**: Privacy erasure no longer removes tracked events one row at a time. Matching event IDs are deleted in batches, which is a better fit for larger event tables and reduces unnecessary database work during compliance workflows.
* **Lower frontend and runtime overhead**: Frequently used settings are now cached, the consent bridge script only loads when attribution, consent handling, or browser events actually need it, and the bootstrap fallback no longer re-checks the same file paths on every page load.
* **Clearer debugging when a site needs answers**: Invalid attribution-token payloads can now emit a debug-only diagnostic message, and WordPress privacy erasure can surface the real database error in debug mode instead of only returning a generic failure message.
* **Cleaner internal runtime structure**: The public script enqueue flow and event-batch processing code were split into smaller helper methods, which makes future maintenance easier and reduces the chance of regressions in release work.

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

= Forms behavior by plugin =

* **Contact Form 7 and Fluent Forms**: ClickTrail can add hidden attribution fields automatically.
* **Gravity Forms and WPForms**: ClickTrail can populate matching hidden fields you add to the form.
* **Recommended for Gravity Forms and WPForms**: add the hidden fields you want to store or export, and ClickTrail will fill them.
* **Elementor Forms (Pro)**: ClickTrail uses the available submission hooks and attribution fallback, not automatic hidden-field injection.
* **Ninja Forms**: ClickTrail stores attribution with the submission and surfaces it in the submission record, not as automatic hidden-field injection.
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

= Before you configure anything =

ClickTrail can be rolled out in layers. A basic attribution setup for forms or WooCommerce does not require server-side delivery on day one.

* If you only want attribution inside forms or WooCommerce, you can leave server-side delivery disabled.
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
   * add a GTM container ID only if your site does not already inject GTM somewhere else
7. In **Delivery**:
   * leave server-side delivery off if you do not have a collector, sGTM, or advertising endpoint yet
   * if you do use server-side delivery, configure the adapter, endpoint, and timeout here
   * if consent is required, choose the correct consent source and mode before going live
8. Open **ClickTrail > Diagnostics** and run the relevant checks.

= How to verify your setup =

1. Visit your site with a test URL such as `?utm_source=test&utm_medium=cpc&utm_campaign=clicktrail-install-check`.
2. Browse to another page, then submit a supported form or place a test WooCommerce order.
3. Confirm the expected result:
   * the form entry or WooCommerce order contains attribution values
   * browser events appear in your GTM preview or `dataLayer` if **Events** is enabled
   * Diagnostics and Logs show intake or delivery activity if **Delivery** is enabled

= Good default rollout =

Start with **Capture** and the integrations you already use. Add **Events** next if you want browser analytics signals. Add **Delivery** only when you are ready to send data to a collector or advertising endpoint.

== Frequently Asked Questions ==

= Does ClickTrail replace GA4 or GTM? =

No. ClickTrail complements them. It preserves attribution inside WordPress and can push event data to the `dataLayer`. It can also deliver events through its optional server-side pipeline.

= Does it work only with WooCommerce? =

No. WooCommerce is one supported conversion surface, but ClickTrail also supports lead forms and external webhook providers.

= What happens if my site uses aggressive caching? =

ClickTrail includes a client-side fallback and dynamic-content support so attribution can still reach supported form fields when server-rendered fields are not enough.

= Do I need to add hidden fields to every form? =

No. Contact Form 7 and Fluent Forms can receive attribution hidden fields automatically. Gravity Forms and WPForms work best when you add the matching `ct_*` hidden fields you want stored or exported. Elementor Forms (Pro) and Ninja Forms use their submission hooks and stored attribution paths rather than automatic hidden-field injection.

= Where can I see the captured attribution data? =

That depends on what you enabled. Form plugins expose it in the submission or entry record. WooCommerce stores it on the order. If browser events are enabled, you can inspect GTM Preview or the `dataLayer`. If server-side delivery is enabled, use **ClickTrail > Logs** and **ClickTrail > Diagnostics**.

= Should I enter my GTM container ID if GTM already loads on my site? =

No. Use ClickTrail's GTM container setting only when your theme, plugin stack, or tag setup does not already inject GTM.

= Can I use it without server-side delivery? =

Yes. Attribution capture, form enrichment, WooCommerce order attribution, and browser event collection can still be used without enabling server-side delivery.

= Do I need to enable every ClickTrail area on day one? =

No. Most sites can start with **Capture** plus the forms or WooCommerce integrations they already use. Add **Events** when you want browser-side signals. Add **Delivery** when you are ready to send data to a collector, sGTM, or advertising endpoint.

= Is consent mode required? =

No. Consent mode is optional. When enabled, ClickTrail can gate attribution and event handling according to the configured consent behavior.

= What consent modes are supported? =

`strict`, `relaxed`, and `geo`.

= Can I keep using my existing consent platform? =

Yes. ClickTrail can listen to its own banner, Cookiebot, OneTrust, Complianz, GTM, or a custom source. You do not need to replace an existing CMP just to use the plugin.

= Is there still a "Tracking v2" screen? =

No user-facing screen uses that label anymore. The current admin UI is organized by capability. Some internal storage keys still keep that legacy name for backward compatibility.

== Screenshots ==

1. Unified ClickTrail settings organized into Capture, Forms, Events, and Delivery.
2. Capture settings for attribution retention and cross-domain continuity.
3. Forms settings for cached-page fallback, WhatsApp, and external form sources.
4. Delivery settings showing server-side transport, consent controls, and delivery health summary.

== Changelog ==

= 1.3.9 =
* Made WordPress privacy export and erasure safer by escaping `user_id` fragments used inside `LIKE`-based event matching, reducing the chance of accidental over-matching during personal-data cleanup.
* Improved large-site privacy erasure performance by deleting matched event rows in batches instead of issuing one delete query per row.
* Added lightweight caching for frequently read plugin settings so attribution checks, consent checks, token handling, and server-side delivery spend less time reloading the same options.
* Stopped loading the frontend consent bridge script on pages that do not need attribution capture, consent handling, or browser events.
* Cached the successful bootstrap fallback path for `CLICUTCL\Core\Context` so production requests do not probe the same candidate files repeatedly.
* Added clearer debug output for invalid attribution-token payloads and for database-level failures during privacy erasure.
* Continued internal cleanup by splitting public runtime config building and event-batch processing into smaller, easier-to-maintain methods.

= 1.3.8 =
* Added a smarter referrer fallback for visits that arrive without UTMs or click IDs.
* ClickTrail now classifies common search, social, and external referral traffic into first-touch and last-touch `source` / `medium` values instead of collapsing those visits into "Direct".
* Internal subdomain hops are ignored so one part of the same site does not overwrite the visitor’s real acquisition source.
* Explicit tagged campaign signals still win when UTMs or click IDs are present, so fallback attribution does not override stronger marketing data.

= 1.3.7 =
* Separated session management from attribution-signal changes by introducing a dedicated session manager with a 30-minute inactivity model.
* Session state now lives in its own cookie and localStorage store, instead of being mixed into attribution storage.
* Added client-side and server-side session helpers so forms, purchases, and event payloads can include consistent session information.
* Clearing consent or clearing ClickTrail data now removes both attribution state and session state together.

= 1.3.6 =
* Added native Elementor Forms support and completed the Ninja Forms submission-storage path so attribution is preserved more reliably across supported form plugins.
* Expanded the capture schema to include newer UTM fields and browser/platform identifiers such as `utm_id`, `utm_source_platform`, `fbc`, `fbp`, `li_gc`, and GA client/session IDs.
* Split browser event collection from browser event transport so sites can disable delivery without breaking capture, or disable capture without loading the browser events runtime.
* Moved frontend attribution logic onto the shared consent bridge so Cookiebot, OneTrust, Complianz, GTM, and custom integrations can all unblock attribution correctly when consent is granted.
* Added TTL-bound client storage behavior so attribution mirrors respect `cookie_days` and are cleared when consent is denied or revoked.

= 1.3.5 =
* Rebuilt the main settings experience around four capability-based tabs: Capture, Forms, Events, and Delivery.
* Removed user-facing "Tracking v2" language from the main admin flow while keeping backward-compatible internal storage where needed.
* Added grouped admin load/save behavior for the unified settings app.
* Improved admin copy, visual hierarchy, and operational summaries.
* Fixed settings checkbox persistence and corrected the Fluent Forms integration hook signature used on form render.

Older release notes remain available in `changelog.txt`.

== Upgrade Notice ==

= 1.3.9 =
Recommended update. This release makes WordPress privacy erasure safer and faster, reduces repeated runtime overhead, avoids loading an unnecessary frontend consent script on pages that do not need it, and makes token or database failures easier to diagnose in debug mode.
