<?php
/**
 * Class Metatrac_CAPI
 *
 * Sends events to Meta's Conversions API (server-side), as the counterpart to
 * the browser-side Pixel calls in Metatrac_Pixel. Every event shares an
 * event_id with its Pixel counterpart so Meta can deduplicate the two.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Metatrac_CAPI {

	/**
	 * Sends a single event to the Conversions API.
	 *
	 * @param string $event_name       Standard Meta event name, e.g. 'Purchase'.
	 * @param string $event_id         Shared pixel/CAPI dedupe id.
	 * @param array  $custom_data      The event's custom_data payload (value, currency, contents, ...).
	 * @param string $event_source_url The page the event is associated with.
	 * @param array  $extra_user_data  Optional overrides: [ 'email' => ..., 'phone' => ... ].
	 */
	public function send_event( $event_name, $event_id, array $custom_data, $event_source_url, array $extra_user_data = [] ) {
		if ( ! Metatrac_Settings::can_use_capi() ) {
			return;
		}

		$payload = [
			'data' => [
				[
					'event_name'       => $event_name,
					'event_time'       => time(),
					'event_id'         => $event_id,
					'event_source_url' => $event_source_url,
					'action_source'    => 'website',
					'user_data'        => $this->build_user_data( $extra_user_data ),
					'custom_data'      => $custom_data,
				],
			],
		];

		$test_event_code = Metatrac_Settings::get( 'test_event_code' );
		if ( ! empty( $test_event_code ) ) {
			$payload['test_event_code'] = $test_event_code;
		}

		$debug = Metatrac_Settings::is_debug();

		$url = sprintf(
			'https://graph.facebook.com/%s/%s/events?access_token=%s',
			METATRAC_GRAPH_API_VERSION,
			rawurlencode( Metatrac_Settings::get( 'pixel_id' ) ),
			rawurlencode( Metatrac_Settings::get( 'access_token' ) )
		);

		$response = wp_remote_post(
			$url,
			[
				'body'     => wp_json_encode( $payload ),
				'headers'  => [ 'Content-Type' => 'application/json' ],
				// Only wait for (and log) the response while debugging; otherwise
				// fire-and-forget so CAPI calls never add latency to page loads.
				'timeout'  => $debug ? 8 : 3,
				'blocking' => $debug,
			]
		);

		if ( $debug ) {
			Metatrac_Logger::log_capi_response( $event_name, $event_id, $response );
		}
	}

	/**
	 * Builds the user_data object: cookies/IP/UA always, plus hashed
	 * email/phone/external_id when available, for better Meta match quality.
	 *
	 * @param array $extra [ 'email' => ..., 'phone' => ... ].
	 * @return array
	 */
	private function build_user_data( array $extra ) {
		$data = [];

		if ( ! empty( $_COOKIE['_fbp'] ) ) {
			$data['fbp'] = sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) );
		}

		$fbc = $this->resolve_fbc();
		if ( $fbc ) {
			$data['fbc'] = $fbc;
		}

		$ip = $this->get_client_ip();
		if ( $ip ) {
			$data['client_ip_address'] = $ip;
		}

		if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$data['client_user_agent'] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		}

		$email = isset( $extra['email'] ) ? $extra['email'] : ( is_user_logged_in() ? wp_get_current_user()->user_email : '' );
		$phone = isset( $extra['phone'] ) ? $extra['phone'] : '';

		if ( $email ) {
			$data['em'] = $this->hash( strtolower( trim( $email ) ) );
		}

		if ( $phone ) {
			$digits = preg_replace( '/\D+/', '', $phone );
			if ( $digits ) {
				$data['ph'] = $this->hash( $digits );
			}
		}

		// A stable identifier for logged-in customers, unlike fbp/fbc which
		// reset with cookies/devices; applies to any event, not just Purchase.
		if ( is_user_logged_in() ) {
			$data['external_id'] = $this->hash( (string) get_current_user_id() );
		}

		return $data;
	}

	/**
	 * Reads the _fbc cookie, falling back to reconstructing it from a fresh
	 * fbclid query parameter if the cookie hasn't been set yet.
	 *
	 * @return string
	 */
	private function resolve_fbc() {
		if ( ! empty( $_COOKIE['_fbc'] ) ) {
			return sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) );
		}

		if ( ! empty( $_GET['fbclid'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return 'fb.1.' . time() . '.' . sanitize_text_field( wp_unslash( $_GET['fbclid'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		return '';
	}

	/**
	 * Best-effort real client IP, preferring known proxy headers.
	 *
	 * @return string
	 */
	private function get_client_ip() {
		foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$candidate = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) )[0] );
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return $candidate;
			}
		}
		return '';
	}

	/**
	 * Hashes a value per Meta's user_data spec (lowercase/trimmed by caller).
	 *
	 * @param string $value Value to hash.
	 * @return string
	 */
	private function hash( $value ) {
		return hash( 'sha256', $value );
	}
}
