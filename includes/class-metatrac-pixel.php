<?php
/**
 * Class Metatrac_Pixel
 *
 * Outputs the base Meta Pixel snippet (PageView on every page) and provides a
 * small queue that event hooks push into; the queue is flushed as a script
 * in the footer, which calls the shared metatracFireEvent() JS helper
 * (assets/js/metatrac-frontend.js) for each queued event.
 *
 * Two ways an event reaches that queue:
 *  - fire_event(): mints the event_id and sends the CAPI copy immediately,
 *    server-side. Only safe for events fired from a request that's never
 *    served from a page cache to more than one visitor (an ajax response, or
 *    a page with a naturally unique/per-visitor URL like an order-received
 *    page).
 *  - queue_deferred_event(): no event_id yet; the browser mints one and
 *    reports it back via handle_deferred_event() (admin-ajax.php). Use this
 *    for anything fired from an ordinary page render a caching plugin might
 *    serve unchanged to many visitors, so the Pixel event and its CAPI copy
 *    both stay per-visitor even when the underlying HTML is cached.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Metatrac_Pixel {

	const DEFERRED_AJAX_ACTION  = 'metatrac_deferred_event';
	const DEFERRED_NONCE_ACTION = 'metatrac_deferred_event_nonce';

	/**
	 * Events queued during this request, flushed in the footer.
	 *
	 * @var array
	 */
	private static $queue = [];

	/**
	 * Registers the hooks that render the pixel and flush the event queue.
	 */
	public function init() {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_script' ] );
		add_action( 'wp_head', [ $this, 'output_base_pixel' ], 5 );
		add_action( 'wp_footer', [ $this, 'output_queue' ], 20 );
		add_action( 'wp_ajax_' . self::DEFERRED_AJAX_ACTION, [ $this, 'handle_deferred_event' ] );
		add_action( 'wp_ajax_nopriv_' . self::DEFERRED_AJAX_ACTION, [ $this, 'handle_deferred_event' ] );
		add_filter( 'nonce_life', [ __CLASS__, 'extend_nonce_life' ], 10, 2 );
	}

	/**
	 * Extends this action's nonce lifetime well past any realistic page
	 * cache TTL. enqueue_frontend_script() bakes the nonce into a normal
	 * page render, which is exactly what a caching plugin might serve
	 * unchanged for far longer than WordPress's default ~12-24 hour nonce
	 * window, so without this a deferred event on a long-cached page would
	 * silently fail check_ajax_referer() and lose its CAPI copy (the Pixel
	 * copy is unaffected either way, since it doesn't depend on the nonce).
	 * A week comfortably covers realistic cache lifetimes without leaving
	 * the nonce valid indefinitely.
	 *
	 * @param int    $lifetime Default nonce lifetime in seconds.
	 * @param string $action   The nonce action being ticked.
	 * @return int
	 */
	public static function extend_nonce_life( $lifetime, $action ) {
		return self::DEFERRED_NONCE_ACTION === $action ? WEEK_IN_SECONDS : $lifetime;
	}

	/**
	 * Enqueues the small helper script that actually calls fbq() and,
	 * in debug mode, console.log()s each event; also hands it the ajax URL
	 * and a nonce for handle_deferred_event() below.
	 */
	public function enqueue_frontend_script() {
		if ( ! Metatrac_Settings::has_pixel_id() ) {
			return;
		}

		wp_register_script( 'metatrac-frontend', METATRAC_PLUGIN_URL . 'assets/js/metatrac-frontend.js', [], METATRAC_VERSION, true );
		wp_enqueue_script( 'metatrac-frontend' );

		wp_localize_script(
			'metatrac-frontend',
			'metatracDeferred',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::DEFERRED_AJAX_ACTION,
				'nonce'   => wp_create_nonce( self::DEFERRED_NONCE_ACTION ),
			]
		);
	}

	/**
	 * Adds an event to the queue for output in the footer.
	 *
	 * @param string $name     Standard Meta event name, e.g. 'ViewContent'.
	 * @param array  $params   Event parameters (value, currency, contents, ...).
	 * @param string $event_id Shared pixel/CAPI dedupe id.
	 */
	public static function queue_event( $name, array $params, $event_id ) {
		self::$queue[] = [
			'name'   => $name,
			'params' => $params,
			'id'     => $event_id,
		];
	}

	/**
	 * Queues an event for the Pixel, sends it to the Conversions API, and
	 * logs it: the fan-out shared by every server-driven tracked event
	 * (WooCommerce, Gravity Forms, ...).
	 *
	 * @param string $event_name      Standard Meta event name.
	 * @param array  $custom_data     custom_data payload.
	 * @param string $page_url        Page the event is associated with.
	 * @param array  $extra_user_data Optional email/phone overrides for CAPI matching.
	 * @return string The event_id used, so callers can reuse it (e.g. for the ajax add-to-cart fragment).
	 */
	public static function fire_event( $event_name, array $custom_data, $page_url, array $extra_user_data = [] ) {
		$event_id = wp_generate_uuid4();

		self::queue_event( $event_name, $custom_data, $event_id );
		( new Metatrac_CAPI() )->send_event( $event_name, $event_id, $custom_data, $page_url, $extra_user_data );
		Metatrac_Logger::log_event( $event_name, $page_url, $event_id, $custom_data );

		return $event_id;
	}

	/**
	 * Queues an event for the Pixel without minting an event_id or sending
	 * its CAPI copy yet. Use this instead of fire_event() for any event
	 * fired from a normal page render that a page-caching plugin might
	 * serve unchanged to many different visitors (ViewContent,
	 * InitiateCheckout, Page Events): fire_event() would bake one real
	 * event_id into the cached HTML and send exactly one real CAPI call at
	 * cache-generation time, so every later visitor served that same cached
	 * page would fire a Pixel event Meta dedupes away against that single
	 * stale CAPI event, undercounting real traffic for as long as the page
	 * stays cached.
	 *
	 * Instead, metatracFireEvent() (assets/js/metatrac-frontend.js) mints a
	 * fresh event_id itself when it actually runs in each visitor's browser,
	 * fires the Pixel call with it immediately, and reports it to
	 * handle_deferred_event() below via admin-ajax.php so the CAPI call runs
	 * fresh on every real page load instead of once at render time.
	 *
	 * @param string $event_name  Standard Meta event name.
	 * @param array  $custom_data custom_data payload.
	 * @param string $page_url    Page the event is associated with.
	 */
	public static function queue_deferred_event( $event_name, array $custom_data, $page_url ) {
		self::$queue[] = [
			'name'     => $event_name,
			'params'   => $custom_data,
			'pageUrl'  => $page_url,
			'deferred' => true,
		];
	}

	/**
	 * Receives the client-minted event_id and payload for a deferred event
	 * (see queue_deferred_event()) and sends the matching CAPI event. Runs
	 * fresh on every real page load via admin-ajax.php, which page caches
	 * don't serve from cache, unlike the page render that queued the event.
	 */
	public function handle_deferred_event() {
		$event_name = isset( $_POST['event_name'] ) ? sanitize_text_field( wp_unslash( $_POST['event_name'] ) ) : '';

		if ( ! check_ajax_referer( self::DEFERRED_NONCE_ACTION, 'nonce', false ) ) {
			Metatrac_Logger::log_nonce_failure( $event_name );
			wp_send_json_error();
		}

		$event_id = isset( $_POST['event_id'] ) ? sanitize_text_field( wp_unslash( $_POST['event_id'] ) ) : '';
		$page_url = isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : self::current_url();

		if ( '' === $event_id || ! in_array( $event_name, Metatrac_Settings::standard_events(), true ) ) {
			wp_send_json_error();
		}

		$custom_data = [];
		if ( isset( $_POST['custom_data'] ) ) {
			$decoded = json_decode( wp_unslash( $_POST['custom_data'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			if ( is_array( $decoded ) ) {
				$custom_data = $decoded;
			}
		}

		( new Metatrac_CAPI() )->send_event( $event_name, $event_id, $custom_data, $page_url );
		Metatrac_Logger::log_event( $event_name, $page_url, $event_id, $custom_data );

		wp_send_json_success();
	}

	/**
	 * Outputs the base fbq loader, init call, and an automatic PageView.
	 */
	public function output_base_pixel() {
		$pixel_id = Metatrac_Settings::get( 'pixel_id' );
		if ( empty( $pixel_id ) ) {
			return;
		}

		$debug = Metatrac_Settings::is_debug();
		?>
		<script>
		window.metatracDebug = <?php echo $debug ? 'true' : 'false'; ?>;
		!function(f,b,e,v,n,t,s)
		{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
		n.callMethod.apply(n,arguments):n.queue.push(arguments)};
		if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
		n.queue=[];t=b.createElement(e);t.async=!0;
		t.src=v;s=b.getElementsByTagName(e)[0];
		s.parentNode.insertBefore(t,s)}(window, document,'script',
		'https://connect.facebook.net/en_US/fbevents.js');
		fbq('init', '<?php echo esc_js( $pixel_id ); ?>');
		fbq('track', 'PageView');
		<?php if ( $debug ) : ?>
		console.log('[MetaTrac] Event fired: PageView');
		<?php endif; ?>
		</script>
		<noscript><img height="1" width="1" style="display:none" alt=""
			src="https://www.facebook.com/tr?id=<?php echo esc_attr( $pixel_id ); ?>&ev=PageView&noscript=1" /></noscript>
		<?php
		Metatrac_Logger::log_event( 'PageView', self::current_url(), '', [] );
	}

	/**
	 * Flushes the event queue as a script that calls metatracFireEvent()
	 * for each entry once that helper is available.
	 */
	public function output_queue() {
		if ( empty( self::$queue ) || ! Metatrac_Settings::has_pixel_id() ) {
			return;
		}
		?>
		<script>
		(function () {
			var metatracQueue = <?php echo wp_json_encode( self::$queue, JSON_HEX_TAG | JSON_HEX_AMP ); ?>;
			function metatracRunQueue() {
				metatracQueue.forEach(function (evt) {
					if (window.metatracFireEvent) {
						window.metatracFireEvent(evt);
					}
				});
			}
			if (window.metatracFireEvent) {
				metatracRunQueue();
			} else {
				document.addEventListener('DOMContentLoaded', metatracRunQueue);
			}
		})();
		</script>
		<?php
	}

	/**
	 * The current front-end request URL, used as event_source_url / page context.
	 *
	 * @return string
	 */
	public static function current_url() {
		if ( empty( $_SERVER['HTTP_HOST'] ) || empty( $_SERVER['REQUEST_URI'] ) ) {
			return home_url( '/' );
		}

		$scheme = is_ssl() ? 'https://' : 'http://';
		$host   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) );
		$uri    = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );

		return $scheme . $host . $uri;
	}
}
