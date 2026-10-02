<?php
/**
 * Declarative REST CORS policy.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\REST;

/** Browser-origin policy applied to one namespace handler. */
enum RestCorsPolicy: string {
	case SAME_SITE_CREDENTIALS = 'same_site_credentials';
}
