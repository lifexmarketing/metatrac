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
 * tel:/sms: link and/or a mailto: link anywhere on the page, independently
 * controlled by metatracContact.trackTelSms and .trackMailto (a site can
 * enable either without the other). window.metatracContact is only
 * localized (see class-metatrac-contact-tracker.php) when at least one of
 * the two is enabled, so its absence means there's nothing to listen for.
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

		var selectors = [];
		if ( metatracContact.trackTelSms ) {
			selectors.push( 'a[href^="tel:"]', 'a[href^="sms:"]' );
		}
		if ( metatracContact.trackMailto ) {
			selectors.push( 'a[href^="mailto:"]' );
		}

		var link = selectors.length ? event.target.closest( selectors.join( ', ' ) ) : null;
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

/**
 * FindLocation event: fires once per browser session on the first click/tap
 * of a link to Google Maps anywhere on the page (a "Get Directions" link, an
 * embedded map's "View larger map" link, etc.). window.metatracFindLocation
 * is only localized (see class-metatrac-find-location-tracker.php) when the
 * FindLocation event is enabled, so its absence means there's nothing to
 * listen for.
 */
( function () {
	if ( ! window.metatracFindLocation ) {
		return;
	}

	var SESSION_KEY = 'metatracFindLocationFired';

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

	// Matched on the link's own resolved hostname/pathname (rather than a
	// regex over the raw href) so this doesn't accidentally match some other
	// site's link that merely contains "google.com/maps" in a query string.
	function isGoogleMapsLink( link ) {
		var host = ( link.hostname || '' ).toLowerCase();
		var path = link.pathname || '';

		if ( /(^|\.)goo\.gl$/.test( host ) ) {
			return /^\/maps(\/|$)/.test( path ); // goo.gl/maps/... short links
		}

		if ( /(^|\.)maps\.app\.goo\.gl$/.test( host ) ) {
			return true; // maps.app.goo.gl/... short links
		}

		if ( /^maps\.google\.[a-z.]+$/.test( host ) ) {
			return true; // maps.google.com/..., maps.google.co.uk/..., any path
		}

		if ( /^(www\.)?google\.[a-z.]+$/.test( host ) ) {
			return /^\/maps(\/|$)/.test( path ); // google.com/maps/... ("Get Directions" / embedded map links)
		}

		return false;
	}

	document.addEventListener( 'click', function ( event ) {
		if ( alreadyFiredThisSession() || ! event.target.closest ) {
			return;
		}

		var link = event.target.closest( 'a[href]' );
		if ( ! link || ! isGoogleMapsLink( link ) ) {
			return;
		}

		markFiredThisSession();

		var eventId = generateEventId();

		window.metatracFireEvent( { name: 'FindLocation', params: {}, id: eventId } );

		var body = new URLSearchParams( {
			action: 'metatrac_find_location',
			nonce: metatracFindLocation.nonce,
			event_id: eventId,
			page_url: window.location.href
		} );

		if ( navigator.sendBeacon ) {
			navigator.sendBeacon( metatracFindLocation.ajaxUrl, body );
		} else {
			fetch( metatracFindLocation.ajaxUrl, { method: 'POST', body: body, keepalive: true } );
		}
	} );
} )();
