<?php
/**
 * Typed result of preparing WooCommerce session authority for one REST request.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\REST;

/** Distinguish invalid authority from unavailable WooCommerce infrastructure. */
enum WooSessionBootstrapOutcome {
	case READY;

	case INVALID_CREDENTIAL;

	case UNAVAILABLE;
}
