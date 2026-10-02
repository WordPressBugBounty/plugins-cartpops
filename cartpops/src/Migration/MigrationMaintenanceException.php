<?php
/**
 * Value-free bounded migration maintenance outcome.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Migration;

/**
 * Stops automatic work when a bounded data limit requires human maintenance.
 */
final class MigrationMaintenanceException extends \RuntimeException {
}
