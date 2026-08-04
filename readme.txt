=== MetaTrac ===
Contributors: lifexmarketing
Tags: woocommerce, meta, facebook, pixel, conversions api
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Tracks PageView, Contact, and Lead events, plus WooCommerce ecommerce events when WooCommerce is active, and sends them to Meta via the Pixel and the Conversions API.

== Description ==

MetaTrac tracks PageView, Contact (tel:/sms: link clicks, once per session),
and Lead (Gravity Forms submissions, if Gravity Forms is active) on any
WordPress site, plus ViewContent, AddToCart, InitiateCheckout, and Purchase
if WooCommerce is active. Each event is sent to Meta twice: once from the
browser via the Meta Pixel, and once from the server via the Conversions
API, sharing an event_id so Meta deduplicates the pair. Which events are
tracked, and whether debug logging is on, are configured per site under
Settings > MetaTrac; the ecommerce events are grayed out there while
WooCommerce is inactive.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/metatrac`, or install the zip
   through the Plugins screen.
2. Activate MetaTrac.
3. Go to Settings > MetaTrac and enter your Meta Pixel ID and Conversions
   API access token.
4. Choose which events to track.

== Changelog ==

= 1.0.2 =
* Ecommerce events (ViewContent, AddToCart, InitiateCheckout, Purchase) are
  now conditional on WooCommerce being active, instead of the whole plugin
  requiring it. PageView, Contact, and Lead now track regardless. The
  ecommerce checkboxes on the settings screen gray out while WooCommerce
  is inactive.
* Added a "Settings" link to MetaTrac's row on the Plugins screen.

= 1.0.1 =
* Fix: InitiateCheckout wasn't firing on stores using the block-based
  Checkout, since it relied on a hook that only fires from the classic
  `[woocommerce_checkout]` shortcode template. Now detected via `is_checkout()`
  instead, so it works with either.
* Moved the settings screen from WooCommerce > MetaTrac to Settings > MetaTrac
  (now requires `manage_options` instead of `manage_woocommerce`).

= 1.0.0 =
* Initial release: ViewContent, AddToCart, InitiateCheckout, and Purchase,
  tracked via both the Meta Pixel and the Conversions API, with a debug mode
  and per-site event selection.
