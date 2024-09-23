<?php
namespace CartPops\Cart\Fragments;

if ( ! defined( 'WPINC' ) ) {
	die; }

/**
 * Renders the recommendation fragment.
 *
 * @since 1.4.3
 */
class Fragment_Drawer_Recommendations extends Fragment {
	/**
	 * Renders the fragment.
	 *
	 * @return void
	 */
	protected function render_fragment(): void {
		$this->settings->templates->render_drawer_recommendations();
	}
}
