<?php
/**
 * Recommends cross-sell products defined on cart items.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Recommendations;

/**
 * Recommends cross-sell products defined on cart items.
 */
final class CrossSellStrategy implements RecommendationStrategy {

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

		$cross_sell_ids = array();

		foreach ( $products as $product ) {
			$cross_sells = $product->get_cross_sell_ids();
			foreach ( $cross_sells as $cross_sell_id ) {
				if ( ! in_array( $cross_sell_id, $cart_product_ids, true ) ) {
					$cross_sell_ids[ $cross_sell_id ] = ( $cross_sell_ids[ $cross_sell_id ] ?? 0 ) + 1;
				}
			}
		}

		arsort( $cross_sell_ids );

		return array_slice( array_keys( $cross_sell_ids ), 0, $limit );
	}

	/**
	 * Strategy identifier.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'cross_sell';
	}
}
