<?php
/**
 * Admin: Upgrade card
 *
 * @package     CartPops\Admin\Views
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>

<div class="cartpops-card upgrade" style="background-image: url(<?php echo esc_url( CartPops_Settings::get_admin_asset( 'effects@20x-3.png' ) ); ?>);">
	<div class="card-content">
		<div class="card-inside">
			<img class="rocket" src="<?php echo esc_url( CartPops_Settings::get_admin_asset( 'rocket.png' ) ); ?>" />
			<h2>Level up your store</h2>
			<p>Upgrade your WooCommerce Cart today and convert more customers</p>
			<ul>
				<li><i data-feather="check"></i><?php echo __( 'Popup & Bar Add To Cart Triggers', 'cartpops' ); ?></li>
				<li><i data-feather="check"></i><?php echo __( 'Recommend Custom Products', 'cartpops' ); ?></li>
				<li><i data-feather="check"></i><?php echo __( 'Add To Cart Triggers', 'cartpops' ); ?></li>
			</ul>
			<a href="<?php echo esc_url( cartpops_outbound_url() ); ?>" class="cpops-button upgrade-button animated-button">Upgrade to Pro<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-external-link cpops-icon cartpops-has-margin-left-xs"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg></a>
		</div>
	</div>
</div>
