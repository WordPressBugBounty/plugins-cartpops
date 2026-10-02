<?php
/**
 * Atomic, uncached persistence for internal network migration records.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Migration;

/**
 * Stores network migration state in the network main site's options table.
 *
 * Unlike wp_sitemeta, wp_options has a unique option_name index. That index is
 * the first-writer primitive used for immutable cohorts and progress records.
 * All correctness reads remain raw and uncached; cache invalidation only keeps
 * incidental get_option() callers from observing stale internal bytes.
 */
final class NetworkOptionStore {

	private const OPTION_PREFIX          = 'cartpops_network_';
	private const BOUNDED_LENGTH_ALIAS   = 'cartpops_network_bounded_length';
	private const BOUNDED_VALUE_ALIAS    = 'cartpops_network_bounded_value';
	private const BOUNDED_AUTOLOAD_ALIAS = 'cartpops_network_bounded_autoload';
	private const BOUNDED_PROBE_ALIAS    = 'cartpops_network_bounded_probe';

	/**
	 * Network whose internal records this instance owns.
	 *
	 * @var int
	 */
	private int $network_id;

	/**
	 * Main site that provides the uniquely indexed options table.
	 *
	 * @var int
	 */
	private int $main_site_id;

	/**
	 * Exact main-site options table.
	 *
	 * @var string
	 */
	private string $table;

	/** Resolve the current network's unique main-site option table. */
	public function __construct() {
		global $wpdb;

		$network_id = CanonicalInteger::parse( get_current_network_id(), 1 );
		$main_site  = null === $network_id ? null : CanonicalInteger::parse( get_main_site_id( $network_id ), 1 );
		$table      = null;
		if ( null !== $main_site && is_object( $wpdb ) && method_exists( $wpdb, 'get_blog_prefix' ) ) {
			$table = $wpdb->get_blog_prefix( $main_site ) . 'options';
		}

		$this->network_id   = $network_id ?? 0;
		$this->main_site_id = $main_site ?? 0;
		$this->table        = is_string( $table ) && 1 === preg_match( '/^[A-Za-z0-9_]+$/D', $table ) ? $table : '';
	}

	/** Return the exact main-site options table, primarily for probes. */
	public function table_name(): string {
		return $this->table;
	}

	/**
	 * Convert a logical network record name to its unique physical option key.
	 *
	 * @param string $option Logical network record name.
	 */
	public function option_name( string $option ): string {
		if (
			$this->network_id < 1
			|| 1 !== preg_match( '/^cartpops_[a-z0-9_]{1,140}$/D', $option )
		) {
			return '';
		}

		return self::OPTION_PREFIX . $this->network_id . '_' . $option;
	}

	/**
	 * Read one internal network option without object deserialization.
	 *
	 * @param string $option Logical network record name.
	 * @return array{success: bool, exists: bool, raw_value: string, autoload: string}
	 */
	public function read( string $option ): array {
		global $wpdb;

		$name = $this->option_name( $option );
		if ( '' === $name || '' === $this->table || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return $this->failed_read();
		}
		/**
		 * Typed database connection.
		 *
		 * @var \wpdb $wpdb
		 */

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact raw bytes and duplicate detection are concurrency invariants.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Validated internal table identifier cannot use a SQL value placeholder.
				"SELECT option_value, autoload FROM {$this->table} WHERE option_name = %s LIMIT 2",
				$name
			),
			ARRAY_A
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

