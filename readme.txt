=== ClickTrail ===
Contributors: hugoc
Donate link: https://vizuh.com/
Tags: analytics, attribution, utm, consent, woocommerce, whatsapp, tracking
Requires at least: 5.0
Tested up to: 6.8
Stable tag: 1.0.0
Requires PHP: 7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lead & Order Attribution for WordPress forms, WooCommerce and WhatsApp.

== Description ==

**What is ClickTrail?**

ClickTrail is a WordPress plugin that finally shows you which campaigns actually generate your leads and sales.

It captures first- and last-touch UTMs and click IDs, stores them in cookies, and automatically attaches that data to your form entries and WooCommerce orders. On top of that, it ships with a lightweight consent banner + Consent Mode defaults, so your tracking stays aligned with EU-style privacy rules.

Built for WordPress 5.0+ / PHP 7.0+, it includes a simple settings screen for marketers and the front-end scripts needed for attribution and consent handling.

**Key Benefits**

*   **See the real source of every lead and order**: First-touch and last-touch UTMs + click IDs are persisted for up to 90 days and injected into supported forms and Woo orders automatically.
*   **Make GA4 & Meta tracking actually useful**: ClickTrail pushes enriched, GA4-ready purchase events from WooCommerce thank-you pages, with campaign data and line items included.
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
    *   Emits a GA4-ready purchase event on the thank-you page, including first- and last-touch fields and line-item data.
    *   Prevents duplicate events on page refresh.
*   **WhatsApp**:
    *   Automatically tracks clicks on WhatsApp links (`wa.me`, `whatsapp.com`, `api.whatsapp.com`) and pushes a `wa_click` event to the dataLayer with full attribution details.

== Installation ==

1.  Upload the plugin files to the `/wp-content/plugins/clicktrail` directory, or install the plugin through the WordPress plugins screen directly.
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

== Screenshots ==

1.  Attribution & Consent settings page showing toggle controls.
2.  Example ClickTrail consent banner on the frontend.

== Changelog ==

= 1.0.0 =
*   Initial release with attribution capture, consent banner, and integrations for WooCommerce, Contact Form 7, Fluent Forms, and WhatsApp.
