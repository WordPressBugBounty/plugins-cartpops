<?php
/**
 * Immutable edition-specific upgrade preparation result.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Setup;

/** Represent either one executable plan or one closed terminal preflight. */
final class SiteUpgradePreparation {

	/**
	 * Construct one internally valid preparation result.
	 *
	 * @param SiteUpgradePlan|null $plan             Prepared implementation-specific plan.
	 * @param UpgradeOutcome|null  $terminal_outcome Terminal preflight result.
	 */
	private function __construct(
		private readonly ?SiteUpgradePlan $plan,
		private readonly ?UpgradeOutcome $terminal_outcome
	) {}

	/**
	 * Return one executable immutable plan.
	 *
	 * @param SiteUpgradePlan $plan Prepared implementation-specific plan.
	 */
	public static function prepared( SiteUpgradePlan $plan ): self {
		return new self( $plan, null );
	}

	/**
	 * Return one terminal result that requires no edition-specific execution.
	 *
	 * @param UpgradeOutcome $outcome Terminal preflight result.
	 *
	 * @throws \InvalidArgumentException When passed a post-mutation outcome.
	 */
	public static function terminal( UpgradeOutcome $outcome ): self {
		if ( ! in_array( $outcome, array( UpgradeOutcome::NOT_APPLICABLE, UpgradeOutcome::FUTURE_VERSION, UpgradeOutcome::FAILED ), true ) ) {
			throw new \InvalidArgumentException( 'Invalid site-upgrade preparation outcome.' );
		}

		return new self( null, $outcome );
	}

	/** Return the executable plan, or null for a terminal preflight. */
	public function plan(): ?SiteUpgradePlan {
		return $this->plan;
	}

	/** Return the terminal preflight result, or null for an executable plan. */
	public function terminal_outcome(): ?UpgradeOutcome {
		return $this->terminal_outcome;
	}
}
