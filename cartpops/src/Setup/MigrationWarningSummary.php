<?php
/**
 * Human-readable summary of value-free legacy migration warnings.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Setup;

/**
 * Name the version 1 settings a migration warning is about, by the screen and
 * label store owners saw in CartPops 1.5. Only migration codes and option names
 * are read, never stored values, so the summary is safe for every notice viewer.
 */
final class MigrationWarningSummary {
	private const PAID_CODES = array(
		'legacy_paid_state_uncertain',
		'legacy_paid_entitlement_requires_adapter',
		'legacy_paid_entitlement_state_uncertain',
		'legacy_paid_features_require_conversion',
		'legacy_custom_recommendations_require_conversion',
	);

	/** Codes another notice or line already explains, or that carry no setting. */
	private const SILENT_CODES = array(
		'legacy_unmapped_options_retained',
		'legacy_rules_behavior_retired',
		'legacy_rules_require_conversion',
		'legacy_paid_rules_require_conversion',
		'legacy_custom_js_requires_review',
	);

	/**
	 * Group warnings by what happened to each setting.
	 *
	 * @param string[] $warnings      Value-free migration warning codes.
	 * @param string[] $unmapped_keys Version 1 option names with no version 2 setting.
	 * @return array{reset: string[], retired: string[], changed: string[], custom_js: bool, pro: bool, other: int}
	 */
	public static function summarize( array $warnings, array $unmapped_keys ): array {
		$summary = array(
			'reset'     => array(),
			'retired'   => array(),
			'changed'   => array(),
			'custom_js' => false,
			'pro'       => false,
			'other'     => 0,
		);
		$labels  = self::labels();
		// Usually reported again by the unreadable option's own code.
		$unnamed_behavior = false;
		$reset_options    = array();
		foreach ( $warnings as $code ) {
			if ( ! is_string( $code ) || in_array( $code, self::SILENT_CODES, true ) ) {
				continue;
			}
			if ( 'legacy_retained_behavior_requires_review' === $code ) {
				$unnamed_behavior = true;
				continue;
			}
			if ( in_array( $code, self::PAID_CODES, true ) ) {
				$summary['pro'] = true;
				continue;
			}
			switch ( $code ) {
				case 'legacy_custom_js_quarantined':
					$summary['custom_js'] = true;
					continue 2;
				case 'legacy_noop_warning_color_retired':
					$summary['retired'][] = $labels['cartpops_color_state_warning'];
					continue 2;
				case 'legacy_animation_slick_mapped_to_slide':
					$summary['changed'][] = $labels['cartpops_animation_type'];
					continue 2;
				case 'invalid_inline_launcher_color_retained':
					$summary['reset'][] = __( 'Colors → Cart launcher: menu launcher colors', 'cartpops' );
					continue 2;
				case 'invalid_recommendation_button_presentation_retained':
					$summary['reset'][] = $labels['cartpops_product_recommendation_engine_button_type'];
					continue 2;
			}
			if ( str_starts_with( $code, 'legacy_add_to_cart_trigger_' ) ) {
				$summary['changed'][] = $labels['cartpops_add_to_cart_trigger'];
				continue;
			}
			// Codes about one V1 option name it; anything else is not about a setting.
			if ( 1 === preg_match( '/(cartpops_[a-z0-9_]+?)(?:_retained)?$/D', $code, $match ) ) {
				$reset_options[ $match[1] ] = true;
			}
		}
		$unlabelled = array();
		foreach ( array_keys( $reset_options ) as $option ) {
			if ( isset( $labels[ $option ] ) ) {
				$summary['reset'][] = $labels[ $option ];
			} else {
				$unlabelled[ $option ] = true;
			}
		}
		foreach ( $unmapped_keys as $option ) {
			if ( ! is_string( $option ) || isset( $reset_options[ $option ] ) ) {
				continue;
			}
			if ( isset( $labels[ $option ] ) ) {
				$summary['retired'][] = $labels[ $option ];
			} else {
				$unlabelled[ $option ] = true;
			}
		}
		$summary['other'] += count( $unlabelled );
		foreach ( array( 'reset', 'retired', 'changed' ) as $group ) {
			$summary[ $group ] = array_values( array_unique( $summary[ $group ] ) );
		}
		if ( $unnamed_behavior && array() === $summary['reset'] ) {
			++$summary['other'];
		}
		// A setting reported as reset is not repeated as retired or changed.
		$summary['retired'] = array_values( array_diff( $summary['retired'], $summary['reset'] ) );
		$summary['changed'] = array_values( array_diff( $summary['changed'], $summary['reset'] ) );
		return $summary;
	}

