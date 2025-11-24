=== ClickTrail ===
Contributors: vizuh
Tags: analytics, tracking, attribution, utm, consent
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0-beta
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==
ClickTrail captures marketing parameters such as UTM tags and click IDs, saves them for later use, and provides lightweight consent management hooks. Use it to keep attribution data intact across sessions and make sure downstream tools receive the context they need.

**Key features:**
* Automatically records UTMs and click identifiers from inbound visits.
* Persists marketing parameters for use in forms, eCommerce checkout, and other integrations.
* Provides basic consent prompts and storage so you can respect visitor choices.

== Installation ==
1. Upload the `click-trail` directory to the `/wp-content/plugins/` directory or install via the WordPress Plugins screen.
2. Activate the plugin through the Plugins screen in WordPress.
3. Configure your preferred integrations and consent options in the ClickTrail settings.

== Frequently Asked Questions ==
= What data does ClickTrail capture? =
ClickTrail captures common marketing parameters including UTM values and click identifiers, then stores them so they can be referenced later by supported integrations.

= How does consent handling work? =
A lightweight consent prompt is provided to give visitors control over whether tracking data is saved. The plugin respects their choice when storing attribution data.

== Changelog ==
= 1.0.0-beta =
* Initial beta release with marketing parameter capture and consent handling.

== Developer Information ==
If you plan to list this plugin in the WordPress Plugin Directory:

* Make sure the plugin is licensed under GPLv2 or later and does not include offensive or illegal functionality.
* Use the Subversion repository provided by WordPress.org so the plugin is visible in the directory and avoid embedding external links without user consent.
* Follow the directory guidelines around spam prevention and system abuse; submit the plugin for manual review, respond to any requested changes, and upload both the plugin code and this readme file to the repository so it appears in the plugin browser.
