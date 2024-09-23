<?php

/**
 * Rules Tab
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( class_exists( 'CartPops_Rules_Tab' ) ) {
	return new CartPops_Rules_Tab();
}

/**
 * CartPops_Rules_Tab.
 */
class CartPops_Rules_Tab extends CartPops_Settings_Page {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id           = 'rules';
		$this->show_buttons = false;
		$this->hide_page    = true;
		$this->label        = esc_html__( 'Upsells', 'cartpops' );
		$this->icon         = CartPops_Settings::get_admin_asset( 'menu-item-customization.svg' );

		parent::__construct();
	}

	/**
	 * Output the Rules
	 */
	public function output_extra_fields() {
		global $current_action;

		switch ( $current_action ) {
			case 'new':
				$this->display_rule_configuration_notices();
				$this->render_new_rule_page();
				break;
			case 'edit':
				$this->display_rule_configuration_notices();
				$this->render_edit_rule_page();
				break;
			default:
				$this->render_rules_table();
				break;
		}
	}

	/**
	 * Display the rule configuration notices.
	 *
	 * @return void
	 */
	public function display_rule_configuration_notices() {
		CartPops_Settings::notice( esc_html__( 'Please make sure the products you select as upsells have been published and are currently In-stock.', 'cartpops' ) );
	}

	/**
	 * Output the Rule New Page
	 */
	public function render_new_rule_page() {
		global $post_rules , $rule_data;

		$default_field_data = array(
			'cartpops_rule_status'                    => 'cartpops_active',
			'cartpops_upsell_name'                    => '',
			'cartpops_upsell_desc'                    => '',
			'cartpops_rule_type'                      => '1',
			'cartpops_upsell_type'                    => '1',
			'cartpops_upsell_products'                => array(),
			'cartpops_upsell_categories'              => array(),
			'cartpops_automatic_product_qty'          => '1',
			'cartpops_bogo_upsell_type'               => '1',
			'cartpops_buy_product_type'               => '1',
			'cartpops_buy_product'                    => array(),
			'cartpops_buy_categories'                 => array(),
			'cartpops_buy_category_type'              => '1',
			'cartpops_get_products'                   => array(),
			'cartpops_buy_product_count'              => '1',
			'cartpops_get_product_count'              => '1',
			'cartpops_bogo_upsell_repeat'             => '1',
			'cartpops_bogo_upsell_repeat_mode'        => '1',
			'cartpops_bogo_upsell_repeat_limit'       => '',
			'cartpops_apply_coupon'                   => array(),
			'cartpops_coupon_upsell_products'         => array(),
			'cartpops_coupon_upsell_products_qty'     => '1',
			'cartpops_upsell_valid_from_date'         => '',
			'cartpops_upsell_valid_to_date'           => '',
			'cartpops_rule_week_days_validation'      => array(),
			'cartpops_rule_allowed_user_type'         => '1',
			'cartpops_rule_allowed_user_count'        => '1',
			'cartpops_condition_type'                 => '1',
			'cartpops_total_type'                     => '1',
			'cartpops_cart_categories'                => array(),
			'cartpops_cart_subtotal_min_value'        => '',
			'cartpops_cart_subtotal_max_value'        => '',
			'cartpops_quantity_min_value'             => '',
			'cartpops_quantity_max_value'             => '',
			'cartpops_product_count_min_value'        => '',
			'cartpops_product_count_max_value'        => '',
			'cartpops_rule_upsells_count_per_order'   => '',
			'cartpops_rule_restriction_count'         => '',
			'cartpops_show_notice'                    => '1',
			'cartpops_notice'                         => '',
			'cartpops_user_filter_type'               => '1',
			'cartpops_include_users'                  => array(),
			'cartpops_exclude_users'                  => array(),
			'cartpops_exclude_user_roles'             => array(),
			'cartpops_include_user_roles'             => array(),
			'cartpops_product_filter_type'            => '1',
			'cartpops_include_products'               => array(),
			'cartpops_include_product_count'          => '1',
			'cartpops_exclude_products'               => array(),
			'cartpops_applicable_products_type'       => '1',
			'cartpops_applicable_categories_type'     => '1',
			'cartpops_include_categories'             => array(),
			'cartpops_include_category_product_count' => '1',
			'cartpops_exclude_categories'             => array(),
		);

		// may be sanitize post data
		$rule_post_data = isset( $post_rules ) ? $post_rules : array() ;  // @codingStandardsIgnoreLine.

		$rule_data = wp_parse_args( $rule_post_data, $default_field_data );

		// Html for New Rule Page
		include_once CARTPOPS_PATH . '/admin/menu/views/add-new-rule.php';
	}

	/**
	 * Output the Rule Edit Page
	 */
	public function render_edit_rule_page() {

		global $post_rules , $rule_data;

		if ( ! isset( $_GET[ 'id' ] ) ) { // @codingStandardsIgnoreLine.
			return;
		}

		$rule_id = absint( $_GET[ 'id' ] ) ; // @codingStandardsIgnoreLine.
		$rule    = cartpops_get_rule( $rule_id );

		if ( ! $rule->exists() ) {
			return;
		}

		$default_field_data = array(
			'id'                                      => $rule->get_id(),
			'cartpops_rule_status'                    => $rule->get_status(),
			'cartpops_upsell_name'                    => $rule->get_name(),
			'cartpops_upsell_desc'                    => $rule->get_description(),
			'cartpops_rule_type'                      => $rule->get_rule_type(),
			'cartpops_upsell_type'                    => $rule->get_upsell_type(),
			'cartpops_upsell_products'                => $rule->get_upsell_products(),
			'cartpops_upsell_categories'              => $rule->get_upsell_categories(),
			'cartpops_automatic_product_qty'          => $rule->get_automatic_product_qty(),
			'cartpops_buy_product_type'               => $rule->get_buy_product_type(),
			'cartpops_bogo_upsell_type'               => $rule->get_bogo_upsell_type(),
			'cartpops_buy_product'                    => $rule->get_buy_product(),
			'cartpops_buy_categories'                 => $rule->get_buy_categories(),
			'cartpops_buy_category_type'              => $rule->get_buy_category_type(),
			'cartpops_get_products'                   => $rule->get_products(),
			'cartpops_buy_product_count'              => $rule->get_buy_product_count(),
			'cartpops_get_product_count'              => $rule->get_product_count(),
			'cartpops_bogo_upsell_repeat'             => $rule->get_bogo_upsell_repeat(),
			'cartpops_bogo_upsell_repeat_mode'        => $rule->get_bogo_upsell_repeat_mode(),
			'cartpops_bogo_upsell_repeat_limit'       => $rule->get_bogo_upsell_repeat_limit(),
			'cartpops_apply_coupon'                   => $rule->get_apply_coupon(),
			'cartpops_coupon_upsell_products'         => $rule->get_coupon_upsell_products(),
			'cartpops_coupon_upsell_products_qty'     => $rule->get_coupon_upsell_products_qty(),
			'cartpops_upsell_valid_from_date'         => $rule->get_rule_valid_from_date(),
			'cartpops_upsell_valid_to_date'           => $rule->get_rule_valid_to_date(),
			'cartpops_rule_week_days_validation'      => $rule->get_rule_week_days_validation(),
			'cartpops_rule_upsells_count_per_order'   => $rule->get_rule_upsells_count_per_order(),
			'cartpops_rule_restriction_count'         => $rule->get_rule_restriction_count(),
			'cartpops_rule_usage_count'               => floatval( $rule->get_rule_usage_count() ),
			'cartpops_rule_allowed_user_type'         => $rule->get_rule_allowed_user_type(),
			'cartpops_rule_allowed_user_count'        => floatval( $rule->get_rule_allowed_user_count() ),
			'cartpops_condition_type'                 => $rule->get_condition_type(),
			'cartpops_total_type'                     => $rule->get_total_type(),
			'cartpops_cart_categories'                => $rule->get_cart_categories(),
			'cartpops_cart_subtotal_min_value'        => $rule->get_cart_subtotal_minimum_value(),
			'cartpops_cart_subtotal_max_value'        => $rule->get_cart_subtotal_maximum_value(),
			'cartpops_quantity_min_value'             => $rule->get_quantity_minimum_value(),
			'cartpops_quantity_max_value'             => $rule->get_quantity_maximum_value(),
			'cartpops_product_count_min_value'        => $rule->get_product_count_min_value(),
			'cartpops_product_count_max_value'        => $rule->get_product_count_max_value(),
			'cartpops_show_notice'                    => $rule->get_show_notice(),
			'cartpops_notice'                         => $rule->get_notice(),
			'cartpops_user_filter_type'               => $rule->get_user_filter_type(),
			'cartpops_include_users'                  => $rule->get_include_users(),
			'cartpops_exclude_users'                  => $rule->get_exclude_users(),
			'cartpops_exclude_user_roles'             => $rule->get_exclude_user_roles(),
			'cartpops_include_user_roles'             => $rule->get_include_user_roles(),
			'cartpops_product_filter_type'            => $rule->get_product_filter_type(),
			'cartpops_include_products'               => $rule->get_include_products(),
			'cartpops_include_product_count'          => $rule->get_include_product_count(),
			'cartpops_exclude_products'               => $rule->get_exclude_products(),
			'cartpops_applicable_products_type'       => $rule->get_applicable_products_type(),
			'cartpops_applicable_categories_type'     => $rule->get_applicable_categories_type(),
			'cartpops_include_categories'             => $rule->get_include_categories(),
			'cartpops_include_category_product_count' => $rule->get_include_category_product_count(),
			'cartpops_exclude_categories'             => $rule->get_exclude_categories(),
		);

		// may be sanitize post data
		$rule_post_data = isset( $post_rules ) ? $post_rules : array();

		$rule_data = wp_parse_args( $rule_post_data, $default_field_data );

		// Html for Edit Rule Page
		include_once CARTPOPS_PATH . '/admin/menu/views/edit-rule.php';
	}

	/**
	 * Output the Rules WP List Table
	 */
	public function render_rules_table() {
		if ( ! class_exists( 'CartPops_Rules_List_Table' ) ) {
			require_once CARTPOPS_PATH . '/admin/menu/wp-list-table/class-cartpops-rules-list-table.php';
		}

		echo '<div class="cartpops_table_wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Upsell Rules', 'cartpops' ) . '</h1>';
		echo '<a class="page-title-action cartpops_add_btn" href="' . esc_url( cartpops_get_rule_page_url( array( 'action' => 'new' ) ) ) . '">' . esc_html__( 'Add New Rule', 'cartpops' ) . '</a>';
		echo '<hr class="wp-header-end">';
		$post_table = new CartPops_Rules_List_Table();
		$post_table->render();
		echo '</div>';
	}

	/**
	 * Show the rule panel.
	 *
	 * @return void
	 */
	private static function output_panel() {
		global $rule_data;

		include_once CARTPOPS_PATH . '/admin/menu/views/rule-panel/html-rule-data-panel.php';
	}

	/**
	 * Show the rule panel tabs.
	 *
	 * @return void
	 */
	private static function output_tabs() {
		global $rule_data;

		include_once CARTPOPS_PATH . '/admin/menu/views/rule-panel/html-rule-data-general.php';
		include_once CARTPOPS_PATH . '/admin/menu/views/rule-panel/html-rule-data-notices.php';
		include_once CARTPOPS_PATH . '/admin/menu/views/rule-panel/html-rule-data-restrictions.php';
		include_once CARTPOPS_PATH . '/admin/menu/views/rule-panel/html-rule-data-criteria.php';
		include_once CARTPOPS_PATH . '/admin/menu/views/rule-panel/html-rule-data-filters.php';
	}

	/**
	 * Output sidebar.
	 */
	public function output_sidebar() {
		CartPops_Settings::get_upgrade_card();
	}

	/**
	 * Return array of tabs.
	 *
	 * @return array
	 */
	private static function get_rule_data_tabs() {
		$tabs = apply_filters(
			'cartpops_rule_data_tabs',
			array(
				'general'      => array(
					'label'    => esc_html__( 'General', 'cartpops' ),
					'target'   => 'cartpops_rule_data_general',
					'class'    => array(),
					'priority' => 10,
				),
				'restrictions' => array(
					'label'    => esc_html__( 'Restrictions', 'cartpops' ),
					'target'   => 'cartpops_rule_data_restrictions',
					'class'    => array(),
					'priority' => 20,
				),
				'criteria'     => array(
					'label'    => esc_html__( 'Criteria', 'cartpops' ),
					'target'   => 'cartpops_rule_data_criteria',
					'class'    => array(),
					'priority' => 30,
				),
				'filters'      => array(
					'label'    => esc_html__( 'Filters', 'cartpops' ),
					'target'   => 'cartpops_rule_data_filters',
					'class'    => array(),
					'priority' => 40,
				),
				'notices'      => array(
					'label'    => esc_html__( 'Notice', 'cartpops' ),
					'target'   => 'cartpops_rule_data_notices',
					'class'    => array(),
					'priority' => 50,
				),
			)
		);

		// Sort tabs based on priority.
		uasort( $tabs, array( __CLASS__, 'rule_data_tabs_sort' ) );

		return $tabs;
	}

	/**
	 * Callback to sort tabs on priority.
	 *
	 * @return boolean
	 */
	private static function rule_data_tabs_sort( $a, $b ) {
		if ( ! isset( $a['priority'], $b['priority'] ) ) {
			return -1;
		}

		if ( $a['priority'] === $b['priority'] ) {
			return 0;
		}

		return $a['priority'] < $b['priority'] ? -1 : 1;
	}

	/**
	 * Save settings.
	 */
	public function save() {
		global $current_action , $post_rules;

		// Show success message
		if ( isset( $_GET['message'] ) && 'success' == sanitize_title( $_GET['message'] ) ) {
			CartPops_Settings::add_message( esc_html__( 'New Rule has been created successfuly', 'cartpops' ) );
		}

		$post_rules = ! empty( $_REQUEST['cartpops_rule'] ) ? wc_clean( wp_unslash( ( $_REQUEST['cartpops_rule'] ) ) ) : $post_rules;

		if ( ! isset( $_REQUEST['cartpops_save'] ) ) {
			return;
		}

		switch ( $current_action ) {
			case 'new':
				$this->create_new_rule();
				break;
			case 'edit':
				$this->update_rule();
				break;
		}
	}

	/**
	 * Create a new rule.
	 */
	public function create_new_rule() {
		check_admin_referer( 'cartpops_new_rule', '_cartpops_nonce' );

		try {

			$rule_post_data = self::prepare_rule_data();

			$post_args = array(
				'post_status'  => $rule_post_data['cartpops_rule_status'],
				'post_title'   => $rule_post_data['cartpops_upsell_name'],
				'post_content' => $rule_post_data['cartpops_upsell_desc'],
				'menu_order'   => 99999,
			);

			$rule_id = cartpops_create_new_rule( $rule_post_data, $post_args );

			do_action( 'cartpops_after_created_new_rule', $rule_id, $rule_post_data );

			unset( $_POST['cartpops_rule'] );

			wp_safe_redirect(
				cartpops_get_rule_page_url(
					array(
						'action'  => 'edit',
						'id'      => $rule_id,
						'message' => 'success',
					)
				)
			);
			exit();
		} catch ( Exception $ex ) {
			CartPops_Settings::add_error( $ex->getMessage() );
		}
	}

	/**
	 * Update the rule.
	 */
	public function update_rule() {

		check_admin_referer( 'cartpops_update_rule', '_cartpops_nonce' );

		try {

			$rule_id        = ! empty( $_POST[ 'cartpops_rule_id' ] ) ? absint( $_POST[ 'cartpops_rule_id' ] ) : 0 ; // @codingStandardsIgnoreLine.
			$rule_post_data = self::prepare_rule_data();

			$post_args = array(
				'post_status'  => $rule_post_data['cartpops_rule_status'],
				'post_title'   => $rule_post_data['cartpops_upsell_name'],
				'post_content' => $rule_post_data['cartpops_upsell_desc'],
			);

			cartpops_update_rule( $rule_id, $rule_post_data, $post_args );

			do_action( 'cartpops_after_updated_rule', $rule_id, $rule_post_data );

			unset( $_POST['cartpops_rule'] );

			CartPops_Settings::add_message( esc_html__( 'Rule has been updated successfully', 'cartpops' ) );
		} catch ( Exception $ex ) {
			CartPops_Settings::add_error( $ex->getMessage() );
		}
	}

	/**
	 * Prepare the rule data.
	 *
	 * @return array
	 */
	public function prepare_rule_data() {
		$rule_post_data = ! empty( $_REQUEST['cartpops_rule'] ) ? wc_clean( wp_unslash( ( $_REQUEST['cartpops_rule'] ) ) ) : array();

		// Validate rule name
		if ( '' == $rule_post_data['cartpops_upsell_name'] ) {
			throw new Exception( esc_html__( 'Rule Name is mandatory', 'cartpops' ) );
		}

		$upsell_categories      = isset( $rule_post_data['cartpops_upsell_categories'] ) ? $rule_post_data['cartpops_upsell_categories'] : array();
		$upsell_products        = isset( $rule_post_data['cartpops_upsell_products'] ) ? $rule_post_data['cartpops_upsell_products'] : array();
		$include_products       = isset( $rule_post_data['cartpops_include_products'] ) ? $rule_post_data['cartpops_include_products'] : array();
		$include_categories     = isset( $rule_post_data['cartpops_include_categories'] ) ? $rule_post_data['cartpops_include_categories'] : array();
		$buy_product            = isset( $rule_post_data['cartpops_buy_product'] ) ? $rule_post_data['cartpops_buy_product'] : array();
		$buy_categories         = isset( $rule_post_data['cartpops_buy_categories'] ) ? $rule_post_data['cartpops_buy_categories'] : array();
		$get_products           = isset( $rule_post_data['cartpops_get_products'] ) ? $rule_post_data['cartpops_get_products'] : array();
		$apply_coupon           = isset( $rule_post_data['cartpops_apply_coupon'] ) ? $rule_post_data['cartpops_apply_coupon'] : array();
		$coupon_upsell_products = isset( $rule_post_data['cartpops_coupon_upsell_products'] ) ? $rule_post_data['cartpops_coupon_upsell_products'] : array();

		$rule_products = array();

		switch ( $rule_post_data['cartpops_rule_type'] ) {
			case '4':
				if ( empty( $apply_coupon ) ) {
					throw new Exception( esc_html__( 'Please select a coupon', 'cartpops' ) );
				}

				if ( empty( $coupon_upsell_products ) ) {
					throw new Exception( esc_html__( 'Please select atleast one product', 'cartpops' ) );
				} else {
					$rule_products = $coupon_upsell_products;
				}

				if ( empty( $rule_post_data['cartpops_coupon_upsell_products_qty'] ) ) {
					throw new Exception( esc_html__( 'Quantity for Selected Upsell Product(s)cannot be empty', 'cartpops' ) );
				}
				break;
			case '3':
				// Validate if the buy product is not selected.
				if ( '1' == $rule_post_data['cartpops_buy_product_type'] && empty( $buy_product ) ) {
					throw new Exception( esc_html__( 'Please select atleast one Product for buy product', 'cartpops' ) );
				} elseif ( '2' == $rule_post_data['cartpops_buy_product_type'] && empty( $buy_categories ) ) {
					throw new Exception( esc_html__( 'Please select atleast one Category for buy product', 'cartpops' ) );
				}

				// Validate if the get product is not selected..
				if ( '2' == $rule_post_data['cartpops_bogo_upsell_type'] && empty( $get_products ) ) {
					throw new Exception( esc_html__( 'Please select atleast one Product for get product', 'cartpops' ) );
				}

				// Validate if the buy product count does not exist.
				if ( empty( $rule_post_data['cartpops_buy_product_count'] ) ) {
					throw new Exception( esc_html__( 'Buy Product count cannot be empty.', 'cartpops' ) );
				}

				// Validate if the get product count does not exist.
				if ( empty( $rule_post_data['cartpops_get_product_count'] ) ) {
					throw new Exception( esc_html__( 'Get Product count cannot be empty.', 'cartpops' ) );
				}

				// Validate if the repeat count does not exist.
				if ( isset( $rule_post_data['cartpops_bogo_upsell_repeat'] ) && '2' == $rule_post_data['cartpops_bogo_upsell_repeat_mode'] && empty( $rule_post_data['cartpops_bogo_upsell_repeat_limit'] ) ) {
					throw new Exception( esc_html__( 'Repeat Limit field cannot be empty.', 'cartpops' ) );
				}

				if ( '2' == $rule_post_data['cartpops_bogo_upsell_type'] && ! empty( $get_products ) ) {
					$rule_products = $get_products;
				}
				break;

			case '2':
				if ( empty( $upsell_products ) ) {
					throw new Exception( esc_html__( 'Please select atleast one product', 'cartpops' ) );
				} else {
					$rule_products = $upsell_products;
				}

				if ( empty( $rule_post_data['cartpops_automatic_product_qty'] ) ) {
					throw new Exception( esc_html__( 'Quantity for Selected Upsell Product(s)cannot be empty', 'cartpops' ) );
				}
				break;

			default:
				if ( '2' == $rule_post_data['cartpops_upsell_type'] && empty( $upsell_categories ) ) {
					throw new Exception( esc_html__( 'Please select atleast one category', 'cartpops' ) );
				} elseif ( '1' == $rule_post_data['cartpops_upsell_type'] && empty( $upsell_products ) ) {
					throw new Exception( esc_html__( 'Please select atleast one product', 'cartpops' ) );
				}

				if ( '1' == $rule_post_data['cartpops_upsell_type'] && ! empty( $upsell_products ) ) {
					$rule_products = $upsell_products;
				}
				break;
		}

		// Validate the Products is purchasable.
		$non_purchasable_product = self::get_non_purchasable_product( $rule_products );
		if ( $non_purchasable_product ) {
			/* translators: %s: products */
			throw new Exception( sprintf( esc_html__( 'The selected product(s) %s cannot be set as upsells. Please make sure the products you select as upsells have been published and are currently In-stock.', 'cartpops' ), $non_purchasable_product ) );
		}

		// Validate the include Products/Category selection.
		if ( '2' == $rule_post_data['cartpops_product_filter_type'] && empty( $include_products ) ) {
			throw new Exception( esc_html__( 'Please select atleast one Product', 'cartpops' ) );
		} elseif ( '5' == $rule_post_data['cartpops_product_filter_type'] && empty( $include_categories ) ) {
			throw new Exception( esc_html__( 'Please select atleast one Category', 'cartpops' ) );
		}

		// Validate the product count selection.
		if ( '2' == $rule_post_data['cartpops_product_filter_type'] && '4' == $rule_post_data['cartpops_applicable_products_type'] ) {
			if ( $rule_post_data['cartpops_include_product_count'] && count( $include_products ) < $rule_post_data['cartpops_include_product_count'] ) {
				throw new Exception( esc_html__( 'Product Count cannot be more than the number of selected products.', 'cartpops' ) );
			} elseif ( ! $rule_post_data['cartpops_include_product_count'] ) {
				throw new Exception( esc_html__( 'Product Count cannot be empty.', 'cartpops' ) );
			}
		}

		// Validate the categories product count selection.
		if ( '5' == $rule_post_data['cartpops_product_filter_type'] && '4' == $rule_post_data['cartpops_applicable_categories_type'] && ! $rule_post_data['cartpops_include_category_product_count'] ) {
			throw new Exception( esc_html__( 'Minimum Quantity field cannot be empty.', 'cartpops' ) );
		}

		$rule_post_data['cartpops_cart_subtotal_min_value'] = wc_format_decimal( $rule_post_data['cartpops_cart_subtotal_min_value'] );
		$rule_post_data['cartpops_cart_subtotal_max_value'] = wc_format_decimal( $rule_post_data['cartpops_cart_subtotal_max_value'] );
		$rule_post_data['cartpops_notice']                  = isset( $_REQUEST['cartpops_rule']['cartpops_notice'] ) ? wp_kses_post( $_REQUEST['cartpops_rule']['cartpops_notice'] ) : '';

		// Validate Select2 values.
		$rule_post_data['cartpops_upsell_categories']         = $upsell_categories;
		$rule_post_data['cartpops_upsell_products']           = $upsell_products;
		$rule_post_data['cartpops_buy_product']               = $buy_product;
		$rule_post_data['cartpops_buy_categories']            = $buy_categories;
		$rule_post_data['cartpops_get_products']              = $get_products;
		$rule_post_data['cartpops_apply_coupon']              = $apply_coupon;
		$rule_post_data['cartpops_coupon_upsell_products']    = $coupon_upsell_products;
		$rule_post_data['cartpops_bogo_upsell_repeat']        = isset( $rule_post_data['cartpops_bogo_upsell_repeat'] ) ? '2' : '1';
		$rule_post_data['cartpops_include_users']             = isset( $rule_post_data['cartpops_include_users'] ) ? $rule_post_data['cartpops_include_users'] : array();
		$rule_post_data['cartpops_exclude_users']             = isset( $rule_post_data['cartpops_exclude_users'] ) ? $rule_post_data['cartpops_exclude_users'] : array();
		$rule_post_data['cartpops_include_user_roles']        = isset( $rule_post_data['cartpops_include_user_roles'] ) ? $rule_post_data['cartpops_include_user_roles'] : array();
		$rule_post_data['cartpops_exclude_user_roles']        = isset( $rule_post_data['cartpops_exclude_user_roles'] ) ? $rule_post_data['cartpops_exclude_user_roles'] : array();
		$rule_post_data['cartpops_include_products']          = $include_products;
		$rule_post_data['cartpops_exclude_products']          = isset( $rule_post_data['cartpops_exclude_products'] ) ? $rule_post_data['cartpops_exclude_products'] : array();
		$rule_post_data['cartpops_cart_categories']           = isset( $rule_post_data['cartpops_cart_categories'] ) ? $rule_post_data['cartpops_cart_categories'] : array();
		$rule_post_data['cartpops_rule_week_days_validation'] = isset( $rule_post_data['cartpops_rule_week_days_validation'] ) ? $rule_post_data['cartpops_rule_week_days_validation'] : array();

		return $rule_post_data;
	}

	/**
	 * Get the non purchasable product.
	 *
	 * @return false/array
	 */
	public function get_non_purchasable_product( $product_ids ) {

		if ( ! cartpops_check_is_array( $product_ids ) ) {
			return false;
		}

		$non_puchasable_products = array();

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! is_object( $product ) || ( 'publish' === $product->get_status() && $product->is_purchasable() && $product->is_in_stock() ) ) {
				continue;
			}

			$non_puchasable_products[] = '<a href="' . get_edit_post_link( $product_id ) . '">' . $product->get_name() . '</a>';
		}

		if ( ! cartpops_check_is_array( $non_puchasable_products ) ) {
			return false;
		}

		return implode( ', ', $non_puchasable_products );
	}

}

return new CartPops_Rules_Tab();
