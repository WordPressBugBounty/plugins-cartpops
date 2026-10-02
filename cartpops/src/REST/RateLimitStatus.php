<?php
/**
 * Typed REST rate-limit outcomes.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\REST;

/** A caller must never have to infer why a rate-limit check failed. */
enum RateLimitStatus: string {
	case ALLOWED               = 'allowed';
	case EXHAUSTED             = 'exhausted';
	case STORE_UNAVAILABLE     = 'store_unavailable';
	case INVALID_CONFIGURATION = 'invalid_configuration';
	case MISSING_IDENTITY      = 'missing_identity';
}
