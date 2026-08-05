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

	const NOTICE_SHOWN_OPTION = 'metatrac_woo_notice_shown';

	/**
	 * Checks if WooCommerce is active.
	 *
	 * @return bool
	 */
	public function is_woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Whether the WooCommerce notice has already been shown once. It's only
	 * meant to greet the admin the first time they land on the MetaTrac
	 * settings page without WooCommerce active, not on every page load.
	 *
	 * @return bool
	 */
	public function is_notice_shown() {
		return (bool) get_option( self::NOTICE_SHOWN_OPTION, false );
	}

	/**
	 * Displays an admin notice that ecommerce events won't be tracked. The
	 * notice records itself as shown so it won't render again on the next
	 * page load; the "is-dismissible" X button also hides it immediately.
	 */
	public function dependency_notice() {
		update_option( self::NOTICE_SHOWN_OPTION, true );
		?>
		<div class="notice notice-warning is-dismissible">
			<p><?php esc_html_e( 'MetaTrac: WooCommerce is not active, so ecommerce events (ViewContent, AddToCart, InitiateCheckout, Purchase) will not be tracked. PageView, Contact, and Lead tracking are unaffected.', 'metatrac' ); ?></p>
		</div>
		<?php
	}
}
