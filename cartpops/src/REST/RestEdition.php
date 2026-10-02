<?php
/**
 * Intended physical edition ownership for a REST route.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\REST;

enum RestEdition: string {
	case SHARED = 'shared';
	case PRO    = 'pro';
}