		$row = reset( $rows );
		if (
			! is_array( $row )
			|| ! isset( $row['option_value'], $row['autoload'] )
			|| ! is_string( $row['option_value'] )
			|| ! is_string( $row['autoload'] )
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
	 * Read one internal network option with a database-enforced byte projection.
	 *
	 * @param string $option        Logical network record name.
	 * @param int    $maximum_bytes Maximum admitted value length.
	 * @return array{success: bool, exists: bool, overbound: bool, raw_value: string, autoload: string}
	 */
	public function read_bounded( string $option, int $maximum_bytes ): array {
		global $wpdb;

		$name = $this->option_name( $option );
		if (
			'' === $name
			|| '' === $this->table
			|| $maximum_bytes < 1
			|| PHP_INT_MAX === $maximum_bytes
			|| ! is_object( $wpdb )
			|| ! method_exists( $wpdb, 'get_results' )
			|| ! method_exists( $wpdb, 'prepare' )
		) {
			return $this->failed_bounded_read();
		}
		/**
		 * Typed database connection.
		 *
		 * @var \wpdb $wpdb
		 */

		$bounded_bytes    = $maximum_bytes + 1;
		$wpdb->last_error = '';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Aliases are closed constants, the table was validated, and values use placeholders.
		$query = $wpdb->prepare(
			'SELECT OCTET_LENGTH(option_value) AS ' . self::BOUNDED_LENGTH_ALIAS
				. ', LEFT(BINARY option_value, %d) AS ' . self::BOUNDED_VALUE_ALIAS
				. ', autoload AS ' . self::BOUNDED_AUTOLOAD_ALIAS
				. ', 1 AS ' . self::BOUNDED_PROBE_ALIAS
				. " FROM {$this->table} WHERE option_name = %s LIMIT 2",
			$bounded_bytes,
			$name
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above after closed identifier validation.
		$rows = $wpdb->get_results( $query, ARRAY_A );
		if ( '' !== MigrationDatabaseState::last_error( $wpdb ) || ! is_array( $rows ) || count( $rows ) > 1 ) {
			return $this->failed_bounded_read();
		}
		if ( array() === $rows ) {
			return array(
				'success'   => true,
				'exists'    => false,
				'overbound' => false,
				'raw_value' => '',
				'autoload'  => '',
			);
		}

		$row      = reset( $rows );
		$expected = array(
			self::BOUNDED_AUTOLOAD_ALIAS,
			self::BOUNDED_LENGTH_ALIAS,
			self::BOUNDED_PROBE_ALIAS,
			self::BOUNDED_VALUE_ALIAS,
		);
		$keys     = is_array( $row ) ? array_keys( $row ) : array();
		sort( $keys, SORT_STRING );
		$length   = is_array( $row ) ? CanonicalInteger::parse( $row[ self::BOUNDED_LENGTH_ALIAS ] ?? null, 0 ) : null;
		$probe    = is_array( $row ) ? CanonicalInteger::parse( $row[ self::BOUNDED_PROBE_ALIAS ] ?? null, 1, 1 ) : null;
		$raw      = is_array( $row ) ? ( $row[ self::BOUNDED_VALUE_ALIAS ] ?? null ) : null;
		$autoload = is_array( $row ) ? ( $row[ self::BOUNDED_AUTOLOAD_ALIAS ] ?? null ) : null;
		if (
			$expected !== $keys
			|| null === $length
			|| 1 !== $probe
			|| ! is_string( $raw )
			|| ! is_string( $autoload )
			|| strlen( $raw ) !== min( $length, $bounded_bytes )
		) {
			return $this->failed_bounded_read();
		}

		return array(
			'success'   => true,
			'exists'    => true,
			'overbound' => $length > $maximum_bytes,
			'raw_value' => $raw,
			'autoload'  => $autoload,
		);
	}

	/**
	 * Atomically establish a first-writer record and reload the durable winner.
	 *
	 * @param string $option Logical network record name.
	 * @param mixed  $value  Intended first value.
	 * @return array{success: bool, won: bool, row: array{success: bool, exists: bool, raw_value: string, autoload: string}}
	 */
	public function add_immutable( string $option, mixed $value ): array {
		global $wpdb;

		$name = $this->option_name( $option );
		$raw  = $this->serialize_value( $value );
		if ( '' === $name || '' === $this->table || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
			return array(
				'success' => false,
				'won'     => false,
				'row'     => $this->failed_read(),
			);
		}
		/**
		 * Typed database connection.
		 *
		 * @var \wpdb $wpdb
		 */

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The unique option_name index is the atomic first-writer primitive.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Validated internal table identifier cannot use a SQL value placeholder.
				"INSERT IGNORE INTO {$this->table} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				$name,
				$raw,
				'no'
			)
		);
		if ( 1 === (int) $inserted ) {
			$this->invalidate_cache( $name );
		}

		$row = $this->read( $option );
		return array(
			'success' => $row['success'] && $row['exists'],
			'won'     => $row['success'] && $row['exists'] && hash_equals( $raw, $row['raw_value'] ),
			'row'     => $row,
		);
	}

