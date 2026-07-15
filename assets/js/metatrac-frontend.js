/**
 * MetaTrac front-end helper.
 *
 * Defines window.metatracFireEvent(), the single place a queued event turns
 * into an actual fbq() call (and, in debug mode, a console.log). PHP feeds
 * this function events from two places:
 *  - the footer queue flush (page-load events: ViewContent, InitiateCheckout, Purchase)
 *  - the WooCommerce ajax add-to-cart fragment response (AddToCart)
 */
window.metatracFireEvent = function ( evt ) {
	if ( ! evt || ! evt.name ) {
		return;
	}

	if ( typeof fbq === 'function' ) {
		fbq( 'track', evt.name, evt.params || {}, evt.id ? { eventID: evt.id } : undefined );
	}

	if ( window.metatracDebug ) {
		console.log( '[MetaTrac] Event fired: ' + evt.name, evt.params || {}, evt.id || '' );
	}
};

/**
 * Contact event: fires once per browser session on the first click/tap of a
 * tel: or sms: link anywhere on the page. window.metatracContact is only
 * localized (see class-metatrac-contact-tracker.php) when the Contact event
 * is enabled, so its absence means there's nothing to listen for.
 */
( function () {
	if ( ! window.metatracContact ) {
		return;
	}

	var SESSION_KEY = 'metatracContactFired';

	function alreadyFiredThisSession() {
		try {
			return 'true' === sessionStorage.getItem( SESSION_KEY );
		} catch ( e ) {
			return false; // Storage unavailable (e.g. private browsing); worst case, fires more than once.
		}
	}

	function markFiredThisSession() {
		try {
			sessionStorage.setItem( SESSION_KEY, 'true' );
		} catch ( e ) {
			// Nothing to do if storage is unavailable.
		}
	}

	function generateEventId() {
		if ( window.crypto && typeof window.crypto.randomUUID === 'function' ) {
			return window.crypto.randomUUID();
		}
		return 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'.replace( /x/g, function () {
			return Math.floor( Math.random() * 16 ).toString( 16 );
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		if ( alreadyFiredThisSession() || ! event.target.closest ) {
			return;
		}

		var link = event.target.closest( 'a[href^="tel:"], a[href^="sms:"]' );
		if ( ! link ) {
			return;
		}

		markFiredThisSession();

		var eventId = generateEventId();

		window.metatracFireEvent( { name: 'Contact', params: {}, id: eventId } );

		var body = new URLSearchParams( {
			action: 'metatrac_contact',
			nonce: metatracContact.nonce,
			event_id: eventId,
			page_url: window.location.href
		} );

		if ( navigator.sendBeacon ) {
			navigator.sendBeacon( metatracContact.ajaxUrl, body );
		} else {
			fetch( metatracContact.ajaxUrl, { method: 'POST', body: body, keepalive: true } );
		}
	} );
} )();
