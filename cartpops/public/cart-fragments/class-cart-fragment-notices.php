<?php
namespace CartPops\Cart\Fragments;

if ( ! defined( 'WPINC' ) ) {
	die; }

/**
 * Renders the "notices" fragment.
 *
 * @since 1.4.3
 */
class Fragment_Notices extends Fragment {
	/**
	 * Indicates if the fragment should be rendered.
	 *
	 * @return boolean
	 */
	protected function should_render(): bool {
		// Render the content only if we have a notice, and if we're not refreshing the cart
		return ! empty( $this->settings->notice ) && ! in_array( $this->settings->request_type, [ 'refresh_cart' ], true );
	}

	/**
	 * Renders the fragment.
	 *
	 * @return void
	 */
	protected function render_fragment(): void {
		?>
		<div class="cpops-drawer-notices-wrapper">
		<?php
			echo $this->settings->notice;
		?>
		</div>
		<?php
	}
}
