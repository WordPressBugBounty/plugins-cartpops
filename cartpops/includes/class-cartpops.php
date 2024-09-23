<?php

use CartPops\Admin\Options;
/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link  https://cartpops.com
 * @since 1.0.0
 *
 * @package    CartPops
 * @subpackage CartPops/includes
 */
/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    CartPops
 * @subpackage CartPops/includes
 * @author     CartPops <help@cartpops.com>
 */
class CartPops {
    /**
     * The loader that's responsible for maintaining and registering all hooks that power
     * the plugin.
     *
     * @since  1.0.0
     * @access protected
     * @var    CartPops_Loader    $loader    Maintains and registers all hooks for the plugin.
     */
    protected $loader;

    /**
     * The unique identifier of this plugin.
     *
     * @since  1.0.0
     * @access protected
     * @var    string    $plugin_name    The string used to uniquely identify this plugin.
     */
    protected $plugin_name;

    /**
     * The current version of the plugin.
     *
     * @since  1.0.0
     * @access protected
     * @var    string    $version    The current version of the plugin.
     */
    protected $version;

    /**
     * Sanitizer for cleaning user input
     *
     * @since  1.0.0
     * @access private
     * @var    CartPops_Sanitize    $sanitizer    Sanitizes data
     */
    private $integrations;

    private $plugin_enabled;

    /**
     * Define the core functionality of the plugin.
     *
     * Set the plugin name and the plugin version that can be used throughout the plugin.
     * Load the dependencies, define the locale, and set the hooks for the admin area and
     * the public-facing side of the site.
     *
     * @since 1.0.0
     */
    public function __construct() {
        if ( defined( 'CARTPOPS_VERSION' ) ) {
            $this->version = CARTPOPS_VERSION;
        } else {
            $this->version = '1.0.0';
        }
        $this->plugin_name = 'cartpops';
        $this->load_dependencies();
        $this->set_locale();
        $this->api();
        if ( $this->can_activate_frontend() ) {
            $this->cart();
            $this->assets_frontend();
            $this->public();
            $this->ajax_frontend();
        }
    }

    /**
     * Load the required dependencies for this plugin.
     *
     * Include the following files that make up the plugin:
     *
     * - CartPops_Loader. Orchestrates the hooks of the plugin.
     * - CartPops_I18n. Defines internationalization functionality.
     * - CartPops_Admin. Defines all hooks for the admin area.
     * - CartPops_Public. Defines all hooks for the public side of the site.
     *
     * Create an instance of the loader which will be used to register the hooks
     * with WordPress.
     *
     * @since  1.0.0
     * @access private
     */
    private function load_dependencies() {
        include_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-cartpops-loader.php';
        include_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-cartpops-i18n.php';
        include_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/functions/cartpops-common-functions.php';
        include_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/functions/cartpops-layout-functions.php';
        include_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/functions/cartpops-post-functions.php';
        include_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/functions/cartpops-admin-functions.php';
        include_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/functions/cartpops-template-functions.php';
        include_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/abstracts/abstract-cartpops-post.php';
        include_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-cartpops-register-post-types.php';
        include_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-cartpops-register-post-status.php';
        include_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-cartpops-date-time.php';
        include_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/entity/class-cartpops-rule.php';
        include_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/entity/class-cartpops-master-log.php';
        // ADMIN.
        if ( is_admin() ) {
            include_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-cartpops-admin-assets.php';
            include_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-cartpops-admin-ajax.php';
            include_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/menu/class-cartpops-menu-management.php';
        }
        // FRONTEND.
        if ( !is_admin() ) {
            include_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-cartpops-cart.php';
            include_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-cartpops-assets.php';
            include_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-cartpops-public.php';
            include_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-cartpops-ajax.php';
        }
        $this->loader = new CartPops_Loader();
    }

    private function api() {
        $plugin_api = new CartPops_Api($this->get_plugin_name(), $this->get_version());
        $this->loader->add_action( 'plugins_loaded', $plugin_api, 'plugins_loaded' );
    }

