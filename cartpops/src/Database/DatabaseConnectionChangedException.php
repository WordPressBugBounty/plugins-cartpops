<?php
/**
 * Database connection continuity failure.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Database;

/** The operation crossed from the captured handle to an unknown replacement. */
final class DatabaseConnectionChangedException extends \RuntimeException {}
