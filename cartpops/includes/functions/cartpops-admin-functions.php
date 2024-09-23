<?php

/**
 * Admin functions.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! function_exists( 'cartpops_page_screen_ids' ) ) {

	/**
	 * Get the page screen IDs.
	 *
	 * @return array
	 */
	function cartpops_page_screen_ids() {

		$wc_screen_id = sanitize_title( esc_html__( 'WooCommerce', 'woocommerce' ) );

		return apply_filters(
			'cartpops_page_screen_ids',
			array(
				$wc_screen_id . '_page_cartpops_settings',
				'shop_coupon',
			)
		);
	}
}

if ( ! function_exists( 'cartpops_get_allowed_setting_tabs' ) ) {

	/**
	 * Get the setting tabs.
	 *
	 * @return array
	 */
	function cartpops_get_allowed_setting_tabs() {

		return apply_filters( 'cartpops_settings_tabs_array', array() );
	}
}

if ( ! function_exists( 'cartpops_get_wc_order_statuses' ) ) {

	/**
	 * Get the WC order statuses.
	 *
	 * @return array
	 */
	function cartpops_get_wc_order_statuses() {

		$order_statuses_keys   = array_keys( wc_get_order_statuses() );
		$order_statuses_keys   = str_replace( 'wc-', '', $order_statuses_keys );
		$order_statuses_values = array_values( wc_get_order_statuses() );

		return array_combine( $order_statuses_keys, $order_statuses_values );
	}
}

if ( ! function_exists( 'cartpops_get_paid_order_statuses' ) ) {

	/**
	 * Get the WC paid order statuses.
	 *
	 * @return array
	 */
	function cartpops_get_paid_order_statuses() {

		$statuses = array(
			'processing' => esc_html__( 'Processing', 'cartpops' ),
			'completed'  => esc_html__( 'Completed', 'cartpops' ),
		);

		return apply_filters( 'cartpops_paid_order_statuses', $statuses );
	}
}

if ( ! function_exists( 'cartpops_get_wc_categories' ) ) {

	/**
	 * Get the WC categories.
	 *
	 * @return array
	 */
	function cartpops_get_wc_categories() {
		$categories    = array();
		$wc_categories = get_terms( 'product_cat' );

		if ( ! cartpops_check_is_array( $wc_categories ) ) {
			return $categories;
		}

		foreach ( $wc_categories as $category ) {
			$categories[ $category->term_id ] = $category->name;
		}

		return $categories;
	}
}

if ( ! function_exists( 'cartpops_get_wp_user_roles' ) ) {

	/**
	 * Get the WordPress user roles.
	 *
	 * @return array
	 */
	function cartpops_get_wp_user_roles() {
		global $wp_roles;
		$user_roles = array();

		if ( ! isset( $wp_roles->roles ) || ! cartpops_check_is_array( $wp_roles->roles ) ) {
			return $user_roles;
		}

		foreach ( $wp_roles->roles as $slug => $role ) {
			$user_roles[ $slug ] = $role['name'];
		}

		return $user_roles;
	}
}

if ( ! function_exists( 'cartpops_get_user_roles' ) ) {

	/**
	 * Get the user roles.
	 *
	 * @return array
	 */
	function cartpops_get_user_roles( $extra_options = array() ) {
		$user_roles = cartpops_get_wp_user_roles();

		$user_roles['guest'] = esc_html__( 'Guest', 'cartpops' );

		$user_roles = array_merge( $user_roles, $extra_options );

		return $user_roles;
	}
}

if ( ! function_exists( 'cartpops_get_settings_page_url' ) ) {

	/**
	 * Get the settings page URL.
	 *
	 * @return string
	 */
	function cartpops_get_settings_page_url( $args = array() ) {

		$url = add_query_arg( array( 'page' => 'cartpops_settings' ), admin_url( 'admin.php' ) );

		if ( cartpops_check_is_array( $args ) ) {
			$url = add_query_arg( $args, $url );
		}

		return $url;
	}
}

if ( ! function_exists( 'cartpops_get_rule_page_url' ) ) {

	/**
	 * Get the rule page URL.
	 *
	 * @return string
	 */
	function cartpops_get_rule_page_url( $args = array() ) {

		$url = add_query_arg(
			array(
				'page' => 'cartpops_settings',
				'tab'  => 'rules',
			),
			admin_url( 'admin.php' )
		);

		if ( cartpops_check_is_array( $args ) ) {
			$url = add_query_arg( $args, $url );
		}

		return $url;
	}
}

