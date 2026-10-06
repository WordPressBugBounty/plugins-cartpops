<?php
/**
 * Storage engines of the tables CartPops needs transactions on.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Setup;

/**
 * Read the storage engine of the update and cart tables in one query.
 *
 * The update from version 1 locks the options and posts tables, and every
 * cart read and change locks the WooCommerce session and user meta tables.
 * MyISAM cannot do either, so both refuse to run on it.
 */
final class DatabaseEngines {
	/** Cache the all-clear so admin pages do not query information_schema on every load. */
	private const TRANSIENT_CART_OK = 'cartpops_cart_storage_ok';
	private const CART_OK_SECONDS   = 86400;

	/**
	 * Engines keyed by role, lowercased; '' when a table's engine could not be read.
	 *
	 * @return array{options: string, posts: string, sessions: string, usermeta: string}
	 */
	public static function read(): array {
		$engines = array(
			'options'  => '',
			'posts'    => '',
			'sessions' => '',
			'usermeta' => '',
		);
		$tables  = self::tables();
		if ( null === $tables ) {
			return $engines;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One read-only metadata lookup.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s, %s, %s)',
				$tables['options'],
				$tables['posts'],
				$tables['sessions'],
				$tables['usermeta']
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return $engines;
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['TABLE_NAME'] ?? null ) || ! is_string( $row['ENGINE'] ?? null ) ) {
				continue;
			}
			$role = array_search( $row['TABLE_NAME'], $tables, true );
			if ( is_string( $role ) && 1 === preg_match( '/\A[A-Za-z0-9_]{1,64}\z/D', $row['ENGINE'] ) ) {
				$engines[ $role ] = strtolower( $row['ENGINE'] );
			}
		}
		return $engines;
	}

	/**
	 * Whether a cart table positively reports a non-InnoDB engine.
	 *
	 * Unreadable engines do not count: only a proven MyISAM (or similar)
	 * table explains a cart that cannot load. A clean result is cached for a
	 * day; a failing one is re-read on every call so the warning clears as
	 * soon as the tables are converted.
	 */
	public static function cart_tables_not_transactional(): bool {
		if ( false !== get_transient( self::TRANSIENT_CART_OK ) ) {
			return false;
		}
		$engines = self::read();
		foreach ( array( $engines['sessions'], $engines['usermeta'] ) as $engine ) {
			if ( '' !== $engine && 'innodb' !== $engine ) {
				return true;
			}
		}
		set_transient( self::TRANSIENT_CART_OK, 1, self::CART_OK_SECONDS );
		return false;
	}

	/**
	 * Current-site table names, or null without a usable database handle.
	 *
	 * @return array{options: string, posts: string, sessions: string, usermeta: string}|null
	 */
	private static function tables(): ?array {
		global $wpdb;
		if (
			! is_object( $wpdb )
			|| ! method_exists( $wpdb, 'get_results' )
			|| ! method_exists( $wpdb, 'prepare' )
			|| ! isset( $wpdb->options, $wpdb->posts, $wpdb->usermeta, $wpdb->prefix )
		) {
			return null;
		}
		return array(
			'options'  => (string) $wpdb->options,
			'posts'    => (string) $wpdb->posts,
			'sessions' => $wpdb->prefix . 'woocommerce_sessions',
			'usermeta' => (string) $wpdb->usermeta,
		);
	}
}
