<?php
/**
 * Portable ownership of one database transaction.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Database;

/**
 * Proves ownership without privileged or vendor-specific transaction metadata.
 */
final class OwnedTransaction {
	private const ACTIVE_ERROR_NUMBER = 1568;
	private const ACTIVE_SQLSTATE     = '25001';

	/**
	 * Whether this module still owns an active transaction.
	 *
	 * @var bool
	 */
	private bool $active = false;

	/**
	 * Whether the session lock timeout must be restored.
	 *
	 * @var bool
	 */
	private bool $timeout_changed = false;

	/**
	 * Whether the captured connection has been closed.
	 *
	 * @var bool
	 */
	private bool $closed = false;

	/**
	 * Whether closing the unsafe connection was positively verified.
	 *
	 * @var bool
	 */
	private bool $quarantine_verified = false;

	/**
	 * Original bounded session lock timeout.
	 *
	 * @var int
	 */
	private int $original_timeout;

	/**
	 * Store one exact connection-bound transaction boundary.
	 *
	 * @param TransactionConnectionAdapter $connection Non-reconnecting connection adapter.
	 * @param string                       $identity Exact connection identity.
	 * @param string                       $isolation Validated isolation level.
	 */
	private function __construct(
		private readonly TransactionConnectionAdapter $connection,
		private readonly string $identity,
		private readonly string $isolation,
	) {}

	/**
	 * Begin a transaction on an exact, normal-autocommit connection.
	 *
	 * @param object                            $database WordPress database adapter.
	 * @param string                            $isolation Supported transaction isolation name.
	 * @param int                               $lock_wait_timeout Maximum session lock wait.
	 * @param TransactionConnectionAdapter|null $connection Explicit adapter, or null for wpdb/mysqli.
	 * @throws \RuntimeException When state, identity, or transaction ownership cannot be proven.
	 */
	public static function begin(
		object $database,
		string $isolation,
		int $lock_wait_timeout,
		?TransactionConnectionAdapter $connection = null
	): self {
		$connection ??= $database instanceof TransactionConnectionAdapter
			? $database
			: WordPressMysqliTransactionConnectionAdapter::capture( $database );
		$isolation    = strtoupper( $isolation );
		if ( ! in_array( $isolation, array( 'REPEATABLE READ', 'SERIALIZABLE' ), true ) || $lock_wait_timeout < 1 ) {
			throw new \RuntimeException( 'The database transaction configuration is invalid.' );
		}

		$identity = $connection->identity();
		if ( null === $identity || '' === $identity || ! $connection->is_current() ) {
			throw new \RuntimeException( 'The database connection identity cannot be established.' );
		}
		$transaction = new self( $connection, $identity, $isolation );

		$autocommit = $transaction->read_integer( 'SELECT @@session.autocommit', 0, 1 );
		if ( 1 !== $autocommit ) {
			throw new \RuntimeException( 'The database connection is not in normal autocommit mode.' );
		}
		$transaction->original_timeout = $transaction->read_integer( 'SELECT @@session.innodb_lock_wait_timeout', 1, 1073741824 );

		// Both supported engines reject this exact statement with 1568/25001
		// while a caller-owned transaction is active. Outside a transaction it
		// safely configures only the next transaction.
		if ( ! $connection->execute( 'SET TRANSACTION ISOLATION LEVEL ' . $isolation ) ) {
			if ( $transaction->is_active_signal() && $transaction->same_connection() ) {
				throw new \RuntimeException( 'The database connection is already in a transaction.' );
			}
			$transaction->quarantine();
			throw new \RuntimeException( 'The database transaction state cannot be established.' );
		}
		$transaction->assert_same_connection();

		if ( $transaction->original_timeout > $lock_wait_timeout ) {
			if ( ! $connection->execute( 'SET SESSION innodb_lock_wait_timeout = ' . $lock_wait_timeout ) ) {
				$transaction->quarantine();
				throw new \RuntimeException( 'The database lock timeout could not be bounded.' );
			}
			$transaction->assert_same_connection();
			$transaction->timeout_changed = true;
		}

		if ( ! $connection->execute( 'START TRANSACTION' ) ) {
			$transaction->quarantine();
			throw new \RuntimeException( 'The database transaction could not be started.' );
		}
		$transaction->assert_same_connection();
		$transaction->active = true;
		$transaction->assert_active();

		return $transaction;
	}

	/** Whether this module still owns an active transaction. */
	public function is_active(): bool {
		return $this->active;
	}

	/** Exact handle/thread identity captured before any transaction control. */
	public function connection_identity(): string {
		return $this->identity;
	}

	/** Whether an unsafe exact handle was positively closed. */
	public function quarantine_was_verified(): bool {
		return $this->quarantine_verified;
	}

