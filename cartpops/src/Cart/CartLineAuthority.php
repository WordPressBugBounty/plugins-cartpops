<?php
/**
 * Server-owned cart-line presentation and interaction authority.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Cart;

/**
 * Projects the bounded customer-visible and mutation policy for one exact cart line.
 */
final class CartLineAuthority {
	private const CHILD_MARKERS = array(
		'mnm_container',
		'bundled_by',
		'ext_hidden_item',
		'woosb_parent_id',
		'composite_parent',
		'chained_item_of',
		'associated_parent',
	);

	private const DEFAULT_STORE_MAXIMUM = 9999;
	private const MAX_SAFE_INTEGER      = 9007199254740991;
	private const MAX_KEY_LENGTH        = 128;
	private const MAX_NOTICE_BYTES      = 500;
	private const CART_KEY_PATTERN      = '/\A[A-Za-z0-9._:-]+\z/D';

	/**
	 * Build authority from WooCommerce's exact cart item and cart key.
	 *
	 * A missing Store API utility is treated as an exceptional bootstrap state.
	 * The fallback reads the same product and Store API hooks, while any malformed
	 * quantity result pins the line to its current quantity and makes it read-only.
	 *
	 * @param array<string, mixed> $cart_item Raw WooCommerce cart item.
	 * @param mixed                $cart_key  Exact WooCommerce cart item key.
	 * @return array{visible: bool, quantityEditable: bool, removable: bool, quantityLimits: array{minimum: int, maximum: int, multipleOf: int, unlimited: bool}, backorderNotice: string}
	 */
	public static function for_cart_item( array $cart_item, mixed $cart_key ): array {
		$line = self::validate_raw_line( $cart_item, $cart_key );
		if ( null === $line ) {
			return self::locked_authority( $cart_item['quantity'] ?? null );
		}

		$product  = $line['product'];
		$cart_key = $line['key'];
		$visible  = self::is_visible( $cart_item, $cart_key );
		$is_child = self::has_child_marker( $cart_item );
		$limits   = self::quantity_limits( $product, $cart_item );

		if ( null === $limits ) {
			$limits = self::locked_limits( $cart_item['quantity'] ?? null );
		}

		$quantity_editable = $visible && ! $is_child && $limits['editable'];
		$removable         = $visible && ! $is_child;

		return array(
			'visible'          => $visible,
			'quantityEditable' => $quantity_editable,
			'removable'        => $removable,
			'quantityLimits'   => array(
				'minimum'    => $limits['minimum'],
				'maximum'    => $limits['maximum'],
				'multipleOf' => $limits['multipleOf'],
				'unlimited'  => $limits['unlimited'],
			),
			'backorderNotice'  => $visible
				? self::backorder_notice( $product, $cart_item, $cart_key )
				: '',
		);
	}

	/**
	 * Validate the raw line shape before any customer-facing callbacks run.
	 *
	 * @param array<string, mixed> $cart_item Raw WooCommerce cart item.
	 * @param mixed                $cart_key  Candidate cart item key.
	 * @return array{key: string, product: \WC_Product, product_id: int, quantity: int, variation: array<string, string>}|null
	 */
	public static function validate_raw_line( array $cart_item, mixed $cart_key ): ?array {
		if ( ! is_string( $cart_key ) || ! self::valid_key( $cart_key ) ) {
			return null;
		}

		$product    = $cart_item['data'] ?? null;
		$product_id = self::positive_integer( $cart_item['product_id'] ?? null );
		$quantity   = self::positive_integer( $cart_item['quantity'] ?? null );
		if ( ! $product instanceof \WC_Product || null === $product_id || null === $quantity ) {
			return null;
		}

		try {
			if ( ! $product->exists() ) {
				return null;
			}
		} catch ( \Throwable ) {
			return null;
		}

		$variation = $cart_item['variation'] ?? array();
		if ( ! is_array( $variation ) ) {
			return null;
		}
		$safe_variation = array();
		foreach ( $variation as $attribute => $value ) {
			if (
				! is_string( $attribute )
				|| ! is_string( $value )
				|| 1 !== preg_match( '//u', $attribute )
				|| 1 !== preg_match( '//u', $value )
			) {
				return null;
			}
			$safe_variation[ $attribute ] = $value;
		}

		return array(
			'key'        => $cart_key,
			'product'    => $product,
			'product_id' => $product_id,
			'quantity'   => $quantity,
			'variation'  => $safe_variation,
		);
	}

