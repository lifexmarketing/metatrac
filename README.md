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
5. Optionally, under **Page Events**, assign any of [Meta's standard
   events](https://www.facebook.com/business/help/402791146561655?id=1205376682832142)
   to any published page, to fire that event on every load of that page;
   see "Page Events" below.
6. Optionally turn on **Debug Mode** while verifying a new install (see
   "Debug mode" below), and turn it back off once confirmed.
7. Optionally paste a **Test Event Code** from Events Manager > Test Events
   while verifying CAPI delivery, then remove it.

## How events are tracked

Every event fires twice, sharing the same `event_id` so Meta deduplicates
the browser and server copies:

- **Browser (Pixel)**: `fbq('track', ...)`, queued during page render and
  flushed in the footer (or, for ajax add-to-cart, pushed via a WooCommerce
  fragment, see below).
- **Server (CAPI)**: a `wp_remote_post()` to `graph.facebook.com`, including
  `fbp`/`fbc` cookies, client IP/user agent, and, when available, a hashed
  email/phone for match quality.

**Page-cache safety:** `ViewContent`, `InitiateCheckout`, and Page Events are
queued via `Metatrac_Pixel::queue_deferred_event()` rather than
`fire_event()`, since they fire from an ordinary page render that a caching
plugin might serve unchanged to many different visitors. `fire_event()`
mints the `event_id` and sends the CAPI call at render time, which is only
safe when that specific render is guaranteed to run fresh per visitor (an
ajax response, or a page with a naturally unique URL like an order-received
page): baking a real `event_id` into cached HTML would mean every visitor
served from that cache entry fires a Pixel event Meta dedupes away against
the single CAPI call sent when the cache was generated, undercounting real
traffic for as long as the page stays cached. Deferred events instead mint
their `event_id` in the browser on every real page load (`metatracFireEvent()`
in `assets/js/metatrac-frontend.js`) and report it to a dedicated
`admin-ajax.php` endpoint (`metatrac_deferred_event`) so the CAPI call runs
fresh every time too, the same approach Contact and FindLocation already
use below, just applied to page-load events instead of click events.
`AddToCart` and `Purchase` still use `fire_event()` directly: the ajax
add-to-cart response is never page-cached, and an order-received URL is
unique per order.

Every one of these `admin-ajax.php` endpoints (`metatrac_deferred_event`,
`metatrac_contact`, and `metatrac_find_location`) is itself gated by a WP
nonce that's baked into the same cached page render as the event it's
authorizing. A plain `wp_create_nonce()` is normally only valid for
~12-24 hours, which a page cache can easily outlive (some of the cache
entries that first surfaced the `event_id` bug above were 43-51 hours old),
so each of the three trackers extends its own nonce's lifetime to a week via
the `nonce_life` filter (`extend_nonce_life()` in
`Metatrac_Contact_Tracker`/`Metatrac_Find_Location_Tracker`/`Metatrac_Pixel`).
That comfortably covers realistic cache TTLs without leaving the nonce valid
indefinitely.

**Known limitation:** a cache entry that somehow outlives a week without
being regenerated or purged would still cause that specific event's CAPI
call to silently fail its nonce check (the Pixel call is unaffected, since
it doesn't depend on the nonce). Turning on Debug Mode surfaces this as a
`nonce_check_failed` line in the debug log; previously it failed with no
trace at all.

| Event              | Fires on                                              |
|---------------------|--------------------------------------------------------|
| `ViewContent`       | Single product page view                                |
| `AddToCart`         | `woocommerce_add_to_cart` (ajax or classic form submit)  |
| `InitiateCheckout`  | Checkout page load, if the cart isn't empty; deduped per cart contents so refreshing/revisiting an unchanged cart doesn't refire it |
| `Purchase`          | Order-received ("thank you") page, once per order        |
| `Contact`           | First click/tap on a `tel:`/`sms:` link and/or a `mailto:` link anywhere on the site (independently toggled), once per browser session |
| `FindLocation`      | First click/tap on a link to Google Maps anywhere on the site (a "Get Directions" link, an embedded map's "View larger map" link, a `goo.gl/maps`/`maps.app.goo.gl` short link, etc.), once per browser session |
| `Lead`              | A Gravity Forms submission (`gform_after_submission`), for the forms selected in Lead's settings (all active forms by default) |

### Product pricing (variable products and bundles)

`ViewContent`, `AddToCart`, and `InitiateCheckout`'s `value`/`item_price`
come from `Metatrac_WooCommerce_Tracker::resolve_product_price()`, not a
bare `$product->get_price()`. A plain `get_price()` returns `''`, or
sometimes a literal `'0'` (both cast to a silent `$0`), for a product that
has no *usable* price of its own to report, which is normal for two common
WooCommerce catalog shapes:

- **Variable products**: a `WC_Product_Variation` with no price set
  directly on it walks up to its parent's price (the parent's minimum
  active variation price, i.e. WooCommerce's own "From: $X" price); a
  `WC_Product_Variable` viewed or added before any variation is resolved
  (`ViewContent` on the product page, before the shopper picks options)
  walks down to that same minimum active variation price instead.
- **WooCommerce Product Bundles**: walks down into the bundle's *required*
  bundled items (optional add-ons are skipped, since a given add-to-cart
  might not have included them) and sums their resolved prices. This is
  the path that actually matters in practice: a bundle whose required item
  is itself a variable product with no forced default variation can't be
  priced by WooCommerce up front, so its own `get_price()` comes back as a
  literal `'0'`, not `''`, which is exactly why every check in this
  function treats "empty" and "exactly zero" the same way rather than
  trusting a `'0'` as "genuinely free." This whole branch is a no-op on any
  site without the Product Bundles plugin active.

Falls back to `0.0` only when none of that turns up a usable number (e.g. a
genuinely out-of-stock/unpriced product), logged as
`price_resolution_failed product_id=... type=... own_price=...` in Debug
Mode so a specific product can be tracked down if this ever needs a closer
look.

That bundle handling only covers a bundle's *required* items, since that's
the most a static product definition can promise before anyone's actually
configured one; it has no way to know which optional add-ons a specific
shopper picked. `AddToCart` gets a second pass on top of that for exactly
this reason: `resolve_bundle_cart_total()` looks at the real cart items
WooCommerce Product Bundles already added for whichever optional add-ons
this particular shopper checked (they exist as their own cart items linked
back to the bundle, just hidden from the visible cart table) and, when it
finds any, uses that real total instead of the required-items floor.
`ViewContent` and `InitiateCheckout` can't do this (nothing's in the cart
yet, or the configuration a shopper is currently looking at isn't
necessarily what ends up in it), so they stay on the required-items
estimate. Logged as `bundle_cart_link_not_found product_id=... cart_item_data_keys=...`
in Debug Mode on the rare chance this add-to-cart's linked items can't be
found (only the cart_item_data key names are logged, never their values,
since those may include customer-entered field data).

`Purchase`'s values are untouched by any of this: `build_order_data()`
reads the order's own `get_item_total()`/`get_total()`, real transaction
data rather than a catalog price lookup, so a real $0 line item stays $0
rather than being "corrected" to some estimate.

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

Each form in that list also has its own "Track as Subscribe instead of
Lead" checkbox, for a form where Subscribe is the more accurate standard
event (a newsletter signup is a common case: it's a subscription, not a
sales lead). Checking it only changes which event that form fires
(`Metatrac_Settings::lead_event_for_form()`); it doesn't add the form to
tracking on its own, so it still needs to be covered by "All active Gravity
Forms" or its own checkbox under "Only these forms." A form's Subscribe
event goes through the exact same fan-out as Lead (Pixel, CAPI, the
redirect-confirmation cookie replay below), just under a different name.

**Known limitation:** if a form's confirmation is set to "Redirect to a URL"
or "Redirect to a page," the browser navigates away right after submitting,
so the browser Pixel call could be lost if the redirect fires before the
footer script runs. The server-side CAPI event still fires normally either way.

## Page Events (any standard event, on any page)

Unlike the events above, which are each wired to one specific site action,
Page Events lets an admin assign any of [Meta's 17 standard
events](https://www.facebook.com/business/help/402791146561655?id=1205376682832142)
(everything but `PageView`, which MetaTrac already fires automatically on
every page) to any published WordPress page, independent of the Events to
Track checkboxes. Settings > MetaTrac > Page Events is a small repeater:
each row is a page dropdown paired with an event dropdown, with "+ Add Page
Event" (plain JS, no build step) to add more rows and a "Remove" button on
each row, so the settings screen only grows with however many mappings are
actually configured, rather than listing every page on the site. Picking a
page and event fires that event (via `Metatrac_Pixel::queue_deferred_event()`,
see "Page-cache safety" above) on every load of that page, useful for pages
with no dedicated hook of their own, like a Gravity Forms
redirect-confirmation "Thank You" page (`CompleteRegistration` or
`Schedule`), a pricing page (`ViewContent`), or a signup page (`Subscribe`).

Hooked on `wp`, gated on `is_page()`, so it only ever fires for the specific
pages assigned an event, never posts, archives, or the rest of the site.
Saved mappings are keyed by page ID and revalidated against the site's
actual published pages and Meta's actual standard event list on every save,
so a page that's since been trashed or unpublished can't linger as a stale,
invisible mapping.

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
- An ajax event (`metatrac_deferred_event`, `metatrac_contact`,
  `metatrac_find_location`) rejected for a failed nonce check is logged as
  `nonce_check_failed event=<EventName>` (see "Page-cache safety" above).
- A product `resolve_product_price()` couldn't find any usable price for is
  logged as `price_resolution_failed product_id=... type=... own_price=...`
  (see "Product pricing" above).
- A bundle add-to-cart whose selected optional items couldn't be linked
  back to it is logged as
  `bundle_cart_link_not_found product_id=... cart_item_data_keys=...`
  (see "Product pricing" above).

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
