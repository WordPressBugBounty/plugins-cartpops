<?php
/**
 * Result of one stale paid-cart artifact sanitization attempt.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Cart;

/** Keep retry and durability outcomes explicit for callers. */
enum StalePaidCartArtifactSanitizationResult: string {
	case UNCHANGED       = 'unchanged';
	case SANITIZED       = 'sanitized';
	case BLOCKED         = 'blocked';
	case OUTCOME_UNKNOWN = 'outcome_unknown';
}
