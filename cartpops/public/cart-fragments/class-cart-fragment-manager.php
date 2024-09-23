<?php
namespace CartPops\Cart\Fragments;

if ( ! defined( 'WPINC' ) ) {
	die; }

/**
 * Handles the available cart fragments and offers methods to render them.
 *
 * @since 1.4.3
 */
	// TODO Refactor class, moving the methods shared with other Cart_Item fragments to a common ancestor
class Cart_Fragment_Manager {
	/**
	 * Maps the cart fragment IDs to the corresponding fragment.
	 *
	 * @var array
	 */
	protected static $available_fragments = [
		'notices'                => '\CartPops\Cart\Fragments\Fragment_Notices',
		'cart_launcher'          => '\CartPops\Cart\Fragments\Fragment_Floating_Cart_Launcher',
		'cart_totals'            => '\CartPops\Cart\Fragments\Fragment_Cart_Totals',
		'coupon_removal'         => '\CartPops\Cart\Fragments\Fragment_Coupon_Removal',
		'drawer_recommendations' => '\CartPops\Cart\Fragments\Fragment_Drawer_Recommendations',
		'cart_item_price'        => '\CartPops\Cart\Fragments\Fragment_Cart_Item_Price',
		'cart_item_quantity'     => '\CartPops\Cart\Fragments\Fragment_Cart_Item_Quantity',
		'drawer_cart'            => '\CartPops\Cart\Fragments\Fragment_Drawer_Cart',
		'free_shipping_meter'    => '\CartPops\Cart\Fragments\Fragment_Free_Shipping_Meter',
		'recommended_products'   => '\CartPops\Cart\Fragments\Fragment_Recommended_Products',
		'added_product_item'     => '\CartPops\Cart\Fragments\Fragment_Last_Cart_Item',
		'assistant_items'        => '\CartPops\Cart\Fragments\Fragment_Shipping_Calculator',
	];

	/**
	 * Given a list of fragments, it returns the corresponding HTML. Each piece of rendered
	 * HTML is associated to the fragment ID's original key.
	 *
	 * @param array                  $fragments
	 * @param Cart_Fragment_Settings $settings
	 * @return void
	 */
	public static function render_fragments( array $fragments, Cart_Fragment_Settings $settings ): array {
		$fragments_html = [];
		foreach ( $fragments as $key => $fragment_id ) {
			$fragments_html[ $key ] = self::get_fragment_html( $fragment_id, $settings );
		}
		return $fragments_html;
	}

	/**
	 * Given a fragment ID, it returns the corresponding HTML.
	 *
	 * @param string                 $fragment_id
	 * @param Cart_Fragment_Settings $settings
	 * @return string
	 */
	public static function get_fragment_html( string $fragment_id, Cart_Fragment_Settings $settings ): string {
		$available_fragments = apply_filters( 'cartpops_available_cart_fragments', static::$available_fragments );

		$fragment_class = $available_fragments[ $fragment_id ] ?? null;
		if ( isset( $fragment_class ) && class_exists( $fragment_class ) ) {
			return trim( $fragment_class::get_html( $settings ) );
		}
		return '';
	}
}
