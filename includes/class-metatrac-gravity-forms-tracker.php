<?php
/**
 * Class Metatrac_Gravity_Forms_Tracker
 *
 * Tracks a Lead event for every successful Gravity Forms submission, across
 * all forms on the site (or Subscribe instead, for whichever forms are
 * set to "Track as: Subscribe" under Lead's form list, e.g. a
 * newsletter signup form, where Subscribe is the more accurate standard
 * event than Lead). gform_after_submission already excludes entries flagged
 * as spam, so no extra filtering is needed here.
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
	 * Fires the Lead (or Subscribe) event for a submitted entry.
	 *
	 * @param array $entry Gravity Forms entry.
	 * @param array $form  Gravity Forms form.
	 */
	public function track_lead( $entry, $form ) {
		$form_id = isset( $form['id'] ) ? (int) $form['id'] : 0;

		if ( ! Metatrac_Settings::is_lead_form_tracked( $form_id ) ) {
			return;
		}

		$event_name = Metatrac_Settings::lead_event_for_form( $form_id );

		$custom_data = [
			'content_name' => isset( $form['title'] ) ? $form['title'] : '',
		];

		// Better CAPI match quality when the form happens to ask for them;
		// Metatrac_CAPI hashes both before they ever leave the server.
		$contact_fields = $this->extract_contact_fields( $form, $entry );

		$event_id = Metatrac_Pixel::fire_event( $event_name, $custom_data, Metatrac_Pixel::current_url(), $contact_fields );

		// Stashed in case maybe_defer_for_redirect() decides this submission's
		// confirmation is about to redirect the browser away.
		$this->pending_lead_event = [
			'event'  => $event_name,
			'params' => $custom_data,
			'id'     => $event_id,
		];
	}

	/**
	 * Finds the entry's email and phone values. Each slot takes the best
	 * candidate by rank, then form order:
	 *   1. Native GF field types (email / phone).
	 *   2. Single-line text fields whose label, admin label, placeholder,
	 *      parameter name, autocomplete attribute or CSS class names
	 *      email/phone, and whose value actually validates as one.
	 *   3. Email only: any single-line text value that passes is_email().
	 *      Phone is never value-sniffed alone, since zip codes, addresses and
	 *      flock sizes can look like digit strings.
	 * Hidden fields are skipped, since builders often use them for routing
	 * addresses (a location's inbox) that aren't the lead's own.
	 *
	 * Sites can override the result with the metatrac_lead_contact_fields
	 * filter, e.g. to pin exact field IDs for a form.
	 *
	 * @param array $form  Gravity Forms form.
	 * @param array $entry Gravity Forms entry.
	 * @return array ['email' => ..., 'phone' => ...], values '' when not found.
	 */
	private function extract_contact_fields( $form, $entry ) {
		$found = [
			'email' => '',
			'phone' => '',
		];
		$rank  = [
			'email' => PHP_INT_MAX,
			'phone' => PHP_INT_MAX,
		];

		if ( empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
			return apply_filters( 'metatrac_lead_contact_fields', $found, $form, $entry );
		}

		foreach ( $form['fields'] as $field ) {
			$value = $this->field_value( $field, $entry );
			if ( '' === $value ) {
				continue;
			}

			$type = isset( $field->type ) ? $field->type : '';
			$hint = 'text' === $type ? $this->field_hint( $field ) : '';

			if ( is_email( $value ) ) {
				if ( 'email' === $type ) {
					$candidate = 1;
				} elseif ( $hint && preg_match( '/\be-?mail\b/', $hint ) ) {
					$candidate = 2;
				} elseif ( 'text' === $type ) {
					$candidate = 3;
				} else {
					$candidate = PHP_INT_MAX;
				}

				if ( $candidate < $rank['email'] ) {
					$found['email'] = $value;
					$rank['email']  = $candidate;
				}
				continue;
			}

			if ( 'phone' === $type ) {
				$candidate = 1;
			} elseif ( $hint && preg_match( '/\b(phone|cell|mobile|tel|telephone)\b/', $hint ) ) {
				$candidate = 2;
			} else {
				continue;
			}

			$digits = preg_replace( '/\D+/', '', $value );
			if ( $candidate < $rank['phone'] && strlen( $digits ) >= 10 && strlen( $digits ) <= 15 ) {
				$found['phone'] = $value;
				$rank['phone']  = $candidate;
			}
		}

		return apply_filters( 'metatrac_lead_contact_fields', $found, $form, $entry );
	}

	/**
	 * Trimmed entry value stored under the field's own ID, '' when empty.
	 * Multi-input fields (name, address) store under sub-IDs like "3.3", so
	 * they come back '' here; an email field with confirmation enabled still
	 * stores its value under the field ID and is read normally.
	 *
	 * @param object $field GF_Field.
	 * @param array  $entry Gravity Forms entry.
	 * @return string
	 */
	private function field_value( $field, $entry ) {
		if ( ! isset( $field->id ) ) {
			return '';
		}
		$key = (string) $field->id;
		return isset( $entry[ $key ] ) && is_string( $entry[ $key ] ) ? trim( $entry[ $key ] ) : '';
	}

	/**
	 * Lowercased bag of every place a builder might name the field. "Phone #"
	 * becomes "phone", "E-mail Address" stays matchable as "e-mail".
	 *
	 * @param object $field GF_Field.
	 * @return string
	 */
	private function field_hint( $field ) {
		$parts = [];
		foreach ( [ 'label', 'adminLabel', 'placeholder', 'inputName', 'autocompleteAttribute', 'cssClass' ] as $prop ) {
			if ( ! empty( $field->$prop ) && is_string( $field->$prop ) ) {
				$parts[] = $field->$prop;
			}
		}
		return preg_replace( '/[^a-z\-]+/', ' ', strtolower( implode( ' ', $parts ) ) );
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
	 * Fires the Pixel side of a Lead/Subscribe event that was stashed in a
	 * cookie because its confirmation was about to redirect the browser
	 * away. Runs on every front-end page load, so it picks the event up on
	 * whichever page the visitor lands on next (normally the confirmation
	 * page). The matching CAPI event, sharing the same event_id, was already
	 * sent in track_lead().
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

		$event_name = ! empty( $pending['event'] ) && 'Subscribe' === $pending['event'] ? 'Subscribe' : 'Lead';

		$params = [];
		if ( ! empty( $pending['params']['content_name'] ) ) {
			$params['content_name'] = sanitize_text_field( $pending['params']['content_name'] );
		}

		Metatrac_Pixel::queue_event( $event_name, $params, sanitize_text_field( $pending['id'] ) );
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
