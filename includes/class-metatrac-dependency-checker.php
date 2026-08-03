<?php
/**
 * Class Metatrac_Dependency_Checker
 *
 * Checks whether optional plugin dependencies are active. WooCommerce is
 * only needed for the ecommerce events (ViewContent, AddToCart,
 * InitiateCheckout, Purchase); PageView, Contact, and Lead all work without
 * it, so this no longer gates the plugin as a whole.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Metatrac_Dependency_Checker {

	/**
	 * Checks if WooCommerce is active.
	 *
	 * @return bool
	 */
	public function is_woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Displays an admin notice that ecommerce events won't be tracked.
	 */
	public function dependency_notice() {
		?>
		<div class="notice notice-warning is-dismissible">
			<p><?php esc_html_e( 'MetaTrac: WooCommerce is not active, so ecommerce events (ViewContent, AddToCart, InitiateCheckout, Purchase) will not be tracked. PageView, Contact, and Lead tracking are unaffected.', 'metatrac' ); ?></p>
		</div>
		<?php
	}
}
