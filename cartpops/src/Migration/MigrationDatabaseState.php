<?php
/**
 * Database-state helpers shared by migration boundaries.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Migration;

use CartPops\Database\OwnedTransaction;

/**
 * Reads mutable wpdb state without letting static analysis treat a freshly
 * cleared property as permanently empty across a database method call.
 */
final class MigrationDatabaseState {
	/**
	 * Exact transaction currently fencing migration database calls.
	 *
	 * @var OwnedTransaction|null
	 */
	private static ?OwnedTransaction $transaction = null;

	/**
	 * Bind per-query continuity checks for one terminal-fence scope.
	 *
	 * @param OwnedTransaction $transaction Exact active terminal-fence boundary.
	 * @throws \RuntimeException When a different transaction is already bound.
	 */
	public static function bind( OwnedTransaction $transaction ): void {
		if ( null !== self::$transaction && self::$transaction !== $transaction ) {
			throw new \RuntimeException( 'A migration database transaction is already bound.' );
		}
		self::$transaction = $transaction;
	}

	/**
	 * Release only the transaction installed by the matching fence scope.
	 *
	 * @param OwnedTransaction $transaction Exact terminal-fence boundary to release.
	 */
	public static function release( OwnedTransaction $transaction ): void {
		if ( self::$transaction === $transaction ) {
			self::$transaction = null;
		}
	}

	/** Return the exact currently bound transaction, when one owns this scope. */
	public static function transaction(): ?OwnedTransaction {
		return self::$transaction;
	}

	/**
	 * Return the error produced by the immediately preceding query.
	 *
	 * @param mixed $database WordPress database object.
	 * @phpstan-impure The value is mutated externally by each wpdb operation.
	 */
	public static function last_error( mixed $database ): string {
		if ( null !== self::$transaction ) {
			self::$transaction->assert_active();
		}
		if ( ! is_object( $database ) ) {
			return 'database_unavailable';
		}

		/**
		 * Typed database connection.
		 *
		 * @var \wpdb $database
		 */
		return (string) $database->last_error;
	}
}
