<?php
/**
 * Class Metatrac_Dependency_Checker
 *
 * Checks if required plugins are active.
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
	 * Checks if all required dependencies are met.
	 *
	 * @return bool
	 */
	public function check_dependencies() {
		return $this->is_woocommerce_active();
	}

	/**
	 * Displays an admin notice if dependencies are not met.
	 */
	public function dependency_notice() {
		if ( ! $this->is_woocommerce_active() ) {
			?>
			<div class="notice notice-warning is-dismissible">
				<p><?php esc_html_e( 'MetaTrac requires WooCommerce to be installed and activated.', 'metatrac' ); ?></p>
			</div>
			<?php
		}
	}
}
