<?php
/**
 * CartPops Shipping
 *
 * @package     CartPops\Templates
 * @version     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! empty( $packages ) ) {
	$package                 = $packages[0];
	$chosen_method           = isset( WC()->session->chosen_shipping_methods[0] ) ? WC()->session->chosen_shipping_methods[0] : '';
	$formatted_destination   = WC()->countries->get_formatted_address( $package['destination'], ', ' );
	$has_calculated_shipping = ! empty( WC()->customer->has_calculated_shipping() );
	$available_methods       = $package['rates'];
	$index                   = 0;
	$shipping_list_classes   = array( 'cpops-shipping__list', 'woocommerce-shipping-methods' );
	if ( 1 < count( $available_methods ) ) {
		$shipping_list_classes[] = 'cpops-shipping__list-available';
	}
}

?>

<?php if ( ! empty( $packages ) ) : ?>
	<div class="cpops-shipping-info">
		<?php if ( $available_methods ) : ?>
			<div class="cpops-shipping__destination">
				<?php
				if ( $formatted_destination ) {
					printf( '<span class="cpops-shipping__destination__to">%1$s</span><span class="cpops-shipping__destination__destination">%2$s</span>', esc_html__( 'Shipping to:', 'cartpops' ), esc_html( $formatted_destination ) );
					$calculator_text = esc_html__( 'Change address', 'woocommerce' );
				} else {
					echo wp_kses_post( apply_filters( 'woocommerce_shipping_estimate_html', __( 'Shipping options will be updated during checkout.', 'woocommerce' ) ) );
				}
				?>
			</div>

			<ul class="<?php echo esc_html( implode( ' ', array_filter( $shipping_list_classes ) ) ); ?>">
				<?php foreach ( $available_methods as $method ) : ?>
					<li class="cpops-shipping__list_item">
						<?php
						if ( 1 < count( $available_methods ) ) {
							printf( '<input type="radio" name="cpops_shipping_method[%1$d]" id="%2$s" data-index="%1$d" value="%2$s" class="cpops-pick-shipping-method" %3$s /><label for="%2$s" class="cpops-shipping__list_label">%4$s</label>', $index, esc_attr( $method->id ), checked( $method->id, $chosen_method, false ), wc_cart_totals_shipping_method_label( $method ) );
						} else {
							printf( '<input type="hidden" name="cpops_shipping_method[%1$d]" data-index="%1$d" value="%2$s" class="cpops-pick-shipping-method" />', $index, esc_attr( $method->id ) );
						}
						do_action( 'woocommerce_after_shipping_rate', $method, $index );
						?>
					</li>
				<?php endforeach; ?>
			</ul>

		<?php elseif ( ! $has_calculated_shipping || ! $formatted_destination ) : ?>
			<?php
			if ( $shipping_calculator ) {
				echo wp_kses_post( apply_filters( 'woocommerce_shipping_may_be_available_html', __( 'Enter your address to view shipping options.', 'woocommerce' ) ) );
			} else {
				echo wp_kses_post( apply_filters( 'woocommerce_shipping_not_enabled_on_cart_html', __( 'Shipping costs are calculated during checkout.', 'woocommerce' ) ) );
			}
			?>
		<?php else : ?>
			<?php
				// translators: %s is an address.
				echo wp_kses_post( apply_filters( 'woocommerce_cart_no_shipping_available_html', sprintf( esc_html__( 'No shipping options were found for %s.', 'woocommerce' ) . ' ', '<strong>' . esc_html( $formatted_destination ) . '</strong>' ) ) );
				$calculator_text = esc_html__( 'Enter a different address', 'woocommerce' );
			?>
		<?php endif; ?>
	</div>
<?php endif; ?>

<?php if ( $shipping_calculator ) : ?>
	<?php woocommerce_shipping_calculator( $calculator_text ); ?>
<?php endif; ?>
