<?php
/**
 * Admin: LangRelay upgrade card
 *
 * @package     CartPops\Admin\Views
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>

<div class="cartpops-card upgrade textatlas" style="background-image: url(<?php echo esc_url( CartPops_Settings::get_admin_asset( 'effects@20x-3.png' ) ); ?>);">
   <div class="card-content">
	   <div class="card-inside">
		   <img class="rocket" src="<?php echo esc_url( CartPops_Settings::get_admin_asset( 'rocket.png' ) ); ?>" />
		   <h2><?php echo __( 'Translate Your WooCommerce Store Without the Headache', 'cartpops' ); ?></h2>
		   <p><?php echo __( 'LangRelay makes your store multilingual in minutes. Simpler and more affordable than Weglot, with everything you need and nothing you don\'t.', 'cartpops' ); ?></p>
		   <ul>
			   <li><i data-feather="check"></i><?php echo __( 'Translate your entire store in minutes, not hours', 'cartpops' ); ?></li>
			   <li><i data-feather="check"></i><?php echo __( 'Automatic and manual translation options', 'cartpops' ); ?></li>
			   <li><i data-feather="check"></i><?php echo __( 'SEO-friendly with hreflang and translated URLs', 'cartpops' ); ?></li>
			   <li><i data-feather="check"></i><?php echo __( 'Works with any WooCommerce theme', 'cartpops' ); ?></li>
			   <li><i data-feather="check"></i><?php echo __( 'A fraction of the cost of alternatives', 'cartpops' ); ?></li>
			   <li><i data-feather="check"></i><?php echo __( 'Simple setup, no coding required', 'cartpops' ); ?></li>
		   </ul>
		   <a href="https://www.langrelay.com/?utm_source=cartpops&utm_medium=plugin-admin&utm_campaign=promote" target="_blank" rel="noopener noreferrer" class="cpops-button upgrade-button animated-button"><?php echo __( 'Try LangRelay', 'cartpops' ); ?><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-external-link cpops-icon cartpops-has-margin-left-xs"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg></a>
	   </div>
   </div>
</div>
