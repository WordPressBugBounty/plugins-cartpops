<?php
/**
 * Recommends upsell products defined on cart items.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Recommendations;

/**
 * Recommends upsell products defined on cart items.
 */
final class UpsellStrategy implements RecommendationStrategy {

	/**
	 * Get recommended product IDs based on the current cart.
	 *
	 * @param  int[] $cart_product_ids Product IDs currently in cart.
	 * @param  int   $limit            Maximum number of recommendations.
	 * @return int[] Recommended product IDs.
	 */
	public function get_recommendations( array $cart_product_ids, int $limit = 4 ): array {
		if ( empty( $cart_product_ids ) ) {
			return array();
		}

		// Batch-load cart products in one query instead of N individual calls.
		$products = wc_get_products(
			array(
				'include' => $cart_product_ids,
				'limit'   => count( $cart_product_ids ),
				'return'  => 'objects',
			)
		);

		$upsell_ids = array();

		foreach ( $products as $product ) {
			$upsells = $product->get_upsell_ids();
			foreach ( $upsells as $upsell_id ) {
				// Don't recommend items already in cart.
				if ( ! in_array( $upsell_id, $cart_product_ids, true ) ) {
					$upsell_ids[ $upsell_id ] = ( $upsell_ids[ $upsell_id ] ?? 0 ) + 1;
				}
			}
		}

		// Sort by frequency (most common upsells first).
		arsort( $upsell_ids );

		return array_slice( array_keys( $upsell_ids ), 0, $limit );
	}

	/**
	 * Strategy identifier.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'upsell';
	}
}
