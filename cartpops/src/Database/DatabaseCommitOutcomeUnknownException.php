<?php
/**
 * Ambiguous database commit outcome.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Database;

/** A caller must not label the operation safe to retry. */
final class DatabaseCommitOutcomeUnknownException extends \RuntimeException {}
