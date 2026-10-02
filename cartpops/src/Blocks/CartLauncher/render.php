<?php
/**
 * Cart Launcher block server-side render.
 *
 * @package CartPops
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$cartpops_plugin = \CartPops\Plugin::instance();
$frontend_policy = $cartpops_plugin->container()->get( \CartPops\Frontend\FrontendRuntimePolicy::class );
if ( ! $frontend_policy->is_enabled() ) {
	return;
}

$presentation = 'inline' === ( $attributes['presentation'] ?? null ) ? 'inline' : 'floating';
$settings     = $cartpops_plugin->container()->get( \CartPops\Admin\SettingsRepository::class );
$launcher     = $settings->get( 'launcher', array() );
$launcher     = is_array( $launcher ) ? $launcher : array();

$position             = $attributes['position'] ?? $launcher['position'] ?? 'bottom_right';
$position             = is_string( $position ) && in_array( $position, array( 'bottom_left', 'bottom_right' ), true )
	? $position
	: 'bottom_right';
$show_count           = isset( $attributes['showCount'] ) && is_bool( $attributes['showCount'] )
	? $attributes['showCount']
	: ( 'floating' === $presentation ? ! empty( $launcher['show_count'] ) : true );
$show_total           = isset( $attributes['showTotal'] ) && is_bool( $attributes['showTotal'] )
	? $attributes['showTotal']
	: ( 'floating' === $presentation ? ! empty( $launcher['show_total'] ) : false );
$hide_empty           = isset( $attributes['hideEmpty'] ) && is_bool( $attributes['hideEmpty'] )
	? $attributes['hideEmpty']
	: ( 'floating' === $presentation && ! empty( $launcher['hide_empty'] ) );
$hide_indicator_empty = isset( $attributes['hideIndicatorEmpty'] ) && is_bool( $attributes['hideIndicatorEmpty'] )
	? $attributes['hideIndicatorEmpty']
	: ( 'floating' === $presentation ? ! empty( $launcher['hide_indicator_empty'] ) : true );
$indicator            = $attributes['indicator'] ?? ( $show_count ? 'bubble' : 'none' );
$indicator            = is_string( $indicator ) && in_array( $indicator, array( 'none', 'bubble', 'plain' ), true )
	? $indicator
	: 'bubble';
if ( ! $show_count ) {
	$indicator = 'none';
}

$cart         = WC()->cart;
$count        = $cart ? (int) $cart->get_cart_contents_count() : 0;
$subtotal_raw = $cart ? (string) $cart->get_cart_subtotal() : '';
$subtotal     = html_entity_decode( wp_strip_all_tags( $subtotal_raw ), ENT_QUOTES, 'UTF-8' );

// Every launcher in the request controls the one supported drawer instance.
// Store the request-unique relationship in Interactivity state so launcher and
// drawer render order does not matter and repeated launchers remain valid.
$interactivity_state = wp_interactivity_state( 'cartpops' );
$interactivity_state = is_array( $interactivity_state ) ? $interactivity_state : array();
$drawer_id           = $interactivity_state['drawerId'] ?? '';
if ( ! is_string( $drawer_id ) || 1 !== preg_match( '/^cpops-drawer-[A-Za-z0-9_-]+$/D', $drawer_id ) ) {
	$drawer_id = wp_unique_id( 'cpops-drawer-' );
}
$launcher_state = array( 'drawerId' => $drawer_id );
if ( ! array_key_exists( 'isOpen', $interactivity_state ) ) {
	$launcher_state['isOpen'] = false;
}
$open_cart_label                 = __( 'Open cart', 'cartpops' );
$launcher_state['launcherLabel'] = $open_cart_label;
if ( ! array_key_exists( 'cartCount', $interactivity_state ) ) {
	$launcher_state['cartCount'] = $count;
}
if ( ! array_key_exists( 'cartSubtotal', $interactivity_state ) ) {
	$launcher_state['cartSubtotal'] = $subtotal;
}
$launcher_i18n              = is_array( $interactivity_state['i18n'] ?? null )
	? $interactivity_state['i18n']
	: array();
$launcher_i18n['openCart']  = $open_cart_label;
$launcher_i18n['closeCart'] = __( 'Close cart', 'cartpops' );
$launcher_state['i18n']     = $launcher_i18n;
wp_interactivity_state( 'cartpops', $launcher_state );

$css_vars = array();
if ( 'floating' === $presentation ) {
	$launcher_colors = $launcher['colors'] ?? array();
	$launcher_colors = is_array( $launcher_colors ) ? $launcher_colors : array();
	$size            = (int) ( $launcher['size'] ?? 56 );
	$offset_x        = (int) ( $launcher['offset_x'] ?? 24 );
	$offset_y        = (int) ( $launcher['offset_y'] ?? 24 );

	if ( ! empty( $launcher_colors['background'] ) && '#6f23e1' !== $launcher_colors['background'] ) {
		$css_vars[] = '--cpops-launcher-bg: var(--cpops-v1-floating-launcher-background, ' . esc_attr( $launcher_colors['background'] ) . ')';
	}
	if ( ! empty( $launcher_colors['icon'] ) && '#ffffff' !== $launcher_colors['icon'] ) {
		$css_vars[] = '--cpops-launcher-icon: var(--cpops-v1-floating-launcher-color, ' . esc_attr( $launcher_colors['icon'] ) . ')';
	}
	if ( ! empty( $launcher_colors['badge_bg'] ) && '#ef4444' !== $launcher_colors['badge_bg'] ) {
		$css_vars[] = '--cpops-launcher-badge-bg: var(--cpops-v1-floating-launcher-indicator-background, ' . esc_attr( $launcher_colors['badge_bg'] ) . ')';
	}
	if ( ! empty( $launcher_colors['badge_text'] ) && '#ffffff' !== $launcher_colors['badge_text'] ) {
		$css_vars[] = '--cpops-launcher-badge-text: var(--cpops-v1-floating-launcher-indicator-text, ' . esc_attr( $launcher_colors['badge_text'] ) . ')';
	}
	if ( 56 !== $size ) {
		$css_vars[] = '--cpops-launcher-size: ' . $size . 'px';
	}
	if ( 24 !== $offset_x ) {
		$css_vars[] = '--cpops-launcher-offset-x: ' . $offset_x . 'px';
	}
	if ( 24 !== $offset_y ) {
		$css_vars[] = '--cpops-launcher-offset-y: ' . $offset_y . 'px';
	}
} else {
	$inline_colors    = $launcher['inline_colors'] ?? array();
	$inline_colors    = is_array( $inline_colors ) ? $inline_colors : array();
	$inline_defaults  = array(
		'background' => 'rgba(255, 255, 255, 0)',
		'text'       => '#000000',
		'badge_bg'   => '#705aef',
		'badge_text' => '#ffffff',
	);
	$inline_variables = array(
		'background' => array( '--cpops-launcher-bg', '--cpops-v1-inline-launcher-background' ),
		'text'       => array( '--cpops-launcher-icon', '--cpops-v1-inline-launcher-text' ),
		'badge_bg'   => array( '--cpops-launcher-badge-bg', '--cpops-v1-inline-launcher-bubble-background' ),
		'badge_text' => array( '--cpops-launcher-badge-text', '--cpops-v1-inline-launcher-bubble-text' ),
	);

	foreach ( $inline_variables as $key => list( $variable, $legacy_input ) ) {
		$value      = $inline_colors[ $key ] ?? $inline_defaults[ $key ];
		$value      = is_string( $value ) && '' !== $value ? $value : $inline_defaults[ $key ];
		$css_vars[] = sprintf( '%s: var(%s, %s)', $variable, $legacy_input, esc_attr( $value ) );
	}
}

$style_attr = ! empty( $css_vars ) ? ' style="' . esc_attr( implode( '; ', $css_vars ) ) . '"' : '';

// Icon SVGs.
$icons = array(
	'cart'   => '<svg class="cpops-launcher__icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"></path></svg>',
	'bag'    => '<svg class="cpops-launcher__icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"></path><line x1="3" y1="6" x2="21" y2="6"></line><path d="M16 10a4 4 0 01-8 0"></path></svg>',
	'basket' => '<svg class="cpops-launcher__icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2.97 12.92A2 2 0 002 14.63v3.24a2 2 0 00.97 1.71l7 4.12a2 2 0 002.06 0l7-4.12a2 2 0 00.97-1.71v-3.24a2 2 0 00-.97-1.71l-7-4.12a2 2 0 00-2.06 0l-7 4.12z"></path><path d="M12 22V12"></path><path d="M2 12l10-6 10 6"></path></svg>',
);

$icon_key = $attributes['icon'] ?? ( 'floating' === $presentation ? ( $launcher['icon'] ?? 'cart' ) : 'cart' );
$icon_key = is_string( $icon_key ) && isset( $icons[ $icon_key ] ) ? $icon_key : 'cart';
$icon_svg = $icons[ $icon_key ];

$button_classes   = array(
	'cpops-launcher',
	'cpops-launcher--' . $presentation,
	'cpops-launcher--count-' . $indicator,
	'cartpops-cart__toggle',
	'cartpops-cart__container',
	'cpops-toggle-drawer',
);
$button_classes[] = 'none' === $indicator
	? 'cartpops-cart--items-indicator-hide'
	: 'cartpops-cart--items-indicator-' . $indicator;
if ( $show_total ) {
	$button_classes[] = 'cartpops-cart--show-subtotal-yes';
}
if ( $hide_indicator_empty && 0 === $count ) {
	$button_classes[] = 'cartpops-cart--empty-indicator-hide';
}
if ( $hide_empty && 0 === $count ) {
	$button_classes[] = 'cpops-launcher--hidden';
}
if ( 'floating' === $presentation ) {
	$button_classes[] = 'cpops-launcher--' . $position;
	$button_classes[] = 'cpops-floating-cart__button';
}
$badge_classes = array(
	'cpops-launcher__badge',
	'cpops-launcher__badge--' . $indicator,
	'cartpops-cart__container-counter',
);
if ( 'floating' === $presentation ) {
	$badge_classes[] = 'cpops-floating-cart__count';
}
?>

<div
	<?php echo wp_kses_data( get_block_wrapper_attributes() ); ?>
	data-wp-interactive="cartpops"
	<?php echo $style_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>
>
	<button
		type="button"
		class="<?php echo esc_attr( implode( ' ', $button_classes ) ); ?>"
		data-cartpops-launcher
		data-wp-on--click="actions.toggleDrawer"
		aria-label="<?php esc_attr_e( 'Open cart', 'cartpops' ); ?>"
		data-wp-bind--aria-label="state.launcherLabel"
		aria-expanded="false"
		data-wp-bind--aria-expanded="state.isOpen"
		aria-controls="<?php echo esc_attr( $drawer_id ); ?>"
		<?php if ( $hide_indicator_empty ) : ?>
			data-wp-class--cartpops-cart--empty-indicator-hide="state.isEmpty"
		<?php endif; ?>
		<?php if ( $hide_empty ) : ?>
			data-wp-class--cpops-launcher--hidden="state.isEmpty"
		<?php endif; ?>
	>
		<?php echo $icon_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>

		<?php if ( 'none' !== $indicator ) : ?>
			<span
				class="<?php echo esc_attr( implode( ' ', $badge_classes ) ); ?>"
				data-wp-text="state.cartCount"
				<?php if ( $hide_indicator_empty ) : ?>
					data-wp-class--cpops-launcher__badge--hidden="state.isEmpty"
				<?php endif; ?>
			><?php echo esc_html( (string) $count ); ?></span>
		<?php endif; ?>

		<?php if ( $show_total ) : ?>
			<span
				class="cpops-launcher__total cartpops-cart__container-text"
				data-wp-text="state.cartSubtotal"
			><?php echo esc_html( $subtotal ); ?></span>
		<?php endif; ?>
	</button>
</div>
