<?php
/**
 *  Rule restrictions data.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
?>
<div id="cartpops_rule_data_restrictions" class="cartpops-rule-options-wrapper">
	<table class="form-table">
		<tbody>

			<?php do_action( 'cartpops_before_rule_restrictions_settings', $rule_data ); ?>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Rule Validity', 'cartpops' ); ?>
						<?php cartpops_wc_help_tip( esc_html__( 'If left empty, the rule will be valid on all days.', 'cartpops' ) ); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped. ?>
					</label>
				</th>
				<td>
					<?php esc_html_e( 'From', 'cartpops' ); ?>
					<?php
					$rule_valid_from_date_args = array(
						'name'        => 'cartpops_rule[cartpops_upsell_valid_from_date]',
						'value'       => $rule_data['cartpops_upsell_valid_from_date'],
						'wp_zone'     => false,
						'placeholder' => CartPops_Date_Time::get_wp_date_format(),
					);
					cartpops_get_datepicker_html( $rule_valid_from_date_args );
					?>
				</td>
				<td>
					<?php esc_html_e( 'To', 'cartpops' ); ?>
					<?php
					$rule_valid_to_date_args = array(
						'name'        => 'cartpops_rule[cartpops_upsell_valid_to_date]',
						'value'       => $rule_data['cartpops_upsell_valid_to_date'],
						'wp_zone'     => false,
						'placeholder' => CartPops_Date_Time::get_wp_date_format(),
					);
					cartpops_get_datepicker_html( $rule_valid_to_date_args );
					?>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Week Day(s) Restrictions', 'cartpops' ); ?>
						<?php cartpops_wc_help_tip( esc_html__( 'The rule will be valid for the selected Days. If left empty, the rule will be valid for all days of the week.', 'cartpops' ) ); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped. ?>
					</label>
				</th>

				<td>
					<select class="cartpops-rule-week-days-validation cartpops_select2" multiple="multiple" name="cartpops_rule[cartpops_rule_week_days_validation][]">
						<?php foreach ( cartpops_get_rule_week_days_options() as $week_days_id => $week_days_name ) : ?>
							<option value="<?php echo esc_attr( $week_days_id ); ?>" <?php echo in_array( $week_days_id, $rule_data['cartpops_rule_week_days_validation'] ) ? 'selected="selected"' : ''; ?>><?php echo esc_html( $week_days_name ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>

			<?php if ( '2' == get_option( 'cartpops_settings_upsells_count_per_order_type', '2' ) ) : ?>
				<tr>
					<th scope='row'>
						<label><?php esc_html_e( 'Maximum Upsells in an Order from this Rule', 'cartpops' ); ?>
				<?php cartpops_wc_help_tip( esc_html__( 'If left empty / when the rule value is more the Global Restriction, the Global Restriction will apply.', 'cartpops' ) ); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped. ?>
						</label>
					</th>
					<td>
						<input type="number" class="cartpops_rule_type cartpops_manual_rule_type" name="cartpops_rule[cartpops_rule_upsells_count_per_order]" min="1" value="<?php echo esc_attr( $rule_data['cartpops_rule_upsells_count_per_order'] ); ?>"/>
					</td>
				</tr>
			<?php endif; ?>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Order Restrictions', 'cartpops' ); ?>
						<?php cartpops_wc_help_tip( esc_html__( 'If left empty, the rule will be valid for unlimited orders', 'cartpops' ) ); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped. ?>
					</label>
				</th>
				<td>
					<input type="number" name="cartpops_rule[cartpops_rule_restriction_count]" min="1" value="<?php echo esc_attr( $rule_data['cartpops_rule_restriction_count'] ); ?>"/>
				</td>
				<?php if ( $rule_data['cartpops_rule_restriction_count'] ) : ?>
					<td>
					<?php
					$remaining_count = max( floatval( $rule_data['cartpops_rule_restriction_count'] ) - floatval( $rule_data['cartpops_rule_usage_count'] ), 0 );
					/* translators: %s: number of orders and rule usage count */
					echo wp_kses_post( sprintf( esc_html__( 'Orders (%1$s used %2$d remaining)', 'cartpops' ), floatval( $rule_data['cartpops_rule_usage_count'] ), $remaining_count ) );
					?>
						<input type="button" class="cartpops_reset_rule_usage_count button-primary" data-rule-id="<?php echo esc_attr( $rule_data['id'] ); ?>" value="<?php esc_attr_e( 'Reset used count', 'cartpops' ); ?>"/>
					</td>
				<?php endif; ?>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Order Restriction per User', 'cartpops' ); ?><span class="required">*</span>
						<?php cartpops_wc_help_tip( esc_html__( 'When set to Enable, registered users can be restricted to receive free upsell(s) from this rule for a fixed number of times.', 'cartpops' ) ); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped. ?>
					</label>
				</th>
				<td>
					<select class="cartpops_rule_allowed_user_type" name="cartpops_rule[cartpops_rule_allowed_user_type]">
						<option value="1" <?php selected( $rule_data['cartpops_rule_allowed_user_type'], '1' ); ?>><?php esc_html_e( 'Disable', 'cartpops' ); ?></option>
						<option value="2" <?php selected( $rule_data['cartpops_rule_allowed_user_type'], '2' ); ?>><?php esc_html_e( 'Enable - For Registered Users Only', 'cartpops' ); ?></option>
					</select>
				</td>
			</tr>

			<tr>
				<th scope='row'>
					<label><?php esc_html_e( 'Number of Order(s) per User', 'cartpops' ); ?>
						<?php cartpops_wc_help_tip( esc_html__( 'The number of order(s) for which each registered users can receive upsell product(s) from this rule.', 'cartpops' ) ); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped. ?>
					</label>
				</th>
				<td>
					<input type="number" class="cartpops_rule_allowed_user_count" name="cartpops_rule[cartpops_rule_allowed_user_count]" min="0" value="<?php echo esc_attr( $rule_data['cartpops_rule_allowed_user_count'] ); ?>"/>
				</td>
			</tr>

			<?php do_action( 'cartpops_after_rule_restrictions_settings', $rule_data ); ?>
		</tbody>
	</table>
</div>
<?php
