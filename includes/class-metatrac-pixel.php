<?php
/**
 * Class Metatrac_Pixel
 *
 * Outputs the base Meta Pixel snippet (PageView on every page) and provides a
 * small queue that WooCommerce event hooks push into; the queue is flushed as
 * a script in the footer, which calls the shared metatracFireEvent() JS
 * helper (assets/js/metatrac-frontend.js) for each queued event.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Metatrac_Pixel {

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
	}

	/**
	 * Enqueues the small helper script that actually calls fbq() and,
	 * in debug mode, console.log()s each event.
	 */
	public function enqueue_frontend_script() {
		if ( ! Metatrac_Settings::has_pixel_id() ) {
			return;
		}

		wp_register_script( 'metatrac-frontend', METATRAC_PLUGIN_URL . 'assets/js/metatrac-frontend.js', [], METATRAC_VERSION, true );
		wp_enqueue_script( 'metatrac-frontend' );
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
