<?php
/**
 * Blocksy theme integration.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Compatibility;

use CartPops\Admin\SettingsRepository;
use CartPops\Frontend\FrontendRuntimePolicy;

/**
 * Blocksy theme integration.
 *
 * Replaces Blocksy's header cart icon with a CartPops icon
 * and optionally intercepts clicks to open the CartPops drawer.
 *
 * Supports both:
 * - Classic Blocksy header cart element (.ct-header-cart / .ct-cart-item)
 * - WooCommerce Mini Cart Block icon styling used in Blocksy FSE headers
 */
final class BlocksyIntegration {

	/**
	 * SVG icon library — all use stroke="currentColor" for color inheritance.
	 * Width/height match Blocksy's default 15x15 icon sizing.
	 *
	 * @var array<string, string>
	 */
	private const ICONS = array(
		'cart'     => '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>',
		'bag'      => '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>',
		'basket'   => '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 10h20l-1.5 10a2 2 0 0 1-2 1.5H5.5a2 2 0 0 1-2-1.5L2 10z"/><path d="M6 10V6a6 6 0 0 1 12 0v4"/><line x1="12" y1="14" x2="12" y2="18"/><line x1="8" y1="14" x2="8" y2="18"/><line x1="16" y1="14" x2="16" y2="18"/></svg>',
		'cart-2'   => '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2h12l3 7H3L6 2z"/><path d="M3 9v11a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9"/><path d="M9 13v4"/><path d="M15 13v4"/></svg>',
		'bag-2'    => '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16a1 1 0 0 1 1 1v11a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3V8a1 1 0 0 1 1-1z"/><path d="M8 7V5a4 4 0 0 1 8 0v2"/></svg>',
		'basket-2' => '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5.5 21h13a1 1 0 0 0 1-.88L21 10H3l1.5 10.12a1 1 0 0 0 1 .88z"/><path d="M3 10l5-8"/><path d="M21 10l-5-8"/><line x1="12" y1="10" x2="12" y2="21"/><line x1="7.5" y1="10" x2="8.5" y2="21"/><line x1="16.5" y1="10" x2="15.5" y2="21"/></svg>',
	);

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository    $settings        Settings repository.
	 * @param FrontendRuntimePolicy $frontend_policy Frontend runtime policy.
	 */
	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly FrontendRuntimePolicy $frontend_policy,
	) {}

	/**
	 * Initialize hooks — only call when get_template() === 'blocksy'.
	 */
	public function init(): void {
		if (
			! $this->frontend_policy->is_enabled()
			|| ! $this->settings->get( 'integrations.blocksy.replace_icon', false )
		) {
			return;
		}

		// Classic Blocksy header cart icon replacement.
		add_filter( 'blocksy:header:cart:icons', array( $this, 'replace_cart_icons' ), 20 );

		// CSS for classic Blocksy cart + WC Mini Cart Block.
		add_action( 'wp_head', array( $this, 'output_cart_css' ), 90 );

		// JS to intercept clicks and open CartPops drawer.
		if ( $this->settings->get( 'integrations.blocksy.open_drawer', true ) ) {
			add_action( 'wp_footer', array( $this, 'intercept_cart_click' ), 30 );
		}
	}

	/**
	 * Replace all Blocksy cart icon types with the selected CartPops icon.
	 * Works for classic Blocksy PHP header builder.
	 *
	 * @param mixed $icons Blocksy icon definitions.
	 * @return mixed Filtered icons, or an unchanged malformed prior value.
	 */
	public function replace_cart_icons( mixed $icons ): mixed {
		if ( ! $this->frontend_policy->is_enabled() || ! is_array( $icons ) ) {
			return $icons;
		}
		foreach ( $icons as $key => $icon ) {
			if ( ! is_string( $key ) || ! is_string( $icon ) ) {
				return $icons;
			}
		}

		$style = $this->settings->get( 'integrations.blocksy.icon_style', 'cart' );
		$svg   = self::ICONS[ $style ] ?? self::ICONS['cart'];

		foreach ( array_keys( $icons ) as $key ) {
			$icons[ $key ] = $svg;
		}

		return $icons;
	}

	/**
	 * Output CSS to customize both classic Blocksy cart and WC Mini Cart Block.
	 */
	public function output_cart_css(): void {
		if ( ! $this->frontend_policy->is_enabled() ) {
			return;
		}

		$style      = $this->settings->get( 'integrations.blocksy.icon_style', 'cart' );
		$svg        = self::ICONS[ $style ] ?? self::ICONS['cart'];
		$show_count = $this->settings->get( 'integrations.blocksy.show_count', true );
		$icon_color = $this->settings->get( 'integrations.blocksy.icon_color', '' );
		$badge_bg   = $this->settings->get( 'integrations.blocksy.badge_bg', '' );
		$badge_text = $this->settings->get( 'integrations.blocksy.badge_text', '' );

		// Encode SVG for use as CSS background-image (data URI).
		$encoded_svg = $this->encode_svg_for_css( $svg );

		?>
		<style id="cartpops-blocksy-css">
			/* === Classic Blocksy header cart === */

			/* Preserve CartPops stroke-based icons — prevent Blocksy from filling them */
			.ct-header-cart .ct-icon-container svg {
				fill: none !important;
			}

			<?php if ( $icon_color ) : ?>
			/* Custom icon color */
			.ct-header-cart .ct-icon-container svg {
				stroke: <?php echo esc_attr( $icon_color ); ?> !important;
				color: <?php echo esc_attr( $icon_color ); ?> !important;
			}
			<?php endif; ?>

			/* Hide the cart amount/price label (e.g. "€ 68,00") */
			.ct-header-cart .ct-cart-item .ct-label {
				display: none !important;
			}

			<?php if ( ! $show_count ) : ?>
			/* Hide the item count badge */
			.ct-header-cart .ct-cart-item .ct-dynamic-count-cart {
				display: none !important;
			}
			<?php endif; ?>

			<?php if ( $badge_bg || $badge_text ) : ?>
			/* Custom badge colors — classic Blocksy */
			.ct-header-cart .ct-cart-item .ct-dynamic-count-cart {
				<?php if ( $badge_bg ) : ?>
				background-color: <?php echo esc_attr( $badge_bg ); ?> !important;
				<?php endif; ?>
				<?php if ( $badge_text ) : ?>
				color: <?php echo esc_attr( $badge_text ); ?> !important;
				<?php endif; ?>
			}
			<?php endif; ?>

			/* === WC Mini Cart Block (Blocksy FSE headers) === */

			/* Hide the default WC Mini Cart SVG icon */
			.wc-block-mini-cart .wc-block-mini-cart__button .wc-block-mini-cart__icon {
				display: none !important;
			}

			/* Insert CartPops icon via pseudo-element — uses mask-image so color inherits
				from parent (white on dark headers, dark on light headers). */
			.wc-block-mini-cart .wc-block-mini-cart__button .wc-block-mini-cart__quantity-badge::before {
				content: '';
				display: inline-block;
				width: 18px;
				height: 18px;
				<?php if ( $icon_color ) : ?>
				background-color: <?php echo esc_attr( $icon_color ); ?>;
				<?php else : ?>
				background-color: currentColor;
				<?php endif; ?>
				-webkit-mask-image: url("data:image/svg+xml,<?php echo $encoded_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $encoded_svg is built from a hardcoded internal SVG constant and URL-encoded by encode_svg_for_css() for safe embedding in a CSS data URI; HTML escaping would corrupt the encoding. ?>");
				mask-image: url("data:image/svg+xml,<?php echo $encoded_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- See note above; same hardcoded, pre-encoded SVG value. ?>");
				-webkit-mask-size: contain;
				mask-size: contain;
				-webkit-mask-repeat: no-repeat;
				mask-repeat: no-repeat;
				-webkit-mask-position: center;
				mask-position: center;
				vertical-align: middle;
			}

			/* Hide the cart amount text */
			.wc-block-mini-cart .wc-block-mini-cart__button .wc-block-mini-cart__amount {
				display: none !important;
			}

			<?php if ( ! $show_count ) : ?>
			/* Hide the item count badge */
			.wc-block-mini-cart .wc-block-mini-cart__button .wc-block-mini-cart__badge {
				display: none !important;
			}
			<?php endif; ?>

			/* Badge color — WC Mini Cart Block */
			.wc-block-mini-cart .wc-block-mini-cart__button .wc-block-mini-cart__badge {
				background-color: <?php echo $badge_bg ? esc_attr( $badge_bg ) : 'var(--theme-palette-color-1, #111827)'; ?> !important;
				color: <?php echo $badge_text ? esc_attr( $badge_text ) : '#fff'; ?> !important;
			}
		</style>
		<?php
	}

	/**
	 * Add JS to intercept cart icon clicks and open the CartPops drawer.
	 *
	 * Handles the classic Blocksy cart (.ct-cart-item). Woo Mini Cart block
	 * replacement is owned by the shared CartPops browser event bridge.
	 */
	public function intercept_cart_click(): void {
		if ( ! $this->frontend_policy->is_enabled() ) {
			return;
		}

		?>
		<script id="cartpops-blocksy-intercept">
		(function(){
			// Click interception — open CartPops drawer instead of theme cart.
			document.addEventListener('click', function(e) {
				var blocksyTrigger = e.target.closest('.ct-cart-item');
				if (blocksyTrigger) {
					e.preventDefault();
					e.stopPropagation();
					e.stopImmediatePropagation();
					openCartPopsDrawer();
					return;
				}
			}, true);

			function openCartPopsDrawer() {
				if (window.wp && window.wp.interactivity) {
					try {
						var store = window.wp.interactivity.store('cartpops');
						if (store && store.actions && store.actions.openDrawer) {
							store.actions.openDrawer();
							return;
						}
					} catch(ex) {}
				}
				document.dispatchEvent(new CustomEvent('cartpops:open'));
			}

			// Badge count sync — keep theme badge in sync with CartPops cart state.
			// Handles stale full-page cached pages and dynamic cart changes.
			function syncBadgeCount(count) {
				var badges = document.querySelectorAll(
					'.ct-dynamic-count-cart, .wc-block-mini-cart__badge'
				);
				badges.forEach(function(badge) {
					badge.textContent = String(count);
				});
			}

			// Initial sync: clear only when the exact WC cookie proves an empty cart.
			// Positive counts preserve the server-rendered theme badge. Malformed,
			// duplicate, and overflow values are unknown and cannot overwrite it.
			// cartpops-blocksy-cookie-parser:start
			function readInitialCartCookieState(cookieHeader) {
				var values = [];
				String(cookieHeader || '').split(';').forEach(function(part) {
					var cookie = part.replace(/^[\t ]+/, '');
					var separator = cookie.indexOf('=');
					if (
						separator > 0 &&
						cookie.slice(0, separator) === 'woocommerce_items_in_cart'
					) {
						values.push(cookie.slice(separator + 1));
					}
				});

				if (values.length === 0) {
					return 'empty';
				}
				if (values.length !== 1) {
					return 'unknown';
				}

				var value = values[0];
				if (value === '' || value === '0') {
					return 'empty';
				}
				if (!/^[1-9][0-9]{0,15}$/.test(value)) {
					return 'unknown';
				}

				var count = Number(value);
				return Number.isSafeInteger(count) && count > 0
					? 'nonempty'
					: 'unknown';
			}
			// cartpops-blocksy-cookie-parser:end

			if (readInitialCartCookieState(document.cookie) === 'empty') {
				syncBadgeCount(0);
			}

			// Live sync: CartPops dispatches this event on every cart count change.
			document.addEventListener('cartpops:count-updated', function(e) {
				if (e.detail && typeof e.detail.count !== 'undefined') {
					syncBadgeCount(e.detail.count);
				}
			});
		})();
		</script>
		<?php
	}

	/**
	 * Encode SVG string for use in CSS url() data URI.
	 *
	 * @param  string $svg The raw SVG markup.
	 * @return string
	 */
	private function encode_svg_for_css( string $svg ): string {
		// Replace stroke="currentColor" with white for CSS mask-image (luminance mode:
		// white strokes = visible, transparent fill = hidden). The actual color comes
		// from background-color: currentColor on the pseudo-element.
		$svg = str_replace( 'stroke="currentColor"', 'stroke="white"', $svg );
		$svg = str_replace( '"', "'", $svg );
		$svg = str_replace( '<', '%3C', $svg );
		$svg = str_replace( '>', '%3E', $svg );
		$svg = str_replace( '#', '%23', $svg );
		$svg = str_replace( '{', '%7B', $svg );
		$svg = str_replace( '}', '%7D', $svg );

		return $svg;
	}

	/**
	 * Get the icon SVG library (for use in REST API or elsewhere).
	 *
	 * @return array<string, string>
	 */
	public static function get_icons(): array {
		return self::ICONS;
	}
}
