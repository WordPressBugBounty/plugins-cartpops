<?php
/**
 * Admin: Productbird upgrade card
 *
 * @package     CartPops\Admin\Views
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>

<div class="cartpops-card upgrade productbird" style="background-image: url(<?php echo esc_url( CartPops_Settings::get_admin_asset( 'effects@20x-3.png' ) ); ?>);">
	<div class="card-content">
		<div class="card-inside">
			<img class="rocket" src="<?php echo esc_url( CartPops_Settings::get_admin_asset( 'rocket.png' ) ); ?>" />
			<h2>AI-Powered Product Descriptions</h2>
			<p>Transform your WooCommerce product descriptions with Productbird</p>
			<ul>
				<li><i data-feather="check"></i><?php echo __( 'Image-based AI descriptions', 'cartpops' ); ?></li>
				<li><i data-feather="check"></i><?php echo __( 'SEO-optimized content', 'cartpops' ); ?></li>
				<li><i data-feather="check"></i><?php echo __( 'Get 100 free credits when you sign up', 'cartpops' ); ?></li>
				<li><i data-feather="check"></i><?php echo __( 'No monthly fees - pay as you go', 'cartpops' ); ?></li>
			</ul>
			<a href="https://productbird.ai/?utm_source=cartpops&utm_medium=plugin-admin&utm_campaign=promote" target="_blank" rel="noopener noreferrer" class="cpops-button upgrade-button animated-button">Try Productbird<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-external-link cpops-icon cartpops-has-margin-left-xs"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg></a>
		</div>
	</div>
</div>