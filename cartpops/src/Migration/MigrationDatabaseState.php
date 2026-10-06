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

	/**
	 * SQL for a column's byte length as this connection receives it.
	 *
	 * OCTET_LENGTH measures the bytes stored in the table. When the connection
	 * uses another character set (an older site with DB_CHARSET latin1 and
	 * utf8mb4 tables, for example), MySQL converts each value on the way out,
	 * so the stored length no longer matches the string PHP receives. Measuring
	 * the converted value keeps exact-length checks working on those sites.
	 *
	 * @param mixed  $database WordPress database object.
	 * @param string $column   Closed, code-defined column name.
	 */
	public static function received_octet_length_sql( mixed $database, string $column ): string {
		$charset = is_object( $database ) && isset( $database->charset ) && is_string( $database->charset )
			? strtolower( $database->charset )
			: '';
		if ( '' === $charset || 'utf8mb4' === $charset || 1 !== preg_match( '/\A[a-z0-9]{1,32}\z/D', $charset ) ) {
			return "OCTET_LENGTH({$column})";
		}
		return "OCTET_LENGTH(CONVERT({$column} USING {$charset}))";
	}
}
