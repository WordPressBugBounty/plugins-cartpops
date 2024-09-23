<?php
namespace CartPops\Cart\Fragments;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Renders the cart contents fragment.
 *
 * @since 1.4.3
 */
class Fragment_Drawer_Cart extends Fragment {
	/**
	 * Renders the fragment.
	 *
	 * @return void
	 */
	protected function render_fragment(): void {
		$this->settings->templates->render_cart_contents();
	}
}
