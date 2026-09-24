<?php
/**
 * Class Metatrac_Logger
 *
 * Writes a dedicated debug.log for MetaTrac, independent of WP_DEBUG_LOG, so
 * debug mode behaves the same whether or not a site has core debug logging
 * enabled. The file lives outside the plugin folder (so it survives updates)
 * and is locked down with a .htaccess deny rule plus a blank index.php.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Metatrac_Logger {

	const MAX_LOG_BYTES = 5242880; // 5MB.
	const TOKEN_OPTION  = 'metatrac_log_token';

	/**
	 * Directory the log file lives in.
	 *
	 * @return string
	 */
	private static function log_dir() {
		return trailingslashit( trailingslashit( WP_CONTENT_DIR ) . 'uploads/metatrac-logs' );
	}

	/**
	 * A random per-site token used in the log filename. The .htaccess deny
	 * rule below only works on Apache, so on Nginx (or any host that ignores
	 * .htaccess) an unpredictable filename is the only thing standing between
	 * this file and anyone who requests it directly. Generated once and
	 * persisted, rather than derived from anything guessable.
	 *
	 * @return string
	 */
	private static function log_token() {
		$token = get_option( self::TOKEN_OPTION );

		if ( empty( $token ) || ! is_string( $token ) ) {
			$token = wp_generate_password( 32, false, false );
			update_option( self::TOKEN_OPTION, $token, false );
		}

		return $token;
	}

	/**
	 * Full path to the log file.
	 *
	 * @return string
	 */
	public static function log_file_path() {
		return self::log_dir() . 'debug-' . self::log_token() . '.log';
	}

	/**
	 * Creates the log directory and access-lockdown files if they don't exist yet.
	 */
	private static function ensure_log_dir() {
		$dir = self::log_dir();

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$htaccess = $dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$index = $dir . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Appends a raw line to the log file, trimming it first if it has grown too large.
	 *
	 * @param string $line Line to append (should already end in a newline).
	 */
	private static function append( $line ) {
		self::ensure_log_dir();

		$file = self::log_file_path();

		if ( file_exists( $file ) && filesize( $file ) > self::MAX_LOG_BYTES ) {
			$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $file, substr( $contents, (int) ( self::MAX_LOG_BYTES / 2 ) * -1 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		file_put_contents( $file, $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Logs a fired tracking event and the page it fired on.
	 *
	 * @param string $event_name Standard event name, e.g. 'Purchase'.
	 * @param string $page_url   The page the event is associated with.
	 * @param string $event_id   Shared pixel/CAPI dedupe id.
	 * @param array  $payload    The custom_data payload sent for this event.
	 */
	public static function log_event( $event_name, $page_url, $event_id, array $payload = [] ) {
		if ( ! Metatrac_Settings::is_debug() ) {
			return;
		}

		self::append(
			sprintf(
				"[%s] event=%s page=%s event_id=%s payload=%s\n",
				gmdate( 'Y-m-d H:i:s' ),
				$event_name,
				$page_url,
				$event_id,
				wp_json_encode( $payload )
			)
		);
	}

	/**
	 * Logs a product Metatrac_WooCommerce_Tracker::resolve_product_price()
	 * couldn't find any usable price for, after walking up to a variation's
	 * parent, down to a variable product's variations, and down into a
	 * bundle's required items. Whatever event payload this fed into will
	 * report a $0 value for it; this line is what tells a debug-mode site
	 * owner why, and which product to go look at.
	 *
	 * @param WC_Product $product The product that resolved to 0.0.
	 */
	public static function log_price_resolution_failed( $product ) {
		if ( ! Metatrac_Settings::is_debug() ) {
			return;
		}

		self::append(
			sprintf(
				"[%s] price_resolution_failed product_id=%d type=%s own_price=%s\n",
				gmdate( 'Y-m-d H:i:s' ),
				$product->get_id(),
				$product->get_type(),
				wp_json_encode( $product->get_price() )
			)
		);
	}

	/**
	 * Logs a bundle add-to-cart where
	 * Metatrac_WooCommerce_Tracker::resolve_bundle_cart_total() couldn't
	 * find any cart items linked back to it, so that event's value fell
	 * back to the bundle's required-items floor instead of the shopper's
	 * actual selected total. Logs cart_item_data's own top-level keys
	 * (never its values, which may include customer-entered field data)
	 * so a real key name can be confirmed against WooCommerce Product
	 * Bundles' actual behavior if the guessed ones ('bundled_items',
	 * 'bundled_by') turn out wrong on a given site/version.
	 *
	 * @param WC_Product $product        The bundle product.
	 * @param array      $cart_item_data This add's cart item data.
	 */
	public static function log_bundle_cart_link_not_found( $product, array $cart_item_data ) {
		if ( ! Metatrac_Settings::is_debug() ) {
			return;
		}

		self::append(
			sprintf(
				"[%s] bundle_cart_link_not_found product_id=%d cart_item_data_keys=%s\n",
				gmdate( 'Y-m-d H:i:s' ),
				$product->get_id(),
				wp_json_encode( array_keys( $cart_item_data ) )
			)
		);
	}

	/**
	 * Logs an ajax request rejected for failing its nonce check, most often
	 * a nonce that was baked into cached HTML (see the nonce_life filters in
	 * Metatrac_Contact_Tracker, Metatrac_Find_Location_Tracker, and
	 * Metatrac_Pixel) and served past its lifetime by a page cache that
	 * outlived it. Without this, that failure is otherwise completely
	 * silent: check_ajax_referer() just dies with no application-level
	 * trace, so a debug-mode site owner would have no way to tell a missing
	 * CAPI event apart from one that was never queued at all.
	 *
	 * @param string $event_name Standard event name the request was for.
	 */
	public static function log_nonce_failure( $event_name ) {
		if ( ! Metatrac_Settings::is_debug() ) {
			return;
		}

		self::append(
			sprintf(
				"[%s] nonce_check_failed event=%s\n",
				gmdate( 'Y-m-d H:i:s' ),
				$event_name
			)
		);
	}

	/**
	 * Logs the outcome of a Conversions API call.
	 *
	 * @param string $event_name Standard event name.
	 * @param string $event_id   Shared pixel/CAPI dedupe id.
	 * @param mixed  $response   Return value of wp_remote_post() (array or WP_Error).
	 */
	public static function log_capi_response( $event_name, $event_id, $response ) {
		if ( ! Metatrac_Settings::is_debug() ) {
			return;
		}

		if ( is_wp_error( $response ) ) {
			$summary = 'error=' . $response->get_error_message();
		} else {
			$summary = 'http_status=' . wp_remote_retrieve_response_code( $response ) . ' body=' . wp_remote_retrieve_body( $response );
		}

		self::append(
			sprintf(
				"[%s] capi_response event=%s event_id=%s %s\n",
				gmdate( 'Y-m-d H:i:s' ),
				$event_name,
				$event_id,
				$summary
			)
		);
	}
}
