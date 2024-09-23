<?php

/**
 * Settings Tab
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
    // Exit if accessed directly.
}
if ( class_exists( 'CartPops_Settings_Tab' ) ) {
    return new CartPops_Settings_Tab();
}
/**
 * CartPops_Settings_Tab.
 */
class CartPops_Settings_Tab extends CartPops_Settings_Page {
    /**
     * Constructor.
     */
    public function __construct() {
        $this->id = 'settings';
        $this->label = esc_html__( 'Settings', 'cartpops' );
        $this->icon = CartPops_Settings::get_admin_asset( 'menu-item-settings.svg' );
        parent::__construct();
    }

    /**
     * Get sections.
     */
    public function get_sections() {
        $sections = array(
            'general'             => esc_html__( 'General', 'cartpops' ),
            'drawer'              => esc_html__( 'Drawer', 'cartpops' ),
            'cart_launcher'       => esc_html__( 'Cart Launcher', 'cartpops' ),
            'recommendations'     => esc_html__( 'Product Recommendation Engine', 'cartpops' ),
            'free_shipping_meter' => esc_html__( 'Free Shipping Meter settings', 'cartpops' ),
            'custom_code'         => esc_html__( 'Custom code', 'cartpops' ),
            'misc'                => esc_html__( 'Miscellaneous & Experimental', 'cartpops' ),
        );
        return apply_filters( $this->plugin_slug . '_get_sections_' . $this->id, $sections );
    }

    public function general_section_array() {
        $section_fields = array();
        $section_fields[] = array(
            'type'  => 'title',
            'title' => esc_html__( 'General Settings', 'cartpops' ),
            'icon'  => 'zap',
            'id'    => 'cartpops_general_options',
        );
        $section_fields[] = array(
            'id'       => $this->get_option_key( 'add_to_cart_trigger', false ),
            'title'    => __( 'Select an add to cart trigger', 'cartpops' ),
            'desc_tip' => false,
            'type'     => 'select',
            'type'     => 'radio',
            'options'  => array(
                'drawer' => array(
                    'title' => 'Drawer',
                    'pro'   => false,
                ),
                'popup'  => array(
                    'title' => 'Popup (BETA)',
                    'pro'   => true,
                ),
                'bar'    => array(
                    'title' => 'Bar (BETA)',
                    'pro'   => true,
                ),
            ),
        );
        $section_fields[] = array(
            'id'       => $this->get_option_key( 'support_us_enable', false ),
            'title'    => esc_html__( 'Display Powered by CartPops', 'cartpops' ),
            'type'     => 'checkbox',
            'desc'     => esc_html__( 'The core functionality of CartPops is free forever. You can support ongoing development and new updates by showing Powered by CartPops on your Add To Cart Triggers ', 'cartpops' ),
            'desc_tip' => false,
        );
        $section_fields[] = array(
            'id'          => $this->get_option_key( 'support_us_partner_code', false ),
            'title'       => esc_html__( 'Partner code', 'cartpops' ),
            'desc'        => __( 'Replace the default Powered by CartPops branding link with your referral link from Freemius.', 'cartpops' ),
            'type'        => 'text',
            'placeholder' => __( 'e.g. 93494', 'cartpops' ),
        );
        $section_fields[] = array(
            'type' => 'sectionend',
            'id'   => 'cartpops_general_options',
        );
        // General Section End
        return $section_fields;
    }

