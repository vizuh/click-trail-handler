# Runtime evidence for Easy Digital Downloads, plugin 1.12.0 + #173 (2026-11-02)

What ran: `tools/qa/e2e/edd-ci.sh` against the `feat/edd-integration` worktree (`main` at `70fb918` plus the #173 change, mounted
read-only), Easy Digital Downloads 3.7.3 installed from wordpress.org inside the pod, one priced download, the **Store (manual)
gateway only**, driven by Chromium. It is a local pod, not a real shop, not a real payment provider and not a cache or
optimizer; every claim below is limited to what the harness observed.

## Environment

| | |
|---|---|
| WordPress | 7.1 (image `wordpress:php8.3-apache`, `sha256:74dc0652…6f18b`) |
| PHP | 8.3.33 (NTS, Apache) |
| Database | MariaDB 12.3.3 (`mariadb:lts`) |
| ClickTrail | 1.12.0 + #173 (mounted read-only) |
| Easy Digital Downloads | 3.7.3 (pinned by `edd-setup.sh`) |
| Browser | Chromium 147.0.7727.15 headless, Playwright 1.59.0 (Python), `navigator.webdriver` masked as in the base harness |
| Host | Fedora, kernel 7.2.13, podman 5.8.9 |
| Debug | `WP_DEBUG`, `WP_DEBUG_LOG` on, `WP_DEBUG_DISPLAY` off; `debug.log` scanned for PHP and database errors raised by ClickTrail |

Result: **61 checks, 0 FAIL**, exit 0 (`verify_edd.py`), on a fresh pod. The receipt was the default confirmation page EDD creates
(`[edd_receipt]` shortcode, `templates/shortcode-receipt.php`); the block receipt was not exercised.

## What was observed

| Scenario | Observed |
|---|---|
| S1 landing, consent not required | `?gclid=EDDGCLID&utm_source=google&utm_medium=cpc&utm_campaign=edd-launch`, add to cart, Store-gateway checkout. The complete `sale` order carries `_clicutcl_consent` (marketing true), `_clicutcl_gclid`/`_lt_gclid`/`_ft_gclid`, first/last touch source, medium and campaign, `_clicutcl_visitor_id`, `_clicutcl_session_id`. The receipt pushed one `purchase` (`event_id` `purchase_edd_<order ID>`, `transaction_id` the order number, value 12, currency EUR, one item, stored gclid and first/last touch). One purchase reached the capture endpoint with the same `event_id`, the order's first/last touch and consent snapshot. Two reloads of the receipt pushed and dispatched nothing more (`_clicutcl_tracking_sent` = `yes`). |
| S2 consent denied (banner Deny) | One order with only `_clicutcl_consent` (marketing false); the browser wrote no attribution cookie; no purchase push, no dispatch, reloads silent. |
| S3 consent accepted (banner Accept) | Same as S1 with `ACCEPTEDGCLID`. |
| S4 consent withdrawn after landing | Consent accepted and the click captured, then the consent cookie was flipped to denied while the attribution cookie stayed. The checkout request carried both; the order stored only the denied snapshot, nothing was pushed or dispatched. |
| S5 privacy | Through the registered WordPress exporter/eraser and the retention and purge methods, against real `edd_get_orders()` queries: export returned 21 items including the gclid; retention kept a fresh order and removed an order aged 200 days; EDD's own order meta (`_edd_should_send_*`) was untouched; the eraser removed the order meta; the manual purge cleared 4 orders with none failed. |

## Findings during the run

- **Registration timing.** The first run stored no order meta although the hook was registered. EDD builds the order while
  handling the checkout POST on `init` priority 10 (`edd_post_actions`); ClickTrail's own bootstrap runs on `init` priority 20.
  `Edd::init()` therefore runs from `plugins_loaded` (`clicutcl_init_edd()` in `clicutcl.php`). PHPUnit asserts the registration
  point; the E2E proves the behavior.
- **61-table join limit.** `Woo_Order_Privacy::managed_meta_query()` (130+ OR'd `EXISTS` clauses) fails on MariaDB against EDD's
  order tables: "Too many tables; MariaDB can only use 61 tables in a join". `WP_Meta_Query` joins the meta table once per clause.
  `Edd_Order_Privacy::managed_meta_query()` folds the same allowlist into one anchored `REGEXP` key clause (PHPUnit
  `test_managed_meta_query_is_one_key_clause_so_it_never_hits_the_61_table_join_limit`; E2E S5). The Woo query was not run against
  WooCommerce here and may have the same shape problem on its order tables; not verified.
- **EDD tables.** WP-CLI activation never fires `admin_init`, where EDD creates its order and session tables; without
  `edd_install_component_database_tables()` the cart session fails silently and the checkout page shows an empty cart.

## Not covered

- Stripe, PayPal, Square and EDD's REST card controllers: they call the same `edd_build_order()` but were not run, so the
  off-site return path is source-present only.
- Block-based receipt, refunds and status changes, EDD Recurring and Software Licensing renewals, EDD 2.x.
- Consent geo mode, a real CMP, a page cache in front of the checkout.
- Provider acceptance of the dispatched event (the capture endpoint answers 200 to anything).
