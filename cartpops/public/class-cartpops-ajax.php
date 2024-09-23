<?php

use CartPops\Cart\Fragments\Cart_Fragment_Manager;
use CartPops\Cart\Fragments\Cart_Fragment_Settings;
use CartPops\Cart\Fragments\Cart_Item_Fragment_Settings;
/**
 * Frontend AJAX calls reside in this class.
 *
 * @link       https://cartpops.com
 * @since      1.0.0
 *
 * @package    CartPops
 * @subpackage CartPops/public
 */
class CartPops_Frontend_Ajax {
    private $plugin_name;

    private $version;

    private $templates;

    /**
     * Var that holds the cart notice
     *
     * @since    1.0.0
     * @var      string  $notice   Notice
     */
    public $notifications = null;

    protected $last_added_product_id = null;

    /**
     * Constructor
     *
     * @param string $plugin_name The plugin name.
     * @param int    $version Current plugin version.
     * @param class  $templates Cart templates.
     */
    public function __construct( $plugin_name, $version, $templates ) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        $this->templates = $templates;
    }

    public function cart_fragments( $fragments ) {
        $add_to_cart = !empty( $_GET['wc-ajax'] ) && 'add_to_cart' === $_GET['wc-ajax'];
        // phpcs:ignore WordPress.Security.NonceVerification
        $request_type = ( !empty( $_POST['request_type'] ) ? filter_var( wp_unslash( $_POST['request_type'] ), FILTER_SANITIZE_STRING ) : null );
        // phpcs:ignore WordPress.Security.NonceVerification
        $product_id = ( !empty( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : null );
        // phpcs:ignore WordPress.Security.NonceVerification
        $coupons = WC()->cart->get_applied_coupons();
        $cart_count = WC()->cart->get_cart_contents_count();
        $buffer_output = true;
        // Set cart constants.
        cartpops_set_cart_constant();
        // Calculate cart totals.
        WC()->cart->calculate_totals();
        /**
         * Initialize the settings for the cart fragments
         *
         * @since 1.4.3
         */
        $fragment_settings = new Cart_Fragment_Settings(array(
            'templates'    => $this->templates,
            'request_type' => $request_type,
            'product_id'   => $product_id,
            'cart'         => WC()->cart,
            'notice'       => (string) $this->get_notifications(),
        ));
        $cart_fragments = array(
            'div.cpops-drawer-notices-wrapper'         => 'notices',
            'div#cpops-floating-cart'                  => 'cart_launcher',
            'div.cpops-cart-total'                     => 'cart_totals',
            'div.cpops-coupon-remove'                  => 'coupon_removal',
            'div.cpops-drawer-recommendations-wrapper' => 'drawer_recommendations',
        );
        $fragments = array_merge( $fragments, Cart_Fragment_Manager::render_fragments( $cart_fragments, $fragment_settings ) );
        // Update cart.
        if ( in_array( $request_type, array('update_cart'), true ) ) {
            foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
                /**
                 * Initialise the settings for the cart item fragments
                 *
                 * @since 1.4.3
                 */
                $cart_item_fragment_settings = new Cart_Item_Fragment_Settings(array(
                    'templates'    => $this->templates,
                    'request_type' => $request_type,
                    'product_id'   => $product_id,
                    'cart'         => WC()->cart,
                    'notice'       => (string) $this->get_notifications(),
                    'cart_item'    => $cart_item,
                ));
                // Cart item price.
                $fragments['div.cpops-cart-item[data-key="' . $cart_item_key . '"] .cpops-cart-item__actions--pricing'] = Cart_Fragment_Manager::get_fragment_html( 'cart_item_price', $cart_item_fragment_settings );
                // Cart item quantity.
                $fragments['div.cpops-cart-item[data-key="' . $cart_item_key . '"] .cpops-cart-item__quantity-selector'] = Cart_Fragment_Manager::get_fragment_html( 'cart_item_quantity', $cart_item_fragment_settings );
            }
        } else {
            // Add to cart, remove product updates.
            $fragments['.cpops-drawer-cart'] = Cart_Fragment_Manager::get_fragment_html( 'drawer_cart', $fragment_settings );
        }
        /**
         * Remove all empty fragments from the response. This will allow to exclude the fragments thar were not rendered due to specific conditions
         *
         * @since 1.4.3
         */
        return array_filter( $fragments );
    }

    /**
     * Set a notification for rendering in HTML.
     *
     * @param string $notice
     * @param string $type
     * @author CartPops <help@cartpops.com>
     */
    public function set_notification( $notice, $type = 'info' ) {
        $this->notifications = '<div class="cpops-notification cpops-has-shadow cpops-is-light cpops-is-' . esc_attr( $type ) . '" data-type="' . esc_attr( $type ) . '">' . $notice . '</div>';
    }

    /**
     * Get all notifications.
     *
     * @author CartPops <help@cartpops.com>
     */
    public function get_notifications() {
        if ( empty( $this->notifications ) ) {
            return null;
        }
        $notification = $this->notifications;
        $notification = apply_filters( 'cartpops_drawer_notice_html', $notification );
        $this->notifications = '';
        return $notification;
    }

    /**
     * Refresh the cart.
     *
     * @author CartPops <help@cartpops.com>
     */
    public function refresh_cart() {
        ob_start();
        $data = apply_filters( 'cartpops_ajax_fragments', array() );
        wp_send_json( $data );
    }

    /**
     * AJAX: Add a product to the cart.
     * Ignores archive add to carts as they as AJAX by default.
     *
     * @author CartPops <help@cartpops.com>
     */
    public function add_to_cart() {
        cartpops_set_cart_constant();
        $security_check = isset( $_POST['add-to-cart'] );
        // phpcs:ignore WordPress.Security.NonceVerification
        $product_id = ( !empty( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : '' );
        // phpcs:ignore WordPress.Security.NonceVerification
        $add_to_cart = ( !empty( $_POST['add-to-cart'] ) ? intval( $_POST['add-to-cart'] ) : '' );
        // phpcs:ignore WordPress.Security.NonceVerification
        if ( !$security_check ) {
            wp_send_json_error( esc_html__( 'Something went wrong, please reload the page.', 'cartpops' ), 403 );
        }
        if ( empty( wc_get_notices( 'error' ) ) ) {
            do_action( 'woocommerce_ajax_added_to_cart', $add_to_cart );
        }
        $product = ( !empty( $product_id ) ? cartpops_get_product( $product_id ) : '' );
        if ( $product ) {
            /* translators: %s: product name */
            $this->set_notification( sprintf( __( '"%s" has been added to the cart.', 'cartpops' ), $product->get_name() ), 'success' );
        } else {
            $this->set_notification( __( 'Added to the cart successfully.', 'cartpops' ), 'success' );
        }
        wc_clear_notices();
        $this->get_refreshed_fragments();
        wp_die();
    }

    /**
     * AJAX: Remove a product from the cart.
     *
     * @author CartPops <help@cartpops.com>
     */
    public function remove_product() {
        cartpops_set_cart_constant();
        $cart_item_key = ( !empty( $_POST['cart_key'] ) ? filter_var( wp_unslash( $_POST['cart_key'] ), FILTER_SANITIZE_STRING ) : null );
        // phpcs:ignore WordPress.Security.NonceVerification
        $cart_item = WC()->cart->get_cart_item( $cart_item_key );
        if ( $cart_item ) {
            WC()->cart->remove_cart_item( $cart_item_key );
            $product = cartpops_get_product( $cart_item['product_id'] );
            /* translators: %s: Item name. */
            $item_removed_title = apply_filters( 'cartpops_cart_item_removed_title', ( $product ? sprintf( _x( '&ldquo;%s&rdquo;', 'Item name in quotes', 'cartpops' ), $product->get_name() ) : __( 'Item', 'cartpops' ) ), $cart_item );
            // Don't show undo link if removed item is out of stock.
            if ( $product && $product->is_in_stock() && $product->has_enough_stock( $cart_item['quantity'] ) ) {
                /* Translators: %s Product title. */
                $removed_notice = sprintf( __( '%s removed.', 'cartpops' ), $item_removed_title );
                $removed_notice .= ' <a href="#" data-key="' . $cart_item_key . '" class="cpops-restore-item">' . __( 'Undo?', 'cartpops' ) . '</a>';
            } else {
                /* Translators: %s Product title. */
                $removed_notice = sprintf( __( '%s removed.', 'cartpops' ), $item_removed_title );
            }
            WC()->session->set( 'cartpops_last_removed_item_name', $item_removed_title );
            $this->set_notification( $removed_notice, apply_filters( 'cartpops_cart_item_removed_notice_type', 'success' ) );
        }
        wc_clear_notices();
        $this->get_refreshed_fragments();
    }

    /**
     * Restore a product in the cart.
     *
     * @author CartPops <help@cartpops.com>
     */
    public function restore_product() {
        cartpops_set_cart_constant();
        $cart_item_key = ( !empty( $_POST['cart_key'] ) ? filter_var( wp_unslash( $_POST['cart_key'] ), FILTER_SANITIZE_STRING ) : null );
        // phpcs:ignore WordPress.Security.NonceVerification
        $product_name = WC()->session->get( 'cartpops_last_removed_item_name' );
        WC()->cart->restore_cart_item( $cart_item_key );
        if ( $product_name ) {
            /* translators: %s: Item name. */
            $restored_notice = sprintf( __( '%s has been added back to your cart.', 'cartpops' ), $product_name );
            $this->set_notification( $restored_notice, apply_filters( 'cartpops_cart_item_removed_notice_type', 'success' ) );
        }
        wc_clear_notices();
        $this->get_refreshed_fragments();
        wp_die();
    }

    /**
     * AJAX: Update the cart.
     *
     * @author CartPops <help@cartpops.com>
     */
    public function update_cart() {
        cartpops_set_cart_constant();
        $cart_key = ( isset( $_POST['cart_key'] ) ? sanitize_text_field( wp_unslash( $_POST['cart_key'] ) ) : '' );
        // phpcs:ignore WordPress.Security.NonceVerification
        $quantity = ( isset( $_POST['quantity'] ) ? (float) $_POST['quantity'] : '' );
        // phpcs:ignore WordPress.Security.NonceVerification
        if ( !is_numeric( $quantity ) || $quantity < 0 || !$cart_key ) {
            wp_send_json( array(
                'error' => __( 'Did not pass security check', 'cartpops' ),
            ), 403 );
            wp_die();
        }
        $message = '';
        $message_type = '';
        $removed = false;
        $in_stock = true;
        $product_qty_in_cart = WC()->cart->get_cart_item_quantities();
        $current_session_order_id = ( isset( WC()->session->order_awaiting_payment ) ? absint( WC()->session->order_awaiting_payment ) : 0 );
        foreach ( WC()->cart->get_cart() as $cart_item_key => $values ) {
            // Match target cart key  against items in cart until we find a match.
            if ( $cart_key === $cart_item_key ) {
                $product = $values['data'];
                // Check stock based on stock-status.
                if ( $product->managing_stock() && $quantity > $product->get_stock_quantity() ) {
                    $in_stock = false;
                    /* translators: %s: product name */
                    $message = sprintf( __( 'Sorry, "%s" is not in stock. Please edit your cart and try again. We apologize for any inconvenience caused.', 'cartpops' ), $product->get_name() );
                    $message_type = 'error';
                }
                // We only need to check products managing stock, with a limited stock qty.
                if ( !$product->managing_stock() || $product->backorders_allowed() ) {
                    $in_stock = true;
                    /* translators: %s: product name */
                    $message = sprintf( __( '"%s" has been updated.', 'cartpops' ), $product->get_name() );
                    $message_type = 'success';
                }
                // Check stock based on all items in the cart and consider any held stock within pending orders.
                $held_stock = wc_get_held_stock_quantity( $product, $current_session_order_id );
                $required_stock = $product_qty_in_cart[$product->get_stock_managed_by_id()];
                /**
                 * Allows filter if product have enough stock to get added to the cart.
                 *
                 * @since 4.6.0
                 * @param bool       $has_stock If have enough stock.
                 * @param WC_Product $product   Product instance.
                 * @param array      $values    Cart item values.
                 */
                if ( $product->managing_stock() && !$product->backorders_allowed() && $product->get_stock_quantity() < $held_stock + $required_stock ) {
                    $in_stock = false;
                    /* translators: 1: product name 2: quantity in stock */
                    $message = sprintf( __( 'Sorry, we do not have enough "%1$s" in stock to fulfill your order (%2$s available). We apologize for any inconvenience caused.', 'cartpops' ), $product->get_name(), wc_format_stock_quantity_for_display( $product->get_stock_quantity() - $held_stock, $product ) );
                    $message_type = 'error';
                }
                // 0 quantity means that item is being removed.
                if ( 0 === (int) $quantity ) {
                    $removed = true;
                    /* translators: %s: product name */
                    $message = sprintf( __( '"%s" was removed from your cart', 'cartpops' ), $product->get_name() );
                    $message_type = 'success';
                }
                break;
            }
        }
        if ( $in_stock ) {
            $action = WC()->cart->set_quantity( $cart_key, $quantity );
        } elseif ( $removed ) {
            $action = WC()->cart->remove_cart_item( $cart_key );
        }
        if ( $message && $message_type ) {
            $this->set_notification( $message, $message_type );
        }
        wc_clear_notices();
        $this->get_refreshed_fragments();
        wp_die();
    }

    /**
     * AJAX: Apply coupon in cart.
     *
     * @author CartPops <help@cartpops.com>
     */
    public function apply_coupon() {
        cartpops_set_cart_constant();
        $coupon_code = ( isset( $_POST['coupon'] ) ? wc_format_coupon_code( wp_unslash( $_POST['coupon'] ) ) : false );
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification
        if ( $coupon_code ) {
            if ( !WC()->cart->has_discount( $coupon_code ) ) {
                if ( WC()->cart->apply_coupon( $coupon_code ) ) {
                    $this->set_notification( esc_html__( 'Your coupon code was applied successfully.', 'cartpops' ), 'success' );
                } else {
                    $coupon = new WC_Coupon($coupon_code);
                    $discounts = new WC_Discounts(WC()->cart);
                    $valid = $discounts->is_coupon_valid( $coupon );
                    if ( is_wp_error( $valid ) ) {
                        WC()->session->set( 'cartpops_coupon_error', $valid->get_error_message() );
                    }
                    if ( $valid->get_error_message() ) {
                        $this->set_notification( $valid->get_error_message(), 'error' );
                    } else {
                        $this->set_notification( esc_html__( 'Sorry, this coupon code is not valid!', 'cartpops' ), 'error' );
                    }
                }
            } else {
                $this->set_notification( esc_html__( 'Sorry, this coupon code is already applied!', 'cartpops' ), 'error' );
            }
        } else {
            $this->set_notification( WC_Coupon::get_generic_coupon_error( WC_Coupon::E_WC_COUPON_PLEASE_ENTER ), 'error' );
        }
        wc_clear_notices();
        $this->get_refreshed_fragments();
        wp_die();
    }

    /**
     * AJAX: Remove coupon in cart.
     *
     * @author CartPops <help@cartpops.com>
     */
    public function remove_coupon() {
        cartpops_set_cart_constant();
        $coupon = ( isset( $_POST['coupon'] ) ? wc_format_coupon_code( wp_unslash( $_POST['coupon'] ) ) : false );
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification
        if ( empty( $coupon ) ) {
            $this->set_notification( __( 'Sorry there was a problem removing this coupon.', 'cartpops' ), 'error' );
        } else {
            WC()->cart->remove_coupon( $coupon );
            $this->set_notification( __( 'Coupon has been removed.', 'cartpops' ), 'success' );
        }
        wc_clear_notices();
        $this->get_refreshed_fragments();
        wp_die();
    }

    /**
     * Remember last position in cart.
     *
     * @param string $cart_item_key
     * @param object $cart
     * @author CartPops <help@cartpops.com>
     */
    public function removed_cart_item( $cart_item_key, $cart ) {
        $last_position = array_search( $cart_item_key, array_keys( $cart->cart_contents ), true );
        WC()->session->set( 'cartpops_drawer_item_removed_position', $last_position );
    }

    public function calculate_shipping() {
        WC_Shortcode_Cart::calculate_shipping();
        if ( !wc_notice_count( 'error' ) ) {
            $this->set_notification( __( 'You succesfully changed your shipping details.', 'cartpops' ), 'success' );
        }
        $this->get_refreshed_fragments();
    }

    /**
     * WErererer
     *
     * @author CartPops <help@cartpops.com>
     */
    public function update_shipping_method() {
        cartpops_set_cart_constant();
        $chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );
        $posted_shipping_methods = ( isset( $_POST['shipping_method'] ) ? wc_clean( wp_unslash( $_POST['shipping_method'] ) ) : array() );
        if ( is_array( $posted_shipping_methods ) ) {
            foreach ( $posted_shipping_methods as $i => $value ) {
                $chosen_shipping_methods[$i] = $value;
            }
        }
        WC()->session->set( 'chosen_shipping_methods', $chosen_shipping_methods );
        $this->set_notification( __( 'You succesfully changed your shipping method', 'cartpops' ), 'success' );
        $this->get_refreshed_fragments();
    }

    /**
     * Refresh WC AJAX fragments
     *
     * @author CartPops <help@cartpops.com>
     */
    public function get_refreshed_fragments() {
        ob_start();
        woocommerce_mini_cart();
        $mini_cart = ob_get_clean();
        $data = array(
            'fragments' => apply_filters( 'woocommerce_add_to_cart_fragments', array(
                'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>',
            ) ),
            'cart_hash' => WC()->cart->get_cart_hash(),
        );
        wp_send_json( $data );
    }

}
