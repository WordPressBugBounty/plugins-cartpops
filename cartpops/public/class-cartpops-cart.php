<?php

use CartPops\Admin\Options;
/**
 * The public-facing functionality of the plugin.
 *
 * @link       https://cartpops.com
 * @since      1.0.0
 *
 * @package    CartPops
 * @subpackage CartPops/cart
 */
class CartPops_Cart {
    private $options;

    /**
     * The ID of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $plugin_name    The ID of this plugin.
     */
    private $plugin_name;

    /**
     * The version of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $version    The current version of this plugin.
     */
    private $version;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param      string    $plugin_name       The name of the plugin.
     * @param      string    $version    The version of this plugin.
     * @var        int
     */
    public static $animation_counter = 0;

    /**
     * Animation type.
     *
     * @param string $animation_type The type of animation that's active
     */
    public $animation_type;

    /**
     * Trigger.
     *
     * @param string $trigger The alt trigger for add to cart.
     */
    public $trigger_type;

    private $bundled_items = array();

    /**
     * Constructor
     *
     * @param string $plugin_name The plugin name.
     * @param int    $version Current plugin version.
     */
    public function __construct( $plugin_name, $version ) {
        self::$animation_counter = 0;
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        $this->animation_type = Options::get( 'animation_type' );
        $this->trigger_type = Options::get( 'add_to_cart_trigger' );
    }

    /**
     * Renders the drawer.
     *
     * @return void
     */
    public function render_drawer() {
        $data = array(
            'classes'    => apply_filters( 'cartpops_drawer_classes', array('cpops-default-drawer', 'cpops-modal-wrap', "cpops-animation__{$this->animation_type}") ),
            'attributes' => array(
                'tabindex' => '-1',
                'role'     => 'dialog',
            ),
        );
        cartpops_get_template( 'drawer/drawer', $data );
    }

    /**
     * Renders the add to cart launcher.
     *
     * @return void
     */
    public function render_floating_cart_launcher() {
        $enabled = Options::get( 'floating_cart_launcher_enable' );
        $hide_if_empty = Options::get( 'floating_cart_launcher_hide_empty' );
        $hide_on_pages = Options::get( 'floating_cart_launcher_hide_pages' );
        $hide_indicator_if_empty = Options::get( 'floating_cart_launcher_hide_indicator_empty' );
        $cart = WC()->cart;
        $cart_count = ( !empty( $cart ) ? $cart->get_cart_contents_count() : 0 );
        if ( 'on' !== $enabled ) {
            return;
        }
        if ( $hide_on_pages && is_page( $hide_on_pages ) ) {
            return;
        }
        $data = array(
            'classes'      => apply_filters( 'cartpops_floating_cart_launcher_classes', array('cpops-toggle-drawer') ),
            'item_account' => $cart_count,
        );
        if ( 'on' === $hide_if_empty && 0 === $cart_count ) {
            $data['classes'][] = 'cpops-floating-cart-empty';
        }
        if ( 'on' === $hide_indicator_if_empty && 0 === $cart_count ) {
            $data['classes'][] = 'cpops-floating-cart-hide-indicator';
        }
        return cartpops_get_template( 'components/launcher', $data, false );
    }

    /**
     * Load Drawer header
     *
     * @return void
     */
    public function render_drawer_header() {
        $title = Options::get( 'drawer_header_title_text' );
        $data = array(
            'classes' => apply_filters( 'cartpops_drawer_header_classes', array('cpops-drawer-header__heading') ),
            'title'   => ( !empty( $title ) ? esc_html( $title ) : __( 'My cart', 'cartpops' ) ),
        );
        if ( 'slick' === $this->animation_type ) {
            $data['classes'][] = self::get_animation_class();
        }
        cartpops_get_template( 'drawer/header', $data );
    }

    /**
     * Load Drawer footer
     *
     * @return void
     */
    public function render_drawer_footer() {
        $title = Options::get( 'drawer_footer_title_text' );
        $data = array(
            'classes' => apply_filters( 'cartpops_drawer_footer_classes', array('cpops-drawer-footer__heading') ),
            'title'   => ( !empty( $title ) ? esc_html( $title ) : __( 'My cart', 'cartpops' ) ),
        );
        if ( 'slick' === $this->animation_type ) {
            $data['classes'][] = self::get_animation_class();
        }
        cartpops_get_template( 'drawer/footer', $data );
    }

