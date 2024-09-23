<?php
/**
 * CartPops Cart Item
 *
 * @package     CartPops\Templates
 * @version     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$args = array(
	'cart_item_key' => $cart_item_key,
	'cart_item'     => $cart_item,
	'product'       => $product,
);

?>

<div class="<?php echo esc_html( implode( ' ', array_filter( $classes ) ) ); ?>" data-key="<?php echo esc_attr( $cart_item_key ); ?>" data-id="<?php echo esc_html( $product_id ); ?>">

	<div class="cpops-cart-item__container">

			<div class="cpops-cart-item__image">
				<?php if ( $product_permalink ) : ?>
					<a href="<?php echo esc_url( $product_permalink ); ?>">
				<?php endif; ?>

				<?php if ( $thumbnail ) : ?>
					<?php echo $thumbnail; ?>
				<?php else : ?>
					<?php echo $thumbnail_fallback; ?>
				<?php endif; ?>

				<?php if ( $product_permalink ) : ?>
					</a>
				<?php endif; ?>
			</div>

		<div class="cpops-cart-item__product">

			<div class="cpops-cart-item__details">
				<div class="cpops-cart-item__product--name"><?php echo $product_name; ?></div>
			</div>

			<?php if ( $quantity ) : ?>
				<?php echo $quantity; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endif; ?>
		</div>
	</div>

	<div class="cpops-cart-item__actions">
		<?php if ( $delete ) : ?>
			<button aria-label="Remove Item From Cart" class="cpops-cart-item__actions--remove" data-id="<?php echo esc_html( $product_id ); ?>" data-key="<?php echo esc_html( $cart_item_key ); ?>" data-name="<?php echo esc_html( $product->get_name() ); ?>" >
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512.001 512.001">
					<defs/>
					<path d="M512.001 84.853L427.148 0 256.001 171.147 84.853 0 0 84.853 171.148 256 0 427.148l84.853 84.853 171.148-171.147 171.147 171.147 84.853-84.853L340.853 256z"/>
				</svg>
			</button>
		<?php endif; ?>

		<?php if ( $product_subtotal ) : ?>
			<?php cartpops_get_template( 'components/product/price', $args ); ?>
		<?php endif; ?>
	</div>

</div>
