=== ClickTrail – UTM, Click ID & Ad Tracking (with Consent) ===
Author: Vizuh
Author URI: https://vizuh.com
Contributors: hugoc
Tags: attribution, utm, tracking, consent mode, gtm
Requires at least: 5.0
Tested up to: 6.9
Stable tag: 1.1.1
Requires PHP: 7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Persist and attach first- and last-touch UTMs and click IDs to forms and WooCommerce orders, with consent banner and GA4-ready analytics events.

== Description ==

ClickTrail captures first- and last-touch UTMs and click IDs (gclid, fbclid, ttclid, msclkid, and more), stores them in cookies with user consent, and automatically attaches that data to your form entries and WooCommerce orders. A built-in consent banner with Google Consent Mode integration keeps tracking privacy-aware while preserving the signal you need for GA4, Meta, and other analytics tools.

### Key Features

*   **Accurate attribution**: Persist first-touch and last-touch UTMs and click IDs for up to 90 days, then inject them into supported forms and WooCommerce orders automatically.
*   **WooCommerce insights**: View a "Source" column in orders and a detailed attribution meta box on each order edit screen.
*   **GA4-ready purchase events**: Push enriched purchase events from WooCommerce thank-you pages, including campaign data and line items, while preventing duplicate firing.
*   **Broad click ID coverage**: Capture IDs from Google (gclid, wbraid, gbraid), Meta (fbclid), TikTok (ttclid), Microsoft (msclkid), Twitter (twclid), LinkedIn (li_fat_id), Snapchat (ScCid), and Pinterest (epik).
*   **Event tracking built-in**: Track searches, file downloads, scroll depth (25%, 50%, 75%, 90%), time on page, logins, signups, and comments with dataLayer pushes.
*   **Privacy-aware by default**: Toggle strict, relaxed, or geo-based consent modes so tracking aligns with GDPR and regional rules.

### Consent Options

*   Built-in consent banner with Google Consent Mode defaults injected in the `<head>`.
*   Configure strict, relaxed, or geo-based consent to block or allow tracking by region.
*   Control cookie duration and whether consent is required before storing attribution.
*   Admin notices surface PII warnings; PII risk alerts are logged via AJAX.

### GA4 & Analytics Integrations

*   GA4-ready purchase event with campaign data and line items.
*   dataLayer events for WooCommerce, WhatsApp (`wa_click`), and client/server-side engagement tracking.
*   Manual Google Tag Manager container ID injection for sites without a theme-level GTM snippet.

### Supported Platforms

*   **Forms**: Contact Form 7 (hidden fields auto-populated), Fluent Forms, and Gravity Forms scaffolding for dynamic population.
*   **Commerce**: WooCommerce attribution metadata, session count, and admin UI enhancements.
*   **Messaging**: WhatsApp click detection for `wa.me`, `whatsapp.com`, and `api.whatsapp.com` links.

### Documentation & Translations

The base readme is English-only. For Portuguese guidance, visit [ClickTrail docs em Português](https://exemplo.com/pt/clicktrail). Use WordPress.org translations (GlotPress) to localize the readme for additional languages.

== Installation ==

1.  Install via **Plugins → Add New** (search “ClickTrail”) or upload to `/wp-content/plugins/click-trail-handler`.
2.  Activate the plugin through the **Plugins** screen in WordPress.
3.  Navigate to **ClickTrail → Attribution & Consent Settings** to set cookie duration, consent mode (Strict, Relaxed, or Geo-based), and GA4/GTM options.
4.  For supported form plugins, submit a test entry to verify UTM and click ID values are captured in hidden fields.
5.  Optional: Enable the consent banner and review Google Consent Mode defaults to stay compliant.

== Frequently Asked Questions ==

= What data does ClickTrail store and for how long? =

ClickTrail stores attribution data (UTMs, click IDs, landing page, and session count) in cookies for up to 90 days and injects the values into supported form submissions and WooCommerce orders.

= Which click IDs and parameters are supported? =

The plugin captures gclid, wbraid, gbraid, fbclid, ttclid, msclkid, twclid, li_fat_id, ScCid, and epik alongside your standard UTM parameters.

= Does the consent banner block tracking until approval? =

If you enable **Require Consent for Tracking**, ClickTrail will defer storing attribution until the visitor accepts. Strict, Relaxed, and Geo-based presets control whether tracking is granted or denied by default.

= How does WhatsApp click tracking work? =

Clicks on `wa.me`, `whatsapp.com`, and `api.whatsapp.com` links trigger a `wa_click` dataLayer event with full UTM and click ID context so you can build GTM tags easily.

= How do I enable GA4 purchase events? =

Enable **GA4 Purchase Event** in the settings and provide your measurement/stream configuration. The plugin fires a GA4-ready purchase event on the WooCommerce thank-you page with first- and last-touch data and line items.

= Is ClickTrail GDPR compliant? =

ClickTrail provides consent controls and Consent Mode defaults, but ultimate compliance depends on your configuration. Use the built-in banner for lightweight sites or integrate with a dedicated CMP (e.g., Cookiebot, OneTrust, Borlabs) for stricter regulatory needs.

== Screenshots ==

1.  Attribution & Consent settings page with cookie duration, consent modes, and GA4 options.
2.  Frontend consent banner showcasing Strict consent mode.
3.  WooCommerce Orders list with the "Source" column displaying first-touch attribution.
4.  GA4-ready purchase event configuration and dataLayer preview.

== Changelog ==

= 1.1.1 =
*   **Docs/Assets**: Updated readme copy and release guidance for WP.org parsing and added references for refreshed screenshots.
*   **Compatibility**: Set Stable tag and plugin version to 1.1.1 to align GitHub and SVN releases.

= 1.1.0 =
*   **New**: Added comprehensive Event Tracking (Searches, Downloads, Scroll Depth, Time on Page).
*   **New**: Added Server-side Event Tracking for User Login, Signups, and Comments.
*   **New**: Implemented Google Consent Mode v2 support with region-specific defaults.
*   **New**: Added manual Google Tag Manager (GTM) Container ID injection.
*   **Improvement**: Refactored codebase for better modularity and performance.
*   **Improvement**: Enhanced WooCommerce integration with "Source" column and detailed meta box.

Previous release notes are available in `changelog.txt`.

== Upgrade Notice ==

= 1.1.1 =
Documentation and asset updates only; no functional changes. Apply this to keep your WP.org listing in sync with the latest readme and screenshots.

= 1.1.0 =
Adds event tracking, Consent Mode v2 updates, and GA4/GTM enhancements. Review consent settings before upgrading to ensure privacy compliance.
