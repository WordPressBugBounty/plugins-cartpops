<?php
/**
 * Declarative REST cache policy.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\REST;

/** Cache treatment applied to one namespace handler. */
enum RestCachePolicy: string {
	case PUBLIC           = 'public';
	case PRIVATE_NO_STORE = 'private_no_store';
}
