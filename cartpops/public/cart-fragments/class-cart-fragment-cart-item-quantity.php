<?php
namespace CartPops\Cart\Fragments;

if ( ! defined( 'WPINC' ) ) {
	die; }

/**
 * Renders the "cart item quantity" fragment.
 *
 * @since 1.4.3
 */
class Fragment_Cart_Item_Quantity extends Fragment {
	/**
	 * The product contained in the cart item.
	 *
	 * @var WC_Product
	 */
	protected $cart_item_product;

	/**
	 * Constructor.
	 *
	 * @param Cart_Item_Fragment_Settings $settings
	 */
	public function __construct( Cart_Item_Fragment_Settings $settings ) {
		parent::__construct( $settings );
	}

	/**
	 * Returns the instance of the product contained in the cart item.
	 *
	 * @return \WC_Product
	 */
	protected function get_cart_item_product(): \WC_Product {
		$cart_item = $this->settings->cart_item;
		return $this->cart_item_product ?? $this->cart_item_product = cartpops_cart_item_product( $cart_item, $cart_item['key'] );
	}

	/**
	 * Indicates if the fragment should be rendered.
	 *
	 * @return boolean
	 */
	protected function should_render(): bool {
		$product = $this->get_cart_item_product();
		return ( ( $product instanceof \WC_Product ) && $product->exists() && $this->settings->cart_item['quantity'] > 0 && apply_filters( 'woocommerce_widget_cart_item_visible', true, $this->settings->cart_item, $this->settings->cart_item['key'] ) );
	}

	/**
	 * Renders the fragment.
	 *
	 * @return void
	 */
	protected function render_fragment(): void {
		$product = $this->get_cart_item_product();
		$this->settings->templates->render_quantity_selectors(
			array(
				'input_value'   => $this->settings->cart_item['quantity'],
				'quantity'      => $this->settings->cart_item['quantity'],
				'max_value'     => $product->get_max_purchase_quantity(),
				'min_value'     => $product->get_min_purchase_quantity(),
				'product_name' => apply_filters(
					'woocommerce_cart_item_name',
					$product->get_name(),
					$this->settings->cart_item,
					$this->settings->cart_item['key']
				),
				'cart_item_key' => $this->settings->cart_item['key'],
			),
			$product
		);
	}
}
