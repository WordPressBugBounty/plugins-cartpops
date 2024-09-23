<?php

/**
 * Master Log Tab
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
    // Exit if accessed directly.
}
if ( class_exists( 'CartPops_Issues_Tab' ) ) {
    return new CartPops_Issues_Tab();
}
/**
 * CartPops_Issues_Tab.
 */
class CartPops_Issues_Tab extends CartPops_Settings_Page {
    // private $issue_list = array();
    /**
     * Constructor.
     */
    public function __construct() {
        $this->id = 'issues';
        $this->show_buttons = false;
        $this->label = esc_html__( 'Issues', 'cartpops' );
        $this->icon = CartPops_Settings::get_admin_asset( 'menu-item-power_ups.svg' );
        $this->watch_potential_issues();
        if ( $this->issue_list ) {
            $this->hide_page = false;
            add_action( 'admin_notices', array($this, 'show_error') );
        } else {
            $this->hide_page = true;
        }
        parent::__construct();
    }

    /**
     * Show error in the admin panel.
     *
     * @author CartPops <help@cartpops.com>
     */
    public function show_error() {
        global $current_tab;
        if ( 'issues' !== $current_tab ) {
            ?>
			<div class="notice notice-error is-dismissible">
				<p>
					<?php 
            echo sprintf( esc_html__( 'There are errors that prevent CartPops from working properly. %s.', 'cartpops' ), sprintf( '<a href="%s" rel="noopener nofollower">%s</a>', admin_url( 'admin.php?page=cartpops_settings&tab=issues' ), __( 'Review errors', 'cartpops' ) ) );
            ?>
				</p>
			</div>
			<?php 
        }
    }

    /**
     * Returns potential issues that may hinder the plugin from working as expected.
     *
     * @author CartPops <help@cartpops.com>
     */
    public function watch_potential_issues() {
        $issues = array();
        if ( 'yes' === get_option( 'woocommerce_cart_redirect_after_add' ) ) {
            $issues['wc_cart_redirect']['category'] = array('general');
            $issues['wc_cart_redirect']['error'] = __( 'An option in WooCommerce is turned on which will immediately redirect customers to the /cart/ page preventing CartPops to open.', 'cartpops' );
            /* translators: 1. Link */
            $issues['wc_cart_redirect']['fix'] = sprintf( __( 'Turn off "Redirect to the cart page after successful addition" in the WooCommerce plugin settings. %s.', 'cartpops' ), sprintf( '<a href="%s" rel="noopener nofollower" target="_blank">%s</a>', admin_url( 'admin.php?page=wc-settings&tab=products' ), __( 'Take me there', 'cartpops' ) ) );
        }
        if ( 'yes' !== get_option( 'woocommerce_enable_ajax_add_to_cart' ) ) {
            $issues['wc_ajax_add_to_cart_archive']['category'] = array('general');
            $issues['wc_ajax_add_to_cart_archive']['error'] = __( 'An option in WooCommerce is turned OFF which will prevent CartPops from working properly.', 'cartpops' );
            /* translators: 1. Link */
            $issues['wc_ajax_add_to_cart_archive']['fix'] = sprintf( __( 'Turn ON "Enable AJAX add to cart buttons on archives" in the WooCommerce plugin settings. %s.', 'cartpops' ), sprintf( '<a href="%s" rel="noopener nofollower" target="_blank">%s</a>', admin_url( 'admin.php?page=wc-settings&tab=products' ), __( 'Take me there', 'cartpops' ) ) );
        }
        if ( $this->using_deprecated_translation_settings() ) {
            $issues['deprecated_translation_settings']['category'] = array('general');
            /* translators: 1. Link */
            $issues['deprecated_translation_settings']['error'] = sprintf( __( 'The quick translation fields in the advanced plugin settings are deprecated and will be removed on July 11, 2021. %s.', 'cartpops' ), sprintf( '<a href="%s" rel="noopener nofollower" target="_blank">' . __( 'Learn more here', 'cartpops' ) . '</a>', cartpops_outbound_url( 'docs/troubleshooting/deprecating-plugin-translate-settings/', 'Translation deprecation', 'Documentation' ) ) );
            $issues['deprecated_translation_settings']['fix'] = __( 'Please make sure to stop using these features before July 11, 2021.', 'cartpops' );
        }
        if ( !$issues ) {
            return;
        }
        $this->has_issues = true;
        $this->issue_list = $issues;
        return apply_filters( 'cartpops_plugin_issues_list', $this->issue_list );
    }

    /**
     * In-plugin translation is deprecated. Check if user is using any of these options.
     *
     * @see https://cartpops.com/docs/troubleshooting/deprecating-plugin-translate-settings/
     * @since 1.3.0
     */
    private function using_deprecated_translation_settings() {
        $options = array(
            'drawer_header_title_text',
            'generic_add_to_cart_message_text',
            'coupon_title_text',
            'coupon_input_placeholder_text',
            'coupon_button_text',
            'subtotal_line_item_text',
            'discount_line_item_text',
            'total_line_item_text',
            'checkout_button_text',
            'checkout_button_empty_text',
            'drawer_empty_title_text',
            'drawer_empty_subtitle_text',
            'continue_shopping_text',
            'view_details_text'
        );
        foreach ( $options as $option => $value ) {
            if ( !empty( get_option( $value ) ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Output the Rules WP List Table
     */
    public function output_extra_fields() {
        include_once CARTPOPS_PATH . '/admin/menu/views/html-issues.php';
    }

}

return new CartPops_Issues_Tab();