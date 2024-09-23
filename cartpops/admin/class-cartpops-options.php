<?php

namespace CartPops\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Options class.
 *
 * This is used to set default options and get options from the database.
 *
 * @since      1.4.8
 * @package    CartPops
 * @subpackage CartPops/admin
 * @author     CartPops <help@cartpops.com>
 */
class Options {

	/**
	 * Prefix for each option
	 *
	 * @var string
	 */
	private static $option_prefix = 'cartpops_';

	/**
	 * Default settings
	 *
	 * @var array
	 */
	private static $default_settings;

	/**
	 * Gets the option for the given name. Returns the default value if the value does not exist.
	 *
	 * @param string $name
	 */
	public static function get( $name, $prefix = true ) {
		if ( $prefix ) {
			$name = self::$option_prefix . $name;
		}

		if ( ! self::has( $name ) ) {
			return self::get_default( $name );
		}

		return get_option( $name );
	}

	/**
	 * Add to the defualt option array_merge( $)
	 *
	 * @param string $name
	 * @param string $value
	 * @author CartPops <help@cartpops.com>
	 */
	public static function set_default( $name, $value, $prefix = false ) {
		if ( $prefix ) {
			$name = self::$option_prefix . $name;
		}

		self::$default_settings[ $name ] = $value;
	}

	/**
	 * Get default option from memory.
	 *
	 * @param string $name
	 * @author CartPops <help@cartpops.com>
	 */
	public static function get_default( $name ) {
		return self::default_settings()[ $name ] ?? null;
	}

