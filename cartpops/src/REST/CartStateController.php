<?php
/**
 * Lightweight cart state endpoint.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\REST;

use CartPops\Cart\CartItemAuthoritativePresentationProjector;
use CartPops\Cart\CartItemPresentationExtension;
use CartPops\Cart\CartLineAuthority;
use CartPops\Cart\CartStateBuilder;
use CartPops\Cart\CartMutationTransaction;
use CartPops\Cart\CartCommitOutcomeUnknownException;
use CartPops\Cart\CartSessionConflictException;
use CartPops\Cart\CartSessionTransactionMode;
use CartPops\Cart\CartTotalsObserver;
use CartPops\Cart\CouponSerializer;

/**
 * Lightweight cart state endpoint.
 *
 * Returns the same data CartStateBuilder provides — with an explicit
 * calculate_totals() call so CartPops fees (e.g. SmartAddons Shipping
 * Protection) are always reflected in the totals.
 *
 * Used by view.js fetchCart() instead of the WC Store API GET /cart,
 * which reads session-stored totals and may exclude CartPops fees when
 * the session was last saved before an add-on was activated.
 */
final class CartStateController {
	private const MAX_REMOVAL_KEYS = 100;

	/**
	 * Shared transactional observation boundary.
	 *
	 * @var CartTotalsObserver
	 */
	private readonly CartTotalsObserver $cart_totals_observer;

	/**
	 * Closed edition projector that may only narrow shared cart-line authority.
	 *
	 * @var CartItemAuthoritativePresentationProjector|null
	 */
	private readonly ?CartItemAuthoritativePresentationProjector $authoritative_projector;