	/**
	 * Run one wpdb/Woo operation with connection-continuity checks around it.
	 *
	 * @template TResult
	 * @param callable(): TResult $operation Operation that may use wpdb.
	 * @return TResult
	 * @throws \Throwable When the operation fails or connection continuity is lost.
	 */
	public function guard( callable $operation ): mixed {
		$this->assert_same_connection();
		try {
			$result = $operation();
		} catch ( \Throwable $error ) {
			$this->assert_same_connection();
			throw $error;
		}
		$this->assert_same_connection();
		return $result;
	}

	/**
	 * Prove the owned transaction and exact connection are still active.
	 *
	 * @throws DatabaseConnectionChangedException When the owned transaction disappeared.
	 * @throws \RuntimeException When ownership or connection continuity cannot be proven.
	 */
	public function assert_active(): void {
		if ( ! $this->active ) {
			throw new \RuntimeException( 'The owned database transaction is inactive.' );
		}
		$this->assert_same_connection();
		if ( ! $this->connection->execute( 'SET TRANSACTION ISOLATION LEVEL ' . $this->isolation ) ) {
			if ( $this->is_active_signal() && $this->same_connection() ) {
				return;
			}
			$this->quarantine();
			throw new \RuntimeException( 'The owned database transaction state is unknown.' );
		}

		// A successful active-state probe configured a future transaction, which
		// means the owned transaction disappeared. Never reuse that connection.
		$this->quarantine();
		throw new DatabaseConnectionChangedException( 'The owned database transaction was lost.' );
	}

	/**
	 * Roll back and return the exact connection to its original session state.
	 *
	 * @throws \RuntimeException When rollback, continuity, or restoration cannot be proven.
	 */
	public function rollback(): void {
		if ( ! $this->active ) {
			return;
		}
		$this->assert_active();
		if ( ! $this->connection->execute( 'ROLLBACK' ) ) {
			if ( ! $this->same_connection() ) {
				$this->quarantine();
				throw new \RuntimeException( 'The database transaction could not be rolled back.' );
			}
			$probe = $this->connection->execute( 'SET TRANSACTION ISOLATION LEVEL ' . $this->isolation );
			if ( ! $probe && $this->is_active_signal() && $this->same_connection() ) {
				if ( ! $this->connection->execute( 'ROLLBACK' ) || ! $this->same_connection() ) {
					$this->quarantine();
					throw new \RuntimeException( 'The database transaction could not be rolled back.' );
				}
			} elseif ( $probe && $this->same_connection() ) {
				// The first ROLLBACK reached the server but its response was not
				// reliable. The successful probe proves inactivity; consume its
				// next-transaction isolation below.
				$this->active = false;
				$this->consume_inactive_probe();
				$this->restore_or_quarantine();
				return;
			} else {
				$this->quarantine();
				throw new \RuntimeException( 'The database transaction rollback outcome is unknown.' );
			}
		}
		$this->assert_same_connection();
		$this->active = false;
		$this->assert_inactive();
		$this->restore_or_quarantine();
	}

	/**
	 * Commit once, distinguishing a definite rejection from an unknown outcome.
	 *
	 * @phpstan-throws DatabaseCommitOutcomeUnknownException When durability cannot be proven.
	 * @throws \RuntimeException When a definite commit rejection or restoration failure occurs.
	 */
	public function commit(): void {
		if ( ! $this->active ) {
			return;
		}
		$this->assert_active();
		$this->restore_or_quarantine();

		$committed = $this->connection->execute( 'COMMIT' );
		if ( ! $this->same_connection() ) {
			$this->throw_unknown_commit();
		}

		if ( ! $committed ) {
			if ( ! $this->connection->execute( 'SET TRANSACTION ISOLATION LEVEL ' . $this->isolation ) ) {
				if ( $this->is_active_signal() && $this->same_connection() ) {
					throw new \RuntimeException( 'The database transaction commit was rejected before completion.' );
				}
			}
			$this->throw_unknown_commit();
		}

		$this->active = false;
		if ( ! $this->connection->execute( 'SET TRANSACTION ISOLATION LEVEL ' . $this->isolation ) || ! $this->same_connection() ) {
			$this->throw_unknown_commit();
		}
		try {
			$this->consume_inactive_probe();
		} catch ( \Throwable $error ) {
			$this->throw_unknown_commit( $error );
		}
	}

	/**
	 * Restore a changed session timeout on only the exact captured connection.
	 *
	 * @throws \RuntimeException When restoration or safe quarantine fails.
	 */
	private function restore_or_quarantine(): void {
		if ( ! $this->timeout_changed ) {
			return;
		}
		if ( ! $this->same_connection() ) {
			$this->quarantine();
			throw new \RuntimeException( 'The database connection changed before session restoration.' );
		}
		if ( ! $this->connection->execute( 'SET SESSION innodb_lock_wait_timeout = ' . $this->original_timeout ) ) {
			try {
				$this->quarantine();
			} catch ( \Throwable $close_error ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Chained internal exception is never rendered.
				throw new \RuntimeException( 'The database lock timeout could not be restored or quarantined.', 0, $close_error );
			}
			throw new \RuntimeException( 'The database lock timeout could not be restored.' );
		}
		$this->assert_same_connection();
		$this->timeout_changed = false;
	}

