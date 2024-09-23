<?php
use CartPops\Admin\Options;

class CartPops_Public {

	private $plugin_name;
	private $version;

	/**
	 * Constructor
	 *
	 * @param string $plugin_name The plugin name.
	 * @param int    $version Current plugin version.
	 * @param class  $templates Cart templates.
	 */
	public function __construct( $plugin_name, $version, $templates ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
		$this->templates   = $templates;
	}

	/**
	 * Maybe set a session for a customer if none is present yet.
	 *
	 * @author CartPops <help@cartpops.com>
	 */
	public function maybe_set_session() {

		if ( is_user_logged_in() || is_admin() ) {
			return;
		}

		if ( isset( WC()->session ) ) {
			if ( ! WC()->session->has_session() ) {
				WC()->session->set_customer_session_cookie( true );
			}
		}

	}

	/**
	 * Alter the product price display outside of is_cart() function
	 *
	 * @param string $price
	 * @param object $values
	 * @param string $cart_item_key
	 * @author CartPops <help@cartpops.com>
	 */
	public function fix_cart_price( $price, $values, $cart_item_key ) {
		$slashed_price = $values['data']->get_price_html();
		$onsale        = $values['data']->is_on_sale();

		if ( $onsale ) {
			$price = $slashed_price;
		}

		return $price;
	}

	/**
	 * Ajaxify short-code cart count.
	 *
	 * @param array $fragments Array of fragments.
	 *
	 * @return mixed
	 */
	public function menu_cart_fragments( $fragments ) {
		$has_cart = is_a( WC()->cart, 'WC_Cart' );

		if ( ! $has_cart ) {
			return $fragments;
		}

		$cart_count = WC()->cart->get_cart_contents_count();
		$cart_empty = ( 0 === $cart_count ) ? 'is-empty' : '';
		$sub_total  = WC()->cart->get_cart_subtotal();

		ob_start();
		echo '<span class="cartpops-cart__container-counter ' . esc_attr( $cart_empty ) . '">' . sprintf( _n( '%d', '%d', $cart_count, 'cartpops' ), $cart_count ) . '</span>'; // phpcs:ignore
		$cart_launcher_count = ob_get_clean();

		ob_start();
		echo '<span class="cartpops-cart__container-text">' . wp_kses_post( $sub_total ) . '</span>';
		$cart_total = ob_get_clean();

		$fragments['.cartpops-cart__container-counter'] = $cart_launcher_count;
		$fragments['.cartpops-cart__container-text']    = $cart_total;

		return $fragments;
	}

	/**
	 * Cart launcher shortcoce.
	 *
	 * @param array $atts Array of options.
	 * @author CartPops <help@cartpops.com>
	 */
	public function shortcode_cart_launcher( $atts ) {

		$cart_markup = '';

		$atts = shortcode_atts(
			array(
				'icon'                 => 'cpops-icon-shopping-cart-line',
				'subtotal'             => 'true',
				'indicator'            => 'bubble',
				'indicator_hide_empty' => 'false',
				'wrapper'              => 'true',
				'shortcode'            => 'true',
			),
			$atts
		);

		if ( ! WC()->cart ) {
			return $cart_markup;
		}

		ob_start();
		$this->templates::render_cart_launcher( $atts );
		$cart_markup = ob_get_clean();

		return $cart_markup;
	}

	/**
	 * Filter menu  by classfor any possible CartPops menu items.
	 *
	 * @param array $item_output Nav item output.
	 * @param array $item Nav item.
	 * @author CartPops <help@cartpops.com>
	 */
	public function filter_nav_menu( $item_output, $item ) {
		if ( ! is_object( $item ) || ! isset( $item->object ) ) {
			return $item_output;
		}
		$cartpops_menu_item = false;

		foreach ( $item->classes as $class => $classname ) {
			if ( 'cpops-cart-menu-item' === $classname ) {
				$cartpops_menu_item = true;
			}
		}

		if ( $cartpops_menu_item ) {

			$icon            = Options::get( 'menu_cart_launcher_icon' );
			$indicator       = Options::get( 'menu_cart_launcher_indicator' );
			$indicator_empty = Options::get( 'menu_cart_launcher_indicator_empty' );
			$subtotal        = Options::get( 'menu_cart_launcher_subtotal' );

			$args = array(
				'icon'                 => ( empty( $icon ) ) ? 'cpops-icon-shopping-cart-fill' : $icon,  // Whether to include Item/Items suffix.
				'subtotal'             => ( 'on' !== $subtotal ) ? 'false' : 'true',  // Whether to include total cost.
				'indicator'            => ( empty( $indicator ) ) ? 'bubble' : $indicator,
				'indicator_hide_empty' => ( 'on' !== $indicator_empty ) ? 'false' : 'true',  // Whether to include total cost.
				'wrapper'              => 'true',
				'menu_item'            => 'true',
			);

			ob_start();
			$this->templates::render_cart_launcher( $args );
			$cart = ob_get_clean();

			$item_output  = '<a href="#" class="menu-link">';
			$item_output .= $cart;
			$item_output .= '</a>';
		}

		return $item_output;
	}

	/**
	 * Update the cart
	 *
	 * @param array $cart_item_data
	 * @author CartPops <help@cartpops.com>
	 * @since 1.4.2
	 */
	public function woocommerce_cart_item_product( $cart_item_data ) {

		$cart_contents = WC()->cart->cart_contents;

		foreach ( $cart_contents as $key => $cart_item ) {
			/**
			 * Add cart_item key if it's a parent of a chained product.
			 *
			 * @since 1.4.2
			 */
			if ( isset( $cart_item['chained_item_of'] ) ) {
				$parent_key = $cart_item['chained_item_of'];
				WC()->cart->cart_contents[ $cart_item['chained_item_of'] ]['chained_item_parent'] = true;
			}
		}

	}
}
