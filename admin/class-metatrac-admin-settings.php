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

		// The access token field renders blank (see render_settings_page())
		// so its saved value never appears in the page HTML. That means a
		// blank submission means "leave unchanged", not "clear it", so only
		// overwrite when the admin actually typed something, or wipe it when
		// the "clear" checkbox is ticked.
		$output['access_token'] = ! empty( $input['clear_access_token'] )
			? ''
			: ( ! empty( $input['access_token'] ) ? sanitize_text_field( $input['access_token'] ) : $current['access_token'] );

		$posted_events        = ( isset( $input['enabled_events'] ) && is_array( $input['enabled_events'] ) ) ? $input['enabled_events'] : [];
		$ecommerce_events     = Metatrac_Settings::ecommerce_events();
		$gravity_forms_events = Metatrac_Settings::gravity_forms_events();
		$dependency_checker   = new Metatrac_Dependency_Checker();
		$woocommerce_active   = $dependency_checker->is_woocommerce_active();
		$gravity_forms_active = $dependency_checker->is_gravity_forms_active();

		$output['enabled_events'] = [];
		foreach ( Metatrac_Settings::trackable_events() as $event ) {
			$needs_woocommerce   = ! $woocommerce_active && in_array( $event, $ecommerce_events, true );
			$needs_gravity_forms = ! $gravity_forms_active && in_array( $event, $gravity_forms_events, true );

			// Checkboxes for events that need an inactive plugin render
			// disabled (see render_settings_page()), so browsers never submit
			// them; keep whatever was already stored instead of treating
			// their absence as the admin unchecking them.
			if ( $needs_woocommerce || $needs_gravity_forms ) {
				if ( in_array( $event, (array) $current['enabled_events'], true ) ) {
					$output['enabled_events'][] = $event;
				}
				continue;
			}

			if ( in_array( $event, $posted_events, true ) ) {
				$output['enabled_events'][] = $event;
			}
		}

		// Same reasoning as the enabled_events gate above: the form-selector
		// fields only render (and so only ever get submitted) when Gravity
		// Forms is active, so an inactive site keeps whatever was last saved.
		if ( $gravity_forms_active ) {
			$output['lead_form_mode'] = ( isset( $input['lead_form_mode'] ) && 'selected' === $input['lead_form_mode'] ) ? 'selected' : 'all';

			$posted_form_ids         = ( isset( $input['lead_form_ids'] ) && is_array( $input['lead_form_ids'] ) ) ? array_map( 'intval', $input['lead_form_ids'] ) : [];
			$active_form_ids         = wp_list_pluck( $this->active_gravity_forms(), 'id' );
			$output['lead_form_ids'] = array_values( array_intersect( $posted_form_ids, array_map( 'intval', $active_form_ids ) ) );
		} else {
			$output['lead_form_mode'] = $current['lead_form_mode'];
			$output['lead_form_ids']  = $current['lead_form_ids'];
		}

		$output['contact_mailto'] = ! empty( $input['contact_mailto'] );

		// Posted as a list of {page_id, event} rows (see render_settings_page()'s
		// repeater), collapsed here into the stored page_id => event map.
		// Validated against the site's actual published pages and Meta's
		// actual standard event list, rather than trusted as posted, since
		// both are attacker-controllable form values. A page picked in more
		// than one row keeps whichever row appears last.
		$posted_rows        = ( isset( $input['page_events'] ) && is_array( $input['page_events'] ) ) ? $input['page_events'] : [];
		$published_page_ids = wp_list_pluck( $this->published_pages(), 'ID' );
		$standard_events    = Metatrac_Settings::standard_events();

		$output['page_events'] = [];
		foreach ( $posted_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$page_id = ! empty( $row['page_id'] ) ? (int) $row['page_id'] : 0;
			$event   = isset( $row['event'] ) ? $row['event'] : '';

			if ( ! in_array( $page_id, $published_page_ids, true ) || ! in_array( $event, $standard_events, true ) ) {
				continue;
			}

			$output['page_events'][ $page_id ] = $event;
		}

		$output['debug_mode'] = ! empty( $input['debug_mode'] );

		return $output;
	}

	/**
	 * Every published page on the site, for the Page Events selector.
	 *
	 * @return array WP_Post objects.
	 */
	private function published_pages() {
		return get_posts(
			[
				'post_type'     => 'page',
				'post_status'   => 'publish',
				'numberposts'   => -1,
				'orderby'       => 'title',
				'order'         => 'ASC',
				'no_found_rows' => true,
			]
		);
	}

	/**
	 * The site's active, non-trashed Gravity Forms, for the Lead form
	 * selector. Empty when Gravity Forms isn't active.
	 *
	 * @return array Gravity Forms form arrays (each with at least 'id', 'title').
	 */
	private function active_gravity_forms() {
		if ( ! class_exists( 'GFAPI' ) ) {
			return [];
		}

		$forms = GFAPI::get_forms( true, false );
		return is_array( $forms ) ? $forms : [];
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
			'FindLocation'     => __( 'Google Maps Link Clicked (FindLocation), once per session', 'metatrac' ),
			'Lead'             => __( 'Gravity Forms Submitted (Lead)', 'metatrac' ),
		];
	}

	/**
	 * Human-readable labels for every selectable Page Events standard event
	 * (Metatrac_Settings::standard_events()), grouped roughly the way Meta's
	 * own docs group them.
	 *
	 * @return array
	 */
	private function standard_event_labels() {
		return [
			'Lead'                 => __( 'Lead Submitted (Lead)', 'metatrac' ),
			'Contact'              => __( 'Contact (Contact)', 'metatrac' ),
			'FindLocation'         => __( 'Location Found (FindLocation)', 'metatrac' ),
			'Schedule'             => __( 'Appointment Scheduled (Schedule)', 'metatrac' ),
			'SubmitApplication'    => __( 'Application Submitted (SubmitApplication)', 'metatrac' ),
			'CompleteRegistration' => __( 'Registration Completed (CompleteRegistration)', 'metatrac' ),
			'StartTrial'           => __( 'Trial Started (StartTrial)', 'metatrac' ),
			'Subscribe'            => __( 'Subscription Started (Subscribe)', 'metatrac' ),
			'ViewContent'          => __( 'Content Viewed (ViewContent)', 'metatrac' ),
			'Search'               => __( 'Search Performed (Search)', 'metatrac' ),
			'AddToCart'            => __( 'Added to Cart (AddToCart)', 'metatrac' ),
			'AddToWishlist'        => __( 'Added to Wishlist (AddToWishlist)', 'metatrac' ),
			'InitiateCheckout'     => __( 'Checkout Started (InitiateCheckout)', 'metatrac' ),
			'AddPaymentInfo'       => __( 'Payment Info Added (AddPaymentInfo)', 'metatrac' ),
			'Purchase'             => __( 'Purchase Completed (Purchase)', 'metatrac' ),
			'CustomizeProduct'     => __( 'Product Customized (CustomizeProduct)', 'metatrac' ),
			'Donate'               => __( 'Donation Made (Donate)', 'metatrac' ),
		];
	}

	/**
	 * Builds the <option> markup for a Page Events row's page dropdown,
	 * shared by the rendered rows and the JS row template so both use
	 * identical markup.
	 *
	 * @param array $pages           Published pages (see published_pages()).
	 * @param int   $selected_page_id Currently selected page ID, or 0.
	 * @return string
	 */
	private function page_options_html( array $pages, $selected_page_id ) {
		ob_start();
		?>
		<option value=""><?php esc_html_e( 'Select a page', 'metatrac' ); ?></option>
		<?php foreach ( $pages as $page ) : ?>
			<option value="<?php echo esc_attr( $page->ID ); ?>" <?php selected( (int) $selected_page_id, $page->ID ); ?>><?php echo esc_html( $page->post_title ); ?></option>
		<?php endforeach; ?>
		<?php
		return ob_get_clean();
	}

	/**
	 * Builds the <option> markup for a Page Events row's event dropdown,
	 * shared by the rendered rows and the JS row template so both use
	 * identical markup.
	 *
	 * @param string $selected_event Currently selected event, or ''.
	 * @return string
	 */
	private function event_options_html( $selected_event ) {
		ob_start();
		?>
		<option value=""><?php esc_html_e( 'Select an event', 'metatrac' ); ?></option>
		<?php foreach ( $this->standard_event_labels() as $event => $label ) : ?>
			<option value="<?php echo esc_attr( $event ); ?>" <?php selected( $selected_event, $event ); ?>><?php echo esc_html( $label ); ?></option>
		<?php endforeach; ?>
		<?php
		return ob_get_clean();
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
								$dependency_checker   = new Metatrac_Dependency_Checker();
								$woocommerce_active   = $dependency_checker->is_woocommerce_active();
								$gravity_forms_active = $dependency_checker->is_gravity_forms_active();
								$ecommerce_events     = Metatrac_Settings::ecommerce_events();
								$gravity_forms_events = Metatrac_Settings::gravity_forms_events();
								$event_checkbox_ids   = [
									'Lead' => 'metatrac_event_lead',
								];
								foreach ( $this->event_labels() as $key => $label ) :
									$needs_woocommerce   = ! $woocommerce_active && in_array( $key, $ecommerce_events, true );
									$needs_gravity_forms = ! $gravity_forms_active && in_array( $key, $gravity_forms_events, true );
									$needs_plugin        = $needs_woocommerce || $needs_gravity_forms;
									?>
									<label style="display:block;margin-bottom:6px;<?php echo $needs_plugin ? 'color:#a7aaad;' : ''; ?>">
										<input type="checkbox" <?php echo isset( $event_checkbox_ids[ $key ] ) ? 'id="' . esc_attr( $event_checkbox_ids[ $key ] ) . '"' : ''; ?> name="metatrac_settings[enabled_events][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $settings['enabled_events'], true ) ); ?> <?php disabled( $needs_plugin ); ?> />
										<?php echo esc_html( $label ); ?>
										<?php if ( $needs_woocommerce ) : ?>
											<?php esc_html_e( '(requires WooCommerce)', 'metatrac' ); ?>
										<?php elseif ( $needs_gravity_forms ) : ?>
											<?php esc_html_e( '(requires Gravity Forms)', 'metatrac' ); ?>
										<?php endif; ?>
									</label>
									<?php if ( 'Contact' === $key ) : ?>
										<label style="display:block;margin-bottom:6px;">
											<input type="checkbox" name="metatrac_settings[contact_mailto]" value="1" <?php checked( $settings['contact_mailto'] ); ?> />
											<?php esc_html_e( 'Mailto Link Clicked (Contact), once per session', 'metatrac' ); ?>
										</label>
									<?php endif; ?>
									<?php if ( 'Lead' === $key && $gravity_forms_active ) : ?>
										<?php
										$active_forms   = $this->active_gravity_forms();
										$lead_enabled   = in_array( 'Lead', (array) $settings['enabled_events'], true );
										$selected_forms = array_map( 'intval', (array) $settings['lead_form_ids'] );
										?>
										<div id="metatrac_lead_form_selector" style="margin:0 0 14px 24px;<?php echo $lead_enabled ? '' : 'display:none;'; ?>">
											<label style="display:block;margin-bottom:4px;">
												<input type="radio" name="metatrac_settings[lead_form_mode]" value="all" <?php checked( 'selected' !== $settings['lead_form_mode'] ); ?> />
												<?php esc_html_e( 'All active Gravity Forms', 'metatrac' ); ?>
											</label>
											<label style="display:block;">
												<input type="radio" name="metatrac_settings[lead_form_mode]" value="selected" <?php checked( 'selected' === $settings['lead_form_mode'] ); ?> />
												<?php esc_html_e( 'Only these forms:', 'metatrac' ); ?>
											</label>
											<div style="margin:4px 0 0 24px;">
												<?php if ( empty( $active_forms ) ) : ?>
													<p class="description"><?php esc_html_e( 'No active Gravity Forms found.', 'metatrac' ); ?></p>
												<?php else : ?>
													<?php foreach ( $active_forms as $form ) : ?>
														<label style="display:block;">
															<input type="checkbox" name="metatrac_settings[lead_form_ids][]" value="<?php echo esc_attr( $form['id'] ); ?>" <?php checked( in_array( (int) $form['id'], $selected_forms, true ) ); ?> />
															<?php echo esc_html( $form['title'] ); ?>
														</label>
													<?php endforeach; ?>
												<?php endif; ?>
											</div>
										</div>
									<?php endif; ?>
								<?php endforeach; ?>
							</fieldset>
							<?php if ( ! $woocommerce_active ) : ?>
								<p class="description"><?php esc_html_e( 'WooCommerce is not active. PageView and Contact are still tracked; ecommerce events will resume once WooCommerce is active.', 'metatrac' ); ?></p>
							<?php endif; ?>
							<?php if ( ! $gravity_forms_active ) : ?>
								<p class="description"><?php esc_html_e( 'Gravity Forms is not active. Lead tracking will resume once Gravity Forms is active.', 'metatrac' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Page Events', 'metatrac' ); ?></th>
						<td>
							<?php
							$pages       = $this->published_pages();
							$page_events = (array) $settings['page_events'];
							$row_index   = 0;
							?>
							<?php if ( empty( $pages ) ) : ?>
								<p class="description"><?php esc_html_e( 'No published pages found.', 'metatrac' ); ?></p>
							<?php else : ?>
								<div id="metatrac_page_event_rows">
									<?php if ( empty( $page_events ) ) : ?>
										<div class="metatrac-page-event-row" style="margin-bottom:6px;">
											<select name="metatrac_settings[page_events][0][page_id]"><?php echo $this->page_options_html( $pages, 0 ); ?></select>
											<select name="metatrac_settings[page_events][0][event]"><?php echo $this->event_options_html( '' ); ?></select>
											<button type="button" class="button metatrac-remove-page-event-row"><?php esc_html_e( 'Remove', 'metatrac' ); ?></button>
										</div>
										<?php $row_index = 1; ?>
									<?php else : ?>
										<?php foreach ( $page_events as $page_id => $event ) : ?>
											<div class="metatrac-page-event-row" style="margin-bottom:6px;">
												<select name="metatrac_settings[page_events][<?php echo (int) $row_index; ?>][page_id]"><?php echo $this->page_options_html( $pages, $page_id ); ?></select>
												<select name="metatrac_settings[page_events][<?php echo (int) $row_index; ?>][event]"><?php echo $this->event_options_html( $event ); ?></select>
												<button type="button" class="button metatrac-remove-page-event-row"><?php esc_html_e( 'Remove', 'metatrac' ); ?></button>
											</div>
											<?php ++$row_index; ?>
										<?php endforeach; ?>
									<?php endif; ?>
								</div>
								<p>
									<button type="button" id="metatrac_add_page_event_row" class="button"><?php esc_html_e( '+ Add Page Event', 'metatrac' ); ?></button>
								</p>
								<template id="metatrac_page_event_row_template" data-next-index="<?php echo (int) $row_index; ?>">
									<div class="metatrac-page-event-row" style="margin-bottom:6px;">
										<select name="metatrac_settings[page_events][__INDEX__][page_id]"><?php echo $this->page_options_html( $pages, 0 ); ?></select>
										<select name="metatrac_settings[page_events][__INDEX__][event]"><?php echo $this->event_options_html( '' ); ?></select>
										<button type="button" class="button metatrac-remove-page-event-row"><?php esc_html_e( 'Remove', 'metatrac' ); ?></button>
									</div>
								</template>
							<?php endif; ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to Meta's Standard Events documentation. */
									esc_html__( 'Pick a page and a standard event (see %s); it fires that event once, on page load, whenever that page is viewed, independent of the Events to Track checkboxes above. Add as many page/event pairs as you like.', 'metatrac' ),
									'<a href="https://www.facebook.com/business/help/402791146561655?id=1205376682832142" target="_blank" rel="noopener noreferrer">' . esc_html__( "Meta's Standard Events", 'metatrac' ) . '</a>'
								);
								?>
							</p>
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
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<script>
		( function () {
			var leadCheckbox = document.getElementById( 'metatrac_event_lead' );
			var leadSelector = document.getElementById( 'metatrac_lead_form_selector' );
			if ( leadCheckbox && leadSelector ) {
				leadCheckbox.addEventListener( 'change', function () {
					leadSelector.style.display = leadCheckbox.checked ? '' : 'none';
				} );
			}
		} )();

		( function () {
			var addButton = document.getElementById( 'metatrac_add_page_event_row' );
			var container = document.getElementById( 'metatrac_page_event_rows' );
			var template  = document.getElementById( 'metatrac_page_event_row_template' );

			if ( ! addButton || ! container || ! template ) {
				return;
			}

			var nextIndex = parseInt( template.getAttribute( 'data-next-index' ), 10 ) || 0;

			addButton.addEventListener( 'click', function () {
				var row = template.content.firstElementChild.cloneNode( true );
				row.querySelectorAll( 'select' ).forEach( function ( select ) {
					select.name = select.name.replace( '__INDEX__', nextIndex );
				} );
				container.appendChild( row );
				nextIndex++;
			} );

			container.addEventListener( 'click', function ( event ) {
				if ( event.target.classList.contains( 'metatrac-remove-page-event-row' ) ) {
					event.target.closest( '.metatrac-page-event-row' ).remove();
				}
			} );
		} )();
		</script>
		<?php
	}
}
