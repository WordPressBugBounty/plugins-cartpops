<?php
/**
 * CartPops Drawer
 *
 * @package     CartPops\Templates
 * @version     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>

<div id="cpops-drawer-modal" class="cpops-modal">

	<?php do_action( 'cartpops_drawer_wrapper_start' ); ?>

	<div id="cartpops-drawer" class="<?php echo esc_html( implode( ' ', array_filter( $classes ) ) ); ?>" <?php echo esc_attr( cartpops_implode_html_attributes( $attributes ) ); ?>>

		<?php do_action( 'cartpops_drawer_panel_wrapper_start' ); ?>

		<div class="cpops-panel">
			<?php do_action( 'cartpops_drawer_content' ); ?>
		</div>

		<?php do_action( 'cartpops_drawer_panel_wrapper_end' ); ?>

	</div>

	<?php do_action( 'cartpops_drawer_wrapper_end' ); ?>

</div>
