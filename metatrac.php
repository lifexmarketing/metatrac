<?php
/**
 * Plugin Name: MetaTrac
 * Plugin URI: https://www.lifexmarketing.com/metatrac/
 * Description: Tracks PageView, Contact, and Lead events out of the box, plus WooCommerce ecommerce events (ViewContent, AddToCart, InitiateCheckout, Purchase) when WooCommerce is active, and sends them to Meta via both the Pixel (browser) and the Conversions API (server), with per-site event selection and a debug mode for console + log-file visibility.
 * Version: 1.0.3
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
define( 'METATRAC_VERSION', '1.0.3' );
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
	// Purchase) only make sense with WooCommerce active; PageView, Contact,
	// and Lead are tracked above regardless.
	$dependency_checker = new Metatrac_Dependency_Checker();
	$woocommerce_active = $dependency_checker->is_woocommerce_active();

	if ( $woocommerce_active ) {
		require_once METATRAC_PLUGIN_PATH . 'includes/class-metatrac-woocommerce-tracker.php';

		$tracker = new Metatrac_WooCommerce_Tracker();
		$tracker->init();
	}

	// is_admin() is also true for admin-ajax.php requests (e.g. the ajax
	// AddToCart call tracked above), so exclude those or the update checker
	// and admin settings would load on nearly every front-end interaction.
	if ( is_admin() && ! wp_doing_ajax() ) {
		require_once METATRAC_PLUGIN_PATH . 'admin/class-metatrac-admin-settings.php';
		new Metatrac_Admin_Settings();

		if ( ! $woocommerce_active ) {
			add_action( 'admin_notices', [ $dependency_checker, 'dependency_notice' ] );
		}

		metatrac_init_update_checker();
	}
}
add_action( 'plugins_loaded', 'metatrac_init' );

/**
 * Wires up the GitHub-hosted plugin update checker.
 *
 * The lifexmarketing/metatrac repo is public, so update checks work without
 * any authentication. A GitHub personal access token is optional and only
 * raises the GitHub API rate limit; prefer defining METATRAC_GITHUB_TOKEN in
 * wp-config.php (not stored in the database) over the settings-screen field.
 */
function metatrac_init_update_checker() {
	require_once METATRAC_PLUGIN_PATH . 'plugin-update-checker/plugin-update-checker.php';

	$update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/lifexmarketing/metatrac',
		__FILE__,
		'metatrac'
	);

	$github_token = defined( 'METATRAC_GITHUB_TOKEN' ) ? METATRAC_GITHUB_TOKEN : Metatrac_Settings::get( 'github_token' );

	if ( ! empty( $github_token ) && method_exists( $update_checker, 'setAuthentication' ) ) {
		$update_checker->setAuthentication( $github_token );
	}
}
