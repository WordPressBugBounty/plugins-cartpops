<?php
/**
 * Physical source ownership for CartPops capabilities.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Edition;

/** Shared behavior ships in both artifacts; Pro behavior ships only in Pro. */
enum EditionOwnership: string {
	case SHARED = 'shared';
	case PRO    = 'pro';
}
