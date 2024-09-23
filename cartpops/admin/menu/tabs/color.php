<?php

/**
 * Master Log Tab
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( class_exists( 'CartPops_Color_Tab' ) ) {
	return new CartPops_Color_Tab();
}

/**
 * CartPops_Color_Tab.
 */
class CartPops_Color_Tab extends CartPops_Settings_Page {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id    = 'color';
		$this->label = esc_html__( 'Color schemes', 'cartpops' );
		$this->icon  = CartPops_Settings::get_admin_asset( 'menu-item-appearance.svg' );

		parent::__construct();
	}

	/**
	 * Get sections.
	 */
	public function get_sections() {
		$sections = array(
			'primary_colors'                => esc_html__( 'Primary', 'cartpops' ),
			'button_colors'                 => esc_html__( 'Buttons', 'cartpops' ),
			'floating_cart_launcher_colors' => esc_html__( 'Floating Cart Launcher', 'cartpops' ),
			'recommendations_colors'        => esc_html__( 'Recommendations', 'cartpops' ),
			'cart_launcher_colors'          => esc_html__( 'Cart launcher', 'cartpops' ),
			'free_shipping_meter_colors'    => esc_html__( 'Free Shipping Meter', 'cartpops' ),
		);

		return apply_filters( $this->plugin_slug . '_get_sections_' . $this->id, $sections );
	}

	/**
	 * Color scheme section array.
	 */
	public function primary_colors_section_array() {

		$section_fields = array();

		$section_fields[] = array(
			'type'  => 'title',
			'title' => esc_html__( 'Primary color scheme', 'cartpops' ),
			'icon'  => 'sliders',
			'id'    => 'cartpops_primary_colors_options',
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Primary background color', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'background_primary' ),
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Secondary background color', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'background_secondary' ),
		);
		$section_fields[] = array(
			'title'             => esc_html__( 'Primary text color', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'typography_primary' ),
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Secondary text color', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'typography_secondary' ),
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Tertiary text color', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'typography_tertiary' ),
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Border color', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'border' ),
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Accent color', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'border' ),
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Overlay color', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'overlay' ),
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Input field background color', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'input_background' ),
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Input field text color', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'input_text' ),
		);

		$section_fields[] = array(
			'type' => 'sectionend',
			'id'   => 'cartpops_primary_colors_options',
		);

		return $section_fields;
	}

	/**
	 * Color scheme section array.
	 */
	public function button_colors_section_array() {

		$section_fields = array();

		$section_fields[] = array(
			'type'  => 'title',
			'title' => esc_html__( 'Button color scheme', 'cartpops' ),
			'icon'  => 'sliders',
			'id'    => 'cartpops_button_colors_options',
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Primary button background color', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'button_primary_background' ),
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Primary button background color', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'button_primary_text' ),
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Secondary button background', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'button_secondary_background' ),
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Secondary button text color', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'button_secondary_text' ),
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Quantity selector button background', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'button_quantity_background' ),
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Quantity selector button text color', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'button_quantity_text' ),
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Quantity selector input background', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'input_quantity_background' ),
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Quantity selector input border', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'input_quantity_border' ),
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Quantity selector input text color', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'input_quantity_text' ),
		);

		$section_fields[] = array(
			'type' => 'sectionend',
			'id'   => 'cartpops_button_colors_options',
		);

		return $section_fields;
	}

	/**
	 * Color scheme section array.
	 */
	public function floating_cart_launcher_colors_section_array() {

		$section_fields = array();

		$section_fields[] = array(
			'type'  => 'title',
			'title' => esc_html__( 'Floating Cart launcher color scheme', 'cartpops' ),
			'icon'  => 'sliders',
			'id'    => 'cartpops_floating_cart_launcher_colors_options',
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Floating Cart Launcher background color', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'floating_cart_laucher_background' ),
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Floating Cart Launcher icon color', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'floating_cart_laucher_icon' ),
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Floating Cart Launcher indicator background', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'floating_cart_laucher_indicator_background' ),
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Floating Cart Launcher indicator text', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'floating_cart_laucher_indicator_text' ),
		);

		$section_fields[] = array(
			'type' => 'sectionend',
			'id'   => 'cartpops_floating_cart_launcher_colors_options',
		);

		return $section_fields;
	}

	/**
	 * Color scheme section array.
	 */
	public function recommendations_colors_section_array() {

		$section_fields = array();

		$section_fields[] = array(
			'type'  => 'title',
			'title' => esc_html__( 'Recommendations color scheme', 'cartpops' ),
			'icon'  => 'sliders',
			'id'    => 'cartpops_recommendations_colors_options',
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Recommendations button background color', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'recommendations_button_background' ),
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Recommendations button text color', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'recommendations_button_text' ),
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Drawer Recommendations background color', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'recommendations_drawer_background' ),
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Drawer Recommendations border color', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'recommendations_drawer_border' ),
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Drawer Recommendations text color', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'recommendations_drawer_text' ),
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Popup Recommendations background color', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'recommendations_popup_background' ),
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Popup Recommendations text color', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'recommendations_popup_text' ),
		);

		$section_fields[] = array(
			'type' => 'sectionend',
			'id'   => 'cartpops_recommendations_colors_options',
		);

		return $section_fields;
	}

	/**
	 * Color scheme section array.
	 */
	public function cart_launcher_colors_section_array() {

		$section_fields = array();

		$section_fields[] = array(
			'type'  => 'title',
			'title' => esc_html__( 'Cart launcher color scheme', 'cartpops' ),
			'icon'  => 'sliders',
			'id'    => 'cartpops_cart_launcher_colors_options',
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Cart Launcher background color', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'cart_laucher_background' ),
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Cart Launcher text color', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'cart_laucher_text' ),
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Cart Launcher bubble background', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'cart_laucher_bubble_background' ),
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Cart Launcher bubble text', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'cart_laucher_bubble_text' ),
		);

		$section_fields[] = array(
			'type' => 'sectionend',
			'id'   => 'cartpops_cart_launcher_colors_options',
		);

		return $section_fields;
	}

	/**
	 * Color scheme section array.
	 */
	public function free_shipping_meter_colors_section_array() {

		$section_fields = array();

		$section_fields[] = array(
			'type'  => 'title',
			'title' => esc_html__( 'Free Shipping Meter color scheme', 'cartpops' ),
			'icon'  => 'sliders',
			'id'    => 'cartpops_free_shipping_meter_colors_options',
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Free Shipping Meter background color', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'free_shipping_meter_background' ),
		);

		$section_fields[] = array(
			'title'             => esc_html__( 'Free Shipping Meter active background color', 'cartpops' ),
			'type'              => 'color',
			'custom_attributes' => '',
			'desc_tip'          => false,
			'id'                => $this->get_option_key( 'free_shipping_meter_background_active' ),
		);

		$section_fields[] = array(
			'type' => 'sectionend',
			'id'   => 'cartpops_free_shipping_meter_colors_options',
		);

		return $section_fields;
	}

	/**
	 * Output sidebar.
	 */
	public function output_sidebar() {
		include_once CARTPOPS_PATH . 'admin/menu/views/html-card-color-presets.php';
		CartPops_Settings::get_upgrade_card();
	}

}

return new CartPops_Color_Tab();
