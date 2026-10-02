<?php
/**
 * Explicit identity seam for non-production Woo session adapters.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Cart;

/** Used by faithful local adapters that model Woo's exact durable identity. */
interface WooSessionIdentityAdapter {
	/**
	 * Return the exact supported durable Woo session identity.
	 *
	 * @return array{table: string, customer_id: string}
	 */
	public function cartpops_database_identity(): array;
}
