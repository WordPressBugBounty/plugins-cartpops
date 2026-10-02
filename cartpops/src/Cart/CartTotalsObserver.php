<?php
/**
 * Transactional read-only WooCommerce totals observation.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Cart;

use CartPops\Database\DatabaseConnectionChangedException;

/** Calculate one current totals pass without persisting observational changes. */
final class CartTotalsObserver {
	/**
	 * Calculate current display totals while preserving every structural cart layer.
	 *
	 * `STRICT` is required for request-backed reads. The local-dirty mode is reserved
	 * for server rendering immediately after a same-request cart change which Woo is
	 * still entitled to persist at shutdown.
	 *
	 * @param \WC_Cart                   $cart Cart being observed.
	 * @param CartSessionTransactionMode $mode Exact hydration policy.
	 * @throws CartCommitOutcomeUnknownException When durable state cannot be classified safely.
	 * @throws \RuntimeException When exclusion, restoration, or cleanup cannot be proven.
	 */
	public function observe(
		\WC_Cart $cart,
		CartSessionTransactionMode $mode = CartSessionTransactionMode::STRICT
	): void {
		CartTotalsObservationScope::begin();
		try {
			$woocommerce = WC();
			$session     = is_object( $woocommerce ) ? ( $woocommerce->session ?? null ) : null;
			$boundary    = CartSessionTransaction::begin( is_object( $session ) ? $session : null, $mode );
			try {
				$snapshot = $boundary->guard( static fn(): CartMutationSnapshot => CartMutationSnapshot::capture( $cart ) );
			} catch ( \Throwable $error ) {
				try {
					$boundary->abort_checked();
				} catch ( \Throwable $cleanup_error ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Chained internal exception is never rendered.
					throw new \RuntimeException( 'Read-only cart capture failed and database cleanup was unsafe.', 0, $cleanup_error );
				}
				throw $error;
			}

			$session_callback = $this->cart_session_persistence_callback( $cart );
			$session_priority = null;
			if ( null !== $session_callback ) {
				$priority = has_action( 'woocommerce_after_calculate_totals', $session_callback );
				if ( false !== $priority && remove_action( 'woocommerce_after_calculate_totals', $session_callback, (int) $priority ) ) {
					$session_priority = (int) $priority;
				}
			}

			try {
				$failure = null;
				try {
					$boundary->guard(
						static function () use ( $cart ): void {
							$cart->calculate_totals();
						}
					);
					$boundary->assert_active();
				} catch ( \Throwable $error ) {
					$failure = $error;
				}

				try {
					$boundary->rollback_changes();
				} catch ( \Throwable $error ) {
					$failure ??= $error;
					try {
						$boundary->abort_checked();
					} catch ( \Throwable $cleanup_error ) {
						$failure = new \RuntimeException( 'Read-only cart cleanup was unsafe.', 0, $cleanup_error );
					}
				}

				try {
					if ( $failure instanceof \Throwable ) {
						$boundary->guard(
							static function () use ( $snapshot ): void {
								$snapshot->restore();
							}
						);
					} else {
						$boundary->guard(
							static function () use ( $snapshot ): void {
								$snapshot->restore_observation();
							}
						);
					}
				} catch ( \Throwable $error ) {
					$failure ??= $error;
				}

				try {
					$boundary->commit();
				} catch ( \Throwable $error ) {
					$failure ??= $error;
				}

				if ( $failure instanceof DatabaseConnectionChangedException ) {
					try {
						$snapshot->quarantine_after_unknown_outcome();
					} catch ( \Throwable $quarantine_error ) {
						$failure = $quarantine_error;
					}
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Chained internal exception is never rendered.
					throw new CartCommitOutcomeUnknownException( 'The durable cart observation outcome is unknown.', 0, $failure );
				}
				if ( $failure instanceof CartCommitOutcomeUnknownException ) {
					$snapshot->quarantine_after_unknown_outcome();
					throw $failure;
				}
				if ( $failure instanceof \Throwable ) {
					throw $failure;
				}
			} finally {
				if ( null !== $session_callback && null !== $session_priority ) {
					add_action( 'woocommerce_after_calculate_totals', $session_callback, $session_priority );
				}
				$boundary->abort_checked();
			}
		} finally {
			CartTotalsObservationScope::end();
		}
	}

	/**
	 * Resolve WooCommerce's exact cart-session persistence callback.
	 *
	 * @param \WC_Cart $cart WooCommerce cart.
	 * @return array{0: object, 1: string}|null
	 */
	private function cart_session_persistence_callback( \WC_Cart $cart ): ?array {
		try {
			$property = ( new \ReflectionObject( $cart ) )->getProperty( 'session' );
			$session  = $property->getValue( $cart );
		} catch ( \ReflectionException ) {
			return null;
		}

		return is_object( $session ) && is_callable( array( $session, 'set_session' ) )
			? array( $session, 'set_session' )
			: null;
	}
}
