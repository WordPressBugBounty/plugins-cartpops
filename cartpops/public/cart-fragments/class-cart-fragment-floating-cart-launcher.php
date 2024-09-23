<?php
namespace CartPops\Cart\Fragments;

if ( ! defined( 'WPINC' ) ) {
	die; }

/**
 * Renders the floating cart launcher fragment.
 *
 * @since 1.4.3
 */
class Fragment_Floating_Cart_Launcher extends Fragment {
	/**
	 * Renders the fragment.
	 *
	 * @return void
	 */
	protected function render_fragment(): void {
		$this->settings->templates->render_floating_cart_launcher();
	}
}
