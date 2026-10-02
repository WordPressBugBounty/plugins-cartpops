<?php
/**
 * Database exclusion boundary for one WooCommerce cart session operation.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Cart;

use CartPops\Database\DatabaseCommitOutcomeUnknownException;
use CartPops\Database\OwnedTransaction;

/** Holds the exact Woo session and persistent-cart locks until commit. */
final class CartSessionTransaction {
	private const SAVEPOINT                 = 'cartpops_cart_mutation';
	private const LOCK_WAIT_TIMEOUT_SECONDS = 3;

	/**
	 * Exact owned database transaction.
	 *
	 * @var OwnedTransaction|null
	 */
	private ?OwnedTransaction $transaction = null;

	/**
	 * Whether request writes were reverted to the savepoint.
	 *
	 * @var bool
	 */
	private bool $rolled_back_to_savepoint = false;

	/** Construct only through begin(). */
	private function __construct() {}

	/**
	 * Acquire the exact Woo session row/gap and persistent-cart row/gap.
	 *
	 * @param object|null                $session WooCommerce session handler.
	 * @param CartSessionTransactionMode $mode    Exact hydration policy.
	 * @throws CartSessionConflictException When another request owns the cart.
	 * @throws \RuntimeException When exact exclusion cannot be established.
	 */
	public static function begin(
		?object $session,
		CartSessionTransactionMode $mode = CartSessionTransactionMode::STRICT
	): self {
		if ( null === $session ) {
			throw new \RuntimeException( 'WooCommerce session is unavailable.' );
		}
		$identity = WooSessionState::database_identity( $session );
		if ( null === $identity ) {
			throw new \RuntimeException( 'WooCommerce session storage is unsupported.' );
		}

		global $wpdb;
		if (
			! is_object( $wpdb )
			|| ! method_exists( $wpdb, 'query' )
			|| ! method_exists( $wpdb, 'get_var' )
			|| ! method_exists( $wpdb, 'prepare' )
		) {
			throw new \RuntimeException( 'WooCommerce session database is unavailable.' );
		}
		$basis = WooSessionState::capture( $session );
		if ( null === $basis ) {
			throw new \RuntimeException( 'WooCommerce session state is unavailable.' );
		}

		$persistent_basis = PersistentCartState::capture_current();

		$boundary              = new self();
		$boundary->transaction = OwnedTransaction::begin( $wpdb, 'SERIALIZABLE', self::LOCK_WAIT_TIMEOUT_SECONDS );
		try {
			$tables = array( $identity['table'] );
			if ( null !== $persistent_basis ) {
				$tables[] = $persistent_basis->table();
			}
			$boundary->assert_transactional_storage( $tables );
			$boundary->lock_session( $identity['table'], $identity['customer_id'] );
			if ( null !== $persistent_basis ) {
				$boundary->lock_persistent_cart( $persistent_basis );
			}
			$boundary->guard(
				static function () use ( $basis, $mode ): void {
					$basis->assert_current_basis( $mode );
				}
			);
			if ( null !== $persistent_basis ) {
				$boundary->guard(
					static function () use ( $persistent_basis ): void {
						$persistent_basis->assert_current_basis();
					}
				);
			}
			$boundary->execute_statement( 'SAVEPOINT ' . self::SAVEPOINT, 'The cart transaction savepoint could not be created.' );
			$boundary->assert_active();
		} catch ( \Throwable $error ) {
			$database_error = substr( self::database_error( $wpdb ), 0, 512 );
			$lock_conflict  = self::is_lock_conflict( $database_error );
			if ( $lock_conflict || $error instanceof CartSessionConflictException ) {
				try {
					if ( CartSessionTransactionMode::LOCAL_DIRTY_OBSERVATION === $mode ) {
						$basis->quarantine_after_observation_conflict();
					} else {
						$basis->quarantine_after_conflict();
					}
					if ( null !== $persistent_basis ) {
						$persistent_basis->evict_cache();
					}
				} catch ( \Throwable $quarantine_error ) {
					$error         = $quarantine_error;
					$lock_conflict = false;
				}
			}
			try {
				$boundary->abort_checked();
			} catch ( \Throwable $cleanup_error ) {
				if ( $lock_conflict && $boundary->was_safely_quarantined() ) {
					// InnoDB deadlocks roll back the selected victim before returning
					// the error. A positively closed exact handle proves this request
					// cannot retain writes or locks, so the conflict remains retry-safe.
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal chained exception is never rendered.
					throw new CartSessionConflictException( 'The cart is being updated by another request.', 0, $error );
				}
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal chained exception is never rendered.
				throw new \RuntimeException( 'The cart transaction failed and the database connection could not be restored.', 0, $cleanup_error );
			}
			if ( $lock_conflict ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal chained exception is never rendered.
				throw new CartSessionConflictException( 'The cart is being updated by another request.', 0, $error );
			}
			throw $error;
		}

		return $boundary;
	}

