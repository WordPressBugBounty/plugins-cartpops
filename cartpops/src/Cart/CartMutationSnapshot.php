<?php
/**
 * Exact rollback state for one WooCommerce cart mutation.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Cart;

/**
 * Captures every cart-owned persistence layer behind one small rollback seam.
 */
final class CartMutationSnapshot {
	/**
	 * Create an immutable snapshot from the captured layers.
	 *
	 * @param \WC_Cart                                               $cart Cart being mutated.
	 * @param array<string, array<string, mixed>>                    $contents Active cart contents.
	 * @param array<string, array<string, mixed>>                    $removed_contents Removed cart contents.
	 * @param array<int, array{product: \WC_Product, price: string}> $prices Mutable product prices.
	 * @param string[]                                               $coupons Applied coupons.
	 * @param array<string, float>                                   $coupon_discounts Coupon discount totals.
	 * @param array<string, float>                                   $coupon_discount_taxes Coupon discount tax totals.
	 * @param array<int, array<string, mixed>>                       $fees Exact fee collection exposed by WC_Cart_Fees.
	 * @param array<string, mixed>                                   $totals Cart totals.
	 * @param WooSessionState|null                                   $session_state Exact live and durable Woo session state.
	 * @param PersistentCartState|null                               $persistent_cart Exact logged-in durable cart state.
	 */
	private function __construct(
		private readonly \WC_Cart $cart,
		private readonly array $contents,
		private readonly array $removed_contents,
		private readonly array $prices,
		private readonly array $coupons,
		private readonly array $coupon_discounts,
		private readonly array $coupon_discount_taxes,
		private readonly array $fees,
		private readonly array $totals,
		private readonly ?WooSessionState $session_state,
		private readonly ?PersistentCartState $persistent_cart,
	) {}

	/**
	 * Capture the exact pre-mutation state.
	 *
	 * @param \WC_Cart $cart Cart being protected.
	 */
	public static function capture( \WC_Cart $cart ): self {
		$contents         = $cart->get_cart();
		$removed_contents = method_exists( $cart, 'get_removed_cart_contents' )
			? $cart->get_removed_cart_contents()
			: array();
		$prices           = array();
		foreach ( array_merge( $contents, $removed_contents ) as $cart_item ) {
			$product = $cart_item['data'] ?? null;
			if ( $product instanceof \WC_Product ) {
				$prices[] = array(
					'product' => $product,
					'price'   => (string) $product->get_price(),
				);
			}
		}

		$woocommerce   = WC();
		$session       = is_object( $woocommerce ) ? ( $woocommerce->session ?? null ) : null;
		$session_state = WooSessionState::capture( is_object( $session ) ? $session : null );
		$fees          = array();
		if ( method_exists( $cart, 'fees_api' ) ) {
			$fees_api = $cart->fees_api();
			if ( is_object( $fees_api ) && method_exists( $fees_api, 'get_fees' ) ) {
				foreach ( $fees_api->get_fees() as $fee ) {
					if ( is_object( $fee ) ) {
						$fees[] = (array) clone $fee;
					}
				}
			}
		}

		$persistent_cart = PersistentCartState::capture_current();

		return new self(
			$cart,
			$contents,
			$removed_contents,
			$prices,
			$cart->get_applied_coupons(),
			method_exists( $cart, 'get_coupon_discount_totals' ) ? $cart->get_coupon_discount_totals() : array(),
			method_exists( $cart, 'get_coupon_discount_tax_totals' ) ? $cart->get_coupon_discount_tax_totals() : array(),
			$fees,
			$cart->get_totals(),
			$session_state,
			$persistent_cart
		);
	}

