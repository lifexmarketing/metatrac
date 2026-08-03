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
		return [ 'ViewContent', 'AddToCart', 'InitiateCheckout', 'Purchase', 'Contact', 'Lead' ];
	}

	/**
	 * The subset of trackable_events() that require WooCommerce to be active.
	 * PageView, Contact, and Lead all work without it.
	 *
	 * @return array
	 */
	public static function ecommerce_events() {
		return [ 'ViewContent', 'AddToCart', 'InitiateCheckout', 'Purchase' ];
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
			'github_token'    => '',
			'enabled_events'  => self::trackable_events(),
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
