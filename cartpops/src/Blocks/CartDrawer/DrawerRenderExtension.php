<?php
/**
 * Cart Drawer render extension contract.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Blocks\CartDrawer;

/** Build one atomic, registry-validated drawer contribution. */
interface DrawerRenderExtension {
	/** Build one atomic drawer render contribution. */
	public function contribution(): DrawerRenderContribution;
}
