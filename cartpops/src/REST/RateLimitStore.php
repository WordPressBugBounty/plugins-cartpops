<?php
/**
 * Rate-limit persistence boundary.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\REST;

interface RateLimitStore {

	/**
	 * Atomically consume one dimension.
	 *
	 * @param string $key    Privacy-hashed dimension key.
	 * @param int    $limit  Maximum requests per window.
	 * @param int    $window Fixed-window length in seconds.
	 * @param int    $now    Current Unix timestamp.
	 */
	public function consume( string $key, int $limit, int $window, int $now ): RateLimitDecision;

	/**
	 * Atomically consume every key or none of them.
	 *
	 * @param string[] $keys   Privacy-hashed independent dimensions.
	 * @param int      $limit  Maximum requests per window.
	 * @param int      $window Fixed-window length in seconds.
	 * @param int      $now    Current Unix timestamp.
	 */
	public function consume_all( array $keys, int $limit, int $window, int $now ): RateLimitDecision;

	/**
	 * Delete one bounded batch of expired plugin-owned rows.
	 *
	 * @param int $now Current Unix timestamp.
	 * @return int|false Number removed, or false when persistence is unavailable.
	 */
	public function cleanup( int $now ): int|false;
}
