=== ClickTrail ===
Contributors: hugoc
Donate link: https://vizuh.com/
Tags: analytics, attribution, utm, consent, woocommerce
Requires at least: 5.0
Tested up to: 6.8
Stable tag: 1.0.0-beta
Requires PHP: 7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track UTM and click attribution across forms and WooCommerce with optional consent controls.

== Description ==

ClickTrail captures marketing parameters such as UTMs and click IDs on first- and last-touch, stores them in a cookie, and exposes the values to your submission and order data. The plugin includes a lightweight consent banner with optional tracking enforcement so you can keep attribution compliant.

* Capture first- and last-touch UTMs and click identifiers and persist them for up to 90 days.
* Inject attribution fields into Contact Form 7 and Fluent Forms submissions automatically.
* Attach attribution metadata to WooCommerce orders to power downstream revenue reporting.
* Toggle consent banner display and require consent before tracking as needed.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/clicktrail` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to **ClickTrail** in the admin menu to configure attribution and consent settings.
4. For supported form plugins, submit a test entry to verify UTM values are captured.

== Frequently Asked Questions ==

= What data does the plugin store? =

ClickTrail stores attribution data (UTMs, click IDs, landing page, and session count) in a cookie and passes the values into supported form submissions and WooCommerce orders.

= Does the consent banner block tracking until approval? =

If you enable "Require Consent for Tracking" in the settings, ClickTrail will defer storing attribution until the visitor accepts.

== Screenshots ==

1. Attribution & Consent settings page showing toggle controls.
2. Example ClickTrail consent banner on the frontend.

== Changelog ==

= 1.0.0-beta =
* Initial beta release with attribution capture, consent banner, and form/WooCommerce integrations.

== Upgrade Notice ==

= 1.0.0-beta =
Initial beta release.

== A brief Markdown Example ==

Ordered list:

1. Capture UTM parameters on landing.
2. Persist attribution for configured cookie duration.
3. Send attribution metadata with form submissions and orders.

Unordered list:

* Enable consent banner.
* Require consent before tracking.
* Integrate with WooCommerce and popular form plugins.

Links require brackets and parenthesis:

Here's a link to [WordPress](https://wordpress.org/ "Your favorite software") and one to [Markdown's Syntax Documentation](https://daringfireball.net/projects/markdown/syntax). Link titles are optional, naturally.

Blockquotes are email style:

> Capture marketing touchpoints and keep them with your conversions.

And Backticks for code:

`<?php clicktrail_get_attribution(); ?>`
