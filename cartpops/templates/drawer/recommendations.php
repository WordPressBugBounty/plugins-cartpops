<?php
/**
 * CartPops Drawer Recommendations
 *
 * @package     CartPops\Templates
 * @version     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>

<div class="cpops-drawer-recommendations-wrapper">
	<div class="<?php echo esc_html( implode( ' ', array_filter( $classes ) ) ); ?>">
		<div class="cpops-drawer-recommendations__header">
			<?php echo esc_html( $title ); ?>
		</div>

		<div class="cpops-slider" id="cpops-drawer-recommendations__slider">
			<div class="cpops-slider__track">
				<div class="cpops-slider__list">

					<?php foreach ( $recommended_products as $key => $recommended_product ) : ?>

						<?php
							$product_permalink = $recommended_product->is_visible() ? $recommended_product->get_permalink() : '';
						?>

						<div class="cpops-cart-item cpops-slider__slide">

							<div class="cpops-cart-item__container">

								<div class="cpops-cart-item__image">
									<?php if ( $product_permalink ) : ?>
										<a href="<?php echo esc_url( $product_permalink ); ?>">
									<?php endif; ?>

									<?php echo wp_get_attachment_image( $recommended_product->get_image_id(), 'thumbnail' ); ?>

									<?php if ( $product_permalink ) : ?>
										</a>
									<?php endif; ?>
								</div>

								<div class="cpops-cart-item__product">

									<div class="cpops-cart-item__details">
										<div class="cpops-cart-item__product--name">
											<div class="cpops-cart-item__product--link">
												<?php if ( $product_permalink ) : ?>
													<a href="<?php echo esc_url( $product_permalink ); ?>">
												<?php endif; ?>

												<?php echo $recommended_product->get_name(); ?></a>

												<?php if ( $product_permalink ) : ?>
												</a>
												<?php endif; ?>
											</div>
										</div>
										<div class="cpops-cart-item__slider-pricing">
											<div class="cpops-cart-item__slider-pricing--price">
												<?php echo $recommended_product->get_price_html(); ?>
											</div>
										</div>
									</div>

								</div>

							</div>

								<div class="cpops-cart-item__actions">
									<?php

									$classes                   = array( 'cpops-btn__upsell ', 'cpops-button' );
									$simple_product_classes    = array( 'add_to_cart_button', 'ajax_add_to_cart' );
									$simple_product_attributes = array();
									$upsell_button_text        = __( 'Select options', 'cartpops' );
									$upsell_button_type        = 'text';

									if ( $recommended_product->is_type( 'simple' ) ) {
										$classes = array_merge( $classes, $simple_product_classes );
									}

									$attributes = array(
										'type'  => 'button',
										'class' => esc_html( implode( ' ', array_filter( $classes ) ) ),
										'href'  => esc_url( $product_permalink ),
									);

									if ( $recommended_product->is_type( 'simple' ) ) {
										$simple_product_attributes = array(
											'value'           => $recommended_product->get_id(),
											'data-cpops-cart-open' => 'false',
											'data-cpops-type' => 'recommendation',
											'data-quantity'   => '1',
											'data-product_id' => $recommended_product->get_id(),
											'href'            => '#',
										);
										$attributes                = array_merge( $attributes, $simple_product_attributes );
										$upsell_button_text        = $button_text;
										$upsell_button_type        = $button_type;
									}

									?>
										<div class="cpops-add-to-cart">
											<a <?php echo cartpops_implode_html_attributes( $attributes ); ?>">
												<?php if ( 'text' === $upsell_button_type || 'text_icon' === $upsell_button_type ) : ?>
													<span><?php echo esc_html( $upsell_button_text ); ?></span>
												<?php endif; ?>
												<?php if ( 'icon' === $upsell_button_type || 'text_icon' === $upsell_button_type ) : ?>
													<svg aria-hidden="true" focusable="false" role="presentation" class="cpops-icon" viewBox="0 0 20 20"><path fill="#444" d="M17.409 8.929h-6.695V2.258c0-.566-.506-1.029-1.071-1.029s-1.071.463-1.071 1.029v6.671H1.967C1.401 8.929.938 9.435.938 10s.463 1.071 1.029 1.071h6.605V17.7c0 .566.506 1.029 1.071 1.029s1.071-.463 1.071-1.029v-6.629h6.695c.566 0 1.029-.506 1.029-1.071s-.463-1.071-1.029-1.071z"></path></svg>
												<?php endif; ?>
											</a>
										</div>
								</div>

						</div>

					<?php endforeach; ?>
				</div>
			</div>
		</div>
	</div>
</div>