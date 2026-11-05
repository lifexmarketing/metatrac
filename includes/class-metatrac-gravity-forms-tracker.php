<?php
/**
 * Class Metatrac_Gravity_Forms_Tracker
 *
 * Tracks a Lead event for every successful Gravity Forms submission, across
 * all forms on the site (or Subscribe / Contact instead, for whichever forms
 * are set to that in their "Track as" dropdown under Lead's form list, e.g.
 * Subscribe for a newsletter signup form).
 *
 * gform_after_submission fires for spam entries too (Gravity Forms only
 * skips notifications and its own feed add-ons for them), so track_lead()
 * checks the entry's status itself; see is_spam_entry().
 *
 * Gravity Forms' order of operations within one submission matters here:
 *   1. The entry is saved and, if a spam filter says so, marked spam.
 *   2. gform_confirmation runs: the confirmation is resolved.
 *   3. gform_after_submission runs: track_lead() fires the event.
 *   4. A non-AJAX redirect sends its Location header and exits; anything
 *      else is rendered (gform_get_form_confirmation_filter runs here).
 * Since the event doesn't exist yet in step 2, note_confirmation() only
 * records what kind of confirmation is coming, and the Pixel side is
 * routed in step 3 or 4:
 *
 * - Non-AJAX text confirmation: the page renders normally, so the event
 *   queued in track_lead() flushes through Metatrac_Pixel's footer script.
 *
 * - Redirect (AJAX or not): no footer ever renders for this response, so
 *   track_lead() stashes the event in a short-lived cookie (headers aren't
 *   sent until step 4), and replay_pending_lead() fires the Pixel side on
 *   the page the visitor lands on.
 *
 * - AJAX text confirmation: Gravity Forms posts into a hidden iframe whose
 *   response holds only the confirmation markup, never the page footer, and
 *   its JS then copies that markup into the page with jQuery, which runs
 *   inline scripts. add_ajax_pixel_script() puts a metatracFireEvent() call
 *   into that markup, the same way the WooCommerce AddToCart fragment does.
 *
 * Every route reuses the CAPI copy's event_id, so Meta deduplicates the pair.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Metatrac_Gravity_Forms_Tracker {

	const PENDING_LEAD_COOKIE = 'metatrac_pending_lead';

	/**
	 * The Lead event built during this request, if any, picked up by
	 * add_ajax_pixel_script() if the confirmation comes back over AJAX.
	 *
	 * @var array|null
	 */
	private $pending_lead_event = null;

	/**
	 * Whether each form's resolved confirmation (form ID => bool) redirects,
	 * as seen by note_confirmation() before track_lead() runs.
	 *
	 * @var bool[]
	 */
	private $confirmation_redirects = [];

	/**
	 * Registers the hooks, only if Gravity Forms is active and Lead is enabled.
	 */
	public function init() {
		if ( ! class_exists( 'GFForms' ) || ! Metatrac_Settings::is_event_enabled( 'Lead' ) ) {
			return;
		}

		// Late, so a spam add-on that marks the entry from its own
		// gform_after_submission handler has already done so.
		add_action( 'gform_after_submission', [ $this, 'track_lead' ], 99, 2 );
		// Late too, after any other plugin has swapped in a redirect.
		add_filter( 'gform_confirmation', [ $this, 'note_confirmation' ], 99, 4 );
		add_filter( 'gform_get_form_confirmation_filter', [ $this, 'add_ajax_pixel_script' ], 10, 2 );
		add_action( 'wp', [ $this, 'replay_pending_lead' ] );
	}

	/**
	 * Fires the Lead (or Subscribe / Contact) event for a submitted entry.
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

		if ( $this->is_spam_entry( $entry ) ) {
			Metatrac_Logger::log_spam_skipped( $event_name, $form_id, (int) rgar( $entry, 'id' ) );
			return;
		}

		$custom_data = [
			'content_name' => isset( $form['title'] ) ? $form['title'] : '',
		];

		// Better CAPI match quality when the form happens to ask for them;
		// Metatrac_CAPI hashes both before they ever leave the server.
		$contact_fields = $this->extract_contact_fields( $form, $entry );

		$event_id = Metatrac_Pixel::fire_event( $event_name, $custom_data, Metatrac_Pixel::current_url(), $contact_fields );

		$this->pending_lead_event = [
			'event'  => $event_name,
			'params' => $custom_data,
			'id'     => $event_id,
		];

		if ( ! empty( $this->confirmation_redirects[ $form_id ] ) ) {
			$this->set_pending_lead_cookie( $this->pending_lead_event );
		}
	}

	/**
	 * Whether Gravity Forms, or a spam add-on hooked into it (CleanTalk,
	 * Akismet, ...), has marked this entry as spam. Gravity Forms sets
	 * status => 'spam' on the passed entry when its gform_entry_is_spam
	 * filter says so; an add-on that instead updates the stored status from
	 * its own after-submission handler only shows up in the database, so a
	 * non-spam status is re-read from there.
	 *
	 * @param array $entry Gravity Forms entry.
	 * @return bool
	 */
	private function is_spam_entry( $entry ) {
		$status = rgar( $entry, 'status' );

		if ( 'spam' !== $status && ! empty( $entry['id'] ) ) {
			$fresh = GFAPI::get_entry( $entry['id'] );
			if ( ! is_wp_error( $fresh ) ) {
				$status = rgar( $fresh, 'status' );
			}
		}

		return 'spam' === $status;
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
	 * Records whether this submission's confirmation redirects, for
	 * track_lead(), which runs after this. Gravity Forms passes a redirect
	 * as ['redirect' => url] and a text confirmation as a string. Returned
	 * untouched.
	 *
	 * @param array|string $confirmation Confirmation markup, or a redirect array.
	 * @param array        $form         Gravity Forms form.
	 * @param array        $entry        Gravity Forms entry.
	 * @param bool         $ajax         Whether this is an AJAX submission.
	 * @return array|string
	 */
	public function note_confirmation( $confirmation, $form, $entry, $ajax ) {
		$form_id = isset( $form['id'] ) ? (int) $form['id'] : 0;

		$this->confirmation_redirects[ $form_id ] = is_array( $confirmation ) && ! empty( $confirmation['redirect'] );

		return $confirmation;
	}

	/**
	 * Adds the inline Pixel call to an AJAX text confirmation, as it's
	 * rendered for the hidden iframe (after track_lead() has run). The
	 * script goes inside the GF_AJAX_POSTBACK body, since that body is the
	 * only part Gravity Forms' JS copies into the page. It also runs once in
	 * the iframe itself, where metatracFireEvent isn't defined, hence the
	 * guard. A non-AJAX confirmation has no such body and is left alone; its
	 * page's footer flushes the event instead. An AJAX redirect renders
	 * through here too, as a JS redirect, but its event already went into
	 * the replay cookie, so it's skipped to avoid firing twice.
	 *
	 * @param string $confirmation Rendered confirmation markup.
	 * @param array  $form         Gravity Forms form.
	 * @return string
	 */
	public function add_ajax_pixel_script( $confirmation, $form ) {
		$form_id = isset( $form['id'] ) ? (int) $form['id'] : 0;

		if ( ! $this->pending_lead_event || ! empty( $this->confirmation_redirects[ $form_id ] ) || ! is_string( $confirmation ) || false === strpos( $confirmation, 'GF_AJAX_POSTBACK' ) || false === strpos( $confirmation, '</body>' ) ) {
			return $confirmation;
		}

		$script = sprintf(
			'<script>if(window.metatracFireEvent){metatracFireEvent(%s);}</script>',
			wp_json_encode(
				[
					'name'   => $this->pending_lead_event['event'],
					'params' => $this->pending_lead_event['params'],
					'id'     => $this->pending_lead_event['id'],
				],
				JSON_HEX_TAG | JSON_HEX_AMP
			)
		);

		return str_replace( '</body>', $script . '</body>', $confirmation );
	}

	/**
	 * Fires the Pixel side of a Lead/Subscribe/Contact event that was stashed in a
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

		$event_name = ! empty( $pending['event'] ) && in_array( $pending['event'], Metatrac_Settings::lead_form_events(), true ) ? $pending['event'] : 'Lead';

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
