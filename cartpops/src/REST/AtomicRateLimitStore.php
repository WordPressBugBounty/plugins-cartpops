<?php
/**
 * Atomic fixed-window rate-limit persistence.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\REST;

/** Consume all independent dimensions in one all-or-none database transaction. */
final class AtomicRateLimitStore implements RateLimitStore {

	private const KEY_PREFIX               = 'cartpops_';
	private const MAX_TRANSACTION_ATTEMPTS = 3;

	/**
	 * WordPress database boundary.
	 *
	 * @var \wpdb|object|null
	 */
	private $database;

	/**
	 * Create the store around an explicit or global WordPress database boundary.
	 *
	 * @param \wpdb|object|null $database Optional WordPress database boundary.
	 */
	public function __construct( $database = null ) {
		if ( null === $database ) {
			global $wpdb;
			$database = $wpdb;
		}
		$this->database = $database;
	}

	/**
	 * Atomically consume one dimension.
	 *
	 * @param string $key    Privacy-hashed dimension key.
	 * @param int    $limit  Maximum requests per window.
	 * @param int    $window Fixed-window length in seconds.
	 * @param int    $now    Current Unix timestamp.
	 */
	public function consume( string $key, int $limit, int $window, int $now ): RateLimitDecision {
		return $this->consume_all( array( $key ), $limit, $window, $now );
	}

	/**
	 * Insert missing dimension rows, lock all rows in deterministic key order,
	 * decide them as a set, and commit all decrements or none. A denied request
	 * rolls back even its provisional inserts, so it cannot burn another shared
	 * dimension while discovering an exhausted one.
	 *
	 * @param string[] $keys   Privacy-hashed independent dimensions.
	 * @param int      $limit  Maximum requests per window.
	 * @param int      $window Fixed-window length in seconds.
	 * @param int      $now    Current Unix timestamp.
	 */
	public function consume_all( array $keys, int $limit, int $window, int $now ): RateLimitDecision {
		if ( ! $this->valid_database() ) {
			return RateLimitDecision::store_unavailable();
		}
		if ( ! $this->valid_input( $keys, $limit, $window, $now ) ) {
			return RateLimitDecision::invalid_configuration();
		}

		$keys = array_values( array_unique( $keys ) );
		sort( $keys, SORT_STRING );
		for ( $attempt = 1; $attempt <= self::MAX_TRANSACTION_ATTEMPTS; ++$attempt ) {
			$result = $this->consume_transaction( $keys, $limit, $window, $now );
			if ( ! $result['retry'] || self::MAX_TRANSACTION_ATTEMPTS === $attempt ) {
				return $result['decision'];
			}
		}

		return RateLimitDecision::store_unavailable();
	}

