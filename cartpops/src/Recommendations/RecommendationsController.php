<?php
/**
 * REST API controller for product recommendations.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Recommendations;

use CartPops\Cart\CartStateBuilder;
use CartPops\Cart\CartMutationTransaction;
use CartPops\Cart\CartCommitOutcomeUnknownException;
use CartPops\Cart\CartSessionConflictException;
use CartPops\Cart\CouponSerializer;
use CartPops\REST\PublicEndpointGuard;

/**
 * REST API controller for product recommendations.
 */
final class RecommendationsController {

	/**
	 * Constructor.
	 *
	 * @param RecommendationEngine $engine Recommendation engine.
	 */
	public function __construct(
		private readonly RecommendationEngine $engine,
	) {}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'cartpops/v1',
			'/recommendations',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_recommendations' ),
				'permission_callback' => array( PublicEndpointGuard::class, 'verify_session' ),
			)
		);

		register_rest_route(
			'cartpops/v1',
			'/recommendations/add',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'add_recommendation' ),
				'permission_callback' => array( PublicEndpointGuard::class, 'verify_nonce' ),
				'args'                => array(
					'product_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Return recommendations for the current cart session.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function get_recommendations( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $request is required by the WP REST route callback signature.
		$products = $this->engine->get_recommendations();

		return new \WP_REST_Response(
			array( 'products' => $products ),
			200
		);
	}

	/**
	 * Add a recommended product to the cart with source attribution meta.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function add_recommendation( \WP_REST_Request $request ): \WP_REST_Response {
		// Ensure WC cart/session is available — REST requests don't load it by default.
		wc_load_cart();

		if ( ! WC()->cart ) {
			return new \WP_REST_Response(
				array(
					'code'    => 'cart_unavailable',
					'message' => __( 'Cart is unavailable. Please try again.', 'cartpops' ),
				),
				503
			);
		}

		$product_id = $request->get_param( 'product_id' );
		$product    = wc_get_product( $product_id );

		if (
			! $product
			|| 'publish' !== $product->get_status()
			|| ! $product->is_visible()
			|| ! $product->is_type( 'simple' )
			|| ! $product->is_purchasable()
			|| ! $product->is_in_stock()
		) {
			return new \WP_REST_Response(
				array(
					'code'    => 'product_unavailable',
					'message' => __( 'This product is not available.', 'cartpops' ),
				),
				400
			);
		}

		$cart = WC()->cart;
		try {
			$data = CartMutationTransaction::execute(
				$cart,
				static function () use ( $cart, $product_id ): string {
					$cart_item_key = $cart->add_to_cart(
						$product_id,
						1,
						0,
						array(),
						array( '_cartpops_source' => 'recommendation' )
					);
					if ( ! is_string( $cart_item_key ) || '' === $cart_item_key ) {
						throw new \DomainException( 'add_failed' );
					}
					return $cart_item_key;
				},
				function (): array {
					$data            = CartStateBuilder::build();
					$data['coupons'] = CouponSerializer::serialize( WC()->cart );
					$data['success'] = true;
					return $data;
				}
			);
		} catch ( CartCommitOutcomeUnknownException ) {
			return new \WP_REST_Response(
				array(
					'code'    => 'cart_commit_outcome_unknown',
					'message' => __( 'The final cart state could not be confirmed. Refresh your cart before making another change.', 'cartpops' ),
				),
				500
			);
		} catch ( CartSessionConflictException ) {
			return new \WP_REST_Response(
				array(
					'code'    => 'cart_session_busy',
					'message' => __( 'Your cart is being updated. Please try again.', 'cartpops' ),
				),
				409
			);
		} catch ( \DomainException ) {
			return new \WP_REST_Response(
				array(
					'code'    => 'add_failed',
					'message' => __( 'Could not add product to cart.', 'cartpops' ),
				),
				400
			);
		} catch ( \Throwable ) {
			return $this->mutation_failure_response();
		}

		return new \WP_REST_Response( $data, 200 );
	}

	/** Return a stable error after an exact best-effort rollback. */
	private function mutation_failure_response(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'code'    => 'cart_change_failed',
				'message' => __( 'The cart could not be updated. Please try again.', 'cartpops' ),
			),
			500
		);
	}
}
