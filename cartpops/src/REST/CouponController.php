<?php
/**
 * Lightweight coupon apply/remove endpoint.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\REST;

use CartPops\Cart\CartStateBuilder;
use CartPops\Cart\CartMutationTransaction;
use CartPops\Cart\CartCommitOutcomeUnknownException;
use CartPops\Cart\CartSessionConflictException;
use CartPops\Cart\CouponSerializer;
use CartPops\Cart\SystemRewardCoupon;

/**
 * Lightweight coupon apply/remove endpoint.
 *
 * Bypasses the WC Store API's expensive full-cart serialization
 * (item schema, shipping rates, payment methods) and returns only
 * the data the drawer needs: totals, items, coupons.
 */
final class CouponController {
	/**
	 * Retain the old constructor seam for transaction-focused tests while all
	 * production rate authority now lives in NamespaceRateLimitMiddleware.
	 *
	 * @param (callable(string, int, int): bool)|null $legacy_rate_limit_check Deprecated no-op seam.
	 */
	public function __construct( ?callable $legacy_rate_limit_check = null ) {
		unset( $legacy_rate_limit_check );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'cartpops/v1',
			'/coupon',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'apply_coupon' ),
					'permission_callback' => array( PublicEndpointGuard::class, 'verify_nonce' ),
					'args'                => array(
						'code' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove_coupon' ),
					'permission_callback' => array( PublicEndpointGuard::class, 'verify_nonce' ),
					'args'                => array(
						'code' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Ensure WC cart and session are available.
	 *
	 * REST API requests don't load the cart by default —
	 * wc_load_cart() initialises customer, session, and cart.
	 * The function has an internal guard (did_action check),
	 * so calling it unconditionally is safe.
	 */
	private function ensure_cart(): void {
		wc_load_cart();
	}

	/**
	 * Apply a coupon to the cart.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function apply_coupon( \WP_REST_Request $request ): \WP_REST_Response {
		$code = $request->get_param( 'code' );
		if ( is_string( $code ) && SystemRewardCoupon::is_current_identifier( $code ) ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'Cart rewards are managed automatically.', 'cartpops' ) ),
				400
			);
		}

		$this->ensure_cart();

		if ( ! WC()->cart ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'Cart is unavailable. Please try again.', 'cartpops' ) ),
				503
			);
		}

		$cart = WC()->cart;
		try {
			$data = CartMutationTransaction::execute(
				$cart,
				static function () use ( $cart, $code ): void {
					if ( ! $cart->apply_coupon( $code ) ) {
						throw new \DomainException( 'coupon_rejected' );
					}
				},
				fn(): array => $this->build_response()
			);
		} catch ( CartCommitOutcomeUnknownException ) {
			return $this->unknown_commit_response();
		} catch ( CartSessionConflictException ) {
			return $this->busy_response();
		} catch ( \DomainException ) {
			$notices = wc_get_notices( 'error' );
			wc_clear_notices();
			$msg = ! empty( $notices )
				? wp_strip_all_tags( $notices[0]['notice'] )
				: __( 'Invalid coupon.', 'cartpops' );
			return new \WP_REST_Response( array( 'message' => $msg ), 400 );
		} catch ( \Throwable ) {
			wc_clear_notices();
			return $this->mutation_failure_response();
		}

		wc_clear_notices();

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * Remove a coupon from the cart.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function remove_coupon( \WP_REST_Request $request ): \WP_REST_Response {
		$this->ensure_cart();

		if ( ! WC()->cart ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'Cart is unavailable. Please try again.', 'cartpops' ) ),
				503
			);
		}

		$code = $request->get_param( 'code' );
		$cart = WC()->cart;
		// Preserve rejection of an already-marked reward without loading or changing the cart.
		if ( is_string( $code ) && SystemRewardCoupon::is_system_coupon( $cart, $code ) ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'Cart rewards are managed automatically.', 'cartpops' ) ),
				409
			);
		}

		try {
			$data = CartMutationTransaction::execute(
				$cart,
				static function () use ( $cart, $code ): void {
					// The guarded snapshot loads Woo's lazy cart; recheck any newly loaded reward.
					if ( is_string( $code ) && SystemRewardCoupon::is_system_coupon( $cart, $code ) ) {
						throw new \DomainException( 'managed_coupon' );
					}
					if ( ! is_string( $code ) || ! $cart->has_discount( $code ) ) {
						// Roll back without recalculating totals or persisting a coupon mutation.
						throw new \DomainException( 'coupon_not_applied' );
					}
					if ( ! $cart->remove_coupon( $code ) ) {
						throw new \DomainException( 'coupon_remove_failed' );
					}
				},
				function () use ( $cart, $code ): array {
					if ( $cart->has_discount( $code ) ) {
						throw new \DomainException( 'coupon_reapplied' );
					}
					return $this->build_response();
				}
			);
		} catch ( CartCommitOutcomeUnknownException ) {
			return $this->unknown_commit_response();
		} catch ( CartSessionConflictException ) {
			return $this->busy_response();
		} catch ( \DomainException $error ) {
			wc_clear_notices();
			if ( 'coupon_not_applied' === $error->getMessage() ) {
				return new \WP_REST_Response( $this->build_response(), 200 );
			}
			if ( 'managed_coupon' === $error->getMessage() ) {
				return new \WP_REST_Response(
					array( 'message' => __( 'Cart rewards are managed automatically.', 'cartpops' ) ),
					409
				);
			}
			if ( 'coupon_reapplied' === $error->getMessage() ) {
				return new \WP_REST_Response(
					array(
						'code'    => 'coupon_removal_conflict',
						'message' => __( 'This coupon is managed automatically and could not be removed.', 'cartpops' ),
					),
					409
				);
			}
			return new \WP_REST_Response(
				array( 'message' => __( 'The coupon could not be removed.', 'cartpops' ) ),
				409
			);
		} catch ( \Throwable ) {
			wc_clear_notices();
			return $this->mutation_failure_response();
		}

		wc_clear_notices();

		return new \WP_REST_Response( $data, 200 );
	}

	/** Return one stable retry-safe response for a concurrent cart request. */
	private function busy_response(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'code'    => 'cart_session_busy',
				'message' => __( 'Your cart is being updated. Please try again.', 'cartpops' ),
			),
			409
		);
	}

	/** Return a non-retry instruction when COMMIT may already have succeeded. */
	private function unknown_commit_response(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'code'    => 'cart_commit_outcome_unknown',
				'message' => __( 'The final cart state could not be confirmed. Refresh your cart before making another change.', 'cartpops' ),
			),
			500
		);
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

	/**
	 * Build the lightweight cart response.
	 *
	 * Uses CartStateBuilder for items/totals (pre-formatted strings)
	 * and adds coupon data + raw totals for optimistic pricing.
	 */
	private function build_response(): array {
		$data            = CartStateBuilder::build();
		$data['coupons'] = CouponSerializer::serialize( WC()->cart );
		return $data;
	}
}
