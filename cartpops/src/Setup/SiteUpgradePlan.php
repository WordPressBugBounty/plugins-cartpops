<?php
/**
 * Immutable edition-specific upgrade plan marker.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Setup;

/**
 * Keep shared orchestration independent from a physically removable plan.
 *
 * Concrete plans carry the exact preflight state required by their extension.
 */
interface SiteUpgradePlan {}
