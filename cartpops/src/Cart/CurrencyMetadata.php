<?php
/**
 * Shared WooCommerce currency and safe minor-unit amounts for cart responses.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Cart;

/**
 * Builds currency metadata and exact client-safe amounts consumed by optimistic
 * cart price formatting.
 */
final class CurrencyMetadata {
	private const MAX_MINOR_UNIT       = 8;
	private const MAX_SAFE_MINOR_UNITS = '9007199254740991';

	/**
	 * Return the current WooCommerce currency formatting configuration.
	 *
	 * @return array{currency_symbol: string, currency_minor_unit: int, currency_prefix: string, currency_suffix: string, currency_decimal_separator: string, currency_thousand_separator: string}
	 */
	public static function current(): array {
		$minor_unit = wc_get_price_decimals();
		if ( $minor_unit < 0 || self::MAX_MINOR_UNIT < $minor_unit ) {
			$minor_unit = 0;
		}

		$symbol   = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		$position = get_option( 'woocommerce_currency_pos' );

		$prefix = match ( $position ) {
			'left'       => $symbol,
			'left_space' => $symbol . ' ',
			default      => '',
		};
		$suffix = match ( $position ) {
			'right'       => $symbol,
			'right_space' => ' ' . $symbol,
			default       => '',
		};

		return array(
			'currency_symbol'             => $symbol,
			'currency_minor_unit'         => $minor_unit,
			'currency_prefix'             => $prefix,
			'currency_suffix'             => $suffix,
			'currency_decimal_separator'  => wc_get_price_decimal_separator(),
			'currency_thousand_separator' => wc_get_price_thousand_separator(),
		);
	}

	/**
	 * Convert one WooCommerce amount to an exact JavaScript-safe minor-unit string.
	 *
	 * WooCommerce formats displayed prices with half-up rounding at the active
	 * currency precision. Formatting the finite float to a plain decimal first
	 * preserves that behavior without ever publishing exponent notation. Values
	 * outside JavaScript's exact integer range fail closed instead of clamping.
	 *
	 * @param mixed $amount     Candidate WooCommerce amount.
	 * @param int   $minor_unit Validated currency precision.
	 */
	public static function minor_units( mixed $amount, int $minor_unit ): ?string {
		if (
			$minor_unit < 0
			|| self::MAX_MINOR_UNIT < $minor_unit
			|| ! is_numeric( $amount )
		) {
			return null;
		}

		$numeric_amount = (float) $amount;
		if ( ! is_finite( $numeric_amount ) || $numeric_amount < 0 ) {
			return null;
		}

		$display_amount = number_format( $numeric_amount, $minor_unit, '.', '' );
		$minor_units    = ltrim( str_replace( '.', '', $display_amount ), '0' );
		$minor_units    = '' === $minor_units ? '0' : $minor_units;
		if ( ! self::unsigned_integer_fits( $minor_units, self::MAX_SAFE_MINOR_UNITS ) ) {
			return null;
		}

		return $minor_units;
	}

	/**
	 * Build one response-ready currency state for the current WooCommerce cart.
	 *
	 * Required optimistic amounts use zero as a non-promotional fallback when an
	 * amount is malformed, negative, non-finite, or cannot be represented exactly
	 * by JavaScript. Customer-facing formatted totals remain owned by wc_price().
	 *
	 * @param \WC_Cart $cart Current WooCommerce cart.
	 * @return array{
	 *     metadata: array{currency_symbol: string, currency_minor_unit: int, currency_prefix: string, currency_suffix: string, currency_decimal_separator: string, currency_thousand_separator: string},
	 *     raw_subtotal: string,
	 *     raw_totals: array{subtotal: int, total: int, currency_symbol: string, currency_minor_unit: int, currency_prefix: string, currency_suffix: string, currency_decimal_separator: string, currency_thousand_separator: string}
	 * }
	 */
	public static function for_cart( \WC_Cart $cart ): array {
		$metadata      = self::current();
		$minor_unit    = $metadata['currency_minor_unit'];
		$subtotal      = self::minor_units( $cart->get_displayed_subtotal(), $minor_unit );
		$total         = self::minor_units( $cart->get_total( 'edit' ), $minor_unit );
		$subtotal_safe = self::safe_integer( $subtotal );
		$total_safe    = self::safe_integer( $total );

		return array(
			'metadata'     => $metadata,
			'raw_subtotal' => null === $subtotal_safe ? '0' : $subtotal,
			'raw_totals'   => array_merge(
				array(
					'subtotal' => $subtotal_safe ?? 0,
					'total'    => $total_safe ?? 0,
				),
				$metadata
			),
		);
	}

	/**
	 * Convert one validated digit string to a platform-safe integer.
	 *
	 * @param string|null $value Validated canonical minor-unit string.
	 */
	private static function safe_integer( ?string $value ): ?int {
		if ( null === $value || ! self::unsigned_integer_fits( $value, (string) PHP_INT_MAX ) ) {
			return null;
		}

		return (int) $value;
	}

	/**
	 * Compare unsigned canonical integer strings without an unsafe numeric cast.
	 *
	 * @param string $value   Candidate canonical unsigned integer.
	 * @param string $maximum Inclusive canonical upper bound.
	 */
	private static function unsigned_integer_fits( string $value, string $maximum ): bool {
		return 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value )
			&& ( strlen( $value ) < strlen( $maximum )
				|| ( strlen( $value ) === strlen( $maximum ) && strcmp( $value, $maximum ) <= 0 ) );
	}
}
