<?php
/**
 * Immutable site and database binding for one upgrade convergence.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Setup;

use CartPops\Database\TransactionConnectionAdapter;
use CartPops\Database\WordPressMysqliTransactionConnectionAdapter;
use CartPops\Migration\CanonicalInteger;

/**
 * Captures every per-site database target before the first migration read.
 *
 * Mutable fence state lives behind an owned object so the captured binding
 * itself remains readonly and cannot be retargeted during convergence.
 */
final class SiteUpgradeContext {

	/** Earliest priority representable by WordPress' integer hook map. */
	private const FENCE_PRIORITY = PHP_INT_MIN;

	/**
	 * Construct one exact request-local binding.
	 *
	 * @param int                          $site_blog_id           Exact captured blog ID.
	 * @param int                          $site_network_id        Exact captured network ID.
	 * @param array<int, int>              $site_switch_stack     Exact captured core switch stack.
	 * @param bool                         $site_switched         Exact captured core switched flag.
	 * @param object                       $site_database          Exact captured wpdb object.
	 * @param TransactionConnectionAdapter $connection             Exact captured connection adapter.
	 * @param string                       $site_connection_id     Exact captured live connection identity.
	 * @param string                       $site_prefix            Exact validated blog prefix.
	 * @param bool                         $site_multisite         Exact captured multisite state.
	 * @param string                       $site_blogs_table       Exact WordPress blogs table.
	 * @param string                       $site_options_table     Exact options table.
	 * @param string                       $site_posts_table       Exact posts table.
	 * @param string                       $site_events_table      Exact CartPops analytics table.
	 * @param string                       $site_scheduler_table   Exact Action Scheduler table.
	 * @param SiteUpgradeFenceState        $fence                  Owned mutable fence state.
	 */
	private function __construct(
		private readonly int $site_blog_id,
		private readonly int $site_network_id,
		private readonly array $site_switch_stack,
		private readonly bool $site_switched,
		private readonly object $site_database,
		private readonly TransactionConnectionAdapter $connection,
		private readonly string $site_connection_id,
		private readonly string $site_prefix,
		private readonly bool $site_multisite,
		private readonly string $site_blogs_table,
		private readonly string $site_options_table,
		private readonly string $site_posts_table,
		private readonly string $site_events_table,
		private readonly string $site_scheduler_table,
		private readonly SiteUpgradeFenceState $fence,
	) {}

	/** Prevent a captured binding from sharing mutable fence state. */
	private function __clone() {}

	/**
	 * Captured live database bindings cannot be serialized for later replay.
	 *
	 * @return never
	 * @throws \LogicException Always; live bindings are request-local.
	 */
	public function __serialize(): array {
		throw new \LogicException( 'A site upgrade context cannot be serialized.' );
	}

	/**
	 * Captured live database bindings cannot be restored from serialized data.
	 *
	 * @param array<mixed> $data Serialized data, which is never accepted.
	 * @return never
	 * @throws \LogicException Always; live bindings are request-local.
	 */
	public function __unserialize( array $data ): void {
		unset( $data );
		throw new \LogicException( 'A site upgrade context cannot be unserialized.' );
	}

