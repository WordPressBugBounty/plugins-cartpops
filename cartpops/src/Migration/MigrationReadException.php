<?php
/**
 * Value-free exact database read failure.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Migration;

/**
 * Stops migration when absence cannot be distinguished from a database error.
 */
final class MigrationReadException extends \RuntimeException {
}
