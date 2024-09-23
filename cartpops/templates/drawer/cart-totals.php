<?php
/**
 * CartPops Cart Totals
 *
 * @package     CartPops\Templates
 * @version     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>

<div class="<?php echo esc_html( implode( ' ', array_filter( $classes ) ) ); ?>">

	<div class="cpops-cart-line-items">
		<?php if ( ! empty( $subtotal ) ) : ?>
			<?php echo wp_kses_post( $subtotal ); ?>
		<?php endif; ?>

		<?php if ( ! empty( $discount ) ) : ?>
			<?php echo wp_kses_post( $discount ); ?>
		<?php endif; ?>

		<?php if ( ! empty( $tax ) ) : ?>
			<?php echo wp_kses_post( $tax ); ?>
		<?php endif; ?>

		<?php if ( ! empty( $shipping ) ) : ?>
			<?php echo wp_kses_post( $shipping ); ?>
		<?php endif; ?>

		<?php if ( ! empty( $total ) ) : ?>
			<?php echo wp_kses_post( $total ); ?>
		<?php endif; ?>
	</div>

	<?php do_action( 'cartpops_drawer_before_secondary_checkout_button' ); ?>
		<?php if ( ! empty( $secondary_btn ) ) : ?>
			<?php echo wp_kses_post( $secondary_btn ); ?>
		<?php endif; ?>
	<?php do_action( 'cartpops_drawer_after_secondary_checkout_button' ); ?>

	<?php do_action( 'cartpops_drawer_before_checkout_button' ); ?>

	<div class="wc-proceed-to-checkout">
		<a class="checkout-button wc-forward" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $button_text ); ?></a>
	</div>

	<?php do_action( 'cartpops_drawer_after_checkout_button' ); ?>

</div>
