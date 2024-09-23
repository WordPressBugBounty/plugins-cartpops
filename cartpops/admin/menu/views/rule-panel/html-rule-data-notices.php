<?php
/**
 *  Rule notices data.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
?>
<div id="cartpops_rule_data_notices" class="cartpops-rule-options-wrapper">
	<div class="cartpops-options-group">
		<table class="form-table">
			<tbody>

				<?php do_action( 'cartpops_before_rule_notices_settings', $rule_data ); ?>

				<tr>
					<th scope='row'>
						<label><?php esc_html_e( 'Upsell Upselll Eligibility Notice in Cart for this Rule', 'cartpops' ); ?>
							<?php cartpops_wc_help_tip( esc_html__( 'When set to "Show", a notice will be displayed to user in cart and checkout if they are eligible for receiving upsells.', 'cartpops' ) ); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped. ?>
						</label>
					</th>
					<td>
						<select name="cartpops_rule[cartpops_show_notice]" class="cartpops_rule_show_notice">
							<option value="1" <?php selected( $rule_data['cartpops_show_notice'], '1' ); ?>><?php esc_html_e( 'Hide', 'cartpops' ); ?></option>
							<option value="2" <?php selected( $rule_data['cartpops_show_notice'], '2' ); ?>><?php esc_html_e( 'Show', 'cartpops' ); ?></option>
						</select>
					</td>
				</tr>

				<tr>
					<th scope='row'>
						<label><?php esc_html_e( 'CartPops Eligibility Notice', 'cartpops' ); ?></label>
					</th>
					<td>
						<textarea name="cartpops_rule[cartpops_notice]" cols="20" rows="5" class="cartpops_rule_notice"><?php echo esc_textarea( $rule_data['cartpops_notice'] ); ?></textarea>
					</td>
				</tr>

				<?php do_action( 'cartpops_after_rule_notices_settings', $rule_data ); ?>

			</tbody>
		</table>
	</div>
	<div class="cartpops-options-group">
			<h3><?php esc_html_e( 'Shortcodes', 'cartpops' ); ?></h3>
			<table class="cartpops-shortcode-table">
				<tr>
					<th>[product_upsell_min_order_total]</th>
					<td><?php esc_html_e( 'The minimum order total required to receive free upsell(s)', 'cartpops' ); ?></td>
				</tr>
				<tr>
					<th>[product_upsell_min_sub_total]</th>
					<td><?php esc_html_e( 'The minimum cart subtotal required to receive free upsell(s)', 'cartpops' ); ?></td>
				</tr>
								<tr>
					<th>[product_upsell_min_category_sub_total]</th>
					<td><?php esc_html_e( 'The minimum category subtotal required in the cart to receive free upsell(s)', 'cartpops' ); ?></td>
				</tr>
				<tr>
					<th>[product_upsell_min_cart_qty]</th>
					<td><?php esc_html_e( 'The minimum cart quantity required to receive free upsell(s)', 'cartpops' ); ?></td>
				</tr>
				<tr>
					<th>[product_upsell_min_product_count]</th>
					<td><?php esc_html_e( 'The minimum no.of products which has to be purchased to receive free upsell(s)', 'cartpops' ); ?></td>
				</tr>
			</table>
		</div>
</div>
<?php
