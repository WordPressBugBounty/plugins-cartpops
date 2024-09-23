<?php

use CartPops\Admin\Options;

/**
 * The class that loads in all dynamic styling.
 *
 * @package    CartPops
 * @subpackage CartPops/assets
 * @author     CartPops <help@cartpops.com>
 */
class CartPops_Assets {

	private $plugin_name;
	private $version;
	private $trigger;

	/**
	 * Constructor
	 *
	 * @param string $plugin_name The plugin name.
	 * @param int    $version Current plugin version.
	 * @param object $plugin_settings instance of the plugin options.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;

		$this->trigger = Options::get( 'add_to_cart_trigger' );
	}


	/**
	 * Enqueue styles
	 *
	 * @return void
	 */
	public function enqueue_frontend_styles() {

		$suffix                = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		$custom_css            = Options::get( 'custom_css' );
		$recommendation_engine = Options::get( 'product_recommendation_engine_enable' );
		$shipping_calculator   = Options::get( 'drawer_footer_display_shipping_calculator' );

		wp_enqueue_style( 'cartpops-frontend', CARTPOPS_URL . 'public/dist/css/frontend.min.css', array(), $this->version, 'all' );

		if ( $shipping_calculator ) {
			wp_enqueue_style( $this->plugin_name . '-select2', CARTPOPS_URL . 'public/dist/vendor/select2-frontend.min.css', array( 'cartpops-frontend' ), $this->version, 'all' );
		}

		if ( $recommendation_engine ) {
			wp_enqueue_style( 'cartpops-product-recommendations', CARTPOPS_URL . 'public/dist/css/product-recommendations.min.css', array( 'cartpops-frontend' ), $this->version, 'all' );
		}

		if ( $custom_css ) {
			wp_add_inline_style( 'cartpops-frontend', wp_unslash( $custom_css ) );
		}

	}

	/**
	 * Enqueue scripts
	 *
	 * @return void
	 */
	public function enqueue_frontend_scripts() {

		$suffix                = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		$custom_js             = Options::get( 'custom_js' );
		$custom_js             = Options::get( 'custom_js' );
		$recommendation_engine = Options::get( 'product_recommendation_engine_enable' );
		$shipping_calculator   = Options::get( 'drawer_footer_display_shipping_calculator' );

		wp_enqueue_script( 'cartpops-frontend', CARTPOPS_URL . 'public/dist/js/frontend.min.js', array( 'jquery' ), $this->version, true );

		if ( $shipping_calculator ) {
			wp_enqueue_script( 'wc-country-select' );
			wp_enqueue_script( 'selectWoo' );
		}

		if ( $recommendation_engine ) {
			wp_enqueue_script( 'cartpops-product-recommendations', CARTPOPS_URL . 'public/dist/js/product-recommendations.min.js', array( 'cartpops-frontend' ), $this->version, true );
		}

		wp_localize_script(
			'cartpops-frontend',
			'CartPopsConfig',
			array(
				'script_debug'            => ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? true : false,
				'ajax_url'                => admin_url( 'admin-ajax.php' ),
				'pro'                     => wp_json_encode( fs_cartpops()->can_use_premium_code() ),
				'nonce'                   => wp_create_nonce( 'cartpops' ),
				'trigger'                 => apply_filters( 'cartpops_add_to_cart_trigger', $this->trigger ),
				'forceRefresh'            => ( 'on' === Options::get( 'force_fragments_refresh', true ) ) ? wp_json_encode( true ) : wp_json_encode( false ),
				'refreshDelay'            => 150,
				'ajaxAddToCart'           => true,
				'isCheckoutPage'          => wp_json_encode( is_checkout() ),
				'isCartPage'              => wp_json_encode( is_cart() ),
				'genericAddToCartMessage' => ( ! empty( Options::get( 'generic_add_to_cart_message_text', true ) ) ) ? Options::get( 'generic_add_to_cart_message_text', true ) : __( 'Product successfully added to your cart.', 'cartpops' ),
				'notificationDelay'       => apply_filters( 'cartpops_notification_remove_delay', 4200 ),
				'loadingStateDelay'       => apply_filters(
					'cartpops_loading_state_delay',
					array(
						'deleteProduct'  => 500,
						'updateProduct'  => 500,
						'addProduct'     => 250,
						'restoreProduct' => 1500,
						'addCoupon'      => 100,
						'deleteCoupon'   => 100,
					)
				),
				'recommendationEngine'    => ( ! empty( $recommendation_engine ) ) ? wp_json_encode( true ) : wp_json_encode( false ),
			)
		);

		if ( $custom_js ) {
			wp_add_inline_script( 'cartpops-frontend', wp_unslash( $custom_js ) );
		}
	}

