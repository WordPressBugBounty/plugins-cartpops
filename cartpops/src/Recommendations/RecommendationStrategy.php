<?php
/**
 * Interface for product recommendation strategies.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Recommendations;

/**
 * Interface for product recommendation strategies.
 */
interface RecommendationStrategy {

	/**
	 * Get recommended product IDs based on the current cart.
	 *
	 * @param  int[] $cart_product_ids Product IDs currently in cart.
	 * @param  int   $limit           Maximum number of recommendations.
	 * @return int[] Recommended product IDs.
	 */
	public function get_recommendations( array $cart_product_ids, int $limit = 4 ): array;

	/**
	 * Strategy identifier.
	 */
	public function get_type(): string;
}
