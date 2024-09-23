<?php
/**
 * Rule Panel.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="cartpops-rule-data-panel-wrapper">
	<div class="cartpops-rule-data-panel-header">

		<p class="form-field">
			<label><?php esc_html_e( 'Upsell Type', 'cartpops' ); ?><span class="required">* </span>
				<?php cartpops_wc_help_tip( esc_html__( "When set to Manual Upsells, the users can choose their upsell product(s). When set to Automatic Upsells, the upsell product(s) set in this rule will be automatically added to the user's cart. When set to Buy X Get Y,  the user will get the specified quantities of the product for free if they purchase the specified quantities of the product.", 'cartpops' ) ); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped. ?>
			</label>

			<select name="cartpops_rule[cartpops_rule_type]" class="cartpops_rule_types">
				<option value="1" <?php selected( $rule_data['cartpops_rule_type'], '1' ); ?>><?php esc_html_e( 'Manual Upsells', 'cartpops' ); ?></option>
				<option value="2" <?php selected( $rule_data['cartpops_rule_type'], '2' ); ?>><?php esc_html_e( 'Automatic Upsells', 'cartpops' ); ?></option>
				<option value="3" <?php selected( $rule_data['cartpops_rule_type'], '3' ); ?>><?php esc_html_e( 'Buy X Get Y(Buy One Get One)', 'cartpops' ); ?></option>
				<option value="4" <?php selected( $rule_data['cartpops_rule_type'], '4' ); ?>><?php esc_html_e( 'Coupon based Upsell', 'cartpops' ); ?></option>
			</select>
		</p>
	</div>

	<div class="cartpops-rule-data-panel-content">

		<ul class="cartpops-rule-data-tabs">
			<?php foreach ( self::get_rule_data_tabs() as $key => $panel_tab ) : ?>
				<li class="cartpops-rule-data-tab <?php echo esc_attr( $key ); ?>_tab <?php echo esc_attr( isset( $panel_tab['class'] ) ? implode( ' ', (array) $panel_tab['class'] ) : '' ); ?>">
					<a href="#<?php echo esc_attr( $panel_tab['target'] ); ?>" class="cartpops-rule-data-tab-link"><span><?php echo esc_html( $panel_tab['label'] ); ?></span></a>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php
		self::output_tabs();
		do_action( 'cartpops_rule_data_panels' );
		?>
		<div class="clear"></div>
	</div>
</div>
<?php
