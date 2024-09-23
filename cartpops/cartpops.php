<?php

/**
 * Plugin Name: CartPops
 * Description: Beautiful, responsive, and conversion optimized add to cart popup for WooCommerce.
 * Plugin URI: https://cartpops.com
 * Author: CartPops.com
 * Version: 1.5.33
 * Author URI: https://cartpops.com/?utm_source=wp-plugins&utm_campaign=author-uri&utm_medium=wp-dash
 * Text Domain: cartpops
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 6.7.0
 *
 * CartPops is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * CartPops is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 */
// If this file is called directly, abort.
if ( !defined( 'WPINC' ) ) {
    die;
}
if ( !defined( 'CARTPOPS_VERSION' ) ) {
    define( 'CARTPOPS_VERSION', '1.5.33' );
}
if ( !defined( 'CARTPOPS_PREFIX' ) ) {
    define( 'CARTPOPS_PREFIX', 'cartpops' );
}
if ( !defined( 'CARTPOPS_URL' ) ) {
    define( 'CARTPOPS_URL', plugins_url( '/', __FILE__ ) );
}
if ( !defined( 'CARTPOPS_PATH' ) ) {
    define( 'CARTPOPS_PATH', plugin_dir_path( __FILE__ ) );
}
if ( !defined( 'CARTPOPS_DOCS' ) ) {
    define( 'CARTPOPS_DOCS', 'https://cartpops.com/docs/' );
}
if ( !defined( 'CARTPOPS_FILE' ) ) {
    define( 'CARTPOPS_FILE', plugin_basename( __FILE__ ) );
}
if ( !defined( 'CARTPOPS_ABSPATH' ) ) {
    define( 'CARTPOPS_ABSPATH', dirname( CARTPOPS_FILE ) . '/' );
}
if ( !defined( 'CARTPOPS_TEMPLATE_DEBUG_MODE' ) ) {
    define( 'CARTPOPS_TEMPLATE_DEBUG_MODE', false );
}
require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
/**
 * Cartpops does not use any `custom_order_tables` features, but we need to declare compatibility.
 */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );
if ( function_exists( 'fs_cartpops' ) ) {
    fs_cartpops()->set_basename( false, __FILE__ );
} else {
    /**
     * Will stop plugin from loading if PHP version is below #.
     *
     * @author CartPops <help@cartpops.com>
     */
    function cartpops_cant_activate_msg() {
        ?>
		<div class="notice notice-error is-dismissible checkout-wc">
			<?php 
        echo esc_html__( 'Oh no! WooCommerce is required to use CartPops.', 'cartpops' );
        ?>
			<p>
			<?php 
        echo esc_html__( 'To prevent fatal errors, we have loaded CartPops into safe mode.', 'cartpops' );
        ?>
				</p>
				<p class="button-container">
					<?php 
        printf(
            '<a class="button button-primary" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s <span class="screen-reader-text">%3$s</span><span aria-hidden="true" class="dashicons dashicons-external"></span></a>',
            esc_url( CARTPOPS_DOCS ),
            esc_html__( 'View troubleshooting docs', 'cartpops' ),
            /* translators: Accessibility text. */
            esc_html__( '(opens in a new tab)', 'cartpops' )
        );
        ?>
			</p>
		</div>
		<?php 
    }

    /**
     * Check if CartPops can activate. If not, will stop plugin from loading to prevent fatal errors.
     *
     * @return bool
     */
    function cartpops_can_activate() {
        $can_activate = true;
        if ( !function_exists( 'is_plugin_active_for_network' ) ) {
            require_once ABSPATH . '/wp-admin/includes/plugin.php';
        }
        if ( is_multisite() ) {
            // This plugin is network activated (WooCommerce must be network activated).
            if ( is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
                $can_activate = ( is_plugin_active_for_network( 'woocommerce/woocommerce.php' ) ? true : false );
                // Locally activated (WooCommerce can be network or locally activated).
            } else {
                $can_activate = ( is_plugin_active( 'woocommerce/woocommerce.php' ) ? true : false );
            }
        } else {
            $can_activate = ( is_plugin_active( 'woocommerce/woocommerce.php' ) ? true : false );
        }
        if ( false === $can_activate ) {
            return false;
        } else {
            return true;
        }
    }

    if ( cartpops_can_activate() ) {
        if ( !function_exists( 'fs_cartpops' ) ) {
            /**
             * Freemius SDK access function
             */
            function fs_cartpops() {
                global $fs_cartpops;
                if ( !isset( $fs_cartpops ) ) {
                    // Include Freemius SDK.
                    $fs_cartpops = fs_dynamic_init( array(
                        'id'              => '7061',
                        'slug'            => 'cartpops',
                        'premium_slug'    => 'cartpops-pro',
                        'type'            => 'plugin',
                        'public_key'      => 'pk_f71eea687152e554f27b743874cd0',
                        'is_premium'      => false,
                        'premium_suffix'  => 'Pro',
                        'has_addons'      => false,
                        'has_paid_plans'  => true,
                        'trial'           => array(
                            'days'               => 14,
                            'is_require_payment' => true,
                        ),
                        'has_affiliation' => 'selected',
                        'menu'            => array(
                            'slug'    => 'cartpops_settings',
                            'contact' => false,
                            'support' => false,
                        ),
                        'is_live'         => true,
                    ) );
                }
                return $fs_cartpops;
            }

            // Init Freemius.
            fs_cartpops();
            // Signal that SDK was initiated.
            do_action( 'fs_cartpops_loaded' );
        }
        /**
         * The code that runs during plugin activation.
         * This action is documented in includes/class-cartpops-activator.php
         */
        function activate_cartpops() {
            require_once plugin_dir_path( __FILE__ ) . 'includes/class-cartpops-activator.php';
            CartPops_Activator::activate();
        }

        /**
         * The code that runs during plugin deactivation.
         * This action is documented in includes/class-cartpops-deactivator.php
         */
        function deactivate_cartpops() {
            require_once plugin_dir_path( __FILE__ ) . 'includes/class-cartpops-deactivator.php';
            CartPops_Deactivator::deactivate();
        }

        register_activation_hook( __FILE__, 'activate_cartpops' );
        register_deactivation_hook( __FILE__, 'deactivate_cartpops' );
        /**
         * The core plugin class that is used to define internationalization,
         * admin-specific hooks, and public-facing site hooks.
         */
        require plugin_dir_path( __FILE__ ) . 'includes/class-cartpops.php';
        /**
         * Begins execution of the plugin.
         *
         * Since everything within the plugin is registered via hooks,
         * then kicking off the plugin from this point in the file does
         * not affect the page life cycle.
         *
         * @since    1.0.0
         */
        function run_cartpops() {
            $plugin = new CartPops();
            $plugin->run();
        }

        run_cartpops();
    } else {
        add_action( 'admin_notices', 'cartpops_cant_activate_msg' );
    }
    // cartpops_can_activate()
}