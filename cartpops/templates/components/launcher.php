<?php
/**
 * CartPops Floating Cart Launcher
 *
 * @package     CartPops\Templates
 * @version     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


?>
<div id="cpops-floating-cart" class="<?php echo esc_html( implode( ' ', array_filter( $classes ) ) ); ?>">

	<button aria-label="Open CartPops popover" aria-expanded="false" class="cpops-floating-cart__button">
		<div class="cpops-floating-cart__animation"></div>
		<div class="cpops-floating-cart__count">
			<span><?php echo esc_attr( $item_account ); ?></span>
		</div>
		<span class="cpops-floating-cart__icon">
			<svg width="24" height="24" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M24 3l-.743 2h-1.929l-3.474 12h-13.239l-4.615-11h16.812l-.564 2h-13.24l2.937 7h10.428l3.432-12h4.195zm-15.5 15c-.828 0-1.5.672-1.5 1.5 0 .829.672 1.5 1.5 1.5s1.5-.671 1.5-1.5c0-.828-.672-1.5-1.5-1.5zm6.9-7-1.9 7c-.828 0-1.5.671-1.5 1.5s.672 1.5 1.5 1.5 1.5-.671 1.5-1.5c0-.828-.672-1.5-1.5-1.5z"/></svg>
		</span>
	</button>

</div>
