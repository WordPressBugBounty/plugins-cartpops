<?php
/**
 * CartPops Drawer Header
 *
 * @package     CartPops\Templates
 * @version     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>

<div class="cpops-drawer-header">

	<?php do_action( 'cartpops_drawer_header_before' ); ?>

	<div class="<?php echo esc_html( implode( ' ', array_filter( $classes ) ) ); ?>">
		<div class="cpops-drawer-header__title">
			<span><?php echo esc_html( $title ); ?></span>
		</div>
		<button type="button" class="cpops-drawer-header__close" data-dismiss="cpops-modal">
			<svg aria-hidden="true" focusable="false" role="presentation" class="cpops-icon"  viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill-rule="evenodd" clip-rule="evenodd"><path d="M12 11.293l10.293-10.293.707.707-10.293 10.293 10.293 10.293-.707.707-10.293-10.293-10.293 10.293-.707-.707 10.293-10.293-10.293-10.293.707-.707 10.293 10.293z"/></svg>
			<span class="cpops-sr-only" aria-hidden="true"><?php echo esc_html( __( 'Close cart', 'cartpops' ) ); ?></span>
		</button>
	</div>

	<?php do_action( 'cartpops_drawer_header_after' ); ?>

</div>
