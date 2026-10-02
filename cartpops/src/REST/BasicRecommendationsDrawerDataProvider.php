<?php
/**
 * Shared/free WooCommerce recommendation drawer data.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\REST;

use CartPops\Recommendations\RecommendationEngine;

/** Expose the basic cross-sell/upsell recommendation capability in both editions. */
final class BasicRecommendationsDrawerDataProvider implements DrawerDataProvider {
	/**
	 * Construct the shared provider.
	 *
	 * @param RecommendationEngine $recommendations Shared recommendation service.
	 */
	public function __construct( private readonly RecommendationEngine $recommendations ) {}

	/**
	 * Return exact shared fields.
	 *
	 * @return string[] Exact shared fields.
	 */
	public function fields(): array {
		return array( 'recommendations' );
	}

	/**
	 * Return basic recommendations when requested.
	 *
	 * @param string[] $requested_fields Requested owned fields.
	 * @return array<string, mixed>|\WP_REST_Response
	 */
	public function provide( array $requested_fields ): array|\WP_REST_Response {
		return in_array( 'recommendations', $requested_fields, true )
			? array( 'recommendations' => $this->recommendations->get_recommendations() )
			: array();
	}
}
