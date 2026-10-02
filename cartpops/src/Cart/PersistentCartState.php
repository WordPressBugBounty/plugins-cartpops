<?php
/**
 * Exact durable basis for one logged-in WooCommerce persistent cart.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Cart;

/**
 * Keeps pre-lock user-meta bytes distinct from WordPress object-cache state.
 */
final class PersistentCartState {
	private const MAX_ROW_COUNT   = 16;
	private const MAX_TOTAL_BYTES = 8388608;

	/**
	 * Store the exact durable persistent-cart basis.
	 *
	 * @param int                                                     $user_id Customer ID.
	 * @param string                                                  $table Validated usermeta table.
	 * @param string                                                  $meta_key Persistent-cart key.
	 * @param array<int, array{umeta_id: string, meta_value: string}> $rows Exact raw durable rows.
	 */
	private function __construct(
		private readonly int $user_id,
		private readonly string $table,
		private readonly string $meta_key,
		private readonly array $rows,
	) {}

	/**
	 * Capture the current logged-in customer's exact persistent-cart rows.
	 *
	 * @throws \RuntimeException When identity or durable bytes cannot be captured exactly.
	 */
	public static function capture_current(): ?self {
		if ( ! is_user_logged_in() ) {
			return null;
		}
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			throw new \RuntimeException( 'WooCommerce persistent cart identity is unavailable.' );
		}

		global $wpdb;
		$table = is_object( $wpdb ) ? ( $wpdb->usermeta ?? null ) : null;
		if ( ! is_string( $table ) || 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $table ) ) {
			throw new \RuntimeException( 'WooCommerce persistent cart storage is unsupported.' );
		}
		$meta_key = '_woocommerce_persistent_cart_' . get_current_blog_id();
		return new self( $user_id, $table, $meta_key, self::read_rows( $table, $user_id, $meta_key ) );
	}

	/** Validated table identifier used by the row/gap lock. */
	public function table(): string {
		return $this->table;
	}

	/** Customer ID used by the row/gap lock. */
	public function user_id(): int {
		return $this->user_id;
	}

	/** Persistent-cart key used by the row/gap lock. */
	public function meta_key(): string {
		return $this->meta_key;
	}

	/**
	 * Reject a row creation/change that occurred while this request waited.
	 *
	 * @throws CartSessionConflictException When the durable basis changed before locking.
	 */
	public function assert_current_basis(): void {
		if ( self::read_rows( $this->table, $this->user_id, $this->meta_key ) !== $this->rows ) {
			throw new CartSessionConflictException( 'The WooCommerce persistent cart changed before its lock was acquired.' );
		}
	}

	/**
	 * Refuse rollback from overwriting any successor persistent cart.
	 *
	 * @throws \RuntimeException When a successor persistent cart already exists.
	 */
	public function assert_current_for_restore(): void {
		if ( self::read_rows( $this->table, $this->user_id, $this->meta_key ) !== $this->rows ) {
			throw new \RuntimeException( 'A newer WooCommerce persistent cart superseded this request.' );
		}
		$this->evict_cache();
	}

	/** Evict object-cache state without guessing durable meta bytes. */
	public function evict_cache(): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( (string) $this->user_id, 'user_meta' );
		}
	}

	/**
	 * Read exact bounded rows directly from the durable usermeta table.
	 *
	 * @param string $table Validated core usermeta table.
	 * @param int    $user_id Exact customer ID.
	 * @param string $meta_key Exact persistent-cart meta key.
	 * @return array<int, array{umeta_id: string, meta_value: string}>
	 * @throws \RuntimeException When exact bounded durable rows cannot be read.
	 */
	private static function read_rows( string $table, int $user_id, string $meta_key ): array {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_results' ) ) {
			throw new \RuntimeException( 'WooCommerce persistent cart database is unavailable.' );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The validated core table is the exact persistence boundary.
		$sql = $wpdb->prepare( "SELECT umeta_id, meta_value FROM {$table} WHERE user_id = %d AND meta_key = %s ORDER BY umeta_id ASC", $user_id, $meta_key );
		self::clear_database_error( $wpdb );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Exact uncached read of a prepared query.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( '' !== self::database_error( $wpdb ) || ! is_array( $rows ) || count( $rows ) > self::MAX_ROW_COUNT ) {
			throw new \RuntimeException( 'WooCommerce persistent cart rows could not be read exactly.' );
		}

		$result      = array();
		$total_bytes = 0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! is_scalar( $row['umeta_id'] ?? null ) || ! is_string( $row['meta_value'] ?? null ) ) {
				throw new \RuntimeException( 'WooCommerce persistent cart rows have an unsupported shape.' );
			}
			$total_bytes += strlen( $row['meta_value'] );
			if ( $total_bytes > self::MAX_TOTAL_BYTES ) {
				throw new \RuntimeException( 'WooCommerce persistent cart rows exceed safe bounds.' );
			}
			$result[] = array(
				'umeta_id'   => (string) $row['umeta_id'],
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Array key names the already-bounded selected database field.
				'meta_value' => $row['meta_value'],
			);
		}
		return $result;
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
}
