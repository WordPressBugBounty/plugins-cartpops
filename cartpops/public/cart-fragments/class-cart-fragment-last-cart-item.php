<?php
namespace CartPops\Cart\Fragments;

if ( ! defined( 'WPINC' ) ) {
	die; }

/**
 * Renders the "last cart item" fragment.
 *
 * @since 1.4.3
 */
class Fragment_Last_Cart_Item extends Fragment {
	/**
	 * Indicates if the fragment should be rendered.
	 *
	 * @return boolean
	 */
	protected function should_render(): bool {
		return ( $this->settings->templates->trigger_type === 'bar' || $this->settings->templates->trigger_type === 'popup' ) && fs_cartpops()->is__premium_only();
	}

	/**
	 * Renders the fragment.
	 *
	 * @return void
	 */
	protected function render_fragment(): void {
		$product = cartpops_get_product( $this->settings->product_id );

		?>
		<div class="cpops-added-product-item">
		<?php
			$this->settings->templates->render_last_cart_item__premium_only( $product );
		?>
		</div>
		<?php
	}
}