	/**
	 * Capture and validate one exact current-site binding.
	 *
	 * @throws SiteUpgradeContextDrift When the binding cannot be proven stable.
	 */
	public static function capture(): self {
		global $wpdb, $_wp_switched_stack, $switched;

		$blog_id      = get_current_blog_id();
		$network_id   = get_current_network_id();
		$switch_stack = is_array( $_wp_switched_stack ?? null ) ? $_wp_switched_stack : array();
		$is_switched  = (bool) ( $switched ?? false );
		if ( $blog_id <= 0 || $network_id <= 0 || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_blog_prefix' ) ) {
			throw new SiteUpgradeContextDrift( 'The current site database binding cannot be captured.' );
		}
		if ( ( array() !== $switch_stack ) !== $is_switched ) {
			throw new SiteUpgradeContextDrift( 'The current WordPress switch stack is inconsistent.' );
		}

		$database   = $wpdb;
		$connection = $database instanceof TransactionConnectionAdapter
			? $database
			: WordPressMysqliTransactionConnectionAdapter::capture( $database );
		$identity   = $connection->identity();
		if ( null === $identity || '' === $identity || ! $connection->is_current() ) {
			throw new SiteUpgradeContextDrift( 'The current database connection identity cannot be proven.' );
		}

		$prefix = $database->get_blog_prefix( $blog_id );
		if ( ! is_string( $prefix ) || 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $prefix ) ) {
			throw new SiteUpgradeContextDrift( 'The current site table prefix is invalid.' );
		}
		$multisite = is_multisite();
		$blogs     = $multisite && isset( $database->blogs ) && is_string( $database->blogs ) ? $database->blogs : '';
		$options   = $prefix . 'options';
		$posts     = $prefix . 'posts';
		$events    = $prefix . 'cartpops_events';
		$scheduler = $prefix . 'actionscheduler_actions';
		$tables    = array( $options, $posts, $events, $scheduler );
		if ( $multisite ) {
			array_unshift( $tables, $blogs );
		}
		foreach ( $tables as $table ) {
			if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $table ) ) {
				throw new SiteUpgradeContextDrift( 'A current site table target is invalid.' );
			}
		}
		if ( $multisite && self::read_bound_network_id( $database, $connection, $identity, $blogs, $blog_id ) !== $network_id ) {
			throw new SiteUpgradeContextDrift( 'The current site network membership cannot be proven.' );
		}

		$context = new self(
			$blog_id,
			$network_id,
			$switch_stack,
			$is_switched,
			$database,
			$connection,
			$identity,
			$prefix,
			$multisite,
			$blogs,
			$options,
			$posts,
			$events,
			$scheduler,
			new SiteUpgradeFenceState(),
		);
		$context->assert_current();

		return $context;
	}

	/** Return the exact captured WordPress blog. */
	public function blog_id(): int {
		return $this->site_blog_id;
	}

	/** Return the exact captured WordPress network. */
	public function network_id(): int {
		return $this->site_network_id;
	}

	/**
	 * Return the exact captured wpdb object.
	 *
	 * @return \wpdb
	 */
	public function database(): object {
		return $this->site_database;
	}

	/** Return the exact captured live connection identity. */
	public function connection_identity(): string {
		return $this->site_connection_id;
	}

	/** Return the captured site prefix. */
	public function prefix(): string {
		return $this->site_prefix;
	}

	/** Return the exact captured WordPress blogs table. */
	public function blogs_table(): string {
		return $this->site_blogs_table;
	}

	/**
	 * Read and lock one exact persisted blog-to-network membership row.
	 *
	 * FOR UPDATE is portable across the supported MySQL and MariaDB floors. When
	 * called inside the terminal transaction, it holds the membership authority
	 * through completion; outside a transaction it remains an exact fresh read.
	 *
	 * @param object $database    Exact captured wpdb object.
	 * @param string $blogs_table Exact validated WordPress blogs table.
	 * @param int    $blog_id     Exact target blog ID.
	 * @throws SiteUpgradeContextDrift When the row is absent, malformed, or uncertain.
	 */
	public static function read_persisted_network_id( object $database, string $blogs_table, int $blog_id ): int {
		try {
			$connection = $database instanceof TransactionConnectionAdapter
				? $database
				: WordPressMysqliTransactionConnectionAdapter::capture( $database );
			$identity   = $connection->identity();
		} catch ( \Throwable ) {
			throw new SiteUpgradeContextDrift( 'The persisted site network membership cannot be read.' );
		}
		if ( null === $identity || '' === $identity ) {
			throw new SiteUpgradeContextDrift( 'The persisted site network membership cannot be read.' );
		}

		return self::read_bound_network_id( $database, $connection, $identity, $blogs_table, $blog_id );
	}

	/**
	 * Read membership through one exact non-reconnecting connection handle.
	 *
	 * @param object                       $database    Exact captured wpdb object.
	 * @param TransactionConnectionAdapter $connection  Exact captured connection adapter.
	 * @param string                       $identity    Exact captured connection identity.
	 * @param string                       $blogs_table Exact validated WordPress blogs table.
	 * @param int                          $blog_id     Exact target blog ID.
	 * @throws SiteUpgradeContextDrift When the row, handle, or database binding is uncertain.
	 */
	private static function read_bound_network_id(
		object $database,
		TransactionConnectionAdapter $connection,
		string $identity,
		string $blogs_table,
		int $blog_id
	): int {
		global $wpdb;

		if ( $wpdb !== $database || $blog_id <= 0 || 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $blogs_table ) ) {
			throw new SiteUpgradeContextDrift( 'The persisted site network membership cannot be read.' );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table and canonical positive integer are closed before reaching the non-reconnecting adapter.
		$query      = "SELECT site_id FROM {$blogs_table} WHERE blog_id = {$blog_id} LIMIT 1 FOR UPDATE";
		$network_id = $connection->read_scalar( $query );
		$network_id = CanonicalInteger::parse( $network_id, 1 );
		if (
			$wpdb !== $database
			|| ! $connection->is_current()
			|| $identity !== $connection->identity()
			|| 0 !== $connection->error_number()
			|| '00000' !== $connection->sql_state()
			|| '' !== $connection->error_message()
			|| null === $network_id
		) {
			throw new SiteUpgradeContextDrift( 'The persisted site network membership cannot be read.' );
		}

		return $network_id;
	}

	/** Return the captured options table. */
	public function options_table(): string {
		return $this->site_options_table;
	}

	/** Return the captured posts table. */
	public function posts_table(): string {
		return $this->site_posts_table;
	}

	/** Return the captured analytics events table. */
	public function analytics_events_table(): string {
		return $this->site_events_table;
	}

	/** Return the captured Action Scheduler actions table. */
	public function action_scheduler_actions_table(): string {
		return $this->site_scheduler_table;
	}

	/**
	 * Install the earliest switch fence for an entire convergence.
	 *
	 * @template T
	 * @param callable(): T $operation Bound convergence operation.
	 * @return T
	 * @throws SiteUpgradeContextDrift When a switch/restore or other drift occurs.
	 */
	public function run( callable $operation ): mixed {
		$this->assert_current();
		if ( $this->fence->installed ) {
			return $this->guard( $operation );
		}
		if ( ! $this->fence_is_first() || ! function_exists( 'add_action' ) || ! function_exists( 'remove_action' ) ) {
			throw new SiteUpgradeContextDrift( 'The site switch fence cannot be proven first.' );
		}

		$this->fence->callback = function ( int $new_blog_id, int $previous_blog_id, string $reason ): void {
			unset( $new_blog_id, $previous_blog_id, $reason );
			$this->fence->tripped = true;
			throw new SiteUpgradeContextDrift( 'WordPress site context changed during an upgrade.' );
		};
		if ( ! add_action( 'switch_blog', $this->fence->callback, self::FENCE_PRIORITY, 3 ) ) {
			$this->fence->callback = null;
			throw new SiteUpgradeContextDrift( 'The site switch fence could not be installed.' );
		}
		$this->fence->installed = true;

		try {
			return $this->guard( $operation );
		} finally {
			$callback = $this->fence->callback;
			if ( null !== $callback ) {
				remove_action( 'switch_blog', $callback, self::FENCE_PRIORITY );
			}
			$this->fence->callback  = null;
			$this->fence->installed = false;
		}
	}

	/**
	 * Verify the binding immediately before and after an external boundary.
	 *
	 * @template T
	 * @param callable(): T $operation External read or mutation boundary.
	 * @return T
	 * @throws \Throwable When the binding drifts or the guarded operation fails.
	 */
	public function guard( callable $operation ): mixed {
		$this->assert_current();
		try {
			$result = $operation();
		} catch ( \Throwable $error ) {
			$this->assert_current();
			throw $error;
		}
		$this->assert_current();
		return $result;
	}

	/**
	 * Guard cache publication after a transaction has closed its connection.
	 *
	 * An ambiguous COMMIT quarantines the captured database connection before
	 * its option-cache invalidations can be published. Cache deletion remains
	 * safe only while every site, table, wpdb, stack, and switch-fence binding
	 * is still exact; it must not require the deliberately closed connection.
	 *
	 * @template T
	 * @param callable(): T $operation Post-transaction cache mutation.
	 * @return T
	 * @throws \Throwable When the site binding drifts or the operation fails.
	 */
	public function guard_postcommit_cache( callable $operation ): mixed {
		$this->assert_binding( false );
		try {
			$result = $operation();
		} catch ( \Throwable $error ) {
			$this->assert_binding( false );
			throw $error;
		}
		$this->assert_binding( false );
		return $result;
	}

	/**
	 * Prove the exact site, table targets, wpdb object, and live connection.
	 *
	 * @throws SiteUpgradeContextDrift When any captured identity has drifted.
	 */
	public function assert_current(): void {
		$this->assert_binding( true );
	}

	/**
	 * Prove the immutable site binding, optionally including its live handle.
	 *
	 * @param bool $require_connection Whether the captured connection must remain live.
	 * @throws SiteUpgradeContextDrift When any required identity has drifted.
	 */
	private function assert_binding( bool $require_connection ): void {
		global $wpdb, $_wp_switched_stack, $switched;

		$live_stack  = is_array( $_wp_switched_stack ?? null ) ? $_wp_switched_stack : array();
		$is_switched = (bool) ( $switched ?? false );
		$live_prefix = is_object( $wpdb ) && method_exists( $wpdb, 'get_blog_prefix' )
			? $wpdb->get_blog_prefix( $this->site_blog_id )
			: null;
		if (
			$this->fence->tripped
			|| get_current_blog_id() !== $this->site_blog_id
			|| get_current_network_id() !== $this->site_network_id
			|| is_multisite() !== $this->site_multisite
			|| $live_stack !== $this->site_switch_stack
			|| $is_switched !== $this->site_switched
			|| ! is_object( $wpdb )
			|| $wpdb !== $this->site_database
			|| (
				$require_connection
				&& (
					! $this->connection->is_current()
					|| $this->connection->identity() !== $this->site_connection_id
				)
			)
			|| ! isset( $wpdb->prefix, $wpdb->options, $wpdb->posts )
			|| ! is_string( $live_prefix )
			|| $live_prefix !== $this->site_prefix
			|| (string) $wpdb->prefix !== $this->site_prefix
			|| (string) $wpdb->options !== $this->site_options_table
			|| (string) $wpdb->posts !== $this->site_posts_table
		) {
			throw new SiteUpgradeContextDrift( 'The exact site/database upgrade binding drifted.' );
		}
		if (
			$require_connection
			&& $this->site_multisite
			&& self::read_bound_network_id( $this->site_database, $this->connection, $this->site_connection_id, $this->site_blogs_table, $this->site_blog_id ) !== $this->site_network_id
		) {
			throw new SiteUpgradeContextDrift( 'The exact site network membership drifted.' );
		}
	}

	/** Whether no existing switch listener can run before or alongside the fence. */
	private function fence_is_first(): bool {
		global $wp_filter, $wp_actions;

		if ( isset( $wp_filter['switch_blog'] ) ) {
			$callbacks = is_object( $wp_filter['switch_blog'] ) && isset( $wp_filter['switch_blog']->callbacks )
				? $wp_filter['switch_blog']->callbacks
				: ( is_array( $wp_filter['switch_blog'] ) ? $wp_filter['switch_blog'] : array() );
			foreach ( is_array( $callbacks ) ? array_keys( $callbacks ) : array() as $priority ) {
				if ( is_numeric( $priority ) && (int) $priority <= self::FENCE_PRIORITY ) {
					return false;
				}
			}
		}

		foreach ( is_array( $wp_actions['switch_blog'] ?? null ) ? $wp_actions['switch_blog'] : array() as $registered ) {
			if ( is_array( $registered ) && (int) ( $registered['priority'] ?? 10 ) <= self::FENCE_PRIORITY ) {
				return false;
			}
		}

		return true;
	}
}
