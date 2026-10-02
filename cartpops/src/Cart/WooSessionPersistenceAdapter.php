<?php
/**
 * Explicit persistence-quarantine seam for non-native Woo session adapters.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Cart;

/** Allows a custom/test session to prove its deferred writer was disabled. */
interface WooSessionPersistenceAdapter {
	/** Disable every deferred writer for the remainder of this request. */
	public function cartpops_disable_persistence(): bool;
}
