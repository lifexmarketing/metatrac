<?php
/**
 * Class Metatrac_Gravity_Forms_Tracker
 *
 * Tracks a Lead event for every successful Gravity Forms submission, across
 * all forms on the site. gform_after_submission already excludes entries
 * flagged as spam, so no extra filtering is needed here.
 *
 * No ajax-fragment workaround (unlike WooCommerce's AddToCart) is needed:
 * Gravity Forms' own AJAX submission mechanism re-renders the full page
 * template, including wp_head/wp_footer, inside a hidden iframe, so the
 * event queued below flushes normally through Metatrac_Pixel's existing
 * footer script within that iframe.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Metatrac_Gravity_Forms_Tracker {

	/**
	 * Registers the hook, only if Gravity Forms is active and Lead is enabled.
	 */
	public function init() {
		if ( ! class_exists( 'GFForms' ) || ! Metatrac_Settings::is_event_enabled( 'Lead' ) ) {
			return;
		}

		add_action( 'gform_after_submission', [ $this, 'track_lead' ], 10, 2 );
	}

	/**
	 * Fires the Lead event for a submitted entry.
	 *
	 * @param array $entry Gravity Forms entry (unused; included for the hook's expected signature).
	 * @param array $form  Gravity Forms form.
	 */
	public function track_lead( $entry, $form ) {
		$custom_data = [
			'content_name' => isset( $form['title'] ) ? $form['title'] : '',
		];

		Metatrac_Pixel::fire_event( 'Lead', $custom_data, Metatrac_Pixel::current_url() );
	}
}