	/**
	 * Version 1 settings by option name, as "Screen → Section: Label".
	 *
	 * @return array<string, string>
	 */
	private static function labels(): array {
		$labels = array();
		foreach ( self::definitions() as $option => [ $screen, $label ] ) {
			$labels[ $option ] = self::screen( $screen ) . ': ' . $label;
		}
		return $labels;
	}

	/**
	 * Translate a version 1 screen path.
	 *
	 * @param string $screen Untranslated "Tab → Section" path.
	 */
	private static function screen( string $screen ): string {
		[ $tab, $section ] = explode( ' → ', $screen, 2 );
		$tabs              = array(
			'Settings' => __( 'Settings', 'cartpops' ),
			'Colors'   => __( 'Colors', 'cartpops' ),
		);
		$sections          = array(
			'Texts (older versions)'        => __( 'Texts (older versions)', 'cartpops' ),
			'Primary (older versions)'      => __( 'Primary (older versions)', 'cartpops' ),
			'Buttons'                       => __( 'Buttons', 'cartpops' ),
			'Cart Launcher'                 => __( 'Cart Launcher', 'cartpops' ),
			'Cart launcher'                 => __( 'Cart launcher', 'cartpops' ),
			'Custom code'                   => __( 'Custom code', 'cartpops' ),
			'Drawer'                        => __( 'Drawer', 'cartpops' ),
			'Floating Cart Launcher'        => __( 'Floating Cart Launcher', 'cartpops' ),
			'Free Shipping Meter'           => __( 'Free Shipping Meter', 'cartpops' ),
			'Free Shipping Meter settings'  => __( 'Free Shipping Meter settings', 'cartpops' ),
			'General'                       => __( 'General', 'cartpops' ),
			'Miscellaneous & Experimental'  => __( 'Miscellaneous & Experimental', 'cartpops' ),
			'Primary'                       => __( 'Primary', 'cartpops' ),
			'Product Recommendation Engine' => __( 'Product Recommendation Engine', 'cartpops' ),
			'Recommendations'               => __( 'Recommendations', 'cartpops' ),
		);
		return ( $tabs[ $tab ] ?? $tab ) . ' → ' . ( $sections[ $section ] ?? $section );
	}

