<?php
/**
 * CartPops REST route data/authority classes.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\REST;

enum RestRouteClass: string {
	case ADMIN        = 'admin';
	case GLOBAL_READ  = 'global_read';
	case SESSION_READ = 'session_read';
	case MUTATION     = 'mutation';
}
