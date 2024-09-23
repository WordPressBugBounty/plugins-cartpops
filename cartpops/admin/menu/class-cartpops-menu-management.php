<?php

/*
 * Menu Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'CartPops_Menu_Management' ) ) {

	include_once 'class-cartpops-settings.php';

	/**
	 * CartPops_Menu_Management Class.
	 */
	class CartPops_Menu_Management {


		/**
		 * Slug of the plugin screen.
		 *
		 * @since 1.0.0
		 *
		 * @var string
		 */
		public static $plugin_screen_hook_suffix = null;

		/**
		 * Plugin slug.
		 */
		protected static $plugin_slug = 'cartpops';

		/**
		 * Settings slug.
		 */
		protected static $settings_slug = 'cartpops_settings';

		/**
		 * Class initialization.
		 */
		public static function init() {
			add_action( 'admin_menu', array( __CLASS__, 'add_menu_pages' ) );
		}

		/**
		 * Add menu pages
		 */
		public static function add_menu_pages() {
			self::$plugin_screen_hook_suffix = add_menu_page(
				apply_filters( 'cartpops_admin_settings_page_title', esc_html__( 'CartPops Options', 'cartpops' ) ),
				apply_filters( 'cartpops_admin_settings_menu_title', esc_html__( 'CartPops', 'cartpops' ) ),
				'manage_options',
				self::$settings_slug,
				array( __CLASS__, 'settings_page' ),
				CartPops_Settings::get_admin_asset( 'icon-cartpops25x25@2x.png' )
			);

			add_action( 'load-' . self::$plugin_screen_hook_suffix, array( __CLASS__, 'settings_page_init' ) );
		}

		/**
		 * Settings page init
		 */
		public static function settings_page_init() {
			global $current_tab , $current_section , $current_sub_section , $current_action;

			// Include settings pages.
			$settings = CartPops_Settings::get_settings_pages();

			$tabs = cartpops_get_allowed_setting_tabs();

			foreach ( $tabs as $tab => $key ) {
				$all_tabs[ $tab ] = $tab;
			}

			// Get current tab/section.
			$current_tab = key( $all_tabs );

			if ( ! empty( $_GET['tab'] ) ) {
             $sanitize_current_tab = sanitize_title( wp_unslash( $_GET[ 'tab' ] ) ) ; // @codingStandardsIgnoreLine.
				if ( array_key_exists( $sanitize_current_tab, $all_tabs ) ) {
					$current_tab = $sanitize_current_tab;
				}
			}

			$section = isset( $settings[ $current_tab ] ) ? $settings[ $current_tab ]->get_sections() : array();

         	$current_section     = empty( $_REQUEST[ 'section' ] ) ? key( $section ) : sanitize_title( wp_unslash( $_REQUEST[ 'section' ] ) ) ; // @codingStandardsIgnoreLine.
			$current_section     = empty( $current_section ) ? $current_tab : $current_section;
        	$current_sub_section = empty( $_REQUEST[ 'subsection' ] ) ? '' : sanitize_title( wp_unslash( $_REQUEST[ 'subsection' ] ) ) ; // @codingStandardsIgnoreLine.
         	$current_action      = empty( $_REQUEST[ 'action' ] ) ? '' : sanitize_title( wp_unslash( $_REQUEST[ 'action' ] ) ) ; // @codingStandardsIgnoreLine.

			do_action( sanitize_key( self::$plugin_slug . '_settings_save_' . $current_tab ), $current_section );
			do_action( sanitize_key( self::$plugin_slug . '_settings_reset_' . $current_tab ), $current_section );

			add_action( 'woocommerce_admin_field_cartpops_custom_fields', array( __CLASS__, 'custom_fields_output' ) );
		}

		/**
		 * Settings page output.
		 */
		public static function settings_page() {
			CartPops_Settings::output();
		}

		/**
		 * Output the custom field settings.
		 */
		public static function custom_fields_output( $options ) {
			CartPops_Settings::output_fields( $options );
		}

		/**
		 * Sanitize the custom field settings.
		 *
		 * @return mixed
		 */
		public static function sanitize_custom_fields( $value, $option, $raw_value ) {
			return CartPops_Settings::save_fields( $value, $option, $raw_value );
		}

	}

	CartPops_Menu_Management::init();
}
