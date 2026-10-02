<?php
/**
 * Verified outcome of one complete per-site upgrade operation.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Setup;

/**
 * Lets boot and network coordination fail closed without parsing diagnostics.
 */
enum UpgradeOutcome: string {

	case COMPLETE             = 'complete';
	case NOT_APPLICABLE       = 'not_applicable';
	case BUSY                 = 'busy';
	case FUTURE_VERSION       = 'future_version';
	case MAINTENANCE_REQUIRED = 'maintenance_required';
	case FAILED               = 'failed';
}