	/**
	 * Normalize one raw Store product ID without coercing arrays or objects.
	 *
	 * @param mixed $candidate Raw product ID candidate.
	 */
	public static function normalize_product_id( mixed $candidate ): int {
		return self::positive_integer( $candidate ) ?? 0;
	}

	/**
	 * Normalize one raw cart quantity for a non-actionable placeholder.
	 *
	 * @param mixed $candidate Raw quantity candidate.
	 */
	public static function normalize_quantity( mixed $candidate ): int {
		return self::positive_integer( $candidate ) ?? 1;
	}

	/**
	 * Apply an authenticated edition-owned lock without expanding authority.
	 *
	 * @param array<string, mixed> $item Cart item presentation.
	 * @return array<string, mixed>
	 */
	public static function lock_optional_item( array $item ): array {
		$item['quantityEditable'] = false;
		$item['removable']        = false;
		return $item;
	}

	/**
	 * Strictly copy the authority fields from a completed cart item projection.
	 *
	 * @param mixed $candidate Candidate cart item projection.
	 * @return array{visible: bool, quantityEditable: bool, removable: bool, quantityLimits: array{minimum: int, maximum: int, multipleOf: int, unlimited: bool}, backorderNotice: string}|null
	 */
	public static function sanitize_projection( mixed $candidate ): ?array {
		if (
			! is_array( $candidate )
			|| ! is_bool( $candidate['visible'] ?? null )
			|| ! is_bool( $candidate['quantityEditable'] ?? null )
			|| ! is_bool( $candidate['removable'] ?? null )
			|| ! is_string( $candidate['backorderNotice'] ?? null )
		) {
			return null;
		}

		$limits = $candidate['quantityLimits'] ?? null;
		if (
			! is_array( $limits )
			|| 4 !== count( $limits )
			|| ! array_key_exists( 'minimum', $limits )
			|| ! array_key_exists( 'maximum', $limits )
			|| ! array_key_exists( 'multipleOf', $limits )
			|| ! array_key_exists( 'unlimited', $limits )
			|| ! is_int( $limits['minimum'] )
			|| ! is_int( $limits['maximum'] )
			|| ! is_int( $limits['multipleOf'] )
			|| ! is_bool( $limits['unlimited'] )
			|| $limits['minimum'] < 1
			|| $limits['maximum'] < $limits['minimum']
			|| $limits['maximum'] > self::MAX_SAFE_INTEGER
			|| $limits['multipleOf'] < 1
			|| $limits['multipleOf'] > self::MAX_SAFE_INTEGER
			|| 0 !== $limits['minimum'] % $limits['multipleOf']
			|| 0 !== $limits['maximum'] % $limits['multipleOf']
		) {
			return null;
		}

		$notice = self::plain_notice( $candidate['backorderNotice'] );
		if ( null === $notice || $notice !== $candidate['backorderNotice'] ) {
			return null;
		}

		return array(
			'visible'          => $candidate['visible'],
			'quantityEditable' => $candidate['quantityEditable'],
			'removable'        => $candidate['removable'],
			'quantityLimits'   => array(
				'minimum'    => $limits['minimum'],
				'maximum'    => $limits['maximum'],
				'multipleOf' => $limits['multipleOf'],
				'unlimited'  => $limits['unlimited'],
			),
			'backorderNotice'  => $notice,
		);
	}