	/**
	 * Prove there is no active transaction without privileged metadata.
	 *
	 * @throws \RuntimeException When inactivity cannot be proven or consumed.
	 */
	private function assert_inactive(): void {
		$this->assert_same_connection();
		if ( ! $this->connection->execute( 'SET TRANSACTION ISOLATION LEVEL ' . $this->isolation ) ) {
			$this->quarantine();
			throw new \RuntimeException( 'The database transaction did not become inactive.' );
		}
		$this->assert_same_connection();
		$this->consume_inactive_probe();
	}

	/**
	 * Consume a successful inactive-state probe without leaking isolation.
	 *
	 * @throws \RuntimeException When the probe transaction cannot be consumed safely.
	 */
	private function consume_inactive_probe(): void {
		// SET TRANSACTION changes the next transaction only. Consume that probe
		// setting with an empty transaction so no state leaks to later WordPress
		// queries on this reusable connection.
		if ( ! $this->connection->execute( 'START TRANSACTION' ) ) {
			$this->quarantine();
			throw new \RuntimeException( 'The database inactive-state probe could not start.' );
		}
		$this->assert_same_connection();
		if ( ! $this->connection->execute( 'ROLLBACK' ) ) {
			$this->quarantine();
			throw new \RuntimeException( 'The database inactive-state probe could not finish.' );
		}
		$this->assert_same_connection();
	}

	/**
	 * Read one canonical non-negative session integer.
	 *
	 * @param string $query Closed scalar session-state query.
	 * @param int    $minimum Inclusive lower bound.
	 * @param int    $maximum Inclusive upper bound.
	 * @throws \RuntimeException When the value or connection cannot be proven.
	 */
	private function read_integer( string $query, int $minimum, int $maximum ): int {
		$value = $this->connection->read_scalar( $query );
		$this->assert_same_connection();
		if ( is_int( $value ) ) {
			$parsed = $value;
		} elseif ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			$parsed = (int) $value;
		} else {
			throw new \RuntimeException( 'The database session state cannot be determined.' );
		}
		if ( $parsed < $minimum || $parsed > $maximum ) {
			throw new \RuntimeException( 'The database session state is outside supported bounds.' );
		}
		return $parsed;
	}

	/** Whether the last adapter error is the one portable active-state signal. */
	private function is_active_signal(): bool {
		return self::ACTIVE_ERROR_NUMBER === $this->connection->error_number()
			&& self::ACTIVE_SQLSTATE === $this->connection->sql_state();
	}

	/**
	 * Whether the exact connection is still installed in wpdb.
	 *
	 * @phpstan-impure The live handle may be replaced between observations.
	 */
	private function same_connection(): bool {
		return ! $this->closed
			&& $this->connection->is_current()
			&& $this->identity === $this->connection->identity();
	}

	/**
	 * Refuse continuation when connection continuity cannot be proven.
	 *
	 * @throws DatabaseConnectionChangedException When the captured handle is no longer exact.
	 */
	private function assert_same_connection(): void {
		if ( ! $this->same_connection() ) {
			$this->quarantine();
			throw new DatabaseConnectionChangedException( 'The database connection was replaced.' );
		}
	}

	/**
	 * Close an unprovable handle without attempting session restoration.
	 *
	 * @throws \RuntimeException When the unsafe connection cannot be positively closed.
	 */
	private function quarantine(): void {
		$this->active          = false;
		$this->timeout_changed = false;
		if ( $this->closed ) {
			return;
		}
		$this->closed = true;
		try {
			$closed = $this->connection->close();
		} catch ( \Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal chained exception is never rendered.
			throw new \RuntimeException( 'The unsafe database connection threw while closing.', 0, $error );
		}
		if ( ! $closed ) {
			throw new \RuntimeException( 'The unsafe database connection refused to close.' );
		}
		$this->quarantine_verified = true;
	}

	/**
	 * Never turn an ambiguous COMMIT into a retryable failure.
	 *
	 * @param \Throwable|null $cause Prior connection or durability failure.
	 * @throws DatabaseCommitOutcomeUnknownException Always, after safe quarantine is attempted.
	 */
	private function throw_unknown_commit( ?\Throwable $cause = null ): never {
		$this->active = false;
		try {
			$this->quarantine();
		} catch ( \Throwable $close_error ) {
			$cause = $close_error;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Chained internal exception is never rendered.
		throw new DatabaseCommitOutcomeUnknownException( 'The durable database commit outcome is unknown.', 0, $cause );
	}
}