	/**
	 * Run one all-or-none attempt with the already validated, sorted key set.
	 *
	 * @param string[] $keys   Privacy-hashed dimensions in deterministic order.
	 * @param int      $limit  Maximum requests per window.
	 * @param int      $window Fixed-window length in seconds.
	 * @param int      $now    Current Unix timestamp.
	 * @return array{decision: RateLimitDecision, retry: bool}
	 */
	private function consume_transaction( array $keys, int $limit, int $window, int $now ): array {
		$table   = $this->database->prefix . 'wc_rate_limits';
		$expires = $now + $window;

		if ( ! $this->run( 'START TRANSACTION' ) ) {
			return $this->transaction_failure();
		}

		try {
			foreach ( $keys as $key ) {
				$insert = $this->database->prepare(
					"INSERT INTO {$table}
						(`rate_limit_key`, `rate_limit_expiry`, `rate_limit_remaining`)
					VALUES (%s, %d, %d)
					ON DUPLICATE KEY UPDATE `rate_limit_key` = VALUES(`rate_limit_key`)",
					$key,
					$expires,
					$limit
				);
				if ( ! is_string( $insert ) || ! $this->run( $insert ) ) {
					return $this->transaction_failure();
				}
			}

			$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
			$select       = $this->database->prepare(
				"SELECT `rate_limit_key`, `rate_limit_expiry`, `rate_limit_remaining`
				FROM {$table}
				WHERE `rate_limit_key` IN ({$placeholders})
				ORDER BY `rate_limit_key` ASC
				FOR UPDATE",
				...$keys
			);
			if ( ! is_string( $select ) ) {
				return $this->transaction_failure();
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Locking the complete bounded dimension set is the all-or-none authority.
			$rows = $this->database->get_results( $select, ARRAY_A );
			if ( ! is_array( $rows ) || count( $rows ) !== count( $keys ) || $this->has_error() ) {
				return $this->transaction_failure();
			}

			$by_key              = array();
			$retry_after         = 0;
			$aggregate_remaining = $limit;
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['rate_limit_key'], $row['rate_limit_expiry'], $row['rate_limit_remaining'] ) ) {
					return $this->transaction_failure();
				}
				$key = (string) $row['rate_limit_key'];
				if ( ! in_array( $key, $keys, true ) || isset( $by_key[ $key ] ) ) {
					return $this->transaction_failure();
				}
				$expiry              = (int) $row['rate_limit_expiry'];
				$remaining           = (int) $row['rate_limit_remaining'];
				$remaining_after     = $expiry <= $now ? $limit - 1 : max( 0, $remaining - 1 );
				$aggregate_remaining = min( $aggregate_remaining, $remaining_after );
				$by_key[ $key ]      = array(
					'expiry'    => $expiry,
					'remaining' => $remaining,
				);
				if ( $expiry > $now && $remaining < 1 ) {
					$retry_after = max( $retry_after, min( $window, $expiry - $now ) );
				}
			}

			if ( $retry_after > 0 ) {
				if ( ! $this->rollback() ) {
					return self::attempt_result( RateLimitDecision::store_unavailable() );
				}
				return self::attempt_result( RateLimitDecision::exhausted( $limit, $retry_after ) );
			}

			foreach ( $keys as $key ) {
				$row = $by_key[ $key ] ?? null;
				if ( ! is_array( $row ) ) {
					return $this->transaction_failure();
				}
				if ( $row['expiry'] <= $now ) {
					$update = $this->database->prepare(
						"UPDATE {$table}
						SET `rate_limit_expiry` = %d, `rate_limit_remaining` = %d
						WHERE `rate_limit_key` = %s",
						$expires,
						$limit - 1,
						$key
					);
				} else {
					$update = $this->database->prepare(
						"UPDATE {$table}
						SET `rate_limit_remaining` = `rate_limit_remaining` - 1
						WHERE `rate_limit_key` = %s AND `rate_limit_remaining` > 0",
						$key
					);
				}
				if ( ! is_string( $update ) || 1 !== $this->run_affected( $update ) ) {
					return $this->transaction_failure();
				}
			}

