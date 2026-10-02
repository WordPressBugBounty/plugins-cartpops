<?php
/**
 * Reusable CartPops uninstall cleanup boundary.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Setup;

use CartPops\Migration\MigrationDatabaseState;

/**
 * Removes executable schedules in every mode while preserving recovery data
 * unless the caller explicitly requests the permanent-purge path.
 *
 * This class is intentionally independent of uninstall.php so Freemius can
 * invoke it from its after_uninstall action when that distribution excludes
 * WordPress' direct-uninstall adapter.
 */
final class UninstallCleanup {

	/** Maximum sites loaded into memory at once. */
	private const SITE_BATCH_SIZE = 100;

	/** Maximum product-owned option rows removed by one query. */
	private const OPTION_BATCH_SIZE = 500;

	/** Maximum post IDs loaded into memory at once. */
	private const POST_BATCH_SIZE = 100;

	/**
	 * WordPress cron callbacks that must not survive uninstall.
	 *
	 * @var string[]
	 */
	private const CRON_HOOKS = array(
		'cartpops_analytics_cleanup',
		'cartpops_backfill_conversions',
	);

	/**
	 * Action Scheduler callbacks that must not survive uninstall.
	 *
	 * @var string[]
	 */
	private const ACTION_SCHEDULER_HOOKS = array(
		'cartpops_backfill_conversions',
	);

	/**
	 * Current and historical product-owned post types.
	 *
	 * @var string[]
	 */
	private const POST_TYPES = array(
		'cartpops_rule',
		'cartpops_rules',
		'cartpops_master_log',
	);

	/**
	 * Run cleanup for the current installation.
	 *
	 * @param bool $permanent_purge Explicit operator authorization to destroy
	 *                              product-owned data. Freemius fs_* data is
	 *                              outside this boundary in both modes.
	 */
	public function run( bool $permanent_purge = false ): bool {
		$success = true;
		if ( ! is_multisite() ) {
			$success = $this->cleanup_current_site( $permanent_purge );
		} else {
			$cursor     = 0;
			$site_count = 0;
			do {
				$site_ids = $this->site_ids_after( $cursor );
				if ( null === $site_ids ) {
					$success = false;
					break;
				}
				foreach ( $site_ids as $site_id ) {
					$context = $this->capture_blog_context();
					try {
						if ( ! switch_to_blog( $site_id ) || ! $this->cleanup_current_site( $permanent_purge ) ) {
							$success = false;
						}
					} catch ( \Throwable ) {
						$success = false;
					} finally {
						if ( ! $this->restore_blog_context( $context ) ) {
							$success = false;
						}
					}
					$cursor = $site_id;
				}
				$site_count = count( $site_ids );
			} while ( self::SITE_BATCH_SIZE === $site_count );
		}

		if ( $permanent_purge && ! $this->purge_network_records() ) {
			$success = false;
		}

		return $success;
	}

	/**
	 * Run the data-preserving or explicit-purge operation for one site.
	 *
	 * @param bool $permanent_purge Whether explicit permanent purge was authorized.
	 */
	public function cleanup_current_site( bool $permanent_purge = false ): bool {
		$success = $this->clear_schedules();
		if ( ! $permanent_purge ) {
			return $success;
		}

		if ( ! $this->purge_site_options() ) {
			$success = false;
		}
		if ( ! $this->drop_owned_tables() ) {
			$success = false;
		}
		if ( ! $this->purge_owned_posts() ) {
			$success = false;
		}

		return $success;
	}

	/** Remove WordPress cron and Action Scheduler execution for this site. */
	private function clear_schedules(): bool {
		$success = true;
		foreach ( self::CRON_HOOKS as $hook ) {
			try {
				$cleared = wp_clear_scheduled_hook( $hook );
			} catch ( \Throwable ) {
				$success = false;
				continue;
			}
			if ( $this->schedule_clear_failed( $cleared ) ) {
				$success = false;
			}
			if ( false !== wp_next_scheduled( $hook ) ) {
				$success = false;
			}
		}

		return $this->clear_action_scheduler_hooks() && $success;
	}

