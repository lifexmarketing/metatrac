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

		$allowed_events         = Metatrac_Settings::trackable_events();
		$output['enabled_events'] = [];
		if ( isset( $input['enabled_events'] ) && is_array( $input['enabled_events'] ) ) {
			foreach ( $input['enabled_events'] as $event ) {
				if ( in_array( $event, $allowed_events, true ) ) {
					$output['enabled_events'][] = $event;
				}
			}
		}

		$output['debug_mode'] = ! empty( $input['debug_mode'] );

		return $output;
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
								<?php foreach ( $this->event_labels() as $key => $label ) : ?>
									<label style="display:block;margin-bottom:6px;">
										<input type="checkbox" name="metatrac_settings[enabled_events][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $settings['enabled_events'], true ) ); ?> />
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
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
								<?php esc_html_e( 'Only needed if METATRAC_GITHUB_TOKEN is not already defined in wp-config.php. A GitHub personal access token with read access to the private lifexmarketing/metatrac repo, used only to check for and install plugin updates.', 'metatrac' ); ?>
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