			if ( ! $this->run( 'COMMIT' ) ) {
				// The server may have committed even if the client lost the reply.
				// Attempt rollback for cleanup, but never retry this ambiguous state.
				return $this->transaction_failure( false );
			}
		} catch ( \Throwable ) {
			$this->rollback();
			return self::attempt_result( RateLimitDecision::store_unavailable() );
		}

		return self::attempt_result( RateLimitDecision::allowed( $limit, $aggregate_remaining ) );
	}

	/**
	 * Delete one bounded batch of expired CartPops rows.
	 *
	 * @param int $now Current Unix timestamp.
	 */
	public function cleanup( int $now ): int|false {
		if ( ! $this->valid_database() || $now < 1 ) {
			return false;
		}
		$table = $this->database->prefix . 'wc_rate_limits';
		$like  = $this->database->esc_like( self::KEY_PREFIX ) . '%';
		$query = $this->database->prepare(
			"DELETE FROM {$table}
			WHERE `rate_limit_key` LIKE %s
			AND `rate_limit_expiry` <= %d
			LIMIT 1000",
			$like,
			$now
		);
		if ( ! is_string( $query ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded cleanup of this plugin's rows in WooCommerce's rate-limit table.
		$deleted = $this->database->query( $query );
		if ( false === $deleted || $this->has_error() ) {
			return false;
		}
		return (int) $deleted;
	}

	/**
	 * Validate public store inputs before starting a transaction.
	 *
	 * @param string[] $keys   Privacy-hashed dimensions.
	 * @param int      $limit  Maximum requests per window.
	 * @param int      $window Fixed-window length in seconds.
	 * @param int      $now    Current Unix timestamp.
	 */
	private function valid_input( array $keys, int $limit, int $window, int $now ): bool {
		if (
			array() === $keys
			|| count( $keys ) > 4
			|| count( $keys ) !== count( array_unique( $keys ) )
			|| $limit < 1
			|| $limit > 10000
			|| $window < 1
			|| $window > 86400
			|| $now < 1
		) {
			return false;
		}
		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) || 1 !== preg_match( '/^cartpops_[ip]_[a-f0-9]{64}$/D', $key ) ) {
				return false;
			}
		}
		return true;
	}

	/** Whether the injected object provides the required database operations. */
	private function valid_database(): bool {
		return is_object( $this->database )
			&& property_exists( $this->database, 'prefix' )
			&& is_callable( array( $this->database, 'prepare' ) )
			&& is_callable( array( $this->database, 'query' ) )
			&& is_callable( array( $this->database, 'get_results' ) );
	}

	/**
	 * Execute a query that need not report an affected-row count.
	 *
	 * @param string $query Prepared internal query.
	 */
	private function run( string $query ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction and insert/commit authority for the plugin's rate-limit rows.
		$result = $this->database->query( $query );
		return false !== $result && ! $this->has_error();
	}

	/**
	 * Execute a query and return its affected-row count or a failure sentinel.
	 *
	 * @param string $query Prepared internal query.
	 */
	private function run_affected( string $query ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Locked row decrement in the all-or-none rate-limit transaction.
		$result = $this->database->query( $query );
		return false === $result || $this->has_error() ? -1 : (int) $result;
	}

	/** Roll back the active transaction. */
	private function rollback(): bool {
		return $this->run( 'ROLLBACK' );
	}

	/**
	 * Roll back an infrastructure failure and mark only known safe transient
	 * failures for a bounded whole-transaction retry.
	 *
	 * @param bool $allow_retry Whether a known safe transient failure may retry.
	 * @return array{decision: RateLimitDecision, retry: bool}
	 */
	private function transaction_failure( bool $allow_retry = true ): array {
		$retry = $allow_retry && $this->retryable_error();
		if ( ! $this->rollback() ) {
			$retry = false;
		}
		return self::attempt_result( RateLimitDecision::store_unavailable(), $retry );
	}

	/**
	 * Return an internal attempt tuple.
	 *
	 * @param RateLimitDecision $decision Typed public outcome.
	 * @param bool              $retry    Whether the whole transaction may retry.
	 * @return array{decision: RateLimitDecision, retry: bool}
	 */
	private static function attempt_result( RateLimitDecision $decision, bool $retry = false ): array {
		return array(
			'decision' => $decision,
			'retry'    => $retry,
		);
	}

	/** Retry only errors known to leave an uncommitted transaction safe. */
	private function retryable_error(): bool {
		$error = strtolower( $this->last_error() );
		return str_contains( $error, 'deadlock found' ) || str_contains( $error, 'duplicate entry' );
	}

	/** Return the current database error without logging its value. */
	private function last_error(): string {
		$properties = get_object_vars( $this->database );
		return is_string( $properties['last_error'] ?? null ) ? $properties['last_error'] : '';
	}

	/** Whether the database currently reports an error. */
	private function has_error(): bool {
		return '' !== $this->last_error();
	}
}
