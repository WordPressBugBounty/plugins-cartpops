<?php
/**
 * WordPress hook seam for one Cart-Token session dispatch.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\REST;

/** Keep Woo's official Store API handler selected for one REST dispatch. */
interface WooSessionDispatchAdapter {
	/** Return the exact handler class selected for a validated Cart-Token. */
	public function handler_class(): string;

	/**
	 * Install one request-lifecycle hook.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Exact callback.
	 * @param int      $priority      Hook priority.
	 * @param int      $accepted_args Accepted argument count.
	 */
	public function add( string $hook, callable $callback, int $priority, int $accepted_args ): bool;

	/**
	 * Remove the exact installed request-lifecycle hook.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Exact callback.
	 * @param int      $priority Hook priority.
	 */
	public function remove( string $hook, callable $callback, int $priority ): bool;

	/**
	 * Whether WordPress is currently applying one request-lifecycle hook.
	 *
	 * @param string $hook Hook name.
	 */
	public function is_running( string $hook ): bool;
}