    /**
     * Get all cart contents
     *
     * @return void
     */
    public function render_cart_contents() {
        $cart = WC()->cart->get_cart();
        ?>
		<div class="cpops-drawer-cart">
		<?php 
        if ( !empty( $cart ) ) {
            foreach ( $cart as $cart_item_key => $cart_item ) {
                $_product = apply_filters(
                    'woocommerce_cart_item_product',
                    $cart_item['data'],
                    $cart_item,
                    $cart_item_key
                );
                $name = $this->get_product_name( $cart_item, $cart_item_key );
                $bundled_items = $this->get_bundled_item( $cart_item );
                $data = array(
                    'product_name'       => $name,
                    'classes'            => apply_filters( 'cartpops_drawer_cart_item_classes', array('cpops-cart-item') ),
                    'quantity'           => $this->render_quantity_selectors( array(
                        'input_value'   => $cart_item['quantity'],
                        'quantity'      => $cart_item['quantity'],
                        'max_value'     => $_product->get_max_purchase_quantity(),
                        'min_value'     => $_product->get_min_purchase_quantity(),
                        'product_name'  => $_product->get_name(),
                        'cart_item_key' => $cart_item_key,
                    ), $_product, true ),
                    'delete'             => true,
                    'cart_item'          => $cart_item,
                    'cart_item_key'      => $cart_item_key,
                    'product'            => $_product,
                    'product_id'         => apply_filters(
                        'woocommerce_cart_item_product_id',
                        $cart_item['product_id'],
                        $cart_item,
                        $cart_item_key
                    ),
                    'product_permalink'  => apply_filters(
                        'woocommerce_cart_item_permalink',
                        ( $_product->is_visible() ? $_product->get_permalink( $cart_item ) : '' ),
                        $cart_item,
                        $cart_item_key
                    ),
                    'thumbnail'          => cartpops_cart_item_thumbnail( $_product, $cart_item, $cart_item_key ),
                    'thumbnail_fallback' => wp_get_attachment_image( $_product->get_image_id(), 'thumbnail' ),
                    'product_subtotal'   => cartpops_cart_item_price( $_product, $cart_item, $cart_item_key ),
                );
                // Check if we're dealing with a bundled item.
                if ( !empty( $bundled_items ) ) {
                    $data['classes'][] = 'cpops-cart-item__bundle_' . $bundled_items['type'];
                    $data['classes'][] = 'cpops-cart-item__bundle_' . $bundled_items['key'];
                    $data['cpops_product_subtotal'] = '';
                    if ( !$bundled_items['image'] ) {
                        $data['cpops_thumbnail'] = '';
                    }
                    if ( !$bundled_items['quantity_selectors'] ) {
                        $data['quantity'] = '';
                    }
                    if ( !$bundled_items['quantity_selectors'] ) {
                        $data['quantity'] = '';
                    }
                    if ( !$bundled_items['delete'] ) {
                        $data['delete'] = false;
                    }
                }
                if ( 'slick' === $this->animation_type ) {
                    $data['classes'][] = self::get_animation_class();
                }
                cartpops_get_template( 'drawer/cart-item', $data, false );
            }
        } else {
            $empty_cart_image = null;
            $data = array(
                'classes'   => apply_filters( 'cartpops_drawer_empty_cart_classes', array('cpops-empty-cart') ),
                'headline'  => ( !empty( Options::get( 'drawer_empty_title_text' ) ) ? Options::get( 'drawer_empty_title_text' ) : __( 'Your cart is empty.', 'cartpops' ) ),
                'subheader' => ( !empty( Options::get( 'drawer_empty_subtitle_text' ) ) ? Options::get( 'drawer_empty_subtitle_text' ) : __( 'Looks like you haven\'t made a choice yet.', 'cartpops' ) ),
            );
            if ( 'slick' === $this->animation_type ) {
                $data['classes'][] = self::get_animation_class();
            }
            cartpops_get_template( 'drawer/empty-state', $data, false );
        }
        ?>
		</div>
		<?php 
    }

    /**
     * Helper fuction to get the product name.
     *
     * @param  object $cart_item     Single cart item.
     * @param  string $cart_item_key Single cart key.
     * @return string
     */
    public function get_product_name( $cart_item, $cart_item_key ) {
        $_product = apply_filters(
            'woocommerce_cart_item_product',
            $cart_item['data'],
            $cart_item,
            $cart_item_key
        );
        $product_id = apply_filters(
            'woocommerce_cart_item_product_id',
            $cart_item['product_id'],
            $cart_item,
            $cart_item_key
        );
        $product_permalink = apply_filters(
            'woocommercee_cart_item_permalink',
            ( $_product->is_visible() ? $_product->get_permalink( $cart_item ) : '' ),
            $cart_item,
            $cart_item_key
        );
        $cart_item_data = wc_get_formatted_cart_item_data( $cart_item );
        $show_details_collapse = apply_filters( 'cartpops_after_cart_item_name_hook_collapsible', true );
        $html = '<div class="cpops-cart-item__product--link" data-id="' . $product_id . '">';
        if ( !$product_permalink ) {
            $html .= wp_kses_post( apply_filters(
                'woocommerce_cart_item_name',
                $_product->get_name(),
                $cart_item,
                $cart_item_key
            ) . '&nbsp;' );
        } else {
            $html .= wp_kses_post( apply_filters(
                'woocommerce_cart_item_name',
                sprintf( '<a href="%s">%s</a>', esc_url( $product_permalink ), $_product->get_name() ),
                $cart_item,
                $cart_item_key
            ) );
        }
        $html .= '</div>';
        do_action( 'woocommerce_after_cart_item_name', $cart_item, $cart_item_key );
        if ( apply_filters( 'cartpops_after_cart_item_name_price', false ) ) {
            $html .= '<div class="cpops-cart-item__product--single-price">' . $slashed_price . '</div>';
        }
        // Gets and formats a list of cart item data + variations for display on the frontend.
        if ( $show_details_collapse && $cart_item_data ) {
            $html .= '<span class="cpops-collapse-btn-link" data-cpops-toggle="collapse" data-cpops-target=".cpops-collapse-' . esc_attr( $cart_item_key ) . '" role="button" aria-expanded="false">' . __( 'View details', 'cartpops' ) . '</span>';
            $html .= '<div class="cpops-cart-item__product--data cpops-collapse cpops-collapse-' . esc_attr( $cart_item_key ) . '">';
            $html .= '<h5 class="">' . __( 'Product details', 'cartpops' ) . '</h5>';
            $html .= $cart_item_data;
            $html .= '</div>';
        }
        // Backorder notification.
        if ( $_product->backorders_require_notification() && $_product->is_on_backorder( $cart_item['quantity'] ) ) {
            $html .= wp_kses_post( apply_filters( 'woocommerce_cart_item_backorder_notification', '<p class="backorder_notification">' . esc_html__( 'Available on backorder', 'cartpops' ) . '</p>', $product_id ) );
            // phpcs:ignore
        }
        return $html;
    }

