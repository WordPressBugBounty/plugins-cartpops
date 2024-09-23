<?php
namespace CartPops\Cart\Fragments;

if ( ! defined( 'WPINC' ) ) {
	die; }

/**
 * Renders the free shipping meter fragment.
 *
 * @since 1.4.3
 */
class Fragment_Free_Shipping_Meter extends Fragment {
	/**
	 * Indicates if the fragment should be rendered.
	 *
	 * @return boolean
	 */
	protected function should_render(): bool {
		return fs_cartpops()->is__premium_only();
	}

	/**
	 * Renders the fragment.
	 *
	 * @return void
	 */
	protected function render_fragment(): void {
		$this->settings->templates->render_free_shipping_meter__premium_only();
	}
}
