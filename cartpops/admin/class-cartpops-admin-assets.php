<?php

/**
 * Admin Assets
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
if ( ! class_exists( 'CartPops_Admin_Assets' ) ) {


	/**
	 * Class.
	 */
	class CartPops_Admin_Assets {

		protected static $plugin_screen_hook_suffix;

		/**
		 * Class Initialization.
		 */
		public static function init() {
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
		}

		/**
		 * Enqueue external JS files
		 */
		public static function enqueue_styles() {

			if ( ! isset( CartPops_Menu_Management::$plugin_screen_hook_suffix ) ) {
				return;
			}

			$screen = get_current_screen();

			wp_enqueue_style( 'cartpops-admin-global', CARTPOPS_URL . '/admin/dist/css/admin-global.min.css', array(), CARTPOPS_VERSION, 'all' );

			if ( CartPops_Menu_Management::$plugin_screen_hook_suffix === $screen->id ) {
				wp_enqueue_style( 'cartpops-admin', CARTPOPS_URL . 'admin/dist/css/admin.min.css', array(), CARTPOPS_VERSION, 'all' );
				wp_enqueue_style( 'cartpops-admin-jquery-ui', CARTPOPS_URL . 'admin/dist/vendor/jquery-ui.css', array(), CARTPOPS_VERSION, 'all' );
				wp_enqueue_style( 'cartpops-admin-select2', CARTPOPS_URL . 'admin/dist/vendor/select2.css', array(), CARTPOPS_VERSION, 'all' );
				wp_enqueue_style( 'cartpops-admin-spectrum', CARTPOPS_URL . 'admin/dist/vendor/spectrum.css', array(), CARTPOPS_VERSION, 'all' );
				wp_enqueue_style( 'cartpops-admin-codemirror', CARTPOPS_URL . 'admin/dist/vendor/codemirror.css', array(), CARTPOPS_VERSION, 'all' );
				wp_enqueue_style( 'cartpops-admin-codemirror-theme', CARTPOPS_URL . 'admin/dist/vendor/material.css', array(), CARTPOPS_VERSION, 'all' );
			}
		}

		/**
		 * Enqueue scripts
		 *
		 * @author CartPops <help@cartpops.com>
		 */
		public static function enqueue_scripts() {

			if ( ! isset( CartPops_Menu_Management::$plugin_screen_hook_suffix ) ) {
				return;
			}

			$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
			$screen = get_current_screen();

			if ( CartPops_Menu_Management::$plugin_screen_hook_suffix === $screen->id ) {

				wp_enqueue_script( 'jquery-ui-datepicker' );
				wp_enqueue_script( 'cartpops-admin', CARTPOPS_URL . 'admin/dist/js/admin.min.js', array( 'jquery', 'jquery-blockui', 'jquery-ui-datepicker' ), CARTPOPS_VERSION, true );
				wp_localize_script(
					'cartpops-admin',
					'CartPopsAdminConfig',
					array(
						'delete_transient_nonce'         => wp_create_nonce( 'cartpops-delete-transient-nonce' ),
						'cartpops_master_log_info_nonce' => wp_create_nonce( 'cartpops-master-log-info-nonce' ),
						'delete_confirm_msg'             => esc_html__( 'Are you sure you want to delete?', 'cartpops' ),
						'cartpops_rules_nonce'           => wp_create_nonce( 'cartpops-rules-nonce' ),
						'cartpops_rules_drag_nonce'      => wp_create_nonce( 'cartpops-rules-drag-nonce' ),
						'search_nonce'                   => wp_create_nonce( 'cartpops-search-nonce' ),
						'cartpops_apply_color_preset_nonce' => wp_create_nonce( 'cartpops-apply-color-preset-nonce' ),
						'ajaxurl'                        => admin_url( 'admin-ajax.php' ),
					)
				);

			}

		}

	}

	CartPops_Admin_Assets::init();
}
