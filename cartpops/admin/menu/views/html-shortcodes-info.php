<?php
/**
 * Shortcodes.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
/*
 * Hook: cartpops_before_shortcode_contents.
 */
do_action( 'cartpops_before_shortcode_contents' );
?>

<table class="form-table cartpops_parameter_syntax widefat">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Syntax', 'cartpops' ); ?></th>
			<th></th>
		</tr>
	</thead>
	<tbody>
		<tr>
			<td><?php esc_html_e( 'Syntax', 'cartpops' ); ?></td>
			<td><?php esc_html_e( '[shortcode parameter1 = "value" parameter2 = "value" ]', 'cartpops' ); ?></td>
		</tr>
	</tbody>
</table>

<h2><?php esc_html_e( 'Example', 'cartpops' ); ?></h2>
<p><b>[cartpops_upsell_products type="carousel" mode="inline"]</b></p>
<p><b>[cartpops_upsell_products type="table" per_page="2"]</b></p>

<table class="form-table cartpops_parameter_list widefat">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Parameters', 'cartpops' ); ?></th>
			<th><?php esc_html_e( 'Value', 'cartpops' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<tr>
			<td>type</td>
			<td>carousel, table, selectbox</td>
		</tr>
		<tr>
			<td>mode</td>
			<td>inline, popup</td>
		</tr>
		<tr>
			<td>per_page</td>
			<td>any number</td>
		</tr>
	</tbody>
</table>
<?php
/*
 * Hook: cartpops_after_shortcodes_content.
 */
do_action( 'cartpops_after_shortcodes_content' );

