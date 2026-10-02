<?php
/**
 * Mutable site-switch fence state owned by one captured upgrade context.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Setup;

/** Keep request-local hook state outside the immutable captured binding. */
final class SiteUpgradeFenceState {

	/**
	 * Whether the switch fence is currently installed.
	 *
	 * @var bool
	 */
	public bool $installed = false;

	/**
	 * Whether any switch or restore attempted to cross the fence.
	 *
	 * @var bool
	 */
	public bool $tripped = false;

	/**
	 * Exact installed callback identity.
	 *
	 * @var callable(int, int, string): void|null
	 */
	public $callback = null;
}
