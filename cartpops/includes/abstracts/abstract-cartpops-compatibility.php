<?php

/**
 * Compatibility.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'CartPops_Compatibility' ) ) {

	/**
	 * Abstract.
	 */
	abstract class CartPops_Compatibility {


		/**
		 * ID
		 *
		 * @var string.
		 * */
		protected $id;

		/**
		 * Plugin slug.
		 *
		 * @var string.
		 * */
		protected $plugin_slug = 'cartpops';

		/**
		 * Class Constructor.
		 * */
		public function __construct() {
			$this->process_actions();
		}

		/**
		 * Get id.
		 *
		 * @return string
		 * */
		public function get_id() {
			return $this->id;
		}

		/**
		 * Is enabled?.
		 *
		 * @return bool
		 * */
		public function is_enabled() {

			return $this->is_plugin_enabled();
		}

		/**
		 * Is plugin enabled?.
		 *
		 * @return bool
		 * */
		public function is_plugin_enabled() {

			return true;
		}

		/**
		 * Actions.
		 *
		 * @return void
		 * */
		public function process_actions() {
			if ( ! $this->is_enabled() ) {
				return;
			}

			$this->actions();

			if ( is_admin() ) {
				$this->admin_action();

				// Add action for external js files in backend
				add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
			}

			if ( ! is_admin() || defined( 'DOING_AJAX' ) ) {
				$this->frontend_action();

				// Add action for external js files in backend
				add_action( 'wp_enqueue_scripts', array( $this, 'frontend_enqueue_scripts' ) );
			}
		}

		/**
		 * Admin Actions.
		 * */
		public function admin_action() {

		}

		/**
		 * Actions.
		 * */
		public function actions() {

		}

		/**
		 * Frontend Actions.
		 * */
		public function frontend_action() {

		}

		/**
		 * Enqueue admin scripts.
		 * */
		public function admin_enqueue_scripts() {
			$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

			$this->admin_external_js_files( $suffix );
			$this->admin_external_css_files( $suffix );
		}

		/**
		 * Enqueue Frontend scripts.
		 * */
		public function frontend_enqueue_scripts() {
			$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

			$this->frontend_external_js_files( $suffix );
			$this->frontend_external_css_files( $suffix );
		}

		/**
		 * Enqueue frontend JS files.
		 * */
		public function frontend_external_js_files( $suffix ) {

		}

		/**
		 * Enqueue frontend CSS files.
		 * */
		public function frontend_external_css_files( $suffix ) {

		}

		/**
		 * Enqueue admin JS files.
		 * */
		public function admin_external_js_files( $suffix ) {

		}

		/**
		 * Enqueue admin CSS files.
		 * */
		public function admin_external_css_files( $suffix ) {

		}

	}

}
