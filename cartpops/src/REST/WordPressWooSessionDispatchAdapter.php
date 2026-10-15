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

	private const CLASSIC_SESSION_HANDLER = 'WC_Session_Handler';

	/** Return WooCommerce's installed Store API session handler. */
	public function handler_class(): string {
		return self::STORE_API_SESSION_HANDLER;
	}

	/** Return WooCommerce's classic cookie session handler. */
	public function classic_handler_class(): string {
		return self::CLASSIC_SESSION_HANDLER;
	}

	/**
	 * Report whether WooCommerce would persist anything for this classic session.
	 *
	 * WooCommerce exposes the dirty flag only as a protected property.
	 *
	 * @param object $session Classic session initialized earlier in this request.
	 */
	public function is_unmodified_classic_session( object $session ): bool {
		try {
			return false === ( new \ReflectionProperty( \WC_Session::class, '_dirty' ) )->getValue( $session );
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Drop the early classic session, cart and customer and detach their hooks.
	 *
	 * @param object $woocommerce WooCommerce singleton.
	 */
	public function discard_classic_session( object $woocommerce ): bool {
		try {
			$owners = array_filter(
				array(
					$woocommerce->session ?? null,
					$woocommerce->customer ?? null,
					$woocommerce->cart ?? null,
					$this->cart_session_component( $woocommerce->cart ?? null ),
				),
				'is_object'
			);
			if ( ! $this->detach_hooks_owned_by( $owners ) ) {
				return false;
			}

			$woocommerce->session  = null;
			$woocommerce->customer = null;
			$woocommerce->cart     = null;
			return true;
		} catch ( \Throwable ) {
			return false;
		}
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

	/**
	 * WooCommerce keeps the cart's persistence callbacks on a private helper object.
	 *
	 * @param mixed $cart WooCommerce cart.
	 */
	private function cart_session_component( mixed $cart ): ?object {
		if ( ! $cart instanceof \WC_Cart ) {
			return null;
		}
		try {
			$component = ( new \ReflectionProperty( \WC_Cart::class, 'session' ) )->getValue( $cart );
			return is_object( $component ) ? $component : null;
		} catch ( \Throwable ) {
			return null;
		}
	}

	/**
	 * Remove every callback registered on one of these exact objects.
	 *
	 * @param object[] $owners Objects whose hook callbacks must go.
	 */
	private function detach_hooks_owned_by( array $owners ): bool {
		global $wp_filter;
		if ( array() === $owners || ! is_array( $wp_filter ) ) {
			return array() === $owners;
		}

		$registered = array();
		foreach ( $wp_filter as $hook => $hook_object ) {
			foreach ( $hook_object->callbacks ?? array() as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'] ?? null;
					if ( is_array( $function ) && is_object( $function[0] ?? null ) && in_array( $function[0], $owners, true ) ) {
						$registered[] = array( (string) $hook, $function, (int) $priority );
					}
				}
			}
		}

		$removed = true;
		foreach ( $registered as list( $hook, $function, $priority ) ) {
			$removed = remove_filter( $hook, $function, $priority ) && $removed;
		}
		return $removed;
	}
}
