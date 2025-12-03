=== ClickTrail – UTM, Click ID & Ad Tracking (with Consent) ===
Contributors: vizuh
Tags: attribution, utm, tracking, consent mode, gtm
Requires at least: 5.0
Tested up to: 6.9
Stable tag: 1.1.0
Requires PHP: 7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Complete consent management and marketing attribution solution. Captures UTMs and click IDs, manages consent, and tracks across forms and WooCommerce.

== Description ==

ClickTrail captures first- and last-touch UTMs and click IDs, stores them in cookies (with user consent when required), and automatically attaches that data to your form entries and WooCommerce orders. Built-in consent banner with Google Consent Mode integration ensures your tracking stays compliant with GDPR and other privacy regulations.

**Key Benefits**

*   **See the real source of every lead and order**: First-touch and last-touch UTMs + click IDs are persisted for up to 90 days and injected into supported forms and Woo orders automatically.
*   **WooCommerce admin insights**: New "Source" column in orders list shows attribution at a glance. View full first-touch and last-touch attribution in order details.
*   **Make GA4 & Meta tracking actually useful**: ClickTrail pushes enriched, GA4-ready purchase events from WooCommerce thank-you pages, with campaign data and line items included.
*   **Multi-platform click ID support**: Captures click IDs from Google (gclid, wbraid, gbraid), Meta (fbclid), TikTok (ttclid), Microsoft (msclkid), Twitter (twclid), LinkedIn (li_fat_id), Snapchat (ScCid), and Pinterest (epik).
*   **Stay privacy-aware without losing all signal**: A built-in consent banner and Consent Mode defaults let you block or allow tracking based on strict, relaxed, or geo-based rules.

**Admin & Configuration**

ClickTrail adds a “Attribution & Consent Settings” page under its own admin menu. From there, you can:

*   Turn attribution capture on/off.
*   Set cookie duration.
*   Enable or require consent before attribution is stored.
*   Choose a consent mode:
    *   **Strict** – everything denied by default.
    *   **Relaxed** – everything granted by default.
    *   **Geo-based custom** – deny for EU/UK/CH visitors, grant elsewhere.

Settings are stored under a single `clicktrail_attribution_settings` option and rendered through native WordPress settings sections/fields. An AJAX endpoint logs PII risk alerts, and an admin notice surfaces PII warnings on the dashboard when needed.

**Front-end Behavior**

Based on your settings, ClickTrail:

*   Enqueues attribution and consent scripts/styles.
*   Localizes the attribution script with cookie name, duration, consent requirements, and nonce-secured AJAX URL.
*   Loads consent banner assets when enabled.
*   Injects Google Consent Mode defaults in the `<head>` (Strict, Relaxed, or Custom).

**Data Capture & Exposure**

Attribution data is read from cookies (`ct_attribution` / `attribution`), sanitized, and exposed via helper functions so theme and plugin code can attach it to form submissions, save it on orders, or feed it into custom integrations.

**Integrations**

*   **Forms**:
    *   **Contact Form 7**: Hidden fields are auto-populated with attribution values.
    *   **Fluent Forms**: Same behavior for supported forms.
    *   **Gravity Forms**: Scaffolding is in place for dynamic population.
*   **WooCommerce**:
    *   Saves sanitized attribution metadata (including session count) to orders at checkout.
    *   **New**: "Source" column in WooCommerce orders list shows first-touch attribution (e.g., "Google / CPC").
    *   **New**: Attribution meta box on order edit page displays complete first-touch and last-touch data.
    *   Emits a GA4-ready purchase event on the thank-you page, including first- and last-touch fields and line-item data.
    *   Prevents duplicate events on page refresh.
*   **WhatsApp**:
    *   Automatically tracks clicks on WhatsApp links (`wa.me`, `whatsapp.com`, `api.whatsapp.com`) and pushes a `wa_click` event to the dataLayer with full attribution details.
*   **Event Tracking (New)**:
    *   **Client-side**: Automatically tracks searches, file downloads, scroll depth (25%, 50%, 75%, 90%), and time on page.
    *   **Server-side**: Tracks user logins, signups, and comments, pushing them to the dataLayer for accurate measurement.

== Installation ==

1.  Upload the plugin files to the `/wp-content/plugins/click-trail-handler` directory, or install the plugin through the WordPress plugins screen directly.
2.  Activate the plugin through the 'Plugins' screen in WordPress.
3.  Go to **ClickTrail** in the admin menu to configure attribution and consent settings.
4.  For supported form plugins, submit a test entry to verify UTM values are captured.

== Frequently Asked Questions ==

= What data does the plugin store? =

ClickTrail stores attribution data (UTMs, click IDs, landing page, and session count) in a cookie and passes the values into supported form submissions and WooCommerce orders.

= Does the consent banner block tracking until approval? =

If you enable "Require Consent for Tracking" in the settings, ClickTrail will defer storing attribution until the visitor accepts.

= How does the WhatsApp tracking work? =

The plugin automatically detects clicks on WhatsApp links and pushes a `wa_click` event to the dataLayer, including the current attribution data. You can use this event in GTM to trigger tags.

= Is the built-in consent banner GDPR compliant? =

The built-in consent banner is a basic solution suitable for small websites. For full GDPR/CCPA compliance or if you operate in highly regulated industries, we strongly recommend using a dedicated Consent Management Platform (CMP) such as Cookiebot, OneTrust, or Borlabs Cookie. Ultimate compliance with privacy regulations is the responsibility of the website owner.

== Screenshots ==

1.  Attribution & Consent settings page showing toggle controls.
2.  Example ClickTrail consent banner on the frontend.

== Changelog ==

= 1.1.0 =
*   **New**: Added comprehensive Event Tracking (Searches, Downloads, Scroll Depth, Time on Page).
*   **New**: Added Server-side Event Tracking for User Login, Signups, and Comments.
*   **New**: Implemented Google Consent Mode v2 support with region-specific defaults.
*   **New**: Added manual Google Tag Manager (GTM) Container ID injection.
*   **Improvement**: Refactored codebase for better modularity and performance.
*   **Improvement**: Enhanced WooCommerce integration with "Source" column and detailed meta box.

= 1.0.0 =
*   Initial release with attribution capture, consent banner, and integrations for WooCommerce, Contact Form 7, Fluent Forms, and WhatsApp.
