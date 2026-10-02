<?php
/**
 * One atomic Cart Drawer render contribution.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Blocks\CartDrawer;

/** Immutable state, CSS, and markup proposed by one drawer extension. */
final class DrawerRenderContribution {
	/**
	 * Create a render contribution.
	 *
	 * @param array<string, mixed>  $state         Interactivity state additions.
	 * @param array<string, scalar> $css_variables CSS custom properties keyed by exact property name.
	 * @param array<string, string> $slots         Markup keyed by DrawerRenderSlot value.
	 */
	public function __construct(
		private readonly array $state = array(),
		private readonly array $css_variables = array(),
		private readonly array $slots = array(),
	) {}

	/**
	 * Return Interactivity state additions.
	 *
	 * @return array<string, mixed>
	 */
	public function state(): array {
		return $this->state;
	}

	/**
	 * Return CSS custom properties.
	 *
	 * @return array<string, scalar>
	 */
	public function css_variables(): array {
		return $this->css_variables;
	}

	/**
	 * Return markup for one closed slot.
	 *
	 * @param DrawerRenderSlot $slot Closed drawer render slot.
	 */
	public function markup( DrawerRenderSlot $slot ): string {
		return $this->slots[ $slot->value ] ?? '';
	}

	/**
	 * Return all contributed slot markup.
	 *
	 * @return array<string, string>
	 */
	public function slots(): array {
		return $this->slots;
	}
}
