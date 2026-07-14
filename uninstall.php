<?php
/**
 * Fired when the plugin is deleted (not just deactivated) via the WordPress
 * admin. Removes the settings option so it doesn't linger in the database.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'metatrac_settings' );
