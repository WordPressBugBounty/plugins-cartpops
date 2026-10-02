<?php
/**
 * Simple dependency injection container.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops;

/**
 * Simple dependency injection container.
 */
final class Container {

	/**
	 * Registered factory callbacks keyed by binding identifier.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = array();

	/**
	 * Resolved singleton instances keyed by binding identifier.
	 *
	 * @var array<string, object>
	 */
	private array $singletons = array();

	/**
	 * Identifiers that should be resolved as singletons.
	 *
	 * @var array<string, true>
	 */
	private array $singleton_keys = array();

	/**
	 * Register a singleton binding. The factory is called once and the result is cached.
	 *
	 * @param string   $id      Binding identifier.
	 * @param callable $factory Factory callback that builds the instance.
	 */
	public function singleton( string $id, callable $factory ): void {
		$this->factories[ $id ]      = $factory;
		$this->singleton_keys[ $id ] = true;
	}

	/**
	 * Replace a singleton binding and discard any previously resolved instance.
	 *
	 * @param string   $id      Binding identifier.
	 * @param callable $factory Factory callback that builds the replacement.
	 */
	public function replace_singleton( string $id, callable $factory ): void {
		unset( $this->singletons[ $id ] );
		$this->singleton( $id, $factory );
	}

	/**
	 * Register a factory binding. A new instance is created on every call.
	 *
	 * @param string   $id      Binding identifier.
	 * @param callable $factory Factory callback that builds the instance.
	 */
	public function bind( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->singleton_keys[ $id ] );
	}

	/**
	 * Resolve a binding.
	 *
	 * @param  string $id Binding identifier.
	 * @return mixed  The resolved instance.
	 * @throws \RuntimeException If the binding is not found.
	 */
	public function get( string $id ): mixed {
		if ( isset( $this->singletons[ $id ] ) ) {
			return $this->singletons[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \RuntimeException(
				sprintf( 'No binding found for "%s".', esc_html( $id ) )
			);
		}

		$instance = ( $this->factories[ $id ] )( $this );

		if ( isset( $this->singleton_keys[ $id ] ) ) {
			$this->singletons[ $id ] = $instance;
		}

		return $instance;
	}

	/**
	 * Check if a binding exists.
	 *
	 * @param  string $id Binding identifier.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}
}
