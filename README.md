# MetaTrac

A WordPress/WooCommerce plugin that tracks core ecommerce and lead-gen events
and sends them to Meta through both the browser Pixel and the server-side
Conversions API (CAPI), so tracking survives ad blockers and iOS ATT opt-outs.
Built as a successor to the internal `zooraz` (Cloudflare Zaraz) plugin, but
talks to Meta directly instead of going through a dataLayer/Zaraz intermediary.

Installed on multiple client sites and updated centrally from this repo via
the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker).

## Requirements

- A Meta Pixel ID and a Conversions API access token (Events Manager > Data
  Sources > your Pixel > Settings > Conversions API > Generate access token).
- WooCommerce active, only if you want the ecommerce events (`ViewContent`,
  `AddToCart`, `InitiateCheckout`, `Purchase`); `PageView`, `Contact`,
  `FindLocation`, and `Lead` all work without it. On the settings screen, the
  ecommerce checkboxes are grayed out while WooCommerce is inactive.
- Gravity Forms active, only if you want the `Lead` event; MetaTrac still
  works fully without it, `Lead` just never fires.

## Setup on a site

1. Install the plugin (see "Installing / updating" below).
2. Go to **Settings > MetaTrac** (requires the `manage_options` capability).
3. Enter the **Meta Pixel ID** and **Conversions API Access Token**.
4. Check which events to track: `ViewContent`, `AddToCart`,
   `InitiateCheckout`, `Purchase`, `Contact`, `FindLocation`, `Lead`.
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
| `InitiateCheckout`  | Checkout page load, if the cart isn't empty; deduped per cart contents so refreshing/revisiting an unchanged cart doesn't refire it |
| `Purchase`          | Order-received ("thank you") page, once per order        |
| `Contact`           | First click/tap on a `tel:`/`sms:` link and/or a `mailto:` link anywhere on the site (independently toggled), once per browser session |
| `FindLocation`      | First click/tap on a link to Google Maps anywhere on the site (a "Get Directions" link, an embedded map's "View larger map" link, a `goo.gl/maps`/`maps.app.goo.gl` short link, etc.), once per browser session |
| `Lead`              | A Gravity Forms submission (`gform_after_submission`), for the forms selected in Lead's settings (all active forms by default) |

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

### Contact (tel:/sms:/mailto: link clicks)

There's no server-side hook for "a link was clicked", so detection happens
entirely in `assets/js/metatrac-frontend.js`: a delegated click listener
matches `a[href^="tel:"]`/`a[href^="sms:"]` and/or `a[href^="mailto:"]` on
the page, gated by two independent checkboxes on the settings screen
("Phone/SMS Link Clicked" and "Mailto Link Clicked," both under Events to
Track > Contact) so either can be turned on without the other; mailto is off
by default, since a mailto: link is a much weaker Lead signal than a
tel:/sms: link on most sites. On the first match of either kind in a browser
session (tracked via `sessionStorage`, so it resets when the tab/browser
closes, not tied to a WooCommerce/PHP session), it fires the Pixel side
immediately and calls a dedicated `admin-ajax.php` endpoint
(`metatrac_contact`) for the CAPI side, sharing the same `event_id` between
the two.

### FindLocation (Google Maps link clicks)

Same mechanism as Contact, its own delegated click listener in
`assets/js/metatrac-frontend.js`, independently enabled/disabled and gated
on its own once-per-session `sessionStorage` key. A click matches when the
clicked link's resolved hostname/pathname point at Google Maps:
`google.<tld>/maps...` (e.g. an embedded map's "View larger map" link, or a
"Get Directions" link), `maps.google.<tld>/...`, `goo.gl/maps/...`, or
`maps.app.goo.gl/...`. Matching is done against the link's own `hostname`/
`pathname` properties rather than a regex over the raw `href`, so a link on
some unrelated domain that merely happens to contain "google.com/maps" in a
query string isn't mistaken for a Maps link. Fires the Pixel side
immediately and calls a dedicated `admin-ajax.php` endpoint
(`metatrac_find_location`) for the CAPI side, sharing the same `event_id`
between the two.

### Lead (Gravity Forms submissions)

Fires via `gform_after_submission`, which Gravity Forms already skips for
entries flagged as spam. `custom_data.content_name` is set to the form's
title. Unlike AddToCart, no ajax-fragment workaround is needed: Gravity
Forms' own AJAX submission mechanism re-renders the entire page template
(including `wp_head`/`wp_footer`) inside a hidden iframe, so the Pixel event
queued during that request flushes normally through the existing footer
script.

By default, every active form on the site fires Lead. The MetaTrac settings
screen (Settings > MetaTrac > Events to Track > Lead) can instead be
switched to "Only these forms," listing every active Gravity Form so
specific forms (e.g. a newsletter signup) can be excluded from Lead without
disabling Lead tracking site-wide.

**Known limitation:** if a form's confirmation is set to "Redirect to a URL"
or "Redirect to a page," the browser navigates away right after submitting,
so the browser Pixel call could be lost if the redirect fires before the
footer script runs. The server-side CAPI event still fires normally either way.

## Debug mode

When enabled:

- Every fired event is `console.log`'d in the browser as
  `[MetaTrac] Event fired: <EventName>`.
- Every fired event (Pixel-side build, not just CAPI) is appended to a
  dedicated log file, independent of `WP_DEBUG_LOG`, at:

  ```
  wp-content/uploads/metatrac-logs/debug-<random-token>.log
  ```

  The random token is generated once per site and shown on the MetaTrac
  settings page while debug mode is on. It's there because the directory's
  `.htaccess` deny rule only works on Apache; on other servers, an
  unpredictable filename is what keeps the log from being directly requestable.

  Each line includes the event name, the page it fired on, its `event_id`,
  and its payload.
- CAPI calls become blocking (instead of fire-and-forget) so the HTTP
  response from Meta is also logged.

Leave debug mode off in normal operation — the CAPI call becomes
non-blocking and adds no latency to page loads.

## Installing / updating

The `lifexmarketing/metatrac` GitHub repo is **public**, so the bundled
Plugin Update Checker works out of the box with no token needed.

## Maintenance notes

- `METATRAC_GRAPH_API_VERSION` (in `metatrac.php`) pins the Graph API version
  used for CAPI calls. Meta deprecates versions roughly every two years —
  bump it if Meta announces the pinned version is sunsetting.
- All settings live in a single `metatrac_settings` option.
