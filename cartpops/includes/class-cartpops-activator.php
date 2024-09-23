<?php
/**
 * Fired during plugin activation
 *
 * @link       https://cartpops.com
 * @since      1.0.0
 *
 * @package    CartPops
 * @subpackage CartPops/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    CartPops
 * @subpackage CartPops/includes
 * @author     CartPops <help@cartpops.com>
 */
class CartPops_Activator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function activate() {
		self::default_options();
	}

	/**
	 * Initialize default options upon activation
	 *
	 * @return void
	 */
	public static function default_options() {

		// Options: Colors - Primary.
		// add_option( 'cartpops_color_background_primary', '#ffffff' );
		// add_option( 'cartpops_color_background_secondary', '#f7f7fe' );
		// add_option( 'cartpops_color_typography_primary', '#000000' );
		// add_option( 'cartpops_color_typography_secondary', '#3b3b3b' );
		// add_option( 'cartpops_color_typography_tertiary', '#777777' );
		// add_option( 'cartpops_color_border', '#c8c8c8' );
		// add_option( 'cartpops_color_accent', '#705aef' );
		// add_option( 'cartpops_color_overlay', 'rgba(33, 42, 47, 0.85)' );
		// add_option( 'cartpops_color_button_primary_background', '#705aef' );
		// add_option( 'cartpops_color_button_primary_text', '#ffffff' );
		// add_option( 'cartpops_color_button_secondary_background', '#dfdfdf' );
		// add_option( 'cartpops_color_button_secondary_text', '#252525' );
		// add_option( 'cartpops_color_input_background', '#ffffff' );
		// add_option( 'cartpops_color_input_text', '#82828b' );

		// // Options: Styles.
		// add_option( 'cartpops_border_radius_rounded', 'on' );
		// add_option( 'cartpops_animation_type', 'slideup' );

		// // Options: Colors - Recommendations.
		// add_option( 'cartpops_color_recommendations_button_background', '#000000' );
		// add_option( 'cartpops_color_recommendations_button_text', '#ffffff' );
		// add_option( 'cartpops_color_recommendations_drawer_background', '#f7f7fe' );
		// add_option( 'cartpops_color_recommendations_drawer_text', '#000000' );
		// add_option( 'cartpops_color_slider_pagination_bullet_active', '#705aef' );
		// add_option( 'cartpops_color_slider_pagination_bullet', '#705aef' );
		// add_option( 'cartpops_color_recommendations_popup_background', '#f7f7fe' );
		// add_option( 'cartpops_color_recommendations_popup_text', '#000000' );

		// // Options: Colors - Floating Cart Launcher.
		// add_option( 'cartpops_color_floating_cart_laucher_icon', '#ffffff' );
		// add_option( 'cartpops_color_floating_cart_laucher_background', '#000000' );
		// add_option( 'cartpops_color_floating_cart_laucher_indicator_text', '#ffffff' );
		// add_option( 'cartpops_color_floating_cart_laucher_indicator_background', '#705aef' );

		// // Options: Colors - Cart Launcher.
		// add_option( 'cartpops_color_cart_laucher_background', 'rgba(255, 255, 255, 0)' );
		// add_option( 'cartpops_color_cart_laucher_text', '#000000' );
		// add_option( 'cartpops_color_cart_laucher_bubble_background', '#705aef' );
		// add_option( 'cartpops_color_cart_laucher_bubble_text', '#ffffff' );

		// // Options: Colors - States.
		// add_option( 'cartpops_color_close_color', '#3b3b3b' );
		// add_option( 'cartpops_color_remove_color', '#3b3b3b' );
		// add_option( 'cartpops_color_state_success', '#24a317' );
		// add_option( 'cartpops_color_state_warning', '#ffdd57' );
		// add_option( 'cartpops_color_state_danger', '#f14668' );

		// if ( fs_cartpops()->is__premium_only() ) {
		// Options: Colors - FSM.
		// add_option( 'cartpops_color_free_shipping_meter_background', '#dbdbe0' );
		// add_option( 'cartpops_color_free_shipping_meter_background_active', '#705aef' );
		// }

		// // Settings: Main.
		// add_option( 'cartpops_plugin_enable', 'on' );
		// add_option( 'support_chat_enable', 'on' );

		// // Settings: Coupoon form.
		// add_option( 'cartpops_coupon_form_enable', 'on' );
		// add_option( 'cartpops_add_to_cart_trigger', 'drawer' );

		// // Settings: Floating Cart Launcher..
		// add_option( 'cartpops_floating_cart_launcher_enable', 'on' );
		// add_option( 'cartpops_floating_cart_launcher_position', 'bottom_right' );

		// // Settings: Recommendation.
		// add_option( 'cartpops_product_recommendation_engine_enable', 'on' );
		// add_option( 'cartpops_product_recommendation_engine_type', 'upsells' );
		// add_option( 'cartpops_product_recommendation_engine_text', 'You might also like these items!' );
		// add_option( 'cartpops_product_recommendation_engine_fallback', 'random_products' );
		// add_option( 'cartpops_product_recommendation_engine_button_type', 'icon' );

		// // Settings: Drawer footer.
		// add_option( 'cartpops_drawer_footer_display_subtotal', 'on' );
		// add_option( 'cartpops_drawer_footer_display_total', 'on' );
		// add_option( 'cartpops_drawer_footer_display_discount', 'on' );
		// add_option( 'cartpops_drawer_footer_display_tax', 'on' );
		// add_option( 'cartpops_drawer_footer_display_shipping', 'on' );
		// add_option( 'cartpops_drawer_footer_display_shipping_calculator', 'on' );
		// add_option( 'cartpops_drawer_footer_secondary_button', 'none' );

		// // Settings: Customization.
		// add_option( 'cartpops_customize_white_space_text', 'break' );
	}

}
