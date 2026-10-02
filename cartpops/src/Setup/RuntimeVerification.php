<?php
/**
 * Edition-specific runtime verification depth.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Setup;

/** Distinguish a normal hot-path convergence from an explicit runtime proof. */
enum RuntimeVerification {
	case CONVERGE_PENDING;
	case VERIFY;
}
