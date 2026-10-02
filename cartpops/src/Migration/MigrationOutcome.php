<?php
/**
 * Durable outcome of one legacy migration attempt.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Migration;

/**
 * Lets callers distinguish verified completion from a retryable failure.
 */
enum MigrationOutcome: string {

	case COMPLETE             = 'complete';
	case FAILED               = 'failed';
	case BUSY                 = 'busy';
	case FUTURE_VERSION       = 'future_version';
	case MAINTENANCE_REQUIRED = 'maintenance_required';
	case NOT_APPLICABLE       = 'not_applicable';
}
