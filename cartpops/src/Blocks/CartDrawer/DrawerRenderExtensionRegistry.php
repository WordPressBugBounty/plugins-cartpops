<?php
/**
 * Fail-closed Cart Drawer render extension registry.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Blocks\CartDrawer;

/** Compose bounded internal extensions without allowing shared-state replacement. */
final class DrawerRenderExtensionRegistry {
	private const MAX_EXTENSIONS    = 4;
	private const MAX_STATE_DEPTH   = 16;
	private const MAX_STATE_NODES   = 16384;
	private const MAX_STATE_STRING  = 1048576;
	private const MAX_CSS_VARIABLES = 64;
	private const MAX_CSS_VALUE     = 512;
	private const MAX_SLOT_BYTES    = 524288;

	/**
	 * Closed, deterministic internal extension sequence.
	 *
	 * @var DrawerRenderExtension[]
	 */
	private array $extensions = array();

	/**
	 * Register one plugin-owned final implementation at most once.
	 *
	 * @param DrawerRenderExtension $extension Candidate internal extension.
	 * @throws \InvalidArgumentException When the class is not final plugin-owned source.
	 * @throws \LogicException When the bounded registry is already full.
	 */
	public function register_internal( DrawerRenderExtension $extension ): void {
		if ( ! $this->extension_is_trusted( $extension ) ) {
			throw new \InvalidArgumentException( 'CartPops drawer render extensions must be final plugin-owned source.' );
		}
		foreach ( $this->extensions as $registered ) {
			if ( $registered === $extension || $registered::class === $extension::class ) {
				return;
			}
		}
		if ( count( $this->extensions ) >= self::MAX_EXTENSIONS ) {
			throw new \LogicException( 'CartPops drawer render extensions exceed the closed bound.' );
		}

		$this->extensions[] = $extension;
	}

