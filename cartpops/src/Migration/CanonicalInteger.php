<?php
/**
 * Strict integer parsing for durable migration state.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Migration;

/**
 * Rejects PHP's permissive numeric coercions at persistence boundaries.
 */
final class CanonicalInteger {

	/**
	 * Parse a native integer or canonical unsigned decimal string.
	 *
	 * @param mixed    $value Candidate persisted value.
	 * @param int      $minimum Inclusive lower bound.
	 * @param int|null $maximum Inclusive upper bound, or null for PHP_INT_MAX.
	 */
	public static function parse( mixed $value, int $minimum = 0, ?int $maximum = null ): ?int {
		if ( is_int( $value ) ) {
			$parsed = $value;
		} elseif ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			$limit = (string) PHP_INT_MAX;
			if ( strlen( $value ) > strlen( $limit ) || ( strlen( $value ) === strlen( $limit ) && strcmp( $value, $limit ) > 0 ) ) {
				return null;
			}
			$parsed = (int) $value;
			if ( (string) $parsed !== $value ) {
				return null;
			}
		} else {
			return null;
		}

		$upper = $maximum ?? PHP_INT_MAX;
		return $parsed >= $minimum && $parsed <= $upper ? $parsed : null;
	}
}