	/**
	 * Determine whether a candidate is an exact bounded cart key.
	 *
	 * @param mixed $candidate Candidate cart key.
	 */
	public static function valid_key( mixed $candidate ): bool {
		return is_string( $candidate )
			&& '' !== $candidate
			&& strlen( $candidate ) <= self::MAX_KEY_LENGTH
			&& 1 === preg_match( self::CART_KEY_PATTERN, $candidate );
	}

	/**
	 * Invoke WooCommerce's V1-compatible mini-cart visibility hook.
	 *
	 * Non-scalar and throwing callbacks fail closed so an integration cannot
	 * accidentally expose an item it intended to hide.
	 *
	 * @param array<string, mixed> $cart_item Raw WooCommerce cart item.
	 * @param string               $cart_key  Exact cart item key.
	 */
	private static function is_visible( array $cart_item, string $cart_key ): bool {
		try {
			$candidate = apply_filters( 'woocommerce_widget_cart_item_visible', true, $cart_item, $cart_key );
		} catch ( \Throwable ) {
			return false;
		}

		return is_scalar( $candidate ) || null === $candidate
			? (bool) $candidate
			: false;
	}

	/**
	 * Read the canonical Woo Store API quantity contract.
	 *
	 * @param \WC_Product          $product   WooCommerce product authority.
	 * @param array<string, mixed> $cart_item Raw WooCommerce cart item.
	 * @return array{minimum: int, maximum: int, multipleOf: int, unlimited: bool, editable: bool}|null
	 */
	private static function quantity_limits( \WC_Product $product, array $cart_item ): ?array {
		$quantity_limits_class = '\\Automattic\\WooCommerce\\StoreApi\\Utilities\\QuantityLimits';
		try {
			if ( class_exists( $quantity_limits_class ) ) {
				$provider  = new $quantity_limits_class();
				$candidate = $provider->get_cart_item_quantity_limits( $cart_item );
			} else {
				$candidate = self::fallback_quantity_limits( $product, $cart_item );
			}
		} catch ( \Throwable ) {
			return null;
		}

		if ( ! is_array( $candidate ) || ! is_bool( $candidate['editable'] ?? null ) ) {
			return null;
		}

		$minimum     = self::positive_integer( $candidate['minimum'] ?? null );
		$maximum     = self::positive_integer( $candidate['maximum'] ?? null );
		$multiple    = self::positive_integer( $candidate['multiple_of'] ?? null );
		$product_max = self::integer( self::product_maximum( $product ) );
		if ( null === $minimum || null === $maximum || null === $multiple ) {
			return null;
		}

		$minimum_remainder = $minimum % $multiple;
		if ( 0 !== $minimum_remainder ) {
			$increase = $multiple - $minimum_remainder;
			if ( $minimum > self::MAX_SAFE_INTEGER - $increase ) {
				return null;
			}
			$minimum += $increase;
		}
		$maximum -= $maximum % $multiple;
		if ( $maximum < $minimum ) {
			return null;
		}

		$default_aligned_maximum = self::DEFAULT_STORE_MAXIMUM - ( self::DEFAULT_STORE_MAXIMUM % $multiple );
		$unlimited               = null !== $product_max
			&& $product_max < 1
			&& $maximum >= $default_aligned_maximum;

		return array(
			'minimum'    => $minimum,
			'maximum'    => $maximum,
			'multipleOf' => $multiple,
			'unlimited'  => $unlimited,
			'editable'   => $candidate['editable'],
		);
	}

