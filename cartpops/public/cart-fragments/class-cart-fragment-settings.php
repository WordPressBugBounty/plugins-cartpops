<?php
namespace CartPops\Cart\Fragments;

use CartPops\Base\Data\Base_Data_Object;

if ( ! defined( 'WPINC' ) ) {
	die; }

class Cart_Fragment_Settings extends Base_Data_Object {
	/**
	 * The request type.
	 *
	 * @var string
	 * @see CartPops_Frontend_Ajax::cart_fragments()
	 */
	protected $request_type;

	/**
	 * The ID of the product from the context in which a fragment is being rendered.
	 *
	 * @var int|null
	 * @see CartPops_Frontend_Ajax::cart_fragments()
	 */
	protected $product_id;

	/**
	 * A cart instance.
	 *
	 * @var WC_Cart
	 */
	protected $cart;

	/**
	 * The notices that might have to be displayed by the cart fragments.
	 *
	 * @var string
	 */
	protected $notice = '';

	/**
	 * The templates passed to the fragment.
	 *
	 * @var CartPops_Cart
	 */
	protected $templates;
}
