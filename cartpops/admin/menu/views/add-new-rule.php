<?php
/* New Rule Page */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$categories = cartpops_get_wc_categories();
?>
<div class="woocommerce cartpops_rule_wrapper cartpops_new_rule">
	<h2><?php esc_html_e( 'New Upsell Rule', 'cartpops' ); ?></h2>
	<table class="form-table">
		<tbody>
			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Upsell Status', 'cartpops' ); ?><span class="required">*</span>
						<?php cartpops_wc_help_tip( esc_html__( 'When set to Active, the products from this rule will be listed to the user. The user can choose their Upsell from the available products.', 'cartpops' ) ); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped. ?>
					</label>
				</th>
				<td>
					<select name="cartpops_rule[cartpops_rule_status]">
						<option value="cartpops_active" <?php selected( $rule_data['cartpops_rule_status'], 'cartpops_active' ); ?>><?php esc_html_e( 'Active', 'cartpops' ); ?></option>
						<option value="cartpops_inactive" <?php selected( $rule_data['cartpops_rule_status'], 'cartpops_inactive' ); ?>><?php esc_html_e( 'In-active', 'cartpops' ); ?></option>
					</select>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Upsell Name', 'cartpops' ); ?><span class="required">*</span></label>
				</th>
				<td>
					<input type="text" name="cartpops_rule[cartpops_upsell_name]" value="<?php echo esc_attr( $rule_data['cartpops_upsell_name'] ); ?>"/>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Description', 'cartpops' ); ?></label>
				</th>
				<td>
					<textarea name="cartpops_rule[cartpops_upsell_desc]"><?php echo esc_html( $rule_data['cartpops_upsell_desc'] ); ?></textarea>
				</td>
			</tr>

		</tbody>
	</table>
	<?php
	self::output_panel();
	?>
	<p class="submit">
		<input name='cartpops_save' class='button-primary cartpops_save_btn' type='submit' value="<?php esc_attr_e( 'Add Rule', 'cartpops' ); ?>" />
		<?php wp_nonce_field( 'cartpops_new_rule', '_cartpops_nonce', false, true ); ?>
	</p>
</div>
<?php