	/**
	 * Create the controller with an injectable observer and a safe legacy default.
	 *
	 * @param CartTotalsObserver|null                         $cart_totals_observer  Shared totals observer.
	 * @param CartItemAuthoritativePresentationProjector|null $authoritative_projector Closed edition projector.
	 */
	public function __construct(
		?CartTotalsObserver $cart_totals_observer = null,
		?CartItemAuthoritativePresentationProjector $authoritative_projector = null
	) {
		$this->cart_totals_observer    = $cart_totals_observer ?? new CartTotalsObserver();
		$this->authoritative_projector = $authoritative_projector;
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'cartpops/v1',
			'/cart',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_cart' ),
				'permission_callback' => array( PublicEndpointGuard::class, 'verify_session' ),
			)
		);

		register_rest_route(
			'cartpops/v1',
			'/cart/remove-items',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'remove_items' ),
				'permission_callback' => array( PublicEndpointGuard::class, 'verify_nonce' ),
				'args'                => array(
					'keys' => array(
						'required'          => true,
						'type'              => 'array',
						'minItems'          => 1,
						'maxItems'          => self::MAX_REMOVAL_KEYS,
						'uniqueItems'       => true,
						'items'             => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 128,
							'pattern'   => '^[A-Za-z0-9._:-]+$',
						),
						'validate_callback' => static fn( mixed $keys ): bool => null !== self::validate_removal_keys( $keys ),
					),
				),
			)
		);
	}

	/**
	 * Remove multiple items in one request (1 session lock, 1 calculate_totals).
	 *
	 * Used by the debounced batch removal in view.js — turns N serial
	 * round-trips into a single request for dramatically faster UX.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function remove_items( \WP_REST_Request $request ): \WP_REST_Response {
		wc_load_cart();

		if ( ! WC()->cart ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'Cart is unavailable. Please try again.', 'cartpops' ) ),
				503
			);
		}

		$cart = WC()->cart;
		$keys = self::validate_removal_keys( $request->get_param( 'keys' ) );
		if ( null === $keys ) {
			return new \WP_REST_Response(
				array(
					'code'    => 'invalid_cart_item_keys',
					'message' => __( 'Select one or more valid cart items.', 'cartpops' ),
				),
				400
			);
		}
		$authoritative_projector = $this->authoritative_projector;

		try {
			$data = CartMutationTransaction::execute(
				$cart,
				static function () use ( $cart, $keys, $authoritative_projector ): void {
					$cart_items = $cart->get_cart();
					foreach ( $keys as $key ) {
						$cart_item = self::exact_cart_item( $cart_items, $key );
						if ( null === $cart_item || ! self::line_allows_removal( $cart_item, $key, $authoritative_projector ) ) {
							throw new \DomainException( 'removal_not_authorized' );
						}
					}

					foreach ( $keys as $key ) {
						if ( ! $cart->remove_cart_item( $key ) ) {
							throw new \DomainException( 'remove_failed' );
						}
					}
				},
				function (): array {
					$data            = CartStateBuilder::build( $this->authoritative_projector );
					$data['coupons'] = CouponSerializer::serialize( WC()->cart );
					return $data;
				}
			);
		} catch ( CartCommitOutcomeUnknownException ) {
			return $this->unknown_commit_response();
		} catch ( CartSessionConflictException ) {
			return $this->busy_response();
		} catch ( \DomainException ) {
			return new \WP_REST_Response(
				array(
					'code'    => 'cart_items_not_removed',
					'message' => __( 'One or more cart items could not be removed.', 'cartpops' ),
				),
				409
			);
		} catch ( \Throwable ) {
			return new \WP_REST_Response(
				array(
					'code'    => 'cart_change_failed',
					'message' => __( 'The cart could not be updated. Please try again.', 'cartpops' ),
				),
				500
			);
		}

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * Return full cart state with CartPops fees included in the totals.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function get_cart( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $request is required by the WP REST route callback signature.
		// REST requests don't load the cart by default.
		wc_load_cart();

		if ( ! WC()->cart ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'Cart is unavailable. Please try again.', 'cartpops' ) ),
				503
			);
		}

		// This public GET is observational. The narrow calculation boundary
		// suppresses only Woo's per-calculation cart-session callback and restores
		// it immediately; normal dirty-tracked shutdown persistence stays intact.
		try {
			$this->cart_totals_observer->observe( WC()->cart, CartSessionTransactionMode::STRICT );
		} catch ( CartCommitOutcomeUnknownException ) {
			return $this->unknown_commit_response();
		} catch ( CartSessionConflictException ) {
			return $this->busy_response();
		} catch ( \Throwable ) {
			return new \WP_REST_Response(
				array(
					'code'    => 'cart_read_failed',
					'message' => __( 'The cart could not be refreshed. Please try again.', 'cartpops' ),
				),
				500
			);
		}

		$data            = CartStateBuilder::build( $this->authoritative_projector );
		$data['coupons'] = CouponSerializer::serialize( WC()->cart );

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * Validate one exact bounded list of cart keys without rewriting any value.
	 *
	 * @param mixed $candidate Raw REST parameter.
	 * @return list<string>|null
	 */
	private static function validate_removal_keys( mixed $candidate ): ?array {
		if (
			! is_array( $candidate )
			|| ! array_is_list( $candidate )
			|| array() === $candidate
			|| count( $candidate ) > self::MAX_REMOVAL_KEYS
		) {
			return null;
		}

		$seen = array();
		foreach ( $candidate as $key ) {
			if ( ! CartLineAuthority::valid_key( $key ) || isset( $seen[ $key ] ) ) {
				return null;
			}
			$seen[ $key ] = true;
		}

		return $candidate;
	}

	/**
	 * Read one exact string-keyed row from the live cart without coercion.
	 *
	 * @param array<mixed> $cart_items    Live WooCommerce cart rows.
	 * @param string       $requested_key Exact requested cart key.
	 * @return array<string, mixed>|null
	 */
	private static function exact_cart_item( array $cart_items, string $requested_key ): ?array {
		foreach ( $cart_items as $cart_key => $cart_item ) {
			if ( $cart_key === $requested_key ) {
				return is_array( $cart_item ) ? $cart_item : null;
			}
		}

		return null;
	}

	/**
	 * Recompute shared and edition-owned removal authority for one live row.
	 *
	 * @param array<string, mixed>                            $cart_item               Live WooCommerce cart row.
	 * @param string                                          $cart_key                Exact cart item key.
	 * @param CartItemAuthoritativePresentationProjector|null $authoritative_projector Closed edition projector.
	 */
	private static function line_allows_removal(
		array $cart_item,
		string $cart_key,
		?CartItemAuthoritativePresentationProjector $authoritative_projector
	): bool {
		$authority = CartLineAuthority::sanitize_projection(
			CartLineAuthority::for_cart_item( $cart_item, $cart_key )
		);
		if (
			null === $authority
			|| true !== $authority['visible']
			|| true !== $authority['removable']
		) {
			return false;
		}
		if ( null === $authoritative_projector ) {
			return true;
		}

		$baseline = array_merge( $authority, array( 'optionalLocked' => false ) );
		try {
			$candidate = $authoritative_projector->project( $baseline, $cart_item, $cart_key );
		} catch ( \Throwable ) {
			return false;
		}
		if ( ! is_array( $candidate ) || ! is_bool( $candidate['optionalLocked'] ?? null ) ) {
			return false;
		}

		$projected = CartItemPresentationExtension::sanitize_projector_result( $baseline, $candidate );
		return true === ( $projected['removable'] ?? null )
			&& false === ( $projected['optionalLocked'] ?? null );
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
}
