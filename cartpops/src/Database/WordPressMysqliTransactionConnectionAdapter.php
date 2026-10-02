<?php
/**
 * Non-reconnecting mysqli adapter for the WordPress database handle.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Database;

/**
 * Keeps transaction control off wpdb's reconnect-and-replay query path.
 */
final class WordPressMysqliTransactionConnectionAdapter implements TransactionConnectionAdapter {
	/**
	 * Error number from the immediately preceding raw operation.
	 *
	 * @var int
	 */
	private int $last_error_number = 0;

	/**
	 * SQLSTATE from the immediately preceding raw operation.
	 *
	 * @var string
	 */
	private string $last_sql_state = '00000';

	/**
	 * Bounded diagnostic from the immediately preceding raw operation.
	 *
	 * @var string
	 */
	private string $last_error_message = '';

	/**
	 * Store the exact wpdb/mysqli connection boundary.
	 *
	 * @param object  $database WordPress database object.
	 * @param \mysqli $handle Exact live mysqli handle.
	 * @param int     $thread_id Exact live server connection identity.
	 */
	private function __construct(
		private readonly object $database,
		private readonly \mysqli $handle,
		private readonly int $thread_id,
	) {}

	/**
	 * Capture the exact mysqli handle currently installed in wpdb.
	 *
	 * @param object $database WordPress database object.
	 * @throws \RuntimeException When the exact supported handle cannot be captured.
	 */
	public static function capture( object $database ): self {
		$handle = self::database_handle( $database );
		if ( ! $handle instanceof \mysqli ) {
			throw new \RuntimeException( 'The WordPress database driver is unsupported for owned transactions.' );
		}
		try {
			$thread_id = (int) $handle->thread_id;
			// @phpstan-ignore-next-line catch.neverThrown (Closed mysqli handles throw on supported runtimes.)
		} catch ( \Throwable $error ) {
			unset( $error );
			throw new \RuntimeException( 'The WordPress database connection identity cannot be read.' );
		}
		if ( $thread_id <= 0 ) {
			throw new \RuntimeException( 'The WordPress database connection identity is invalid.' );
		}

		return new self( $database, $handle, $thread_id );
	}

	/** Return the exact handle/thread identity while it remains live. */
	public function identity(): ?string {
		try {
			$thread_id = (int) $this->handle->thread_id;
			// @phpstan-ignore-next-line catch.neverThrown (Closed mysqli handles throw on supported runtimes.)
		} catch ( \Throwable $error ) {
			unset( $error );
			return null;
		}
		return $thread_id === $this->thread_id && $thread_id > 0
			? spl_object_id( $this->handle ) . ':' . $thread_id
			: null;
	}

	/** Whether wpdb still owns the exact captured live handle. */
	public function is_current(): bool {
		return self::database_handle( $this->database ) === $this->handle
			&& null !== $this->identity();
	}

	/**
	 * Read one scalar through the exact raw handle.
	 *
	 * @param string $query Closed internal scalar query.
	 */
	public function read_scalar( string $query ): mixed {
		$this->clear_error();
		if ( ! $this->is_current() ) {
			$this->record_internal_error( 'The captured mysqli handle is no longer current.' );
			return null;
		}

		try {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- Per-call warning suppression avoids changing global mysqli_report; this exact raw handle never reconnects or replays.
			$result = @$this->handle->query( $query );
		} catch ( \Throwable $error ) {
			$this->record_throwable( $error );
			return null;
		}
		if ( ! $result instanceof \mysqli_result ) {
			$this->record_handle_error();
			return null;
		}
		$row = $result->fetch_row();
		$result->free();
		if ( ! is_array( $row ) || 1 !== count( $row ) ) {
			$this->record_internal_error( 'The transaction scalar query returned an unsupported shape.' );
			return null;
		}
		return $row[0];
	}

