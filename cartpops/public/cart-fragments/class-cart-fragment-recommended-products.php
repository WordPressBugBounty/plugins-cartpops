<?php
namespace CartPops\Cart\Fragments;

if ( ! defined( 'WPINC' ) ) {
	die; }

/**
 * Renders the "recommended products" fragment.
 *
 * @since 1.4.3
 */
class Fragment_Recommended_Products extends Fragment {
	/**
	 * Indicates if the fragment should be rendered.
	 *
	 * @return boolean
	 */
	protected function should_render(): bool {
		return ( $this->settings->templates->trigger_type === 'popup' ) && ! empty( $this->settings->product_id ) && fs_cartpops()->is__premium_only();
	}

	/**
	 * Renders the fragment.
	 *
	 * @return void
	 */
	protected function render_fragment(): void {
		$this->settings->templates->render_popup_recommendations__premium_only();
	}
}
