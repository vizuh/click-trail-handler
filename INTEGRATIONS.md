# ClickTrail — Integrations Reference

This document lists every external service ClickTrail communicates with, how each connection is made, and where credentials are configured.

---

## Server-Side Conversion Tracking

All server-side adapters live in `includes/server-side/`. They are dispatched via `CLICUTCL\Server_Side\Dispatcher::dispatch()`, which:

- Respects the **WP_ENVIRONMENT_TYPE** guard (calls are blocked on `local` and `development` environments unless the `clicutcl_dispatch_in_environment` filter returns `true`).
- Uses the async queue (`CLICUTCL\Server_Side\Queue`) — events are written to a custom DB table and processed in the background via WP-Cron (`clicutcl_process_queue` hook, scheduled every minute).
- Retries failed sends up to the configured max-retry count before marking an event as `failed`.

### sGTM (Server-side Google Tag Manager)

| Property | Value |
|---|---|
| Adapter class | `CLICUTCL\Server_Side\Sgtm_Adapter` |
| File | `includes/server-side/class-sgtm-adapter.php` |
| Adapter key | `sgtm` |
| Endpoint | Configured sGTM container URL (Settings → Server-Side → sGTM URL) |
| Auth | None (shared secret optional via custom header) |
| Protocol | HTTP POST, JSON payload |
| Credentials location | `clicutcl_server_side_settings` option → `sgtm_url` |

### Meta Conversions API (CAPI)

| Property | Value |
|---|---|
| Adapter class | `CLICUTCL\Server_Side\Meta_Capi_Adapter` |
| File | `includes/server-side/class-meta-capi-adapter.php` |
| Adapter key | `meta_capi` |
| Endpoint | `https://graph.facebook.com/v19.0/{pixel_id}/events` |
| Auth | Meta Access Token (Bearer) |
| Credentials location | `clicutcl_server_side_settings` option → `meta_pixel_id`, `meta_access_token` |

### Google Ads Enhanced Conversions

| Property | Value |
|---|---|
| Adapter class | `CLICUTCL\Server_Side\Google_Ads_Adapter` |
| File | `includes/server-side/class-google-ads-adapter.php` |
| Adapter key | `google_ads` |
| Endpoint | Google Ads API (via sGTM or direct API call) |
| Auth | Google OAuth2 / API Key |
| Credentials location | `clicutcl_server_side_settings` option → `google_ads_conversion_id`, `google_ads_api_key` |

### LinkedIn Conversions API (CAPI)

| Property | Value |
|---|---|
| Adapter class | `CLICUTCL\Server_Side\Linkedin_Capi_Adapter` |
| File | `includes/server-side/class-linkedin-capi-adapter.php` |
| Adapter key | `linkedin_capi` |
| Endpoint | `https://api.linkedin.com/rest/conversionEvents` |
| Auth | LinkedIn OAuth2 Access Token |
| Credentials location | `clicutcl_server_side_settings` option → `linkedin_access_token`, `linkedin_conversion_id` |

### Generic Collector (fallback)

| Property | Value |
|---|---|
| Adapter class | `CLICUTCL\Server_Side\Generic_Collector_Adapter` |
| File | `includes/server-side/class-generic-collector-adapter.php` |
| Adapter key | `generic` |
| Endpoint | User-configured webhook URL |
| Auth | Optional Bearer token |
| Credentials location | `clicutcl_server_side_settings` option → `generic_endpoint_url`, `generic_token` |

---

## WooCommerce

| Property | Value |
|---|---|
| Integration class | `CLICUTCL\Integrations\WooCommerce` |
| File | `includes/integrations/class-woocommerce.php` |
| Hooks | `woocommerce_thankyou`, `woocommerce_order_status_changed` |
| Purpose | Fires conversion events on successful order completion |
| Requires | WooCommerce active (`class_exists('WooCommerce')`) |

---

## Form Integrations

Managed by `CLICUTCL\Integrations\Form_Integration_Manager` (`includes/integrations/`). Each form plugin integration listens for submission hooks and passes attribution data through.

Supported form plugins (auto-detected at runtime):

- **Contact Form 7** — `wpcf7_mail_sent`
- **Gravity Forms** — `gform_after_submission`
- **WPForms** — `wpforms_process_complete`
- **Elementor Forms** — `elementor_pro/forms/new_record`
- **Ninja Forms** — `ninja_forms_after_submission`
- **Fluent Forms** — `fluentform/submission_inserted`

---

## WhatsApp Link Attribution

Handled in `includes/api/traits/trait-log-controller-public-wa.php`.

- Rewrites WhatsApp links (`wa.me`, `whatsapp.com`, `api.whatsapp.com`, `web.whatsapp.com`) to append UTM/attribution parameters.
- No external API calls — client-side URL manipulation only.

---

## Geo IP / Region Detection

No external geo-IP service is called. Region detection for Consent Mode (`geo` mode) reads server-injected headers from the infrastructure layer:

| Header | Provider |
|---|---|
| `HTTP_CF_IPCOUNTRY` | Cloudflare |
| `HTTP_X_COUNTRY_CODE` | Nginx/proxy custom header |
| `HTTP_GEOIP_COUNTRY_CODE` | MaxMind GeoIP Apache module |
| `GEOIP_COUNTRY_CODE` | MaxMind GeoIP (legacy) |
| `HTTP_CF_REGION_CODE` | Cloudflare (US state) |

---

## REST API Endpoints (internal)

These are WordPress REST endpoints registered by the plugin — not external services.

| Route | Controller | Purpose |
|---|---|---|
| `POST /clicutcl/v2/events/batch` | `Tracking_Controller` | Receives batched client-side events |
| `POST /clicutcl/v2/attribution-token/sign` | `Tracking_Controller` | Signs cross-domain attribution tokens |
| `POST /clicutcl/v2/attribution-token/verify` | `Tracking_Controller` | Verifies incoming attribution tokens |