    /**
     * Returns the neccessary hooks and data after the product name in a collapsable section (or not).
     *
     * @param  object $cart_item     WooCommerce cart item object.
     * @param  string $cart_item_key WooCommerce cart item key.
     * @author CartPops <help@cartpops.com>
     */
    public function cartpops_after_cart_item_name( $cart_item, $cart_item_key ) {
        $collapsible = '';
        $html = '';
        $_product = $cart_item['data'];
        $slashed_price = $_product->get_price_html();
        $cart_item_data = wc_get_formatted_cart_item_data( $cart_item );
        $show_details_collapse = apply_filters( 'cartpops_after_cart_item_name_hook_collapsible', true );
        do_action( 'woocommerce_after_cart_item_name', $cart_item, $cart_item_key );
        if ( apply_filters( 'cartpops_after_cart_item_name_price', false ) ) {
            $html .= '<div class="cpops-cart-item__product--single-price">' . $slashed_price . '</div>';
        }
        // Gets and formats a list of cart item data + variations for display on the frontend.
        if ( $show_details_collapse && $cart_item_data ) {
            $collapsible .= '<h5 class="">' . __( 'Product details', 'cartpops' ) . '</h5>';
            $collapsible .= $cart_item_data;
            $html .= '<span class="cpops-collapse-btn-link" data-cpops-toggle="collapse" data-cpops-target=".cpops-collapse-' . esc_attr( $cart_item_key ) . '" role="button" aria-expanded="false">' . __( 'View details', 'cartpops' ) . '</span>';
            $html .= '<div class="cpops-cart-item__product--data cpops-collapse cpops-collapse-' . esc_attr( $cart_item_key ) . '">';
            $html .= $collapsible;
            $html .= '</div>';
            // $collapsible .= ob_get_clean();
        }
        return $html;
    }

    public function render_price( $data = array() ) {
        return cartpops_get_template( 'components/product/price', $data );
    }

    /**
     * Output the quantity input for add to cart forms.
     *
     * @param  array           $data Args for the input.
     * @param  WC_Product|null $product Product.
     * @param  boolean         $echo Whether to return or echo|string.
     *
     * @return string
     */
    public function render_quantity_selectors( $data = array(), $product = null, $echo = true ) {
        $quantity_enabled = true;
        if ( is_null( $product ) ) {
            return;
        }
        if ( !$quantity_enabled ) {
            return;
        }
        if ( $product->is_sold_individually() ) {
            return;
        }
        $defaults = array(
            'input_id'      => uniqid( 'quantity_' ),
            'input_name'    => 'quantity',
            'input_value'   => '1',
            'classes'       => apply_filters( 'woocommerce_quantity_input_classes', array('input-text', 'qty', 'text'), $product ),
            'max_value'     => apply_filters( 'woocommerce_quantity_input_max', -1, $product ),
            'min_value'     => apply_filters( 'woocommerce_quantity_input_min', 0, $product ),
            'step'          => apply_filters( 'woocommerce_quantity_input_step', 1, $product ),
            'pattern'       => apply_filters( 'woocommerce_quantity_input_pattern', ( has_filter( 'woocommerce_stock_amount', 'intval' ) ? '[0-9]*' : '' ) ),
            'inputmode'     => apply_filters( 'woocommerce_quantity_input_inputmode', ( has_filter( 'woocommerce_stock_amount', 'intval' ) ? 'numeric' : '' ) ),
            'product_name'  => ( $product ? $product->get_title() : '' ),
            'placeholder'   => apply_filters( 'woocommerce_quantity_input_placeholder', '', $product ),
            'cart_item_key' => '',
        );
        $data = wp_parse_args( $data, $defaults );
        // Apply sanity to min/max args - min cannot be lower than 0.
        $data['min_value'] = max( $data['min_value'], 0 );
        $data['max_value'] = ( 0 < $data['max_value'] ? $data['max_value'] : '' );
        // Max cannot be lower than min if defined.
        if ( '' !== $data['max_value'] && $data['max_value'] < $data['min_value'] ) {
            $data['max_value'] = $data['min_value'];
        }
        return cartpops_get_template( 'components/product/quantity-selector', $data, $echo );
    }

    /**
     * Renders the coupon form partial.
     *
     * @return void
     */
    public function render_coupon_form() {
        if ( 'on' === Options::get( 'coupon_form_enable' ) && wc_coupons_enabled() ) {
            $title = Options::get( 'coupon_title_text' );
            $placeholder_text = Options::get( 'coupon_input_placeholder_text' );
            $button_text = Options::get( 'coupon_button_text' );
            $coupons = WC()->cart->get_applied_coupons();
            if ( $coupons ) {
                foreach ( $coupons as $coupon ) {
                    $coupon = new WC_Coupon($coupon);
                }
            }
            $data = array(
                'classes'          => apply_filters( 'cartpops_drawer_coupon_form_classes', array('cpops-drawer-coupon') ),
                'active_coupon'    => WC()->cart->get_applied_coupons(),
                'currency_symbol'  => get_woocommerce_currency_symbol(),
                'cpops_coupon'     => ( !empty( $coupon ) ? $coupon->get_code() : '' ),
                'title'            => ( !empty( $title ) ? $title : __( 'Got a discount code?', 'cartpops' ) ),
                'placeholder_text' => ( !empty( $placeholder_text ) ? $placeholder_text : __( 'Enter discount code', 'cartpops' ) ),
                'button_text'      => ( !empty( $button_text ) ? $button_text : __( 'Apply', 'cartpops' ) ),
            );
            if ( 'slick' === $this->animation_type ) {
                $data['classes'][] = self::get_animation_class();
            }
            cartpops_get_template( 'drawer/coupon-form', $data, false );
        }
    }