    public function drawer_section_array() {
        $section_fields = array();
        // Trobuleshoot Section Start.
        $section_fields[] = array(
            'type'  => 'title',
            'title' => esc_html__( 'Drawer General Settings', 'cartpops' ),
            'icon'  => 'sliders',
            'id'    => 'cartpops_drawer_general_settings_options',
        );
        $section_fields[] = array(
            'id'                => $this->get_option_key( 'customize_width_drawer_mobile', false ),
            'title'             => esc_html__( 'Drawer width mobile (In percentage)', 'cartpops' ),
            'type'              => 'number',
            'custom_attributes' => array(
                'min' => '50',
                'max' => '100',
            ),
            'placeholder'       => '80',
        );
        $section_fields[] = array(
            'id'                => $this->get_option_key( 'customize_width_drawer_desktop', false ),
            'title'             => esc_html__( 'Drawer width desktop (In pixels)', 'cartpops' ),
            'type'              => 'number',
            'custom_attributes' => array(
                'min' => '50',
                'max' => '1400',
            ),
            'placeholder'       => '500',
        );
        $section_fields[] = array(
            'id'      => $this->get_option_key( 'animation_type', false ),
            'title'   => esc_html__( 'Animation type', 'cartpops' ),
            'type'    => 'select',
            'options' => array(
                'none'   => __( 'None', 'cartpops' ),
                'fade'   => __( 'Fade', 'cartpops' ),
                'simple' => __( 'Slide in right', 'cartpops' ),
                'slick'  => __( 'Slick', 'cartpops' ),
            ),
        );
        $section_fields[] = array(
            'id'                => $this->get_option_key( 'customize_animation_duration', false ),
            'title'             => esc_html__( 'Animation speed', 'cartpops' ),
            'type'              => 'number',
            'custom_attributes' => array(
                'min' => '50',
                'max' => '800',
            ),
            'placeholder'       => '300',
        );
        $section_fields[] = array(
            'id'      => $this->get_option_key( 'customize_white_space_text', false ),
            'title'   => esc_html__( 'Truncate long text', 'cartpops' ),
            'type'    => 'select',
            'options' => array(
                'nowrap' => __( 'Yes', 'cartpops' ),
                'break'  => __( 'No', 'cartpops' ),
            ),
        );
        $section_fields[] = array(
            'type' => 'sectionend',
            'id'   => 'cartpops_drawer_settings_options',
        );
        // Trobuleshoot Section Start.
        $section_fields[] = array(
            'type'  => 'title',
            'title' => esc_html__( 'Drawer Coupon Settings', 'cartpops' ),
            'icon'  => 'percent',
            'id'    => 'cartpops_drawer_coupon_settings_options',
        );
        $section_fields[] = array(
            'id'            => $this->get_option_key( 'coupon_form_enable', false ),
            'title'         => esc_html__( 'Enable coupon form', 'cartpops' ),
            'help_doc_slug' => 'docs/features/coupon-form/',
            'type'          => 'checkbox',
        );
        $section_fields[] = array(
            'type' => 'sectionend',
            'id'   => 'cartpops_drawer_coupon_settings_options',
        );
        // Start: Drawer footer settings.
        $section_fields[] = array(
            'type'  => 'title',
            'title' => esc_html__( 'Drawer Footer Settings', 'cartpops' ),
            'icon'  => 'sliders',
            'id'    => 'cartpops_drawer_footer_options',
        );
        $section_fields[] = array(
            'id'    => $this->get_option_key( 'drawer_footer_display_subtotal', false ),
            'title' => esc_html__( 'Show subtotal line item', 'cartpops' ),
            'type'  => 'checkbox',
        );
        $section_fields[] = array(
            'id'    => $this->get_option_key( 'drawer_footer_display_total', false ),
            'title' => esc_html__( 'Show total line item', 'cartpops' ),
            'type'  => 'checkbox',
        );
        $section_fields[] = array(
            'id'    => $this->get_option_key( 'drawer_footer_display_discount', false ),
            'title' => esc_html__( 'Show discount line item', 'cartpops' ),
            'type'  => 'checkbox',
        );
        $section_fields[] = array(
            'id'    => $this->get_option_key( 'drawer_footer_display_tax', false ),
            'title' => esc_html__( 'Show tax line item', 'cartpops' ),
            'type'  => 'checkbox',
        );
        $section_fields[] = array(
            'id'    => $this->get_option_key( 'drawer_footer_display_shipping', false ),
            'title' => esc_html__( 'Show shipping line item', 'cartpops' ),
            'type'  => 'checkbox',
        );
        $section_fields[] = array(
            'id'       => $this->get_option_key( 'drawer_footer_display_shipping_calculator', false ),
            'title'    => esc_html__( 'Show shipping calculator', 'cartpops' ),
            'desc'     => __( 'This will show a button next to the shipping line item where the customer can calculate or change their shipping.', 'cartpops' ),
            'desc_tip' => true,
            'type'     => 'checkbox',
            'pro'      => true,
        );
        $section_fields[] = array(
            'id'      => $this->get_option_key( 'drawer_footer_secondary_button', false ),
            'title'   => esc_html__( 'Secondary button', 'cartpops' ),
            'type'    => 'select',
            'options' => array(
                'none'              => __( 'None', 'cartpops' ),
                'continue_shopping' => __( 'Continue shopping (Pro)', 'cartpops' ),
                'view_cart'         => __( 'View cart (Pro)', 'cartpops' ),
                'custom_url'        => __( 'Custom URL (Pro)', 'cartpops' ),
            ),
        );
        $section_fields[] = array(
            'type' => 'sectionend',
            'id'   => 'cartpops_drawer_footer_options',
        );
        // End: Drawer footer settings.
        return $section_fields;
    }

