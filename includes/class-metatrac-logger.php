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