	/**
	 * Restore in-memory cart state, WC session storage, and logged-in persistent
	 * cart metadata. Cleanup is best-effort and the first failure is rethrown only
	 * after every layer has had a restoration attempt.
	 *
	 * @throws \Throwable When any layer cannot be restored exactly.
	 */
	public function restore(): void {
		$failure = null;
		$attempt = static function ( callable $operation ) use ( &$failure ): void {
			try {
				$operation();
			} catch ( \Throwable $error ) {
				$failure ??= $error;
			}
		};

		foreach ( $this->prices as $price_state ) {
			$attempt( static fn() => $price_state['product']->set_price( $price_state['price'] ) );
		}
		$attempt( fn() => $this->cart->set_cart_contents( $this->contents ) );
		if ( method_exists( $this->cart, 'set_removed_cart_contents' ) ) {
			$attempt( fn() => $this->cart->set_removed_cart_contents( $this->removed_contents ) );
		}
		$attempt( fn() => $this->cart->set_applied_coupons( $this->coupons ) );
		if ( method_exists( $this->cart, 'set_coupon_discount_totals' ) ) {
			$attempt( fn() => $this->cart->set_coupon_discount_totals( $this->coupon_discounts ) );
		}
		if ( method_exists( $this->cart, 'set_coupon_discount_tax_totals' ) ) {
			$attempt( fn() => $this->cart->set_coupon_discount_tax_totals( $this->coupon_discount_taxes ) );
		}
		if ( method_exists( $this->cart, 'fees_api' ) ) {
			$attempt(
				function (): void {
					$fees_api = $this->cart->fees_api();
					if ( ! is_object( $fees_api ) || ! method_exists( $fees_api, 'remove_all_fees' ) || ! method_exists( $fees_api, 'add_fee' ) ) {
						throw new \RuntimeException( 'WooCommerce fee collection is not restorable.' );
					}
					$fees_api->remove_all_fees();
					foreach ( $this->fees as $fee ) {
						$result = $fees_api->add_fee( $fee );
						if ( $result instanceof \WP_Error ) {
							// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text is not rendered and must preserve Woo's diagnostic.
							throw new \RuntimeException( $result->get_error_message() );
						}
					}
				}
			);
		}
		$attempt( fn() => $this->cart->set_totals( $this->totals ) );

		if ( null !== $this->session_state ) {
			$attempt( fn() => $this->session_state->restore() );
		}

		$attempt( fn() => $this->restore_persistent_cart() );

		if ( $failure instanceof \Throwable ) {
			throw $failure;
		}
	}

	/**
	 * Commit the session layer once and verify durable persistence.
	 *
	 * @throws \RuntimeException When the session cannot be persisted exactly.
	 */
	public function commit(): void {
		if ( null === $this->session_state ) {
			throw new \RuntimeException( 'WooCommerce session is unavailable.' );
		}
		$this->session_state->persist_current();
	}

	/**
	 * Evict process-local persistence state after an outcome becomes unknowable.
	 *
	 * Durable session/meta rows are deliberately never guessed or overwritten.
	 */
	public function quarantine_after_unknown_outcome(): void {
		if ( null !== $this->session_state ) {
			$this->session_state->quarantine_after_unknown_outcome();
		}
		if ( null !== $this->persistent_cart ) {
			$this->persistent_cart->evict_cache();
		}
	}

	/**
	 * Restore every stateful layer changed by an observational totals pass while
	 * retaining its calculated prices, fees, taxes and totals for serialization.
	 *
	 * @throws \Throwable When any structural or persistence layer cannot be restored.
	 */
	public function restore_observation(): void {
		$failure = null;
		$attempt = static function ( callable $operation ) use ( &$failure ): void {
			try {
				$operation();
			} catch ( \Throwable $error ) {
				$failure ??= $error;
			}
		};

		$attempt( fn() => $this->cart->set_cart_contents( $this->contents ) );
		if ( method_exists( $this->cart, 'set_removed_cart_contents' ) ) {
			$attempt( fn() => $this->cart->set_removed_cart_contents( $this->removed_contents ) );
		}
		$attempt( fn() => $this->cart->set_applied_coupons( $this->coupons ) );
		if ( null !== $this->session_state ) {
			$attempt( fn() => $this->session_state->restore() );
		}
		$attempt( fn() => $this->restore_persistent_cart() );

		if ( $failure instanceof \Throwable ) {
			throw $failure;
		}
	}

	/**
	 * Restore logged-in persistent cart state.
	 *
	 * @throws \RuntimeException When logged-in persistent cart state is not exact.
	 */
	private function restore_persistent_cart(): void {
		if ( null !== $this->persistent_cart ) {
			$this->persistent_cart->assert_current_for_restore();
		}
	}
}
