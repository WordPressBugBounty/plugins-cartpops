<?php

/**
 * Admin Ajax.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
if ( ! class_exists( 'CartPops_Admin_Ajax' ) ) {

	/**
	 * CartPops_Admin_Ajax Class.
	 */
	class CartPops_Admin_Ajax {

		/**
		 * Class initialization.
		 */
		public static function init() {

			$actions = array(
				'json_search_products_and_variations' => false,
				'json_search_products'                => false,
				'json_search_pages'                   => false,
				'json_search_customers'               => false,
				'json_search_coupons'                 => false,
				'upsell_products_pagination'          => true,
				'drag_rules_list'                     => false,
				'reset_rule_usage_count'              => false,
				'delete_transient'                    => false,
				'apply_color_preset'                  => false,
			);

			foreach ( $actions as $action => $nopriv ) {
				add_action( 'wp_ajax_cartpops_' . $action, array( __CLASS__, $action ) );

				if ( $nopriv ) {
					add_action( 'wp_ajax_nopriv_cartpops_' . $action, array( __CLASS__, $action ) );
				}
			}
		}

		/**
		 * Search for products.
		 */
		public static function json_search_products( $term = '', $include_variations = false ) {
			check_ajax_referer( 'search-products', 'cartpops_security' );

			try {

				if ( empty( $term ) && isset( $_GET['term'] ) ) {
					$term = isset( $_GET['term'] ) ? wc_clean( wp_unslash( $_GET['term'] ) ) : '';
				}

				if ( empty( $term ) ) {
					throw new exception( esc_html__( 'No Products found', 'cartpops' ) );
				}

				if ( ! empty( $_GET['limit'] ) ) {
					$limit = absint( $_GET['limit'] );
				} else {
					$limit = absint( apply_filters( 'woocommerce_json_search_limit', 30 ) );
				}

				$data_store = WC_Data_Store::load( 'product' );
				$ids        = $data_store->search_products( $term, '', (bool) $include_variations, false, $limit );

				$product_objects = cartpops_filter_readable_products( $ids );
				$products        = array();

				$exclude_global_variable = isset( $_GET[ 'exclude_global_variable' ] ) ? wc_clean( wp_unslash( $_GET[ 'exclude_global_variable' ] ) ) : 'no' ; // @codingStandardsIgnoreLine.
				foreach ( $product_objects as $product_object ) {
					if ( 'yes' == $exclude_global_variable && $product_object->is_type( 'variable' ) ) {
						continue;
					}

					$products[ $product_object->get_id() ] = rawurldecode( $product_object->get_formatted_name() );
				}

				wp_send_json( apply_filters( 'woocommerce_json_search_found_products', $products ) );
			} catch ( Exception $ex ) {
				wp_die();
			}
		}

		/**
		 * Search for pages.
		 */
		public static function json_search_pages( $term = '' ) {
			check_ajax_referer( 'cartpops-search-nonce', 'cartpops_security' );

			try {

				if ( empty( $term ) && isset( $_GET['term'] ) ) {
					$term = isset( $_GET['term'] ) ? wc_clean( wp_unslash( $_GET['term'] ) ) : '';
				}

				if ( empty( $term ) ) {
					throw new exception( esc_html__( 'No pages found', 'cartpops' ) );
				}

				$exclude = isset( $_GET[ 'exclude' ] ) ? wc_clean( wp_unslash( $_GET[ 'exclude' ] ) ) : '' ; // @codingStandardsIgnoreLine.
				$exclude = ! empty( $exclude ) ? array_map( 'intval', explode( ',', $exclude ) ) : array();

				$found_pages = array();
				$pages_query = new WP_Query(
					array(
						'post_type'      => 'page', // it is a Page right?
						'post_status'    => 'publish',
						's'              => $term,
						'posts_per_page' => -1,
					)
				);

				$pages = $pages_query->posts;

				if ( cartpops_check_is_array( $pages ) ) {
					foreach ( $pages as $page ) {
						if ( ! in_array( $page->ID, $exclude, true ) ) {
							$found_pages[ $page->ID ] = $page->post_title;
						}
					}
				}

				wp_send_json( $found_pages );
			} catch ( Exception $ex ) {
				wp_die();
			}
		}

		/**
		 * Search for product variations.
		 */
		public static function json_search_products_and_variations( $term = '', $include_variations = false ) {
			self::json_search_products( '', true );
		}

		/**
		 * Customers search.
		 */
		public static function json_search_customers() {
			check_ajax_referer( 'cartpops-search-nonce', 'cartpops_security' );

			try {
				$term = isset( $_GET[ 'term' ] ) ? wc_clean( wp_unslash( $_GET[ 'term' ] ) ) : '' ; // @codingStandardsIgnoreLine.

				if ( empty( $term ) ) {
					throw new exception( esc_html__( 'No Customer found', 'cartpops' ) );
				}

				$exclude = isset( $_GET[ 'exclude' ] ) ? wc_clean( wp_unslash( $_GET[ 'exclude' ] ) ) : '' ; // @codingStandardsIgnoreLine.
				$exclude = ! empty( $exclude ) ? array_map( 'intval', explode( ',', $exclude ) ) : array();

				$found_customers = array();
				$customers_query = new WP_User_Query(
					array(
						'fields'         => 'all',
						'orderby'        => 'display_name',
						'search'         => '*' . $term . '*',
						'search_columns' => array( 'ID', 'user_login', 'user_email', 'user_nicename' ),
					)
				);
				$customers       = $customers_query->get_results();

				if ( cartpops_check_is_array( $customers ) ) {
					foreach ( $customers as $customer ) {
						if ( ! in_array( $customer->ID, $exclude ) ) {
							$found_customers[ $customer->ID ] = $customer->display_name . ' (#' . $customer->ID . ' &ndash; ' . sanitize_email( $customer->user_email ) . ')';
						}
					}
				}

				wp_send_json( $found_customers );
			} catch ( Exception $ex ) {
				wp_die();
			}
		}

		/**
		 * Coupon search.
		 */
		public static function json_search_coupons() {
			check_ajax_referer( 'cartpops-search-nonce', 'cartpops_security' );

			try {
				$term = isset( $_GET[ 'term' ] ) ? wc_clean( wp_unslash( $_GET[ 'term' ] ) ) : '' ; // @codingStandardsIgnoreLine.

				if ( empty( $term ) ) {
					throw new exception( esc_html__( 'No Coupon found', 'cartpops' ) );
				}

				global $wpdb;
				$like = '%' . $wpdb->esc_like( $term ) . '%';

				$search_results = array_filter(
					$wpdb->get_results(
						$wpdb->prepare(
							"SELECT DISTINCT ID as id, post_title as name FROM {$wpdb->posts}
			WHERE post_type='shop_coupon' AND post_status IN('publish')
                        AND (post_title LIKE %s) ORDER BY post_title ASC",
							$like
						),
						ARRAY_A
					)
				);

				$found_coupons = array();

				if ( cartpops_check_is_array( $search_results ) ) {
					foreach ( $search_results as $search_result ) {
						$found_coupons[ $search_result['id'] ] = $search_result['name'] . ' (#' . $search_result['id'] . ')';
					}
				}

				wp_send_json( $found_coupons );
			} catch ( Exception $ex ) {
				wp_die();
			}
		}

		/**
		 * Create order for selected user with upsell products.
		 */
		public static function create_upsell_order() {
			check_ajax_referer( 'cartpops-manual-upsell-nonce', 'cartpops_security' );

			try {
				if ( ! isset( $_POST ) ) {
					throw new exception( esc_html__( 'Invalid Request', 'cartpops' ) );
				}

				if ( ! isset( $_POST[ 'user' ] ) || empty( absint( $_POST[ 'user' ] ) ) ) { // @codingStandardsIgnoreLine.
					throw new exception( esc_html__( 'Please select a User', 'cartpops' ) );
				}

				if ( ! isset( $_POST[ 'products' ] ) || empty( wc_clean( wp_unslash( ( $_POST[ 'products' ] ) ) ) ) ) { // @codingStandardsIgnoreLine.
					throw new exception( esc_html__( 'Please select atleast one Product', 'cartpops' ) );
				}

				// Sanitize post values
				$user_id      = ! empty( $_POST[ 'user' ] ) ? absint( $_POST[ 'user' ] ) : 0 ; // @codingStandardsIgnoreLine.
				$products     = ! empty( $_POST[ 'products' ] ) ? wc_clean( wp_unslash( ( $_POST[ 'products' ] ) ) ) : array() ; // @codingStandardsIgnoreLine.
				$order_status = ! empty( $_POST[ 'status' ] ) ? wc_clean( wp_unslash( ( $_POST[ 'status' ] ) ) ) : '' ; // @codingStandardsIgnoreLine.
				// Create order for selected user with upsell products
				$order_id = CartPops_Manual_Upsell_Order_Handler::create_free_upsell_order( $user_id, $products, $order_status );

				$msg = esc_html__( 'Upsell has been sent successfully', 'cartpops' );

				wp_send_json_success( array( 'msg' => $msg ) );
			} catch ( Exception $ex ) {
				wp_send_json_error( array( 'error' => $ex->getMessage() ) );
			}
		}

		/**
		 * Display Upsell Products based on pagination.
		 */
		public static function upsell_products_pagination() {
			check_ajax_referer( 'cartpops-upsell-products-pagination', 'cartpops_security' );

			try {
				if ( ! isset( $_POST ) || ! isset( $_POST[ 'page_number' ] ) ) { // @codingStandardsIgnoreLine.
					throw new exception( esc_html__( 'Invalid Request', 'cartpops' ) );
				}

				// Sanitize post values
				$current_page = ! empty( $_POST[ 'page_number' ] ) ? absint( $_POST[ 'page_number' ] ) : 0 ; // @codingStandardsIgnoreLine.
				$page_url     = ! empty( $_POST[ 'page_url' ] ) ? wc_clean( wp_unslash( $_POST[ 'page_url' ] ) ) : '' ; // @codingStandardsIgnoreLine.

				$per_page = cartpops_get_free_upsells_per_page_column_count();
				$offset   = ( $current_page - 1 ) * $per_page;

				// Get upsell products based on per page count
				$upsell_products = CartPops_Rule_Handler::get_manual_upsell_products();
				$upsell_products = array_slice( $upsell_products, $offset, $per_page );

				// Get upsell products table body content
				$html = cartpops_get_template_html(
					'upsell-products.php',
					array(
						'upsell_products' => $upsell_products,
						'permalink'       => esc_url( $page_url ),
					)
				);

				wp_send_json_success( array( 'html' => $html ) );
			} catch ( Exception $ex ) {
				wp_send_json_error( array( 'error' => $ex->getMessage() ) );
			}
		}


		/**
		 * Drag Rules.
		 */
		public static function drag_rules_list() {
			check_ajax_referer( 'cartpops-rules-drag-nonce', 'cartpops_security' );

			try {
				if ( ! isset( $_POST ) || ! isset( $_POST[ 'sort_order' ] ) ) { // @codingStandardsIgnoreLine.
					throw new exception( esc_html__( 'Invalid Request', 'cartpops' ) );
				}

				$sort_ids = array();
				// Sanitize post values
				$post_sort_order_ids = ! empty( $_POST[ 'sort_order' ] ) ? wc_clean( wp_unslash( ( $_POST[ 'sort_order' ] ) ) ) : array() ; // @codingStandardsIgnoreLine.
				// prepare sort order post ids
				foreach ( $post_sort_order_ids as $key => $post_id ) {
					$sort_ids[ $key + 1 ] = str_replace( 'post-', '', $post_id );
				}

				// update sort order post ids
				foreach ( $sort_ids as $menu_order => $post_id ) {
					wp_update_post(
						array(
							'ID'         => $post_id,
							'menu_order' => $menu_order,
						)
					);
				}

				wp_send_json_success();
			} catch ( Exception $ex ) {
				wp_send_json_error( array( 'error' => $ex->getMessage() ) );
			}
		}

		/**
		 * Apply color preset
		 */
		public static function apply_color_preset() {
			check_ajax_referer( 'cartpops-apply-color-preset-nonce', 'cartpops_security' );

			try {
				if ( ! isset( $_POST ) || ! isset( $_POST[ 'colors' ] ) ) { // @codingStandardsIgnoreLine.
					throw new exception( esc_html__( 'Invalid Request', 'cartpops' ) );
				}

				$colors = $_POST[ 'colors' ]; // @codingStandardsIgnoreLine.

				// update sort order post ids
				foreach ( $colors as $color => $value ) {
					update_option( $color, $value );
				}

				wp_send_json_success();
			} catch ( Exception $ex ) {
				wp_send_json_error( array( 'error' => $ex->getMessage() ) );
			}
		}

		/**
		 * Reset rule usage count.
		 */
		public static function reset_rule_usage_count() {
			check_ajax_referer( 'cartpops-rules-nonce', 'cartpops_security' );

			try {
				if ( ! isset( $_POST ) || ! isset( $_POST[ 'rule_id' ] ) ) { // @codingStandardsIgnoreLine.
					throw new exception( esc_html__( 'Invalid Request', 'cartpops' ) );
				}

				// Sanitize post values
				$rule_id = absint( $_POST[ 'rule_id' ] ) ; // @codingStandardsIgnoreLine.
				// Reset rule usage count
				update_post_meta( $rule_id, 'cartpops_rule_usage_count', 0 );

				wp_send_json_success( array( 'msg' => esc_html__( 'Order usage count reset successfully', 'cartpops' ) ) );
			} catch ( Exception $ex ) {
				wp_send_json_error( array( 'error' => $ex->getMessage() ) );
			}
		}

		/**
		 * Delete the issue list transietn.
		 *
		 * @author CartPops <help@cartpops.com>
		 */
		public function delete_transient() {

			check_ajax_referer( 'cartpops-delete-transient-nonce', 'cartpops_security' );

			try {
				if ( ! isset( $_POST ) || ! isset( $_POST[ 'transient' ] ) ) { // @codingStandardsIgnoreLine.
					throw new exception( esc_html__( 'Invalid Request', 'cartpops' ) );
				}

				// Sanitize post values
				$transient = absint( $_POST[ 'transient' ] ) ; // @codingStandardsIgnoreLine.

				delete_transient( $transient );

				wp_send_json_success( array( 'msg' => esc_html__( 'Transient deleted', 'cartpops' ) ) );
			} catch ( Exception $ex ) {
				wp_send_json_error( array( 'error' => $ex->getMessage() ) );
			}

		}

	}

	CartPops_Admin_Ajax::init();
}