    /**
     * Renders the coupon removal partial.
     *
     * @return void
     */
    public function render_coupon_removal() {
        $coupons = WC()->cart->get_applied_coupons();
        ?>
		<div class="cpops-coupon-remove">
		<?php 
        if ( $coupons ) {
            $html = '';
            foreach ( $coupons as $coupon ) {
                $coupon = new WC_Coupon($coupon);
                $code = $coupon->get_code();
                $coupon_data = $coupon->get_data();
                $discount_amount = $coupon_data['amount'];
                $discount_type = $coupon_data['discount_type'];
                $currency_symbol = get_woocommerce_currency_symbol();
                $coupon_text_price = ( 'percent' === $discount_type ? $discount_amount . '%' : $currency_symbol . $discount_amount );
                $html .= '<div class="cpops-coupons-tag">';
                $html .= sprintf(
                    '<span class="cpops-coupon-remove__item cpops-coupon-remove__item  cpops-coupon-remove__item-link">%1$s</span><a href="javascript:void(0);" class="cpops-coupon-remove__item  cpops-coupon-remove__item-delete" data-coupon="%2$s"><span class="cpops-sr-only">%3$s</span></a>',
                    /* translators: %1$s is replaced with the coupon name and %2$s with the coupon amount  */
                    sprintf( esc_html__( 'Coupon "%1$s" (%2$s)', 'cartpops' ), esc_html( $code ), esc_html( $coupon_text_price ) ),
                    esc_html( $code ),
                    esc_html__( 'Removes coupon code', 'cartpops' )
                );
                $html .= '</div>';
            }
            // We echo because we don't need a seperate file for this..
            echo wp_kses_post( $html );
        }
        ?>
		</div>
		<?php 
    }

    /**
     * Renders the drawer recommendations partial.
     *
     * @author CartPops <help@cartpops.com>
     */
    public function render_drawer_recommendations() {
        $enabled = Options::get( 'product_recommendation_engine_enable' );
        $button_text = Options::get( 'product_recommendation_engine_button_text' );
        $button_type = Options::get( 'product_recommendation_engine_button_type' );
        $title = Options::get( 'product_recommendation_engine_text' );
        $products = $this->get_recommendations();
        if ( 'on' !== $enabled ) {
            return;
        }
        if ( empty( $products ) ) {
            // @Chris: We need to return an empty div here to prevent the
            // recommendations from being hidden on page load and any first event.
            ?>
				<div class="cpops-drawer-recommendations-wrapper"></div>
			<?php 
            return;
        }
        $data = array(
            'classes'              => apply_filters( 'cartpops_drawer_recommendations_classes', array('cpops-drawer-recommendations') ),
            'title'                => ( !empty( $title ) ? $title : __( 'You might also like these items!', 'cartpops' ) ),
            'recommended_products' => $products,
            'button_text'          => ( !empty( $button_text ) ? $button_text : 'Add' ),
            'button_type'          => ( !empty( $button_type ) ? $button_type : 'icon' ),
        );
        if ( 'slick' === $this->animation_type ) {
            $data['classes'][] = self::get_animation_class();
        }
        cartpops_get_template( 'drawer/recommendations', $data );
    }

    /**
     * Get an object of products to recommend based on plugin settings.
     *
     * @author CartPops <help@cartpops.com>
     * @return object $products An object of products.
     */
    public function get_recommendations() {
        $recommendation_type = Options::get( 'product_recommendation_engine_type' );
        $recommendation_fallback = Options::get( 'product_recommendation_engine_fallback' );
        $custom_products = Options::get( 'product_recommendation_engine_custom_global' );
        $cart = WC()->cart->get_cart();
        $products = array();
        $current_cart_item_ids = array();
        $product_ids = array();
        if ( 'upsells' === $recommendation_type ) {
            foreach ( $cart as $item ) {
                $_product = new WC_Product($item['product_id']);
                $product_ids = $_product->get_upsell_ids();
            }
        }
        if ( 'cross_sells' === $recommendation_type ) {
            foreach ( $cart as $item ) {
                $_product = new WC_Product($item['product_id']);
                $product_ids = $_product->get_cross_sell_ids();
            }
        }
        // Gather products and check stock.
        foreach ( $product_ids as $product_id ) {
            $_product = cartpops_get_product( $product_id );
            $product_cart_ids[] = WC()->cart->generate_cart_id( $product_id );
            if ( !is_a( $_product, 'WC_Product' ) ) {
                continue;
            }
            if ( $_product->exists() && $_product->is_in_stock() && 'publish' === $_product->get_status() ) {
                $products[] = $_product;
            }
        }
        // No products found.
        if ( empty( $products ) ) {
            if ( 'random_products' === $recommendation_fallback ) {
                $products = $this->get_random_recommended_products( array(), 4, $current_cart_item_ids );
            }
        }
        return $products;
    }