if ( ! function_exists( 'cartpops_filter_readable_products' ) ) {

	/**
	 * Filter the readable products.
	 *
	 * @return array
	 */
	function cartpops_filter_readable_products( $product_ids ) {

		if ( ! cartpops_check_is_array( $product_ids ) ) {
			return array();
		}

		if ( function_exists( 'wc_products_array_filter_readable' ) ) {
			return array_filter( array_map( 'wc_get_product', $product_ids ), 'wc_products_array_filter_readable' );
		} else {
			return array_filter( array_map( 'wc_get_product', $product_ids ), 'cartpops_products_array_filter_readable' );
		}
	}
}
if ( ! function_exists( 'cartpops_products_array_filter_readable' ) ) {

	/**
	 * Filter the readable product.
	 *
	 * @return array
	 */
	function cartpops_products_array_filter_readable( $product ) {
		return $product && is_a( $product, 'WC_Product' ) && current_user_can( 'read_product', $product->get_id() );
	}
}

if ( ! function_exists( 'cartpops_get_master_log_page_url' ) ) {

	/**
	 * Get the master log page URL.
	 *
	 * @return string
	 */
	function cartpops_get_master_log_page_url( $args = array() ) {

		$url = add_query_arg(
			array(
				'page' => 'cartpops_settings',
				'tab'  => 'master-log',
			),
			admin_url( 'admin.php' )
		);

		if ( cartpops_check_is_array( $args ) ) {
			$url = add_query_arg( $args, $url );
		}

		return $url;
	}
}

if ( ! function_exists( 'cartpops_get_rule_type_name' ) ) {

	/**
	 * Get the rule type name.
	 *
	 * @return string
	 */
	function cartpops_get_rule_type_name( $type ) {

		$types = array(
			'1' => esc_html__( 'Manual', 'cartpops' ),
			'2' => esc_html__( 'Automatic', 'cartpops' ),
			'3' => esc_html__( 'Buy X Get Y', 'cartpops' ),
			'4' => esc_html__( 'Coupon based Upsell', 'cartpops' ),
		);

		if ( ! isset( $types[ $type ] ) ) {
			return '';
		}

		return $types[ $type ];
	}
}

if ( ! function_exists( 'cartpops_get_rule_week_days_options' ) ) {

	/**
	 * Get the rule weekdays options.
	 *
	 * @return array
	 * */
	function cartpops_get_rule_week_days_options() {
		return array(
			'1' => esc_html__( 'Monday', 'cartpops' ),
			'2' => esc_html__( 'Tuesday', 'cartpops' ),
			'3' => esc_html__( 'Wednesday', 'cartpops' ),
			'4' => esc_html__( 'Thursday', 'cartpops' ),
			'5' => esc_html__( 'Friday', 'cartpops' ),
			'6' => esc_html__( 'Saturday', 'cartpops' ),
			'7' => esc_html__( 'Sunday', 'cartpops' ),
		);
	}
}


if ( ! function_exists( 'cartpops_display_action' ) ) {

	/**
	 * Display the post action.
	 *
	 * @return string
	 */
	function cartpops_display_action( $status, $id, $current_url, $action = false ) {
		switch ( $status ) {
			case 'edit':
				$status_name = esc_html__( 'Edit', 'cartpops' );
				break;
			case 'active':
				$status_name = esc_html__( 'Activate', 'cartpops' );
				break;
			case 'inactive':
				$status_name = esc_html__( 'Deactivate', 'cartpops' );
				break;
			default:
				$status_name = esc_html__( 'Delete Permanently', 'cartpops' );
				break;
		}

		$section_name = 'section';
		if ( $action ) {
			$section_name = 'action';
		}

		if ( 'edit' == $status ) {
			return '<a href="' . esc_url(
				add_query_arg(
					array(
						$section_name => $status,
						'id'          => $id,
					),
					$current_url
				)
			) . '">' . $status_name . '</a>';
		} elseif ( 'delete' == $status ) {
			return '<a class="cartpops_delete_data" href="' . esc_url(
				add_query_arg(
					array(
						'action' => $status,
						'id'     => $id,
					),
					$current_url
				)
			) . '">' . $status_name . '</a>';
		} else {
			return '<a href="' . esc_url(
				add_query_arg(
					array(
						'action' => $status,
						'id'     => $id,
					),
					$current_url
				)
			) . '">' . $status_name . '</a>';
		}
	}
}

if ( ! function_exists( 'cartpops_display_status' ) ) {

	/**
	 * Display the formatted post status.
	 *
	 * @return string
	 */
	function cartpops_display_status( $status, $html = true ) {

		$status_object = get_post_status_object( $status );

		if ( ! isset( $status_object ) ) {
			return '';
		}

		return $html ? '<mark class="cartpops_status_label ' . esc_attr( $status ) . '_status"><span >' . esc_html( $status_object->label ) . '</span></mark>' : esc_html( $status_object->label );
	}
}

if ( ! function_exists( 'cartpops_wc_help_tip' ) ) {

	/**
	 * Display the tool tip based on WC help tip.
	 *
	 * @return string
	 */
	function cartpops_wc_help_tip( $tip, $allow_html = false, $echo = true ) {

		$formatted_tip = wc_help_tip( $tip, $allow_html );

		if ( $echo ) {
			echo wp_kses_post( $formatted_tip );
		}

		return $formatted_tip;
	}
}
