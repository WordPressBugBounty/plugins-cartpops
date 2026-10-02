<?php
/**
 * Cart Drawer block server-side render.
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
$settings              = $cartpops_plugin->container()->get( \CartPops\Admin\SettingsRepository::class );
$mini_cart_integration = $cartpops_plugin->container()->get( \CartPops\Compatibility\MiniCartIntegration::class );
$design                = $settings->get( 'design', array() );
$drawer                = $settings->get( 'drawer', array() );
$colors                = $design['colors'] ?? array();
$colors_dark           = is_array( $design['colors_dark'] ?? null ) ? $design['colors_dark'] : array();
$dark_mode             = \CartPops\Blocks\CartDrawer\DrawerThemeMode::normalize( $design['dark_mode'] ?? null );
$position              = $drawer['position'] ?? 'right';
$product_name_display  = \CartPops\Blocks\CartDrawer\DrawerProductNameDisplay::normalize( $drawer['product_name_display'] ?? null );
$totals_visibility     = new \CartPops\Blocks\CartDrawer\DrawerTotalsVisibility( $drawer );
$body_fee_only         = $totals_visibility->is_fee_only_at( 'body' );
$footer_fee_only       = $totals_visibility->is_fee_only_at( 'footer' );
$drawer_text           = static function ( string $key, string $fallback = '' ) use ( $drawer ): string {
	$value = $drawer[ $key ] ?? '';
	return is_string( $value ) && '' !== $value ? $value : $fallback;
};
$header_title          = $drawer_text( 'header_title', __( 'Your Cart', 'cartpops' ) );
$added_to_cart_message = $drawer_text( 'added_to_cart_message' );
$coupon_title          = $drawer_text( 'coupon_title' );
$coupon_placeholder    = $drawer_text( 'coupon_input_placeholder', __( 'Coupon code', 'cartpops' ) );
$coupon_button_text    = $drawer_text( 'coupon_button_text', __( 'Apply', 'cartpops' ) );
$subtotal_label        = $drawer_text( 'subtotal_label', __( 'Subtotal', 'cartpops' ) );
$discount_label        = $drawer_text( 'discount_label', __( 'Discount', 'cartpops' ) );
$total_label           = $drawer_text( 'total_label', __( 'Total', 'cartpops' ) );
$empty_button_text     = $drawer_text( 'empty_button_text', __( 'Continue shopping', 'cartpops' ) );
$empty_title           = $drawer_text( 'empty_title', __( 'Your cart is empty', 'cartpops' ) );
$empty_subtitle        = $drawer_text( 'empty_subtitle' );
$powered_by_url        = true === $settings->get( 'general.powered_by', false )
	? \CartPops\Compatibility\LegacyPoweredByLink::resolve( $settings->get( 'general.powered_by_partner_code', '' ) )
	: null;
$cart                  = WC()->cart;
$tax_display_cart      = get_option( 'woocommerce_tax_display_cart', 'excl' );
$placeholder_url       = \CartPops\Blocks\CartDrawer\CartItemImage::normalize_url(
	wc_placeholder_img_src( 'woocommerce_thumbnail' )
) ?? '';
$checkout_url          = \CartPops\Compatibility\LegacyDrawerUrl::checkout( wc_get_checkout_url() );
$shop_url              = \CartPops\Compatibility\LegacyDrawerUrl::empty_cart( wc_get_page_permalink( 'shop' ) );

// Recalculate cart totals before building state.
// WooCommerce does NOT call calculate_totals() automatically on every page
// load — it only runs when cart data changes. Fees (e.g. Shipping Protection)
// are NOT persisted to the session; they are dynamically re-registered each
// time calculate_totals() fires the woocommerce_cart_calculate_fees hook.
// Without this call, $cart->get_fees() returns [] and the fee line items
// are missing from the initial drawer state even though the session-stored
// total already includes them (making the total look wrong to the user).
if ( $cart && ! $cart->is_empty() ) {
	try {
		\CartPops\Plugin::instance()
			->container()
			->get( \CartPops\Cart\CartTotalsObserver::class )
			->observe(
				$cart,
				\CartPops\Cart\CartSessionTransactionMode::LOCAL_DIRTY_OBSERVATION
			);
	} catch ( \CartPops\Cart\CartSessionConflictException $error ) {
		// A concurrent cart mutation owns the session. Render the last coherent
		// in-process/session totals; the client will retry its normal cart fetch.
		unset( $error );
	} catch ( \Throwable $error ) {
		// Rendering must remain available when a third-party totals hook fails.
		unset( $error );
	}
}

// Build initial cart state using shared builder.
$cart_state = \CartPops\Cart\CartStateBuilder::build();
$cart_lines = \CartPops\Blocks\CartDrawer\CartLineStateProjector::project( $cart_state['cartItems'] ?? null );
$cart_items = $cart_lines['cartItems'];
$cart_count = $cart_state['cartCount'];

// Build initial recommendations for server-side rendering.
$rec_settings    = $settings->get( 'recommendations', array() );
$rec_engine      = \CartPops\Plugin::instance()->container()->get( \CartPops\Recommendations\RecommendationEngine::class );
$recommendations = $rec_engine->get_recommendations();

// Format recommendation prices for display.
foreach ( $recommendations as &$rec_product ) {
	$rec_product['formattedPrice'] = html_entity_decode( wp_strip_all_tags( wc_price( $rec_product['price'] ) ), ENT_QUOTES, 'UTF-8' );
}
unset( $rec_product );

// Build CSS custom properties from settings.
$legacy_color_inputs = array(
	'primary'               => '--cpops-v1-accent-color',
	'background'            => '--cpops-v1-background-primary',
	'surface'               => '--cpops-v1-background-secondary',
	'text_primary'          => '--cpops-v1-text-primary',
	'text_secondary'        => '--cpops-v1-text-secondary',
	'text_tertiary'         => '--cpops-v1-text-tertiary',
	'border'                => '--cpops-v1-border-color',
	'input_bg'              => '--cpops-v1-input-field-background',
	'input_border'          => '--cpops-v1-border-color',
	'input_text'            => '--cpops-v1-input-field-text',
	'button_primary_bg'     => '--cpops-v1-button-primary-background',
	'button_primary_text'   => '--cpops-v1-button-primary-text',
	'button_secondary_bg'   => '--cpops-v1-button-secondary-background',
	'button_secondary_text' => '--cpops-v1-button-secondary-text',
	'quantity_button_bg'    => '--cpops-v1-button-quantity-background',
	'quantity_button_text'  => '--cpops-v1-button-quantity-text',
	'quantity_input_bg'     => '--cpops-v1-input-quantity-background',
	'quantity_input_border' => '--cpops-v1-input-quantity-border',
	'quantity_input_text'   => '--cpops-v1-input-quantity-text',
	'recs_button_bg'        => '--cpops-v1-recommendations-button-background',
	'recs_button_text'      => '--cpops-v1-recommendations-button-text',
	'recs_background'       => '--cpops-v1-recommendations-background',
	'recs_border'           => '--cpops-v1-recommendations-border',
	'recs_text'             => '--cpops-v1-recommendations-text',
	'success'               => '--cpops-v1-state-success',
	'danger'                => '--cpops-v1-state-danger',
);

$css_vars = array();
foreach ( $colors as $key => $value ) {
	if ( 'overlay' === $key ) {
		continue; // Built from overlay_color + overlay_opacity below.
	}
	$property = '--cpops-' . str_replace( '_', '-', $key );
	if ( isset( $legacy_color_inputs[ $key ] ) ) {
		$css_vars[] = sprintf( '%s: var(%s, %s)', $property, $legacy_color_inputs[ $key ], esc_attr( $value ) );
		continue;
	}
	$css_vars[] = sprintf( '%s: %s', $property, esc_attr( $value ) );
}

// Dark values use a closed property map. Revalidate the canonical repository
// values at this final CSS boundary so malformed storage can only fall back.
$dark_color_properties = array(
	'background'     => array( '--cpops-dark-background', '#1a1a2e' ),
	'surface'        => array( '--cpops-dark-surface', '#252542' ),
	'text_primary'   => array( '--cpops-dark-text-primary', '#f9fafb' ),
	'text_secondary' => array( '--cpops-dark-text-secondary', '#d1d5db' ),
	'border'         => array( '--cpops-dark-border', '#6b7280' ),
	'input_bg'       => array( '--cpops-dark-input-bg', '#252542' ),
	'input_border'   => array( '--cpops-dark-input-border', '#6b7280' ),
	'overlay'        => array( '--cpops-dark-overlay', 'rgba(0, 0, 0, 0.7)' ),
);
$canonical_dark_color  = static function ( mixed $candidate, string $fallback ): string {
	if ( ! is_string( $candidate ) ) {
		return $fallback;
	}

	$candidate = trim( $candidate );
	if ( strlen( $candidate ) > 64 ) {
		return $fallback;
	}
	$hex = sanitize_hex_color( $candidate );
	if ( is_string( $hex ) && '' !== $hex ) {
		return $hex;
	}
	if ( 1 === preg_match( '/^rgb\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*\)$/iD', $candidate, $matches ) ) {
		$channels = array_map( 'intval', array_slice( $matches, 1, 3 ) );
		if ( max( $channels ) <= 255 ) {
			return sprintf( 'rgb(%d, %d, %d)', $channels[0], $channels[1], $channels[2] );
		}
		return $fallback;
	}
	if ( 1 !== preg_match( '/^rgba\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*((?:0(?:\.[0-9]+)?|1(?:\.0+)?|\.[0-9]+))\s*\)$/iD', $candidate, $matches ) ) {
		return $fallback;
	}
	$channels = array_map( 'intval', array_slice( $matches, 1, 3 ) );
	$alpha    = (float) $matches[4];
	if ( max( $channels ) > 255 || ! is_finite( $alpha ) || $alpha < 0 || $alpha > 1 ) {
		return $fallback;
	}
	$alpha_string = rtrim( rtrim( number_format( $alpha, 6, '.', '' ), '0' ), '.' );
	return sprintf( 'rgba(%d, %d, %d, %s)', $channels[0], $channels[1], $channels[2], $alpha_string );
};
foreach ( $dark_color_properties as $key => [ $property, $fallback ] ) {
	$value      = $canonical_dark_color( $colors_dark[ $key ] ?? null, $fallback );
	$css_vars[] = sprintf( '%s: %s', $property, esc_attr( $value ) );
}

// Overlay: combine color + opacity into rgba.
$design          = $settings->get( 'design', array() );
$overlay_color   = $design['overlay_color'] ?? '#000000';
$overlay_opacity = ( $design['overlay_opacity'] ?? 50 ) / 100;
$overlay_rgb     = array(
	hexdec( substr( $overlay_color, 1, 2 ) ),
	hexdec( substr( $overlay_color, 3, 2 ) ),
	hexdec( substr( $overlay_color, 5, 2 ) ),
);
$css_vars[]      = sprintf( '--cpops-overlay: var(--cpops-v1-overlay-background, rgba(%d, %d, %d, %s))', $overlay_rgb[0], $overlay_rgb[1], $overlay_rgb[2], $overlay_opacity );

$css_vars[] = sprintf( '--cpops-border-radius: %dpx', (int) ( $design['border_radius'] ?? 8 ) );
$css_vars[] = sprintf( '--cpops-button-border-radius: %dpx', (int) ( $design['button_border_radius'] ?? 8 ) );
$css_vars[] = sprintf( '--cpops-drawer-width: var(--cpops-v1-drawer-width-desktop, %dpx)', (int) ( $drawer['width_desktop'] ?? 480 ) );
$css_vars[] = sprintf( '--cpops-drawer-width-mobile: var(--cpops-v1-drawer-width-mobile, %d%%)', (int) ( $drawer['width_mobile'] ?? 100 ) );
$css_vars[] = sprintf( '--cpops-animation-duration: %dms', (int) ( $drawer['animation_duration'] ?? 300 ) );
$cart_hash  = '';
if ( $cart instanceof \WC_Cart && $cart_state['cartCount'] > 0 && method_exists( $cart, 'get_cart_hash' ) ) {
	$candidate_cart_hash = (string) $cart->get_cart_hash();
	if ( 1 === preg_match( '/^[a-f0-9]{32}$/i', $candidate_cart_hash ) ) {
		$cart_hash = strtolower( $candidate_cart_hash );
	}
}

// All supported launcher instances target this request-unique singleton
// drawer. Reuse an ID created by an earlier launcher, or establish it when the
// drawer renders first.
$interactivity_state = wp_interactivity_state( 'cartpops' );
$drawer_id           = $interactivity_state['drawerId'] ?? '';
if ( ! is_string( $drawer_id ) || 1 !== preg_match( '/^cpops-drawer-[A-Za-z0-9_-]+$/D', $drawer_id ) ) {
	$drawer_id = wp_unique_id( 'cpops-drawer-' );
}
$coupon_input_id = $drawer_id . '-coupon';
$coupon_form_id  = $drawer_id . '-coupon-form';

// Build the complete shared state before applying the closed internal extension seam.
$shared_state = array(
	'drawerId'        => $drawer_id,
	'isOpen'          => false,
	'isLoading'       => false,
	'cartCount'       => $cart_state['cartCount'],
	'cartHash'        => $cart_hash,
	'cartTotal'       => $cart_state['cartTotal'],
	'cartSubtotal'    => $cart_state['cartSubtotal'],
	'cartItems'       => $cart_items,
	'renderCartItems' => $cart_lines['renderCartItems'],
	'coupons'         => $cart instanceof \WC_Cart
		? \CartPops\Cart\CouponSerializer::serialize( $cart )
		: array(),
	'error'           => '',
	'couponExpanded'  => false,
	'undoItem'        => null,
	'storeApiBase'    => esc_url_raw( get_rest_url( null, 'wc/store/v1' ) ),
	'nonce'           => wp_create_nonce( 'wc_store_api' ),
	'position'        => $position,
	'restUrl'         => esc_url_raw( get_rest_url( null, '/' ) ),
	'restNonce'       => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
	'nonceRefreshUrl' => esc_url_raw( admin_url( 'admin-ajax.php?action=rest-nonce' ) ),
	'isUserLoggedIn'  => is_user_logged_in(),
	'wpVersion'       => get_bloginfo( 'version' ),
	'recommendations' => $recommendations,
	'launcherLabel'   => __( 'Open cart', 'cartpops' ),
	'recsConfig'      => array(
		'enabled' => (bool) ( $rec_settings['enabled'] ?? false ),
		'heading' => $rec_settings['heading'] ?? __( 'You may also like', 'cartpops' ),
		'layout'  => $rec_settings['layout'] ?? 'horizontal',
	),
	'cartFees'        => $cart_state['cartFees'],
	'cartShipping'    => $cart_state['cartShipping'] ?? '',
	'cartTax'         => $cart_state['cartTax'],
	'config'          => array(
		'trigger'               => $frontend_policy->trigger(),
		'miniCartMode'          => $mini_cart_integration->mode(),
		'announceAddedToCart'   => '' !== $added_to_cart_message,
		'animation'             => $drawer['animation'] ?? 'slide',
		'showUndo'              => (bool) ( $drawer['show_undo'] ?? true ),
		'forceFragmentsRefresh' => true === $settings->get( 'advanced.force_fragments_refresh', false ),
		// Classic product-page forms are submitted in the background only on a
		// single product page, and never when Woo must redirect to the cart.
		'productPageAjaxAdd'    => false !== $settings->get( 'general.product_page_ajax_add', true )
			&& function_exists( 'is_product' )
			&& is_product(),
		'cartRedirectAfterAdd'  => 'yes' === get_option( 'woocommerce_cart_redirect_after_add' ),
		'fragmentsUrl'          => class_exists( '\WC_AJAX' )
			? esc_url_raw( \WC_AJAX::get_endpoint( 'get_refreshed_fragments' ) )
			: '',
		'checkoutUrl'           => $checkout_url,
		'emptyCartUrl'          => $shop_url,
		'cartUrl'               => esc_url( wc_get_cart_url() ),
		'currencySymbol'        => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
		'taxDisplayCart'        => $tax_display_cart,
		'taxEnabled'            => wc_tax_enabled(),
		'taxLabel'              => WC()->countries->tax_or_vat(),
		'placeholderImageUrl'   => $placeholder_url,
	),
	'i18n'            => array(
		// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Uses WooCommerce's canonical translated cart label.
		'freeShipping'               => __( 'Free!', 'woocommerce' ),
		'openCart'                   => __( 'Open cart', 'cartpops' ),
		'closeCart'                  => __( 'Close cart', 'cartpops' ),
		'addToCart'                  => __( 'Add to cart', 'cartpops' ),
		/* translators: %s: product name. */
		'addProductToCart'           => __( 'Add %s to cart', 'cartpops' ),
		'selectOptions'              => __( 'Select options', 'cartpops' ),
		/* translators: %s: product name. */
		'selectOptionsForProduct'    => __( 'Select options for %s', 'cartpops' ),
		/* translators: %s: cart item name. */
		'removed'                    => __( '"%s" removed.', 'cartpops' ),
		/* translators: %s: cart item name. */
		'removedFromCart'            => __( '%s removed from cart.', 'cartpops' ),
		'item'                       => __( 'Item', 'cartpops' ),
		/* translators: %s: cart item name. */
		'restoredToCart'             => __( '%s restored to cart.', 'cartpops' ),
		/* translators: %s: coupon code. */
		'couponApplied'              => __( 'Coupon "%s" applied.', 'cartpops' ),
		/* translators: %s: coupon code. */
		'couponRemoved'              => __( 'Coupon "%s" removed.', 'cartpops' ),
		/* translators: %s: product name. */
		'addedToCart'                => '' !== $added_to_cart_message ? $added_to_cart_message : __( '%s added to cart.', 'cartpops' ),
		'cartExpired'                => __( 'Your cart has expired.', 'cartpops' ),
		'requestFailed'              => __( 'Request failed', 'cartpops' ),
		'cartRefreshing'             => __( 'Your cart is refreshing. Please wait.', 'cartpops' ),
		'fetchRecommendationsFailed' => __( 'Failed to fetch recommendations', 'cartpops' ),
		/* translators: 1: tax amount, 2: tax label (e.g. VAT). */
		'inclTax'                    => __( 'Incl. %1$s %2$s', 'cartpops' ),
		/* translators: %s: price of a single unit, shown under a cart line's total. */
		'priceEach'                  => __( '%s each', 'cartpops' ),
	),
);

