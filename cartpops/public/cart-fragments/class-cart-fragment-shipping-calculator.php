<?php
namespace CartPops\Cart\Fragments;

use CartPops\Admin\Options;

if ( ! defined( 'WPINC' ) ) {
	die; }

/**
 * Renders the shipping calculator fragment.
 *
 * @since 1.4.3
 */
class Fragment_Shipping_Calculator extends Fragment {
	/**
	 * Indicates if the fragment should be rendered.
	 *
	 * @return boolean
	 */
	protected function should_render(): bool {
		return fs_cartpops()->is__premium_only() && 'on' === Options::get( 'drawer_footer_display_shipping_calculator' );
	}

	/**
	 * Renders the fragment.
	 *
	 * @return void
	 */
	protected function render_fragment(): void {
		$this->settings->templates->render_assistant_items__premium_only();
	}
}
