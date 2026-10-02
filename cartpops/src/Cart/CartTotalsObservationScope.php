<?php
/**
 * Shared state for nested observational cart totals calculations.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Cart;

/** Let cart hooks distinguish observational totals from authoritative mutations. */
final class CartTotalsObservationScope {
	/**
	 * Number of currently nested observational totals operations.
	 *
	 * @var int
	 */
	private static int $depth = 0;

	/** Enter one observational totals operation. */
	public static function begin(): void {
		++self::$depth;
	}

	/** Leave one observational totals operation without allowing underflow. */
	public static function end(): void {
		self::$depth = max( 0, self::$depth - 1 );
	}

	/** Whether the current call stack is observing rather than mutating totals. */
	public static function is_active(): bool {
		return self::$depth > 0;
	}
}
