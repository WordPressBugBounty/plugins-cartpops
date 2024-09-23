<?php
namespace CartPops\Cart\Fragments;

if ( ! defined( 'WPINC' ) ) {
	die; }

/**
 * Extends the Cart Fragment data class to introduce elements specific
 * to cart item fragments.
 *
 * @since 1.4.3
 */
class Cart_Item_Fragment_Settings extends Cart_Fragment_Settings {
	/**
	 * An array representing a cart item.
	 *
	 * @var array
	 */
	protected $cart_item = [];
}