	/**
	 * Require a final CartPops class loaded from the exact canonical source root.
	 *
	 * @param DrawerRenderExtension $extension Candidate internal extension.
	 */
	private function extension_is_trusted( DrawerRenderExtension $extension ): bool {
		try {
			$reflection  = new \ReflectionClass( $extension );
			$source_root = defined( 'CARTPOPS_PATH' ) && is_string( CARTPOPS_PATH )
				? realpath( CARTPOPS_PATH . 'src' )
				: false;
			$source_file = $reflection->getFileName();
			$canonical   = is_string( $source_file ) ? realpath( $source_file ) : false;
			return is_string( $source_root )
				&& is_string( $source_file )
				&& is_string( $canonical )
				&& realpath( CARTPOPS_PATH . 'src' ) === CARTPOPS_PATH . 'src'
				&& ! is_link( CARTPOPS_PATH . 'src' )
				&& $canonical === $source_file
				&& ! is_link( $canonical )
				&& str_starts_with( $canonical, $source_root . DIRECTORY_SEPARATOR )
				&& str_starts_with( $reflection->getName(), 'CartPops\\' )
				&& $reflection->isFinal()
				&& ! $reflection->isAnonymous();
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Compose valid contributions atomically around immutable shared state.
	 *
	 * A throwing or malformed extension contributes nothing. State additions may
	 * extend associative branches such as config or i18n, but may never replace
	 * an existing shared or earlier-extension leaf.
	 *
	 * @param array<string, mixed> $shared_state Complete shared drawer state.
	 */
	public function compose( array $shared_state ): DrawerRenderContribution {
		$state = $shared_state;
		$css   = array();
		$slots = array_fill_keys(
			array_map( static fn( DrawerRenderSlot $slot ): string => $slot->value, DrawerRenderSlot::cases() ),
			''
		);

		foreach ( $this->extensions as $extension ) {
			try {
				$contribution = $extension->contribution();
				$next_state   = $state;
				$next_css     = $css;
				$next_slots   = $slots;
				$nodes        = 0;
				if (
					! $this->state_is_valid( $contribution->state(), 0, $nodes )
					|| ! $this->merge_without_overwrite( $next_state, $contribution->state() )
					|| ! $this->merge_css( $next_css, $contribution->css_variables() )
					|| ! $this->merge_slots( $next_slots, $contribution->slots() )
				) {
					continue;
				}
			} catch ( \Throwable ) {
				continue;
			}

			$state = $next_state;
			$css   = $next_css;
			$slots = $next_slots;
		}

		return new DrawerRenderContribution( $state, $css, $slots );
	}

	/**
	 * Recursively validate JSON-compatible Interactivity state.
	 *
	 * @param mixed $value Candidate value.
	 * @param int   $depth Current recursion depth.
	 * @param int   $nodes Running node count.
	 */
	private function state_is_valid( mixed $value, int $depth, int &$nodes ): bool {
		if ( $depth > self::MAX_STATE_DEPTH || ++$nodes > self::MAX_STATE_NODES ) {
			return false;
		}
		if ( is_null( $value ) || is_bool( $value ) || is_int( $value ) ) {
			return true;
		}
		if ( is_float( $value ) ) {
			return is_finite( $value );
		}
		if ( is_string( $value ) ) {
			return strlen( $value ) <= self::MAX_STATE_STRING;
		}
		if ( $value instanceof \stdClass ) {
			$value = get_object_vars( $value );
		} elseif ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $key => $child ) {
			if ( ! is_int( $key ) && ! is_string( $key ) ) {
				return false;
			}
			if ( ! $this->state_is_valid( $child, $depth + 1, $nodes ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Merge only new leaves; associative branch traversal cannot overwrite.
	 *
	 * @param array<mixed> $target   Existing state.
	 * @param array<mixed> $addition Proposed additions.
	 */
	private function merge_without_overwrite( array &$target, array $addition ): bool {
		foreach ( $addition as $key => $value ) {
			if ( ! array_key_exists( $key, $target ) ) {
				$target[ $key ] = $value;
				continue;
			}
			if (
				! is_array( $target[ $key ] )
				|| ! is_array( $value )
				|| array_is_list( $target[ $key ] )
				|| array_is_list( $value )
				|| ! $this->merge_without_overwrite( $target[ $key ], $value )
			) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Append unique, bounded CSS custom properties.
	 *
	 * @param array<string, scalar> $target   Existing properties.
	 * @param array<string, scalar> $addition Proposed properties.
	 */
	private function merge_css( array &$target, array $addition ): bool {
		if ( count( $target ) + count( $addition ) > self::MAX_CSS_VARIABLES ) {
			return false;
		}
		foreach ( $addition as $name => $value ) {
			if (
				! is_string( $name )
				|| 1 !== preg_match( '/^--cpops-[a-z][a-z0-9-]{0,63}$/D', $name )
				|| isset( $target[ $name ] )
				|| ! is_scalar( $value )
				|| strlen( (string) $value ) > self::MAX_CSS_VALUE
				|| 1 === preg_match( '/[;{}<>\x00-\x1F\x7F]/', (string) $value )
			) {
				return false;
			}
			$target[ $name ] = $value;
		}

		return true;
	}

	/**
	 * Append markup only to declared slots and within per-slot bounds.
	 *
	 * @param array<string, string> $target   Existing slot markup.
	 * @param array<string, string> $addition Proposed markup.
	 */
	private function merge_slots( array &$target, array $addition ): bool {
		foreach ( $addition as $slot => $markup ) {
			if (
				! is_string( $slot )
				|| ! array_key_exists( $slot, $target )
				|| ! is_string( $markup )
				|| strlen( $target[ $slot ] ) + strlen( $markup ) > self::MAX_SLOT_BYTES
			) {
				return false;
			}
			$safe_markup = DrawerRenderMarkupPolicy::sanitize( $markup );

			$target[ $slot ] .= $safe_markup;
		}

		return true;
	}
}
