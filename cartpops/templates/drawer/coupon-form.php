<?php
/**
 * CartPops Drawer Coupon Form
 *
 * @package     CartPops\Templates
 * @version     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>

<?php do_action( 'cartpops_drawer_coupon_wrapper_start' ); ?>

<div class="<?php echo esc_html( implode( ' ', array_filter( $classes ) ) ); ?>">

	<span><?php echo esc_html( $title ); ?></span>

	<?php do_action( 'cartpops_drawer_coupon_form_before' ); ?>

	<form class="cpops-coupon-form">
		<div class="cpops-drawer-coupon__input">
			<input type="text" id="cpops-coupon-input" placeholder="<?php echo esc_html( $placeholder_text ); ?>" required>
			<button type="submit" class="cpops-drawer-form__button cpops-button"><?php echo esc_html( $button_text ); ?></button>
		</div>
	</form>

	<?php do_action( 'cartpops_drawer_coupon_form_after' ); ?>

</div>

<?php do_action( 'cartpops_drawer_coupon_wrapper_end' ); ?>
