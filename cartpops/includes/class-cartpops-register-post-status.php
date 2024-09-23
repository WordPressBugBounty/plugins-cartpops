<?php
/**
 * Register Custom Post Status.
 *
 * @package
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'CartPops_Register_Post_Status' ) ) {

	/**
	 * CartPops_Register_Post_Status Class.
	 */
	class CartPops_Register_Post_Status {


		/**
		 * Class initialization.
		 */
		public static function init() {
			add_action( 'init', array( __CLASS__, 'register_custom_post_status' ) );
		}

		/**
		 * Register Custom Post Status.
		 */
		public static function register_custom_post_status() {
			$custom_post_statuses = array(
				'cartpops_active'    => array( 'CartPops_Register_Post_Status', 'active_post_status_args' ),
				'cartpops_inactive'  => array( 'CartPops_Register_Post_Status', 'inactive_post_status_args' ),
				'cartpops_manual'    => array( 'CartPops_Register_Post_Status', 'manual_post_status_args' ),
				'cartpops_automatic' => array( 'CartPops_Register_Post_Status', 'automatic_post_status_args' ),
			);

			$custom_post_statuses = apply_filters( 'cartpops_add_custom_post_status', $custom_post_statuses );

			// return if no post status to register.
			if ( ! cartpops_check_is_array( $custom_post_statuses ) ) {
				return;
			}

			foreach ( $custom_post_statuses as $post_status => $args_function ) {

				$args = array();
				if ( $args_function ) {
					$args = call_user_func_array( $args_function, array() );
				}

				// Register post status.
				register_post_status( $post_status, $args );
			}
		}

		/**
		 * Active Custom Post Status arguments.
		 */
		public static function active_post_status_args() {
			$args = apply_filters(
				'cartpops_active_post_status_args',
				array(
					'label'                     => esc_html_x( 'Active', 'Post status label', 'cartpops' ),
					'public'                    => true,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					/* translators: %s: number of rules */
					'label_count'               => _n_noop( 'Active <span class="count">(%s)</span>', 'Active <span class="count">(%s)</span>', 'cartpops' ),
				)
			);

			return $args;
		}

		/**
		 * Inactive Custom Post Status arguments.
		 */
		public static function inactive_post_status_args() {
			$args = apply_filters(
				'cartpops_inactive_post_status_args',
				array(
					'label'                     => esc_html_x( 'Inactive', 'Post status label', 'cartpops' ),
					'public'                    => true,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					/* translators: %s: number of rules */
					'label_count'               => _n_noop( 'Inactive <span class="count">(%s)</span>', 'Inactive <span class="count">(%s)</span>', 'cartpops' ),
				)
			);

			return $args;
		}

		/**
		 * Manual Custom Post Status arguments.
		 */
		public static function manual_post_status_args() {
			$args = apply_filters(
				'cartpops_manual_post_status_args',
				array(
					'label'                     => esc_html_x( 'Manual', 'Post status label', 'cartpops' ),
					'public'                    => true,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					/* translators: %s: number of master logs */
					'label_count'               => _n_noop( 'Manual <span class="count">(%s)</span>', 'Manual <span class="count">(%s)</span>', 'cartpops' ),
				)
			);

			return $args;
		}

		/**
		 * Automatic Custom Post Status arguments.
		 */
		public static function automatic_post_status_args() {
			$args = apply_filters(
				'cartpops_automatic_post_status_args',
				array(
					'label'                     => esc_html_x( 'Automatic', 'Post status label', 'cartpops' ),
					'public'                    => true,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					/* translators: %s: number of master logs */
					'label_count'               => _n_noop( 'Automatic <span class="count">(%s)</span>', 'Automatic <span class="count">(%s)</span>', 'cartpops' ),
				)
			);

			return $args;
		}

	}

	CartPops_Register_Post_Status::init();
}
