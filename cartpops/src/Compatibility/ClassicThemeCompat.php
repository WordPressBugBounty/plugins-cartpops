<?php
/**
 * Auto-renders the cart drawer and launcher for all themes.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Compatibility;

use CartPops\Admin\SettingsRepository;
use CartPops\Cart\DrawerRenderer;
use CartPops\Frontend\FrontendRuntimePolicy;

/**
 * Auto-renders the cart drawer and launcher for all themes.
 *
 * For block themes, blocks can also be placed via the site editor,
 * but we auto-render them in wp_footer to ensure they're always present.
 */
final class ClassicThemeCompat {
	private const LAUNCHER_SHORTCODES = array(
		'cartpops_launcher',
		'cartpops_cart_launcher',
	);

	/**
	 * Whether one usable Cart Drawer block has rendered in this request.
	 *
	 * @var bool
	 */
	private bool $drawer_rendered = false;

	/**
	 * Constructor.
	 *
	 * @param DrawerRenderer        $drawer_renderer Drawer renderer.
	 * @param SettingsRepository    $settings        Settings repository.
	 * @param FrontendRuntimePolicy $frontend_policy Frontend runtime policy.
	 */
	public function __construct(
		private readonly DrawerRenderer $drawer_renderer,
		private readonly SettingsRepository $settings,
		private readonly FrontendRuntimePolicy $frontend_policy,
	) {
		add_filter( 'pre_render_block', array( $this->frontend_policy, 'filter_pre_render_block' ), PHP_INT_MAX, 2 );
		add_filter( 'render_block', array( $this, 'filter_rendered_drawer' ), PHP_INT_MAX, 2 );
		add_filter( 'walker_nav_menu_start_el', array( $this, 'filter_nav_menu_item' ), 20, 2 );
	}

	/**
	 * Keep the first usable Cart Drawer block and suppress later duplicates.
	 *
	 * The instance is constructed once per WordPress request, so the detection
	 * does not leak into later requests or long-running test processes.
	 *
	 * @param mixed $block_content Rendered block markup.
	 * @param mixed $block         Parsed block.
	 * @return mixed Original value for unrelated or malformed input, empty for suppressed CartPops output.
	 */
	public function filter_rendered_drawer( mixed $block_content, mixed $block ): mixed {
		if ( ! is_array( $block ) ) {
			return $block_content;
		}

		if (
			! $this->frontend_policy->is_enabled()
			&& $this->frontend_policy->is_frontend_block( $block['blockName'] ?? null )
		) {
			return '';
		}

		if ( 'cartpops/cart-drawer' !== ( $block['blockName'] ?? '' ) ) {
			return $block_content;
		}

		if ( ! is_string( $block_content ) ) {
			return $block_content;
		}

		if ( '' === trim( $block_content ) ) {
			return $block_content;
		}

		if ( $this->drawer_rendered ) {
			return '';
		}

		$this->drawer_rendered = true;
		return $block_content;
	}

	/**
	 * Render the cart drawer in the footer.
	 */
	public function render_drawer(): void {
		if (
			$this->drawer_rendered ||
			! $this->frontend_policy->is_enabled() ||
			! apply_filters( 'cartpops_render_drawer', true )
		) {
			return;
		}

		$this->drawer_renderer->render();
	}

	/**
	 * Render the cart launcher in the footer.
	 */
	public function render_launcher(): void {
		if (
			! $this->frontend_policy->is_enabled() ||
			! $this->settings->get( 'launcher.enabled', true ) ||
			! $this->cart_is_available()
		) {
			return;
		}

		$block_content = render_block(
			array(
				'blockName' => 'cartpops/cart-launcher',
				'attrs'     => array(
					'position'           => $this->settings->get( 'launcher.position', 'bottom_right' ),
					'presentation'       => 'floating',
					'showCount'          => $this->settings->get( 'launcher.show_count', true ),
					'showTotal'          => $this->settings->get( 'launcher.show_total', false ),
					'hideEmpty'          => $this->settings->get( 'launcher.hide_empty', false ),
					'hideIndicatorEmpty' => $this->settings->get( 'launcher.hide_indicator_empty', true ),
					'icon'               => $this->settings->get( 'launcher.icon', 'cart' ),
				),
			)
		);

		echo $block_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block output.
	}

	/**
	 * Enqueue block styles and mark footer block modules early on the frontend.
	 */
	public function enqueue_assets(): void {
		if ( ! $this->frontend_policy->is_enabled() ) {
			return;
		}

		$this->enqueue_view_script_modules();
		wp_enqueue_style( 'cartpops-cart-drawer-style' );
		wp_enqueue_style( 'cartpops-cart-launcher-style' );
	}

