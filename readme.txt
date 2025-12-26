=== ClickTrail – UTM, Click ID & Ad Tracking (with Consent) ===
Author: Vizuh
Author URI: https://vizuh.com
Contributors: hugoc
Tags: attribution, utm, tracking, consent mode, gtm
Requires at least: 5.0
Tested up to: 6.9
Stable tag: 1.3.0
Requires PHP: 7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Complete marketing attribution: UTMs & Click IDs for WooCommerce and forms. Built-in consent banner + Google Consent Mode support.

== Description ==

Most WordPress sites lose campaign data the moment a visitor clicks away from the landing page. If the user doesn’t convert immediately, the UTM/click ID context often never makes it into your form entry or WooCommerce order — leaving you with “Direct / None” conversions and a huge reporting blind spot.

ClickTrail fixes that.

It captures first-touch and last-touch UTMs + click IDs (gclid, fbclid, ttclid, msclkid, and more), stores them in first-party cookies (with consent), and automatically injects that attribution into supported form submissions and WooCommerce orders — even when the buyer journey takes multiple pages or multiple sessions.

= Why this matters (ROI, not just tracking) =

* **Illuminate “lost conversions”**: Without proper UTM persistence, marketing becomes guesswork — you’re effectively optimizing in the dark. ClickTrail ensures each lead/order carries its true source so you can prove what’s working.
* **Reduce wasted ad spend**: Industry reports estimate large chunks of marketing spend are wasted when measurement is incomplete or misattributed. ClickTrail helps you recover missing signals so you can cut spend that isn’t converting and scale what is.
* **Make better decisions with real data**: Many marketers still don’t trust their attribution data. ClickTrail gives you clean first-touch and last-touch context inside WordPress — where the conversion actually happens.

= Concrete example =

A visitor clicks a Google Ads campaign, browses a few pages, leaves, and comes back next week to buy.
With ClickTrail, the WooCommerce order still records the original utm_source=google and the relevant campaign/click IDs (first-touch + last-touch).
Without this type of persistence, that purchase often shows up as “Direct” or loses the campaign context in your lead/order records.

= Key Features =

* **Accurate attribution (first + last touch)**: Persist first-touch and last-touch UTMs and click IDs for up to 90 days, then inject them into supported forms and WooCommerce orders automatically.
* **WooCommerce insights**: View a "Source" column in orders and a detailed attribution meta box on each order edit screen.
* **GA4-ready purchase events**: Push enriched purchase events from WooCommerce thank-you pages, including campaign data and line items, while preventing duplicate firing.
* **Broad click ID coverage**: Capture IDs from Google (gclid, wbraid, gbraid), Meta (fbclid), TikTok (ttclid), Microsoft (msclkid), Twitter/X (twclid), LinkedIn (li_fat_id), Snapchat (ScCid), and Pinterest (epik).
* **Event tracking built-in**: Track searches, file downloads, scroll depth (25%, 50%, 75%, 90%), time on page, logins, signups, and comments with dataLayer pushes.
* **Privacy-aware by default**: Built-in consent banner + Google Consent Mode defaults, with Strict, Relaxed, or Geo-based modes.

= Consent Options =

* Built-in consent banner with Google Consent Mode defaults injected in the <head>.
* Configure strict, relaxed, or geo-based consent behavior to align with your region and policies.
* Control cookie duration and whether consent is required before storing attribution.
* Optional admin warnings help you avoid accidentally storing personal data inside UTMs/campaign parameters.

= GA4 & Analytics Integrations =

* GA4-ready purchase event with campaign data and line items.
* dataLayer events for WooCommerce, WhatsApp (wa_click), and engagement tracking (client/server-side).
* Manual Google Tag Manager container ID injection for sites without a theme-level GTM snippet.

= Supported Platforms =

* **Forms**: Contact Form 7 (hidden fields auto-populated), Fluent Forms, and Gravity Forms scaffolding for dynamic population.
* **Commerce**: WooCommerce attribution metadata, session count, and admin UI enhancements.
* **Messaging**: WhatsApp click detection for wa.me, whatsapp.com, and api.whatsapp.com links.

= References (optional, for credibility) =

* Why WordPress/UTM attribution breaks without persistence: https://pilotdigital.com/blog/how-to-track-source-campaign-and-other-data-in-hidden-fields/
* “Marketing in the dark without proper UTM tracking” framing: https://wpmayor.com/afl-utm-tracker/
* Ad waste + measurement/misattribution overview: https://cdn2.hubspot.net/hubfs/1878504/Ebook-The-Waste-in-Advertising-Stats-and-Solutions-of-Misattribution.pdf
* Confidence gap in attribution data (industry survey coverage): https://www.moengage.com/blog/branch-state-of-app-growth-report-moengage-perspective/
* ROI/waste/touchpoints stats roundup (benchmarks vary): https://marketingltb.com/blog/statistics/marketing-attribution-statistics/

