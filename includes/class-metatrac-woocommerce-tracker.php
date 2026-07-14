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
 * run JS after an ajax add-to-cart completes. If a theme disables ajax add
 * to cart (redirecting to the cart page instead), the Conversions API side
 * still fires; only the browser Pixel call for that specific add is skipped.
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
		}

		if ( Metatrac_Settings::is_event_enabled( 'InitiateCheckout' ) ) {
			add_action( 'woocommerce_before_checkout_form', [ $this, 'track_initiate_checkout' ], 10 );
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

		$this->fire_event( 'ViewContent', $this->build_product_data( $product, 1 ), Metatrac_Pixel::current_url() );
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
		$event_id    = $this->fire_event( 'AddToCart', $custom_data, $page_url, [], false );

		// Queue for a normal page render (classic non-ajax add to cart).
		Metatrac_Pixel::queue_event( 'AddToCart', $custom_data, $event_id );

		// Also stash it so the ajax fragment handler below can push it to the
		// browser immediately, in case this request never renders a footer.
		$this->pending_add_to_cart_event = [
			'name'   => 'AddToCart',
			'params' => $custom_data,
			'id'     => $event_id,
		];
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
			wp_json_encode( $this->pending_add_to_cart_event )
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
	 * Tracks InitiateCheckout when the checkout form loads with items in the cart.
	 */
	public function track_initiate_checkout() {
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}

		$this->fire_event( 'InitiateCheckout', $this->build_cart_data( WC()->cart ), Metatrac_Pixel::current_url() );
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

		$this->fire_event(
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
	 * Queues an event for the Pixel, sends it to the Conversions API, and logs it.
	 *
	 * @param string $event_name      Standard Meta event name.
	 * @param array  $custom_data     custom_data payload.
	 * @param string $page_url        Page the event is associated with.
	 * @param array  $extra_user_data Optional email/phone overrides for CAPI matching.
	 * @param bool   $queue_for_pixel Whether to queue this for the footer Pixel flush
	 *                                (false when the caller queues it separately, e.g. AddToCart).
	 * @return string The event_id used, so callers can reuse it.
	 */
	private function fire_event( $event_name, array $custom_data, $page_url, array $extra_user_data = [], $queue_for_pixel = true ) {
		$event_id = wp_generate_uuid4();

		if ( $queue_for_pixel ) {
			Metatrac_Pixel::queue_event( $event_name, $custom_data, $event_id );
		}

		( new Metatrac_CAPI() )->send_event( $event_name, $event_id, $custom_data, $page_url, $extra_user_data );
		Metatrac_Logger::log_event( $event_name, $page_url, $event_id, $custom_data );

		return $event_id;
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
