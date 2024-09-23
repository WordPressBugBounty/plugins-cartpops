<?php
/**
 * CartPops Quantity Selectors
 *
 * @package     CartPops\Templates
 * @version     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>

<div class="cpops-cart-item__quantity-selector">

	<div class="cpops-cart-item__quantity">
		<button class="quantity__button--down" data-key="<?php echo esc_attr( $cart_item_key ); ?>" data-action="down">
			<svg aria-hidden="true" focusable="false" role="presentation" class="cpops-icon" viewBox="0 0 20 20"><path fill="#444" d="M17.543 11.029H2.1A1.032 1.032 0 0 1 1.071 10c0-.566.463-1.029 1.029-1.029h15.443c.566 0 1.029.463 1.029 1.029 0 .566-.463 1.029-1.029 1.029z"></path></svg>
			<span class="cpops-sr-only" aria-hidden="true">−</span>
		</button>

		<input class="cpops-quantity__input"
			type="number"
			aria-label="Quantity"
			step="<?php echo esc_attr( $step ); ?>"
			min="<?php echo esc_attr( $min_value ); ?>"
			max="<?php echo esc_attr( 0 < $max_value ? $max_value : '' ); ?>"
			value="<?php echo esc_attr( $quantity ); ?>"
			placeholder="<?php echo esc_attr( $placeholder ); ?>"
			inputmode="<?php echo esc_attr( $inputmode ); ?>"
			data-key="<?php echo esc_attr( $cart_item_key ); ?>"
			id="q-<?php echo esc_attr( $cart_item_key ); ?>"
			data-action="input"
			pattern="<?php echo esc_attr( $pattern ); ?>"
		>

		<button class="quantity__button--up" data-key="<?php echo esc_attr( $cart_item_key ); ?>" data-action="up">
			<svg aria-hidden="true" focusable="false" role="presentation" class="cpops-icon" viewBox="0 0 20 20"><path fill="#444" d="M17.409 8.929h-6.695V2.258c0-.566-.506-1.029-1.071-1.029s-1.071.463-1.071 1.029v6.671H1.967C1.401 8.929.938 9.435.938 10s.463 1.071 1.029 1.071h6.605V17.7c0 .566.506 1.029 1.071 1.029s1.071-.463 1.071-1.029v-6.629h6.695c.566 0 1.029-.506 1.029-1.071s-.463-1.071-1.029-1.071z"></path></svg>
			<span class="cpops-sr-only" aria-hidden="true">+</span>
		</button>
	</div>

</div>