	/**
	 * Enqueue dynamic CSS.
	 *
	 * @since    1.2.7
	 * @author CartPops <help@cartpops.com>
	 */
	public function enqueue_dynamic_styles() {

		$cart_launcher_position = Options::get( 'floating_cart_launcher_position' );
		$settings               = new Options();

		$dynamic_css  = ':root {';
		$dynamic_css .= "
		--color-cpops-text-primary: {$settings::get( 'color_typography_primary' )};
		--color-cpops-text-secondary: {$settings::get( 'color_typography_secondary' )};
		--color-cpops-text-tertiary: {$settings::get( 'color_typography_tertiary' )};
		--color-cpops-accent-color: {$settings::get( 'color_accent' )};
		--color-cpops-overlay-background: {$settings::get( 'color_overlay' )};
		--color-cpops-background-primary: {$settings::get( 'color_background_primary' )};
		--color-cpops-background-secondary: {$settings::get( 'color_background_secondary' )};
		--color-cpops-button-primary-background: {$settings::get( 'color_button_primary_background' )};
		--color-cpops-button-primary-text: {$settings::get( 'color_button_primary_text' )};
		--color-cpops-button-secondary-background: {$settings::get( 'color_button_secondary_background' )};
		--color-cpops-button-secondary-text: {$settings::get( 'color_button_secondary_text' )};
		--color-cpops-button-quantity-background: {$settings::get( 'color_button_quantity_background' )};
		--color-cpops-button-quantity-text: {$settings::get( 'color_button_quantity_text' )};
		--color-cpops-input-quantity-background: {$settings::get( 'color_input_quantity_background' )};
		--color-cpops-input-quantity-border: {$settings::get( 'color_input_quantity_border' )};
		--color-cpops-input-quantity-text: {$settings::get( 'color_input_quantity_text' )};
		--color-cpops-input-field-background: {$settings::get( 'color_input_background' )};
		--color-cpops-input-field-text: {$settings::get( 'color_input_text' )};
		--color-cpops-border-color: {$settings::get( 'color_border' )};
		--color-cpops-recommendations-plus-btn-text: {$settings::get( 'color_recommendations_button_text' )};
		--color-cpops-recommendations-plus-btn-background: {$settings::get( 'color_recommendations_button_background' )};
		--color-cpops-drawer-recommendations-background: {$settings::get( 'color_recommendations_drawer_background' )};
		--color-cpops-drawer-recommendations-border: {$settings::get( 'color_recommendations_drawer_border' )};
		--color-cpops-drawer-recommendations-text: {$settings::get( 'color_recommendations_drawer_text' )};
		--color-cpops-popup-recommendations-background: {$settings::get( 'color_recommendations_popup_background' )};
		--color-cpops-popup-recommendations-text: {$settings::get( 'color_recommendations_popup_text' )};
		--color-cpops-slider-pagination-bullet-active: {$settings::get( 'color_accent' )};
		--color-cpops-slider-pagination-bullet: {$settings::get( 'color_accent' )};
		--color-cpops-floating-cart-launcher-color: {$settings::get( 'color_floating_cart_laucher_icon' )};
		--color-cpops-floating-cart-launcher-background: {$settings::get( 'color_floating_cart_laucher_background' )};
		--color-cpops-floating-cart-launcher-indicator-text: {$settings::get( 'color_floating_cart_laucher_indicator_text' )};
		--color-cpops-floating-cart-launcher-indicator-background: {$settings::get( 'color_floating_cart_laucher_indicator_background' )};
		--color-cpops-cart-launcher-background: {$settings::get( 'color_cart_laucher_background' )};
		--color-cpops-cart-launcher-text: {$settings::get( 'color_cart_laucher_text' )};
		--color-cpops-cart-launcher-bubble-background: {$settings::get( 'color_cart_laucher_bubble_background' )};
		--color-cpops-cart-launcher-bubble-text: {$settings::get( 'color_cart_laucher_bubble_text' )};
		--color-cpops-close-color: {$settings::get( 'color_typography_secondary' )};
		--color-cpops-remove-color: {$settings::get( 'color_typography_secondary' )};
		--color-cpops-free-shipping-meter-background: {$settings::get( 'color_free_shipping_meter_background' )};
		--color-cpops-free-shipping-meter-background-active: {$settings::get( 'color_free_shipping_meter_background_active' )};
		--color-cpops-state-success: #24a317;
		--color-cpops-state-warning: #ffdd57;
		--color-cpops-state-danger: #f14668;
		--cpops-animation-duration: {$settings::get( 'customize_animation_duration' )}ms;
		--cpops-width-drawer-desktop: {$settings::get( 'customize_width_drawer_desktop' )}px;
		--cpops-width-drawer-mobile: {$settings::get( 'customize_width_drawer_mobile' )}%;
		--cpops-white-space-text: {$settings::get( 'customize_white_space_text' )};
		";

		if ( 'on' === $settings::get( 'border_radius_rounded' ) ) {
			$dynamic_css .= '
				--cpops-border-radius: 6px;
			';
		} else {
			$dynamic_css .= '
                --cpops-border-radius: 0;
			';
		}

		$dynamic_css .= '}';

		if ( $cart_launcher_position && 'bottom_left' === $cart_launcher_position ) {
			$dynamic_css .= '#cpops-floating-cart {
				right: auto;
				left: 20px;
			}';
		}

		wp_add_inline_style( 'cartpops-frontend', $dynamic_css );
	}

}