    public function cart_launcher_section_array() {
        $section_fields[] = array(
            'type'  => 'title',
            'title' => esc_html__( 'Floating Cart Launcher settings', 'cartpops' ),
            'icon'  => 'shopping-cart',
            'id'    => 'cartpops_floating_cart_launcher_options',
        );
        $section_fields[] = array(
            'id'    => $this->get_option_key( 'floating_cart_launcher_enable', false ),
            'title' => esc_html__( 'Enable Floating Cart Launcher', 'cartpops' ),
            'type'  => 'checkbox',
        );
        $section_fields[] = array(
            'id'      => $this->get_option_key( 'floating_cart_launcher_position', false ),
            'title'   => esc_html__( 'Select a position', 'cartpops' ),
            'type'    => 'select',
            'options' => array(
                'bottom_right' => __( 'Bottom right', 'cartpops' ),
                'bottom_left'  => __( 'Bottom left', 'cartpops' ),
            ),
        );
        $section_fields[] = array(
            'id'        => $this->get_option_key( 'floating_cart_launcher_hide_pages', false ),
            'title'     => esc_html__( 'Hide on certain pages', 'cartpops' ),
            'action'    => 'cartpops_json_search_pages',
            'nonce'     => 'search-pages',
            'list_type' => 'post',
            'type'      => 'ajaxmultiselect',
            'options'   => array(
                'bottom_right' => __( 'Bottom right', 'cartpops' ),
                'bottom_left'  => __( 'Bottom left', 'cartpops' ),
            ),
        );
        $section_fields[] = array(
            'id'    => $this->get_option_key( 'floating_cart_launcher_hide_empty', false ),
            'title' => esc_html__( 'Hide when cart is empty', 'cartpops' ),
            'type'  => 'checkbox',
            'pro'   => true,
        );
        $section_fields[] = array(
            'id'    => $this->get_option_key( 'floating_cart_launcher_hide_indicator_empty', false ),
            'title' => esc_html__( 'Hide indicator when cart is empty', 'cartpops' ),
            'type'  => 'checkbox',
            'pro'   => true,
        );
        $section_fields[] = array(
            'type' => 'sectionend',
            'id'   => 'cartpops_floating_cart_launcher_options',
        );
        // End: Floating cart launcher settings.
        $section_fields[] = array(
            'type'  => 'title',
            'title' => esc_html__( 'Menu Cart launcher settings (Experimental)', 'cartpops' ),
            'icon'  => 'shopping-cart',
            'id'    => 'cartpops_menu_cart_launcher_options',
        );
        $section_fields[] = array(
            'id'      => $this->get_option_key( 'menu_cart_launcher_icon', false ),
            'title'   => __( 'Menu Cart Launcher icon', 'cartpops' ),
            'desc'    => __( 'Choose an icon to display', 'cartpops' ),
            'type'    => 'select',
            'options' => array(
                'cpops-icon-shopping-cart-outline' => __( 'Cart', 'cartpops' ) . ' 1',
                'cpops-icon-shopping-cart-line'    => __( 'Cart', 'cartpops' ) . ' 2',
                'cpops-icon-shopping-cart-fill'    => __( 'Cart', 'cartpops' ) . ' 3',
                'cpops-icon-shopping-cart-2-line'  => __( 'Cart', 'cartpops' ) . ' 4',
                'cpops-icon-shopping-cart-2-fill'  => __( 'Cart', 'cartpops' ) . ' 5',
                'cpops-icon-shopping-bag-outline'  => __( 'Cart', 'cartpops' ) . ' 6',
                'cpops-icon-shopping-bag-line'     => __( 'Bag', 'cartpops' ) . ' 2',
                'cpops-icon-shopping-bag-fill'     => __( 'Bag', 'cartpops' ) . ' 3',
                'cpops-icon-shopping-bag-2-line'   => __( 'Bag', 'cartpops' ) . ' 4',
                'cpops-icon-shopping-bag-2-fill'   => __( 'Bag', 'cartpops' ) . ' 5',
                'cpops-icon-shopping-bag-3-fill'   => __( 'Bag', 'cartpops' ) . ' 6',
                'cpops-icon-handbag-line'          => __( 'Bag', 'cartpops' ) . ' 7',
                'cpops-icon-cpops-handbag-fill'    => __( 'Bag', 'cartpops' ) . ' 8',
            ),
        );
        $section_fields[] = array(
            'id'      => $this->get_option_key( 'menu_cart_launcher_indicator', false ),
            'title'   => __( 'Menu Cart Launcher Indicator', 'cartpops' ),
            'desc'    => __( 'Choose if you wnat to show an indicator with the item total.', 'cartpops' ),
            'type'    => 'select',
            'options' => array(
                'none'   => __( 'None', 'cartpops' ),
                'bubble' => __( 'Bubble', 'cartpops' ),
                'plain'  => __( 'Plain', 'cartpops' ),
            ),
        );
        $section_fields[] = array(
            'id'    => $this->get_option_key( 'menu_cart_launcher_indicator_empty', false ),
            'title' => __( 'Hide indicator when cart is empty', 'cartpops' ),
            'desc'  => __( 'This will hide the indicator on the cart icon if the cart is empty.', 'cartpops' ),
            'type'  => 'checkbox',
        );
        $section_fields[] = array(
            'id'    => $this->get_option_key( 'menu_cart_launcher_subtotal', false ),
            'title' => __( 'Show subtotal', 'cartpops' ),
            'desc'  => __( 'This will show the subtotal next to the cart icon.', 'cartpops' ),
            'type'  => 'checkbox',
        );
        $section_fields[] = array(
            'type' => 'sectionend',
            'id'   => 'cartpops_menu_cart_launcher_options',
        );
        return $section_fields;
    }

