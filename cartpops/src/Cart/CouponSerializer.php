<?php
/**
 * Store-API-compatible coupon response serialization.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Cart;

/**
 * Serializes every CartPops coupon response through one financial boundary.
 */
final class CouponSerializer {
	private const MAX_CURRENCY_MINOR_UNIT = 8;

	/**
	 * Serialize applied coupons without combining item discount and tax.
	 *
	 * WooCommerce's Store API defines total_discount as the merchandise
	 * discount excluding tax, with total_discount_tax reported separately.
	 * The internal reward identifier is deliberately replaced by a safe label
	 * and a non-removable state rather than exposed as a customer coupon code.
	 *
	 * @param \WC_Cart $cart Cart whose applied coupons are serialized.
	 * @return array<int, array{key: string, code: string, label: string, is_system: bool, removable: bool, totals: array<string, int|string>}>
	 */
	public static function serialize( \WC_Cart $cart ): array {
		$raw_decimals = wc_get_price_decimals();
		$precision_ok = $raw_decimals >= 0 && $raw_decimals <= self::MAX_CURRENCY_MINOR_UNIT;
		$decimals     = $precision_ok ? $raw_decimals : 0;
		$symbol       = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		$currency_pos = get_option( 'woocommerce_currency_pos', 'left' );
		$prefix       = in_array( $currency_pos, array( 'left', 'left_space' ), true )
			? $symbol . ( 'left_space' === $currency_pos ? ' ' : '' )
			: '';
		$suffix       = in_array( $currency_pos, array( 'right', 'right_space' ), true )
			? ( 'right_space' === $currency_pos ? ' ' : '' ) . $symbol
			: '';
		$result       = array();

		foreach ( $cart->get_applied_coupons() as $raw_code ) {
			$code      = (string) $raw_code;
			$is_system = SystemRewardCoupon::is_system_coupon( $cart, $code );
			$discount  = $cart->get_coupon_discount_amount( $code, true );
			$tax       = $cart->get_coupon_discount_tax_amount( $code );

			$result[] = array(
				'key'       => $is_system ? 'cart-reward' : 'coupon:' . $code,
				'code'      => $is_system ? '' : $code,
				'label'     => $is_system ? __( 'Cart Reward', 'cartpops' ) : $code,
				'is_system' => $is_system,
				'removable' => ! $is_system,
				'totals'    => array(
					'total_discount'              => self::minor_units( $discount, $decimals, PHP_ROUND_HALF_UP, $precision_ok ),
					'total_discount_tax'          => self::minor_units( $tax, $decimals, PHP_ROUND_HALF_DOWN, $precision_ok ),
					'currency_code'               => get_woocommerce_currency(),
					'currency_symbol'             => $symbol,
					'currency_minor_unit'         => $decimals,
					'currency_decimal_separator'  => wc_get_price_decimal_separator(),
					'currency_thousand_separator' => wc_get_price_thousand_separator(),
					'currency_prefix'             => $prefix,
					'currency_suffix'             => $suffix,
				),
			);
		}

		return $result;
	}

	/**
	 * Convert a Woo amount to Store API minor units at Woo's precision seam.
	 *
	 * Invalid filtered values and unreasonable store precision fail to zero so
	 * response serialization can never emit INF, a negative discount, or an
	 * exponentially large/non-integer amount.
	 *
	 * @param mixed $raw_amount    Woo/extension-provided amount.
	 * @param int   $decimals      Validated currency minor unit.
	 * @param int   $rounding_mode PHP rounding mode used by Store API.
	 * @param bool  $precision_ok  Whether the configured precision is supported.
	 */
	private static function minor_units( mixed $raw_amount, int $decimals, int $rounding_mode, bool $precision_ok ): string {
		if ( ! $precision_ok || ! in_array( $rounding_mode, array( PHP_ROUND_HALF_UP, PHP_ROUND_HALF_DOWN ), true ) ) {
			return '0';
		}
		if ( is_string( $raw_amount ) ) {
			if ( strlen( $raw_amount ) > 64 || 1 !== preg_match( '/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $raw_amount ) ) {
				return '0';
			}
		} elseif ( ! is_int( $raw_amount ) && ! is_float( $raw_amount ) ) {
			return '0';
		}

		$amount = (float) $raw_amount;
		if ( ! is_finite( $amount ) || $amount < 0 ) {
			return '0';
		}

		$precise = function_exists( 'wc_add_number_precision' )
			? wc_add_number_precision( $amount, false )
			: $amount * ( 10 ** $decimals );
		if ( ! is_int( $precise ) && ! is_float( $precise ) ) {
			return '0';
		}
		$precise = (float) $precise;
		if ( ! is_finite( $precise ) || $precise < 0 ) {
			return '0';
		}

		$minor = round( $precise, 0, $rounding_mode );
		return is_finite( $minor ) && $minor >= 0 ? number_format( $minor, 0, '.', '' ) : '0';
	}
}
