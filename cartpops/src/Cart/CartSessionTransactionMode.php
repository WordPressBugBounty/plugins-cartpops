<?php
/**
 * Hydration policy for an owned WooCommerce session transaction.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Cart;

/** Keep the local-render exception explicit and unavailable to mutation calls. */
enum CartSessionTransactionMode {
	case STRICT;
	case LOCAL_DIRTY_OBSERVATION;
}