	/**
	 * Enqueue block view modules before block-theme script modules print.
	 *
	 * CartPops auto-renders its blocks in wp_footer. WordPress 6.5 prints script
	 * modules in wp_head for block themes, so enqueueing only when those footer
	 * blocks render is too late. Read the generated IDs from registered block
	 * metadata to keep this aligned with WordPress' normal block registration.
	 */
	private function enqueue_view_script_modules(): void {
		if ( ! function_exists( 'wp_enqueue_script_module' ) || ! class_exists( '\\WP_Block_Type_Registry' ) ) {
			return;
		}

		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( array( 'cartpops/cart-drawer', 'cartpops/cart-launcher' ) as $block_name ) {
			$block_type = $registry->get_registered( $block_name );
			$module_ids = $block_type->view_script_module_ids ?? array();

			foreach ( $module_ids as $module_id ) {
				if ( is_string( $module_id ) && '' !== $module_id ) {
					wp_enqueue_script_module( $module_id );
				}
			}
		}
	}

	/**
	 * Register the current and legacy launcher shortcode names.
	 */
	public function register_shortcode(): void {
		foreach ( self::LAUNCHER_SHORTCODES as $shortcode ) {
			add_shortcode( $shortcode, array( $this, 'render_shortcode' ) );
		}
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array<string, string>|string $atts          Shortcode attributes.
	 * @param string|null                  $content       Enclosed content, unused.
	 * @param string                       $shortcode_tag Actual invoked shortcode tag.
	 */
	public function render_shortcode(
		array|string $atts = array(),
		?string $content = null,
		string $shortcode_tag = 'cartpops_launcher'
	): string {
		unset( $content );
		if ( ! $this->frontend_policy->is_enabled() || ! $this->cart_is_available() ) {
			return '';
		}
		$atts = is_array( $atts ) ? $atts : array();

		$defaults = array(
			'icon'                 => 'cart',
			'indicator'            => 'bubble',
			'subtotal'             => 'true',
			'indicator_hide_empty' => 'false',
			'hide_empty'           => 'false',
		);
		$atts     = shortcode_atts(
			$defaults,
			$atts,
			$shortcode_tag
		);
		$atts     = is_array( $atts ) ? $atts : $defaults;

		return $this->render_inline_launcher(
			array(
				'presentation'       => 'inline',
				'icon'               => $this->normalize_icon( $atts['icon'] ?? null, 'cart' ),
				'indicator'          => $this->normalize_indicator( $atts['indicator'] ?? null, 'bubble' ),
				'showCount'          => true,
				'showTotal'          => $this->normalize_boolean( $atts['subtotal'] ?? null, true ),
				'hideEmpty'          => $this->normalize_boolean( $atts['hide_empty'] ?? null, false ),
				'hideIndicatorEmpty' => $this->normalize_boolean( $atts['indicator_hide_empty'] ?? null, false ),
			)
		);
	}

	/**
	 * Replace only the deliberate V1 CartPops menu placeholder.
	 *
	 * @param mixed $item_output Existing menu item output.
	 * @param mixed $item        Menu item object.
	 * @return mixed Original output for unrelated or malformed items.
	 */
	public function filter_nav_menu_item( mixed $item_output, mixed $item ): mixed {
		if (
			! is_string( $item_output ) ||
			! is_object( $item ) ||
			! isset( $item->object ) ||
			! isset( $item->classes ) ||
			! is_array( $item->classes ) ||
			! in_array( 'cpops-cart-menu-item', $item->classes, true ) ||
			! $this->frontend_policy->is_enabled() ||
			! $this->cart_is_available()
		) {
			return $item_output;
		}

		$indicator  = $this->settings->get( 'launcher.menu.indicator', 'bubble' );
		$indicator  = $this->normalize_indicator( $indicator, 'bubble' );
		$attributes = array(
			'presentation'       => 'inline',
			'icon'               => $this->normalize_icon( $this->settings->get( 'launcher.menu.icon', 'bag' ), 'bag' ),
			'indicator'          => $indicator,
			'showCount'          => 'none' !== $indicator,
			'showTotal'          => (bool) $this->settings->get( 'launcher.menu.show_total', false ),
			'hideEmpty'          => false,
			'hideIndicatorEmpty' => (bool) $this->settings->get( 'launcher.menu.hide_indicator_empty', false ),
		);

		/**
		 * Filter the bounded attributes for an exact legacy navigation-menu launcher.
		 *
		 * Presentation is always normalized back to inline and every other value is
		 * checked against the launcher's closed schema before rendering.
		 *
		 * @param array<string, mixed> $attributes  Menu launcher block attributes.
		 * @param object               $item        Navigation menu item.
		 * @param string               $item_output Original menu item markup.
		 */
		$filtered = apply_filters( 'cartpops_menu_launcher_attributes', $attributes, $item, $item_output );

		return $this->render_inline_launcher( $this->normalize_menu_launcher_attributes( $filtered, $attributes ) );
	}

	/**
	 * Normalize the supported V1 and V2 icon names to bundled SVGs.
	 *
	 * @param mixed  $icon     Candidate icon name.
	 * @param string $fallback Safe icon used for malformed names.
	 */
	private function normalize_icon( mixed $icon, string $fallback = 'cart' ): string {
		if ( ! is_string( $icon ) || strlen( $icon ) > 64 ) {
			return $fallback;
		}

		$icons = array(
			'cart'                             => 'cart',
			'basket'                           => 'basket',
			'cpops-icon-shopping-cart-outline' => 'cart',
			'cpops-icon-shopping-cart-line'    => 'cart',
			'cpops-icon-shopping-cart-fill'    => 'cart',
			'cpops-icon-shopping-cart-2-line'  => 'cart',
			'cpops-icon-shopping-cart-2-fill'  => 'cart',
			'bag'                              => 'bag',
			'cpops-icon-shopping-bag-outline'  => 'bag',
			'cpops-icon-shopping-bag-line'     => 'bag',
			'cpops-icon-shopping-bag-fill'     => 'bag',
			'cpops-icon-shopping-bag-2-line'   => 'bag',
			'cpops-icon-shopping-bag-2-fill'   => 'bag',
			'cpops-icon-shopping-bag-3-fill'   => 'bag',
			'cpops-icon-handbag-line'          => 'bag',
			'cpops-icon-cpops-handbag-fill'    => 'bag',
		);

		return $icons[ $icon ] ?? $fallback;
	}

	/**
	 * Normalize the supported V1 count presentations.
	 *
	 * @param mixed  $indicator Candidate count presentation.
	 * @param string $fallback  Safe presentation used for malformed values.
	 */
	private function normalize_indicator( mixed $indicator, string $fallback = 'bubble' ): string {
		return is_string( $indicator ) && in_array( $indicator, array( 'none', 'bubble', 'plain' ), true )
			? $indicator
			: $fallback;
	}

	/**
	 * Normalize a trusted-code menu filter result to the exact inline block shape.
	 *
	 * @param mixed                $candidate Filtered value.
	 * @param array<string, mixed> $fallback  Saved, already-normalized menu attributes.
	 * @return array<string, mixed>
	 */
	private function normalize_menu_launcher_attributes( mixed $candidate, array $fallback ): array {
		$candidate  = is_array( $candidate ) ? $candidate : array();
		$indicator  = $this->normalize_indicator( $candidate['indicator'] ?? null, (string) $fallback['indicator'] );
		$show_count = $this->normalize_boolean( $candidate['showCount'] ?? null, 'none' !== $indicator );
		if ( false === $show_count ) {
			$indicator = 'none';
		} elseif ( 'none' === $indicator ) {
			$show_count = false;
		}

		return array(
			'presentation'       => 'inline',
			'icon'               => $this->normalize_icon( $candidate['icon'] ?? null, (string) $fallback['icon'] ),
			'indicator'          => $indicator,
			'showCount'          => $show_count,
			'showTotal'          => $this->normalize_boolean( $candidate['showTotal'] ?? null, (bool) $fallback['showTotal'] ),
			'hideEmpty'          => $this->normalize_boolean( $candidate['hideEmpty'] ?? null, (bool) $fallback['hideEmpty'] ),
			'hideIndicatorEmpty' => $this->normalize_boolean( $candidate['hideIndicatorEmpty'] ?? null, (bool) $fallback['hideIndicatorEmpty'] ),
		);
	}

	/**
	 * Render one already bounded inline launcher through the registered block.
	 *
	 * @param array<string, mixed> $attributes Normalized inline launcher attributes.
	 */
	private function render_inline_launcher( array $attributes ): string {
		$indicator               = $this->normalize_indicator( $attributes['indicator'] ?? null, 'bubble' );
		$attributes['indicator'] = $indicator;
		$attributes['showCount'] = 'none' !== $indicator;

		return render_block(
			array(
				'blockName' => 'cartpops/cart-launcher',
				'attrs'     => $attributes,
			)
		);
	}

	/**
	 * Parse a bounded compatibility boolean.
	 *
	 * @param mixed     $value    Candidate boolean value.
	 * @param bool|null $fallback Safe result used for malformed values.
	 */
	private function normalize_boolean( mixed $value, ?bool $fallback ): ?bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( 1 === $value || '1' === $value ) {
			return true;
		}
		if ( 0 === $value || '0' === $value ) {
			return false;
		}
		if ( ! is_string( $value ) || strlen( $value ) > 16 ) {
			return $fallback;
		}

		return match ( strtolower( trim( $value ) ) ) {
			'true', 'yes', 'on'  => true,
			'false', 'no', 'off' => false,
			default              => $fallback,
		};
	}

	/** Match V1's fail-safe behavior when WooCommerce has not initialized a cart. */
	private function cart_is_available(): bool {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}

		try {
			$woocommerce = WC();
			return is_object( $woocommerce ) && isset( $woocommerce->cart );
		} catch ( \Throwable ) {
			return false;
		}
	}
}
