<?php
/**
 * Orchestrates recommendation strategies and returns product data.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Recommendations;

use CartPops\Admin\SettingsRepository;

/**
 * Orchestrates recommendation strategies and returns product data.
 */
final class RecommendationEngine {
	private const PRODUCT_NAME_MAX_CHARS  = 160;
	private const CONTROL_LABEL_MAX_CHARS = 200;
	private const CONTROL_TEXT_MAX_CHARS  = 80;
	private const PERMALINK_MAX_BYTES     = 8192;

	/**
	 * Registered strategies keyed by type identifier.
	 *
	 * @var RecommendationStrategy[]
	 */
	private array $strategies = array();

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 */
	public function __construct(
		private readonly SettingsRepository $settings,
	) {
		$this->register_default_strategies();
	}

	/**
	 * Register a recommendation strategy.
	 *
	 * @param RecommendationStrategy $strategy The strategy to register.
	 */
	public function register_strategy( RecommendationStrategy $strategy ): void {
		$this->strategies[ $strategy->get_type() ] = $strategy;
	}

	/**
	 * Get recommendations for the current cart.
	 *
	 * @return array<int, array{id: int, name: string, price: string, formatted_price: string, image: string, permalink: string, action: 'add'|'select_options', control_text: string, control_label: string, add_label: string, is_add_action: bool, is_select_options_action: bool}>
	 */
	public function get_recommendations(): array {
		$config = $this->settings->get( 'recommendations', array() );

		if ( ! ( $config['enabled'] ?? false ) ) {
			return array();
		}

		$strategy_type = $config['strategy'] ?? 'cross_sell';
		$limit         = (int) ( $config['limit'] ?? 4 );

		$strategy = 'random' === $strategy_type ? null : ( $this->strategies[ $strategy_type ] ?? null );

		if ( ! function_exists( 'WC' ) || null === WC()->cart ) {
			return array();
		}

		// Collect parent and variation IDs so paid/manual selections cannot
		// recommend the exact variation that is already in the cart.
		$cart_product_ids = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			foreach ( array( 'product_id', 'variation_id' ) as $key ) {
				$product_id = $this->positive_product_id( $item[ $key ] ?? null );
				if ( null !== $product_id ) {
					$cart_product_ids[ $product_id ] = $product_id;
				}
			}
		}
		$cart_product_ids = array_values( $cart_product_ids );

		if ( empty( $cart_product_ids ) ) {
			return array();
		}

		$products = array();
		if ( $strategy ) {
			$recommended_ids = $strategy->get_recommendations( $cart_product_ids, $limit );
			$products        = $this->build_product_data( $recommended_ids );
		}

		// Match V1: fall back only when the primary produces no customer-visible
		// products. Never top up a partially eligible primary selection.
		if ( empty( $products ) ) {
			$fallback_type = $config['fallback'] ?? 'random';
			$fallback      = $this->strategies[ $fallback_type ] ?? null;

			if ( $fallback && $fallback->get_type() !== $strategy_type ) {
				$fallback_ids = $fallback->get_recommendations( $cart_product_ids, $limit );
				$products     = $this->build_product_data( $fallback_ids );
			}
		}

