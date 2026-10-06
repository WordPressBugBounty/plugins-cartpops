<?php
/**
 * Transaction fence for the final V1 compatibility decision.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Migration;

use CartPops\Database\DatabaseCommitOutcomeUnknownException;
use CartPops\Database\OwnedTransaction;
use CartPops\Setup\SiteUpgradeContext;

/**
 * Locks the complete legacy-rule equality range, both surrounding gaps, and
 * every option that can influence terminal migration state before committing.
 */
final class LegacyCompatibilityTerminalFence {

	/**
	 * Exact request-local site/database binding.
	 *
	 * @var SiteUpgradeContext
	 */
	private readonly SiteUpgradeContext $context;

	private const MAX_RULE_POSTS                 = 500;
	private const LEGACY_POST_TYPE               = 'cartpops_rules';
	private const MAX_OPTION_BYTES               = 4194304;
	private const MAX_OPTION_TOTAL_BYTES         = 16777216;
	private const MAX_COMPATIBILITY_OPTION_BYTES = 1048576;
	private const MAX_COMPATIBILITY_TOTAL_BYTES  = 2097152;
	private const LOCK_WAIT_TIMEOUT              = 5;

	/**
	 * Exact transaction currently holding the terminal-fence locks.
	 *
	 * @var OwnedTransaction|null
	 */
	private ?OwnedTransaction $transaction = null;

	/**
	 * Code-defined reason the last run() failed before its operation decided.
	 *
	 * @var string
	 */
	private static string $last_failure_reason = '';

	/** Why the last run() failed on its own, or '' when it did not. */
	public static function last_failure_reason(): string {
		return self::$last_failure_reason;
	}

	/**
	 * Record why the fence itself failed.
	 *
	 * @param string $reason Code-defined reason.
	 */
	private function fail( string $reason ): MigrationOutcome {
		self::$last_failure_reason = $reason;
		return MigrationOutcome::FAILED;
	}

	/**
	 * Bind the terminal transaction to one captured current site.
	 *
	 * @param SiteUpgradeContext $context Exact whole-convergence binding.
	 */
	public function __construct( SiteUpgradeContext $context ) {
		$this->context = $context;
	}

	/**
	 * Prove that multisite membership can be held through a later transaction.
	 *
	 * This preflight runs before journalled site writes. The terminal fence repeats
	 * the same proof while holding the membership row lock.
	 */
	public function membership_lock_is_transactional(): bool {
		self::$last_failure_reason = '';
		$blogs_table               = $this->context->blogs_table();
		if ( '' === $blogs_table ) {
			return true;
		}
		$blogs_table = $this->table_identifier( $blogs_table );
		return null !== $blogs_table && $this->transactional_tables_are_proven( array( $blogs_table ) );
	}

	/**
	 * Lock and prove every table used by one multisite site mutation.
	 *
	 * The caller must already own and bind the surrounding transaction.
	 */
	public function prepare_site_mutation_transaction(): bool {
		$transaction = MigrationDatabaseState::transaction();
		if ( null === $transaction || ! $transaction->is_active() ) {
			return false;
		}
		$options_table = $this->table_identifier( $this->context->options_table() );
		$posts_table   = $this->table_identifier( $this->context->posts_table() );
		$blogs_table   = $this->table_identifier( $this->context->blogs_table() );
		return null !== $options_table
			&& null !== $posts_table
			&& null !== $blogs_table
			&& $this->acquire_metadata_locks( $options_table, $posts_table )
			&& null !== $this->database_proof( $options_table, $posts_table, $blogs_table );
	}

