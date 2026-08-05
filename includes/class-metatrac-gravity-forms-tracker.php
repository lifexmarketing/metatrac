<?php
/**
 * Class Metatrac_Gravity_Forms_Tracker
 *
 * Tracks a Lead event for every successful Gravity Forms submission, across
 * all forms on the site. gform_after_submission already excludes entries
 * flagged as spam, so no extra filtering is needed here.
 *
 * For a form whose confirmation just displays a message on the same page,
 * that's the end of it: the event queued in track_lead() flushes normally
 * through Metatrac_Pixel's existing footer script (inside the hidden AJAX
 * iframe, if the form uses AJAX, same as a normal page).
 *
 * A form confirmation of type "redirect" is different: Gravity Forms
 * navigates the browser to the confirmation page shortly after the footer
 * renders, racing the Pixel call's own network request. The Conversions API
 * side isn't affected (it's a synchronous PHP call that already completed),
 * but the browser Pixel side frequently loses that race, which is exactly
 * what shows up as low browser-side event_id coverage in Meta's deduplication
 * diagnostics. maybe_defer_for_redirect() below detects that case via the
 * gform_confirmation filter (which knows the resolved confirmation, unlike
 * gform_after_submission) and stashes the event in a short-lived cookie
 * instead of racing the redirect; replay_pending_lead() fires the Pixel side
 * fresh on the confirmation page's own load, a normal page like any other.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Metatrac_Gravity_Forms_Tracker {

	const PENDING_LEAD_COOKIE = 'metatrac_pending_lead';

	/**
	 * The Lead event built during this request, if any, picked up by
	 * maybe_defer_for_redirect() if the submission's confirmation redirects.
	 *
	 * @var array|null
	 */
	private $pending_lead_event = null;

	/**
	 * Registers the hooks, only if Gravity Forms is active and Lead is enabled.
	 */
	public function init() {
		if ( ! class_exists( 'GFForms' ) || ! Metatrac_Settings::is_event_enabled( 'Lead' ) ) {
			return;
		}

		add_action( 'gform_after_submission', [ $this, 'track_lead' ], 10, 2 );
		add_filter( 'gform_confirmation', [ $this, 'maybe_defer_for_redirect' ], 20, 4 );
		add_action( 'wp', [ $this, 'replay_pending_lead' ] );
	}

	/**
	 * Fires the Lead event for a submitted entry.
	 *
	 * @param array $entry Gravity Forms entry.
	 * @param array $form  Gravity Forms form.
	 */
	public function track_lead( $entry, $form ) {
		$custom_data = [
			'content_name' => isset( $form['title'] ) ? $form['title'] : '',
		];

		// Better CAPI match quality when the form happens to ask for them;
		// Metatrac_CAPI hashes both before they ever leave the server.
		$contact_fields = $this->extract_contact_fields( $form, $entry );

		$event_id = Metatrac_Pixel::fire_event( 'Lead', $custom_data, Metatrac_Pixel::current_url(), $contact_fields );

		// Stashed in case maybe_defer_for_redirect() decides this submission's
		// confirmation is about to redirect the browser away.
		$this->pending_lead_event = [
			'params' => $custom_data,
			'id'     => $event_id,
		];
	}

	/**
	 * Finds the entry's email and phone values, if the form has fields of
	 * those types. Only ever picks the first of each, since Meta's user_data
	 * only has one em/ph slot anyway.
	 *
	 * @param array $form  Gravity Forms form.
	 * @param array $entry Gravity Forms entry.
	 * @return array ['email' => ..., 'phone' => ...], values '' when not found.
	 */
	private function extract_contact_fields( $form, $entry ) {
		$email = '';
		$phone = '';

		if ( empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
			return [
				'email' => $email,
				'phone' => $phone,
			];
		}

		foreach ( $form['fields'] as $field ) {
			$field_id   = isset( $field->id ) ? $field->id : null;
			$field_type = isset( $field->type ) ? $field->type : '';

			if ( null === $field_id || empty( $entry[ $field_id ] ) ) {
				continue;
			}

			if ( '' === $email && 'email' === $field_type ) {
				$email = $entry[ $field_id ];
			}

			if ( '' === $phone && 'phone' === $field_type ) {
				$phone = $entry[ $field_id ];
			}
		}

		return [
			'email' => $email,
			'phone' => $phone,
		];
	}

	/**
	 * Runs after gform_after_submission, once Gravity Forms has resolved
	 * which confirmation applies to this submission. Left untouched and
	 * returned as-is; this only inspects it to decide whether the Pixel side
	 * of the Lead event needs to survive a redirect.
	 *
	 * @param array|string $confirmation Confirmation markup, or a redirect array.
	 * @param array        $form         Gravity Forms form.
	 * @param array        $entry        Gravity Forms entry.
	 * @param bool         $ajax         Whether this is an AJAX submission.
	 * @return array|string
	 */
	public function maybe_defer_for_redirect( $confirmation, $form, $entry, $ajax ) {
		if ( $this->pending_lead_event && is_array( $confirmation ) && isset( $confirmation['type'] ) && 'redirect' === $confirmation['type'] ) {
			$this->set_pending_lead_cookie( $this->pending_lead_event );
		}

		return $confirmation;
	}

	/**
	 * Fires the Pixel side of a Lead event that was stashed in a cookie
	 * because its confirmation was about to redirect the browser away. Runs
	 * on every front-end page load, so it picks the event up on whichever
	 * page the visitor lands on next (normally the confirmation page). The
	 * matching CAPI event, sharing the same event_id, was already sent in
	 * track_lead().
	 */
	public function replay_pending_lead() {
		if ( empty( $_COOKIE[ self::PENDING_LEAD_COOKIE ] ) ) {
			return;
		}

		$pending = json_decode( wp_unslash( $_COOKIE[ self::PENDING_LEAD_COOKIE ] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$this->clear_pending_lead_cookie();

		if ( ! is_array( $pending ) || empty( $pending['id'] ) ) {
			return;
		}

		$params = [];
		if ( ! empty( $pending['params']['content_name'] ) ) {
			$params['content_name'] = sanitize_text_field( $pending['params']['content_name'] );
		}

		Metatrac_Pixel::queue_event( 'Lead', $params, sanitize_text_field( $pending['id'] ) );
	}

	/**
	 * Stores the pending Lead event in a short-lived cookie, read back (and
	 * cleared) by replay_pending_lead() on the next page load.
	 *
	 * @param array $event ['params' => custom_data, 'id' => shared event_id].
	 */
	private function set_pending_lead_cookie( array $event ) {
		setcookie(
			self::PENDING_LEAD_COOKIE,
			wp_json_encode( $event ),
			[
				'expires'  => time() + MINUTE_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);
	}

	/**
	 * Clears the pending-Lead cookie so it never replays more than once.
	 */
	private function clear_pending_lead_cookie() {
		setcookie(
			self::PENDING_LEAD_COOKIE,
			'',
			[
				'expires'  => time() - HOUR_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);
	}
}
