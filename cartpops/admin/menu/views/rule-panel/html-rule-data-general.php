<?php
/**
 *  Rule General data.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$categories = cartpops_get_wc_categories();
?>
<div id="cartpops_rule_data_general" class="cartpops-rule-options-wrapper">
	<table class="form-table">

		<?php do_action( 'cartpops_before_rule_general_settings', $rule_data ); ?>

		<tbody>
			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Upsell Product Selection Type', 'cartpops' ); ?><span class="required">*</span></label>
				</th>
				<td>
					<select name="cartpops_rule[cartpops_upsell_type]" class = "cartpops_upsell_type cartpops_rule_type cartpops_manual_rule_type cartpops_automatic_rule_type">
						<option value="1" <?php selected( $rule_data['cartpops_upsell_type'], '1' ); ?>><?php esc_html_e( 'Selected Product(s)', 'cartpops' ); ?></option>
						<option value="2" <?php selected( $rule_data['cartpops_upsell_type'], '2' ); ?>><?php esc_html_e( 'Products from Selected Categories', 'cartpops' ); ?></option>
					</select>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Select Product(s)', 'cartpops' ); ?><span class="required">*</span>
						<?php cartpops_wc_help_tip( esc_html__( 'The selected products will be displayed to the user', 'cartpops' ) ); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped. ?>
					</label>
				</th>
				<td>
					<?php
					$upsell_product_args = array(
						'class'       => 'cartpops_upsell_products cartpops_rule_type',
						'name'        => 'cartpops_rule[cartpops_upsell_products]',
						'list_type'   => 'products',
						'action'      => 'cartpops_json_search_products_and_variations',
						// 'exclude_global_variable' => 'yes' ,
						'placeholder' => esc_html__( 'Search a Product', 'cartpops' ),
						'options'     => $rule_data['cartpops_upsell_products'],
					);
					cartpops_select2_html( $upsell_product_args );
					?>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Select Categories', 'cartpops' ); ?><span class="required">*</span>
						<?php cartpops_wc_help_tip( esc_html__( 'The products from the selected categories will be displayed to the user', 'cartpops' ) ); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped. ?>
					</label>
				</th>
				<td>
					<select class="cartpops_upsell_categories cartpops_select2 cartpops_rule_type" name="cartpops_rule[cartpops_upsell_categories][]" multiple="multiple">
						<?php
						foreach ( $categories as $category_id => $category_name ) :
							$selected = ( in_array( $category_id, $rule_data['cartpops_upsell_categories'] ) ) ? ' selected="selected"' : '';
							?>
							<option value="<?php echo esc_attr( $category_id ); ?>"<?php echo esc_attr( $selected ); ?>><?php echo esc_html( $category_name ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Quantity for Selected Upsell Product(s)', 'cartpops' ); ?><span class="required">*</span></label>
				</th>
				<td>
					<input type="number" class="cartpops_rule_type cartpops_automatic_rule_type" name="cartpops_rule[cartpops_automatic_product_qty]" min="1" value="<?php echo esc_attr( $rule_data['cartpops_automatic_product_qty'] ); ?>"/>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Upsell Product Type', 'cartpops' ); ?><span class="required">*</span>
						<?php cartpops_wc_help_tip( esc_html__( 'When set to Same Product, the user will receive the specified quantities of the same product for free. When set to Different products, the user will receive the specified quantities of another product  for free.', 'cartpops' ) ); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped. ?>
					</label>
				</th>
				<td>
					<select name="cartpops_rule[cartpops_bogo_upsell_type]" class = "cartpops_bogo_upsell_type cartpops_rule_type cartpops_bogo_rule_type">
						<option value="1" <?php selected( $rule_data['cartpops_bogo_upsell_type'], '1' ); ?>><?php esc_html_e( 'Same Product', 'cartpops' ); ?></option>
						<option value="2" <?php selected( $rule_data['cartpops_bogo_upsell_type'], '2' ); ?>><?php esc_html_e( 'Different Products', 'cartpops' ); ?></option>
					</select>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Buy Product Type', 'cartpops' ); ?><span class="required">*</span>
						<?php cartpops_wc_help_tip( esc_html__( 'Products: The user will receive a Upsell if they purchase the selected product. Categories: The user will receive a Upsell if they purchase any one product from the selected category.', 'cartpops' ) ); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped. ?>
					</label>
				</th>
				<td>
					<select name="cartpops_rule[cartpops_buy_product_type]" class = "cartpops_buy_product_type cartpops_bogo_rule_type cartpops_rule_type">
						<option value="1" <?php selected( $rule_data['cartpops_buy_product_type'], '1' ); ?>><?php esc_html_e( 'Product', 'cartpops' ); ?></option>
						<option value="2" <?php selected( $rule_data['cartpops_buy_product_type'], '2' ); ?>><?php esc_html_e( 'Category', 'cartpops' ); ?></option>
					</select>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Buy Product', 'cartpops' ); ?><span class="required">*</span></label>
				</th>
				<td>
					<?php
					$buy_product_args = array(
						'class'                   => 'cartpops_buy_product cartpops_rule_type cartpops_bogo_rule_type',
						'name'                    => 'cartpops_rule[cartpops_buy_product]',
						'list_type'               => 'products',
						'action'                  => 'cartpops_json_search_products_and_variations',
						'exclude_global_variable' => 'yes',
						'multiple'                => false,
						'placeholder'             => esc_html__( 'Search a Product', 'cartpops' ),
						'options'                 => $rule_data['cartpops_buy_product'],
					);
					cartpops_select2_html( $buy_product_args );
					?>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Buy Quantity Calculated Based on', 'cartpops' ); ?><span class="required">*</span>
						<?php cartpops_wc_help_tip( esc_html__( "Same Product's Quantity: Quantity must match for each product to receive a free upsell. Total Quantity of the Selected Category's Products: Quantity must match either for each product or quantity of products which belong to the selected category should match to receive a free upsell.", 'cartpops' ) ); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped. ?>
					</label>
				</th>
				<td>
					<select name="cartpops_rule[cartpops_buy_category_type]" class = "cartpops_buy_category_type cartpops_buy_categories cartpops_bogo_rule_type cartpops_rule_type cartpops_get_products">
						<option value="1" <?php selected( $rule_data['cartpops_buy_category_type'], '1' ); ?>><?php esc_html_e( "Same Product's Quantity", 'cartpops' ); ?></option>
						<option value="2" <?php selected( $rule_data['cartpops_buy_category_type'], '2' ); ?>><?php esc_html_e( "Total Quantity of the Selected Category's Products", 'cartpops' ); ?></option>
					</select>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Buy Category', 'cartpops' ); ?><span class="required">*</span></label>
				</th>
				<td>
					<select class="cartpops_buy_categories cartpops_select2 cartpops_bogo_rule_type cartpops_rule_type" name="cartpops_rule[cartpops_buy_categories][]">
						<?php
						foreach ( $categories as $category_id => $category_name ) :
							$selected = ( in_array( $category_id, $rule_data['cartpops_buy_categories'] ) ) ? ' selected="selected"' : '';
							?>
							<option value="<?php echo esc_attr( $category_id ); ?>"<?php echo esc_attr( $selected ); ?>><?php echo esc_html( $category_name ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Get Product(s)', 'cartpops' ); ?><span class="required">*</span></label>
				</th>
				<td>
					<?php
					$get_product_args = array(
						'class'                   => 'cartpops_get_products cartpops_rule_type',
						'name'                    => 'cartpops_rule[cartpops_get_products]',
						'list_type'               => 'products',
						'action'                  => 'cartpops_json_search_products_and_variations',
						'exclude_global_variable' => 'yes',
						'placeholder'             => esc_html__( 'Search a Product', 'cartpops' ),
						'options'                 => $rule_data['cartpops_get_products'],
					);
					cartpops_select2_html( $get_product_args );
					?>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Buy Quantity', 'cartpops' ); ?><span class="required">*</span></label>
				</th>
				<td>
					<input type="number" class="cartpops_rule_type cartpops_bogo_rule_type" name="cartpops_rule[cartpops_buy_product_count]" min="1" value="<?php echo esc_attr( $rule_data['cartpops_buy_product_count'] ); ?>"/>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Get Quantity', 'cartpops' ); ?><span class="required">*</span></label>
				</th>
				<td>
					<input type="number" class="cartpops_rule_type cartpops_bogo_rule_type" name="cartpops_rule[cartpops_get_product_count]" min="1" value="<?php echo esc_attr( $rule_data['cartpops_get_product_count'] ); ?>"/>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Repeat Upsell', 'cartpops' ); ?><span class="required">*</span>
						<?php cartpops_wc_help_tip( esc_html__( 'When enabled, the user will keep receiving upsells every time they add the multiples of the required quantity to the cart.', 'cartpops' ) ); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped. ?>
					</label>
				</th>
				<td>
					<input type="checkbox" name="cartpops_rule[cartpops_bogo_upsell_repeat]" class = "cartpops_bogo_upsell_repeat cartpops_rule_type cartpops_bogo_rule_type" value="2" <?php checked( '2', $rule_data['cartpops_bogo_upsell_repeat'] ); ?>/>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Repeat Upsell Mode', 'cartpops' ); ?><span class="required">*</span>
						<?php cartpops_wc_help_tip( esc_html__( 'Unlimited: No restriction on receiving CartPops. Limited: Upsell can be received till the Repeat Limit is reached.', 'cartpops' ) ); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped. ?>
					</label>
				</th>
				<td>
					<select name="cartpops_rule[cartpops_bogo_upsell_repeat_mode]" class = "cartpops_bogo_upsell_repeat_mode cartpops_bogo_upsell_repeat_field cartpops_rule_type cartpops_bogo_rule_type">
						<option value="1" <?php selected( $rule_data['cartpops_bogo_upsell_repeat_mode'], '1' ); ?>><?php esc_html_e( 'Unlimited', 'cartpops' ); ?></option>
						<option value="2" <?php selected( $rule_data['cartpops_bogo_upsell_repeat_mode'], '2' ); ?>><?php esc_html_e( 'Limited', 'cartpops' ); ?></option>
					</select>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Repeat Limit', 'cartpops' ); ?><span class="required">*</span></label>
				</th>
				<td>
					<input type="number" class="cartpops_bogo_upsell_repeat_limit cartpops_bogo_upsell_repeat_field cartpops_rule_type cartpops_bogo_rule_type" name="cartpops_rule[cartpops_bogo_upsell_repeat_limit]" min="1" value="<?php echo esc_attr( $rule_data['cartpops_bogo_upsell_repeat_limit'] ); ?>"/>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Select the Coupon', 'cartpops' ); ?><span class="required">*</span></label>
				</th>
				<td>
					<?php
					$coupon_args = array(
						'class'       => 'cartpops_apply_coupon cartpops_rule_type',
						'name'        => 'cartpops_rule[cartpops_apply_coupon]',
						'list_type'   => 'coupons',
						'action'      => 'cartpops_json_search_coupons',
						'multiple'    => false,
						'placeholder' => esc_html__( 'Search a Coupon', 'cartpops' ),
						'options'     => $rule_data['cartpops_apply_coupon'],
					);
					cartpops_select2_html( $coupon_args );
					?>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Select Product(s)', 'cartpops' ); ?><span class="required">*</span>
						<?php cartpops_wc_help_tip( esc_html__( "The selected Product(s) will be added to the user's cart once the coupon applied", 'cartpops' ) ); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped. ?>
					</label>
				</th>
				<td>
					<?php
					$upsell_product_args = array(
						'class'                   => 'cartpops_coupon_upsell_products cartpops_rule_type',
						'name'                    => 'cartpops_rule[cartpops_coupon_upsell_products]',
						'list_type'               => 'products',
						'action'                  => 'cartpops_json_search_products_and_variations',
						'exclude_global_variable' => 'yes',
						'placeholder'             => esc_html__( 'Search a Product', 'cartpops' ),
						'options'                 => $rule_data['cartpops_coupon_upsell_products'],
					);
					cartpops_select2_html( $upsell_product_args );
					?>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Quantity for Selected Upsell Product(s)', 'cartpops' ); ?><span class="required">*</span></label>
				</th>
				<td>
					<input type="number" class="cartpops_rule_type cartpops_coupon_upsell_products_qty" name="cartpops_rule[cartpops_coupon_upsell_products_qty]" min="1" value="<?php echo esc_attr( $rule_data['cartpops_coupon_upsell_products_qty'] ); ?>"/>
				</td>
			</tr>

			<?php do_action( 'cartpops_after_rule_general_settings', $rule_data ); ?>

		</tbody>
	</table>
</div>
<?php
