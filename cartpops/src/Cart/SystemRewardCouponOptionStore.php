<?php
/**
 * Exact option-row persistence for the CartPops system reward identifier.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Cart;

/**
 * Owns the only durable operations needed by the reward-identifier protocol.
 */
interface SystemRewardCouponOptionStore {
	/**
	 * Read the exact serialized bytes for one current-site option.
	 *
	 * @param string $name Site-local option name.
	 * @return string|null|false Raw bytes, null when absent, or false on failure.
	 */
	public function read_raw( string $name ): string|false|null;

	/**
	 * Add one current-site option with autoload disabled.
	 *
	 * @param string $name  Site-local option name.
	 * @param mixed  $value Option value.
	 */
	public function add_non_autoloaded( string $name, mixed $value ): bool;

	/**
	 * Replace only the exact serialized row previously observed.
	 *
	 * @param string $name         Site-local option name.
	 * @param string $expected_raw Exact serialized bytes previously read.
	 * @param mixed  $replacement  Replacement option value.
	 */
	public function compare_replace_non_autoloaded( string $name, string $expected_raw, mixed $replacement ): bool;

	/**
	 * Delete only the exact serialized row previously observed.
	 *
	 * @param string $name         Site-local option name.
	 * @param string $expected_raw Exact serialized bytes previously read.
	 */
	public function compare_delete( string $name, string $expected_raw ): bool;
}
