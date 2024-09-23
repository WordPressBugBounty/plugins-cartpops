<?php
namespace CartPops\Cart\Fragments;

if ( ! defined( 'WPINC' ) ) {
	die; }

/**
 * Renders the coupon removal fragment.
 *
 * @since 1.4.3
 */
class Fragment_Coupon_Removal extends Fragment {
	/**
	 * Indicates if the fragment should be rendered.
	 *
	 * @return boolean
	 */
	protected function should_render(): bool {
		return ! empty( $this->settings->cart->get_applied_coupons() );
	}

	/**
	 * Renders the fragment.
	 *
	 * @return void
	 */
	protected function render_fragment(): void {
		$this->settings->templates->render_coupon_removal();
	}
}