    /**
     * Gets the cart toals
     *
     * @return void
     */
    public function render_cart_totals() {
        $cart = WC()->cart->get_cart();
        $applied_coupons = WC()->cart->get_applied_coupons();
        $checkout_url = apply_filters( 'cartpops_checkout_button_url', wc_get_checkout_url() );
        $shop_url = apply_filters( 'cartpops_empty_cart_button_url', get_permalink( wc_get_page_id( 'shop' ) ) );
        if ( !empty( $cart ) ) {
            $button_text = ( !empty( Options::get( 'checkout_button_text' ) ) ? Options::get( 'checkout_button_text' ) : __( 'Checkout now', 'cartpops' ) );
            $url = $checkout_url;
        } else {
            $button_text = ( !empty( Options::get( 'checkout_button_empty_text' ) ) ? Options::get( 'checkout_button_empty_text' ) : __( 'Your cart is empty. Shop now', 'cartpops' ) );
            $url = $shop_url;
        }
        $data = array(
            'classes'     => apply_filters( 'cartpops_drawer_cart_totals_classes', array('cpops-cart-total') ),
            'subtotal'    => $this->get_cart_subtotal_order_total_html(),
            'discount'    => $this->get_cart_discount_order_total_html(),
            'total'       => $this->get_cart_totals_order_total_html(),
            'tax'         => $this->get_cart_tax_order_total_html(),
            'shipping'    => $this->get_cart_shipping_total_html(),
            'button_text' => $button_text,
            'url'         => $url,
        );
        if ( 'slick' === $this->animation_type ) {
            $data['classes'][] = self::get_animation_class();
        }
        cartpops_get_template( 'drawer/cart-totals', $data );
    }

    /**
     * Get the cart subtotal.
     *
     * @author CartPops <help@cartpops.com>
     */
    private function get_cart_subtotal_order_total_html() {
        $enable_subtotal = Options::get( 'drawer_footer_display_subtotal' );
        $html = '';
        if ( 'on' === $enable_subtotal ) {
            $label_translation = Options::get( 'subtotal_line_item_text' );
            $label = ( !empty( $label_translation ) ? $label_translation : __( 'Subtotal', 'cartpops' ) );
            $has_coupons = WC()->cart->get_cart_discount_total();
            $value = WC()->cart->get_cart_subtotal();
            if ( wc_tax_enabled() || $has_coupons ) {
                $html = $this->create_single_line_item_html(
                    $label,
                    apply_filters( 'cartpops_cart_totals_subtotal_html', $value ),
                    '',
                    'subtotal'
                );
            }
        }
        return $html;
    }

    /**
     * Get the cart total without shipping.
     *
     * @author CartPops <help@cartpops.com>
     */
    private function get_cart_totals_order_total_html() {
        $enable_total = Options::get( 'drawer_footer_display_total' );
        $html = '';
        if ( 'on' === $enable_total ) {
            $cart = WC()->cart;
            $tooltip = '';
            $label_translation = Options::get( 'total_line_item_text' );
            $enable_shipping = Options::get( 'drawer_footer_display_shipping' );
            $label = ( !empty( $label_translation ) ? $label_translation : __( 'Total', 'cartpops' ) );
            $cart_total = $cart->get_total( 'number' );
            $shipping = $this->get_shipping_totals();
            $shipping_total = $shipping->total;
            $shipping_tax_total = $shipping->tax_total;
            $total_without_shipping = $cart_total - $shipping_total - $shipping_tax_total;
            if ( $enable_shipping ) {
                $value = '<strong>' . wc_price( $cart_total ) . '</strong> ';
            } else {
                $value = '<strong>' . wc_price( $total_without_shipping ) . '</strong> ';
            }
            if ( wc_tax_enabled() && $cart->display_prices_including_tax() ) {
                $tax_string_array = array();
                $cart_tax_totals = $cart->get_tax_totals();
                if ( get_option( 'woocommerce_tax_total_display' ) === 'itemized' ) {
                    foreach ( $cart_tax_totals as $code => $tax ) {
                        $tax_string_array[] = sprintf( '%s %s', wc_price( $tax->amount - $shipping_tax_total ), $tax->label );
                    }
                } elseif ( !empty( $cart_tax_totals ) ) {
                    $tax_string_array[] = sprintf( '%s %s', wc_price( $cart->get_taxes_total( true, false ) - $shipping_tax_total ), WC()->countries->tax_or_vat() );
                }
                if ( !empty( $tax_string_array ) ) {
                    $taxable_address = WC()->customer->get_taxable_address();
                    $estimated_text = ( WC()->customer->is_customer_outside_base() && !WC()->customer->has_calculated_shipping() ? sprintf( ' ' . __( 'estimated for %s', 'cartpops' ), WC()->countries->estimated_for_prefix( $taxable_address[0] ) . WC()->countries->countries[$taxable_address[0]] ) : '' );
                    /* translators: %s: tax amount */
                    $value .= '<small class="includes_tax">' . sprintf( __( '(includes %s)', 'cartpops' ), implode( ', ', $tax_string_array ) . $estimated_text ) . '</small>';
                }
            }
            if ( WC()->cart->needs_shipping() && WC()->cart->show_shipping() && !$enable_shipping ) {
                $tooltip = __( 'Shipping calculated at checkout', 'cartpops' );
            }
            $html = $this->create_single_line_item_html(
                $label,
                apply_filters( 'cartpops_cart_totals_order_total_html', $value ),
                $tooltip,
                'total'
            );
        }
        return $html;
    }