try {
	$drawer_registry = $cartpops_plugin->container()->get( \CartPops\Blocks\CartDrawer\DrawerRenderExtensionRegistry::class );
	if ( ! $drawer_registry instanceof \CartPops\Blocks\CartDrawer\DrawerRenderExtensionRegistry ) {
		throw new \LogicException( 'CartPops drawer render registry is unavailable.' );
	}
	$drawer_render = $drawer_registry->compose( $shared_state );
} catch ( \Throwable $error ) {
	unset( $error );
	$drawer_render = new \CartPops\Blocks\CartDrawer\DrawerRenderContribution( $shared_state );
}

$drawer_state     = $drawer_render->state();
$secondary_action = \CartPops\Blocks\CartDrawer\SecondaryActionPresentation::project(
	$drawer_state['secondaryAction'] ?? null
);
if ( null === $secondary_action ) {
	unset( $drawer_state['secondaryAction'] );
} else {
	$drawer_state['secondaryAction'] = $secondary_action;
}
$hide_native_view_cart                    = 'view_cart' === ( $secondary_action['mode'] ?? null );
$recommendation_button_mode               = \CartPops\Recommendations\RecommendationButtonPresentation::normalize_mode(
	$drawer_state['recommendationButtonMode'] ?? null
);
$recommendation_button_text               = \CartPops\Recommendations\RecommendationButtonPresentation::display_text(
	$drawer_state['recommendationButtonText'] ?? null,
	$drawer_state['i18n']['add'] ?? __( 'Add', 'cartpops' )
);
$drawer_state['recommendationButtonText'] = $recommendation_button_text;
$recommendation_button_shows_text         = in_array( $recommendation_button_mode, array( 'text', 'text_icon' ), true );
$recommendation_button_shows_icon         = in_array( $recommendation_button_mode, array( 'icon', 'text_icon' ), true );

