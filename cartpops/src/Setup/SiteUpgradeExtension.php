<?php
/**
 * Optional edition-specific site upgrade boundary.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Setup;

/** Keep shared upgrades independent from physically removable implementations. */
interface SiteUpgradeExtension {

	/**
	 * Prepare one immutable current-site plan before any upgrade mutation.
	 *
	 * @param SiteUpgradeContext|null $context Exact whole-convergence binding.
	 */
	public function prepare( ?SiteUpgradeContext $context = null ): SiteUpgradePreparation;

	/**
	 * Execute a prepared current-site plan without re-resolving authority.
	 *
	 * @param SiteUpgradePlan         $plan         Exact immutable preparation.
	 * @param RuntimeVerification     $verification Required verification depth.
	 * @param SiteUpgradeContext|null $context  Exact whole-convergence binding.
	 */
	public function execute( SiteUpgradePlan $plan, RuntimeVerification $verification, ?SiteUpgradeContext $context = null ): UpgradeOutcome;
}
