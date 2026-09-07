<?php
/**
 * Class Metatrac_Settings
 *
 * Central read access to MetaTrac's settings. All settings live in a single
 * option so the plugin only ever autoloads one row, regardless of how many
 * fields the settings screen grows to.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Metatrac_Settings {

	const OPTION_KEY = 'metatrac_settings';

	/**
	 * The events this version of MetaTrac knows how to track.
	 *
	 * @return array
	 */
	public static function trackable_events() {
		return [ 'ViewContent', 'AddToCart', 'InitiateCheckout', 'Purchase', 'Contact', 'FindLocation', 'Lead' ];
	}

	/**
	 * The subset of trackable_events() that require WooCommerce to be active.
	 *
	 * @return array
	 */
	public static function ecommerce_events() {
		return [ 'ViewContent', 'AddToCart', 'InitiateCheckout', 'Purchase' ];
	}

	/**
	 * The subset of trackable_events() that require Gravity Forms to be active.
	 *
	 * @return array
	 */
	public static function gravity_forms_events() {
		return [ 'Lead' ];
	}

	/**
	 * Default settings values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return [
			'pixel_id'        => '',
			'access_token'    => '',
			'test_event_code' => '',
			'enabled_events'  => self::trackable_events(),
			'lead_form_mode'  => 'all',
			'lead_form_ids'   => [],
			'contact_mailto'  => false,
			'debug_mode'      => false,
		];
	}

	/**
	 * All settings, merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * A single setting value.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Whether a given standard event is enabled for this site.
	 *
	 * @param string $event Event name, e.g. 'AddToCart'.
	 * @return bool
	 */
	public static function is_event_enabled( $event ) {
		$events = self::get( 'enabled_events' );
		return is_array( $events ) && in_array( $event, $events, true );
	}

	/**
	 * Whether a specific Gravity Forms form should fire the Lead event, given
	 * the site's Lead form selection. When 'lead_form_mode' is 'all' (the
	 * default, matching pre-1.1.0 behavior), every form qualifies.
	 *
	 * @param int $form_id Gravity Forms form ID.
	 * @return bool
	 */
	public static function is_lead_form_tracked( $form_id ) {
		if ( 'selected' !== self::get( 'lead_form_mode' ) ) {
			return true;
		}

		$form_ids = self::get( 'lead_form_ids' );
		return is_array( $form_ids ) && in_array( (int) $form_id, $form_ids, true );
	}

	/**
	 * Whether the Contact event should also fire on mailto: link clicks, in
	 * addition to its default tel:/sms: links.
	 *
	 * @return bool
	 */
	public static function is_contact_mailto_enabled() {
		return (bool) self::get( 'contact_mailto' );
	}

	/**
	 * Whether debug mode is enabled.
	 *
	 * @return bool
	 */
	public static function is_debug() {
		return (bool) self::get( 'debug_mode' );
	}

	/**
	 * Whether the plugin has enough configuration to fire the pixel.
	 *
	 * @return bool
	 */
	public static function has_pixel_id() {
		return '' !== (string) self::get( 'pixel_id' );
	}

	/**
	 * Whether the plugin has enough configuration to call the Conversions API.
	 *
	 * @return bool
	 */
	public static function can_use_capi() {
		return self::has_pixel_id() && '' !== (string) self::get( 'access_token' );
	}
}