foreach ( $drawer_render->css_variables() as $property => $value ) {
	$css_vars[] = sprintf( '%s: %s', $property, esc_attr( (string) $value ) );
}
$style_attr = implode( '; ', $css_vars );

wp_interactivity_state( 'cartpops', $drawer_state );
?>

<div
	<?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'cpops-modal' ) ) ); ?>
	data-wp-interactive="cartpops"
	data-wp-class--cpops-drawer-open="state.isOpen"
	data-wp-on-document--keydown="actions.handleKeydown"
	style="<?php echo esc_attr( $style_attr ); ?>"
>
	<?php do_action( 'cartpops_drawer_wrapper_start' ); ?>

	<!-- Overlay -->
	<div
		class="cpops-overlay cpops-modal-backdrop"
		data-animation="<?php echo esc_attr( $drawer['animation'] ?? 'slide' ); ?>"
		data-dark-mode="<?php echo esc_attr( $dark_mode ); ?>"
		data-wp-on--click="actions.handleOverlayClick"
		data-wp-class--cpops-overlay-visible="state.isOpen"
		aria-hidden="true"
	></div>

	<!-- Drawer Panel -->
	<aside
		id="<?php echo esc_attr( $drawer_id ); ?>"
		class="cpops-drawer cpops-drawer--<?php echo esc_attr( $position ); ?> cpops-default-drawer cpops-modal-wrap cpops-panel"
		data-animation="<?php echo esc_attr( $drawer['animation'] ?? 'slide' ); ?>"
		data-dark-mode="<?php echo esc_attr( $dark_mode ); ?>"
		data-product-name-display="<?php echo esc_attr( $product_name_display ); ?>"
		<?php if ( version_compare( (string) $drawer_state['wpVersion'], '6.9', '<' ) ) : ?>
			data-wp-ignore
			data-cartpops-legacy-hydration-guard="true"
		<?php endif; ?>
		data-wp-class--cpops-drawer-open="state.isOpen"
		data-wp-class--cpops-drawer-loading="state.isLoading"
		data-wp-on--click="actions.handleDrawerClick"
		data-wp-bind--aria-hidden="state.drawerAriaHidden"
		data-wp-bind--inert="state.drawerInert"
		role="dialog"
		aria-modal="true"
		aria-hidden="true"
		inert
		aria-label="<?php esc_attr_e( 'Shopping cart', 'cartpops' ); ?>"
	>
		<?php do_action( 'cartpops_drawer_panel_wrapper_start' ); ?>

		<!-- Header -->
		<header class="cpops-drawer__header cpops-drawer-header cpops-drawer-header__heading">
			<?php do_action( 'cartpops_drawer_header_before' ); ?>

			<h2 class="cpops-drawer__title cpops-drawer-header__title">
				<?php echo esc_html( $header_title ); ?>
				<span class="cpops-drawer__count" data-wp-text="state.cartCount"></span>
			</h2>
			<button
				type="button"
				class="cpops-drawer__close cpops-drawer-header__close"
				data-cartpops-dialog-initial-focus
				data-wp-on--click="actions.closeDrawer"
				aria-label="<?php esc_attr_e( 'Close cart', 'cartpops' ); ?>"
			>
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
					<line x1="18" y1="6" x2="6" y2="18"></line>
					<line x1="6" y1="6" x2="18" y2="18"></line>
				</svg>
			</button>

			<?php do_action( 'cartpops_drawer_header_after' ); ?>
		</header>

		<!-- Cart Content -->
		<div class="cpops-drawer__content cpops-drawer-cart">
			<?php do_action( 'cartpops_drawer_content' ); ?>

			<?php
			echo wp_kses(
				$drawer_render->markup( \CartPops\Blocks\CartDrawer\DrawerRenderSlot::CONTENT_START ),
				\CartPops\Blocks\CartDrawer\DrawerRenderMarkupPolicy::allowed_html()
			);
			?>

			<!-- Items List -->
			<div class="cpops-drawer__items" data-wp-class--cpops-hidden="state.isEmpty">
				<template data-wp-each--item="state.renderCartItems" data-wp-each-key="context.item.key">
					<div
						class="cpops-cart-item"
						data-wp-class--cpops-cart-item--optional-locked="context.item.optionalLocked"
					>
						<a class="cpops-cart-item__image" data-wp-bind--href="context.item.permalink">
							<img data-wp-bind--src="context.item.cartpopsImageSrc" data-wp-bind--alt="context.item.name" loading="lazy" />
						</a>
						<div class="cpops-cart-item__details">
							<h3 class="cpops-cart-item__name">
								<a class="cpops-cart-item__link" data-wp-bind--href="context.item.permalink" data-wp-text="context.item.name"></a>
							</h3>
							<div class="cpops-cart-item__meta" data-wp-text="context.item.variationSummary"></div>
							<div class="cpops-cart-item__price" data-wp-class--cpops-cart-item__price--sale="context.item.isOnSale">
								<span class="cpops-cart-item__price-regular" data-wp-class--cpops-hidden="!context.item.formattedRegularLineTotal" data-wp-text="context.item.formattedRegularLineTotal"></span>
								<span class="cpops-cart-item__price-current" data-wp-text="context.item.formattedLineTotal"></span>
								<span class="cpops-cart-item__price-each" data-wp-class--cpops-hidden="!context.item.unitPriceEach" data-wp-text="context.item.unitPriceEach"></span>
							</div>
							<template data-wp-each--extra="context.item.extraLines">
								<div class="cpops-cart-item__extra-line">
									<span class="cpops-cart-item__extra-label" data-wp-text="context.extra.label"></span>
									<span class="cpops-cart-item__extra-value" data-wp-text="context.extra.value"></span>
								</div>
							</template>
							<p
								class="cpops-cart-item__backorder"
								data-wp-class--cpops-hidden="!context.item.hasBackorderNotice"
								data-wp-text="context.item.backorderNotice"
								role="status"
								aria-live="polite"
								aria-atomic="true"
							></p>
						</div>
						<div class="cpops-cart-item__actions">
							<?php if ( ( $drawer['quantity_style'] ?? 'default' ) !== 'none' ) : ?>
							<div
								class="cpops-quantity cpops-quantity--<?php echo esc_attr( $drawer['quantity_style'] ?? 'default' ); ?>"
								data-wp-class--cpops-hidden="!context.item.showQuantityControls"
							>
								<button
									type="button"
									class="cpops-quantity__btn cpops-quantity__btn--minus"
									data-cpops-cart-mutation
									data-wp-on--click="actions.decrementQuantity"
									data-wp-bind--disabled="!context.item.canDecrementQuantity"
								>
									<span class="cpops-sr-only"><?php esc_html_e( 'Decrease quantity:', 'cartpops' ); ?> <span data-wp-text="context.item.name"></span></span>
									<svg aria-hidden="true" focusable="false" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2">
										<line x1="4" y1="8" x2="12" y2="8"></line>
									</svg>
								</button>
								<span class="cpops-quantity__value" data-wp-text="context.item.quantity"></span>
								<button
									type="button"
									class="cpops-quantity__btn cpops-quantity__btn--plus"
									data-cpops-cart-mutation
									data-wp-on--click="actions.incrementQuantity"
									data-wp-bind--disabled="!context.item.canIncrementQuantity"
								>
									<span class="cpops-sr-only"><?php esc_html_e( 'Increase quantity:', 'cartpops' ); ?> <span data-wp-text="context.item.name"></span></span>
									<svg aria-hidden="true" focusable="false" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2">
										<line x1="4" y1="8" x2="12" y2="8"></line>
										<line x1="8" y1="4" x2="8" y2="12"></line>
									</svg>
								</button>
							</div>
							<?php endif; ?>
							<button
								type="button"
								class="cpops-cart-item__remove"
								data-cpops-cart-mutation
								data-wp-on--click="actions.removeItem"
								data-wp-class--cpops-hidden="!context.item.showRemoveControl"
								data-wp-bind--disabled="!context.item.showRemoveControl"
							>
								<span class="cpops-sr-only"><?php esc_html_e( 'Remove item:', 'cartpops' ); ?> <span data-wp-text="context.item.name"></span></span>
								<svg aria-hidden="true" focusable="false" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5">
									<path d="M2 4h12M5 4V3a1 1 0 011-1h4a1 1 0 011 1v1M6 7v5M10 7v5M3 4l1 9a1 1 0 001 1h6a1 1 0 001-1l1-9"></path>
								</svg>
							</button>
						</div>
						<?php
						echo wp_kses(
							$drawer_render->markup( \CartPops\Blocks\CartDrawer\DrawerRenderSlot::CART_ITEM_AFTER_ACTIONS ),
							\CartPops\Blocks\CartDrawer\DrawerRenderMarkupPolicy::allowed_html()
						);
						?>
					</div>
				</template>
			</div>

			<!-- Empty State -->
			<div class="cpops-drawer__empty cpops-empty-cart" data-wp-class--cpops-hidden="!state.isEmpty">
				<svg class="cpops-drawer__empty-icon" width="64" height="64" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="1.5">
					<circle cx="24" cy="56" r="4"></circle>
					<circle cx="48" cy="56" r="4"></circle>
					<path d="M2 2h8l6 36h36l6-24H16"></path>
				</svg>
				<p class="cpops-drawer__empty-text">
					<?php echo esc_html( $empty_title ); ?>
				</p>
				<?php if ( '' !== $empty_subtitle ) : ?>
				<p class="cpops-drawer__empty-subtitle">
					<?php echo esc_html( $empty_subtitle ); ?>
				</p>
				<?php endif; ?>
				<a
					class="cpops-btn cpops-btn--primary"
					href="<?php echo esc_url( $shop_url ); ?>"
					data-wp-bind--href="state.config.emptyCartUrl"
					data-wp-on--click="actions.closeDrawer"
				>
					<?php echo esc_html( $empty_button_text ); ?>
				</a>
			</div>

			<!-- Undo Bar -->
			<div class="cpops-drawer__undo" data-wp-class--cpops-hidden="!state.undoItem">
				<span data-wp-text="state.undoMessage"></span>
				<button
					class="cpops-drawer__undo-btn"
					data-cpops-cart-mutation
					data-wp-on--click="actions.undoRemove"
				>
					<?php esc_html_e( 'Undo', 'cartpops' ); ?>
				</button>
			</div>

			<!-- Recommendations -->
			<?php if ( ! empty( $rec_settings['enabled'] ) ) : ?>
			<div
				class="cpops-recs<?php echo $recommendation_button_shows_text ? ' cpops-recs--button-text' : ''; ?>"
				data-wp-class--cpops-hidden="state.recsEmpty"
				data-wp-class--cpops-recs--button-text="state.recommendationButtonShowsText"
			>
				<h3 class="cpops-recs__heading" data-wp-text="state.recsConfig.heading">
					<?php echo esc_html( $rec_settings['heading'] ?? __( 'You may also like', 'cartpops' ) ); ?>
				</h3>
				<div class="cpops-recs__slider">
					<div class="cpops-recs__list cpops-recs__list--<?php echo esc_attr( $rec_settings['layout'] ?? 'horizontal' ); ?>">
						<template data-wp-each--rec="state.recommendations" data-wp-each-key="context.rec.id">
							<div class="cpops-recs__card">
								<div class="cpops-recs__card-image">
									<img
										data-wp-bind--src="context.rec.image"
										data-wp-bind--alt="context.rec.name"
										loading="lazy"
									/>
								</div>
								<div class="cpops-recs__card-info">
									<h4 class="cpops-recs__card-name" data-wp-text="context.rec.name"></h4>
									<div class="cpops-recs__card-price" data-wp-text="context.rec.formattedPrice"></div>
								</div>
								<button
									type="button"
									class="cpops-recs__add-btn<?php echo $recommendation_button_shows_text ? ' cpops-recs__add-btn--text' : ''; ?>"
									data-cpops-cart-mutation
									data-wp-class--cpops-hidden="!context.rec.is_add_action"
									data-wp-class--cpops-recs__add-btn--text="state.recommendationButtonShowsText"
									data-wp-on--click="actions.addRecommendation"
									data-wp-bind--aria-label="context.rec.recommendation_button_label"
									aria-label="<?php esc_attr_e( 'Add to cart', 'cartpops' ); ?>"
								>
									<span class="cpops-recs__add-btn-icon<?php echo $recommendation_button_shows_icon ? '' : ' cpops-hidden'; ?>" aria-hidden="true" data-wp-class--cpops-hidden="!state.recommendationButtonShowsIcon">
										<svg focusable="false" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
									</span>
									<span class="cpops-recs__add-btn-text<?php echo $recommendation_button_shows_text ? '' : ' cpops-hidden'; ?>" data-wp-class--cpops-hidden="!state.recommendationButtonShowsText" data-wp-text="state.recommendationButtonText"><?php echo esc_html( $recommendation_button_text ); ?></span>
								</button>
								<a
									class="cpops-recs__select-options cpops-hidden"
									data-wp-class--cpops-hidden="!context.rec.is_select_options_action"
									data-wp-bind--href="context.rec.permalink"
									data-wp-bind--aria-label="context.rec.control_label"
								>
									<span data-wp-text="context.rec.control_text"><?php esc_html_e( 'Select options', 'cartpops' ); ?></span>
								</a>
							</div>
						</template>
					</div>
					<button
						class="cpops-recs__nav cpops-recs__nav--prev"
						data-wp-on--click="actions.scrollRecsPrev"
						aria-label="<?php esc_attr_e( 'Previous', 'cartpops' ); ?>"
					>
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
					</button>
					<button
						class="cpops-recs__nav cpops-recs__nav--next"
						data-wp-on--click="actions.scrollRecsNext"
						aria-label="<?php esc_attr_e( 'Next', 'cartpops' ); ?>"
					>
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
					</button>
				</div>
			</div>
			<?php endif; ?>

			<!-- Totals Breakdown (body position) -->
			<?php if ( $totals_visibility->shows_breakdown_at( 'body' ) ) : ?>
			<div
				class="<?php echo esc_attr( 'cpops-totals cpops-totals--body' . ( $body_fee_only ? ' cpops-hidden' : '' ) ); ?>"
				data-wp-class--cpops-hidden="<?php echo esc_attr( $body_fee_only ? '!state.hasFees' : 'state.isEmpty' ); ?>"
			>
				<?php if ( $totals_visibility->shows_subtotal() ) : ?>
				<div class="cpops-totals__row">
					<span class="cpops-totals__label"><?php echo esc_html( $subtotal_label ); ?></span>
					<span class="cpops-totals__value" data-wp-text="state.cartSubtotal"></span>
				</div>
				<?php endif; ?>
				<?php if ( $totals_visibility->shows_discount() ) : ?>
				<div class="cpops-totals__row cpops-totals__row--discount cpops-hidden" data-wp-class--cpops-hidden="!state.hasCoupons">
					<span class="cpops-totals__label"><?php echo esc_html( $discount_label ); ?></span>
					<span class="cpops-totals__value" data-wp-text="state.couponDiscount"></span>
				</div>
				<?php endif; ?>
				<?php if ( $totals_visibility->shows_shipping() ) : ?>
				<div class="cpops-totals__row cpops-totals__row--fee" data-wp-class--cpops-hidden="!state.cartShipping">
					<span class="cpops-totals__label"><?php esc_html_e( 'Shipping', 'cartpops' ); ?></span>
					<span class="cpops-totals__value" data-wp-text="state.cartShipping"></span>
				</div>
				<?php endif; ?>
				<template data-wp-each--fee="state.cartFees" data-wp-each-key="context.fee.name">
					<div class="cpops-totals__row cpops-totals__row--fee">
						<span class="cpops-totals__label" data-wp-text="context.fee.name"></span>
						<span class="cpops-totals__value" data-wp-text="context.fee.total"></span>
					</div>
				</template>
				<?php if ( $totals_visibility->shows_tax() && wc_tax_enabled() ) : ?>
				<div class="cpops-totals__row cpops-totals__row--fee" data-wp-class--cpops-hidden="!state.showTaxLine">
					<span class="cpops-totals__label"><?php echo esc_html( WC()->countries->tax_or_vat() ); ?></span>
					<span class="cpops-totals__value" data-wp-text="state.cartTax"></span>
				</div>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>

		<?php
		echo wp_kses(
			$drawer_render->markup( \CartPops\Blocks\CartDrawer\DrawerRenderSlot::PANEL_AFTER_CONTENT ),
			\CartPops\Blocks\CartDrawer\DrawerRenderMarkupPolicy::allowed_html()
		);
		?>

		<!-- Footer -->
		<footer class="cpops-drawer__footer cpops-drawer-footer" data-wp-class--cpops-hidden="state.isEmpty">
			<?php do_action( 'cartpops_drawer_footer_before' ); ?>

			<?php do_action( 'cartpops_drawer_footer_content' ); ?>

			<?php
			echo wp_kses(
				$drawer_render->markup( \CartPops\Blocks\CartDrawer\DrawerRenderSlot::FOOTER_START ),
				\CartPops\Blocks\CartDrawer\DrawerRenderMarkupPolicy::allowed_html()
			);
			?>

			<!-- Coupon Form -->
			<?php if ( $drawer['show_coupon'] ?? true ) : ?>
				<?php do_action( 'cartpops_drawer_coupon_wrapper_start' ); ?>

			<div class="cpops-coupon" data-wp-class--cpops-coupon--open="state.couponExpanded">
				<?php // Shown only on short phone screens, where the form starts collapsed. ?>
				<button
					type="button"
					class="cpops-coupon__toggle"
					aria-expanded="false"
					aria-controls="<?php echo esc_attr( $coupon_form_id ); ?>"
					data-wp-bind--aria-expanded="state.couponExpanded"
					data-wp-on--click="actions.toggleCouponForm"
				>
					<?php echo esc_html( '' !== $coupon_title ? $coupon_title : __( 'Have a coupon?', 'cartpops' ) ); ?>
				</button>
				<?php do_action( 'cartpops_drawer_coupon_form_before' ); ?>
				<?php if ( '' !== $coupon_title ) : ?>
				<p class="cpops-coupon__title"><?php echo esc_html( $coupon_title ); ?></p>
				<?php endif; ?>

				<div class="cpops-coupon__form" id="<?php echo esc_attr( $coupon_form_id ); ?>">
					<label class="cpops-sr-only" for="<?php echo esc_attr( $coupon_input_id ); ?>"><?php esc_html_e( 'Coupon code', 'cartpops' ); ?></label>
					<input
						id="<?php echo esc_attr( $coupon_input_id ); ?>"
						type="text"
						class="cpops-coupon__input"
						data-cpops-cart-mutation
						placeholder="<?php echo esc_attr( $coupon_placeholder ); ?>"
						data-wp-bind--value="state.couponCode"
						data-wp-on--input="actions.setCouponCode"
						data-wp-on--keydown="actions.handleCouponKeydown"
					/>
					<button
						type="button"
						class="cpops-btn cpops-btn--secondary cpops-coupon__btn"
						data-cpops-cart-mutation
						data-wp-on--click="actions.applyCoupon"
						data-wp-bind--disabled="state.couponApplying"
						data-wp-class--cpops-btn--loading="state.couponApplying"
					>
						<?php echo esc_html( $coupon_button_text ); ?>
					</button>
				</div>

				<?php do_action( 'cartpops_drawer_coupon_form_after' ); ?>

				<div class="cpops-coupon__tags cpops-hidden" data-wp-class--cpops-hidden="!state.hasCoupons">
					<template data-wp-each--coupon="state.coupons" data-wp-each-key="context.coupon.key">
						<span class="cpops-coupon__tag">
							<span class="cpops-coupon__code" data-wp-text="context.coupon.label"></span>
							<button
								type="button"
								class="cpops-coupon__remove"
								data-cpops-cart-mutation
								data-wp-on--click="actions.removeCoupon"
								data-wp-bind--hidden="!context.coupon.removable"
								data-wp-bind--disabled="!context.coupon.removable"
							>
								<span class="cpops-sr-only"><?php esc_html_e( 'Remove coupon:', 'cartpops' ); ?> <span data-wp-text="context.coupon.label"></span></span>
								<span aria-hidden="true">&times;</span>
							</button>
						</span>
					</template>
				</div>
			</div>

				<?php do_action( 'cartpops_drawer_coupon_wrapper_end' ); ?>
			<?php endif; ?>
			<p
				class="cpops-coupon__error cpops-drawer__status"
				data-wp-bind--hidden="state.errorHidden"
				data-wp-text="state.error"
			></p>

			<!-- Totals -->
			<?php if ( $totals_visibility->shows_footer_totals() ) : ?>
			<div
				class="<?php echo esc_attr( 'cpops-totals' . ( $footer_fee_only ? ' cpops-hidden' : '' ) ); ?>"
				<?php if ( $footer_fee_only ) : ?>
				data-wp-class--cpops-hidden="!state.hasFees"
				<?php endif; ?>
			>
				<?php if ( $totals_visibility->shows_breakdown_at( 'footer' ) ) : ?>
					<?php if ( $totals_visibility->shows_subtotal() ) : ?>
				<div class="cpops-totals__row">
					<span class="cpops-totals__label"><?php echo esc_html( $subtotal_label ); ?></span>
					<span class="cpops-totals__value" data-wp-text="state.cartSubtotal"></span>
				</div>
					<?php endif; ?>
					<?php if ( $totals_visibility->shows_discount() ) : ?>
				<div class="cpops-totals__row cpops-totals__row--discount cpops-hidden" data-wp-class--cpops-hidden="!state.hasCoupons">
					<span class="cpops-totals__label"><?php echo esc_html( $discount_label ); ?></span>
					<span class="cpops-totals__value" data-wp-text="state.couponDiscount"></span>
				</div>
					<?php endif; ?>
					<?php if ( $totals_visibility->shows_shipping() ) : ?>
				<div class="cpops-totals__row cpops-totals__row--fee" data-wp-class--cpops-hidden="!state.cartShipping">
					<span class="cpops-totals__label"><?php esc_html_e( 'Shipping', 'cartpops' ); ?></span>
					<span class="cpops-totals__value" data-wp-text="state.cartShipping"></span>
				</div>
					<?php endif; ?>
				<template data-wp-each--fee="state.cartFees" data-wp-each-key="context.fee.name">
					<div class="cpops-totals__row cpops-totals__row--fee">
						<span class="cpops-totals__label" data-wp-text="context.fee.name"></span>
						<span class="cpops-totals__value" data-wp-text="context.fee.total"></span>
					</div>
				</template>
					<?php if ( $totals_visibility->shows_tax() && wc_tax_enabled() ) : ?>
				<div class="cpops-totals__row cpops-totals__row--fee" data-wp-class--cpops-hidden="!state.showTaxLine">
					<span class="cpops-totals__label"><?php echo esc_html( WC()->countries->tax_or_vat() ); ?></span>
					<span class="cpops-totals__value" data-wp-text="state.cartTax"></span>
				</div>
				<?php endif; ?>
				<?php endif; ?>
				<?php if ( $totals_visibility->shows_total() ) : ?>
				<div class="cpops-totals__row cpops-totals__row--total">
					<span class="cpops-totals__label"><?php echo esc_html( $total_label ); ?></span>
					<span class="cpops-totals__value" data-wp-text="state.cartTotal"></span>
				</div>
				<?php endif; ?>
				<?php if ( $totals_visibility->shows_tax_note() && wc_tax_enabled() ) : ?>
				<div class="cpops-totals__row cpops-totals__row--tax-note" data-wp-class--cpops-hidden="!state.showTaxNote">
					<span class="cpops-totals__tax-note" data-wp-text="state.taxNoteText"></span>
				</div>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<!-- Checkout Button -->
			<?php do_action( 'cartpops_drawer_before_checkout_button' ); ?>

			<a
				class="cpops-btn cpops-btn--primary cpops-btn--checkout"
				href="<?php echo esc_url( $checkout_url ); ?>"
				data-wp-bind--href="state.config.checkoutUrl"
			>
				<?php
				$checkout_text = $drawer['checkout_button_text'] ?? '';
				echo esc_html( $checkout_text ? $checkout_text : __( 'Proceed to Checkout', 'cartpops' ) );
				?>
			</a>

			<?php do_action( 'cartpops_drawer_after_checkout_button' ); ?>

			<!-- Optional Secondary Action -->
			<?php do_action( 'cartpops_drawer_before_secondary_checkout_button' ); ?>

			<?php if ( null !== $secondary_action ) : ?>
				<?php $secondary_action_classes = implode( ' ', $secondary_action['classes'] ); ?>
				<?php if ( 'continue_shopping' === $secondary_action['mode'] ) : ?>
			<button
				type="button"
				class="<?php echo esc_attr( $secondary_action_classes ); ?>"
				data-wp-on--click="actions.activateSecondaryAction"
			>
					<?php echo esc_html( $secondary_action['text'] ); ?>
			</button>
				<?php else : ?>
			<a
				class="<?php echo esc_attr( $secondary_action_classes ); ?>"
				href="<?php echo esc_url( (string) $secondary_action['navigationUrl'] ); ?>"
			>
					<?php echo esc_html( $secondary_action['text'] ); ?>
			</a>
				<?php endif; ?>
			<?php endif; ?>

			<?php do_action( 'cartpops_drawer_after_secondary_checkout_button' ); ?>

			<!-- View Cart Link -->
			<?php if ( ! $hide_native_view_cart ) : ?>
			<a
				class="cpops-drawer__view-cart"
				data-wp-bind--href="state.config.cartUrl"
			>
				<?php esc_html_e( 'View Cart', 'cartpops' ); ?>
			</a>
			<?php endif; ?>

			<?php if ( null !== $powered_by_url ) : ?>
			<div class="cpops-powered-by">
				<?php esc_html_e( 'Powered by', 'cartpops' ); ?>
				<a href="<?php echo esc_url( $powered_by_url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'CartPops', 'cartpops' ); ?>
					<span class="cpops-sr-only"><?php esc_html_e( '(opens in a new tab)', 'cartpops' ); ?></span>
				</a>
			</div>
			<?php endif; ?>

			<?php do_action( 'cartpops_drawer_footer_after' ); ?>
		</footer>

		<?php do_action( 'cartpops_drawer_panel_wrapper_end' ); ?>
	</aside>

	<!-- Live Region for Screen Readers -->
	<div class="cpops-sr-only" data-cartpops-live-region aria-live="polite" aria-atomic="true" data-wp-text="state.liveMessage"></div>

	<?php do_action( 'cartpops_drawer_wrapper_end' ); ?>
</div>
