<?php
/**
 * Class Metatrac_Dependency_Checker
 *
 * Checks whether optional plugin dependencies are active. WooCommerce is
 * only needed for the ecommerce events (ViewContent, AddToCart,
 * InitiateCheckout, Purchase); Gravity Forms is only needed for the Lead
 * event. PageView and Contact work without either, so neither gates the
 * plugin as a whole.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Metatrac_Dependency_Checker {

	const WOOCOMMERCE_NOTICE_SHOWN_OPTION   = 'metatrac_woo_notice_shown';
	const GRAVITY_FORMS_NOTICE_SHOWN_OPTION = 'metatrac_gf_notice_shown';

	/**
	 * Checks if WooCommerce is active.
	 *
	 * @return bool
	 */
	public function is_woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Checks if Gravity Forms is active.
	 *
	 * @return bool
	 */
	public function is_gravity_forms_active() {
		return class_exists( 'GFForms' );
	}

	/**
	 * Whether a given dependency notice has already been shown once. Notices
	 * are only meant to greet the admin the first time they land on the
	 * MetaTrac settings page with the dependency inactive, not on every page
	 * load.
	 *
	 * @param string $option One of the *_NOTICE_SHOWN_OPTION constants above.
	 * @return bool
	 */
	public function is_notice_shown( $option ) {
		return (bool) get_option( $option, false );
	}

	/**
	 * Displays an admin notice that ecommerce events won't be tracked. The
	 * notice records itself as shown so it won't render again on the next
	 * page load; the "is-dismissible" X button also hides it immediately.
	 */
	public function woocommerce_notice() {
		update_option( self::WOOCOMMERCE_NOTICE_SHOWN_OPTION, true );
		?>
		<div class="notice notice-warning is-dismissible">
			<p><?php esc_html_e( 'MetaTrac: WooCommerce is not active, so ecommerce events (ViewContent, AddToCart, InitiateCheckout, Purchase) will not be tracked. PageView, Contact, and Lead tracking are unaffected.', 'metatrac' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Displays an admin notice that the Lead event won't be tracked. Same
	 * once-only behavior as woocommerce_notice() above.
	 */
	public function gravity_forms_notice() {
		update_option( self::GRAVITY_FORMS_NOTICE_SHOWN_OPTION, true );
		?>
		<div class="notice notice-warning is-dismissible">
			<p><?php esc_html_e( 'MetaTrac: Gravity Forms is not active, so the Lead event will not be tracked. PageView, Contact, and ecommerce tracking are unaffected.', 'metatrac' ); ?></p>
		</div>
		<?php
	}
}
