<?php
/**
 * Class Metatrac_WooCommerce_Tracker
 *
 * Hooks into WooCommerce, builds the standard event payload for each
 * enabled event, and fans it out to the Pixel queue, the Conversions API,
 * and the debug logger.
 *
 * AddToCart note: WooCommerce's default "Ajax add to cart" flow doesn't
 * reload the page, so there's no server-rendered page for the browser Pixel
 * call to live on. We solve that with the woocommerce_add_to_cart_fragments
 * filter below, which is the same technique used by GTM/analytics plugins to
 * run JS after an ajax add-to-cart completes.
 *
 * That trick doesn't work at all, though, when WooCommerce > Settings >
 * Products > "Redirect to the cart page after successful addition" is
 * enabled: WooCommerce's own JS checks that setting before it ever touches
 * the ajax response's fragments and just navigates straight to the cart
 * page, and a classic (non-ajax) submit does the same via a server-side
 * redirect before this request ever reaches wp_footer. The Conversions API
 * call already went out by then, so without a fallback the Pixel side of
 * AddToCart would never fire and Meta can't dedupe the two. See
 * is_redirect_after_add(), track_add_to_cart(), and
 * replay_pending_add_to_cart() for how the event is carried over the
 * session to the next page load (the cart page) in that case.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Metatrac_WooCommerce_Tracker {

	/**
	 * The AddToCart event built during this request, if any, picked up by
	 * add_to_cart_fragment() to inject into the ajax add-to-cart response.
	 *
	 * @var array|null
	 */
	private $pending_add_to_cart_event = null;

	/**
	 * Registers hooks for every event enabled in settings.
	 */
	public function init() {
		if ( Metatrac_Settings::is_event_enabled( 'ViewContent' ) ) {
			add_action( 'woocommerce_after_single_product', [ $this, 'track_view_content' ], 10 );
		}

		if ( Metatrac_Settings::is_event_enabled( 'AddToCart' ) ) {
			add_action( 'woocommerce_add_to_cart', [ $this, 'track_add_to_cart' ], 10, 6 );
			add_filter( 'woocommerce_add_to_cart_fragments', [ $this, 'add_to_cart_fragment' ] );
			add_action( 'wp_footer', [ $this, 'render_add_to_cart_placeholder' ], 5 );
			add_action( 'wp', [ $this, 'replay_pending_add_to_cart' ] );
		}

		if ( Metatrac_Settings::is_event_enabled( 'InitiateCheckout' ) ) {
			// Hooked to 'wp' and gated on is_checkout() rather than the classic
			// woocommerce_before_checkout_form hook, since that hook only fires
			// from the [woocommerce_checkout] shortcode template — it never runs
			// for the block-based Checkout, which is the default on newer stores.
			add_action( 'wp', [ $this, 'track_initiate_checkout' ] );
		}

		if ( Metatrac_Settings::is_event_enabled( 'Purchase' ) ) {
			add_action( 'woocommerce_thankyou', [ $this, 'track_purchase' ], 10, 1 );
		}
	}

	/**
	 * Tracks ViewContent on single product pages.
	 */
	public function track_view_content() {
		global $product;

		if ( ! is_product() || ! $product instanceof WC_Product ) {
			return;
		}

		Metatrac_Pixel::fire_event( 'ViewContent', $this->build_product_data( $product, 1 ), Metatrac_Pixel::current_url() );
	}

	/**
	 * Tracks AddToCart, both for classic form submits and ajax adds.
	 *
	 * @param string $cart_item_key  Cart item key.
	 * @param int    $product_id     Product ID.
	 * @param int    $quantity       Quantity added.
	 * @param int    $variation_id   Variation ID, if any.
	 * @param array  $variation      Variation data, if any.
	 * @param array  $cart_item_data Extra cart item data.
	 */
	public function track_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$page_url    = wp_get_referer() ? wp_get_referer() : Metatrac_Pixel::current_url();
		$custom_data = $this->build_product_data( $product, $quantity );

		// Queues it for a normal page render (classic non-ajax add to cart),
		// sends the CAPI copy, and logs it.
		$event_id = Metatrac_Pixel::fire_event( 'AddToCart', $custom_data, $page_url );

		if ( $this->is_redirect_after_add() ) {
			// This request (or the ajax response it returns) will never
			// render a footer the Pixel side could fire from; see the class
			// docblock. Stash it in the session for replay_pending_add_to_cart()
			// to pick up on the next page load instead.
			if ( WC()->session ) {
				WC()->session->set(
					'metatrac_pending_add_to_cart',
					[
						'name'   => 'AddToCart',
						'params' => $custom_data,
						'id'     => $event_id,
					]
				);
			}
			return;
		}

		// Also stash it so the ajax fragment handler below can push it to the
		// browser immediately, in case this request never renders a footer.
		$this->pending_add_to_cart_event = [
			'name'   => 'AddToCart',
			'params' => $custom_data,
			'id'     => $event_id,
		];
	}

	/**
	 * Whether WooCommerce > Settings > Products > "Redirect to the cart page
	 * after successful addition" is enabled.
	 *
	 * @return bool
	 */
	private function is_redirect_after_add() {
		return 'yes' === get_option( 'woocommerce_cart_redirect_after_add' );
	}

	/**
	 * Fires the Pixel side of an AddToCart event that was stashed in the
	 * session because the request that created it was about to redirect
	 * away before a footer could render. Runs on every front-end page load,
	 * so it picks the event up on whichever page the shopper lands on next
	 * (normally the cart page). The matching CAPI event, sharing the same
	 * event_id, was already sent in track_add_to_cart().
	 */
	public function replay_pending_add_to_cart() {
		if ( ! WC()->session ) {
			return;
		}

		$pending = WC()->session->get( 'metatrac_pending_add_to_cart' );
		if ( ! $pending ) {
			return;
		}

		WC()->session->__unset( 'metatrac_pending_add_to_cart' );
		Metatrac_Pixel::queue_event( $pending['name'], $pending['params'], $pending['id'] );
	}

	/**
	 * Injects a script fragment carrying the AddToCart event into
	 * WooCommerce's ajax add-to-cart response.
	 *
	 * @param array $fragments Fragments keyed by CSS selector.
	 * @return array
	 */
	public function add_to_cart_fragment( $fragments ) {
		if ( ! $this->pending_add_to_cart_event ) {
			return $fragments;
		}

		$fragments['div.metatrac-atc-fragment'] = sprintf(
			'<div class="metatrac-atc-fragment" style="display:none;"><script>if(window.metatracFireEvent){metatracFireEvent(%s);}</script></div>',
			wp_json_encode( $this->pending_add_to_cart_event, JSON_HEX_TAG | JSON_HEX_AMP )
		);

		return $fragments;
	}

	/**
	 * Renders the placeholder element that WooCommerce's ajax fragment
	 * replacement needs to already exist in the DOM before it can be swapped.
	 */
	public function render_add_to_cart_placeholder() {
		echo '<div class="metatrac-atc-fragment" style="display:none;"></div>';
	}

	/**
	 * Tracks InitiateCheckout on the checkout page (classic or block-based),
	 * as long as it's not the order-received page and the cart isn't empty.
	 *
	 * Deduped per cart contents via WC's cart hash, so refreshing or
	 * revisiting the checkout page with an unchanged cart doesn't refire the
	 * event; a genuinely changed cart (items added/removed) fires again.
	 */
	public function track_initiate_checkout() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
			return;
		}

		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}

		$cart_hash = WC()->cart->get_cart_hash();

		if ( WC()->session && $cart_hash === WC()->session->get( 'metatrac_initiate_checkout_hash' ) ) {
			return;
		}

		Metatrac_Pixel::fire_event( 'InitiateCheckout', $this->build_cart_data( WC()->cart ), Metatrac_Pixel::current_url() );

		if ( WC()->session ) {
			WC()->session->set( 'metatrac_initiate_checkout_hash', $cart_hash );
		}
	}

	/**
	 * Tracks Purchase on the order-received (thank you) page, once per order.
	 *
	 * @param int $order_id Order ID.
	 */
	public function track_purchase( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_metatrac_purchase_tracked' ) ) {
			return;
		}

		$custom_data = $this->build_order_data( $order );

		Metatrac_Pixel::fire_event(
			'Purchase',
			$custom_data,
			Metatrac_Pixel::current_url(),
			[
				'email' => $order->get_billing_email(),
				'phone' => $order->get_billing_phone(),
			]
		);

		$order->update_meta_data( '_metatrac_purchase_tracked', 1 );
		$order->save();
	}

	/**
	 * Builds the standard content payload for a single product/quantity.
	 *
	 * @param WC_Product $product  Product.
	 * @param int        $quantity Quantity.
	 * @return array
	 */
	private function build_product_data( WC_Product $product, $quantity ) {
		$price = (float) $product->get_price();

		return [
			'content_ids'      => [ (string) $product->get_id() ],
			'content_type'     => 'product',
			'content_name'     => $product->get_name(),
			'content_category' => $this->get_product_category( $product ),
			'currency'         => get_woocommerce_currency(),
			'value'            => $price * $quantity,
			'contents'         => [
				[
					'id'         => (string) $product->get_id(),
					'quantity'   => (int) $quantity,
					'item_price' => $price,
				],
			],
		];
	}

	/**
	 * Builds the standard content payload for everything in a cart.
	 *
	 * @param WC_Cart $cart Cart.
	 * @return array
	 */
	private function build_cart_data( WC_Cart $cart ) {
		$content_ids = [];
		$contents    = [];
		$value       = 0;

		foreach ( $cart->get_cart() as $cart_item ) {
			$product  = $cart_item['data'];
			$quantity = (int) $cart_item['quantity'];
			$price    = (float) $product->get_price();

			$content_ids[] = (string) $product->get_id();
			$contents[]    = [
				'id'         => (string) $product->get_id(),
				'quantity'   => $quantity,
				'item_price' => $price,
			];
			$value        += $price * $quantity;
		}

		return [
			'content_ids'  => $content_ids,
			'content_type' => 'product',
			'currency'     => get_woocommerce_currency(),
			'value'        => $value,
			'contents'     => $contents,
			'num_items'    => array_sum( wp_list_pluck( $contents, 'quantity' ) ),
		];
	}

	/**
	 * Builds the standard content payload for a completed order.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private function build_order_data( WC_Order $order ) {
		$content_ids = [];
		$contents    = [];

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			$content_ids[] = (string) $product->get_id();
			$contents[]    = [
				'id'         => (string) $product->get_id(),
				'quantity'   => (int) $item->get_quantity(),
				'item_price' => (float) $order->get_item_total( $item, false, false ),
			];
		}

		return [
			'content_ids'  => $content_ids,
			'content_type' => 'product',
			'currency'     => $order->get_currency(),
			'value'        => (float) $order->get_total(),
			'contents'     => $contents,
			'num_items'    => array_sum( wp_list_pluck( $contents, 'quantity' ) ),
		];
	}

	/**
	 * Gets a product's primary category name, if any.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private function get_product_category( WC_Product $product ) {
		$categories = wp_get_post_terms( $product->get_id(), 'product_cat' );
		return ! empty( $categories ) && ! is_wp_error( $categories ) ? $categories[0]->name : '';
	}
}
