<?php
/**
 *  Rule filters data.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$categories = cartpops_get_wc_categories();
?>
<div id="cartpops_rule_data_filters" class="cartpops-rule-options-wrapper">
	<table class="form-table">

		<?php do_action( 'cartpops_before_rule_filters_settings', $rule_data ); ?>

		<tbody>
			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'User Filter', 'cartpops' ); ?>
						<?php cartpops_wc_help_tip( esc_html__( 'The selected users will be eligible for upsells', 'cartpops' ) ); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped. ?>
					</label>
				</th>
				<td>
					<select class="cartpops_user_filter_type" name="cartpops_rule[cartpops_user_filter_type]">
						<option value="1" <?php selected( $rule_data['cartpops_user_filter_type'], '1' ); ?>><?php esc_html_e( 'All User(s)', 'cartpops' ); ?></option>
						<option value="2" <?php selected( $rule_data['cartpops_user_filter_type'], '2' ); ?>><?php esc_html_e( 'Include User(s)', 'cartpops' ); ?></option>
						<option value="3" <?php selected( $rule_data['cartpops_user_filter_type'], '3' ); ?>><?php esc_html_e( 'Exclude User(s)', 'cartpops' ); ?></option>
						<option value="4" <?php selected( $rule_data['cartpops_user_filter_type'], '4' ); ?>><?php esc_html_e( 'Include User Role(s)', 'cartpops' ); ?></option>
						<option value="5" <?php selected( $rule_data['cartpops_user_filter_type'], '5' ); ?>><?php esc_html_e( 'Exclude User Role(s)', 'cartpops' ); ?></option>
					</select>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Select User(s)', 'cartpops' ); ?></label>
				</th>
				<td>
					<?php
					$include_user_args = array(
						'class'       => 'cartpops_include_users cartpops_user_filter',
						'name'        => 'cartpops_rule[cartpops_include_users]',
						'list_type'   => 'customers',
						'action'      => 'cartpops_json_search_customers',
						'placeholder' => esc_html__( 'Search a User', 'cartpops' ),
						'options'     => $rule_data['cartpops_include_users'],
					);
					cartpops_select2_html( $include_user_args );
					?>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Select User(s)', 'cartpops' ); ?></label>
				</th>
				<td>
					<?php
					$exclude_user_args = array(
						'class'       => 'cartpops_exclude_users cartpops_user_filter',
						'name'        => 'cartpops_rule[cartpops_exclude_users]',
						'list_type'   => 'customers',
						'action'      => 'cartpops_json_search_customers',
						'placeholder' => esc_html__( 'Search a User', 'cartpops' ),
						'options'     => $rule_data['cartpops_exclude_users'],
					);
					cartpops_select2_html( $exclude_user_args );
					?>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Select User Role(s)', 'cartpops' ); ?> </label>
				</th>
				<td>
					<select class="cartpops_include_user_roles cartpops_user_filter cartpops_select2" name="cartpops_rule[cartpops_include_user_roles][]" multiple="multiple">
						<?php
						foreach ( cartpops_get_user_roles() as $user_role_id => $user_role_name ) :
							$selected = ( in_array( $user_role_id, $rule_data['cartpops_include_user_roles'] ) ) ? ' selected="selected"' : '';
							?>
							<option value="<?php echo esc_attr( $user_role_id ); ?>"<?php echo esc_attr( $selected ); ?>><?php echo esc_html( $user_role_name ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Select User Role(s)', 'cartpops' ); ?></label>
				</th>
				<td>
					<select class="cartpops_exclude_user_roles cartpops_user_filter cartpops_select2" name="cartpops_rule[cartpops_exclude_user_roles][]" multiple="multiple">
						<?php
						foreach ( cartpops_get_user_roles() as $user_role_id => $user_role_name ) :
							$selected = ( in_array( $user_role_id, $rule_data['cartpops_exclude_user_roles'] ) ) ? ' selected="selected"' : '';
							?>
							<option value="<?php echo esc_attr( $user_role_id ); ?>"<?php echo esc_attr( $selected ); ?>><?php echo esc_html( $user_role_name ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Product Filter', 'cartpops' ); ?>
						<?php cartpops_wc_help_tip( esc_html__( 'The users will be eligible for free products when they purchase any of the products selected in this option.', 'cartpops' ) ); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped. ?>
					</label>
				</th>
				<td>
					<select class="cartpops_product_filter_type" name="cartpops_rule[cartpops_product_filter_type]">
						<option value="1" <?php selected( $rule_data['cartpops_product_filter_type'], '1' ); ?>><?php esc_html_e( 'All Product(s)', 'cartpops' ); ?></option>
						<option value="2" <?php selected( $rule_data['cartpops_product_filter_type'], '2' ); ?>><?php esc_html_e( 'Include Product(s)', 'cartpops' ); ?></option>
						<option value="3" <?php selected( $rule_data['cartpops_product_filter_type'], '3' ); ?>><?php esc_html_e( 'Exclude Product(s)', 'cartpops' ); ?></option>
						<option value="4" <?php selected( $rule_data['cartpops_product_filter_type'], '4' ); ?>><?php esc_html_e( 'All Categories', 'cartpops' ); ?></option>
						<option value="5" <?php selected( $rule_data['cartpops_product_filter_type'], '5' ); ?>><?php esc_html_e( 'Include Categories', 'cartpops' ); ?></option>
						<option value="6" <?php selected( $rule_data['cartpops_product_filter_type'], '6' ); ?>><?php esc_html_e( 'Exclude Categories', 'cartpops' ); ?></option>
					</select>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Applicable when', 'cartpops' ); ?>
						<?php cartpops_wc_help_tip( esc_html__( ' This option provides additional control on when to award the CartPops.', 'cartpops' ) ); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped. ?>
					</label>
				</th>
				<td>
					<select name="cartpops_rule[cartpops_applicable_products_type]" class="cartpops_product_filter cartpops_applicable_products_type">
						<option value="1" <?php selected( $rule_data['cartpops_applicable_products_type'], '1' ); ?>><?php esc_html_e( 'Any one of the selected Product(s) must be in cart', 'cartpops' ); ?></option>
						<option value="2" <?php selected( $rule_data['cartpops_applicable_products_type'], '2' ); ?>><?php esc_html_e( 'All the selected Product(s) must be in cart', 'cartpops' ); ?></option>
						<option value="3" <?php selected( $rule_data['cartpops_applicable_products_type'], '3' ); ?>><?php esc_html_e( 'Only the selected Product(s) must be in cart', 'cartpops' ); ?></option>
						<option value="4" <?php selected( $rule_data['cartpops_applicable_products_type'], '4' ); ?>><?php esc_html_e( 'User purchases the Specified Number of Products', 'cartpops' ); ?></option>
					</select>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Select Product(s)', 'cartpops' ); ?></label>
				</th>
				<td>
					<?php
					$include_product_args = array(
						'class'       => 'cartpops_include_products cartpops_product_filter',
						'name'        => 'cartpops_rule[cartpops_include_products]',
						'list_type'   => 'products',
						'action'      => 'cartpops_json_search_products_and_variations',
						'placeholder' => esc_html__( 'Search a Product', 'cartpops' ),
						'options'     => $rule_data['cartpops_include_products'],
					);
					cartpops_select2_html( $include_product_args );
					?>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Select Product(s)', 'cartpops' ); ?></label>
				</th>
				<td>
					<?php
					$exclude_product_args = array(
						'class'       => 'cartpops_exclude_products cartpops_product_filter',
						'name'        => 'cartpops_rule[cartpops_exclude_products]',
						'list_type'   => 'products',
						'action'      => 'cartpops_json_search_products_and_variations',
						'placeholder' => esc_html__( 'Search a Product', 'cartpops' ),
						'options'     => $rule_data['cartpops_exclude_products'],
					);
					cartpops_select2_html( $exclude_product_args );
					?>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Product Count', 'cartpops' ); ?>
						<?php cartpops_wc_help_tip( esc_html__( 'The user must add the number of products mentioned in this option to their cart in order for them to be eligibile for a Upsell.', 'cartpops' ) ); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped. ?>
					</label>
				</th>
				<td>
					<input type="number" class="cartpops_product_filter cartpops_include_product_count" name="cartpops_rule[cartpops_include_product_count]" min="1" value="<?php echo esc_attr( $rule_data['cartpops_include_product_count'] ); ?>"/>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Applicable when', 'cartpops' ); ?>
						<?php cartpops_wc_help_tip( esc_html__( 'This option provides additional control on when to award the CartPops.', 'cartpops' ) ); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped. ?>
					</label>
				</th>
				<td>
					<select name="cartpops_rule[cartpops_applicable_categories_type]" class="cartpops_product_filter cartpops_applicable_categories_type">
						<option value="1" <?php selected( $rule_data['cartpops_applicable_categories_type'], '1' ); ?>><?php esc_html_e( 'Any one of the product(s) should be from the selected category', 'cartpops' ); ?></option>
						<option value="2" <?php selected( $rule_data['cartpops_applicable_categories_type'], '2' ); ?>><?php esc_html_e( 'One product from each category must be in cart', 'cartpops' ); ?></option>
						<option value="3" <?php selected( $rule_data['cartpops_applicable_categories_type'], '3' ); ?>><?php esc_html_e( 'Only products from the selected category should be in cart', 'cartpops' ); ?></option>
						<option value="4" <?php selected( $rule_data['cartpops_applicable_categories_type'], '4' ); ?>><?php esc_html_e( 'Total Quantity of the Product(s) from the selected categories', 'cartpops' ); ?></option>
					</select>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Select Categories', 'cartpops' ); ?></label>
				</th>
				<td>
					<select class="cartpops_include_categories cartpops_product_filter cartpops_select2" name="cartpops_rule[cartpops_include_categories][]" multiple="multiple">
						<?php
						foreach ( $categories as $category_id => $category_name ) :
							$selected = ( in_array( $category_id, $rule_data['cartpops_include_categories'] ) ) ? ' selected="selected"' : '';
							?>
							<option value="<?php echo esc_attr( $category_id ); ?>"<?php echo esc_attr( $selected ); ?>><?php echo esc_html( $category_name ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Minimum Quantity', 'cartpops' ); ?>
						<?php cartpops_wc_help_tip( esc_html__( "The user's cart must contain the minimum quantity mentioned in this option which is the sum of the product(s) quantity that belongs to the selected categories.", 'cartpops' ) ); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped. ?>
					</label>
				</th>
				<td>
					<input type="number" class="cartpops_product_filter cartpops_include_category_product_count" name="cartpops_rule[cartpops_include_category_product_count]" min="1" value="<?php echo esc_attr( $rule_data['cartpops_include_category_product_count'] ); ?>"/>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Select Categories', 'cartpops' ); ?></label>
				</th>
				<td>
					<select class="cartpops_exclude_categories cartpops_product_filter cartpops_select2" name="cartpops_rule[cartpops_exclude_categories][]" multiple="multiple">
						<?php
						foreach ( $categories as $category_id => $category_name ) :
							$selected = ( in_array( $category_id, $rule_data['cartpops_exclude_categories'] ) ) ? ' selected="selected"' : '';
							?>
							<option value="<?php echo esc_attr( $category_id ); ?>"<?php echo esc_attr( $selected ); ?>><?php echo esc_html( $category_name ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>

			<?php do_action( 'cartpops_after_rule_filters_settings', $rule_data ); ?>
		</tbody>
	</table>
</div>
<?php