	/**
	 * Execute one terminal mutation inside the verified compatibility fence.
	 *
	 * @param string[]             $option_names Exact option rows/gaps to lock.
	 * @param \Closure             $operation    Transactional mutation.
	 * @param MigrationOptionStore $store        Exact option-row persistence adapter.
	 * @phpstan-param \Closure(bool, array<string, array{raw_value: string, autoload: string}>, bool): MigrationOutcome $operation
	 */
	public function run( array $option_names, \Closure $operation, MigrationOptionStore $store ): MigrationOutcome {
		$wpdb                      = $this->context->database();
		$this->transaction         = null;
		$owns_transaction          = false;
		self::$last_failure_reason = '';

		if ( ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'get_results' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return $this->fail( 'fence_database_adapter' );
		}
		/**
		 * WordPress database adapter.
		 *
		 * @var \wpdb $wpdb
		 */
		$options_table = $this->table_identifier( $this->context->options_table() );
		$posts_table   = $this->table_identifier( $this->context->posts_table() );
		$blogs_table   = '' === $this->context->blogs_table()
			? null
			: $this->table_identifier( $this->context->blogs_table() );
		if ( null === $options_table || null === $posts_table || ( '' !== $this->context->blogs_table() && null === $blogs_table ) ) {
			return $this->fail( 'fence_table_names' );
		}
		$transaction = MigrationDatabaseState::transaction();
		if ( null === $transaction ) {
			if ( ! $this->begin_transaction() ) {
				return $this->fail( 'fence_transaction_begin' );
			}
			$transaction      = $this->owned_transaction();
			$owns_transaction = true;
			MigrationDatabaseState::bind( $transaction );
			$store->defer_cache_invalidation();
		} else {
			$this->transaction = $transaction;
			try {
				$transaction->assert_active();
			} catch ( \Throwable ) {
				return $this->fail( 'fence_transaction_inactive' );
			}
		}
		$result = MigrationOutcome::FAILED;

		try {
			if ( ! $this->acquire_metadata_locks( $options_table, $posts_table ) ) {
				if ( $owns_transaction ) {
					$this->rollback( $store );
				}
				$result = $this->fail( 'fence_metadata_lock' );
			} else {
				$indexes = $this->database_proof( $options_table, $posts_table, $blogs_table );
				if ( null === $indexes ) {
					if ( $owns_transaction ) {
						$this->rollback( $store );
					}
					// database_proof() recorded whether tables or indexes were the cause.
					$result = MigrationOutcome::FAILED;
				} else {
					$locked    = $this->lock_options( $options_table, $indexes['options'], $option_names );
					$has_rules = $this->lock_rule_range( $posts_table, $indexes['posts'] );
					$outcome   = $transaction->guard(
						static fn(): MigrationOutcome => $operation( $has_rules, $locked['rows'], $locked['compatibility_overbound'] )
					);
					$transaction->assert_active();
					if ( ! in_array( $outcome, array( MigrationOutcome::COMPLETE, MigrationOutcome::NOT_APPLICABLE, MigrationOutcome::MAINTENANCE_REQUIRED ), true ) ) {
						$result = ( ! $owns_transaction || $this->rollback( $store ) ) ? $outcome : MigrationOutcome::FAILED;
					} elseif ( ! $owns_transaction ) {
						$result = $outcome;
					} else {
						try {
							$transaction->commit();
							$store->commit_cache_invalidation();
							$result = $outcome;
						} catch ( DatabaseCommitOutcomeUnknownException ) {
							$store->commit_cache_invalidation();
							MigrationDatabaseState::release( $transaction );
							$result = $this->fail( 'fence_commit_outcome_unknown' );
						} catch ( \Throwable ) {
							$this->rollback( $store );
							$result = $this->fail( 'fence_commit_failed' );
						}
					}
				}
			}
		} catch ( \Throwable ) {
			if ( $owns_transaction ) {
				$this->rollback( $store );
			}
			$result = $this->fail( 'fence_exception' );
		} finally {
			if ( $owns_transaction ) {
				MigrationDatabaseState::release( $transaction );
				if ( $transaction->is_active() ) {
					$this->rollback( $store );
				}
			}
		}

		return $result;
	}

	/**
	 * Establish a bounded, portable next transaction without adopting a caller's
	 * session transaction or changing its persistent isolation default.
	 */
	private function begin_transaction(): bool {
		$wpdb = $this->context->database();
		try {
			$this->transaction = OwnedTransaction::begin( $wpdb, 'REPEATABLE READ', self::LOCK_WAIT_TIMEOUT );
			return true;
		} catch ( \Throwable ) {
			$this->transaction = null;
			return false;
		}
	}

