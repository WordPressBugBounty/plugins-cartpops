<?php
/**
 * Exact, uncached option persistence for the legacy migration.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Migration;

use CartPops\Setup\SiteUpgradeContext;

/**
 * Keeps migration commits independent from WordPress' option object cache.
 */
final class MigrationOptionStore {

	/**
	 * Exact request-local site/database binding.
	 *
	 * @var SiteUpgradeContext
	 */
	private readonly SiteUpgradeContext $context;

	/**
	 * Whether cache invalidations must wait for transaction classification.
	 *
	 * @var bool
	 */
	private bool $defer_cache_invalidation = false;

	/**
	 * Option names awaiting post-commit invalidation.
	 *
	 * @var array<string, true>
	 */
	private array $deferred_cache_options = array();

	/**
	 * Bind every option operation to one captured current site.
	 *
	 * @param SiteUpgradeContext|null $context Existing whole-convergence binding.
	 */
	public function __construct( ?SiteUpgradeContext $context = null ) {
		$this->context = $context ?? SiteUpgradeContext::capture();
	}

	/**
	 * Whether this store is bound to the exact same request-local site context.
	 *
	 * @param SiteUpgradeContext $context Candidate exact site context.
	 */
	public function is_bound_to( SiteUpgradeContext $context ): bool {
		return $this->context === $context;
	}

