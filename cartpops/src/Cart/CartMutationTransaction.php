<?php
/**
 * Auditable transaction boundary for customer cart mutations.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Cart;

use CartPops\Database\DatabaseConnectionChangedException;

/**
 * Runs a cart mutation, one totals pass, and one verified session commit.
 */
final class CartMutationTransaction {
	/**
	 * Execute a mutation atomically.
	 *
	 * The callback must throw when an endpoint-specific return value represents
	 * rejection. Any callback, totals, hook, or persistence failure restores all
	 * cart/session/persistent-cart layers before the original error is rethrown.
	 *
	 * @template TMutation
	 * @template TResult
	 * @param \WC_Cart                            $cart         Cart being mutated.
	 * @param callable(): TMutation               $mutation     Endpoint-specific mutation.
	 * @param (callable(TMutation): TResult)|null $after_totals Response construction/validation after totals.
	 * @return ($after_totals is null ? TMutation : TResult)
	 * @phpstan-throws \DomainException|\RuntimeException When a callback rejects the mutation or transactional safety cannot be proven.
	 * @throws CartCommitOutcomeUnknownException When the durable COMMIT outcome cannot be proven.
	 * @throws \RuntimeException When mutation or exact rollback cannot complete safely.
	 */
	public static function execute( \WC_Cart $cart, callable $mutation, ?callable $after_totals = null ): mixed {
		$woocommerce = WC();
		$session     = is_object( $woocommerce ) ? ( $woocommerce->session ?? null ) : null;
		$boundary    = CartSessionTransaction::begin( is_object( $session ) ? $session : null );
		try {
			$snapshot = $boundary->guard( static fn(): CartMutationSnapshot => CartMutationSnapshot::capture( $cart ) );
		} catch ( \Throwable $error ) {
			try {
				$boundary->abort_checked();
			} catch ( \Throwable $cleanup_error ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Chained internal exception is never rendered.
				throw new \RuntimeException( 'Cart snapshot capture failed and database cleanup was unsafe.', 0, $cleanup_error );
			}
			throw $error;
		}
		try {
			$result = $boundary->guard( $mutation );
			$boundary->assert_active();
			$boundary->guard(
				static function () use ( $cart ): void {
					$cart->calculate_totals();
				}
			);
			$boundary->assert_active();
			$result = null === $after_totals
				? $result
				: $boundary->guard( static fn(): mixed => $after_totals( $result ) );
			$boundary->assert_active();
			$boundary->guard(
				static function () use ( $snapshot ): void {
					$snapshot->commit();
				}
			);
			$boundary->assert_active();
			$boundary->commit();
			return $result;
		} catch ( CartCommitOutcomeUnknownException $error ) {
			// COMMIT may have reached the server. Never run rollback restoration or
			// tell a controller this mutation is safe to retry.
			try {
				$snapshot->quarantine_after_unknown_outcome();
			} catch ( \Throwable $quarantine_error ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Chained internal exception is never rendered.
				throw new CartCommitOutcomeUnknownException( 'The durable cart commit outcome is unknown and process-local state could not be quarantined.', 0, $quarantine_error );
			}
			throw $error;
		} catch ( DatabaseConnectionChangedException $error ) {
			try {
				$snapshot->quarantine_after_unknown_outcome();
			} catch ( \Throwable $quarantine_error ) {
				$error = $quarantine_error;
			}
			// A callback may already have changed durable cart state on the lost
			// handle. This outcome is never safe for an automatic retry.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Chained internal exception is never rendered.
			throw new CartCommitOutcomeUnknownException( 'The durable cart mutation outcome is unknown.', 0, $error );
		} catch ( \Throwable $error ) {
			$rollback_failure = null;
			try {
				$boundary->rollback_changes();
			} catch ( \Throwable $rollback_error ) {
				$rollback_failure = $rollback_error;
				// A lost savepoint/transaction cannot safely retain locks while
				// recovery proceeds. A full rollback first restores durable rows;
				// snapshots then restore only process-local/cache state and refuse
				// to overwrite any successor request that won the race.
				try {
					$boundary->abort_checked();
				} catch ( \Throwable $cleanup_error ) {
					$rollback_failure = $cleanup_error;
				}
			}
			try {
				$boundary->guard(
					static function () use ( $snapshot ): void {
						$snapshot->restore();
					}
				);
			} catch ( \Throwable $rollback_error ) {
				$rollback_failure ??= $rollback_error;
			}
			if ( null === $rollback_failure ) {
				try {
					$boundary->commit();
				} catch ( \Throwable $rollback_error ) {
					$rollback_failure = $rollback_error;
				}
			}
			if ( $rollback_failure instanceof \Throwable ) {
				try {
					$boundary->abort_checked();
				} catch ( \Throwable $cleanup_error ) {
					$rollback_failure = $cleanup_error;
				}
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Chained internal exception is never rendered.
				throw new \RuntimeException( 'The cart change failed and could not be rolled back safely.', 0, $rollback_failure );
			}
			throw $error;
		} finally {
			$boundary->abort_checked();
		}
	}
}