	/**
	 * Guard a Woo/wpdb operation with exact connection-continuity checks.
	 *
	 * @template TResult
	 * @param callable(): TResult $operation Operation that may use wpdb.
	 * @return TResult
	 * @throws \RuntimeException When the connection was replaced.
	 */
	public function guard( callable $operation ): mixed {
		return $this->owned()->guard( $operation );
	}

	/**
	 * Roll back request writes while retaining row locks for local restoration.
	 *
	 * @throws \RuntimeException When the savepoint was lost or rollback failed.
	 */
	public function rollback_changes(): void {
		if ( ! $this->owned()->is_active() ) {
			return;
		}
		$this->assert_active();
		$this->execute_statement( 'ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT, 'The cart database changes could not be rolled back.' );
		$this->assert_active();
		$this->rolled_back_to_savepoint = true;
	}

	/**
	 * Commit either the successful mutation or already-rolled-back state.
	 *
	 * @throws CartCommitOutcomeUnknownException When durability cannot be proven.
	 * @phpstan-throws \RuntimeException When a definite commit or cleanup step fails.
	 */
	public function commit(): void {
		try {
			$this->owned()->commit();
		} catch ( DatabaseCommitOutcomeUnknownException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal chained exception is never rendered.
			throw new CartCommitOutcomeUnknownException( 'The durable cart commit outcome is unknown.', 0, $error );
		}
	}

	/** Best-effort full rollback for an incomplete boundary. */
	public function abort(): void {
		try {
			$this->abort_checked();
		} catch ( \Throwable $error ) {
			unset( $error );
		}
	}

	/**
	 * Full rollback with verified same-connection cleanup.
	 *
	 * @throws \RuntimeException When rollback or restoration cannot be proven.
	 */
	public function abort_checked(): void {
		if ( null === $this->transaction || ! $this->transaction->is_active() ) {
			return;
		}
		try {
			$this->transaction->rollback();
		} catch ( \Throwable $error ) {
			$close_failed = str_contains( strtolower( $error->getMessage() ), 'closing' )
				|| str_contains( strtolower( $error->getMessage() ), 'refused to close' );
			$message      = $close_failed
				? 'The cart database connection could not be restored or closed safely.'
				: 'The cart database connection could not be restored safely.';
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal chained exception is never rendered.
			throw new \RuntimeException( $message, 0, $error );
		}
	}

	/** Whether database writes were rolled back before in-memory restoration. */
	public function did_rollback_changes(): bool {
		return $this->rolled_back_to_savepoint;
	}

	/**
	 * Detect an implicit commit or replacement of the owned connection.
	 *
	 * @throws \RuntimeException When exclusion is no longer held.
	 */
	public function assert_active(): void {
		$this->owned()->assert_active();
	}

	/**
	 * Prove every table participating in rollback uses InnoDB.
	 *
	 * @param string[] $tables Exact validated table identifiers.
	 * @throws \RuntimeException When row locks/savepoints are unsupported.
	 */
	private function assert_transactional_storage( array $tables ): void {
		global $wpdb;
		foreach ( array_values( array_unique( $tables ) ) as $table ) {
			if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $table ) ) {
				throw new \RuntimeException( 'Cart durable storage is not transaction-safe.' );
			}
			$query = $wpdb->prepare(
				'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
				$table
			);
			self::clear_database_error( $wpdb );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Exact storage proof under the guarded connection.
			$engine = $this->guard( static fn(): mixed => $wpdb->get_var( $query ) );
			if ( '' !== self::database_error( $wpdb ) || ! is_string( $engine ) || 0 !== strcasecmp( 'InnoDB', $engine ) ) {
				throw new \RuntimeException( 'Cart durable storage is not transaction-safe.' );
			}
			$this->assert_active();
		}
	}

	/**
	 * Lock one exact Woo session row or its first-write gap.
	 *
	 * @param string $table Validated Woo session table.
	 * @param string $customer_id Exact Woo customer/session ID.
	 * @throws \RuntimeException When the exact row/gap lock cannot be proven.
	 */
	private function lock_session( string $table, string $customer_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Woo identity validation closes the table identifier.
		$query = $wpdb->prepare( "SELECT session_key FROM {$table} WHERE session_key = %s FOR UPDATE", $customer_id );
		self::clear_database_error( $wpdb );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Exact row/gap lock using prepared SQL above.
		$this->guard( static fn(): mixed => $wpdb->get_var( $query ) );
		if ( '' !== self::database_error( $wpdb ) ) {
			throw new \RuntimeException( 'The WooCommerce session row could not be locked.' );
		}
		$this->assert_active();
	}

	/**
	 * Lock the exact logged-in persistent-cart row or its first-write gap.
	 *
	 * @param PersistentCartState $persistent_cart Exact persistent-cart basis.
	 * @throws \RuntimeException When the exact row/gap lock cannot be proven.
	 */
	private function lock_persistent_cart( PersistentCartState $persistent_cart ): void {
		global $wpdb;
		$table    = $persistent_cart->table();
		$user_id  = $persistent_cart->user_id();
		$meta_key = $persistent_cart->meta_key();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Validated core table identifier.
		$query = $wpdb->prepare( "SELECT umeta_id FROM {$table} WHERE user_id = %d AND meta_key = %s FOR UPDATE", $user_id, $meta_key );
		self::clear_database_error( $wpdb );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Exact persistent-cart row/gap lock.
		$this->guard( static fn(): mixed => $wpdb->get_var( $query ) );
		if ( '' !== self::database_error( $wpdb ) ) {
			throw new \RuntimeException( 'The WooCommerce persistent cart row could not be locked.' );
		}
		$this->assert_active();
	}

	/**
	 * Execute one wpdb statement and reject false/error responses.
	 *
	 * @param string $query Closed internal statement.
	 * @param string $failure_message Fixed internal failure description.
	 * @throws \RuntimeException When the statement or connection cannot be proven.
	 */
	private function execute_statement( string $query, string $failure_message ): void {
		global $wpdb;
		self::clear_database_error( $wpdb );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Closed internal transaction statement.
		$result = $this->guard( static fn(): mixed => $wpdb->query( $query ) );
		if ( false === $result || '' !== self::database_error( $wpdb ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Closed internal message is never rendered directly.
			throw new \RuntimeException( $failure_message );
		}
	}

	/**
	 * Return the established deep transaction module.
	 *
	 * @throws \RuntimeException When the transaction was not established.
	 */
	private function owned(): OwnedTransaction {
		if ( null === $this->transaction ) {
			throw new \RuntimeException( 'The cart database transaction is unavailable.' );
		}
		return $this->transaction;
	}

	/** Whether a failed boundary positively closed the exact unsafe handle. */
	private function was_safely_quarantined(): bool {
		return null !== $this->transaction
			&& ! $this->transaction->is_active()
			&& $this->transaction->quarantine_was_verified();
	}

	/**
	 * Determine whether the database reported a retry-safe lock conflict.
	 *
	 * @param string $message Bounded database diagnostic.
	 */
	private static function is_lock_conflict( string $message ): bool {
		$message = strtolower( $message );
		return str_contains( $message, 'lock wait timeout' ) || str_contains( $message, 'deadlock' );
	}

	/**
	 * Clear mutable wpdb error state without static-analysis literal narrowing.
	 *
	 * @param object $database WordPress database object.
	 */
	private static function clear_database_error( object $database ): void {
		/**
		 * Typed WordPress database object.
		 *
		 * @var \wpdb $database
		 */
		$database->last_error = '';
	}

	/**
	 * Read mutable wpdb error state after the immediately preceding query.
	 *
	 * @param object $database WordPress database object.
	 * @phpstan-impure The value is mutated externally by wpdb operations.
	 */
	private static function database_error( object $database ): string {
		/**
		 * Typed WordPress database object.
		 *
		 * @var \wpdb $database
		 */
		return (string) $database->last_error;
	}

	/** Ensure incomplete transactions cannot escape object lifetime. */
	public function __destruct() {
		$this->abort();
	}
}
