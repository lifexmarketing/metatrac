<?php
/**
 * Class Metatrac_Find_Location_Tracker
 *
 * Tracks the FindLocation event: a click/tap on a link to Google Maps (a
 * "Get Directions" link, an embedded map's "View larger map" link, etc.)
 * anywhere on the site, once per browser session. Same shape as
 * Metatrac_Contact_Tracker: there's no server-side hook for "a link was
 * clicked", so the click itself is detected in assets/js/metatrac-frontend.js,
 * which fires the Pixel side directly and calls handle_ajax() below (via
 * admin-ajax.php) for the CAPI side, sharing one event_id between the two
 * for dedupe.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Metatrac_Find_Location_Tracker {

	const AJAX_ACTION  = 'metatrac_find_location';
	const NONCE_ACTION = 'metatrac_find_location_nonce';

	/**
	 * Registers hooks, only if the FindLocation event is enabled and the
	 * plugin has enough configuration for anything to fire at all.
	 */
	public function init() {
		if ( ! Metatrac_Settings::is_event_enabled( 'FindLocation' ) || ! Metatrac_Settings::has_pixel_id() ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', [ $this, 'localize_script' ], 20 );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'handle_ajax' ] );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, [ $this, 'handle_ajax' ] );
	}

	/**
	 * Passes the ajax URL and a nonce to the already-registered frontend
	 * script, so its click listener can reach handle_ajax() below. Runs at
	 * priority 20 so it fires after Metatrac_Pixel::enqueue_frontend_script()
	 * (priority 10) has registered the 'metatrac-frontend' handle.
	 */
	public function localize_script() {
		if ( ! wp_script_is( 'metatrac-frontend', 'registered' ) && ! wp_script_is( 'metatrac-frontend', 'enqueued' ) ) {
			return;
		}

		wp_localize_script(
			'metatrac-frontend',
			'metatracFindLocation',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			]
		);
	}

	/**
	 * Receives the client-generated event_id for a FindLocation click and
	 * sends the matching CAPI event. The once-per-session gate lives entirely
	 * in JS (sessionStorage), since only the browser knows whether this click
	 * is the first one this session.
	 */
	public function handle_ajax() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$event_id = isset( $_POST['event_id'] ) ? sanitize_text_field( wp_unslash( $_POST['event_id'] ) ) : '';
		$page_url = isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : Metatrac_Pixel::current_url();

		if ( '' === $event_id ) {
			wp_send_json_error();
		}

		( new Metatrac_CAPI() )->send_event( 'FindLocation', $event_id, [], $page_url );
		Metatrac_Logger::log_event( 'FindLocation', $page_url, $event_id, [] );

		wp_send_json_success();
	}
}
