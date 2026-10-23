/**
 * MetaTrac front-end helper.
 *
 * Defines window.metatracFireEvent(), the single place a queued event turns
 * into an actual fbq() call (and, in debug mode, a console.log). PHP feeds
 * this function events from:
 *  - the footer queue flush (page-load events, e.g. Purchase, and
 *    AddToCart's non-ajax redirect fallback, id already set; plus
 *    ViewContent, InitiateCheckout, and Page Events, deferred, no id yet)
 *  - the WooCommerce ajax add-to-cart fragment response (AddToCart, id
 *    already set)
 *
 * An event queued with `deferred: true` and no `id` (see
 * Metatrac_Pixel::queue_deferred_event()) is one whose page render might be
 * served from a page cache to many different visitors unchanged, so PHP
 * couldn't safely mint its event_id or send its CAPI copy at render time.
 * This function mints the id itself, fires the Pixel call with it right
 * away, and reports it to metatracDeferred.action (admin-ajax.php) so the
 * matching CAPI call runs fresh on every real page load instead.
 *
 * window.metatracOptedOut (set by the base pixel snippet in wp_head, see
 * Metatrac_Pixel::output_base_pixel()) is true when the site honors Do Not
 * Track / Global Privacy Control and this visitor's browser sends either
 * one. Everything below checks it before any fbq() call or CAPI request,
 * since another plugin may still have loaded its own fbq.
 */
function metatracGenerateEventId() {
	if ( window.crypto && typeof window.crypto.randomUUID === 'function' ) {
		return window.crypto.randomUUID();
	}
	return 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'.replace( /x/g, function () {
		return Math.floor( Math.random() * 16 ).toString( 16 );
	} );
}

window.metatracFireEvent = function ( evt ) {
	if ( ! evt || ! evt.name || window.metatracOptedOut ) {
		return;
	}

	var eventId = evt.id || ( evt.deferred ? metatracGenerateEventId() : '' );

	if ( typeof fbq === 'function' ) {
		fbq( 'track', evt.name, evt.params || {}, eventId ? { eventID: eventId } : undefined );
	}

	if ( window.metatracDebug ) {
		console.log( '[MetaTrac] Event fired: ' + evt.name, evt.params || {}, eventId );
	}

	if ( evt.deferred && eventId && window.metatracDeferred ) {
		metatracSendDeferred( evt, eventId );
	}
};

function metatracReadCookie( name ) {
	var match = document.cookie.match( new RegExp( '(?:^|; )' + name + '=([^;]*)' ) );
	return match ? decodeURIComponent( match[ 1 ] ) : '';
}

/**
 * _fbc, or one built from a fbclid on this page's URL when fbevents.js
 * hasn't written the cookie yet. The server can't do this itself for
 * deferred events, since the admin-ajax request carries no fbclid.
 */
function metatracResolveFbc() {
	var fbc = metatracReadCookie( '_fbc' );
	if ( fbc ) {
		return fbc;
	}
	var fbclid = new URLSearchParams( window.location.search ).get( 'fbclid' );
	return fbclid ? 'fb.1.' + Date.now() + '.' + fbclid : '';
}

/**
 * Posts a deferred event's CAPI copy. On a visitor's first page view this
 * usually runs before fbevents.js has loaded and set _fbp, so the server
 * would read an empty cookie; instead this waits up to 1.5s for _fbp and
 * sends fbp/fbc in the body. If the visitor leaves first, it sends right
 * away with whatever it has, rather than losing the event.
 */
function metatracSendDeferred( evt, eventId ) {
	var MAX_WAIT_MS = 1500;
	var POLL_MS     = 100;
	var waited      = 0;
	var sent        = false;
	var timer;

	function send( leaving ) {
		if ( sent ) {
			return;
		}
		sent = true;
		clearTimeout( timer );
		window.removeEventListener( 'pagehide', onPageHide );

		var body = new URLSearchParams( {
			action: window.metatracDeferred.action,
			nonce: window.metatracDeferred.nonce,
			event_name: evt.name,
			event_id: eventId,
			// The browser's own URL, not evt.pageUrl: that was rendered into
			// HTML a page cache may be serving to every visitor, fbclid and all.
			page_url: window.location.href,
			custom_data: JSON.stringify( evt.params || {} ),
			fbp: metatracReadCookie( '_fbp' ),
			fbc: metatracResolveFbc()
		} );

		if ( leaving && navigator.sendBeacon ) {
			navigator.sendBeacon( window.metatracDeferred.ajaxUrl, body );
		} else {
			fetch( window.metatracDeferred.ajaxUrl, { method: 'POST', body: body, keepalive: true } );
		}
	}

	function onPageHide() {
		send( true );
	}

	function poll() {
		if ( metatracReadCookie( '_fbp' ) || waited >= MAX_WAIT_MS ) {
			send( false );
			return;
		}
		waited += POLL_MS;
		timer = setTimeout( poll, POLL_MS );
	}

	window.addEventListener( 'pagehide', onPageHide );
	poll();
}

/**
 * Contact event: fires once per browser session on the first click/tap of a
 * tel:/sms: link and/or a mailto: link anywhere on the page, independently
 * controlled by metatracContact.trackTelSms and .trackMailto (a site can
 * enable either without the other). window.metatracContact is only
 * localized (see class-metatrac-contact-tracker.php) when at least one of
 * the two is enabled, so its absence means there's nothing to listen for.
 */
( function () {
	if ( ! window.metatracContact || window.metatracOptedOut ) {
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

		var eventId = metatracGenerateEventId();

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
	if ( ! window.metatracFindLocation || window.metatracOptedOut ) {
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

		var eventId = metatracGenerateEventId();

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