    /**
     * Get the cart vat without shipping.
     *
     * @author CartPops <help@cartpops.com>
     */
    private function get_cart_tax_order_total_html() {
        $enable_tax = Options::get( 'drawer_footer_display_tax' );
        $label = '';
        $value = '';
        $html = '';
        if ( 'on' === $enable_tax && wc_tax_enabled() && !WC()->cart->display_prices_including_tax() ) {
            $shipping = $this->get_shipping_totals();
            $shipping_total = $shipping->total;
            $shipping_tax_total = $shipping->tax_total;
            if ( 'itemized' === get_option( 'woocommerce_tax_total_display' ) ) {
                foreach ( WC()->cart->get_tax_totals() as $code => $tax ) {
                    $tax_excluding_shipping = $tax->amount - $shipping_tax_total;
                    // $label                 .= 'using';
                    $label .= $tax->label;
                    $value .= wp_kses_post( wc_price( $tax_excluding_shipping ) );
                }
            } else {
                $label .= esc_html( WC()->countries->tax_or_vat() );
                $value .= wc_price( WC()->cart->get_taxes_total( false, false ) - $shipping_tax_total );
            }
            $html = $this->create_single_line_item_html(
                $label,
                apply_filters( 'cartpops_cart_totals_vat_total_html', $value ),
                '',
                'tax'
            );
        }
        return $html;
    }

    /**
     * Get the cart discount.
     *
     * @author CartPops <help@cartpops.com>
     */
    private function get_cart_discount_order_total_html() {
        $html = '';
        $label_translation = Options::get( 'discount_line_item_text' );
        $enable_discount = Options::get( 'drawer_footer_display_discount' );
        $label = ( !empty( $label_translation ) ? $label_translation : __( 'Discount', 'cartpops' ) );
        $discount_excl_tax_total = WC()->cart->get_cart_discount_total();
        $discount_tax_total = WC()->cart->get_cart_discount_tax_total();
        $discount_total = $discount_excl_tax_total + $discount_tax_total;
        $value = wc_price( $discount_total );
        $html = '';
        if ( 'on' === $enable_discount ) {
            if ( $discount_total > 0 ) {
                $html = $this->create_single_line_item_html(
                    $label,
                    apply_filters( 'cartpops_cart_totals_discount_total_html', $value ),
                    '',
                    'discount'
                );
            }
        }
        return $html;
    }

    /**
     * Shipping line item
     *
     * @author CartPops <help@cartpops.com>
     */
    public function get_cart_shipping_total_html() {
        $enable_shipping = Options::get( 'drawer_footer_display_shipping' );
        $html = '';
        if ( 'on' === $enable_shipping ) {
            $value = '';
            $label = __( 'Shipping', 'cartpops' );
            WC()->cart->calculate_shipping();
            $packages = WC()->shipping()->get_packages();
            if ( !empty( $packages ) ) {
                $package = $packages[0];
                $available_methods = $package['rates'];
                if ( $available_methods ) {
                    $value = WC()->cart->get_cart_shipping_total();
                } else {
                    $formatted_destination = WC()->countries->get_formatted_address( $package['destination'], ', ' );
                    if ( !$formatted_destination ) {
                        $value = wp_kses_post( apply_filters( 'woocommerce_shipping_not_enabled_on_cart_html', __( 'Shipping costs are calculated during checkout.', 'woocommerce' ) ) );
                    } else {
                        $value = wp_kses_post( apply_filters( 'woocommerce_cart_no_shipping_available_html', sprintf( esc_html__( 'No shipping options were found for %s.', 'woocommerce' ) . ' ', '<strong>' . esc_html( $formatted_destination ) . '</strong>' ) ) );
                    }
                }
            }
            $html = $this->create_single_line_item_html(
                $label,
                apply_filters( 'cartpops_cart_totals_shipping_html', $value ),
                '',
                'shipping'
            );
        }
        return $html;
    }

    /**
     * Get shipping information.
     *
     * @author CartPops <help@cartpops.com>
     */
    private function get_shipping_totals() {
        $cart = WC()->cart;
        $shipping = array(
            'total'     => (float) $cart->get_shipping_total(),
            'tax_total' => (float) $cart->shipping_tax_total,
        );
        return (object) $shipping;
    }

    /**
     * Creates a single line item.
     *
     * @param string $label A html string for the label.
     * @param string $value A html string for the value.
     * @author CartPops <help@cartpops.com>
     */
    private function create_single_line_item_html(
        $label,
        $value,
        $tooltip,
        $type = 'item'
    ) {
        $html = '';
        $classes = apply_filters( 'cartpops_single_line_item_classes', array('cpops-cart-line-items__item', 'cpops-cart-line-items__' . $type) );
        $html .= '<div class="' . esc_html( implode( ' ', array_filter( $classes ) ) ) . '">';
        $html .= '<span class="cpops-cart-line-items__label cpops-cart-line-items__' . $type . '-label">' . $label;
        if ( $tooltip ) {
            $html .= '<div class="cpops-tooltip">';
            $html .= '<i class="cpops-tooltip__icon>?</i>';
            $html .= '<div class="cpops-tooltip__info">';
            $html .= '<span>' . $tooltip . '</span>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '<span class="cpops-sr-only">' . $tooltip . '</span>';
        }
        $html .= '</span>';
        $html .= '<span class="cpops-cart-line-items__value cpops-cart-line-items__' . $type . '-value">' . $value . '</span>';
        $html .= '</div>';
        return $html;
    }

    /**
     * Renders the powered by partial.
     *
     * @author CartPops <help@cartpops.com>
     */
    public function render_powered_by() {
        $enabled = Options::get( 'support_us_enable' );
        if ( 'on' !== $enabled ) {
            return;
        }
        $affiliate_url = Options::get( 'support_us_partner_code' );
        $protocols = array('http://', 'http://www.', 'www.');
        $url = cartpops_outbound_url(
            '',
            'Add To Cart Trigger',
            'Powered By',
            str_replace( $protocols, '', get_bloginfo( 'wpurl' ) )
        );
        if ( $affiliate_url ) {
            $url = "https://r.freemius.com/7061/{$affiliate_url}/";
        }
        $data = array(
            'classes' => apply_filters( 'cartpops_powered_by_classes', array('cpops-powered-by') ),
            'url'     => esc_url( $url ),
        );
        if ( 'slick' === $this->animation_type ) {
            $data['classes'][] = self::get_animation_class();
        }
        cartpops_get_template( 'components/powered-by', $data );
    }