    public function recommendations_section_array() {
        $section_fields = array();
        // Trobuleshoot Section Start.
        $section_fields[] = array(
            'type'  => 'title',
            'title' => esc_html__( 'Product Recommendation Engine Settings', 'cartpops' ),
            'icon'  => 'shopping-bag',
            'id'    => 'cartpops_recommendations_settings_options',
        );
        $section_fields[] = array(
            'id'    => $this->get_option_key( 'product_recommendation_engine_enable', false ),
            'title' => __( 'Enable Product Recommendation Engine', 'cartpops' ),
            'desc'  => __( 'This will enable the Product Recommendation Engine.', 'cartpops' ),
            'type'  => 'checkbox',
        );
        $section_fields[] = array(
            'id'          => $this->get_option_key( 'product_recommendation_engine_text', false ),
            'title'       => __( 'Recommendations title', 'cartpops' ),
            'type'        => 'text',
            'placeholder' => __( 'Enter call to action text', 'cartpops' ),
            'attributes'  => array(),
        );
        $section_fields[] = array(
            'id'      => $this->get_option_key( 'product_recommendation_engine_type', false ),
            'title'   => __( 'Select a Recommendation type', 'cartpops' ),
            'desc'    => __( 'Select a group of products that you want to recommend (only simple products are supported). If a product is already in the cart it will be excluded from the recommendations.', 'cartpops' ),
            'type'    => 'select',
            'options' => array(
                'upsells'     => __( 'Upsells', 'cartpops' ),
                'cross_sells' => __( 'Cross-sells', 'cartpops' ),
                'custom'      => __( 'Custom products (Pro)', 'cartpops' ),
            ),
        );
        $section_fields[] = array(
            'id'      => $this->get_option_key( 'product_recommendation_engine_fallback', false ),
            'title'   => __( 'Select a fallback', 'cartpops' ),
            'desc'    => __( 'Select what you want to do in case there are no products to recommend.', 'cartpops' ),
            'type'    => 'select',
            'options' => array(
                'hide'            => __( 'Hide the Product Recommendation Engine', 'cartpops' ),
                'random_products' => __( 'Show random products', 'cartpops' ),
            ),
        );
        $section_fields[] = array(
            'id'      => $this->get_option_key( 'product_recommendation_engine_button_type', false ),
            'title'   => __( 'Button type', 'cartpops' ),
            'desc'    => __( 'Select what type of button you want to use.', 'cartpops' ),
            'type'    => 'select',
            'options' => array(
                'icon'      => __( 'Icon', 'cartpops' ),
                'text'      => __( 'Text (Pro)', 'cartpops' ),
                'text_icon' => __( 'Text + Icon (Pro)', 'cartpops' ),
            ),
        );
        $section_fields[] = array(
            'id'          => $this->get_option_key( 'product_recommendation_engine_button_text', false ),
            'title'       => __( 'Button text', 'cartpops' ),
            'type'        => 'text',
            'placeholder' => __( 'Add', 'cartpops' ),
        );
        $section_fields[] = array(
            'type' => 'sectionend',
            'id'   => 'cartpops_recommendations_settings_options',
        );
        return $section_fields;
    }