	/**
	 * Acquire transaction-scoped metadata locks for both participating tables.
	 *
	 * @param string $options_table Validated options table identifier.
	 * @param string $posts_table   Validated posts table identifier.
	 */
	private function acquire_metadata_locks( string $options_table, string $posts_table ): bool {
		$wpdb = $this->context->database();

		foreach ( array( $options_table, $posts_table ) as $table ) {
			$rows = $this->context->guard(
				static function () use ( $wpdb, $table ): mixed {
					$wpdb->last_error = '';
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Validated table identifiers; zero-row locking reads hold metadata locks through the transaction.
					return $wpdb->get_results( "SELECT 1 AS cartpops_compatibility_fence_mdl FROM {$table} LIMIT 0 FOR UPDATE", ARRAY_A );
				}
			);
			if ( '' !== MigrationDatabaseState::last_error( $wpdb ) || ! is_array( $rows ) || array() !== $rows ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Verify transactional engines and required locking indexes.
	 *
	 * @param string      $options_table Validated options table identifier.
	 * @param string      $posts_table   Validated posts table identifier.
	 * @param string|null $blogs_table   Validated multisite membership table, when applicable.
	 * @return array{options: string, posts: string}|null
	 */
	private function database_proof( string $options_table, string $posts_table, ?string $blogs_table ): ?array {
		$table_names = array( $options_table, $posts_table );
		if ( null !== $blogs_table ) {
			$table_names[] = $blogs_table;
		}
		if ( ! $this->transactional_tables_are_proven( $table_names ) ) {
			// That check recorded whether an engine is non-transactional or unverified.
			return null;
		}
		$options_index = $this->find_index( $options_table, 'option_name', true );
		$posts_index   = $this->find_index( $posts_table, 'post_type', false );
		if ( null === $options_index || null === $posts_index ) {
			self::$last_failure_reason = 'database_index_missing';
		}
		return null === $options_index || null === $posts_index
			? null
			: array(
				'options' => $options_index,
				'posts'   => $posts_index,
			);
	}

	/**
	 * Prove an exact validated table set exists once and uses InnoDB.
	 *
	 * @param string[] $table_names Exact validated table identifiers.
	 */
	private function transactional_tables_are_proven( array $table_names ): bool {
		$wpdb    = $this->context->database();
		$engines = $this->context->guard(
			static function () use ( $wpdb, $table_names ): mixed {
				$wpdb->last_error = '';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Every mutated or authority-locking table must have a transactional engine.
				return $wpdb->get_results(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The placeholder count is closed by the validated table-name list.
						'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode( ', ', array_fill( 0, count( $table_names ), '%s' ) ) . ')',
						...$table_names
					),
					ARRAY_A
				);
			}
		);
		// Unless a table really reports another engine, the cause is unverified, not MyISAM.
		self::$last_failure_reason = 'database_engine_unverified';
		if ( '' !== MigrationDatabaseState::last_error( $wpdb ) || ! is_array( $engines ) || count( $table_names ) !== count( $engines ) ) {
			return false;
		}
		$seen = array();
		foreach ( $engines as $row ) {
			if ( ! is_array( $row ) || array( 'ENGINE', 'TABLE_NAME' ) !== $this->sorted_keys( $row ) || ! is_string( $row['TABLE_NAME'] ?? null ) || isset( $seen[ $row['TABLE_NAME'] ] ) ) {
				return false;
			}
			if ( 'INNODB' !== strtoupper( (string) ( $row['ENGINE'] ?? '' ) ) ) {
				self::$last_failure_reason = 'database_not_transactional';
				return false;
			}
			$seen[ $row['TABLE_NAME'] ] = true;
		}
		foreach ( $table_names as $table_name ) {
			if ( ! isset( $seen[ $table_name ] ) ) {
				return false;
			}
		}
		self::$last_failure_reason = '';
		return true;
	}

	/**
	 * Find a validated index whose leading column matches the requested field.
	 *
	 * @param string $table          Validated table identifier.
	 * @param string $first_column   Required leading column.
	 * @param bool   $require_unique Whether the index must be unique.
	 */
	private function find_index( string $table, string $first_column, bool $require_unique ): ?string {
		$wpdb = $this->context->database();
		$rows = $this->context->guard(
			static function () use ( $wpdb, $table ): mixed {
				$wpdb->last_error = '';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Validated internal table identifier; metadata cannot use a value placeholder.
				return $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
			}
		);
		if ( '' !== MigrationDatabaseState::last_error( $wpdb ) || ! is_array( $rows ) ) {
			return null;
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['Key_name'], $row['Column_name'], $row['Seq_in_index'], $row['Non_unique'] ) ) {
				continue;
			}
			$name = $row['Key_name'];
			if (
				is_string( $name )
				&& 1 === preg_match( '/^[A-Za-z0-9_]+$/D', $name )
				&& $first_column === $row['Column_name']
				&& 1 === CanonicalInteger::parse( $row['Seq_in_index'], 1, 1 )
				&& ( ! $require_unique || 0 === CanonicalInteger::parse( $row['Non_unique'], 0, 0 ) )
			) {
				return $name;
			}
		}

