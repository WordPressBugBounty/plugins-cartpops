<?php
/**
 * Builds cart state in the display format used by wp_interactivity_state().
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Cart;

use CartPops\Blocks\CartDrawer\CartItemImage;

/**
 * Builds cart state in the display format used by wp_interactivity_state().
 *
 * Shared between render.php (server-side hydration) and the
 * woocommerce_add_to_cart_fragments filter (AJAX add-to-cart).
 */
final class CartStateBuilder {
	/** Maximum selected shipping methods accepted from one calculated cart. */
	private const MAX_SELECTED_SHIPPING_METHODS = 20;
	/** Maximum current rates accepted for one calculated shipping package. */
	private const MAX_SHIPPING_RATES_PER_PACKAGE = 50;
	/** Maximum bytes accepted for one exact WooCommerce shipping rate ID. */
	private const MAX_SHIPPING_RATE_ID_BYTES = 256;
	/** Largest minor-unit amount the drawer script can represent exactly. */
	private const MAX_SAFE_MINOR = 9007199254740991;

	/**
	 * Build cart items, count, total, subtotal, fees, and tax
	 * from the current WooCommerce cart session.
	 *
	 * @param CartItemAuthoritativePresentationProjector|null $authoritative_projector Optional explicit edition projector.
	 * @return array{cartItems: array, cartCount: int, cartTotal: string, cartSubtotal: string, cartFees: array, cartShipping?: string, cartTax: string}
	 */
	public static function build( ?CartItemAuthoritativePresentationProjector $authoritative_projector = null ): array {
		$cart = WC()->cart;

		if ( ! $cart ) {
			return array(
				'cartItems'    => array(),
				'cartCount'    => 0,
				'cartTotal'    => '',
				'cartSubtotal' => '',
				'cartFees'     => array(),
				'cartTax'      => '',
			);
		}

		$tax_display_cart          = get_option( 'woocommerce_tax_display_cart', 'excl' );
		$currency_state            = CurrencyMetadata::for_cart( $cart );
		$currency_metadata         = $currency_state['metadata'];
		$decimals                  = $currency_metadata['currency_minor_unit'];
		$authoritative_projector ??= self::resolve_authoritative_projector();

		// Batch-load variation term names to avoid N+1 get_term_by() queries.
		$term_slugs_by_tax = array();
		foreach ( $cart->get_cart() as $cart_key => $cart_item ) {
			$validated_line = is_array( $cart_item )
				? CartLineAuthority::validate_raw_line( $cart_item, $cart_key )
				: null;
			if ( null === $validated_line || array() === $validated_line['variation'] ) {
				continue;
			}
			foreach ( $validated_line['variation'] as $attr_key => $attr_value ) {
				if ( '' === $attr_value ) {
					continue;
				}
				$taxonomy                         = str_replace( 'attribute_', '', $attr_key );
				$term_slugs_by_tax[ $taxonomy ][] = $attr_value;
			}
		}
		$term_name_map = array();
		foreach ( $term_slugs_by_tax as $taxonomy => $slugs ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'slug'       => array_unique( $slugs ),
					'hide_empty' => false,
				)
			);
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$term_name_map[ $taxonomy ][ $term->slug ] = $term->name;
				}
			}
		}

		$cart_items      = array();
		$placeholder_url = wc_placeholder_img_src( 'woocommerce_thumbnail' );
		foreach ( $cart->get_cart() as $cart_key => $cart_item ) {
			$cart_item      = is_array( $cart_item ) ? $cart_item : array();
			$raw_cart_key   = $cart_key;
			$cart_key       = is_string( $cart_key ) ? $cart_key : '';
			$validated_line = CartLineAuthority::validate_raw_line( $cart_item, $raw_cart_key );
			$line_authority = CartLineAuthority::for_cart_item( $cart_item, $raw_cart_key );
			if ( null === $validated_line ) {
				$malformed_item = array_merge(
					self::malformed_item_data( $cart_item, $cart_key, $currency_metadata ),
					$line_authority
				);
				$cart_items[]   = CartItemPresentationExtension::for_json_transport(
					CartItemImage::materialize( $malformed_item, $placeholder_url )
				);
				continue;
			}
			$product    = $validated_line['product'];
			$product_id = $validated_line['product_id'];
			$quantity   = $validated_line['quantity'];
			$variation  = $validated_line['variation'];

			$image         = wp_get_attachment_image_url( (int) $product->get_image_id(), 'woocommerce_thumbnail' );
			$image_url     = CartItemImage::normalize_url( $image ? $image : $placeholder_url ) ?? '';
			$permalink_url = CartItemImage::normalize_url( $product->get_permalink() ) ?? '';

			// Build variation summary (e.g. "Color: Blue, Logo: Yes").
			$variation_parts = array();
			if ( array() !== $variation ) {
				foreach ( $variation as $attr_key => $attr_value ) {
					if ( '' === $attr_value ) {
						continue;
					}
					$taxonomy          = str_replace( 'attribute_', '', $attr_key );
					$label             = wc_attribute_label( $taxonomy, $product );
					$name              = $term_name_map[ $taxonomy ][ $attr_value ] ?? ucfirst( $attr_value );
					$variation_parts[] = $label . ': ' . $name;
				}
			}

			// Use tax-adjusted price matching woocommerce_tax_display_cart setting.
			$display_price_raw = self::valid_price( $product->get_price() );
			$display_price     = null;
			if ( null !== $display_price_raw ) {
				$display_price = self::valid_price(
					'incl' === $tax_display_cart
						? wc_get_price_including_tax( $product, array( 'price' => $display_price_raw ) )
						: wc_get_price_excluding_tax( $product, array( 'price' => $display_price_raw ) )
				);
			}

			// Determine the regular (pre-discount) price for sale display.
			$regular_price_raw = self::valid_price( $product->get_regular_price() );
			$regular_price     = null;
			if ( null !== $regular_price_raw ) {
				$regular_price = self::valid_price(
					'incl' === $tax_display_cart
						? wc_get_price_including_tax( $product, array( 'price' => $regular_price_raw ) )
						: wc_get_price_excluding_tax( $product, array( 'price' => $regular_price_raw ) )
				);
			}
			$display_price_minor = CurrencyMetadata::minor_units( $display_price, $decimals );
			$regular_price_minor = CurrencyMetadata::minor_units( $regular_price, $decimals );
			$is_on_sale          = null !== $display_price_minor
				&& null !== $regular_price_minor
				&& $regular_price_minor > $display_price_minor;
			$formatted_price     = null === $display_price_minor ? '' : html_entity_decode( wp_strip_all_tags( wc_price( $display_price ) ), ENT_QUOTES, 'UTF-8' );
			$line_price          = self::line_price_presentation(
				$cart_item,
				$quantity,
				$display_price_minor,
				$is_on_sale ? $regular_price_minor : null,
				$formatted_price,
				$decimals,
				(string) $tax_display_cart
			);

			$item_data = array_merge(
				array(
					'key'                   => $cart_key,
					'id'                    => $product->get_id(),
					'product_id'            => $product_id,
					'name'                  => $product->get_name(),
					'permalink'             => $permalink_url,
					'quantity'              => $quantity,
					'short_description'     => wp_strip_all_tags( $product->get_short_description() ),
					'variationSummary'      => implode( ', ', $variation_parts ),
					'isOnSale'              => $is_on_sale,
					'images'                => array( array( 'src' => $image_url ) ),
					'prices'                => array_merge(
						array(
							'price'         => null === $display_price_minor ? '' : $display_price_minor,
							'regular_price' => null === $regular_price_minor ? '' : $regular_price_minor,
						),
						$currency_metadata
					),
					'formattedPrice'        => $formatted_price,
					'formattedRegularPrice' => $is_on_sale ? html_entity_decode( wp_strip_all_tags( wc_price( $regular_price ) ), ENT_QUOTES, 'UTF-8' ) : '',
					'extraLines'            => self::woocommerce_item_data( $cart_item ),
					'customPresentation'    => array(),
					'optionalLocked'        => false,
				),
				$line_price,
				$line_authority
			);

			$cart_items[] = self::finalize_item(
				$item_data,
				$cart_item,
				$cart_key,
				$authoritative_projector,
				$placeholder_url
			);
		}

		$cart_fees = array_values(
			array_map(
				static function ( $fee ) use ( $tax_display_cart ) {
					$fee_total = 'incl' === $tax_display_cart ? $fee->amount + $fee->tax : $fee->amount;
					return array(
						'name'  => $fee->name,
						'total' => html_entity_decode( wp_strip_all_tags( wc_price( $fee_total ) ), ENT_QUOTES, 'UTF-8' ),
					);
				},
				$cart->get_fees()
			)
		);

		// Normalise WooCommerce's formatted price string: decode entities, strip
		// tags, and convert Dutch "151,-" zero-cent notation to "151,00" so the
		// drawer shows a consistent format without re-implementing WooCommerce's
		// own tax-display logic (which differs between incl/excl and
		// wc_prices_include_tax configurations).
		$fmt = static function ( string $raw ): string {
			$s = html_entity_decode( wp_strip_all_tags( $raw ), ENT_QUOTES, 'UTF-8' );
			return preg_replace( '/,-$/', ',00', $s ) ?? $s;
		};

		// Use get_total('edit') to bypass the woocommerce_cart_total filter
		// which some setups strip to the subtotal. The 'edit' context returns
		// the raw numeric total that includes items + fees + shipping + tax.
		$cart_total_raw = (float) $cart->get_total( 'edit' );

		$shipping_display = self::shipping_display( $cart, $tax_display_cart, $fmt );

		return array(
			'cartItems'         => $cart_items,
			'cartCount'         => $cart->get_cart_contents_count(),
			'cartTotal'         => $fmt( wc_price( $cart_total_raw ) ),
			'cartSubtotal'      => $fmt( $cart->get_cart_subtotal() ),
			'cartFees'          => $cart_fees,
			'cartShipping'      => $shipping_display,
			'cartTax'           => wc_tax_enabled()
				? $fmt( wc_price( $cart->get_total_tax() ) )
				: '',
			'taxDisplayCart'    => $tax_display_cart,
			'rawSubtotal'       => $currency_state['raw_subtotal'],
			'currencyMinorUnit' => $decimals,
			'rawTotals'         => $currency_state['raw_totals'],
		);
	}

	/**
	 * Present shipping only after WooCommerce proves one bounded selection.
	 *
	 * A numeric zero is ambiguous until shipping has been calculated and a
	 * method selected. Once confirmed, WooCommerce owns the localized free
	 * presentation; positive shipping retains CartPops' existing tax display.
	 *
	 * @param object   $cart             Current WooCommerce cart.
	 * @param string   $tax_display_cart WooCommerce cart tax display mode.
	 * @param callable $format           Existing CartPops price normalizer.
	 */
	private static function shipping_display( object $cart, string $tax_display_cart, callable $format ): string {
		if ( ! self::has_confirmed_shipping_selection( $cart ) ) {
			return '';
		}

		try {
			$shipping_total = self::valid_price( $cart->get_shipping_total() );
			$shipping_tax   = self::valid_price( $cart->get_shipping_tax() );
		} catch ( \Throwable ) {
			return '';
		}
		if ( null === $shipping_total || null === $shipping_tax ) {
			return '';
		}

		$shipping_amount = 'incl' === $tax_display_cart
			? $shipping_total + $shipping_tax
			: $shipping_total;
		if ( ! is_finite( $shipping_amount ) ) {
			return '';
		}
		if ( $shipping_amount > 0 ) {
			return $format( wc_price( $shipping_amount ) );
		}

		try {
			$free_shipping = $cart->get_cart_shipping_total();
		} catch ( \Throwable ) {
			return '';
		}
		return is_string( $free_shipping ) ? $format( $free_shipping ) : '';
	}

	/**
	 * Require one current selected rate for every calculated Woo package.
	 *
	 * WooCommerce 9.0 exposes calculated packages only through WC_Shipping and
	 * selected IDs through the session. Reading both is non-mutating; unlike
	 * wc_get_chosen_shipping_method_for_package(), this path never manufactures
	 * a default selection or invokes shipping calculation.
	 *
	 * @param object $cart Current WooCommerce cart.
	 */
	private static function has_confirmed_shipping_selection( object $cart ): bool {
		if (
			! is_callable( array( $cart, 'needs_shipping' ) )
			|| ! is_callable( array( $cart, 'show_shipping' ) )
			|| ! is_callable( array( $cart, 'get_cart_shipping_total' ) )
		) {
			return false;
		}

		try {
			if ( true !== $cart->needs_shipping() || true !== $cart->show_shipping() ) {
				return false;
			}

			$woocommerce = WC();
			$session     = is_object( $woocommerce ) ? ( $woocommerce->session ?? null ) : null;
			if (
				! is_object( $woocommerce )
				|| ! is_callable( array( $woocommerce, 'shipping' ) )
				|| ! is_object( $session )
				|| ! is_callable( array( $session, 'get' ) )
			) {
				return false;
			}

			$shipping = $woocommerce->shipping();
			if ( ! is_object( $shipping ) || ! is_callable( array( $shipping, 'get_packages' ) ) ) {
				return false;
			}

			$packages = $shipping->get_packages();
			$chosen   = $session->get( 'chosen_shipping_methods', array() );
		} catch ( \Throwable ) {
			return false;
		}

		if (
			! is_array( $packages )
			|| array() === $packages
			|| count( $packages ) > self::MAX_SELECTED_SHIPPING_METHODS
			|| ! is_array( $chosen )
			|| count( $chosen ) !== count( $packages )
		) {
			return false;
		}

		foreach ( $packages as $package_key => $package ) {
			if (
				! is_array( $package )
				|| ! is_array( $package['rates'] ?? null )
				|| array() === $package['rates']
				|| count( $package['rates'] ) > self::MAX_SHIPPING_RATES_PER_PACKAGE
				|| ! array_key_exists( $package_key, $chosen )
			) {
				return false;
			}

			$chosen_id = $chosen[ $package_key ];
			if (
				! is_string( $chosen_id )
				|| '' === $chosen_id
				|| strlen( $chosen_id ) > self::MAX_SHIPPING_RATE_ID_BYTES
				|| preg_match( '/[\x00-\x1F\x7F]/', $chosen_id )
			) {
				return false;
			}

			foreach ( $package['rates'] as $rate_id => $rate ) {
				if (
					! is_string( $rate_id )
					|| '' === $rate_id
					|| strlen( $rate_id ) > self::MAX_SHIPPING_RATE_ID_BYTES
					|| preg_match( '/[\x00-\x1F\x7F]/', $rate_id )
					|| ! $rate instanceof \WC_Shipping_Rate
				) {
					return false;
				}
			}

			if ( ! array_key_exists( $chosen_id, $package['rates'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Apply the public presentation seam and closed edition lock in authority order.
	 *
	 * @param array<string, mixed>                            $item_data               Server-owned item presentation.
	 * @param array<string, mixed>                            $cart_item               Raw WooCommerce cart item.
	 * @param string                                          $cart_key                Exact cart item key.
	 * @param CartItemAuthoritativePresentationProjector|null $authoritative_projector Closed edition projector.
	 * @param string                                          $placeholder_url         Safe WooCommerce placeholder URL.
	 * @return array<string, mixed>
	 */
	private static function finalize_item(
		array $item_data,
		array $cart_item,
		string $cart_key,
		?CartItemAuthoritativePresentationProjector $authoritative_projector,
		string $placeholder_url
	): array {
		/**
		 * Filter cart item data before it enters the CartPops drawer state.
		 *
		 * @param array  $item_data CartPops item data array.
		 * @param array  $cart_item Raw WooCommerce cart item.
		 * @param string $cart_key  Cart item key.
		 */
		try {
			$filtered_item = apply_filters( 'cartpops_cart_item_data', $item_data, $cart_item, $cart_key );
		} catch ( \Throwable ) {
			$filtered_item = $item_data;
		}
		$projected_item = CartItemPresentationExtension::sanitize_public_result( $item_data, $filtered_item );

		if ( null !== $authoritative_projector ) {
			try {
				$authoritative_item = $authoritative_projector->project( $projected_item, $cart_item, $cart_key );
			} catch ( \Throwable ) {
				$authoritative_item = $projected_item;
			}
			$projected_item = CartItemPresentationExtension::sanitize_projector_result( $projected_item, $authoritative_item );
		}
		if ( true === ( $projected_item['optionalLocked'] ?? false ) ) {
			$projected_item = CartLineAuthority::lock_optional_item( $projected_item );
		}

		return CartItemPresentationExtension::for_json_transport(
			CartItemImage::materialize( $projected_item, $placeholder_url )
		);
	}

	/**
	 * Preserve a malformed raw line in authority state without making it actionable.
	 *
	 * @param array<string, mixed> $cart_item          Raw WooCommerce cart item.
	 * @param string               $cart_key           Exact or fail-closed empty key.
	 * @param array<string, mixed> $currency_metadata  Cart currency metadata.
	 * @return array<string, mixed>
	 */
	private static function malformed_item_data( array $cart_item, string $cart_key, array $currency_metadata ): array {
		$product_id = CartLineAuthority::normalize_product_id( $cart_item['product_id'] ?? null );
		$quantity   = CartLineAuthority::normalize_quantity( $cart_item['quantity'] ?? null );

		return array(
			'key'                       => $cart_key,
			'id'                        => $product_id,
			'product_id'                => $product_id,
			'name'                      => '',
			'permalink'                 => '',
			'quantity'                  => $quantity,
			'short_description'         => '',
			'variationSummary'          => '',
			'isOnSale'                  => false,
			'images'                    => array(),
			'prices'                    => array_merge(
				array(
					'price'         => '',
					'regular_price' => '',
				),
				$currency_metadata
			),
			'formattedPrice'            => '',
			'formattedRegularPrice'     => '',
			'formattedLineTotal'        => '',
			'formattedRegularLineTotal' => '',
			'unitPriceEach'             => '',
			'extraLines'                => array(),
			'customPresentation'        => array(),
			'optionalLocked'            => false,
		);
	}

	/**
	 * Present a line like WooCommerce's cart page: the line subtotal before
	 * coupons in the cart tax display, with the unit price as secondary text
	 * once the quantity exceeds one. The drawer's `line-price.js` mirrors this
	 * arithmetic for Store API updates so both renders agree.
	 *
	 * @param array<string, mixed> $cart_item        Raw WooCommerce cart item.
	 * @param int                  $quantity         Validated line quantity.
	 * @param string|null          $unit_minor       Displayed unit price in minor units.
	 * @param string|null          $regular_minor    Regular unit price in minor units when on sale.
	 * @param string               $formatted_unit   Formatted displayed unit price.
	 * @param int                  $decimals         Active currency precision.
	 * @param string               $tax_display_cart WooCommerce cart tax display mode.
	 * @return array{formattedLineTotal: string, formattedRegularLineTotal: string, unitPriceEach: string}
	 */
	private static function line_price_presentation(
		array $cart_item,
		int $quantity,
		?string $unit_minor,
		?string $regular_minor,
		string $formatted_unit,
		int $decimals,
		string $tax_display_cart
	): array {
		$line_minor = self::line_subtotal_minor( $cart_item, $decimals, $tax_display_cart )
			?? self::line_minor( $unit_minor, $quantity );
		if ( null === $line_minor ) {
			return array(
				'formattedLineTotal'        => $formatted_unit,
				'formattedRegularLineTotal' => '',
				'unitPriceEach'             => '',
			);
		}

		$regular_line_minor = self::line_minor( $regular_minor, $quantity );
		return array(
			'formattedLineTotal'        => self::format_minor( $line_minor, $decimals ),
			'formattedRegularLineTotal' => null !== $regular_line_minor && $regular_line_minor > $line_minor
				? self::format_minor( $regular_line_minor, $decimals )
				: '',
			'unitPriceEach'             => $quantity > 1 && '' !== $formatted_unit
				/* translators: %s: price of a single unit, shown under a cart line's total. */
				? sprintf( __( '%s each', 'cartpops' ), $formatted_unit )
				: '',
		);
	}

	/**
	 * Read WooCommerce's calculated line subtotal, rounded per amount exactly
	 * as the Store API reports `line_subtotal` and `line_subtotal_tax`.
	 *
	 * @param array<string, mixed> $cart_item        Raw WooCommerce cart item.
	 * @param int                  $decimals         Active currency precision.
	 * @param string               $tax_display_cart WooCommerce cart tax display mode.
	 */
	private static function line_subtotal_minor( array $cart_item, int $decimals, string $tax_display_cart ): ?int {
		$subtotal = self::safe_minor( CurrencyMetadata::minor_units( $cart_item['line_subtotal'] ?? null, $decimals ) );
		if ( null === $subtotal || 'incl' !== $tax_display_cart ) {
			return $subtotal;
		}

		$tax = self::safe_minor( CurrencyMetadata::minor_units( $cart_item['line_subtotal_tax'] ?? null, $decimals ) );
		return null === $tax || $subtotal > self::MAX_SAFE_MINOR - $tax ? null : $subtotal + $tax;
	}

	/**
	 * Multiply a unit amount by the quantity within the browser-safe range.
	 *
	 * @param string|null $unit_minor Unit amount in minor units.
	 * @param int         $quantity   Line quantity.
	 */
	private static function line_minor( ?string $unit_minor, int $quantity ): ?int {
		$unit = self::safe_minor( $unit_minor );
		if ( null === $unit || $quantity < 1 || ( $unit > 0 && $quantity > intdiv( self::MAX_SAFE_MINOR, $unit ) ) ) {
			return null;
		}

		return $unit * $quantity;
	}

	/**
	 * Convert a canonical minor-unit string within the browser-safe range.
	 *
	 * @param string|null $minor Canonical minor-unit digits.
	 */
	private static function safe_minor( ?string $minor ): ?int {
		if ( null === $minor || 1 !== preg_match( '/^\d{1,16}$/D', $minor ) || (int) $minor > self::MAX_SAFE_MINOR ) {
			return null;
		}

		return (int) $minor;
	}

	/**
	 * Format minor units with WooCommerce's active price format.
	 *
	 * @param int $minor    Amount in minor units.
	 * @param int $decimals Active currency precision.
	 */
	private static function format_minor( int $minor, int $decimals ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $minor / ( 10 ** $decimals ) ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Return a finite non-negative price without coercing malformed input.
	 *
	 * @param mixed $price Candidate WooCommerce price.
	 */
	private static function valid_price( mixed $price ): ?float {
		if ( ! is_numeric( $price ) ) {
			return null;
		}

		$amount = (float) $price;
		return is_finite( $amount ) && $amount >= 0 ? $amount : null;
	}

	/**
	 * Read extension metadata through WooCommerce's standard compatibility hook.
	 *
	 * An empty seed mirrors the WooCommerce Store API and prevents CartPops'
	 * native variation summary from being repeated as extra metadata.
	 *
	 * @param array<string, mixed> $cart_item Raw WooCommerce cart item.
	 * @return list<array{label: string, value: string}>
	 */
	private static function woocommerce_item_data( array $cart_item ): array {
		try {
			$candidate = apply_filters( 'woocommerce_get_item_data', array(), $cart_item );
			return CartItemPresentationExtension::sanitize_woocommerce_item_data( $candidate );
		} catch ( \Throwable ) {
			return array();
		}
	}

	/** Resolve the already-booted edition collaborator without creating plugin state. */
	private static function resolve_authoritative_projector(): ?CartItemAuthoritativePresentationProjector {
		if ( ! class_exists( \CartPops\Plugin::class, false ) ) {
			return null;
		}

		$plugin = \CartPops\Plugin::current_instance();
		if ( null === $plugin ) {
			return null;
		}

		try {
			$container = $plugin->container();
			if ( ! $container->has( CartItemAuthoritativePresentationProjector::class ) ) {
				return null;
			}

			$projector = $container->get( CartItemAuthoritativePresentationProjector::class );
			return $projector instanceof CartItemAuthoritativePresentationProjector ? $projector : null;
		} catch ( \Throwable ) {
			return null;
		}
	}
}
