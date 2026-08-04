<?php
/**
 * Class Metatrac_Admin_Settings
 *
 * Renders and saves the MetaTrac settings screen, under Settings > MetaTrac.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Metatrac_Admin_Settings {

	const OPTION_GROUP = 'metatrac_settings_group';
	const PAGE_SLUG    = 'metatrac-settings';

	/**
	 * Wires up the admin hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_filter( 'plugin_action_links_' . METATRAC_PLUGIN_BASENAME, [ $this, 'add_settings_link' ] );
	}

	/**
	 * Adds the settings page under the Settings menu.
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'options-general.php',
			__( 'MetaTrac Settings', 'metatrac' ),
			__( 'MetaTrac', 'metatrac' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Registers the single settings-array option.
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			Metatrac_Settings::OPTION_KEY,
			[ 'sanitize_callback' => [ $this, 'sanitize' ] ]
		);
	}

	/**
	 * Sanitizes the full settings array on save.
	 *
	 * @param array $input Raw posted settings.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input   = is_array( $input ) ? $input : [];
		$current = Metatrac_Settings::all();
		$output  = [];

		$output['pixel_id']        = isset( $input['pixel_id'] ) ? preg_replace( '/[^0-9]/', '', $input['pixel_id'] ) : '';
		$output['test_event_code'] = isset( $input['test_event_code'] ) ? sanitize_text_field( $input['test_event_code'] ) : '';

		// The access token and GitHub token fields render blank (see
		// render_settings_page()) so their saved values never appear in the
		// page HTML. That means a blank submission means "leave unchanged",
		// not "clear it", so only overwrite when the admin actually typed
		// something, or wipe it when the matching "clear" checkbox is ticked.
		$output['access_token'] = ! empty( $input['clear_access_token'] )
			? ''
			: ( ! empty( $input['access_token'] ) ? sanitize_text_field( $input['access_token'] ) : $current['access_token'] );

		$output['github_token'] = ! empty( $input['clear_github_token'] )
			? ''
			: ( ! empty( $input['github_token'] ) ? sanitize_text_field( $input['github_token'] ) : $current['github_token'] );

		$posted_events      = ( isset( $input['enabled_events'] ) && is_array( $input['enabled_events'] ) ) ? $input['enabled_events'] : [];
		$ecommerce_events   = Metatrac_Settings::ecommerce_events();
		$woocommerce_active = ( new Metatrac_Dependency_Checker() )->is_woocommerce_active();

		$output['enabled_events'] = [];
		foreach ( Metatrac_Settings::trackable_events() as $event ) {
			// Ecommerce event checkboxes render disabled when WooCommerce is
			// inactive (see render_settings_page()), so browsers never submit
			// them; keep whatever was already stored instead of treating
			// their absence as the admin unchecking them.
			if ( ! $woocommerce_active && in_array( $event, $ecommerce_events, true ) ) {
				if ( in_array( $event, (array) $current['enabled_events'], true ) ) {
					$output['enabled_events'][] = $event;
				}
				continue;
			}

			if ( in_array( $event, $posted_events, true ) ) {
				$output['enabled_events'][] = $event;
			}
		}

		$output['debug_mode'] = ! empty( $input['debug_mode'] );

		return $output;
	}

	/**
	 * Adds a "Settings" link to MetaTrac's row on the Plugins screen,
	 * inserted immediately before Deactivate rather than just prepended,
	 * since other plugins (or "Network Activate") can add links of their
	 * own before it.
	 *
	 * @param array $links Existing action links, keyed by slug.
	 * @return array
	 */
	public function add_settings_link( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Settings', 'metatrac' )
		);

		$position = array_search( 'deactivate', array_keys( $links ), true );

		if ( false === $position ) {
			array_unshift( $links, $settings_link );
			return $links;
		}

		return array_slice( $links, 0, $position, true )
			+ [ 'settings' => $settings_link ]
			+ array_slice( $links, $position, null, true );
	}

	/**
	 * Human-readable labels for each trackable event, in funnel order.
	 *
	 * @return array
	 */
	private function event_labels() {
		return [
			'ViewContent'      => __( 'Product Viewed (ViewContent)', 'metatrac' ),
			'AddToCart'        => __( 'Product Added to Cart (AddToCart)', 'metatrac' ),
			'InitiateCheckout' => __( 'Checkout Started (InitiateCheckout)', 'metatrac' ),
			'Purchase'         => __( 'Order Completed (Purchase)', 'metatrac' ),
			'Contact'          => __( 'Phone/SMS Link Clicked (Contact), once per session', 'metatrac' ),
			'Lead'             => __( 'Gravity Forms Submitted (Lead)', 'metatrac' ),
		];
	}

	/**
	 * Renders the settings page markup.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Metatrac_Settings::all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'MetaTrac Settings', 'metatrac' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="metatrac_pixel_id"><?php esc_html_e( 'Meta Pixel ID', 'metatrac' ); ?></label></th>
						<td>
							<input type="text" id="metatrac_pixel_id" name="metatrac_settings[pixel_id]" value="<?php echo esc_attr( $settings['pixel_id'] ); ?>" class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="metatrac_access_token"><?php esc_html_e( 'Conversions API Access Token', 'metatrac' ); ?></label></th>
						<td>
							<input type="password" id="metatrac_access_token" name="metatrac_settings[access_token]" value="" placeholder="<?php echo esc_attr( $settings['access_token'] ? __( 'Saved, leave blank to keep', 'metatrac' ) : '' ); ?>" class="regular-text" autocomplete="off" />
							<?php if ( $settings['access_token'] ) : ?>
								<label style="display:block;margin-top:6px;">
									<input type="checkbox" name="metatrac_settings[clear_access_token]" value="1" />
									<?php esc_html_e( 'Clear the saved access token', 'metatrac' ); ?>
								</label>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'From Events Manager > Settings > Conversions API > Generate access token.', 'metatrac' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="metatrac_test_event_code"><?php esc_html_e( 'Test Event Code', 'metatrac' ); ?></label></th>
						<td>
							<input type="text" id="metatrac_test_event_code" name="metatrac_settings[test_event_code]" value="<?php echo esc_attr( $settings['test_event_code'] ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Optional. Paste from Events Manager > Test Events to confirm server events are arriving, then remove it.', 'metatrac' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Events to Track', 'metatrac' ); ?></th>
						<td>
							<fieldset>
								<?php
								$woocommerce_active = ( new Metatrac_Dependency_Checker() )->is_woocommerce_active();
								$ecommerce_events   = Metatrac_Settings::ecommerce_events();
								foreach ( $this->event_labels() as $key => $label ) :
									$needs_woocommerce = ! $woocommerce_active && in_array( $key, $ecommerce_events, true );
									?>
									<label style="display:block;margin-bottom:6px;<?php echo $needs_woocommerce ? 'color:#a7aaad;' : ''; ?>">
										<input type="checkbox" name="metatrac_settings[enabled_events][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $settings['enabled_events'], true ) ); ?> <?php disabled( $needs_woocommerce ); ?> />
										<?php echo esc_html( $label ); ?>
										<?php if ( $needs_woocommerce ) : ?>
											<?php esc_html_e( '(requires WooCommerce)', 'metatrac' ); ?>
										<?php endif; ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<?php if ( ! $woocommerce_active ) : ?>
								<p class="description"><?php esc_html_e( 'WooCommerce is not active. PageView, Contact, and Lead are still tracked; ecommerce events will resume once WooCommerce is active.', 'metatrac' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="metatrac_debug_mode"><?php esc_html_e( 'Debug Mode', 'metatrac' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="metatrac_debug_mode" name="metatrac_settings[debug_mode]" value="1" <?php checked( $settings['debug_mode'] ); ?> />
								<?php esc_html_e( 'Log every fired event to the browser console, and to a dedicated debug.log file along with the page it fired on.', 'metatrac' ); ?>
							</label>
							<?php if ( Metatrac_Settings::is_debug() ) : ?>
								<p class="description">
									<?php
									printf(
										/* translators: %s: absolute path to the debug log file. */
										esc_html__( 'Log file: %s', 'metatrac' ),
										'<code>' . esc_html( Metatrac_Logger::log_file_path() ) . '</code>'
									);
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="metatrac_github_token"><?php esc_html_e( 'GitHub Update Token', 'metatrac' ); ?></label></th>
						<td>
							<input type="password" id="metatrac_github_token" name="metatrac_settings[github_token]" value="" placeholder="<?php echo esc_attr( $settings['github_token'] ? __( 'Saved, leave blank to keep', 'metatrac' ) : '' ); ?>" class="regular-text" autocomplete="off" />
							<?php if ( $settings['github_token'] ) : ?>
								<label style="display:block;margin-top:6px;">
									<input type="checkbox" name="metatrac_settings[clear_github_token]" value="1" />
									<?php esc_html_e( 'Clear the saved GitHub token', 'metatrac' ); ?>
								</label>
							<?php endif; ?>
							<p class="description">
								<?php esc_html_e( 'Optional. The lifexmarketing/metatrac repo is public, so update checks work without a token; only add one to raise the GitHub API rate limit on sites that check for updates often.', 'metatrac' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