	/**
	 * Conservative equivalent used only if Woo's supported Store utility is unavailable.
	 *
	 * @param \WC_Product          $product   WooCommerce product authority.
	 * @param array<string, mixed> $cart_item Raw WooCommerce cart item.
	 * @return array<string, mixed>|null
	 */
	private static function fallback_quantity_limits( \WC_Product $product, array $cart_item ): ?array {
		if (
			! method_exists( $product, 'get_min_purchase_quantity' )
			|| ! method_exists( $product, 'get_max_purchase_quantity' )
			|| ! method_exists( $product, 'is_sold_individually' )
		) {
			return null;
		}

		$minimum     = $product->get_min_purchase_quantity();
		$product_max = $product->get_max_purchase_quantity();
		$maximum     = is_numeric( $product_max ) && (float) $product_max < 1
			? self::DEFAULT_STORE_MAXIMUM
			: $product_max;
		$multiple    = method_exists( $product, 'get_purchase_quantity_step' )
			? $product->get_purchase_quantity_step()
			: apply_filters( 'woocommerce_quantity_input_step', 1, $product );
		$editable    = ! (bool) $product->is_sold_individually();

		return array(
			'minimum'     => apply_filters( 'woocommerce_store_api_product_quantity_minimum', $minimum, $product, $cart_item ),
			'maximum'     => apply_filters( 'woocommerce_store_api_product_quantity_maximum', $maximum, $product, $cart_item ),
			'multiple_of' => apply_filters( 'woocommerce_store_api_product_quantity_multiple_of', $multiple, $product, $cart_item ),
			'editable'    => apply_filters( 'woocommerce_store_api_product_quantity_editable', $editable, $product, $cart_item ),
		);
	}

	/**
	 * Return the product's raw maximum without allowing failures to expand authority.
	 *
	 * @param \WC_Product $product WooCommerce product authority.
	 */
	private static function product_maximum( \WC_Product $product ): mixed {
		try {
			return method_exists( $product, 'get_max_purchase_quantity' )
				? $product->get_max_purchase_quantity()
				: null;
		} catch ( \Throwable ) {
			return null;
		}
	}

