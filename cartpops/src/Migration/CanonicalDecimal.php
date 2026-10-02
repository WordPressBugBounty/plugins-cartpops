<?php
/**
 * Strict finite-decimal parsing for migration values.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Migration;

/**
 * Rejects exponent, sign, overflow, and non-finite coercions.
 */
final class CanonicalDecimal {

	/**
	 * Parse a non-ambiguous decimal within inclusive bounds.
	 *
	 * @param mixed      $value   Candidate value.
	 * @param float      $minimum Inclusive lower bound.
	 * @param float|null $maximum Inclusive upper bound, or null for any finite value.
	 */
	public static function parse( mixed $value, float $minimum = 0.0, ?float $maximum = null ): ?float {
		if ( is_int( $value ) || is_float( $value ) ) {
			$parsed = (float) $value;
		} elseif (
			is_string( $value )
			&& strlen( $value ) <= 128
			&& 1 === preg_match( '/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value )
		) {
			$parsed = (float) $value;
		} else {
			return null;
		}

		if ( ! is_finite( $parsed ) || $parsed < $minimum ) {
			return null;
		}

		return null === $maximum || $parsed <= $maximum ? $parsed : null;
	}
}