    /**
     * Get an array of recommended products
     *
     * @param array   $products Array of products.
     * @param integer $count Count of products to get.
     * @param array   $excludes Any products that should be excluded.
     * @author CartPops <help@cartpops.com>
     */
    private function get_random_recommended_products( $products = array(), $count = 4, $excludes = array() ) {
        $products_temp = $products;
        $args = array(
            'posts_per_page' => $count,
            'orderby'        => 'rand',
            'post_type'      => 'product',
        );
        $random_products = get_posts( $args );
        // We need at least 4 products for random products to fill the slider nicely.
        foreach ( $random_products as $prod ) {
            if ( !in_array( $prod->ID, $excludes, true ) ) {
                $prod_ = cartpops_get_product( $prod->ID );
                $product_cart_id = WC()->cart->generate_cart_id( $prod->ID );
                if ( !WC()->cart->find_product_in_cart( $product_cart_id ) && count( $products_temp ) <= $count && $prod_->is_in_stock() ) {
                    $products_temp[] = $prod_;
                }
                $excludes[] = $prod->ID;
            }
        }
        return $products_temp;
    }

    /**
     * Renders the WooCommerce notices partial.
     *
     * @author CartPops <help@cartpops.com>
     */
    public function render_wc_notices() {
        $html = '<div class="cpops-drawer-notices-wrapper"></div>';
        echo wp_kses( apply_filters( 'cartpops_notices_html', $html ), apply_filters( 'cartpops_kses_notice_allowed_tags', wp_kses_allowed_html( 'post' ) ) );
    }

    /**
     * Increase animation counter
     *
     * @return void
     */
    public static function increase_animation_counter() {
        self::$animation_counter++;
    }

    /**
     * Increase and also return animation counter
     *
     * @return int
     */
    public static function increase_get_animation_counter() {
        self::increase_animation_counter();
        return self::$animation_counter;
    }

    /**
     * Increase and also return animation counter
     *
     * @return int
     */
    public static function get_animation_class() {
        self::increase_animation_counter();
        return 'cpops-animation__slick-delay-' . self::$animation_counter;
    }

    /**
     * Get animation
     *
     * @return int
     */
    public static function get_animation_counter() {
        return self::$animation_counter;
    }

    /**
     * Renders the cart launcher partial.
     *
     * @param array $args An array of possible options.
     * @author CartPops <help@cartpops.com>
     */
    public static function render_cart_launcher( $args = array() ) {
        if ( !WC()->cart ) {
            return;
        }
        $defaults = array(
            'icon'                 => '',
            'subtotal'             => 'true',
            'indicator'            => 'bubble',
            'indicator_hide_empty' => 'false',
            'wrapper'              => 'false',
            'shortcode'            => 'false',
            'menu_item'            => 'false',
            'classes'              => apply_filters( 'cartpops_cart_launcher_classes', array('cartpops-cart__wrapper') ),
        );
        $args = wp_parse_args( $args, $defaults );
        $product_count = WC()->cart->get_cart_contents_count();
        $sub_total = WC()->cart->get_cart_subtotal();
        if ( 'true' === $args['shortcode'] ) {
            $args['classes'][] = 'is-shortcode';
        }
        if ( 'true' === $args['menu_item'] ) {
            $args['classes'][] = 'is-menu';
        }
        if ( 'true' === $args['subtotal'] ) {
            $args['classes'][] = 'cartpops-cart--show-subtotal-yes';
        }
        if ( $args['indicator'] ) {
            switch ( $args['indicator'] ) {
                case 'none':
                    $args['classes'][] = 'cartpops-cart--items-indicator-hide';
                    break;
                case 'bubble':
                    $args['classes'][] = 'cartpops-cart--items-indicator-bubble';
                    break;
                case 'plain':
                    $args['classes'][] = 'cartpops-cart--items-indicator-plain';
                    break;
                default:
                    $args['classes'][] = 'cartpops-cart--items-indicator-bubble';
                    break;
            }
        }
        if ( 'true' === $args['indicator_hide_empty'] && 0 === $product_count ) {
            $args['classes'][] = 'cartpops-cart--empty-indicator-hide';
        }
        if ( 'true' === $args['wrapper'] ) {
            echo '<div class="' . esc_html( implode( ' ', array_filter( $args['classes'] ) ) ) . '">';
        }
        ?>
		<div class="cartpops-cart__toggle cartpops-cart__container-wrapper" id="cartpops-cart-launcher-<?php 
        echo esc_attr( wp_unique_id() );
        ?>">
			<div class="cartpops-cart__container cpops-toggle-drawer">
				<span class="cartpops-cart__container-icon">
					<i class="<?php 
        echo esc_attr( $args['icon'] );
        ?>" aria-hidden="true"></i>
					<span class="cpops-sr-only"><?php 
        esc_html_e( 'Cart', 'cartpops' );
        ?></span>
					<span class="cartpops-cart__container-counter">
						<?php 
        echo esc_attr( $product_count );
        ?>
					</span>
				</span>
				<span class="cartpops-cart__container-text"><?php 
        echo wp_kses_post( $sub_total );
        ?></span>
			</div>
		</div>
		<?php 
        if ( $args['wrapper'] ) {
            echo '</div>';
        }
    }