		return $products;
	}

	/**
	 * Build structured product data for the frontend.
	 *
	 * @param  int[] $product_ids Product IDs to build data for, in display order.
	 * @return array<int, array{id: int, name: string, price: string, formatted_price: string, image: string, permalink: string, action: 'add'|'select_options', control_text: string, control_label: string, add_label: string, is_add_action: bool, is_select_options_action: bool}>
	 */
	private function build_product_data( array $product_ids ): array {
		if ( empty( $product_ids ) ) {
			return array();
		}

		// Batch-load all recommended products in one query.
		$wc_products = wc_get_products(
			array(
				'include' => $product_ids,
				'limit'   => count( $product_ids ),
				'return'  => 'objects',
			)
		);

		// Index by ID so we can preserve the original ordering.
		$indexed = array();
		foreach ( $wc_products as $product ) {
			$indexed[ $product->get_id() ] = $product;
		}

		$tax_display = get_option( 'woocommerce_tax_display_cart', 'excl' );
		$products    = array();

		foreach ( $product_ids as $product_id ) {
			$product = $indexed[ $product_id ] ?? null;
			if (
				! $product
				|| 'publish' !== $product->get_status()
				|| ! $product->is_visible()
				|| ! $product->is_purchasable()
				|| ! $product->is_in_stock()
			) {
				continue;
			}

			$action    = $product->is_type( 'simple' ) ? 'add' : 'select_options';
			$permalink = $this->normalize_permalink( $product->get_permalink() );
			if ( 'select_options' === $action && '' === $permalink ) {
				continue;
			}

			$image_id  = $product->get_image_id();
			$image_url = $image_id
				? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' )
				: wc_placeholder_img_src( 'woocommerce_thumbnail' );

			// Use the tax-display-aware price, matching woocommerce_tax_display_cart.
			// This mirrors the CartStateBuilder pattern so prices are consistent
			// across the entire drawer.
			$display_price = 'incl' === $tax_display
				? wc_get_price_including_tax( $product )
				: wc_get_price_excluding_tax( $product );

			// Pre-format the price server-side so the frontend can use it directly.
			// wc_price() respects all WooCommerce currency settings (symbol, position,
			// decimal separator, thousand separator) — the result will match the rest
			// of the drawer exactly, including shops that show no currency symbol.
			$formatted_price = html_entity_decode(
				wp_strip_all_tags( wc_price( $display_price ) ),
				ENT_QUOTES,
				'UTF-8'
			);
			$product_name    = $product->get_name();
			$control_text    = $this->control_text( $action );
			$control_label   = $this->control_label( $action, $product_name, $control_text );

			$products[] = array(
				'id'                       => $product->get_id(),
				'name'                     => $product_name,
				'price'                    => (string) $display_price,
				'formatted_price'          => $formatted_price,
				'image'                    => $image_url ? $image_url : '',
				'permalink'                => $permalink,
				'action'                   => $action,
				'control_text'             => $control_text,
				'control_label'            => $control_label,
				// Retain the existing field for add-action consumers while the
				// drawer moves to the action-neutral control label.
				'add_label'                => 'add' === $action ? $control_label : '',
				'is_add_action'            => 'add' === $action,
				'is_select_options_action' => 'select_options' === $action,
			);
		}

		return $products;
	}

	/**
	 * Build one localized, plain-text recommendation control label.
	 *
	 * @param string $action       Closed recommendation action.
	 * @param string $product_name Authoritative WooCommerce product name.
	 * @param string $fallback     Already-normalized visible control text.
	 */
	private function control_label( string $action, string $product_name, string $fallback ): string {
		$name = $this->safe_bounded_text( $product_name, self::PRODUCT_NAME_MAX_CHARS );
		if ( '' === $name ) {
			return $fallback;
		}

		if ( 'select_options' === $action ) {
			/* translators: %s: product name. */
			$template = __( 'Select options for %s', 'cartpops' );
		} else {
			/* translators: %s: product name. */
			$template = __( 'Add %s to cart', 'cartpops' );
		}

		$label = $this->safe_bounded_text(
			sprintf( $template, $name ),
			self::CONTROL_LABEL_MAX_CHARS
		);

		return '' !== $label ? $label : $fallback;
	}

	/**
	 * Build the localized visible text for one closed action.
	 *
	 * @param string $action Closed recommendation action.
	 */
	private function control_text( string $action ): string {
		$fallback = 'select_options' === $action ? 'Select options' : 'Add to cart';
		$text     = 'select_options' === $action
			? __( 'Select options', 'cartpops' )
			: __( 'Add to cart', 'cartpops' );
		$text     = $this->safe_bounded_text( $text, self::CONTROL_TEXT_MAX_CHARS );

		return '' !== $text ? $text : $fallback;
	}

	/**
	 * Accept a bounded HTTP(S) or unambiguous root-relative product URL.
	 *
	 * @param mixed $candidate WooCommerce product permalink.
	 */
	private function normalize_permalink( mixed $candidate ): string {
		if (
			! is_string( $candidate )
			|| '' === $candidate
			|| strlen( $candidate ) > self::PERMALINK_MAX_BYTES
			|| trim( $candidate ) !== $candidate
			|| str_contains( $candidate, '\\' )
			|| 1 === preg_match( '/[\x00-\x20\x7f]/', $candidate )
		) {
			return '';
		}

		$parts = wp_parse_url( $candidate );
		if ( ! is_array( $parts ) ) {
			return '';
		}

		if ( str_starts_with( $candidate, '/' ) ) {
			$valid = ! str_starts_with( $candidate, '//' )
				&& ! isset( $parts['scheme'] )
				&& ! isset( $parts['host'] )
				&& ! isset( $parts['user'] )
				&& ! isset( $parts['pass'] );
		} else {
			$scheme = isset( $parts['scheme'] ) && is_string( $parts['scheme'] )
				? strtolower( $parts['scheme'] )
				: '';
			$valid  = 1 === preg_match( '/\Ahttps?:\/\//iD', $candidate )
				&& in_array( $scheme, array( 'http', 'https' ), true )
				&& isset( $parts['host'] )
				&& is_string( $parts['host'] )
				&& '' !== $parts['host']
				&& ! isset( $parts['user'] )
				&& ! isset( $parts['pass'] );
		}

		if ( ! $valid ) {
			return '';
		}

		$escaped = esc_url_raw( $candidate, array( 'http', 'https' ) );
		return is_string( $escaped ) && $escaped === $candidate ? $candidate : '';
	}

	/**
	 * Strip markup and unsafe directional/control code points, then cap text.
	 *
	 * @param string $value     Candidate customer-facing text.
	 * @param int    $max_chars Maximum Unicode code points to retain.
	 */
	private function safe_bounded_text( string $value, int $max_chars ): string {
		$value = wp_strip_all_tags( $value, true );
		$value = sanitize_text_field( $value );
		$value = preg_replace(
			'/[<>\x{0000}-\x{001F}\x{007F}-\x{009F}\x{061C}\x{200E}\x{200F}\x{2028}-\x{202E}\x{2066}-\x{2069}]/u',
			' ',
			$value
		);
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = preg_replace( '/\s+/u', ' ', $value );
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		$count = preg_match_all( '/./us', $value, $characters );
		if ( false === $count ) {
			return '';
		}
		if ( $count > $max_chars ) {
			$value = implode( '', array_slice( $characters[0], 0, $max_chars ) );
		}

		return trim( $value );
	}

	/**
	 * Register built-in strategies.
	 */
	private function register_default_strategies(): void {
		$this->register_strategy( new UpsellStrategy() );
		$this->register_strategy( new CrossSellStrategy() );
		$this->register_strategy( new RandomProductStrategy() );
	}

	/**
	 * Parse a positive canonical WooCommerce product ID.
	 *
	 * @param mixed $value Candidate product ID.
	 * @return int|null
	 */
	private function positive_product_id( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return null;
		}

		$parsed = filter_var( $value, FILTER_VALIDATE_INT );
		return false !== $parsed && $parsed > 0 ? $parsed : null;
	}
}