	/**
	 * Atomically establish a bounded first-writer record and reload its winner.
	 *
	 * @param string $option        Logical network record name.
	 * @param mixed  $value         Intended first value.
	 * @param int    $maximum_bytes Maximum admitted value length.
	 * @return array{success: bool, won: bool, row: array{success: bool, exists: bool, overbound: bool, raw_value: string, autoload: string}}
	 */
	public function add_immutable_bounded( string $option, mixed $value, int $maximum_bytes ): array {
		global $wpdb;

		$name = $this->option_name( $option );
		$raw  = $this->serialize_value( $value );
		if (
			'' === $name
			|| '' === $this->table
			|| $maximum_bytes < 1
			|| PHP_INT_MAX === $maximum_bytes
			|| strlen( $raw ) > $maximum_bytes
			|| ! is_object( $wpdb )
			|| ! method_exists( $wpdb, 'query' )
			|| ! method_exists( $wpdb, 'prepare' )
		) {
			return array(
				'success' => false,
				'won'     => false,
				'row'     => $this->failed_bounded_read(),
			);
		}
		/**
		 * Typed database connection.
		 *
		 * @var \wpdb $wpdb
		 */

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The unique option_name index is the atomic first-writer primitive.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Validated internal table identifier cannot use a SQL value placeholder.
				"INSERT IGNORE INTO {$this->table} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				$name,
				$raw,
				'no'
			)
		);
		if (
			false === $inserted
			|| '' !== MigrationDatabaseState::last_error( $wpdb )
			|| ! in_array( (int) $inserted, array( 0, 1 ), true )
		) {
			return array(
				'success' => false,
				'won'     => false,
				'row'     => $this->failed_bounded_read(),
			);
		}
		if ( 1 === (int) $inserted ) {
			$this->invalidate_cache( $name );
		}

		$row     = $this->read_bounded( $option, $maximum_bytes );
		$matches = $row['success']
			&& $row['exists']
			&& ! $row['overbound']
			&& hash_equals( $raw, $row['raw_value'] );
		return array(
			'success' => $row['success'] && $row['exists'] && ! $row['overbound'],
			'won'     => 1 === (int) $inserted && $matches,
			'row'     => $row,
		);
	}

	/**
	 * Replace a record only while its exact previous bytes still win.
	 *
	 * @param string $option       Logical network record name.
	 * @param string $expected_raw Exact prior database bytes.
	 * @param mixed  $replacement  Replacement value.
	 */
	public function compare_and_swap( string $option, string $expected_raw, mixed $replacement ): bool {
		return $this->write_if_raw( $option, $expected_raw, $this->serialize_value( $replacement ) );
	}

	/**
	 * Replace a network record only while one exact blog still belongs to it.
	 *
	 * The membership predicate and byte CAS execute as one MySQL/MariaDB update,
	 * closing the gap between a post-switch membership read and cursor authority.
	 *
	 * @param string $option       Logical network record name.
	 * @param string $expected_raw Exact prior database bytes.
	 * @param mixed  $replacement  Replacement value.
	 * @param string $blogs_table  Exact validated WordPress blogs table.
	 * @param int    $blog_id      Exact successfully processed blog ID.
	 * @param int    $network_id   Exact intended network ID.
	 */
	public function compare_and_swap_if_site_in_network(
		string $option,
		string $expected_raw,
		mixed $replacement,
		string $blogs_table,
		int $blog_id,
		int $network_id
	): bool {
		global $wpdb;

		$name            = $this->option_name( $option );
		$replacement_raw = $this->serialize_value( $replacement );
		if (
			'' === $name
			|| '' === $this->table
			|| $blog_id <= 0
			|| $network_id !== $this->network_id
			|| 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $blogs_table )
			|| ! is_object( $wpdb )
			|| ! isset( $wpdb->last_error )
			|| ! is_string( $wpdb->last_error )
			|| ! isset( $wpdb->blogs )
			|| $blogs_table !== $wpdb->blogs
			|| ! method_exists( $wpdb, 'query' )
			|| ! method_exists( $wpdb, 'prepare' )
		) {
			return false;
		}
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cursor authority must be atomically conditional on exact persisted site membership.
		$changed = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Both table identifiers are closed and validated; all values use placeholders.
				"UPDATE {$this->table} SET option_value = %s, autoload = %s WHERE option_name = %s AND BINARY option_value = %s AND EXISTS (SELECT 1 FROM {$blogs_table} WHERE blog_id = %d AND site_id = %d)",
				$replacement_raw,
				'no',
				$name,
				$expected_raw,
				$blog_id,
				$network_id
			)
		);
		if ( false === $changed || '' !== MigrationDatabaseState::last_error( $wpdb ) || 1 !== (int) $changed ) {
			return false;
		}

		$this->invalidate_cache( $name );
		$row = $this->read( $option );
		return $row['success']
			&& $row['exists']
			&& hash_equals( $replacement_raw, $row['raw_value'] )
			&& $this->is_non_autoloaded( $row['autoload'] );
	}

	/**
	 * Complete a network record only while its exact remaining keyset is empty.
	 *
	 * @param string $option       Logical network record name.
	 * @param string $expected_raw Exact prior database bytes.
	 * @param mixed  $replacement  Replacement value.
	 * @param string $blogs_table  Exact validated WordPress blogs table.
	 * @param int    $last_site_id Exact successful cursor.
	 * @param int    $max_site_id  Immutable cohort high-water ID.
	 */
	public function compare_and_swap_if_network_range_empty(
		string $option,
		string $expected_raw,
		mixed $replacement,
		string $blogs_table,
		int $last_site_id,
		int $max_site_id
	): bool {
		global $wpdb;

		$name            = $this->option_name( $option );
		$replacement_raw = $this->serialize_value( $replacement );
		if (
			'' === $name
			|| '' === $this->table
			|| $last_site_id < 0
			|| $max_site_id < $last_site_id
			|| 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $blogs_table )
			|| ! is_object( $wpdb )
			|| ! isset( $wpdb->last_error )
			|| ! is_string( $wpdb->last_error )
			|| ! isset( $wpdb->blogs )
			|| $blogs_table !== $wpdb->blogs
			|| ! method_exists( $wpdb, 'query' )
			|| ! method_exists( $wpdb, 'prepare' )
		) {
			return false;
		}
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Terminal network status must be atomically conditional on the exact keyset remaining empty.
		$changed = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Both table identifiers are closed and validated; all values use placeholders.
				"UPDATE {$this->table} SET option_value = %s, autoload = %s WHERE option_name = %s AND BINARY option_value = %s AND NOT EXISTS (SELECT 1 FROM {$blogs_table} WHERE site_id = %d AND blog_id > %d AND blog_id <= %d)",
				$replacement_raw,
				'no',
				$name,
				$expected_raw,
				$this->network_id,
				$last_site_id,
				$max_site_id
			)
		);
		if ( false === $changed || '' !== MigrationDatabaseState::last_error( $wpdb ) || 1 !== (int) $changed ) {
			return false;
		}

		$this->invalidate_cache( $name );
		$row = $this->read( $option );
		return $row['success']
			&& $row['exists']
			&& hash_equals( $replacement_raw, $row['raw_value'] )
			&& $this->is_non_autoloaded( $row['autoload'] );
	}

	/**
	 * Repair autoload only while the caller's exact bytes still win.
	 *
	 * @param string $option       Logical network record name.
	 * @param string $expected_raw Exact prior database bytes.
	 */
	public function repair_autoload( string $option, string $expected_raw ): bool {
		$row = $this->read( $option );
		if ( ! $row['success'] || ! $row['exists'] || ! hash_equals( $expected_raw, $row['raw_value'] ) ) {
			return false;
		}
		if ( $this->is_non_autoloaded( $row['autoload'] ) ) {
			return true;
		}

		return $this->write_if_raw( $option, $expected_raw, $expected_raw );
	}

	/**
	 * Repair autoload while bounding both the comparison and mutation readback.
	 *
	 * @param string $option        Logical network record name.
	 * @param string $expected_raw  Exact prior database bytes.
	 * @param int    $maximum_bytes Maximum admitted value length.
	 */
	public function repair_autoload_bounded( string $option, string $expected_raw, int $maximum_bytes ): bool {
		$row = $this->read_bounded( $option, $maximum_bytes );
		if (
			! $row['success']
			|| ! $row['exists']
			|| $row['overbound']
			|| ! hash_equals( $expected_raw, $row['raw_value'] )
		) {
			return false;
		}
		if ( $this->is_non_autoloaded( $row['autoload'] ) ) {
			return true;
		}

		return $this->write_if_raw( $option, $expected_raw, $expected_raw, $maximum_bytes );
	}

	/**
	 * Delete a record only while its exact bytes still win.
	 *
	 * @param string $option       Logical network record name.
	 * @param string $expected_raw Exact prior database bytes.
	 */
	public function delete_if_raw( string $option, string $expected_raw ): bool {
		global $wpdb;

		$name = $this->option_name( $option );
		if ( '' === $name || '' === $this->table || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
			return false;
		}
		/**
		 * Typed database connection.
		 *
		 * @var \wpdb $wpdb
		 */

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional cleanup must preserve a concurrent successor.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Validated internal table identifier cannot use a SQL value placeholder.
				"DELETE FROM {$this->table} WHERE option_name = %s AND BINARY option_value = %s",
				$name,
				$expected_raw
			)
		);
		if ( false === $deleted || '' !== MigrationDatabaseState::last_error( $wpdb ) || 1 !== (int) $deleted ) {
			return false;
		}

		$this->invalidate_cache( $name );
		$row = $this->read( $option );
		return $row['success'] && ! $row['exists'];
	}

	/**
	 * Convert a value to exact WordPress option bytes.
	 *
	 * @param mixed $value Option value.
	 */
	public function serialize_value( mixed $value ): string {
		$serialized = maybe_serialize( $value );
		return is_scalar( $serialized ) || null === $serialized ? (string) $serialized : '';
	}

	/**
	 * Raw CAS that also establishes the required non-autoload metadata.
	 *
	 * @param string   $option          Logical network record name.
	 * @param string   $expected_raw    Exact prior database bytes.
	 * @param string   $replacement_raw Exact replacement bytes.
	 * @param int|null $maximum_bytes Maximum admitted readback length, or null for the original unbounded contract.
	 */
	private function write_if_raw( string $option, string $expected_raw, string $replacement_raw, ?int $maximum_bytes = null ): bool {
		global $wpdb;

		$name = $this->option_name( $option );
		if ( '' === $name || '' === $this->table || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
			return false;
		}
		/**
		 * Typed database connection.
		 *
		 * @var \wpdb $wpdb
		 */

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Stale network workers must not overwrite a successor generation.
		$changed = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Validated internal table identifier cannot use a SQL value placeholder.
				"UPDATE {$this->table} SET option_value = %s, autoload = %s WHERE option_name = %s AND BINARY option_value = %s",
				$replacement_raw,
				'no',
				$name,
				$expected_raw
			)
		);
		if ( false === $changed || '' !== MigrationDatabaseState::last_error( $wpdb ) || 1 !== (int) $changed ) {
			return false;
		}

		$this->invalidate_cache( $name );
		$row = null === $maximum_bytes
			? $this->read( $option )
			: $this->read_bounded( $option, $maximum_bytes );
		return $row['success']
			&& $row['exists']
			&& ( ! array_key_exists( 'overbound', $row ) || ! $row['overbound'] )
			&& hash_equals( $replacement_raw, $row['raw_value'] )
			&& $this->is_non_autoloaded( $row['autoload'] );
	}

	/**
	 * Invalidate the physical main-site option cache without changing blog state.
	 *
	 * @param string $name Physical option name.
	 */
	private function invalidate_cache( string $name ): void {
		if ( ! function_exists( 'wp_cache_delete' ) || ! function_exists( 'wp_cache_switch_to_blog' ) ) {
			return;
		}

		$current_blog = get_current_blog_id();
		try {
			if ( $current_blog !== $this->main_site_id ) {
				wp_cache_switch_to_blog( $this->main_site_id );
			}
			wp_cache_delete( $name, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		} finally {
			if ( $current_blog !== $this->main_site_id ) {
				wp_cache_switch_to_blog( $current_blog );
			}
		}
	}

	/**
	 * Whether WordPress considers this row non-autoloaded.
	 *
	 * @param string $autoload Exact autoload metadata.
	 */
	public function is_non_autoloaded( string $autoload ): bool {
		return in_array( strtolower( $autoload ), array( 'no', 'off', 'auto-off' ), true );
	}

	/**
	 * Return one canonical failed-read record.
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

	/**
	 * Return the canonical failed bounded-read value.
	 *
	 * @return array{success: bool, exists: bool, overbound: bool, raw_value: string, autoload: string}
	 */
	private function failed_bounded_read(): array {
		return array(
			'success'   => false,
			'exists'    => false,
			'overbound' => false,
			'raw_value' => '',
			'autoload'  => '',
		);
	}
}
