<?php
/**
 * Native WordPress/WooCommerce Cart-Token dispatch adapter.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\REST;

/** Bind the request lifecycle to native WordPress hooks and Woo's Store handler. */
final class WordPressWooSessionDispatchAdapter implements WooSessionDispatchAdapter {
	private const STORE_API_SESSION_HANDLER = 'Automattic\\WooCommerce\\StoreApi\\SessionHandler';

	/** Return WooCommerce's installed Store API session handler. */
	public function handler_class(): string {
		return self::STORE_API_SESSION_HANDLER;
	}

	/**
	 * Install a native WordPress lifecycle hook.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Exact callback.
	 * @param int      $priority      Hook priority.
	 * @param int      $accepted_args Accepted argument count.
	 */
	public function add( string $hook, callable $callback, int $priority, int $accepted_args ): bool {
		try {
			if ( 'shutdown' === $hook ) {
				return function_exists( 'add_action' ) && true === add_action( $hook, $callback, $priority, $accepted_args );
			}
			return function_exists( 'add_filter' ) && true === add_filter( $hook, $callback, $priority, $accepted_args );
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Remove an exact native WordPress lifecycle hook.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Exact callback.
	 * @param int      $priority Hook priority.
	 */
	public function remove( string $hook, callable $callback, int $priority ): bool {
		try {
			if ( 'shutdown' === $hook ) {
				return function_exists( 'remove_action' ) && true === remove_action( $hook, $callback, $priority );
			}
			return function_exists( 'remove_filter' ) && true === remove_filter( $hook, $callback, $priority );
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Report whether WordPress is applying one lifecycle filter.
	 *
	 * @param string $hook Hook name.
	 */
	public function is_running( string $hook ): bool {
		try {
			return function_exists( 'doing_filter' ) && doing_filter( $hook );
		} catch ( \Throwable ) {
			return false;
		}
	}
}
