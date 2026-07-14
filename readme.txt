=== MetaTrac ===
Contributors: lifexmarketing
Tags: woocommerce, meta, facebook, pixel, conversions api
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Tracks core WooCommerce ecommerce events and sends them to Meta via the Pixel and the Conversions API.

== Description ==

MetaTrac tracks ViewContent, AddToCart, InitiateCheckout, and Purchase on a
WooCommerce store and sends each event to Meta twice: once from the browser
via the Meta Pixel, and once from the server via the Conversions API, sharing
an event_id so Meta deduplicates the pair. Which events are tracked, and
whether debug logging is on, are configured per site under WooCommerce > MetaTrac.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/metatrac`, or install the zip
   through the Plugins screen.
2. Activate MetaTrac.
3. Go to WooCommerce > MetaTrac and enter your Meta Pixel ID and Conversions
   API access token.
4. Choose which events to track.

== Changelog ==

= 1.0.0 =
* Initial release: ViewContent, AddToCart, InitiateCheckout, and Purchase,
  tracked via both the Meta Pixel and the Conversions API, with a debug mode
  and per-site event selection.