    public function free_shipping_meter_section_array() {
        $section_fields = array();
        // Trobuleshoot Section Start.
        $section_fields[] = array(
            'type'  => 'title',
            'title' => esc_html__( 'Free Shipping Meter Settings', 'cartpops' ),
            'icon'  => 'truck',
            'id'    => 'cartpops_free_shipping_meter_settings_options',
        );
        $section_fields[] = array(
            'id'    => $this->get_option_key( 'free_shipping_meter_enable', false ),
            'title' => esc_html__( 'Enable Free Shipping Meter', 'cartpops' ),
            'type'  => 'checkbox',
            'pro'   => true,
            'desc'  => __( 'This will enable the Free Shipping Meter on the CartPops Drawer and selected Add To Cart Trigger.', 'cartpops' ),
        );
        $section_fields[] = array(
            'type' => 'sectionend',
            'id'   => 'cartpops_free_shipping_meter_settings_options',
        );
        return $section_fields;
    }

    public function custom_code_section_array() {
        $section_fields = array();
        // message Section Start
        $section_fields[] = array(
            'type'  => 'title',
            'title' => esc_html__( 'Custom Code Settings', 'cartpops' ),
            'icon'  => 'code',
            'id'    => 'cartpops_custom_code_options',
        );
        $section_fields[] = array(
            'id'          => $this->get_option_key( 'custom_css', false ),
            'title'       => esc_html__( 'Custom CSS', 'cartpops' ),
            'type'        => 'textarea',
            'desc'        => sprintf( __( 'Add custom CSS, this will fire after any CartPops CSS. This is for more advanced users! You know what to do %1$s', 'cartpops' ), 'ʕ •ᴥ•ʔ' ),
            'placeholder' => esc_html( '#cpops-drawer { text: uppercase; }' ),
        );
        $section_fields[] = array(
            'id'          => $this->get_option_key( 'custom_js', false ),
            'title'       => esc_html__( 'Custom JS', 'cartpops' ),
            'desc'        => sprintf( __( 'Add custom JS, this will fire after any CartPops JS. This is for more advanced users! You know what to do %1$s', 'cartpops' ), 'ʕ •ᴥ•ʔ' ),
            'type'        => 'textarea',
            'placeholder' => esc_html( 'console.log("Howdey!");' ),
        );
        $section_fields[] = array(
            'type' => 'sectionend',
            'id'   => 'cartpops_custom_code_options',
        );
        // Upsell Product Cart Page Section End.
        return $section_fields;
    }

    public function misc_section_array() {
        $section_fields = array();
        $section_fields[] = array(
            'type'  => 'title',
            'title' => esc_html__( 'Miscellaneous & Experimental', 'cartpops' ),
            'icon'  => 'box',
            'id'    => 'cartpops_misc_options',
        );
        $section_fields[] = array(
            'id'       => $this->get_option_key( 'force_fragments_refresh', false ),
            'title'    => esc_html__( 'Force refresh on page load?', 'cartpops' ),
            'type'     => 'checkbox',
            'desc'     => esc_html__( 'When enabled this will force refresh the cart on each page load. Only use if you\'re having troubles with how the cart loads.', 'cartpops' ),
            'desc_tip' => true,
        );
        $section_fields[] = array(
            'type' => 'sectionend',
            'id'   => 'cartpops_misc_options',
        );
        return $section_fields;
    }

    public function output_sidebar() {
        CartPops_Settings::get_upgrade_card();
    }

}

return new CartPops_Settings_Tab();