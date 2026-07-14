# MetaTrac

A WordPress/WooCommerce plugin that tracks core ecommerce events and sends
them to Meta through both the browser Pixel and the server-side Conversions
API (CAPI), so tracking survives ad blockers and iOS ATT opt-outs. Built as a
successor to the internal `zooraz` (Cloudflare Zaraz) plugin, but talks to
Meta directly instead of going through a dataLayer/Zaraz intermediary.

Installed on multiple client sites and updated centrally from this repo via
the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker).

## Requirements

- WooCommerce active.
- A Meta Pixel ID and a Conversions API access token (Events Manager > Data
  Sources > your Pixel > Settings > Conversions API > Generate access token).

## Setup on a site

1. Install the plugin (see "Installing / updating" below).
2. Go to **WooCommerce > MetaTrac**.
3. Enter the **Meta Pixel ID** and **Conversions API Access Token**.
4. Check which events to track: `ViewContent`, `AddToCart`,
   `InitiateCheckout`, `Purchase`.
5. Optionally turn on **Debug Mode** while verifying a new install — see
   "Debug mode" below — and turn it back off once confirmed.
6. Optionally paste a **Test Event Code** from Events Manager > Test Events
   while verifying CAPI delivery, then remove it.

## How events are tracked

Every event fires twice, sharing the same `event_id` so Meta deduplicates
the browser and server copies:

- **Browser (Pixel)**: `fbq('track', ...)`, queued during page render and
  flushed in the footer (or, for ajax add-to-cart, pushed via a WooCommerce
  fragment — see below).
- **Server (CAPI)**: a `wp_remote_post()` to `graph.facebook.com`, including
  `fbp`/`fbc` cookies, client IP/user agent, and — when available — a hashed
  email/phone for match quality.

| Event              | Fires on                                              |
|---------------------|--------------------------------------------------------|
| `ViewContent`       | Single product page view                                |
| `AddToCart`         | `woocommerce_add_to_cart` (ajax or classic form submit)  |
| `InitiateCheckout`  | Checkout page load, if the cart isn't empty              |
| `Purchase`          | Order-received ("thank you") page, once per order        |

### AddToCart and ajax carts

WooCommerce's default ajax add-to-cart doesn't reload the page, so there's no
page for the browser Pixel call to run on. MetaTrac handles this the way
GTM/analytics plugins typically do: it injects a `<script>` into
WooCommerce's `woocommerce_add_to_cart_fragments` response, targeting a
placeholder element that's already rendered on the page. The Conversions API
side isn't affected either way, since it fires server-side during the same
request that processes the add-to-cart.

**Known limitation:** if a theme forces non-ajax add-to-cart (a full-page
redirect to the cart), the browser Pixel `AddToCart` call for that specific
add is skipped, since that request never renders a footer. The server-side
CAPI event still fires normally.

## Debug mode

When enabled:

- Every fired event is `console.log`'d in the browser as
  `[MetaTrac] Event fired: <EventName>`.
- Every fired event (Pixel-side build, not just CAPI) is appended to a
  dedicated log file, independent of `WP_DEBUG_LOG`, at:

  ```
  wp-content/uploads/metatrac-logs/debug.log
  ```

  Each line includes the event name, the page it fired on, its `event_id`,
  and its payload. The directory is locked down with a `.htaccess` deny rule.
- CAPI calls become blocking (instead of fire-and-forget) so the HTTP
  response from Meta is also logged.

Leave debug mode off in normal operation — the CAPI call becomes
non-blocking and adds no latency to page loads.

## Installing / updating

The `lifexmarketing/metatrac` GitHub repo is **private**. The bundled
Plugin Update Checker needs a GitHub personal access token (read access to
this repo) to check for and download updates. Set it once per site, either:

- in `wp-config.php` (preferred — doesn't sit in the database):
  ```php
  define( 'METATRAC_GITHUB_TOKEN', 'ghp_xxxxxxxxxxxxxxxxxxxx' );
  ```
- or in the **GitHub Update Token** field on the MetaTrac settings page.

Without a token, the plugin still works fully; it just won't be able to
check GitHub for new versions.

## Maintenance notes

- `METATRAC_GRAPH_API_VERSION` (in `metatrac.php`) pins the Graph API version
  used for CAPI calls. Meta deprecates versions roughly every two years —
  bump it if Meta announces the pinned version is sunsetting.
- All settings live in a single `metatrac_settings` option.