== Installation ==

1.  Install via **Plugins  Add New** (search "ClickTrail") or upload to /wp-content/plugins/click-trail-handler.
2.  Activate the plugin through the **Plugins** screen in WordPress.
3.  Navigate to **ClickTrail  Attribution & Consent Settings** to configure cookie duration, consent mode (Strict, Relaxed, or Geo-based), and GA4/GTM options.
4.  Test it: Submit a form or complete a purchase with UTM parameters in the URL to verify attribution is captured.
5.  Optional: Enable the consent banner and review Google Consent Mode defaults to stay compliant with privacy regulations.

== Frequently Asked Questions ==

= Doesn't GA4 already solve attribution? =

GA4 can attribute sessions, but it doesn't automatically push campaign parameters into your WordPress form submissions or WooCommerce order records. ClickTrail persists UTMs/click IDs in first-party cookies (with consent) and injects them into the conversion record inside WordPress, so your leads and orders retain their true source.

= What's the difference between first-touch and last-touch? =

First-touch shows the campaign that originally brought the visitor to your site. Last-touch shows the most recent campaign/click before conversion. Seeing both helps you understand what starts demand vs. what closes it.

= What data does ClickTrail store and for how long? =

ClickTrail stores attribution data (UTMs, click IDs, landing page, and session count) in first-party cookies for up to 90 days (configurable) and injects the values into supported form submissions and WooCommerce orders.

= Which click IDs and parameters are supported? =

The plugin captures gclid, wbraid, gbraid (Google Ads), fbclid (Facebook), ttclid (TikTok), msclkid (Microsoft), twclid (Twitter/X), li_fat_id (LinkedIn), ScCid (Snapchat), and epik (Pinterest) alongside your standard UTM parameters.

= Does the consent banner block tracking until approval? =

If you enable **Require Consent for Tracking**, ClickTrail will defer storing attribution until the visitor accepts. Strict, Relaxed, and Geo-based presets control whether tracking is granted or denied by default.

= How does WhatsApp click tracking work? =

Clicks on `wa.me`, `whatsapp.com`, and `api.whatsapp.com` links trigger a `wa_click` dataLayer event with full UTM and click ID context so you can build GTM tags to measure WhatsApp conversions.

= How do I enable GA4 purchase events? =

Enable **GA4 Purchase Event** in the settings. The plugin fires a GA4-ready purchase event on the WooCommerce thank-you page with first- and last-touch attribution data and line items.

= Is ClickTrail GDPR compliant? =

ClickTrail provides consent controls and Consent Mode defaults, but ultimate compliance depends on your configuration. Use the built-in banner for lightweight sites or integrate with a dedicated CMP (e.g., Cookiebot, OneTrust, Borlabs) for stricter regulatory needs.

== Screenshots ==

1.  Attribution & Consent settings page with cookie duration, consent modes, and GA4 options.
2.  Frontend consent banner showcasing Strict consent mode.
3.  WooCommerce Orders list with the "Source" column displaying first-touch attribution.
4.  GA4-ready purchase event configuration and dataLayer preview.

== Changelog ==

= 1.3.0 =
* Feature: Cache Resurrection! Now supports Client-Side Field Injection (`enable_js_injection`) to populate hidden form fields even on fully cached pages (WP Rocket, Cloudflare, etc.).
* Feature: Cross-Domain Tracking. Added Link Decoration (`enable_link_decoration`) to safely pass UTMs and Click IDs to allowed domains and subdomains.
* Feature: Added Advanced Bot Protection to prevent attribution pollution from crawlers and headless browsers.
* Feature: Added Site Health diagnostics to detect caching conflicts, JS issues, and blocking of cookies.
* Improvement: Added Dashboard Widget "ClickTrail Status" for quick visibility of tracking health.
* Improvement: Added JS Fallback for WooCommerce Checkout to ensure order attribution works even if cookies are stripped by server-side caching.
* Improvement: Renamed the main settings submenu from "ClickTrail" to "Settings" for better Admin UX.
* Improvement: Removed dependency on jQuery for attribution logic.

= 1.2.3 =
*   **Fix**: Fixed Short Description formatting in readme.txt to comply with WordPress.org plugin guidelines.
*   **Improvement**: Removed deprecated `load_plugin_textdomain()` call—WordPress 4.6+ handles translations automatically for plugins hosted on WordPress.org.
*   **Improvement**: Added PHPCS ignore comment for intentional direct database query on custom plugin table.

