<?php
/**
 * Low-level database connection seam for owned transactions.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Database;

/**
 * Runs only transaction-control statements against one non-reconnecting handle.
 */
interface TransactionConnectionAdapter {
	/**
	 * Return the exact live connection identity, or null when it is unknowable.
	 *
	 * @phpstan-impure The live server connection may change between observations.
	 */
	public function identity(): ?string;

	/**
	 * Whether WordPress still owns the exact captured connection handle.
	 *
	 * @phpstan-impure WordPress may replace its connection between observations.
	 */
	public function is_current(): bool;

	/**
	 * Read one scalar without reconnecting or replaying the statement.
	 *
	 * @param string $query Closed internal scalar query.
	 * @phpstan-impure Database state and connection continuity may change.
	 */
	public function read_scalar( string $query ): mixed;

	/**
	 * Execute one control statement without reconnecting or replaying it.
	 *
	 * @param string $query Closed internal control statement.
	 * @phpstan-impure Database state and connection continuity may change.
	 */
	public function execute( string $query ): bool;

	/** Error number from the immediately preceding adapter operation. */
	public function error_number(): int;

	/** SQLSTATE from the immediately preceding adapter operation. */
	public function sql_state(): string;

	/** Bounded diagnostic from the immediately preceding adapter operation. */
	public function error_message(): string;

	/** Close the exact captured connection. */
	public function close(): bool;
}