    /**
     * Define the locale for this plugin for internationalization.
     *
     * Uses the CartPops_I18n class in order to set the domain and to register the hook
     * with WordPress.
     *
     * @since  1.0.0
     * @access private
     */
    private function set_locale() {
        $plugin_i18n = new CartPops_I18n();
        $this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );
    }

    /**
     * Construct all the cart templates.
     *
     * @since  1.0.0
     * @access private
     */
    private function cart() {
        $plugin_cart = new CartPops_Cart($this->get_plugin_name(), $this->get_version());
        $this->loader->add_action(
            'wp_footer',
            $plugin_cart,
            'render_drawer',
            15
        );
        $this->loader->add_action(
            'wp_footer',
            $plugin_cart,
            'render_floating_cart_launcher',
            10
        );
        $this->loader->add_action(
            'cartpops_drawer_content',
            $plugin_cart,
            'render_drawer_header',
            10,
            1
        );
        $this->loader->add_action(
            'cartpops_drawer_content',
            $plugin_cart,
            'render_cart_contents',
            20,
            1
        );
        $this->loader->add_action(
            'cartpops_drawer_content',
            $plugin_cart,
            'render_drawer_footer',
            30,
            1
        );
        $this->loader->add_action( 'cartpops_drawer_panel_wrapper_start', $plugin_cart, 'render_wc_notices' );
        $this->loader->add_action( 'cartpops_drawer_footer_content', $plugin_cart, 'render_coupon_form' );
        $this->loader->add_action( 'cartpops_drawer_coupon_form_after', $plugin_cart, 'render_coupon_removal' );
        $this->loader->add_action( 'cartpops_drawer_footer_content', $plugin_cart, 'render_drawer_recommendations' );
        $this->loader->add_action( 'cartpops_drawer_footer_content', $plugin_cart, 'render_cart_totals' );
        $this->loader->add_action( 'cartpops_drawer_footer_content', $plugin_cart, 'render_powered_by' );
    }

    /**
     * Front-end assets manager
     *
     * @since 1.2.8
     * @author CartPops <help@cartpops.com>
     */
    private function assets_frontend() {
        $assets_frontend = new CartPops_Assets($this->get_plugin_name(), $this->get_version());
        $this->loader->add_action(
            'wp_enqueue_scripts',
            $assets_frontend,
            'enqueue_frontend_styles',
            20
        );
        $this->loader->add_action(
            'wp_enqueue_scripts',
            $assets_frontend,
            'enqueue_frontend_scripts',
            20
        );
        $this->loader->add_action(
            'wp_enqueue_scripts',
            $assets_frontend,
            'enqueue_dynamic_styles',
            25
        );
    }

    /**
     * Register all of the hooks related to the public-facing functionality
     * of the plugin.
     *
     * @since 1.2.8
     * @access private
     */
    private function public() {
        $templates = new CartPops_Cart($this->get_plugin_name(), $this->get_version());
        $plugin_public = new CartPops_Public($this->get_plugin_name(), $this->get_version(), $templates);
        $this->loader->add_filter( 'woocommerce_add_to_cart_fragments', $plugin_public, 'menu_cart_fragments' );
        // $this->loader->add_action( 'woocommerce_init', $plugin_public, 'maybe_set_session', 20 );
        $this->loader->add_shortcode(
            'cartpops_cart_launcher',
            $plugin_public,
            'shortcode_cart_launcher',
            10
        );
        $this->loader->add_filter(
            'walker_nav_menu_start_el',
            $plugin_public,
            'filter_nav_menu',
            20,
            2
        );
        $this->loader->add_filter(
            'woocommerce_cart_item_price',
            $plugin_public,
            'fix_cart_price',
            40,
            3
        );
        $this->loader->add_action(
            'woocommerce_cart_updated',
            $plugin_public,
            'woocommerce_cart_item_product',
            10,
            1
        );
    }

    /**
     * Front-end ajax calls to
     *
     * @since 1.2.9
     * @author CartPops <help@cartpops.com>
     */
    private function ajax_frontend() {
        $templates = new CartPops_Cart($this->get_plugin_name(), $this->get_version());
        $frontend_ajax = new CartPops_Frontend_Ajax($this->get_plugin_name(), $this->get_version(), $templates);
        $this->loader->add_filter(
            'woocommerce_add_to_cart_fragments',
            $frontend_ajax,
            'cart_fragments',
            1,
            1
        );
        $this->loader->add_action( 'wp_ajax_cpops_add_to_cart', $frontend_ajax, 'add_to_cart' );
        $this->loader->add_action( 'wp_ajax_nopriv_cpops_add_to_cart', $frontend_ajax, 'add_to_cart' );
        $this->loader->add_action( 'wp_ajax_cpops_refresh_cart', $frontend_ajax, 'refresh_cart' );
        $this->loader->add_action( 'wp_ajax_nopriv_cpops_refresh_cart', $frontend_ajax, 'refresh_cart' );
        $this->loader->add_action( 'wp_ajax_cpops_force_refresh_fragments', $frontend_ajax, 'get_refreshed_fragments' );
        $this->loader->add_action( 'wp_ajax_nopriv_cpops_force_refresh_fragments', $frontend_ajax, 'get_refreshed_fragments' );
        $this->loader->add_action( 'wp_ajax_cpops_update_cart', $frontend_ajax, 'update_cart' );
        $this->loader->add_action( 'wp_ajax_nopriv_cpops_update_cart', $frontend_ajax, 'update_cart' );
        $this->loader->add_action( 'wp_ajax_cpops_remove_product', $frontend_ajax, 'remove_product' );
        $this->loader->add_action( 'wp_ajax_nopriv_cpops_remove_product', $frontend_ajax, 'remove_product' );
        $this->loader->add_action( 'wp_ajax_cpops_restore_product', $frontend_ajax, 'restore_product' );
        $this->loader->add_action( 'wp_ajax_nopriv_cpops_restore_product', $frontend_ajax, 'restore_product' );
        $this->loader->add_action( 'wp_ajax_cpops_apply_coupon', $frontend_ajax, 'apply_coupon' );
        $this->loader->add_action( 'wp_ajax_nopriv_cpops_apply_coupon', $frontend_ajax, 'apply_coupon' );
        $this->loader->add_action( 'wp_ajax_cpops_remove_coupon', $frontend_ajax, 'remove_coupon' );
        $this->loader->add_action( 'wp_ajax_nopriv_cpops_remove_coupon', $frontend_ajax, 'remove_coupon' );
        $this->loader->add_action( 'wp_ajax_cpops_calculate_shipping', $frontend_ajax, 'calculate_shipping' );
        $this->loader->add_action( 'wp_ajax_nopriv_cpops_calculate_shipping', $frontend_ajax, 'calculate_shipping' );
        $this->loader->add_action( 'wp_ajax_cpops_update_shipping_method', $frontend_ajax, 'update_shipping_method' );
        $this->loader->add_action( 'wp_ajax_nopriv_cpops_update_shipping_method', $frontend_ajax, 'update_shipping_method' );
        $this->loader->add_filter(
            'woocommerce_remove_cart_item',
            $frontend_ajax,
            'removed_cart_item',
            10,
            2
        );
    }

    /**
     * Check if we can activate CartPops on the front-end.
     *
     * @since 1.3.0
     * @author CartPops <help@cartpops.com>
     */
    private function can_activate_frontend() {
        $enabled = Options::get( 'plugin_enable' );
        /**
         * Don't run the front-end when in Gutenberg edit mode to prevent errors.
         *
         * @since 1.4.3
         */
        if ( $this->is_block_editor() ) {
            return false;
        }
        // If the plugin is disabled in settings.
        if ( 'on' !== $enabled ) {
            return false;
        }
        // If the webpage is being edited by the Oxygen editor.
        if ( isset( $_GET['ct_builder'] ) && 'true' === $_GET['ct_builder'] ) {
            // phpcs:ignore
            return false;
        }
        return true;
    }

    /**
     * Check if the current screen is the Gutenberg block editor.
     *
     * @since 1.4.3
     */
    private function is_block_editor() {
        global $current_screen;
        if ( $current_screen instanceof \WP_Screen && $current_screen->is_block_editor() ) {
            return true;
        }
        return false;
    }

    /**
     * Run the loader to execute all of the hooks with WordPress.
     *
     * @since 1.0.0
     */
    public function run() {
        $this->loader->run();
    }

    /**
     * The name of the plugin used to uniquely identify it within the context of
     * WordPress and to define internationalization functionality.
     *
     * @since  1.0.0
     * @return string    The name of the plugin.
     */
    public function get_plugin_name() {
        return $this->plugin_name;
    }

    /**
     * The reference to the class that orchestrates the hooks with the plugin.
     *
     * @since  1.0.0
     * @return CartPops_Loader    Orchestrates the hooks of the plugin.
     */
    public function get_loader() {
        return $this->loader;
    }

    /**
     * Retrieve the version number of the plugin.
     *
     * @since  1.0.0
     * @return string    The version number of the plugin.
     */
    public function get_version() {
        return $this->version;
    }

}
