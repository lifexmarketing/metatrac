<?php
/**
 * Plugin Name: MetaTrac
 * Plugin URI: https://www.lifexmarketing.com/metatrac/
 * Description: Tracks PageView, Contact, and Lead events out of the box, plus WooCommerce ecommerce events (ViewContent, AddToCart, InitiateCheckout, Purchase) when WooCommerce is active, and sends them to Meta via both the Pixel (browser) and the Conversions API (server), with per-site event selection and a debug mode for console + log-file visibility.
 * Version: 1.0.7
 * Author: LifeX Marketing
 * Author URI: https://www.lifexmarketing.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: metatrac
 */

// Prevent direct access to the plugin file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants for easy referencing.
define( 'METATRAC_VERSION', '1.0.7' );
define( 'METATRAC_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'METATRAC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'METATRAC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Graph API version used for Conversions API requests. Meta deprecates versions
// roughly every two years; bump this if Meta announces this version is sunset.
if ( ! defined( 'METATRAC_GRAPH_API_VERSION' ) ) {
	define( 'METATRAC_GRAPH_API_VERSION', 'v21.0' );
}

// Include the dependency checker class.
require_once METATRAC_PLUGIN_PATH . 'includes/class-metatrac-dependency-checker.php';

/**
 * Initializes the plugin, loading the WooCommerce ecommerce tracker only
 * when WooCommerce is active.
 */
function metatrac_init() {
	require_once METATRAC_PLUGIN_PATH . 'includes/class-metatrac-settings.php';
	require_once METATRAC_PLUGIN_PATH . 'includes/class-metatrac-logger.php';
	require_once METATRAC_PLUGIN_PATH . 'includes/class-metatrac-capi.php';
	require_once METATRAC_PLUGIN_PATH . 'includes/class-metatrac-pixel.php';
	require_once METATRAC_PLUGIN_PATH . 'includes/class-metatrac-contact-tracker.php';
	require_once METATRAC_PLUGIN_PATH . 'includes/class-metatrac-gravity-forms-tracker.php';

	$pixel = new Metatrac_Pixel();
	$pixel->init();

	$contact_tracker = new Metatrac_Contact_Tracker();
	$contact_tracker->init();

	$gravity_forms_tracker = new Metatrac_Gravity_Forms_Tracker();
	$gravity_forms_tracker->init();

	// The ecommerce events (ViewContent, AddToCart, InitiateCheckout,
	// Purchase) only make sense with WooCommerce active, and Lead only makes
	// sense with Gravity Forms active; PageView and Contact are tracked above
	// regardless.
	$dependency_checker   = new Metatrac_Dependency_Checker();
	$woocommerce_active   = $dependency_checker->is_woocommerce_active();
	$gravity_forms_active = $dependency_checker->is_gravity_forms_active();

	if ( $woocommerce_active ) {
		require_once METATRAC_PLUGIN_PATH . 'includes/class-metatrac-woocommerce-tracker.php';

		$tracker = new Metatrac_WooCommerce_Tracker();
		$tracker->init();
	}

	if ( is_admin() ) {
		// WordPress's Plugins page "Update Now" link installs the update
		// over admin-ajax.php (action=update-plugin), not a normal page
		// load, and it refuses to proceed unless this filter is registered
		// on *that* request to tell it a newer version exists. Every other
		// admin-ajax request (e.g. the ajax AddToCart call tracked above)
		// is excluded, or this would load on nearly every front-end
		// interaction.
		$doing_plugin_update_ajax = wp_doing_ajax()
			&& isset( $_REQUEST['action'] ) && 'update-plugin' === $_REQUEST['action'];

		if ( ! wp_doing_ajax() || $doing_plugin_update_ajax ) {
			metatrac_init_update_checker();
		}

		if ( ! wp_doing_ajax() ) {
			require_once METATRAC_PLUGIN_PATH . 'admin/class-metatrac-admin-settings.php';
			new Metatrac_Admin_Settings();

			// Each notice is only ever shown once, on the MetaTrac settings page
			// itself, not on every wp-admin screen; see
			// Metatrac_Dependency_Checker::is_notice_shown().
			$on_settings_page = isset( $_GET['page'] ) && Metatrac_Admin_Settings::PAGE_SLUG === $_GET['page'];

			if ( $on_settings_page && ! $woocommerce_active
				&& ! $dependency_checker->is_notice_shown( Metatrac_Dependency_Checker::WOOCOMMERCE_NOTICE_SHOWN_OPTION ) ) {
				add_action( 'admin_notices', [ $dependency_checker, 'woocommerce_notice' ] );
			}

			if ( $on_settings_page && ! $gravity_forms_active
				&& ! $dependency_checker->is_notice_shown( Metatrac_Dependency_Checker::GRAVITY_FORMS_NOTICE_SHOWN_OPTION ) ) {
				add_action( 'admin_notices', [ $dependency_checker, 'gravity_forms_notice' ] );
			}
		}
	}
}
add_action( 'plugins_loaded', 'metatrac_init' );

/**
 * Wires up the GitHub-hosted plugin update checker.
 *
 * The lifexmarketing/metatrac repo is public, so update checks work without
 * any authentication.
 */
function metatrac_init_update_checker() {
	require_once METATRAC_PLUGIN_PATH . 'plugin-update-checker/plugin-update-checker.php';

	\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/lifexmarketing/metatrac',
		__FILE__,
		'metatrac'
	);
}