		return null;
	}

	/**
	 * Lock exact option rows/gaps, enforce byte bounds before fetching values,
	 * then fetch only the bounded rows under the same transaction locks.
	 *
	 * @param string   $table        Validated option table.
	 * @param string   $index        Validated unique option-name index.
	 * @param string[] $option_names Exact rows/gaps to lock.
	 * @return array{rows: array<string, array{raw_value: string, autoload: string}>, compatibility_overbound: bool}
	 * @throws MigrationReadException When locking evidence is malformed or uncertain.
	 */
	private function lock_options( string $table, string $index, array $option_names ): array {
		$wpdb         = $this->context->database();
		$option_names = array_values( array_unique( $option_names ) );
		if ( array() === $option_names || count( $option_names ) > 64 ) {
			throw new MigrationReadException( 'invalid_compatibility_fence_options' );
		}
		foreach ( $option_names as $option ) {
			if ( ! is_string( $option ) || strlen( $option ) > 191 || 1 !== preg_match( '/^[a-z0-9_]+$/D', $option ) ) {
				throw new MigrationReadException( 'invalid_compatibility_fence_options' );
			}
		}
		$placeholders = implode( ', ', array_fill( 0, count( $option_names ), '%s' ) );
		$forced_index = '`' . $index . '`';
		// Identifiers and the generated placeholder list are closed and bounded above.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$length_sql = $wpdb->prepare( "SELECT option_name, OCTET_LENGTH(option_value) AS byte_length, 1 AS cartpops_compatibility_fence_length FROM {$table} FORCE INDEX ({$forced_index}) WHERE option_name IN ({$placeholders}) ORDER BY option_name ASC FOR UPDATE", ...$option_names );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$length_rows = $this->context->guard(
			static function () use ( $wpdb, $length_sql ): mixed {
				$wpdb->last_error = '';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The SQL was prepared above after identifiers and placeholder count were validated.
				return $wpdb->get_results( $length_sql, ARRAY_A );
			}
		);
		if ( '' !== MigrationDatabaseState::last_error( $wpdb ) || ! is_array( $length_rows ) || count( $length_rows ) > count( $option_names ) ) {
			throw new MigrationReadException( 'database_read_failed_compatibility_fence_options' );
		}
		$allowed                 = array_fill_keys( $option_names, true );
		$compatibility           = array_fill_keys( LegacyPaidStateInspector::option_names(), true );
		$lengths                 = array();
		$total_bytes             = 0;
		$compatibility_bytes     = 0;
		$compatibility_overbound = false;
		foreach ( $length_rows as $row ) {
			if ( ! is_array( $row ) || array( 'byte_length', 'cartpops_compatibility_fence_length', 'option_name' ) !== $this->sorted_keys( $row ) || ! is_string( $row['option_name'] ?? null ) || ! isset( $allowed[ $row['option_name'] ] ) || isset( $lengths[ $row['option_name'] ] ) || 1 !== CanonicalInteger::parse( $row['cartpops_compatibility_fence_length'] ?? null, 1, 1 ) ) {
				throw new MigrationReadException( 'invalid_compatibility_fence_length_rows' );
			}
			$length = CanonicalInteger::parse( $row['byte_length'] ?? null, 0 );
			if ( null === $length ) {
				throw new MigrationReadException( 'invalid_compatibility_fence_length_rows' );
			}
			if ( isset( $compatibility[ $row['option_name'] ] ) ) {
				if ( $length > self::MAX_COMPATIBILITY_OPTION_BYTES || $compatibility_bytes > self::MAX_COMPATIBILITY_TOTAL_BYTES - $length ) {
					$compatibility_overbound = true;
					continue;
				}
				$compatibility_bytes += $length;
			}
			if ( $length > self::MAX_OPTION_BYTES || $total_bytes > self::MAX_OPTION_TOTAL_BYTES - $length ) {
				throw new MigrationReadException( 'compatibility_fence_control_bounds_exceeded' );
			}
			$total_bytes                   += $length;
			$lengths[ $row['option_name'] ] = $length;
		}

		if ( array() === $lengths ) {
			return array(
				'rows'                    => array(),
				'compatibility_overbound' => $compatibility_overbound,
			);
		}
		$bounded_names        = array_keys( $lengths );
		$bounded_placeholders = implode( ', ', array_fill( 0, count( $bounded_names ), '%s' ) );
		// Identifiers and the generated placeholder list are closed and bounded above.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows_sql = $wpdb->prepare( "SELECT option_name, option_value, autoload, 1 AS cartpops_compatibility_fence_option FROM {$table} FORCE INDEX ({$forced_index}) WHERE option_name IN ({$bounded_placeholders}) ORDER BY option_name ASC FOR UPDATE", ...$bounded_names );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $this->context->guard(
			static function () use ( $wpdb, $rows_sql ): mixed {
				$wpdb->last_error = '';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The SQL was prepared above after identifiers and placeholder count were validated.
				return $wpdb->get_results( $rows_sql, ARRAY_A );
			}
		);
		if ( '' !== MigrationDatabaseState::last_error( $wpdb ) || ! is_array( $rows ) || count( $rows ) !== count( $bounded_names ) ) {
			throw new MigrationReadException( 'database_read_failed_compatibility_fence_options' );
		}
		$result = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || array( 'autoload', 'cartpops_compatibility_fence_option', 'option_name', 'option_value' ) !== $this->sorted_keys( $row ) || ! is_string( $row['option_name'] ?? null ) || ! is_string( $row['option_value'] ?? null ) || ! is_string( $row['autoload'] ?? null ) || 1 !== CanonicalInteger::parse( $row['cartpops_compatibility_fence_option'] ?? null, 1, 1 ) || ! isset( $allowed[ $row['option_name'] ] ) || isset( $result[ $row['option_name'] ] ) ) {
				throw new MigrationReadException( 'invalid_compatibility_fence_option_rows' );
			}
			if ( ! isset( $lengths[ $row['option_name'] ] ) || strlen( $row['option_value'] ) !== $lengths[ $row['option_name'] ] ) {
				throw new MigrationReadException( 'compatibility_fence_option_length_changed' );
			}
			$result[ $row['option_name'] ] = array(
				'raw_value' => $row['option_value'],
				'autoload'  => $row['autoload'],
			);
		}
		return array(
			'rows'                    => $result,
			'compatibility_overbound' => $compatibility_overbound,
		);
	}

	/**
	 * Lock the complete legacy rule equality range and both boundary gaps.
	 *
	 * @param string $table Validated posts table identifier.
	 * @param string $index Validated post-type index identifier.
	 * @throws MigrationReadException When locking evidence is malformed or uncertain.
	 */
	private function lock_rule_range( string $table, string $index ): bool {
		$wpdb  = $this->context->database();
		$index = '`' . $index . '`';
		// Both identifiers are closed and validated before interpolation.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$queries = array(
			$wpdb->prepare( "SELECT ID, post_type, 1 AS cartpops_compatibility_fence_rule FROM {$table} FORCE INDEX ({$index}) WHERE post_type < %s ORDER BY post_type DESC, ID DESC LIMIT 1 FOR UPDATE", self::LEGACY_POST_TYPE ),
			$wpdb->prepare( "SELECT ID, post_type, 1 AS cartpops_compatibility_fence_rule FROM {$table} FORCE INDEX ({$index}) WHERE post_type = %s ORDER BY post_type ASC, ID ASC LIMIT %d FOR UPDATE", self::LEGACY_POST_TYPE, self::MAX_RULE_POSTS + 1 ),
			$wpdb->prepare( "SELECT ID, post_type, 1 AS cartpops_compatibility_fence_rule FROM {$table} FORCE INDEX ({$index}) WHERE post_type > %s ORDER BY post_type ASC, ID ASC LIMIT 1 FOR UPDATE", self::LEGACY_POST_TYPE ),
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$equality = array();
		foreach ( $queries as $position => $query ) {
			$rows = $this->context->guard(
				static function () use ( $wpdb, $query ): mixed {
					$wpdb->last_error = '';
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Current locking reads cover both boundary gaps and every equality row.
					return $wpdb->get_results( $query, ARRAY_A );
				}
			);
			if ( '' !== MigrationDatabaseState::last_error( $wpdb ) || ! is_array( $rows ) || ( 1 !== $position && count( $rows ) > 1 ) ) {
				throw new MigrationReadException( 'database_read_failed_legacy_rule_fence' );
			}
			if ( 1 === $position ) {
				$equality = $rows;
			}
			foreach ( $rows as $row ) {
				$post_type = is_array( $row ) && is_string( $row['post_type'] ?? null ) ? $row['post_type'] : null;
				$in_range  = ( 0 === $position && null !== $post_type && $post_type < self::LEGACY_POST_TYPE )
					|| ( 1 === $position && self::LEGACY_POST_TYPE === $post_type )
					|| ( 2 === $position && null !== $post_type && $post_type > self::LEGACY_POST_TYPE );
				if ( ! is_array( $row ) || array( 'ID', 'cartpops_compatibility_fence_rule', 'post_type' ) !== $this->sorted_keys( $row ) || null === CanonicalInteger::parse( $row['ID'] ?? null, 1 ) || ! $in_range || 1 !== CanonicalInteger::parse( $row['cartpops_compatibility_fence_rule'] ?? null, 1, 1 ) ) {
					throw new MigrationReadException( 'invalid_legacy_rule_fence_rows' );
				}
			}
		}

		return array() !== $equality;
	}

	/**
	 * Roll back and prove that the exact captured connection became reusable.
	 *
	 * @param MigrationOptionStore $store Deferred cache adapter.
	 */
	private function rollback( MigrationOptionStore $store ): bool {
		if ( null === $this->transaction || ! $this->transaction->is_active() ) {
			$store->commit_cache_invalidation();
			return false;
		}
		try {
			$this->transaction->rollback();
			$store->discard_cache_invalidation();
			return true;
		} catch ( \Throwable ) {
			$store->commit_cache_invalidation();
			return false;
		}
	}

	/**
	 * Return the established deep transaction module.
	 *
	 * @throws \RuntimeException When the terminal transaction is unavailable.
	 */
	private function owned_transaction(): OwnedTransaction {
		if ( null === $this->transaction ) {
			throw new \RuntimeException( 'The migration terminal transaction is unavailable.' );
		}
		return $this->transaction;
	}

	/**
	 * Accept only canonical database identifiers before SQL interpolation.
	 *
	 * @param mixed $table Candidate identifier.
	 */
	private function table_identifier( mixed $table ): ?string {
		return is_string( $table ) && 1 === preg_match( '/^[A-Za-z0-9_]+$/D', $table ) ? $table : null;
	}

	/**
	 * Return stable keys for closed-schema validation.
	 *
	 * @param array<mixed> $value Candidate record.
	 * @return array<int|string>
	 */
	private function sorted_keys( array $value ): array {
		$keys = array_keys( $value );
		sort( $keys, SORT_STRING );
		return $keys;
	}
}
