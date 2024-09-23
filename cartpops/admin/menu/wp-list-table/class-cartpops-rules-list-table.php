<?php

/**
 * Rules List Table.
 */
if (! defined('ABSPATH') ) {
    exit ; // Exit if accessed directly.
}

if (! class_exists('WP_List_Table') ) {
    include_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php' ;
}

if (! class_exists('CartPops_Rules_List_Table') ) {

    /**
     * CartPops_Rules_List_Table Class.
     * */
    class CartPops_Rules_List_Table extends WP_List_Table
    {

        /**
         * Per page count.
         *
         * @var int
         * */
        private $perpage = 10 ;

        /**
         * Database.
         *
         * @var object
         * */
        private $database ;

        /**
         * Offset.
         *
         * @var int
         * */
        private $offset ;

        /**
         * Order BY.
         *
         * @var string
         * */
        private $orderby = 'ORDER BY menu_order ASC,ID ASC' ;

        /**
         * Post type.
         *
         * @var string
         * */
        private $post_type = CartPops_Register_Post_Types::RULES_POSTTYPE ;

        /**
         * List Slug.
         *
         * @var string
         * */
        private $list_slug = 'cartpops_rules' ;

        /**
         * Base URL.
         *
         * @var string
         * */
        private $base_url ;

        /**
         * Current URL.
         *
         * @var string
         * */
        private $current_url ;

        /**
         * Constructor.
         */
        public function __construct()
        {

            global $wpdb ;
            $this->database = &$wpdb ;

            // Prepare the required data.
            $this->base_url = cartpops_get_rule_page_url();

            parent::__construct(
                array(
                'singular' => 'rule' ,
                'plural'   => 'rules' ,
                'ajax'     => false ,
                )
            );
        }

        /**
         * Prepares the list of items for displaying.
         * */
        public function prepare_items()
        {

            // Prepare the current url.
            $this->current_url = add_query_arg(array( 'paged' => absint($this->get_pagenum()) ), $this->base_url);

            // Prepare the bulk actions.
            $this->process_bulk_action();

            // Prepare the offset.
            $this->offset = $this->perpage * ( absint($this->get_pagenum()) - 1 ) ;

            // Prepare the header columns.
            $this->_column_headers = array( $this->get_columns() , $this->get_hidden_columns() , $this->get_sortable_columns() ) ;

            // Prepare the query clauses.
            $join    = $this->get_query_join();
            $where   = $this->get_query_where();
            $limit   = $this->get_query_limit();
            $offset  = $this->get_query_offset();
            $orderby = $this->get_query_orderby();

            // Prepare the all items.
            $count_items = $this->database->get_var('SELECT COUNT(DISTINCT ID) FROM ' . $this->database->posts . " AS p $where $orderby");

            // Prepare the current page items.
            $prepare_query = $this->database->prepare('SELECT DISTINCT ID FROM ' . $this->database->posts . " AS p $join $where $orderby LIMIT %d,%d", $offset, $limit);

            $items = $this->database->get_results($prepare_query, ARRAY_A);

            // Prepare the item object.
            $this->prepare_item_object($items);

            // Prepare the pagination arguments.
            $this->set_pagination_args(
                array(
                'total_items' => $count_items ,
                'per_page'    => $this->perpage ,
                )
            );
        }

        /**
         * Render the table.
         * */
        public function render()
        {
         if ( isset( $_REQUEST[ 's' ] ) && strlen( wc_clean( wp_unslash( $_REQUEST[ 's' ] ) ) ) ) { // @codingStandardsIgnoreLine.
                /* translators: %s: search keywords */
                echo wp_kses_post(sprintf('<span class="subtitle">' . esc_html__('Search results for &#8220;%s&#8221;', 'cartpops') . '</span>', wc_clean(wp_unslash($_REQUEST[ 's' ]))));
            }

            // Output the table.
            $this->prepare_items();
            $this->views();
            $this->search_box(esc_html__('Search Rule', 'cartpops'), 'cartpops-rules');
            $this->display();
        }

        /**
         * Get a list of columns.
         *
         * @return array
         * */
        public function get_columns()
        {
            $columns = array(
            'cb'               => '<input type="checkbox" />' , // Render a checkbox instead of text
            'rule_name'        => esc_html__('Rule Name', 'cartpops') ,
            'status'           => esc_html__('Status', 'cartpops') ,
            'validity'         => esc_html__('Validity', 'cartpops') ,
            'type'             => esc_html__('Type', 'cartpops') ,
            'product_category' => esc_html__('Product(s) / Categories', 'cartpops') ,
            'created_date'     => esc_html__('Created Date', 'cartpops') ,
            'modified_date'    => esc_html__('Last Modified Date', 'cartpops') ,
            'actions'          => esc_html__('Actions', 'cartpops') ,
            ) ;

            if (! isset($_REQUEST[ 'post_status' ]) && ! isset($_REQUEST[ 's' ]) ) {
                $columns[ 'sort' ] = '<img src="' . esc_url( CartPops_Settings::get_admin_asset( 'drag-icon.png' ) ) . '" title="' . esc_html__('Sort', 'cartpops') . '"></img>' ;
            }

            return apply_filters($this->list_slug . '_get_columns', $columns);
        }

        /**
         * Get a list of hidden columns.
         *
         * @return array
         * */
        public function get_hidden_columns()
        {
            return apply_filters($this->list_slug . '_hidden_columns', array());
        }

        /**
         * Get a list of sortable columns.
         *
         * @return void
         * */
        public function get_sortable_columns()
        {
            return apply_filters(
                $this->list_slug . '_sortable_columns', array(
                'rule_name'     => array( 'rule_name' , false ) ,
                'status'        => array( 'status' , false ) ,
                'created_date'  => array( 'created' , false ) ,
                'modified_date' => array( 'modified' , false )
                )
            );
        }

        /**
         * Message to be displayed when there are no items.
         */
        public function no_items()
        {
            esc_html_e('No rule to show.', 'cartpops');
        }

        /**
         * Get a list of bulk actions.
         *
         * @return array
         * */
        protected function get_bulk_actions()
        {
            $action = array() ;

            $action[ 'active' ]   = esc_html__('Activate', 'cartpops');
            $action[ 'inactive' ] = esc_html__('Deactivate', 'cartpops');
            $action[ 'delete' ]   = esc_html__('Delete', 'cartpops');

            return apply_filters($this->list_slug . '_bulk_actions', $action);
        }

        /**
         * Display the list of views available on this table.
         *
         * @return array
         * */
        public function get_views()
        {
            $args        = array() ;
            $status_link = array() ;

            $status_link_array = apply_filters(
                $this->list_slug . '_get_views', array(
                'all'          => esc_html__('All', 'cartpops') ,
                'cartpops_active'   => esc_html__('Active', 'cartpops') ,
                'cartpops_inactive' => esc_html__('In-Active', 'cartpops') ,
                )
            );

            foreach ( $status_link_array as $status_name => $status_label ) {
                $status_count = $this->get_total_item_for_status($status_name);

                if (! $status_count ) {
                    continue ;
                }

                $args[ 'status' ] = $status_name ;

                $label = $status_label . ' (' . $status_count . ')' ;

                $class = array( strtolower($status_name) ) ;
             if ( isset( $_GET[ 'status' ] ) && ( sanitize_title( $_GET[ 'status' ] ) == $status_name ) ) { // @codingStandardsIgnoreLine.
                    $class[] = 'current' ;
                }

             if ( ! isset( $_GET[ 'status' ] ) && 'all' == $status_name ) { // @codingStandardsIgnoreLine.
                    $class[] = 'current' ;
                }

                $status_link[ $status_name ] = $this->get_edit_link($args, $label, implode(' ', $class));
            }

            return $status_link ;
        }

        /**
         * Get a edit link.
         *
         * @rerurn string
         * */
        private function get_edit_link( $args, $label, $class = '' )
        {
            $url        = add_query_arg($args, $this->base_url);
            $class_html = '' ;
            if (! empty($class) ) {
                $class_html = sprintf(
                    ' class="%s"', esc_attr($class)
                );
            }

            return sprintf(
                '<a href="%s"%s>%s</a>', esc_url($url), $class_html, $label
            );
        }

        /**
         * Get the total item by status.
         *
         * @return int
         * */
        private function get_total_item_for_status( $status = '' )
        {

            // Get the current status item ids.
            $prepare_query = $this->database->prepare('SELECT COUNT(DISTINCT ID) FROM ' . $this->database->posts . " WHERE post_type=%s and post_status IN('" . $this->format_status($status) . "')", $this->post_type);

            return $this->database->get_var($prepare_query);
        }

        /**
         * Format the status.
         *
         * @return string
         * */
        private function format_status( $status )
        {

            if ('all' == $status ) {
                $statuses = cartpops_get_rule_statuses();
                $status   = implode("', '", $statuses);
            }

            return $status ;
        }

        /**
         * Bulk action functionality
         * */
        public function process_bulk_action()
        {

         $ids = isset( $_REQUEST[ 'id' ] ) ? wc_clean( wp_unslash( ( $_REQUEST[ 'id' ] ) ) ) : array() ; // @codingStandardsIgnoreLine.
            $ids = ! is_array($ids) ? explode(',', $ids) : $ids ;

            if (! cartpops_check_is_array($ids) ) {
                return ;
            }

            if (! current_user_can('edit_posts') ) {
                wp_die('<p class="error">' . esc_html__('Sorry, you are not allowed to edit this item.', 'cartpops') . '</p>');
            }

            $action = $this->current_action();

            foreach ( $ids as $id ) {
                if ('delete' === $action ) {
                    wp_delete_post($id, true);
                } elseif ('active' === $action ) {
                    cartpops_update_rule($id, array(), array( 'post_status' => 'cartpops_active' ));
                } elseif ('inactive' === $action ) {
                    cartpops_update_rule($id, array(), array( 'post_status' => 'cartpops_inactive' ));
                }
            }

            wp_safe_redirect($this->current_url);
            exit();
        }

        /**
         * Prepare the CB column data.
         *
         * @return string
         * */
        protected function column_cb( $item )
        {
            return sprintf(
                '<input type="checkbox" name="id[]" value="%s" />', $item->get_id()
            );
        }

        /**
         * Prepare a each column data.
         *
         * @return mixed
         * */
        protected function column_default( $item, $column_name )
        {

            switch ( $column_name ) {

            case 'rule_name':
                return '<a href="' . esc_url(
                    add_query_arg(
                        array(
                                        'action' => 'edit' ,
                                        'id'     => $item->get_id() ,
                                            ), $this->base_url
                    )
                ) . '">' . esc_html($item->get_name()) . '</a>' ;
              break ;

            case 'status':
                return cartpops_display_status($item->get_status());

                    break ;

            case 'type':
                return cartpops_get_rule_type_name($item->get_rule_type());

                    break ;

            case 'validity':
                $from = ! empty($item->get_rule_valid_from_date()) ? $item->get_rule_valid_from_date() : '-' ;
                $to   = ! empty($item->get_rule_valid_to_date()) ? $item->get_rule_valid_to_date() : '-' ;

                if ('-' === $from && '-' === $to ) {
                    return esc_html__('Unlimited', 'cartpops');
                } elseif ('-' === $to ) {
                    $to = esc_html__('Unlimited', 'cartpops');
                }

                return esc_html__('From', 'cartpops') . '&nbsp:&nbsp&nbsp' . $from . '<br />' . esc_html__('To', 'cartpops') . '&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp:&nbsp&nbsp' . $to ;
                    break ;

            case 'product_category':
                return $this->render_product_category($item);
                    break ;

            case 'created_date':
                return $item->get_formatted_created_date();
                    break ;

            case 'modified_date':
                return $item->get_formatted_modified_date();
                    break ;

            case 'actions':
                $actions       = array() ;
                $status_action = ( $item->get_status() == 'cartpops_inactive' ) ? 'active' : 'inactive' ;

                $actions[ 'edit' ]         = cartpops_display_action('edit', $item->get_id(), $this->current_url, true);
                $actions[ $status_action ] = cartpops_display_action($status_action, $item->get_id(), $this->current_url);
                $actions[ 'delete' ]       = cartpops_display_action('delete', $item->get_id(), $this->current_url);

                end($actions);

                $last_key = key($actions);
                $views    = '' ;
                foreach ( $actions as $key => $action ) {
                    $views .= $action ;

                    if ($last_key == $key ) {
                        break ;
                    }

                    $views .= ' | ' ;
                }

                return $views ;

                    break ;

            case 'sort':
                return '<div class = "cartpops_post_sort_handle">'
                            . '<img src = "' . esc_url( CartPops_Settings::get_admin_asset( 'drag-icon.png' ) ) . '" title="' . esc_html__('Sort', 'cartpops') . '"></img>'
                            . '<input type = "hidden" class = "cartpops_rules_sortable" value = "' . $item->get_id() . '" />'
                            . '</div>' ;
                    break ;
            }
        }

        /**
         * Render the product category details.
         *
         * @return string
         */
        private function render_product_category( $item )
        {


            if ('4' == $item->get_rule_type() ) {

                $coupon_products = esc_html__('Coupon Code to Apply', 'cartpops');

                $coupons_link  = $this->get_coupons_link($item->get_apply_coupon());
                $products      = $this->get_products_link($item->get_coupon_upsell_products());
                $products_link = esc_html__('Upsell Product(s)', 'cartpops') . ' ' . $products ;

                $coupon_products .= '<br ><b>' . $coupons_link . '</b><br />' . $products_link ;

                return $coupon_products ;
            } elseif ('3' == $item->get_rule_type() ) {

                $buy_products_label = esc_html__('Buy Product(s)', 'cartpops');
                if ('2' == $item->get_buy_product_type() ) {
                    $buy_products_link = $this->get_categories_link($item->get_buy_categories());
                    $buy_products_link = esc_html__('Product(s) of', 'cartpops') . ' ' . $buy_products_link ;
                } else {
                    $buy_products_link = $this->get_products_link($item->get_buy_product());
                }

                $bogo_products      = '<b><u>' . $buy_products_label . '</u></b><br />' . $buy_products_link ;
                $get_products_label = esc_html__('Get Product(s)', 'cartpops');

                if ('2' == $item->get_bogo_upsell_type() ) {
                    $get_products_link = $this->get_products_link($item->get_products());
                } else {
                    $get_products_link = $buy_products_link ;
                }

                $bogo_products .= '<br ><b><u>' . $get_products_label . '</u></b><br />' . $get_products_link ;

                return $bogo_products ;
            } elseif ('2' == $item->get_upsell_type() && '2' != $item->get_rule_type() ) {

                $categories_link = $this->get_categories_link($item->get_upsell_categories());

                return '<b><u>' . esc_html__('Categories', 'cartpops') . '</u></b><br />' . $categories_link ;
            } else {
                $products_link = $this->get_products_link($item->get_upsell_products());

                return '<b><u>' . esc_html__('Product(s)', 'cartpops') . '</u></b><br />' . $products_link ;
            }
        }

        /**
         * Products Link.
         *
         * @return string
         * */
        private function get_products_link( $product_ids )
        {
            $products_link = '' ;

            foreach ( $product_ids as $product_id ) {
                $product = wc_get_product($product_id);

                //Return if the product does not exist.
                if (! $product ) {
                    continue ;
                }

                $products_link .= '<a href="' . esc_url(
                    add_query_arg(
                        array(
                        'post'   => $product_id ,
                        'action' => 'edit' ,
                        ), admin_url('post.php')
                    )
                ) . '" >' . $product->get_name() . '</a> , ' ;
            }

            return rtrim($products_link, ' , ');
        }

        /**
         * Categories Link.
         *
         * @return string
         * */
        private function get_categories_link( $categories_ids )
        {
            $categories_link = '' ;

            foreach ( $categories_ids as $category_id ) {
                $category = get_term_by('id', $category_id, 'product_cat');
                if (! is_object($category) ) {
                    continue ;
                }

                $categories_link .= '<a href="' . esc_url(
                    add_query_arg(
                        array(
                        'product_cat' => $category->slug ,
                        'post_type'   => 'product' ,
                        ), admin_url('edit.php')
                    )
                ) . '" >' . $category->name . '</a> , ' ;
            }

            return rtrim($categories_link, ' , ');
        }

        /**
         * Coupon Link.
         *
         * @return string
         * */
        private function get_coupons_link( $coupon_ids )
        {
            $coupons_link = '' ;

            foreach ( $coupon_ids as $coupon_id ) {
                $the_coupon = get_post($coupon_id);

                //Return if the coupon code does not exist.
                if (! $the_coupon ) {
                    continue ;
                }

                $coupons_link .= '<a href="' . esc_url(
                    add_query_arg(
                        array(
                        'post'   => $coupon_id ,
                        'action' => 'edit' ,
                        ), admin_url('post.php')
                    )
                ) . '" >' . $the_coupon->post_title . '</a> , ' ;
            }

            return rtrim($coupons_link, ' , ');
        }

        /**
         * Prepare the item Object.
         *
         * @return void
         * */
        private function prepare_item_object( $items )
        {
            $prepare_items = array() ;
            if (cartpops_check_is_array($items) ) {
                foreach ( $items as $item ) {
                    $prepare_items[] = cartpops_get_rule($item[ 'ID' ]);
                }
            }

            $this->items = $prepare_items ;
        }

        /**
         * Get the query join clauses.
         *
         * @return string
         * */
        private function get_query_join()
        {
            $join = '' ;
         if ( empty( $_REQUEST[ 'orderby' ] ) ) { // @codingStandardsIgnoreLine.
                return $join ;
            }

            $join = ' INNER JOIN ' . $this->database->postmeta . ' AS pm ON ( pm.post_id = p.ID )' ;

            return apply_filters($this->list_slug . '_query_join', $join);
        }

        /**
         * Get the query where clauses.
         *
         * @return string
         * */
        private function get_query_where()
        {
            $current_status = 'all' ;
            if (isset($_GET[ 'status' ]) && ( sanitize_title($_GET[ 'status' ]) != 'all' ) ) {
                $current_status = sanitize_title($_GET[ 'status' ]);
            }

            $where = " where post_type='" . $this->post_type . "' and post_status IN('" . $this->format_status($current_status) . "')" ;

            // Search.
            $where = $this->custom_search($where);

            return apply_filters($this->list_slug . '_query_where', $where);
        }

        /**
         * Get the query limit clauses.
         *
         * @return string
         * */
        private function get_query_limit()
        {
            return apply_filters($this->list_slug . '_query_limit', $this->perpage);
        }

        /**
         * Get the query offset clauses.
         *
         * @return string
         * */
        private function get_query_offset()
        {
            return apply_filters($this->list_slug . '_query_offset', $this->offset);
        }

        /**
         * Get the query order by clauses.
         *
         * @return string
         * */
        private function get_query_orderby()
        {

            $order = 'DESC' ;
         if ( ! empty( $_REQUEST[ 'order' ] ) && is_string( $_REQUEST[ 'order' ] ) ) { // @codingStandardsIgnoreLine.
                if ( 'ASC' === strtoupper( wc_clean( wp_unslash( $_REQUEST[ 'order' ] ) ) ) ) { // @codingStandardsIgnoreLine.
                    $order = 'ASC' ;
                      }
            }

            // Order By.
            if (isset($_REQUEST[ 'orderby' ]) ) {
             switch ( wc_clean( wp_unslash( $_REQUEST[ 'orderby' ] ) ) ) { // @codingStandardsIgnoreLine.
                case 'rule_name':
                    $this->orderby = ' ORDER BY p.post_title ' . $order ;
                     break ;
                case 'status':
                    $this->orderby = ' ORDER BY p.post_status ' . $order ;
                     break ;
                case 'created':
                    $this->orderby = ' ORDER BY p.post_date ' . $order ;
                     break ;
                case 'modified':
                    $this->orderby = ' ORDER BY p.post_modified ' . $order ;
                     break ;
                }
            }

            return apply_filters($this->list_slug . '_query_orderby', $this->orderby);
        }

        /**
         * Custom Search.
         *
         * @retrun string
         * */
        public function custom_search( $where )
        {

         if ( ! isset( $_REQUEST[ 's' ] ) ) { // @codingStandardsIgnoreLine.
                return $where ;
            }

            $post_ids = array() ;
         $terms    = explode( ' , ' , wc_clean( wp_unslash( $_REQUEST[ 's' ] ) ) ) ; // @codingStandardsIgnoreLine.

            foreach ( $terms as $term ) {
                $term       = $this->database->esc_like(( $term ));
                $post_query = new CartPops_Query($this->database->prefix . 'posts', 'p');
                $post_query->select('DISTINCT `p`.ID')
                    ->leftJoin($this->database->prefix . 'postmeta', 'pm', '`p`.`ID` = `pm`.`post_id`')
                    ->where('`p`.post_type', $this->post_type)
                    ->whereIn('`p`.post_status', cartpops_get_rule_statuses())
                    ->whereLike('`p`.post_title', '%' . $term . '%');

                $post_ids = $post_query->fetchCol('ID');
            }

            $post_ids = cartpops_check_is_array($post_ids) ? $post_ids : array( 0 ) ;
            $where    .= ' AND (id IN (' . implode(' , ', $post_ids) . '))' ;

            return $where ;
        }

    }

}