= 1.2.2 =
*   **Fix**: Resolved critical autoloading issues on Linux/Unix environments (case-sensitive paths) to prevent Fatal Errors during activation.
*   **Fix**: Implemented strict PHP interface compatibility for all Form Adapters (CF7, WPForms, Gravity Forms, Ninja Forms, Fluent Forms) to resolve fatal errors on PHP 8+.
*   **Fix**: Added robust "preflight" checks in the boot sequence to safely deactivate the plugin if files are corrupted or missing, instead of crashing the site.
*   **Improvement**: Enhanced autoloader performance and added fallback for mixed naming conventions.
*   **Improvement**: Updated plugin metadata and readme for better WordPress.org validation compliance.

= 1.2.1 =
*   **Fix**: Fixed scroll tracking to use GTM's built-in variable names (`gtm.scrollThreshold`, `gtm.scrollUnits`, `gtm.scrollDirection`) instead of custom Data Layer Variables, making GTM setup simpler and more reliable.
*   **Fix**: Fixed scroll percentage calculation bug that prevented scroll events from firing. Changed from string-based property access to direct property access with cross-browser fallbacks for better reliability.
*   **Improvement**: Renamed `time_on_page` event to `user_engagement` with descriptive engagement levels (quick_view, browsing, engaged, interested, highly_engaged) and added detailed parameters (`engagement_time_msec`, `time_label`, `time_threshold`) for better analytics insights.
*   **Fix**: Corrected typo in settings class name (`Attribution_Settings`) for consistency.
*   **Docs**: Updated readme.txt and README.md with benefit-focused messaging emphasizing ROI and business value over technical features.

= 1.2.0 =
*   **Security**: Hardened AJAX handlers with strict WhatsApp URL validation and optimized PII risk logging using nonce verification and state checks.
*   **Refactor**: Major architectural improvements including Namespaced Admin class, decoupled AJAX Log Handler, and extracted CPT registration.
*   **Refactor**: Standardized Integrations (WooCommerce & Forms) into namespaced classes (`CLICUTCL\Integrations`), cleaning up the global namespace and dependencies.
*   **Fix**: Fixed GTM Data Layer variables for Scroll Tracking (now sends `gtm.scrollThreshold`, `gtm.scrollUnits`, ` gtm.scrollDirection`, and `percent_scrolled` for GA4 compatibility).
*   **Fix**: Improved engagement tracking with renamed `user_engagement` event and descriptive engagement levels (quick_view, browsing, engaged, interested, highly_engaged).
*   **Feature**: Introduced Custom Database Table (`wp_clicutcl_events`) for scalable event logging, removing reliance on Custom Post Types.
*   **Feature**: Implemented REST API Log Endpoint (`/wp-json/clicutcl/v1/log`) for faster and lighter tracking requests.
*   **Feature**: Added Admin Log Viewer (`ClickTrail > Logs`) to view events from the custom database table.
*   **Feature**: Implemented Automated Database Cleanup (Cron) to keep the events table healthy.
*   **Refactor**: Centralized settings logic (`Attribution_Settings`) and Attribution Utilities (`Utils\Attribution`) for better maintainability.

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

= 1.3.0 =
Major update: Introduces Cache Resurrection (Client-Side Injection), Link Decoration (Cross-Domain Tracking), Bot Protection, and new Site Health diagnostics. Highly recommended for accurate attribution on cached sites.

= 1.2.3 =
Minor update: Fixes WordPress.org plugin check warnings (short description format, deprecated textdomain loader). Recommended for compliance.

= 1.2.2 =
Critical update: Fixes fatal errors on activation for Linux environments and PHP 8+ strict compatibility issues. Recommended for all users.

= 1.2.1 =
Scroll tracking improvements: Now uses GTM built-in variables (no custom setup needed!) and fixes scroll calculation bug. Engagement tracking enhanced with descriptive levels. Recommended update for better GTM compatibility.

= 1.2.0 =
Major security hardening and internal refactoring. Includes fixes for Scroll Tracking (now uses GTM built-in variables) and enhanced user engagement tracking with descriptive engagement levels.

= 1.1.1 =
Documentation and asset updates only; no functional changes. Apply this to keep your WP.org listing in sync with the latest readme and screenshots.

= 1.1.0 =
Adds event tracking, Consent Mode v2 updates, and GA4/GTM enhancements. Review consent settings before upgrading to ensure privacy compliance.