	/**
	 * Labels from the CartPops 1.5.45 settings screens.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	private static function definitions(): array {
		return array(
			'cartpops_add_to_cart_trigger'                 => array( 'Settings → General', __( 'Select an add to cart trigger', 'cartpops' ) ),
			'cartpops_animation_type'                      => array( 'Settings → Drawer', __( 'Animation type', 'cartpops' ) ),
			'cartpops_border_radius_rounded'               => array( 'Colors → Primary', __( 'Rounded corners', 'cartpops' ) ),
			'cartpops_checkout_button_text'                => array( 'Settings → Drawer', __( 'Checkout button text', 'cartpops' ) ),
			'cartpops_color_accent'                        => array( 'Colors → Primary', __( 'Accent color (older versions)', 'cartpops' ) ),
			'cartpops_color_background_primary'            => array( 'Colors → Primary', __( 'Primary background color', 'cartpops' ) ),
			'cartpops_color_background_secondary'          => array( 'Colors → Primary', __( 'Secondary background color', 'cartpops' ) ),
			'cartpops_color_border'                        => array( 'Colors → Primary', __( 'Accent color', 'cartpops' ) ),
			'cartpops_color_button_primary_background'     => array( 'Colors → Buttons', __( 'Primary button background color', 'cartpops' ) ),
			'cartpops_color_button_primary_text'           => array( 'Colors → Buttons', __( 'Primary button text color', 'cartpops' ) ),
			'cartpops_color_button_quantity_background'    => array( 'Colors → Buttons', __( 'Quantity selector button background', 'cartpops' ) ),
			'cartpops_color_button_quantity_text'          => array( 'Colors → Buttons', __( 'Quantity selector button text color', 'cartpops' ) ),
			'cartpops_color_button_secondary_background'   => array( 'Colors → Buttons', __( 'Secondary button background', 'cartpops' ) ),
			'cartpops_color_button_secondary_text'         => array( 'Colors → Buttons', __( 'Secondary button text color', 'cartpops' ) ),
			'cartpops_color_cart_laucher_background'       => array( 'Colors → Cart launcher', __( 'Cart Launcher background color', 'cartpops' ) ),
			'cartpops_color_cart_laucher_bubble_background' => array( 'Colors → Cart launcher', __( 'Cart Launcher bubble background', 'cartpops' ) ),
			'cartpops_color_cart_laucher_bubble_text'      => array( 'Colors → Cart launcher', __( 'Cart Launcher bubble text', 'cartpops' ) ),
			'cartpops_color_cart_laucher_text'             => array( 'Colors → Cart launcher', __( 'Cart Launcher text color', 'cartpops' ) ),
			'cartpops_color_floating_cart_laucher_background' => array( 'Colors → Floating Cart Launcher', __( 'Floating Cart Launcher background color', 'cartpops' ) ),
			'cartpops_color_floating_cart_laucher_icon'    => array( 'Colors → Floating Cart Launcher', __( 'Floating Cart Launcher icon color', 'cartpops' ) ),
			'cartpops_color_floating_cart_laucher_indicator_background' => array( 'Colors → Floating Cart Launcher', __( 'Floating Cart Launcher indicator background', 'cartpops' ) ),
			'cartpops_color_floating_cart_laucher_indicator_text' => array( 'Colors → Floating Cart Launcher', __( 'Floating Cart Launcher indicator text', 'cartpops' ) ),
			'cartpops_color_free_shipping_meter_background' => array( 'Colors → Free Shipping Meter', __( 'Free Shipping Meter background color', 'cartpops' ) ),
			'cartpops_color_free_shipping_meter_background_active' => array( 'Colors → Free Shipping Meter', __( 'Free Shipping Meter active background color', 'cartpops' ) ),
			'cartpops_color_input_background'              => array( 'Colors → Primary', __( 'Input field background color', 'cartpops' ) ),
			'cartpops_color_input_quantity_background'     => array( 'Colors → Buttons', __( 'Quantity selector input background', 'cartpops' ) ),
			'cartpops_color_input_quantity_border'         => array( 'Colors → Buttons', __( 'Quantity selector input border', 'cartpops' ) ),
			'cartpops_color_input_quantity_text'           => array( 'Colors → Buttons', __( 'Quantity selector input text color', 'cartpops' ) ),
			'cartpops_color_input_text'                    => array( 'Colors → Primary', __( 'Input field text color', 'cartpops' ) ),
			'cartpops_color_overlay'                       => array( 'Colors → Primary', __( 'Overlay color', 'cartpops' ) ),
			'cartpops_color_recommendations_button_background' => array( 'Colors → Recommendations', __( 'Recommendations button background color', 'cartpops' ) ),
			'cartpops_color_recommendations_button_text'   => array( 'Colors → Recommendations', __( 'Recommendations button text color', 'cartpops' ) ),
			'cartpops_color_recommendations_drawer_background' => array( 'Colors → Recommendations', __( 'Drawer Recommendations background color', 'cartpops' ) ),
			'cartpops_color_recommendations_drawer_border' => array( 'Colors → Recommendations', __( 'Drawer Recommendations border color', 'cartpops' ) ),
			'cartpops_color_recommendations_drawer_text'   => array( 'Colors → Recommendations', __( 'Drawer Recommendations text color', 'cartpops' ) ),
			'cartpops_color_recommendations_popup_background' => array( 'Colors → Recommendations', __( 'Popup Recommendations background color', 'cartpops' ) ),
			'cartpops_color_recommendations_popup_text'    => array( 'Colors → Recommendations', __( 'Popup Recommendations text color', 'cartpops' ) ),
			'cartpops_color_state_danger'                  => array( 'Colors → Primary', __( 'Danger color', 'cartpops' ) ),
			'cartpops_color_state_success'                 => array( 'Colors → Primary', __( 'Success color', 'cartpops' ) ),
			'cartpops_color_state_warning'                 => array( 'Colors → Primary', __( 'Warning color', 'cartpops' ) ),
			'cartpops_color_typography_primary'            => array( 'Colors → Primary', __( 'Primary text color', 'cartpops' ) ),
			'cartpops_color_typography_secondary'          => array( 'Colors → Primary', __( 'Secondary text color', 'cartpops' ) ),
			'cartpops_color_typography_tertiary'           => array( 'Colors → Primary', __( 'Tertiary text color', 'cartpops' ) ),
			'cartpops_coupon_form_enable'                  => array( 'Settings → Drawer', __( 'Enable coupon form', 'cartpops' ) ),
			'cartpops_custom_css'                          => array( 'Settings → Custom code', __( 'Custom CSS', 'cartpops' ) ),
			'cartpops_custom_js'                           => array( 'Settings → Custom code', __( 'Custom JS', 'cartpops' ) ),
			'cartpops_customize_animation_duration'        => array( 'Settings → Drawer', __( 'Animation speed', 'cartpops' ) ),
			'cartpops_customize_white_space_text'          => array( 'Settings → Drawer', __( 'Truncate long text', 'cartpops' ) ),
			'cartpops_customize_width_drawer_desktop'      => array( 'Settings → Drawer', __( 'Drawer width desktop (In pixels)', 'cartpops' ) ),
			'cartpops_customize_width_drawer_mobile'       => array( 'Settings → Drawer', __( 'Drawer width mobile (In percentage)', 'cartpops' ) ),
			'cartpops_drawer_footer_display_discount'      => array( 'Settings → Drawer', __( 'Show discount line item', 'cartpops' ) ),
			'cartpops_drawer_footer_display_shipping'      => array( 'Settings → Drawer', __( 'Show shipping line item', 'cartpops' ) ),
			'cartpops_drawer_footer_display_shipping_calculator' => array( 'Settings → Drawer', __( 'Show shipping calculator', 'cartpops' ) ),
			'cartpops_drawer_footer_display_subtotal'      => array( 'Settings → Drawer', __( 'Show subtotal line item', 'cartpops' ) ),
			'cartpops_drawer_footer_display_tax'           => array( 'Settings → Drawer', __( 'Show tax line item', 'cartpops' ) ),
			'cartpops_drawer_footer_display_total'         => array( 'Settings → Drawer', __( 'Show total line item', 'cartpops' ) ),
			'cartpops_drawer_footer_secondary_button'      => array( 'Settings → Drawer', __( 'Secondary button', 'cartpops' ) ),
			'cartpops_drawer_footer_secondary_button_custom_text' => array( 'Settings → Drawer', __( 'Secondary button custom text', 'cartpops' ) ),
			'cartpops_drawer_footer_secondary_button_custom_url' => array( 'Settings → Drawer', __( 'Secondary button custom URL', 'cartpops' ) ),
			'cartpops_floating_cart_launcher_enable'       => array( 'Settings → Cart Launcher', __( 'Enable Floating Cart Launcher', 'cartpops' ) ),
			'cartpops_floating_cart_launcher_hide_empty'   => array( 'Settings → Cart Launcher', __( 'Hide when cart is empty', 'cartpops' ) ),
			'cartpops_floating_cart_launcher_hide_indicator_empty' => array( 'Settings → Cart Launcher', __( 'Floating launcher: hide indicator when cart is empty', 'cartpops' ) ),
			'cartpops_floating_cart_launcher_hide_pages'   => array( 'Settings → Cart Launcher', __( 'Hide on certain pages', 'cartpops' ) ),
			'cartpops_floating_cart_launcher_position'     => array( 'Settings → Cart Launcher', __( 'Select a position', 'cartpops' ) ),
			'cartpops_force_fragments_refresh'             => array( 'Settings → Miscellaneous & Experimental', __( 'Force refresh on page load?', 'cartpops' ) ),
			'cartpops_free_shipping_meter_custom_global'   => array( 'Settings → Free Shipping Meter settings', __( 'Free shipping amount', 'cartpops' ) ),
			'cartpops_free_shipping_meter_enable'          => array( 'Settings → Free Shipping Meter settings', __( 'Enable Free Shipping Meter', 'cartpops' ) ),
			'cartpops_free_shipping_meter_text_achieved'   => array( 'Settings → Free Shipping Meter settings', __( 'Free Shipping Meter achieved text', 'cartpops' ) ),
			'cartpops_free_shipping_meter_text_base'       => array( 'Settings → Free Shipping Meter settings', __( 'Free Shipping Meter Text', 'cartpops' ) ),
			'cartpops_free_shipping_meter_type'            => array( 'Settings → Free Shipping Meter settings', __( 'Free Shipping Meter type', 'cartpops' ) ),
			'cartpops_menu_cart_launcher_icon'             => array( 'Settings → Cart Launcher', __( 'Menu Cart Launcher icon', 'cartpops' ) ),
			'cartpops_menu_cart_launcher_indicator'        => array( 'Settings → Cart Launcher', __( 'Menu Cart Launcher Indicator', 'cartpops' ) ),
			'cartpops_menu_cart_launcher_indicator_empty'  => array( 'Settings → Cart Launcher', __( 'Menu launcher: hide indicator when cart is empty', 'cartpops' ) ),
			'cartpops_menu_cart_launcher_subtotal'         => array( 'Settings → Cart Launcher', __( 'Show subtotal', 'cartpops' ) ),
			'cartpops_plugin_enable'                       => array( 'Settings → General', __( 'Enable CartPops', 'cartpops' ) ),
			'cartpops_product_recommendation_engine_button_text' => array( 'Settings → Product Recommendation Engine', __( 'Button text', 'cartpops' ) ),
			'cartpops_product_recommendation_engine_button_type' => array( 'Settings → Product Recommendation Engine', __( 'Button type', 'cartpops' ) ),
			'cartpops_product_recommendation_engine_custom_global' => array( 'Settings → Product Recommendation Engine', __( 'Select custom products', 'cartpops' ) ),
			'cartpops_product_recommendation_engine_enable' => array( 'Settings → Product Recommendation Engine', __( 'Enable Product Recommendation Engine', 'cartpops' ) ),
			'cartpops_product_recommendation_engine_fallback' => array( 'Settings → Product Recommendation Engine', __( 'Select a fallback', 'cartpops' ) ),
			'cartpops_product_recommendation_engine_text'  => array( 'Settings → Product Recommendation Engine', __( 'Recommendations title', 'cartpops' ) ),
			'cartpops_product_recommendation_engine_type'  => array( 'Settings → Product Recommendation Engine', __( 'Select a Recommendation type', 'cartpops' ) ),
			'cartpops_support_us_enable'                   => array( 'Settings → General', __( 'Display Powered by CartPops', 'cartpops' ) ),
			'cartpops_support_us_partner_code'             => array( 'Settings → General', __( 'Partner code', 'cartpops' ) ),
			'cartpops_drawer_header_title_text'            => array( 'Settings → Texts (older versions)', __( 'Drawer cart title', 'cartpops' ) ),
			'cartpops_generic_add_to_cart_message_text'    => array( 'Settings → Texts (older versions)', __( 'Generic added to cart message', 'cartpops' ) ),
			'cartpops_coupon_title_text'                   => array( 'Settings → Texts (older versions)', __( 'Coupon title', 'cartpops' ) ),
			'cartpops_coupon_input_placeholder_text'       => array( 'Settings → Texts (older versions)', __( 'Coupon input field placeholder text', 'cartpops' ) ),
			'cartpops_coupon_button_text'                  => array( 'Settings → Texts (older versions)', __( 'Coupon button text', 'cartpops' ) ),
			'cartpops_subtotal_line_item_text'             => array( 'Settings → Texts (older versions)', __( 'Line item: Subtotal', 'cartpops' ) ),
			'cartpops_discount_line_item_text'             => array( 'Settings → Texts (older versions)', __( 'Line item: Discount', 'cartpops' ) ),
			'cartpops_total_line_item_text'                => array( 'Settings → Texts (older versions)', __( 'Line item: Total', 'cartpops' ) ),
			'cartpops_checkout_button_empty_text'          => array( 'Settings → Texts (older versions)', __( 'Empty checkout button', 'cartpops' ) ),
			'cartpops_drawer_empty_title_text'             => array( 'Settings → Texts (older versions)', __( 'Drawer empty state title', 'cartpops' ) ),
			'cartpops_drawer_empty_subtitle_text'          => array( 'Settings → Texts (older versions)', __( 'Drawer empty state subtitle', 'cartpops' ) ),
			'cartpops_support_chat_enable'                 => array( 'Settings → General', __( 'Enable chat support', 'cartpops' ) ),
			'cartpops_color_close_color'                   => array( 'Colors → Primary (older versions)', __( 'Close icon color', 'cartpops' ) ),
			'cartpops_color_remove_color'                  => array( 'Colors → Primary (older versions)', __( 'Remove icon color', 'cartpops' ) ),
			'cartpops_color_slider_pagination_bullet'      => array( 'Colors → Primary (older versions)', __( 'Slider bullet color', 'cartpops' ) ),
			'cartpops_color_slider_pagination_bullet_active' => array( 'Colors → Primary (older versions)', __( 'Slider active bullet color', 'cartpops' ) ),
		);
	}
}
