<?php
/**
 * Shared random-product fallback recommendation strategy.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Recommendations;

/**
 * Returns a bounded random set for use only as an engine fallback.
 */
final class RandomProductStrategy implements RecommendationStrategy {

	private const MAX_PRODUCTS = 8;

	/**
	 * Get random published product IDs excluding the current cart.
	 *
	 * Product visibility, type, purchasability, and stock remain the engine's
	 * authoritative customer-facing eligibility checks.
	 *
	 * @param  int[] $cart_product_ids Product and variation IDs in the cart.
	 * @param  int   $limit            Maximum number of recommendations.
	 * @return int[]
	 */
	public function get_recommendations( array $cart_product_ids, int $limit = 4 ): array {
		$limit = min( self::MAX_PRODUCTS, max( 0, $limit ) );
		if ( 0 === $limit ) {
			return array();
		}

		$excluded = $this->canonical_ids( $cart_product_ids );
		$queried  = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => $limit,
				'exclude' => $excluded,
				'orderby' => 'rand',
				'return'  => 'ids',
			)
		);

		if ( ! is_array( $queried ) ) {
			return array();
		}

		$recommendations = array();
		$excluded_lookup = array_fill_keys( $excluded, true );
		foreach ( $queried as $candidate ) {
			$product_id = $this->positive_product_id( $candidate );
			if ( null === $product_id || isset( $excluded_lookup[ $product_id ] ) || isset( $recommendations[ $product_id ] ) ) {
				continue;
			}
			$recommendations[ $product_id ] = $product_id;
			if ( count( $recommendations ) >= $limit ) {
				break;
			}
		}

		return array_values( $recommendations );
	}

	/** Get the stable strategy identifier. */
	public function get_type(): string {
		return 'random';
	}

	/**
	 * Canonicalize and deduplicate product IDs.
	 *
	 * @param mixed[] $values Candidate IDs.
	 * @return int[]
	 */
	private function canonical_ids( array $values ): array {
		$ids = array();
		foreach ( $values as $value ) {
			$product_id = $this->positive_product_id( $value );
			if ( null !== $product_id ) {
				$ids[ $product_id ] = $product_id;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Parse a positive canonical product ID.
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
