<?php
/**
 * Dedicated fail-closed site-upgrade context exception.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Setup;

/** Raised when an upgrade can no longer prove its exact site/database target. */
final class SiteUpgradeContextDrift extends \RuntimeException {}
