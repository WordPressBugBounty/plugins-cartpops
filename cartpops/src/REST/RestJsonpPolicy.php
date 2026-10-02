<?php
/**
 * Declarative REST JSONP policy.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\REST;

/** JSONP treatment applied to one namespace handler. */
enum RestJsonpPolicy: string {
	case REJECT = 'reject';
}