	/**
	 * Clear Action Scheduler before or after its runtime initializes, then
	 * verify the exact site-prefixed table contains no executable callback.
	 */
	private function clear_action_scheduler_hooks(): bool {
		global $wpdb;
		$success = true;

		$table            = $wpdb->prefix . 'actionscheduler_actions';
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must determine whether the site-prefixed scheduler store exists before its runtime initializes.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( '' !== $this->database_error() ) {
			return false;
		}
		if ( $table !== $found ) {
			return true;
		}

		if (
			function_exists( 'as_unschedule_all_actions' )
			&& function_exists( 'did_action' )
			&& did_action( 'action_scheduler_init' ) > 0
		) {
			foreach ( self::ACTION_SCHEDULER_HOOKS as $hook ) {
				try {
					as_unschedule_all_actions( $hook );
				} catch ( \Throwable ) {
					$success = false;
				}
			}
		}

		foreach ( self::ACTION_SCHEDULER_HOOKS as $hook ) {
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact hook deletion is the pre-init fallback and guarantees no stale callback can execute after reinstall.
			$deleted = $wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Validated internal site-prefixed scheduler table identifier.
				$wpdb->prepare( "DELETE FROM {$table} WHERE hook = %s", $hook )
			);
			if ( false === $deleted || '' !== $this->database_error() ) {
				$success = false;
				continue;
			}
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall success requires an exact absence readback.
			$remaining = $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Validated internal site-prefixed scheduler table identifier.
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE hook = %s", $hook )
			);
			if ( '' !== $this->database_error() || ! is_numeric( $remaining ) || 0 !== (int) $remaining ) {
				$success = false;
			}
		}

		return $success;
	}

	/** Delete product-owned options/transients in bounded SQL batches. */
	private function purge_site_options(): bool {
		global $wpdb;

		$patterns = array(
			$wpdb->esc_like( 'cartpops_' ) . '%',
			$wpdb->esc_like( '_transient_cartpops_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_cartpops_' ) . '%',
		);
		do {
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit permanent purge deletes only exact product-owned prefixes and is bounded per query.
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s LIMIT %d",
					$patterns[0],
					$patterns[1],
					$patterns[2],
					self::OPTION_BATCH_SIZE
				)
			);
			if ( false === $deleted || '' !== $this->database_error() ) {
				return false;
			}
		} while ( self::OPTION_BATCH_SIZE === $deleted );

		return true;
	}

	/** Drop only the current site's product-owned analytics table. */
	private function drop_owned_tables(): bool {
		global $wpdb;

		$table            = $wpdb->prefix . 'cartpops_events';
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Internal site prefix plus fixed table suffix; explicit permanent purge only.
		$dropped = $wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		return false !== $dropped && '' === $this->database_error();
	}

	/** Delete every status of current and historical CartPops posts in batches. */
	private function purge_owned_posts(): bool {
		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( self::POST_TYPES ), '%s' ) );
		$id_count     = 0;
		do {
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed placeholder list; keyset/order and LIMIT bound memory for all statuses including trash and auto-draft.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed placeholder list and internal posts table identifier.
					"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$placeholders}) ORDER BY ID ASC LIMIT %d",
					...array_merge( self::POST_TYPES, array( self::POST_BATCH_SIZE ) )
				)
			);
			if ( '' !== $this->database_error() || ! is_array( $ids ) ) {
				return false;
			}
			$ids = array_values( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) );
			foreach ( $ids as $id ) {
				if ( false === wp_delete_post( $id, true ) ) {
					return false;
				}
			}
			$id_count = count( $ids );
		} while ( self::POST_BATCH_SIZE === $id_count );

		return true;
	}

	/** Remove only product-owned network migration records and site transients. */
	private function purge_network_records(): bool {
		global $wpdb;
		$success    = true;
		$network_id = get_current_network_id();
		$main_site  = get_main_site_id( $network_id );
		$main_table = $wpdb->get_blog_prefix( $main_site ) . 'options';
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $main_table ) ) {
			return false;
		}
		$physical_names   = array(
			'cartpops_network_' . $network_id . '_cartpops_v1_network_migration_state',
			'cartpops_network_' . $network_id . '_cartpops_v1_network_legacy_cohort_v1',
		);
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact current-network main-site keys; explicit permanent purge only.
		$physical_deleted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Validated current-network main-site options table identifier.
				"DELETE FROM {$main_table} WHERE option_name IN (%s, %s)",
				$physical_names[0],
				$physical_names[1]
			)
		);
		if ( false === $physical_deleted || '' !== $this->database_error() ) {
			$success = false;
		} else {
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify exact physical network state is absent after explicit purge.
			$physical_remaining = $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Validated current-network main-site options table identifier.
					"SELECT COUNT(*) FROM {$main_table} WHERE option_name IN (%s, %s)",
					$physical_names[0],
					$physical_names[1]
				)
			);
			if ( '' !== $this->database_error() || ! is_numeric( $physical_remaining ) || 0 !== (int) $physical_remaining ) {
				$success = false;
			}
		}

		$patterns = array(
			$wpdb->esc_like( '_site_transient_cartpops_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_cartpops_' ) . '%',
		);
		do {
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit purge of exact CartPops network keys/transient prefixes across bounded batches; fs_* is deliberately excluded.
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->sitemeta} WHERE site_id = %d AND (meta_key IN (%s, %s) OR meta_key LIKE %s OR meta_key LIKE %s) LIMIT %d",
					$network_id,
					'cartpops_v1_network_migration_state',
					'cartpops_v1_network_legacy_cohort_v1',
					$patterns[0],
					$patterns[1],
					self::OPTION_BATCH_SIZE
				)
			);
			if ( false === $deleted || '' !== $this->database_error() ) {
				$success = false;
				break;
			}
		} while ( self::OPTION_BATCH_SIZE === $deleted );

		return $success;
	}

	/**
	 * Read one keyset-bounded current-network site batch.
	 *
	 * @param int $cursor Last processed blog ID.
	 * @return int[]|null
	 */
	private function site_ids_after( int $cursor ): ?array {
		global $wpdb;

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Keyset batching is required to avoid loading an unbounded multisite network.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT blog_id FROM {$wpdb->blogs} WHERE site_id = %d AND blog_id > %d ORDER BY blog_id ASC LIMIT %d",
				get_current_network_id(),
				$cursor,
				self::SITE_BATCH_SIZE
			)
		);
		if ( '' !== $this->database_error() || ! is_array( $ids ) ) {
			return null;
		}
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		foreach ( $ids as $id ) {
			if ( $id <= $cursor ) {
				return null;
			}
		}

		return $ids;
	}

	/** Capture exact WordPress, test-harness, database, and cache blog state. */
	private function capture_blog_context(): array {
		global $wpdb, $blog_id, $_wp_switched_stack, $switched;
		global $wp_current_blog_id, $wp_blog_stack, $wp_options;

		return array(
			'blog_id'              => get_current_blog_id(),
			'global_blog_id'       => $blog_id ?? null,
			'wp_stack'             => isset( $_wp_switched_stack ) && is_array( $_wp_switched_stack ) ? $_wp_switched_stack : null,
			'switched'             => $switched ?? null,
			'prefix'               => isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '',
			'unit_current_blog_id' => $wp_current_blog_id ?? null,
			'unit_blog_stack'      => isset( $wp_blog_stack ) && is_array( $wp_blog_stack ) ? $wp_blog_stack : null,
			'unit_options'         => isset( $wp_options ) && is_array( $wp_options ) ? $wp_options : null,
		);
	}

	/**
	 * Restore the exact original context even after a partially throwing switch.
	 *
	 * @param array<string, mixed> $context Exact captured context.
	 */
	private function restore_blog_context( array $context ): bool {
		global $wpdb, $blog_id, $_wp_switched_stack, $switched;
		global $wp_current_blog_id, $wp_blog_stack, $wp_options, $wp_blog_options;

		$success  = true;
		$original = (int) ( $context['blog_id'] ?? 0 );
		$current  = get_current_blog_id();
		if ( $current !== $original && is_array( $wp_options ?? null ) && is_array( $wp_blog_options ?? null ) ) {
			$wp_blog_options[ $current ] = $wp_options;
		}

		try {
			$core_depth = is_array( $context['wp_stack'] ) ? count( $context['wp_stack'] ) : 0;
			$unit_depth = is_array( $context['unit_blog_stack'] ) ? count( $context['unit_blog_stack'] ) : 0;
			while ( true ) {
				$current_core_depth = is_array( $_wp_switched_stack ?? null ) ? count( $_wp_switched_stack ) : 0;
				$current_unit_depth = is_array( $wp_blog_stack ?? null ) ? count( $wp_blog_stack ) : 0;
				if ( get_current_blog_id() === $original && $current_core_depth <= $core_depth && $current_unit_depth <= $unit_depth ) {
					break;
				}
				if ( ! restore_current_blog() ) {
					$success = false;
					break;
				}
			}
		} catch ( \Throwable ) {
			$success = false;
		}

		if ( method_exists( $wpdb, 'set_blog_id' ) ) {
			$wpdb->set_blog_id( $original );
		} else {
			$wpdb->prefix  = (string) ( $context['prefix'] ?? '' );
			$wpdb->options = $wpdb->prefix . 'options';
		}
		if ( function_exists( 'wp_cache_switch_to_blog' ) ) {
			wp_cache_switch_to_blog( $original );
		}

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Exact context recovery after partially throwing switch hooks.
		$blog_id            = $context['global_blog_id'] ?? null;
		$_wp_switched_stack = is_array( $context['wp_stack'] ?? null ) ? $context['wp_stack'] : array();
		$switched           = $context['switched'] ?? null;
		if ( null !== ( $context['unit_current_blog_id'] ?? null ) ) {
			$wp_current_blog_id = (int) $context['unit_current_blog_id'];
		}
		if ( is_array( $context['unit_blog_stack'] ?? null ) ) {
			$wp_blog_stack = $context['unit_blog_stack'];
		}
		if ( isset( $wp_blog_options[ $original ] ) && is_array( $wp_blog_options[ $original ] ) ) {
			$wp_options = $wp_blog_options[ $original ];
		} elseif ( is_array( $context['unit_options'] ?? null ) ) {
			$wp_options = $context['unit_options'];
		}

		return $success
			&& get_current_blog_id() === $original
			&& (string) ( $context['prefix'] ?? '' ) === (string) $wpdb->prefix;
	}

	/**
	 * Return a fresh database error without relying on a typed wpdb property.
	 *
	 * @phpstan-impure The database driver mutates this value after each query.
	 */
	private function database_error(): string {
		global $wpdb;

		return MigrationDatabaseState::last_error( $wpdb );
	}

	/**
	 * Interpret WordPress' version-dependent cron deletion result.
	 *
	 * @param mixed $result WordPress cron clear result.
	 */
	private function schedule_clear_failed( mixed $result ): bool {
		return false === $result
			|| ( is_object( $result ) && function_exists( 'is_wp_error' ) && is_wp_error( $result ) );
	}
}
