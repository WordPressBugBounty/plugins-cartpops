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

	/** Return the exact class of WooCommerce's classic cookie session handler. */
	public function classic_handler_class(): string;

	/**
	 * Whether an already-initialized classic session holds nothing unsaved.
	 *
	 * Implementations answer false whenever that cannot be proven.
	 *
	 * @param object $session Classic session initialized earlier in this request.
	 */
	public function is_unmodified_classic_session( object $session ): bool;

	/**
	 * Drop an early classic session together with the cart and customer built on it.
	 *
	 * Every shutdown, cookie and cart-persistence callback those objects registered is
	 * detached, so none of them can write over the Store session that replaces them.
	 * Returns false when any part could not be proven removed.
	 *
	 * @param object $woocommerce WooCommerce singleton.
	 */
	public function discard_classic_session( object $woocommerce ): bool;

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
