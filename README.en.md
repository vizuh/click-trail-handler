# ClickTrail

[![Support](https://img.shields.io/badge/support-active-brightgreen.svg)](https://github.com/vizuh/click-trail-handler)
[![Release](https://img.shields.io/github/v/release/vizuh/click-trail-handler?label=release&color=blue)](https://github.com/vizuh/click-trail-handler/releases)
[![WordPress tested](https://img.shields.io/badge/WordPress-v7.0%20tested-3858e9.svg)](https://wordpress.org)
[![License](https://img.shields.io/badge/license-GPL--2.0-orange.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

[![PHP Linting](https://github.com/vizuh/click-trail-handler/actions/workflows/php-lint.yml/badge.svg)](https://github.com/vizuh/click-trail-handler/actions/workflows/php-lint.yml)
[![PHPUnit Tests](https://github.com/vizuh/click-trail-handler/actions/workflows/phpunit.yml/badge.svg)](https://github.com/vizuh/click-trail-handler/actions/workflows/phpunit.yml)
[![CodeQL](https://github.com/vizuh/click-trail-handler/actions/workflows/codeql.yml/badge.svg)](https://github.com/vizuh/click-trail-handler/actions/workflows/codeql.yml)
[![Dependency Review](https://github.com/vizuh/click-trail-handler/actions/workflows/dependency-review.yml/badge.svg)](https://github.com/vizuh/click-trail-handler/actions/workflows/dependency-review.yml)

![ClickTrail](.github/clicktrail-cover.png)

**Keep the campaign attached to the conversion.**

Someone clicks your ad. Three pages later, they send a form or place an order. By then the campaign is usually gone, and the lead looks like it came from nowhere.

ClickTrail keeps what the visitor arrived with: the UTMs, the ad click ID, the referrer. It carries that context to the form entry or the WooCommerce order, and to a Calendly booking. You open the record and see where it came from.

ClickTrail records what arrived. It does not decide what caused the sale.

## Start here

Pick the path that matches your site.

- **WooCommerce store**: turn on Capture and WooCommerce. Orders carry the campaign. Add storefront events later if you want funnel signals.
- **Lead forms**: turn on Capture and Forms. Contact Form 7 and Fluent Forms get hidden fields on their own. Gravity Forms and WPForms fill the `ct_*` fields you add (WPForms Lite has no Hidden field: use a Single Line Text field with the CSS class `ct_<key>` and hide it with CSS).
- **GTM or server-side GTM**: turn on Capture, Events, and Delivery. Events go to the `dataLayer`, and ClickTrail can send canonical JSON to an endpoint you set.

Then place one test order or send one test form, and look at what was stored. The [use-case guide](docs/guides/USE-CASES.md) and [tutorials](docs/tutorials/README.md) walk through each path.

## What it does

ClickTrail only acts on the paths you turn on and set up in its settings. That is what "configured" means everywhere in these docs.

- Capture: first-touch and last-touch UTMs, ad click IDs, and the referrer, with organic, social, and referral fallback when there are no UTMs. When a visitor comes back through a new ad, the newest click is the one that travels. Context lives in first-party storage, falls back to `sessionStorage` when cookies are blocked, and your own admin browsing stays out of it.
- WooCommerce: attribution saved on the order before an off-site payment redirect (classic and block checkout), with returns from payment hosts such as PayPal or Stripe never overwriting the stored touch, an enriched purchase event on the thank-you page, optional storefront events (`view_item`, `view_item_list`, `view_cart`, `add_to_cart`, `remove_from_cart`, `begin_checkout`), and optional order-status milestones. Order metadata has export, erase, retention, and uninstall handling.
- Forms: Contact Form 7 and Fluent Forms get hidden fields automatically. Gravity Forms and WPForms fill the `ct_*` fields you add; Gravity Forms also gets a channel label, nine `{clicutcl_*}` merge tags, and a per-form switch. Elementor Forms (Pro) and Ninja Forms keep attribution with the submission record. The server renders those fields empty so a page cache never serves one visitor's attribution to another (the `clicutcl_render_server_attribution_values` filter opts back in on sites without a cache); the browser fills them after load, the submit-time cookie corrects them, and keys the cookie lacks are blanked rather than kept. Cached form pages need no exclusion. A client-side fallback covers cached pages and forms that load late, and WhatsApp links can carry the context too.
- Bookings and your other domains: links to domains you approve carry the campaign, with a signed token between your own sites. Calendly booking links hold the newest click and the visitor's consent decision, and the signed Calendly webhook records the booking as `book_appointment` with its UTMs. Booking or form iframes your own script loads can carry the campaign too, through `window.ClickTrail.decorateEmbedUrl()`, for hosts you list.
- Events and delivery: browser events to the `dataLayer`, a REST intake, webhooks from Calendly, HubSpot, and Typeform, lifecycle updates, and one-time WordPress events such as `login`, `sign_up`, and `comment_submit`. Optional server-side delivery adds a retry queue and diagnostics.

## Consent and privacy

When consent is required, nothing is stored until the visitor agrees. ClickTrail listens to its own banner, Cookiebot, OneTrust, Complianz, GTM, or a custom source, or detects the source for you. ClickTrail follows Cookiebot and Complianz decisions, including changes the visitor makes on the page. On withdrawal, ClickTrail clears its storage and removes the parameters it added to links. Queued deliveries check current consent again right before they are sent, and the consent decision stays in sync across tabs. Consent behavior can be strict, relaxed, or geo-based. ClickTrail helps you implement consent; compliance still depends on your legal requirements and your settings.

## What it does not do

- It does not prove which click caused a sale, and it does not decide which channel deserves the revenue.
- It does not resolve one person's identity across devices.
- It is not an attribution dashboard, a hosted server-side GTM platform, a lead manager, or an ad optimizer. It works alongside GA4 and GTM. For call tracking, lead scoring, or ad-spend optimization, pair it with a specialist tool.
- It does not install ad pixels. The Meta Pixel, Google tag, TikTok Pixel, LinkedIn Insight Tag, Pinterest Tag, and Reddit Pixel stay yours, usually through GTM.
- Server-side delivery sends JSON to the endpoint you configure. The adapters named after Meta, Google Ads / GA4, LinkedIn, Pinterest, and TikTok exist in the code, but we have not verified that any provider accepts them end to end. Reddit is a relay-only destination.
- We have not yet verified a live Calendly booking round trip.

Having code for an integration is not proof that it works in production. Current status lives in the [integration reference](docs/reference/INTEGRATIONS.md) and the [machine-readable ledger](docs/reference/integration-capabilities.json). The [release-phasing plan](docs/guides/RELEASE-PHASING-AND-INTEGRATION-DOCS.md) shows what gets checked next.

## Reference

**Supported click IDs**

| Platform | Parameters |
|---|---|
| Google | `gclid`, `wbraid`, `gbraid` |
| Meta | `fbclid` |
| TikTok | `ttclid` |
| Microsoft | `msclkid` |
| X / Twitter | `twclid` |
| LinkedIn | `li_fat_id` |
| Snapchat | `sccid`, `snap_cid` |
| Pinterest | `epik`, `pin_cid` |
| Reddit | `rdt_cid` |
| Mailchimp | `mc_cid`, `mc_eid` |
| Display & Video 360 | `dclid` |

**Integrations**

- **Forms**: Contact Form 7, Elementor Forms (Pro), Fluent Forms, Gravity Forms, Ninja Forms, WPForms
- **Commerce**: WooCommerce
- **Consent sources**: ClickTrail banner, Cookiebot, OneTrust, Complianz, GTM, custom
- **Webhooks**: Calendly, HubSpot, Typeform
- **Server-side adapter keys**: generic collector, sGTM, Meta CAPI, Google Ads / GA4, LinkedIn CAPI, Pinterest Conversions API, TikTok Events API (status above)
- **Extra fields captured**: `utm_id`, `utm_source_platform`, `utm_creative_format`, `utm_marketing_tactic`, `fbc`, `fbp`, `_ttp`, `li_gc`, `ga_client_id`, `ga_session_id`
- **Caching and minification**: on every site, the four ClickTrail script tags (`clicutcl-consent-bridge-js`, `clicutcl-attribution-js`, `clicutcl-consent-js`, `clicutcl-events-js`) carry `nowprocket data-no-optimize="1" data-no-defer="1" data-noptimize="1" data-cfasync="false"`: `nowprocket` (WP Rocket Delay JS), `data-no-optimize="1"` and `data-no-defer="1"` (LiteSpeed Cache), `data-noptimize="1"` (Autoptimize), `data-cfasync="false"` (Cloudflare Rocket Loader). The same scripts are registered on the exclusion filters of WP Rocket (`rocket_delay_js_exclusions`, `rocket_exclude_defer_js`, `rocket_exclude_js`), LiteSpeed Cache (`litespeed_optm_js_exc`, `litespeed_optm_js_defer_exc`, `litespeed_optm_gm_js_exc`), Autoptimize (`autoptimize_filter_js_exclude`), Perfmatters (`perfmatters_delay_js_exclusions`, `perfmatters_defer_js_exclusions`, `perfmatters_minify_js_exclusions`) and SiteGround Speed Optimizer (`sgo_js_minify_exclude`, `sgo_javascript_combine_exclude`, `sgo_js_async_exclude`); NitroPack adds `data-nitropack-exclude="true"` and `nitropack_js_url_exclude` when active. The inline consent-default snippet carries `nowprocket data-no-defer="1"`. To check: view the page source, confirm every `clicutcl-*.js` tag carries the attributes, and confirm `window.ClickTrail` is defined before submitting a form. Delay-JS features run scripts on the first interaction (WP Rocket lists `keydown`, `mousemove`, `touchstart`, `scroll`, `click`, `input`, `onsubmit`), so a keyboard-only submit still runs them, too late for the landing touch. FlyingPress exposes no attribute or filter: add `clicutcl` and `clicktrail-consent-mode` to its exclusion field. Source-present / runtime-unverified.

The current release is 1.11.3 on GitHub and WordPress.org. See the [changelog](changelog.txt) for history.

## Installation

### Before you start

ClickTrail does not need to be fully enabled on day one. A basic forms or WooCommerce attribution setup can work without server-side delivery.

- If you only need attribution inside forms or WooCommerce, leave server-side delivery off for now.
- If your site already injects Google Tag Manager, do not enter the GTM container ID again in ClickTrail.
- If you use Gravity Forms or WPForms, add the `ct_*` hidden fields you want stored or exported before testing.
- If your site has consent requirements, decide whether ClickTrail or your existing CMP should be the source of truth.

### Recommended first setup

1. Install the plugin through WordPress or upload it to `/wp-content/plugins/click-trail-handler/`.
2. Activate the plugin and open `ClickTrail > Settings`.
3. In `Capture`, keep attribution enabled, choose a retention window that matches your sales cycle, and enable cross-domain continuity only when visitors actually move between approved domains or subdomains.
4. In `Forms`, enable only the integrations you use. Contact Form 7 and Fluent Forms can receive hidden attribution fields automatically. Gravity Forms, WPForms and Elementor Forms (Pro) should have the matching `ct_*` hidden fields you want to preserve, such as `ct_ft_source`, `ct_lt_source`, or `ct_gclid` (latest click; use `ct_ft_gclid` or `ct_lt_gclid` to pin a touch): Gravity Forms reads the parameter name, WPForms a CSS class (or label) of `ct_<key>`, Elementor the hidden field's ID. Every adapter refreshes those fields from the cookie sent with the submit, so a landing-page submission stores that visit's click.
5. In `Events`, leave browser events enabled only if you want `dataLayer` pushes and on-site event capture. Enable Woo storefront events only if you want `view_item`, `view_item_list`, `view_cart`, `add_to_cart`, `remove_from_cart`, and `begin_checkout`. Turn on the richer Woo `dataLayer` contract only if you want `event_id` and consent-aware `user_data` for GTM-first flows. Add a GTM container ID only if your site does not already inject GTM elsewhere.
6. In `Delivery`, leave server-side delivery off unless you already have a collector, sGTM, or advertising endpoint ready. If consent is required, choose the correct consent source and mode before going live.
7. Open `ClickTrail > Diagnostics` and run the relevant checks.

### How to confirm it is working

1. Visit your site with a test URL such as `?utm_source=test&utm_medium=cpc&utm_campaign=clicktrail-install-check`.
2. Browse to another page, then submit a configured form or place a test WooCommerce order.
3. Confirm the result you expect:
   - the form entry or WooCommerce order contains attribution values
   - browser events appear in your GTM preview or `dataLayer` if `Events` is enabled
   - Diagnostics and Logs show event intake or delivery activity if `Delivery` is enabled

### Good default rollout

Start with `Capture` and the integrations you already use. Add `Events` next if you want browser analytics signals. Add `Delivery` only when you are ready to send data to a collector or advertising endpoint.

## Tutorials

- [Lead form attribution](docs/tutorials/01-lead-form-attribution.md)
- [WooCommerce order attribution](docs/tutorials/02-woocommerce-order-attribution.md)
- [Consent and browser events](docs/tutorials/03-consent-and-events.md)

## Repository Docs

- [Implementation playbook](docs/guides/IMPLEMENTATION-PLAYBOOK.md)
- [Technical documentation index](docs/README.md)
- [Contributor guide](CONTRIBUTING.md)
- [Integrations reference](docs/reference/INTEGRATIONS.md)
- [Full changelog](changelog.txt)
- [WordPress.org readme](readme.txt)
- [Competitive positioning and acquisition roadmap](docs/guides/COMPETITIVE-POSITIONING-AND-ACQUISITION-ROADMAP-2026-08-22.md)

## Notes on Current Architecture

- The public admin UI no longer uses "Tracking v2" terminology.
- Internally, some runtime settings still live in the `clicutcl_tracking_v2` option for backward compatibility.
- The legacy v1 log controller has been removed; `clicutcl/v2` is the only active REST namespace. See [REST API](docs/reference/REST-API.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
