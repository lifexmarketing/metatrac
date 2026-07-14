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
