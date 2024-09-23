<?php
/**
 * CartPops Drawer footer
 *
 * @package     CartPops\Templates
 * @version     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>

<div class="cpops-drawer-footer">

	<?php do_action( 'cartpops_drawer_footer_before' ); ?>

	<?php do_action( 'cartpops_drawer_footer_content' ); ?>

	<?php do_action( 'cartpops_drawer_footer_after' ); ?>

</div>
