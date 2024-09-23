<?php
/**
 * CartPops Powered By
 *
 * @package     CartPops\Templates
 * @version     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>

<div class="<?php echo esc_html( implode( ' ', array_filter( $classes ) ) ); ?>">
	<?php
	printf(
		'%2$s <a href="%1$s" target="_blank" rel="noopener noreferrer">%3$s <span class="screen-reader-text">%4$s</span><i class="cpops-love"></i></a>',
		esc_url( apply_filters( 'cartpops_powered_by_link', $url ) ),
		esc_html__( 'Powered by', 'cartpops' ),
		esc_html__( 'CartPops', 'cartpops' ),
		/* translators: Accessibility text. */
		esc_html__( '(opens in a new tab)', 'cartpops' )
	);
	?>
</div>