	/**
	 * Execute one control statement through the exact raw handle.
	 *
	 * @param string $query Closed internal control statement.
	 */
	public function execute( string $query ): bool {
		$this->clear_error();
		if ( ! $this->is_current() ) {
			$this->record_internal_error( 'The captured mysqli handle is no longer current.' );
			return false;
		}

		try {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- Per-call warning suppression avoids changing global mysqli_report; this exact raw handle never reconnects or replays.
			$result = @$this->handle->query( $query );
		} catch ( \Throwable $error ) {
			$this->record_throwable( $error );
			return false;
		}
		if ( $result instanceof \mysqli_result ) {
			$result->free();
			return true;
		}
		if ( true !== $result ) {
			$this->record_handle_error();
			return false;
		}
		return true;
	}

	/** Return the immediately preceding raw operation's error number. */
	public function error_number(): int {
		return $this->last_error_number;
	}

	/** Return the immediately preceding raw operation's SQLSTATE. */
	public function sql_state(): string {
		return $this->last_sql_state;
	}

	/** Return the immediately preceding raw operation's bounded diagnostic. */
	public function error_message(): string {
		return $this->last_error_message;
	}

	/** Close and detach only the exact captured unsafe handle. */
	public function close(): bool {
		try {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.DB.RestrictedFunctions.mysql_mysqli_close -- Close only the captured unsafe handle; never a wpdb replacement.
			$closed = true === @$this->handle->close();
		} catch ( \Throwable $error ) {
			$this->record_throwable( $error );
			return false;
		}
		if ( ! $closed ) {
			return false;
		}

		// A closed mysqli object left in wpdb makes prepare()/escaping fatal on
		// supported PHP versions. Detach only this exact handle; never alter a
		// replacement that WordPress or a custom layer already installed.
		try {
			$property = self::database_handle_property( $this->database );
			if ( null !== $property && $property->getValue( $this->database ) === $this->handle ) {
				$property->setValue( $this->database, null );
			}
		} catch ( \Throwable $error ) {
			$this->record_throwable( $error );
			return false;
		}
		return true;
	}

	/**
	 * Read wpdb's protected handle without assuming a concrete wpdb subclass.
	 *
	 * @param object $database WordPress database object.
	 */
	private static function database_handle( object $database ): mixed {
		$property = self::database_handle_property( $database );
		if ( null === $property ) {
			return null;
		}
		try {
			return $property->getValue( $database );
		} catch ( \Throwable $error ) {
			unset( $error );
			return null;
		}
	}

	/**
	 * Resolve wpdb's canonical protected handle property.
	 *
	 * @param object $database WordPress database object.
	 */
	private static function database_handle_property( object $database ): ?\ReflectionProperty {
		try {
			$class = new \ReflectionObject( $database );
			while ( ! $class->hasProperty( 'dbh' ) ) {
				$class = $class->getParentClass();
				if ( false === $class ) {
					return null;
				}
			}
			$property = $class->getProperty( 'dbh' );
			return $property;
		} catch ( \Throwable $error ) {
			unset( $error );
			return null;
		}
	}

	/** Reset bounded operation diagnostics. */
	private function clear_error(): void {
		$this->last_error_number  = 0;
		$this->last_sql_state     = '00000';
		$this->last_error_message = '';
	}

	/** Capture diagnostics from the exact mysqli handle. */
	private function record_handle_error(): void {
		try {
			$this->last_error_number  = (int) $this->handle->errno;
			$this->last_sql_state     = (string) $this->handle->sqlstate;
			$this->last_error_message = substr( (string) $this->handle->error, 0, 512 );
		} catch ( \Throwable $error ) {
			$this->record_throwable( $error );
		}
	}

	/**
	 * Capture bounded diagnostics from a native mysqli throwable.
	 *
	 * @param \Throwable $error Native raw-handle failure.
	 */
	private function record_throwable( \Throwable $error ): void {
		$this->last_error_number  = max( 0, (int) $error->getCode() );
		$this->last_sql_state     = $error instanceof \mysqli_sql_exception ? $error->getSqlState() : 'HY000';
		$this->last_error_message = substr( $error->getMessage(), 0, 512 );
	}

	/**
	 * Record a fail-closed adapter diagnostic.
	 *
	 * @param string $message Bounded internal diagnostic.
	 */
	private function record_internal_error( string $message ): void {
		$this->last_error_number  = 0;
		$this->last_sql_state     = 'HY000';
		$this->last_error_message = $message;
	}
}
