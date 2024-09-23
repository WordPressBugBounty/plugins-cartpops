<?php
/**
 * CartPops Price
 *
 * @package     CartPops\Templates
 * @version     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>

<div class="cpops-cart-item__actions--pricing">
	<div class="cpops-price">
		<?php echo cartpops_cart_item_price( $product, $cart_item, $cart_item_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
</div>