	/**
	 * Return whether any exact V1 child marker is present, irrespective of its value.
	 *
	 * @param array<string, mixed> $cart_item Raw WooCommerce cart item.
	 */
	private static function has_child_marker( array $cart_item ): bool {
		foreach ( self::CHILD_MARKERS as $marker ) {
			if ( array_key_exists( $marker, $cart_item ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build the default or filtered V1-compatible backorder customer text.
	 *
	 * @param \WC_Product          $product   WooCommerce product authority.
	 * @param array<string, mixed> $cart_item Raw WooCommerce cart item.
	 * @param string               $cart_key  Exact validated cart item key.
	 */
	private static function backorder_notice( \WC_Product $product, array $cart_item, string $cart_key ): string {
		try {
			$quantity = self::positive_integer( $cart_item['quantity'] ?? null );
			if (
				null === $quantity
				|| ! $product->backorders_require_notification()
				|| ! $product->is_on_backorder( $quantity )
			) {
				return '';
			}

			$filtered_product_id = apply_filters(
				'woocommerce_cart_item_product_id',
				$cart_item['product_id'],
				$cart_item,
				$cart_key
			);
			$product_id          = self::filtered_product_id( $filtered_product_id );
			if ( null === $product_id ) {
				return '';
			}
			$default   = '<p class="backorder_notification">'
				. esc_html__( 'Available on backorder', 'cartpops' )
				. '</p>';
			$candidate = apply_filters( 'woocommerce_cart_item_backorder_notification', $default, $product_id );
		} catch ( \Throwable ) {
			return '';
		}

		return is_string( $candidate ) ? ( self::filtered_notice( $candidate ) ?? '' ) : '';
	}

	/**
	 * Normalize a filtered V1 product ID without permissive numeric coercion.
	 *
	 * Canonical positive decimal strings are accepted because WordPress filters
	 * commonly preserve database identifiers as strings.
	 *
	 * @param mixed $candidate Filtered product ID candidate.
	 */
	private static function filtered_product_id( mixed $candidate ): ?int {
		if ( is_string( $candidate ) && 1 === preg_match( '/\A[1-9][0-9]*\z/D', $candidate ) ) {
			$value = (int) $candidate;
			return (string) $value === $candidate ? self::positive_integer( $value ) : null;
		}

		return self::positive_integer( $candidate );
	}

	/**
	 * Convert one safe positive integer-compatible number.
	 *
	 * @param mixed $candidate Candidate number.
	 */
	private static function positive_integer( mixed $candidate ): ?int {
		$value = self::integer( $candidate );
		return null !== $value && $value >= 1 ? $value : null;
	}

	/**
	 * Convert one exact JavaScript-safe integer without accepting numeric strings.
	 *
	 * @param mixed $candidate Candidate number.
	 */
	private static function integer( mixed $candidate ): ?int {
		if ( is_int( $candidate ) ) {
			return $candidate >= -self::MAX_SAFE_INTEGER && $candidate <= self::MAX_SAFE_INTEGER
				? $candidate
				: null;
		}
		if ( ! is_float( $candidate ) || ! is_finite( $candidate ) || floor( $candidate ) !== $candidate ) {
			return null;
		}
		return abs( $candidate ) <= self::MAX_SAFE_INTEGER ? (int) $candidate : null;
	}

	/**
	 * Pin malformed quantity authority to the current integer quantity.
	 *
	 * @param mixed $quantity Current cart quantity candidate.
	 * @return array{minimum: int, maximum: int, multipleOf: int, unlimited: bool, editable: bool}
	 */
	private static function locked_limits( mixed $quantity ): array {
		$current = self::positive_integer( $quantity ) ?? 1;
		return array(
			'minimum'    => $current,
			'maximum'    => $current,
			'multipleOf' => 1,
			'unlimited'  => false,
			'editable'   => false,
		);
	}

	/**
	 * Build a complete fail-closed projection for a malformed raw cart line.
	 *
	 * @param mixed $quantity Raw current quantity candidate.
	 * @return array{visible: bool, quantityEditable: bool, removable: bool, quantityLimits: array{minimum: int, maximum: int, multipleOf: int, unlimited: bool}, backorderNotice: string}
	 */
	private static function locked_authority( mixed $quantity ): array {
		$limits = self::locked_limits( $quantity );
		return array(
			'visible'          => false,
			'quantityEditable' => false,
			'removable'        => false,
			'quantityLimits'   => array(
				'minimum'    => $limits['minimum'],
				'maximum'    => $limits['maximum'],
				'multipleOf' => $limits['multipleOf'],
				'unlimited'  => false,
			),
			'backorderNotice'  => '',
		);
	}

	/**
	 * Canonicalize WooCommerce's legacy filtered HTML before transport validation.
	 *
	 * @param string $candidate Candidate filtered notice.
	 */
	private static function filtered_notice( string $candidate ): ?string {
		if ( strlen( $candidate ) > self::MAX_NOTICE_BYTES || 1 !== preg_match( '//u', $candidate ) ) {
			return null;
		}

		$text = wp_strip_all_tags( $candidate, true );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return self::plain_notice( $text );
	}

	/**
	 * Validate exact inert transport text and reject controls, markup, and encodings.
	 *
	 * @param string $candidate Candidate canonical notice.
	 */
	private static function plain_notice( string $candidate ): ?string {
		if (
			strlen( $candidate ) > self::MAX_NOTICE_BYTES
			|| 1 !== preg_match( '//u', $candidate )
			|| 1 === preg_match( '/[<>]/u', $candidate )
			|| 1 === preg_match( '/&(?:#[xX][0-9A-Fa-f]+|#[0-9]+|[A-Za-z][A-Za-z0-9]+);/u', $candidate )
		) {
			return null;
		}

		$text = $candidate;
		if (
			strlen( $text ) > self::MAX_NOTICE_BYTES
			|| 1 !== preg_match( '//u', $text )
			|| 1 === preg_match( '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', $text )
			|| 1 === preg_match( '/&#(?:[xX][0-9A-Fa-f]+|[0-9]+);/', $text )
		) {
			return null;
		}

		$text = trim( $text );
		return strlen( $text ) <= self::MAX_NOTICE_BYTES ? $text : null;
	}
}