	/**
	 * Read one option row without invoking WordPress deserialization.
	 *
	 * A null row is a successful "missing" result only when the database did
	 * not report an error for this exact query.
	 *
	 * @param string $option Option name.
	 * @return array{success: bool, exists: bool, raw_value: string, autoload: string}
	 */
	public function read( string $option ): array {
		$wpdb  = $this->context->database();
		$table = $this->context->options_table();

		if ( ! method_exists( $wpdb, 'get_row' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return $this->failed_read();
		}
		/**
		 * Typed database connection.
		 *
		 * @var \wpdb $wpdb
		 */

		$row = $this->context->guard(
			static function () use ( $wpdb, $table, $option ): mixed {
				$wpdb->last_error = '';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact uncached migration read.
				return $wpdb->get_row(
					// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table is the immutable validated context target; the value is prepared.
					$wpdb->prepare(
						"SELECT option_value, autoload FROM {$table} WHERE option_name = %s LIMIT 1",
						$option
					),
					// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					ARRAY_A
				);
			}
		);

		if ( '' !== MigrationDatabaseState::last_error( $wpdb ) ) {
			return $this->failed_read();
		}

		if ( null === $row ) {
			return array(
				'success'   => true,
				'exists'    => false,
				'raw_value' => '',
				'autoload'  => '',
			);
		}

		if ( ! is_array( $row ) || ! array_key_exists( 'option_value', $row ) || ! array_key_exists( 'autoload', $row ) ) {
			return $this->failed_read();
		}

		return array(
			'success'   => true,
			'exists'    => true,
			'raw_value' => is_scalar( $row['option_value'] ) ? (string) $row['option_value'] : '',
			'autoload'  => is_scalar( $row['autoload'] ) ? (string) $row['autoload'] : '',
		);
	}

	/**
	 * Read one option while copying at most a fixed number of LONGTEXT bytes.
	 *
	 * Oversized, duplicate, malformed, and uncertain rows all fail closed. The
	 * CASE expression prevents an oversized value from entering PHP memory.
	 *
	 * @param string $option    Option name.
	 * @param int    $max_bytes Maximum accepted raw bytes.
	 * @return array{success: bool, exists: bool, raw_value: string, autoload: string}
	 */
	public function read_bounded( string $option, int $max_bytes ): array {
		$wpdb  = $this->context->database();
		$table = $this->context->options_table();

		if ( $max_bytes < 1 || $max_bytes > 4194304 || ! method_exists( $wpdb, 'get_results' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return $this->failed_read();
		}
		/**
		 * Typed database connection.
		 *
		 * @var \wpdb $wpdb
		 */
		$length = MigrationDatabaseState::received_octet_length_sql( $wpdb, 'option_value' );
		$rows   = $this->context->guard(
			static function () use ( $wpdb, $table, $option, $max_bytes, $length ): mixed {
				$wpdb->last_error = '';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact bounded uncached migration read.
				return $wpdb->get_results(
					// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table is the immutable validated context target; the bound and value are prepared.
					$wpdb->prepare(
						"SELECT CASE WHEN {$length} <= %d THEN option_value ELSE NULL END AS option_value, {$length} AS cartpops_migration_option_bytes, autoload FROM {$table} WHERE option_name = %s LIMIT 2",
						$max_bytes,
						$option
					),
					// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					ARRAY_A
				);
			}
		);

		if ( '' !== MigrationDatabaseState::last_error( $wpdb ) || ! is_array( $rows ) || count( $rows ) > 1 ) {
			return $this->failed_read();
		}
		if ( array() === $rows ) {
			return array(
				'success'   => true,
				'exists'    => false,
				'raw_value' => '',
				'autoload'  => '',
			);
		}

		$row    = reset( $rows );
		$length = is_array( $row )
			? CanonicalInteger::parse( $row['cartpops_migration_option_bytes'] ?? null, 0, $max_bytes )
			: null;
		if (
			! is_array( $row )
			|| array( 'autoload', 'cartpops_migration_option_bytes', 'option_value' ) !== $this->sorted_keys( $row )
			|| null === $length
			|| ! is_string( $row['option_value'] ?? null )
			|| strlen( $row['option_value'] ) !== $length
			|| ! is_string( $row['autoload'] ?? null )
		) {
			return $this->failed_read();
		}

		return array(
			'success'   => true,
			'exists'    => true,
			'raw_value' => $row['option_value'],
			'autoload'  => $row['autoload'],
		);
	}

	/**
	 * Persist and verify value bytes plus autoload metadata.
	 *
	 * Update_option() returning false can mean either an identical value or a
	 * failed write, so only the raw readback is authoritative.
	 *
	 * @param string   $option   Option name.
	 * @param mixed    $value    Exact intended value.
	 * @param bool     $autoload Required autoload policy.
	 * @param int|null $read_limit Optional maximum readback bytes.
	 */
	public function persist( string $option, mixed $value, bool $autoload, ?int $read_limit = null ): bool {
		$row = $this->read_with_limit( $option, $read_limit );
		if ( ! $row['success'] ) {
			return false;
		}
		if ( ! $row['exists'] ) {
			$inserted = $this->insert_if_absent( $option, $value, $autoload, $read_limit );
			if ( ! $inserted['success'] ) {
				return false;
			}
			$row = $inserted['row'];
		}

		$raw = $this->serialize_value( $value );
		if ( hash_equals( $raw, $row['raw_value'] ) && $this->autoload_matches( $row['autoload'], $autoload ) ) {
			return true;
		}

		// Use exact bytes as a CAS so a stale writer cannot overwrite a
		// concurrent option change or retarget through WordPress callbacks.
		return $this->compare_and_swap( $option, $row['raw_value'], $value, $autoload, $read_limit );
	}

	/**
	 * Atomically establish an immutable first-writer option.
	 *
	 * @param string   $option   Option name.
	 * @param mixed    $value    Exact intended value.
	 * @param bool     $autoload Required autoload policy.
	 * @param int|null $read_limit Optional maximum readback bytes.
	 * @return array{success: bool, won: bool, row: array{success: bool, exists: bool, raw_value: string, autoload: string}}
	 */
	public function add_immutable( string $option, mixed $value, bool $autoload, ?int $read_limit = null ): array {
		return $this->insert_if_absent( $option, $value, $autoload, $read_limit );
	}

	/**
	 * Insert one option through the unique option-name index without invoking
	 * WordPress' cache-mutating option API.
	 *
	 * @param string   $option   Option name.
	 * @param mixed    $value    Exact intended value.
	 * @param bool     $autoload Required autoload policy.
	 * @param int|null $read_limit Optional maximum readback bytes.
	 * @return array{success: bool, won: bool, row: array{success: bool, exists: bool, raw_value: string, autoload: string}}
	 */
	public function insert_if_absent( string $option, mixed $value, bool $autoload, ?int $read_limit = null ): array {
		$wpdb  = $this->context->database();
		$table = $this->context->options_table();

		if ( ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return array(
				'success' => false,
				'won'     => false,
				'row'     => $this->failed_read(),
			);
		}
		/**
		 * WordPress database adapter.
		 *
		 * @var \wpdb $wpdb
		 */
		$raw      = $this->serialize_value( $value );
		$autoload = $autoload ? 'yes' : 'no';
		$inserted = $this->context->guard(
			static function () use ( $wpdb, $table, $option, $raw, $autoload ): mixed {
				$wpdb->last_error = '';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact first-writer mutation.
				return $wpdb->query(
					// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The immutable context table and unique option-name index provide the first-writer primitive.
					$wpdb->prepare(
						"INSERT IGNORE INTO {$table} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
						$option,
						$raw,
						$autoload
					)
					// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				);
			}
		);
		if ( false === $inserted || '' !== MigrationDatabaseState::last_error( $wpdb ) ) {
			return array(
				'success' => false,
				'won'     => false,
				'row'     => $this->failed_read(),
			);
		}
		if ( 1 === (int) $inserted ) {
			$this->invalidate_option_cache( $option );
		}
		$row = $this->read_with_limit( $option, $read_limit );
		return array(
			'success' => $row['success'] && $row['exists'],
			'won'     => 1 === (int) $inserted && $row['exists'] && hash_equals( $raw, $row['raw_value'] ),
			'row'     => $row,
		);
	}

	/** Begin collecting cache invalidations until transaction outcome is known. */
	public function defer_cache_invalidation(): void {
		$this->defer_cache_invalidation = true;
		$this->deferred_cache_options   = array();
	}

	/** Publish cache invalidations after a verified transaction commit. */
	public function commit_cache_invalidation(): void {
		$options                        = array_keys( $this->deferred_cache_options );
		$this->defer_cache_invalidation = false;
		$this->deferred_cache_options   = array();
		foreach ( $options as $option ) {
			$this->invalidate_option_cache( $option, true );
		}
	}

	/** Discard invalidations after rollback or an uncertain failed operation. */
	public function discard_cache_invalidation(): void {
		$this->defer_cache_invalidation = false;
		$this->deferred_cache_options   = array();
	}

	/**
	 * Atomically replace one exact raw option value.
	 *
	 * @param string   $option      Option name.
	 * @param string   $expected_raw Exact current database bytes.
	 * @param mixed    $replacement Replacement value.
	 * @param bool     $autoload    Required autoload policy.
	 * @param int|null $read_limit Optional maximum readback bytes.
	 */
	public function compare_and_swap( string $option, string $expected_raw, mixed $replacement, bool $autoload, ?int $read_limit = null ): bool {
		$wpdb  = $this->context->database();
		$table = $this->context->options_table();

		if ( ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return false;
		}
		/**
		 * Typed database connection.
		 *
		 * @var \wpdb $wpdb
		 */

		$replacement_raw = $this->serialize_value( $replacement );
		$autoload_value  = $autoload ? 'yes' : 'no';
		$changed         = $this->context->guard(
			static function () use ( $wpdb, $table, $replacement_raw, $autoload_value, $option, $expected_raw ): mixed {
				$wpdb->last_error = '';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact CAS mutation.
				return $wpdb->query(
					// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The immutable context table and exact bytes form an atomic compare-and-swap.
					$wpdb->prepare(
						"UPDATE {$table} SET option_value = %s, autoload = %s WHERE option_name = %s AND BINARY option_value = %s",
						$replacement_raw,
						$autoload_value,
						$option,
						$expected_raw
					)
					// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				);
			}
		);

		if ( false === $changed || '' !== MigrationDatabaseState::last_error( $wpdb ) ) {
			return false;
		}
		$this->invalidate_option_cache( $option );

		$row = $this->read_with_limit( $option, $read_limit );
		return $row['success']
			&& $row['exists']
			&& $replacement_raw === $row['raw_value']
			&& $this->autoload_matches( $row['autoload'], $autoload );
	}

	/**
	 * Remove an option only while this request still owns its exact value.
	 *
	 * @param string $option       Option name.
	 * @param string $expected_raw Exact owner-token bytes.
	 */
	public function delete_if_raw( string $option, string $expected_raw ): bool {
		$wpdb  = $this->context->database();
		$table = $this->context->options_table();

		if ( ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return false;
		}
		/**
		 * Typed database connection.
		 *
		 * @var \wpdb $wpdb
		 */

		$deleted = $this->context->guard(
			static function () use ( $wpdb, $table, $option, $expected_raw ): mixed {
				$wpdb->last_error = '';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact conditional delete.
				return $wpdb->query(
					// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The immutable context table and exact bytes protect a concurrent successor.
					$wpdb->prepare(
						"DELETE FROM {$table} WHERE option_name = %s AND BINARY option_value = %s",
						$option,
						$expected_raw
					)
					// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				);
			}
		);

		if ( false === $deleted || '' !== MigrationDatabaseState::last_error( $wpdb ) ) {
			return false;
		}
		if ( 0 < (int) $deleted ) {
			$this->invalidate_option_cache( $option );
			return true;
		}

		// A zero-row result can mean either an already absent option or that a
		// concurrent successor won the compare-and-delete race. Only the former
		// satisfies a caller that requires the exact row to be gone.
		$row = $this->read( $option );
		return $row['success'] && ! $row['exists'];
	}

	/**
	 * Convert an option value to the exact bytes WordPress stores.
	 *
	 * @param mixed $value Option value.
	 */
	public function serialize_value( mixed $value ): string {
		$serialized = maybe_serialize( $value );
		return is_scalar( $serialized ) || null === $serialized ? (string) $serialized : '';
	}

	/**
	 * Report whether raw metadata matches the requested explicit policy.
	 *
	 * @param string $stored   Raw autoload metadata.
	 * @param bool   $expected Required autoload policy.
	 */
	public function autoload_is( string $stored, bool $expected ): bool {
		return $this->autoload_matches( $stored, $expected );
	}

	/**
	 * Check explicit autoload intent across supported WordPress encodings.
	 *
	 * @param string $stored   Raw autoload metadata.
	 * @param bool   $expected Required autoload policy.
	 */
	private function autoload_matches( string $stored, bool $expected ): bool {
		$enabled  = array( 'yes', 'on', 'auto-on' );
		$disabled = array( 'no', 'off', 'auto-off' );
		return in_array( strtolower( $stored ), $expected ? $enabled : $disabled, true );
	}

	/**
	 * Select the ordinary or bounded read contract for one persistence readback.
	 *
	 * @param string   $option     Option name.
	 * @param int|null $read_limit Optional maximum exact bytes.
	 * @return array{success: bool, exists: bool, raw_value: string, autoload: string}
	 */
	private function read_with_limit( string $option, ?int $read_limit ): array {
		return null === $read_limit
			? $this->read( $option )
			: $this->read_bounded( $option, $read_limit );
	}

	/**
	 * Return stable keys for strict raw-row validation.
	 *
	 * @param array<mixed> $value Raw row.
	 * @return array<int|string>
	 */
	private function sorted_keys( array $value ): array {
		$keys = array_keys( $value );
		sort( $keys, SORT_STRING );
		return $keys;
	}

	/**
	 * Invalidate the exact option and both aggregate option-cache entries.
	 *
	 * @param string $option     Option name.
	 * @param bool   $postcommit Whether the database transaction already closed.
	 */
	private function invalidate_option_cache( string $option, bool $postcommit = false ): void {
		if ( $this->defer_cache_invalidation ) {
			$this->deferred_cache_options[ $option ] = true;
			return;
		}
		if ( function_exists( 'wp_cache_delete' ) ) {
			$guard = $postcommit
				? $this->context->guard_postcommit_cache( ... )
				: $this->context->guard( ... );
			$guard( static fn(): bool => wp_cache_delete( $option, 'options' ) );
			$guard( static fn(): bool => wp_cache_delete( 'alloptions', 'options' ) );
			$guard( static fn(): bool => wp_cache_delete( 'notoptions', 'options' ) );
		}
	}

	/**
	 * Return the canonical failed read value.
	 *
	 * @return array{success: bool, exists: bool, raw_value: string, autoload: string}
	 */
	private function failed_read(): array {
		return array(
			'success'   => false,
			'exists'    => false,
			'raw_value' => '',
			'autoload'  => '',
		);
	}
}
