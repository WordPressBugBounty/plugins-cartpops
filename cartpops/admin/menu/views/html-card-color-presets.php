<?php
/**
 * Admin: Color Themes
 *
 * @package     CartPops\Admin\Views
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$color_presets = array(
	'default' => array(
		'title'  => __( 'Default theme', 'cartpops' ),
		'colors' => array(
			'cartpops_color_background_primary'            => '#ffffff',
			'cartpops_color_background_secondary'          => '#f7f3fb',
			'cartpops_color_typography_primary'            => '#26180a',
			'cartpops_color_typography_secondary'          => '#464646',
			'cartpops_color_typography_tertiary'           => '#7a7a7a',
			'cartpops_color_border'                        => '#eaeaec',
			'cartpops_color_accent'                        => '#6f23e1',
			'cartpops_color_overlay'                       => 'rgba(0, 0, 0, 0.887)',
			'cartpops_color_input_background'              => '#ffffff',
			'cartpops_color_input_text'                    => '#26180a',
			'cartpops_color_button_primary_background'     => '#6f23e1',
			'cartpops_color_button_primary_text'           => '#ffffff',
			'cartpops_color_button_secondary_background'   => '#f7f3fb',
			'cartpops_color_button_secondary_text'         => '#26180a',
			'cartpops_color_button_quantity_background'   => '#f7f3fb',
			'cartpops_color_button_quantity_text'         => '#26180a',
			'cartpops_color_input_quantity_background'   => '#ffffff',
			'cartpops_color_input_quantity_border'   => '#f7f3fb',
			'cartpops_color_input_quantity_text'         => '#6f23e1',
			'cartpops_color_recommendations_button_background' => '#e7e8ea',
			'cartpops_color_recommendations_button_text'   => '#000000',
			'cartpops_color_recommendations_drawer_background' => '#ffffff',
			'cartpops_color_recommendations_drawer_border' => '#6f23e1',
			'cartpops_color_recommendations_drawer_text'   => '#6f23e1',
			'cartpops_color_recommendations_popup_background' => '#f7f3fb',
			'cartpops_color_recommendations_popup_text'    => '#26180a',
			'cartpops_color_floating_cart_laucher_background' => '#000000',
			'cartpops_color_floating_cart_laucher_icon'    => '#ffffff',
			'cartpops_color_floating_cart_laucher_indicator_text' => '#ffffff',
			'cartpops_color_floating_cart_laucher_indicator_background' => '#6f23e1',
			'cartpops_color_free_shipping_meter_background' => '#f7f3fb',
			'cartpops_color_free_shipping_meter_background_active' => '#25a418',
		),
	),
	'blue'    => array(
		'title'  => __( 'Blue theme', 'cartpops' ),
		'colors' => array(
			'cartpops_color_background_primary'            => '#ffffff',
			'cartpops_color_background_secondary'          => '#eef2fc',
			'cartpops_color_typography_primary'            => '#26180a',
			'cartpops_color_typography_secondary'          => '#464646',
			'cartpops_color_typography_tertiary'           => '#7a7a7a',
			'cartpops_color_border'                        => '#eaeaec',
			'cartpops_color_accent'                        => '#0040ff',
			'cartpops_color_overlay'                       => 'rgba(0, 0, 0, 0.887)',
			'cartpops_color_input_background'              => '#ffffff',
			'cartpops_color_input_text'                    => '#26180a',
			'cartpops_color_button_primary_background'     => '#0040ff',
			'cartpops_color_button_primary_text'           => '#ffffff',
			'cartpops_color_button_secondary_background'   => '#eef2fc',
			'cartpops_color_button_secondary_text'         => '#26180a',
			'cartpops_color_button_quantity_background'   => '#eef2fc',
			'cartpops_color_button_quantity_text'         => '#26180a',
			'cartpops_color_input_quantity_background'   => '#ffffff',
			'cartpops_color_input_quantity_border'   => '#eef2fc',
			'cartpops_color_input_quantity_text'         => '#0040ff',
			'cartpops_color_recommendations_button_background' => '#e7e8ea',
			'cartpops_color_recommendations_button_text'   => '#000000',
			'cartpops_color_recommendations_drawer_background' => '#ffffff',
			'cartpops_color_recommendations_drawer_border' => '#0040ff',
			'cartpops_color_recommendations_drawer_text'   => '#0040ff',
			'cartpops_color_recommendations_popup_background' => '#eef2fc',
			'cartpops_color_recommendations_popup_text'    => '#26180a',
			'cartpops_color_floating_cart_laucher_background' => '#26180a',
			'cartpops_color_floating_cart_laucher_icon'    => '#ffffff',
			'cartpops_color_floating_cart_laucher_indicator_text' => '#ffffff',
			'cartpops_color_floating_cart_laucher_indicator_background' => '#0040ff',
			'cartpops_color_free_shipping_meter_background' => '#eef2fc',
			'cartpops_color_free_shipping_meter_background_active' => '#0040ff',
		),
	),
	'dark'    => array(
		'title'  => __( 'Dark theme', 'cartpops' ),
		'colors' => array(
			'cartpops_color_background_primary'            => '#1e2127',
			'cartpops_color_background_secondary'          => '#181a20',
			'cartpops_color_typography_primary'            => '#ffffff',
			'cartpops_color_typography_secondary'          => '#e8e8e8',
			'cartpops_color_typography_tertiary'           => '#6f7078',
			'cartpops_color_border'                        => '#282b32',
			'cartpops_color_accent'                        => '#ab73ee',
			'cartpops_color_overlay'                       => 'rgba(26, 27, 27, 0.85)',
			'cartpops_color_input_background'              => '#191c20',
			'cartpops_color_input_text'                    => '#ffffff',
			'cartpops_color_button_primary_background'     => '#355dff',
			'cartpops_color_button_primary_text'           => 'ffffff',
			'cartpops_color_button_secondary_background'   => '#2f3032',
			'cartpops_color_button_secondary_text'         => '#ffffff',
			'cartpops_color_button_quantity_background'   => '#2f3032',
			'cartpops_color_button_quantity_text'         => '#ffffff',
			'cartpops_color_input_quantity_background'   => '#1e2127',
			'cartpops_color_input_quantity_border'   => '#2f3032',
			'cartpops_color_input_quantity_text'         => '#ffffff',
			'cartpops_color_recommendations_button_background' => '#1c2e75',
			'cartpops_color_recommendations_button_text'   => '#ffffff',
			'cartpops_color_recommendations_drawer_text'   => '#ffffff',
			'cartpops_color_recommendations_drawer_border' => 'rgba(0, 0, 0, 0)',
			'cartpops_color_recommendations_drawer_background' => '#14151b',
			'cartpops_color_recommendations_popup_background' => '#14151b',
			'cartpops_color_recommendations_popup_text'    => '#ffffff',
			'cartpops_color_floating_cart_laucher_background' => '#000',
			'cartpops_color_floating_cart_laucher_icon'    => '#ffffff',
			'cartpops_color_floating_cart_laucher_indicator_text' => '#ffffff',
			'cartpops_color_floating_cart_laucher_indicator_background' => '#355dff',
			'cartpops_color_free_shipping_meter_background' => '#383d47',
			'cartpops_color_free_shipping_meter_background_active' => '#2d7f8a',
		),
	),
	'desert'  => array(
		'title'  => __( 'Desert theme', 'cartpops' ),
		'colors' => array(
			'cartpops_color_background_primary'            => '#ffffff',
			'cartpops_color_background_secondary'          => '#f4eddb',
			'cartpops_color_typography_primary'            => '#113034',
			'cartpops_color_typography_secondary'          => '#113034',
			'cartpops_color_typography_tertiary'           => '#2f5155',
			'cartpops_color_border'                        => '#eaeaec',
			'cartpops_color_accent'                        => '#163f44',
			'cartpops_color_overlay'                       => 'rgba(33, 42, 47, 0.85)',
			'cartpops_color_input_background'              => '#ffffff',
			'cartpops_color_input_text'                    => '#113034',
			'cartpops_color_button_primary_background'     => '#163f44',
			'cartpops_color_button_primary_text'           => '#ffffff',
			'cartpops_color_button_secondary_background'   => '#f1f1f1',
			'cartpops_color_button_secondary_text'         => '#163f44',
			'cartpops_color_button_quantity_background'   => '#f1f1f1',
			'cartpops_color_button_quantity_text'         => '#163f44',
			'cartpops_color_input_quantity_background'   => '#ffffff',
			'cartpops_color_input_quantity_border'   => '#f1f1f1',
			'cartpops_color_input_quantity_text'         => '#163f44',
			'cartpops_color_recommendations_button_background' => '#163f44',
			'cartpops_color_recommendations_button_text'   => '#ffffff',
			'cartpops_color_recommendations_drawer_text'   => '#000000',
			'cartpops_color_recommendations_drawer_border' => 'rgba(0, 0, 0, 0)',
			'cartpops_color_recommendations_drawer_background' => '#f4eddb',
			'cartpops_color_recommendations_popup_background' => '#f4eddb',
			'cartpops_color_recommendations_popup_text'    => '#000000',
			'cartpops_color_floating_cart_laucher_background' => '#f4eddb',
			'cartpops_color_floating_cart_laucher_icon'    => '#000000',
			'cartpops_color_floating_cart_laucher_indicator_text' => '#ffffff',
			'cartpops_color_floating_cart_laucher_indicator_background' => '#163f44',
			'cartpops_color_free_shipping_meter_background' => '#f4eddb',
			'cartpops_color_free_shipping_meter_background_active' => '#edb72c',
		),
	),
	'orange'  => array(
		'title'  => __( 'Orange theme', 'cartpops' ),
		'colors' => array(
			'cartpops_color_background_primary'            => '#ffffff',
			'cartpops_color_background_secondary'          => '#f8f8f8',
			'cartpops_color_typography_primary'            => '#26180a',
			'cartpops_color_typography_secondary'          => '#7f7f7f',
			'cartpops_color_typography_tertiary'           => '#a3a3a3',
			'cartpops_color_border'                        => '#eaeaec',
			'cartpops_color_accent'                        => '#48ba82',
			'cartpops_color_overlay'                       => 'rgba(0, 0, 0, 0.85)',
			'cartpops_color_input_background'              => '#ffffff',
			'cartpops_color_input_text'                    => '#113034',
			'cartpops_color_button_primary_background'     => '#f16b26',
			'cartpops_color_button_primary_text'           => '#ffffff',
			'cartpops_color_button_secondary_background'   => '#f5f5f5',
			'cartpops_color_button_secondary_text'         => '#26180a',
			'cartpops_color_button_quantity_background'   => '#f5f5f5',
			'cartpops_color_button_quantity_text'         => '#26180a',
			'cartpops_color_input_quantity_background'   => '#ffffff',
			'cartpops_color_input_quantity_border'   => '#f5f5f5',
			'cartpops_color_input_quantity_text'         => '#913d11',
			'cartpops_color_recommendations_button_background' => '#26180a',
			'cartpops_color_recommendations_button_text'   => '#ffffff',
			'cartpops_color_recommendations_drawer_background' => '#fef6f2',
			'cartpops_color_recommendations_drawer_border' => '#f16b26',
			'cartpops_color_recommendations_drawer_text'   => '#f16b26',
			'cartpops_color_recommendations_popup_background' => '#f4eddb',
			'cartpops_color_recommendations_popup_text'    => '#000000',
			'cartpops_color_floating_cart_laucher_background' => '#26180a',
			'cartpops_color_floating_cart_laucher_icon'    => '#ffffff',
			'cartpops_color_floating_cart_laucher_indicator_text' => '#ffffff',
			'cartpops_color_floating_cart_laucher_indicator_background' => '#f16b26',
			'cartpops_color_free_shipping_meter_background' => '#f5f5f5',
			'cartpops_color_free_shipping_meter_background_active' => '#48ba82',
		),
	),
);

?>

<div class="cpops-color-presets__container">
	<h2>Color presets</h2>
	<?php foreach ( $color_presets as $key => $preset ) : ?>
		<?php
			$count = 0;
		?>
		<a class="cpops-color-presets__list" id="appearance-theme-<?php echo esc_html( $key ); ?>" data-title="<?php echo esc_html( $preset['title'] ); ?>" data-theme="<?php echo htmlspecialchars( wp_json_encode( $preset['colors'] ) ); ?>">
			<h4><?php echo esc_html( $preset['title'] ); ?></h4>
			<div class="color-list">
				<?php foreach ( $preset['colors'] as $key => $color ) : ?>
					<span class="cpops-color-presets" color="<?php echo esc_attr( $color ); ?>" style="background-color: <?php echo esc_attr( $color ); ?>"></span>
					<?php
					if ( ++ $count > 7 ) {
						break;
					}
					?>
				<?php endforeach; ?>
			</div>
		</a>
	<?php endforeach; ?>
</div>