    /**
     * Ajaxify short-code cart count.
     *
     * @param array $fragments WooCommerce fragments.
     *
     * @return mixed
     */
    public function menu_cart_fragments( $fragments ) {
        $has_cart = is_a( WC()->cart, 'WC_Cart' );
        if ( !$has_cart ) {
            return $fragments;
        }
        $cart_count = WC()->cart->get_cart_contents_count();
        $cart_empty = ( 0 === $cart_count ? 'is-empty' : '' );
        $sub_total = WC()->cart->get_cart_subtotal();
        ob_start();
        echo '<span class="cartpops-cart__container-counter ' . esc_attr( $cart_empty ) . '">' . esc_attr( $cart_count ) . '</span>';
        $cart_launcher_count = ob_get_clean();
        ob_start();
        ?>
		<span class="cartpops-cart__container-text"><?php 
        echo wp_kses_post( $sub_total );
        ?></span>
		<?php 
        $cart_total = ob_get_clean();
        $fragments['.cartpops-cart__container-counter'] = $cart_launcher_count;
        $fragments['.cartpops-cart__container-text'] = $cart_total;
        return $fragments;
    }

    /**
     * Get all bundled items.
     *
     * @since 1.4.1
     */
    public function get_bundled_items( $cart_item ) {
        if ( !empty( $this->bundled_items ) ) {
            return $this->bundled_items;
        }
        $data = array(
            'mnm_contents'        => array(
                'key'                => 'woocommerce_mix_match_products_parent',
                'type'               => 'parent',
                'delete'             => true,
                'quantity_selectors' => true,
                'image'              => true,
                'link'               => true,
            ),
            'mnm_container'       => array(
                'key'                => 'woocommerce_mix_match_products_child',
                'type'               => 'child',
                'delete'             => false,
                'quantity_selectors' => false,
                'image'              => true,
                'link'               => true,
            ),
            'bundled_items'       => array(
                'key'                => 'woocommerce_product_bundles_parent',
                'type'               => 'parent',
                'delete'             => true,
                'quantity_selectors' => true,
                'image'              => true,
                'link'               => true,
            ),
            'bundled_by'          => array(
                'key'                => 'woocommerce_product_bundles_child',
                'type'               => 'child',
                'delete'             => false,
                'quantity_selectors' => false,
                'image'              => true,
                'link'               => true,
            ),
            'extbundled_items'    => array(
                'key'                => 'extendons_product_bundles_parent',
                'type'               => 'parent',
                'delete'             => true,
                'quantity_selectors' => true,
                'image'              => true,
                'link'               => true,
            ),
            'ext_hidden_item'     => array(
                'key'                => 'extendons_product_bundles_child',
                'type'               => 'child',
                'delete'             => false,
                'quantity_selectors' => false,
                'image'              => true,
                'link'               => true,
            ),
            'woosb_ids'           => array(
                'key'                => 'wpc_product_bundles_parent',
                'type'               => 'parent',
                'delete'             => true,
                'quantity_selectors' => true,
                'image'              => true,
                'link'               => true,
            ),
            'woosb_parent_id'     => array(
                'key'                => 'wpc_product_bundles_child',
                'type'               => 'child',
                'delete'             => false,
                'quantity_selectors' => false,
                'image'              => true,
                'link'               => true,
            ),
            'composite_parent'    => array(
                'key'                => 'woocommerce_composite_parent',
                'type'               => 'child',
                'delete'             => false,
                'quantity_selectors' => false,
                'image'              => true,
                'link'               => true,
            ),
            'composite_children'  => array(
                'key'                => 'woocommerce_composite_child',
                'type'               => 'parent',
                'delete'             => true,
                'quantity_selectors' => true,
                'image'              => true,
                'link'               => true,
            ),
            'chained_item_parent' => array(
                'key'                => 'woocommerce_chained_products_parent',
                'type'               => 'parent',
                'delete'             => true,
                'quantity_selectors' => true,
                'image'              => true,
                'link'               => true,
            ),
            'chained_item_of'     => array(
                'key'                => 'woocommerce_chained_products_child',
                'type'               => 'child',
                'delete'             => false,
                'quantity_selectors' => false,
                'image'              => true,
                'link'               => true,
            ),
            'associated_products' => array(
                'key'                => 'epo_themecomplete_parent',
                'type'               => 'parent',
                'delete'             => true,
                'quantity_selectors' => true,
                'image'              => true,
                'link'               => true,
            ),
            'associated_parent'   => array(
                'key'                => 'epo_themecomplete_child',
                'type'               => 'child',
                'delete'             => false,
                'quantity_selectors' => false,
                'image'              => true,
                'link'               => true,
            ),
        );
        $this->bundled_items = apply_filters( 'cartpops_bundled_items_data', $data );
        return $this->bundled_items;
    }

    /**
     * Get bundled item.
     *
     * @param array $cart_item Array of cart items.
     * @since 1.4.1
     */
    public function get_bundled_item( $cart_item ) {
        $bundled_items = $this->get_bundled_items( $cart_item );
        $bundled_products = array_intersect_key( $bundled_items, $cart_item );
        return ( !empty( $bundled_products ) ? array_values( array_intersect_key( $bundled_items, $cart_item ) )[0] : $bundled_products );
    }

    /**
     * Match certain placeholders like {{example}} against variable array.
     *
     * @param string $body String containing placeholders that need to be replaced.
     * @param array  $vars Array of variables that match placeholders.
     * @author CartPops <help@cartpops.com>
     */
    private function populate_placeholders( $body, array $vars ) {
        foreach ( $vars as $key => $value ) {
            $placeholder = sprintf( '{{%s}}', $key );
            $body = str_replace( $placeholder, $value, $body );
        }
        return $body;
    }

}
