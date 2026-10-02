<?php
/**
 * WordPress option-table persistence for the CartPops system reward identifier.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Cart;

/**
 * Performs bounded, site-local exact-row operations without consulting caches.
 */
final class WordPressSystemRewardCouponOptionStore implements SystemRewardCouponOptionStore {
	/**
	 * WordPress' current database service.
	 *
	 * @var object|null
	 */
	private readonly ?object $database;

	/**
	 * Constructor.
	 *
	 * @param object|null $database Explicit wpdb-compatible service, or WordPress' current service.
	 */
	public function __construct( ?object $database = null ) {
		if ( null === $database ) {
			global $wpdb;
			$database = is_object( $wpdb ) ? $wpdb : null;
		}

		$this->database = $database;
	}

	/**
	 * Read the exact serialized bytes for one current-site option.
	 *
	 * @param string $name Site-local option name.
	 * @return string|null|false Raw bytes, null when absent, or false on failure.
	 */
	public function read_raw( string $name ): string|false|null {
		$database = $this->database;
		if ( ! $this->supports( $database, 'get_var' ) ) {
			return false;
		}

		try {
			$database->last_error = '';
			$query                = $database->prepare(
				"SELECT option_value FROM {$database->options} WHERE option_name = %s LIMIT 1",
				$name
			);
			if ( ! is_string( $query ) ) {
				return false;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Exact ownership requires the uncached prepared row read above.
			$raw = $database->get_var( $query );
		} catch ( \Throwable ) {
			return false;
		}

		if ( ! empty( $database->last_error ) ) {
			return false;
		}

		return null === $raw ? null : (string) $raw;
	}

	/**
	 * Add one current-site option with autoload disabled.
	 *
	 * @param string $name  Site-local option name.
	 * @param mixed  $value Option value.
	 */
	public function add_non_autoloaded( string $name, mixed $value ): bool {
		$database = $this->database;
		if ( ! $this->supports( $database, 'query' ) || ! function_exists( 'maybe_serialize' ) ) {
			return false;
		}

		try {
			$database->last_error = '';
			$query                = $database->prepare(
				"INSERT IGNORE INTO {$database->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				$name,
				maybe_serialize( $value ),
				'no'
			);
			if ( ! is_string( $query ) ) {
				return false;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Atomic unique insertion is the reward-identifier initialization fence.
			$inserted = $database->query( $query );
		} catch ( \Throwable ) {
			$this->invalidate_cache( $name );
			return false;
		}
		$this->invalidate_cache( $name );

		if ( 1 !== (int) $inserted || ! empty( $database->last_error ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Replace only the exact serialized row previously observed.
	 *
	 * @param string $name         Site-local option name.
	 * @param string $expected_raw Exact serialized bytes previously read.
	 * @param mixed  $replacement  Replacement option value.
	 */
	public function compare_replace_non_autoloaded( string $name, string $expected_raw, mixed $replacement ): bool {
		$database = $this->database;
		if ( ! $this->supports( $database, 'query' ) || ! function_exists( 'maybe_serialize' ) ) {
			return false;
		}

		try {
			$database->last_error = '';
			$query                = $database->prepare(
				"UPDATE {$database->options} SET option_value = %s, autoload = %s WHERE option_name = %s AND BINARY option_value = %s",
				maybe_serialize( $replacement ),
				'no',
				$name,
				$expected_raw
			);
			if ( ! is_string( $query ) ) {
				return false;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The prepared exact-byte compare-and-swap prevents stale replacement.
			$updated = $database->query( $query );
		} catch ( \Throwable ) {
			$this->invalidate_cache( $name );
			return false;
		}
		$this->invalidate_cache( $name );

		if ( 1 !== (int) $updated || ! empty( $database->last_error ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Delete only the exact serialized row previously observed.
	 *
	 * @param string $name         Site-local option name.
	 * @param string $expected_raw Exact serialized bytes previously read.
	 */
	public function compare_delete( string $name, string $expected_raw ): bool {
		$database = $this->database;
		if ( ! $this->supports( $database, 'query' ) ) {
			return false;
		}

		try {
			$database->last_error = '';
			$query                = $database->prepare(
				"DELETE FROM {$database->options} WHERE option_name = %s AND BINARY option_value = %s",
				$name,
				$expected_raw
			);
			if ( ! is_string( $query ) ) {
				return false;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The prepared exact-byte comparison prevents stale lock deletion.
			$deleted = $database->query( $query );
		} catch ( \Throwable ) {
			$this->invalidate_cache( $name );
			return false;
		}
		$this->invalidate_cache( $name );

		if ( 1 !== (int) $deleted || ! empty( $database->last_error ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Evict every WordPress option-cache shape after a possible write outcome.
	 *
	 * @param string $name Site-local option name.
	 */
	private function invalidate_cache( string $name ): void {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}

		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Whether the database exposes the exact wpdb operation this store needs.
	 *
	 * @param object|null $database  Candidate database service.
	 * @param string      $operation Required operation.
	 */
	private function supports( ?object $database, string $operation ): bool {
		return null !== $database
			&& isset( $database->options )
			&& is_string( $database->options )
			&& '' !== $database->options
			&& is_callable( array( $database, 'prepare' ) )
			&& is_callable( array( $database, $operation ) );
	}
}