	public static function default_settings() {
		if ( null === self::$default_settings ) {
			self::$default_settings = array(
				'cartpops_plugin_enable'                   => 'on',
				'cartpops_support_chat_enable'             => 'on',
				'cartpops_add_to_cart_trigger'             => 'drawer',
				'cartpops_coupon_form_enable'              => 'on',
				'cartpops_floating_cart_launcher_enable'   => 'on',
				'cartpops_floating_cart_launcher_position' => 'bottom_right',
				'cartpops_floating_cart_launcher_hide_empty' => '',
				'cartpops_floating_cart_launcher_hide_indicator_empty' => '',
				'cartpops_menu_cart_launcher_icon'         => 'cpops-icon-shopping-bag-line',
				'cartpops_menu_cart_launcher_indicator'    => 'bubble',
				'cartpops_product_recommendation_engine_enable' => '',
				'cartpops_product_recommendation_engine_type' => 'upsells',
				'cartpops_product_recommendation_engine_text' => 'You might also like these items!',
				'cartpops_product_recommendation_engine_fallback' => 'random_products',
				'cartpops_product_recommendation_engine_button_type' => 'icon',
				'cartpops_product_recommendation_engine_button_text' => __( 'Add', 'cartpops' ),
				'cartpops_free_shipping_meter_enable'      => '',
				/* translators: %1$s and %2$s are replaced with specific placeholders that will be dynamically populated */
				'cartpops_free_shipping_meter_text_base'   => sprintf( __( '%1$s Only %2$s away from free shipping to %3$s %4$s', 'cartpops' ), '🎁 ', '{{amount}}', '{{country}}', '{{flag}}' ),
				/* translators: %1$s and %2$s are replaced with specific placeholders that will be dynamically populated */
				'cartpops_free_shipping_meter_text_achieved' => sprintf( __( 'Congrats %1$s! You have earned free shipping to %2$s %3$s', 'cartpops' ), '🎉', '{{country}}', '{{flag}}' ),
				'cartpops_free_shipping_meter_type'        => 'woocommerce_settings',
				'cartpops_drawer_footer_display_subtotal'  => 'on',
				'cartpops_drawer_footer_display_total'     => 'on',
				'cartpops_drawer_footer_display_discount'  => 'on',
				'cartpops_drawer_footer_display_tax'       => 'on',
				'cartpops_drawer_footer_display_shipping'  => 'on',
				'cartpops_drawer_footer_display_shipping_calculator' => '',
				'cartpops_drawer_footer_secondary_button'  => 'continue_shopping',
				'cartpops_customize_white_space_text'      => 'break',
				'cartpops_animation_type'                  => 'simple',
				'cartpops_customize_animation_duration'    => '300',
				'cartpops_customize_width_drawer_desktop'  => '500',
				'cartpops_customize_width_drawer_mobile'   => '80',
				'cartpops_support_us_enable'               => 'on',
				'cartpops_support_us_partner_code'         => '',
				'cartpops_force_fragments_refresh'         => '',
				'cartpops_border_radius_rounded'           => 'on',
				'cartpops_color_background_primary'        => '#ffffff',
				'cartpops_color_background_secondary'      => '#f7f3fb',
				'cartpops_color_typography_primary'        => '#26180a',
				'cartpops_color_typography_secondary'      => '#464646',
				'cartpops_color_typography_tertiary'       => '#7a7a7a',
				'cartpops_color_border'                    => '#eaeaec',
				'cartpops_color_accent'                    => '#6f23e1',
				'cartpops_color_overlay'                   => 'rgba(0, 0, 0, 0.887)',
				'cartpops_color_input_background'          => '#ffffff',
				'cartpops_color_input_text'                => '#26180a',
				'cartpops_color_button_primary_background' => '#6f23e1',
				'cartpops_color_button_primary_text'       => '#ffffff',
				'cartpops_color_button_secondary_background' => '#f7f3fb',
				'cartpops_color_button_secondary_text'     => '#26180a',
				'cartpops_color_button_quantity_background' => '#f7f3fb',
				'cartpops_color_button_quantity_text'     => '#26180a',
				'cartpops_color_input_quantity_background' => '#ffffff',
				'cartpops_color_input_quantity_border' => '#f7f3fb',
				'cartpops_color_input_quantity_text'     => '#26180a',
				'cartpops_color_recommendations_button_background' => '#e7e8ea',
				'cartpops_color_recommendations_button_text' => '#000000',
				'cartpops_color_recommendations_drawer_background' => '#6f23e1',
				'cartpops_color_recommendations_drawer_border' => '#6f23e1',
				'cartpops_color_recommendations_drawer_text' => '#6f23e1',
				'cartpops_color_slider_pagination_bullet_active' => '#705aef',
				'cartpops_color_slider_pagination_bullet'  => '#705aef',
				'cartpops_color_recommendations_popup_background' => '#f7f3fb',
				'cartpops_color_recommendations_popup_text' => '#26180a',
				'cartpops_color_floating_cart_laucher_background' => '#000000',
				'cartpops_color_floating_cart_laucher_icon' => '#ffffff',
				'cartpops_color_floating_cart_laucher_indicator_text' => '#ffffff',
				'cartpops_color_floating_cart_laucher_indicator_background' => '#6f23e1',
				'cartpops_color_cart_laucher_background'   => 'rgba(255, 255, 255, 0)',
				'cartpops_color_cart_laucher_text'         => '#000000',
				'cartpops_color_cart_laucher_bubble_background' => '#705aef',
				'cartpops_color_cart_laucher_bubble_text'  => '#ffffff',
				'cartpops_color_close_color'               => '#3b3b3b',
				'cartpops_color_remove_color'              => '#3b3b3b',
				'cartpops_color_state_success'             => '#24a317',
				'cartpops_color_state_warning'             => '#ffdd57',
				'cartpops_color_state_danger'              => '#f14668',
				'cartpops_color_free_shipping_meter_background' => '#f7f3fb',
				'cartpops_color_free_shipping_meter_background_active' => '#25a418',
			);
		}

		return apply_filters( 'cartpops_default_settings', self::$default_settings );
	}

	/**
	 * Checks if the option exists or not.
	 *
	 * @param string $name
	 *
	 * @return bool
	 */
	public static function has( $name, $prefix = false ) {
		if ( $prefix ) {
			$name = self::$option_prefix . $name;
		}

		return ! empty( get_option( $name ) );
	}

}
