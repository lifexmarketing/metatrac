<?php
/**
 * Class Metatrac_Page_Event_Tracker
 *
 * Fires an arbitrary Meta standard event once, on page load, for whichever
 * published pages the admin has assigned one to under Settings > MetaTrac >
 * Page Events. Unlike the rest of MetaTrac's events, this isn't tied to a
 * single fixed event name or a single on/off checkbox: each page picks its
 * own standard event (see Metatrac_Settings::standard_events()) from
 * https://www.facebook.com/business/help/402791146561655?id=1205376682832142,
 * independent of the Events to Track checkboxes.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Metatrac_Page_Event_Tracker {

	/**
	 * Registers the hook, only if at least one page has an event assigned
	 * and the plugin has enough configuration for anything to fire at all.
	 */
	public function init() {
		$page_events = Metatrac_Settings::get( 'page_events' );

		if ( empty( $page_events ) || ! is_array( $page_events ) || ! Metatrac_Settings::has_pixel_id() ) {
			return;
		}

		add_action( 'wp', [ $this, 'track_page_view_event' ] );
	}

	/**
	 * Fires the page's assigned event, if the current request is a singular
	 * view of a page that has one.
	 */
	public function track_page_view_event() {
		if ( ! is_page() ) {
			return;
		}

		$event = Metatrac_Settings::page_view_event( get_queried_object_id() );
		if ( '' === $event ) {
			return;
		}

		Metatrac_Pixel::fire_event( $event, [], Metatrac_Pixel::current_url() );
	}
}
