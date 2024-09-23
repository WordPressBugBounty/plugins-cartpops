<?php

/*
 * Post functions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! function_exists( 'cartpops_create_new_rule' ) ) {

	/**
	 * Create New Rule
	 *
	 * @return integer/string
	 */
	function cartpops_create_new_rule( $meta_args, $post_args = array() ) {

		$object = new CartPops_RULE();
		$id     = $object->create( $meta_args, $post_args );

		return $id;
	}
}

if ( ! function_exists( 'cartpops_get_rule' ) ) {

	/**
	 * Get Rule object
	 *
	 * @return object
	 */
	function cartpops_get_rule( $id ) {

		$object = new CartPops_RULE( $id );

		return $object;
	}
}

if ( ! function_exists( 'cartpops_update_rule' ) ) {

	/**
	 * Update Rule
	 *
	 * @return object
	 */
	function cartpops_update_rule( $id, $meta_args, $post_args = array() ) {

		$object = new CartPops_RULE( $id );
		$object->update( $meta_args, $post_args );

		return $object;
	}
}

if ( ! function_exists( 'cartpops_delete_rule' ) ) {

	/**
	 * Delete Rule
	 *
	 * @return bool
	 */
	function cartpops_delete_rule( $id, $force = true ) {

		wp_delete_post( $id, $force );

		return true;
	}
}

if ( ! function_exists( 'cartpops_create_new_master_log' ) ) {

	/**
	 * Create New Master Log
	 *
	 * @return Integer/String
	 */
	function cartpops_create_new_master_log( $meta_args, $post_args = array() ) {

		$object = new CartPops_Master_Log();
		$id     = $object->create( $meta_args, $post_args );

		return $id;
	}
}

if ( ! function_exists( 'cartpops_get_master_log' ) ) {

	/**
	 * Get Master Log Object
	 *
	 * @return Object
	 */
	function cartpops_get_master_log( $id ) {

		$object = new CartPops_Master_Log( $id );

		return $object;
	}
}

if ( ! function_exists( 'cartpops_update_master_log' ) ) {

	/**
	 * Update Master Log
	 *
	 * @return Object
	 */
	function cartpops_update_master_log( $id, $meta_args, $post_args = array() ) {

		$object = new CartPops_Master_Log( $id );
		$object->update( $meta_args, $post_args );

		return $object;
	}
}

if ( ! function_exists( 'cartpops_delete_master_log' ) ) {

	/**
	 * Delete Master Log
	 *
	 * @return bool
	 */
	function cartpops_delete_master_log( $id, $force = true ) {

		wp_delete_post( $id, $force );

		return true;
	}
}

if ( ! function_exists( 'cartpops_get_rule_statuses' ) ) {

	/**
	 * Get Upsell statuseses
	 *
	 * @return array
	 */
	function cartpops_get_rule_statuses() {
		return apply_filters( 'cartpops_rule_statuses', array( 'cartpops_active', 'cartpops_inactive' ) );
	}
}

if ( ! function_exists( 'cartpops_get_master_log_statuses' ) ) {

	/**
	 * Get Master log statuses
	 *
	 * @return array
	 */
	function cartpops_get_master_log_statuses() {
		return apply_filters( 'cartpops_master_log_statuses', array( 'cartpops_manual', 'cartpops_automatic' ) );
	}
}

if ( ! function_exists( 'cartpops_get_active_rule_ids' ) ) {

	/**
	 * Get active rule Ids
	 *
	 * @return array
	 */
	function cartpops_get_active_rule_ids() {

		return cartpops_get_rule_ids( 'cartpops_active' );
	}
}

if ( ! function_exists( 'cartpops_get_rule_ids' ) ) {

	/**
	 * Get rule Ids
	 *
	 * @return array
	 */
	function cartpops_get_rule_ids( $post_status = 'all' ) {
		if ( 'all' == $post_status ) {
			$post_status = cartpops_get_rule_statuses();
		}

		$args = array(
			'post_type'      => CartPops_Register_Post_Types::RULES_POSTTYPE,
			'post_status'    => $post_status,
			'posts_per_page' => '-1',
			'fields'         => 'ids',
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
		);

		return get_posts( $args );
	}
}

if ( ! function_exists( 'cartpops_get_product_id_by_category' ) ) {

	/**
	 * Get Product IDs based on category
	 *
	 * @return array
	 */
	function cartpops_get_product_id_by_category( $category_id ) {

		if ( ! $category_id ) {
			return array();
		}

		$args = array(
			'post_type'      => 'product',
			'posts_per_page' => '-1',
			'post_status'    => 'publish',
			'cache_results'  => false,
			'tax_query'      => array(
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'id',
					'terms'    => array( $category_id ),
					'operator' => 'IN',
				),
			),
			'fields'         => 'ids',
		);

		return get_posts( $args );
	}
}

if ( ! function_exists( 'cartpops_update_rule_order_count' ) ) {

	/**
	 * Update a rule order count.
	 *
	 * @return void
	 */
	function cartpops_update_rule_order_count( $rule, $count = 1, $operation = 'set' ) {

		if ( ! is_a( $rule, 'CartPops_Rule' ) ) {
			$rule = cartpops_get_rule( $rule );
		}

		if ( ! $rule->exists() ) {
			return false;
		}

		$old_usage_count = floatval( $rule->get_rule_usage_count() );
		if ( 'decrease' == $operation ) {
			$updated_count = ( $old_usage_count ) ? $old_usage_count - $count : 0;
		} else {
			$updated_count = $old_usage_count + $count;
		}

		$rule->update_meta( 'cartpops_rule_usage_count', $updated_count );

		do_action( 'cartpops_update_rule_order_count', $rule, $operation, $count );
	}
}

if ( ! function_exists( 'cartpops_update_rule_user_usage_count' ) ) {

	/**
	 * Update a rule user usage count.
	 *
	 * @return void
	 */
	function cartpops_update_rule_user_usage_count( $user_id, $rule, $count = 1, $operation = 'set' ) {

		if ( ! $user_id ) {
			return false;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user->exists() ) {
			return false;
		}

		if ( ! is_a( $rule, 'CartPops_Rule' ) ) {
			$rule = cartpops_get_rule( $rule );
		}

		if ( ! $rule->exists() ) {
			return false;
		}

		$user_usage_count = $rule->get_rule_allowed_user_usage_count();
		// Prepare the array if user usage count not updated.
		if ( ! cartpops_check_is_array( $user_usage_count ) ) {
			$user_usage_count = array(
				$user_id => array(
					'id'    => $user_id,
					'count' => 0,
				),
			);
			// Prepare the current user array if user usage count not updated for current user.
		} elseif ( ! array_key_exists( $user_id, $user_usage_count ) ) {
			$user_usage_count[ $user_id ] = array(
				'id'    => $user_id,
				'count' => 0,
			);
		}

		if ( 'decrease' == $operation ) {
			$user_usage_count[ $user_id ]['count'] = ( $user_usage_count[ $user_id ]['count'] ) ? $user_usage_count[ $user_id ]['count'] - $count : 0;
		} else {
			$user_usage_count[ $user_id ]['count'] = $user_usage_count[ $user_id ]['count'] + $count;
		}

		$rule->update_meta( 'cartpops_rule_allowed_user_usage_count', $user_usage_count );

		do_action( 'cartpops_update_rule_user_usage_count', $user_id, $rule, $operation, $count );
	}
}
