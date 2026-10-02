<?php
/**
 * Stable Cart Drawer extension positions.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Blocks\CartDrawer;

/** Closed render positions owned by the CartPops drawer template. */
enum DrawerRenderSlot: string {
	case CONTENT_START           = 'content_start';
	case CART_ITEM_AFTER_ACTIONS = 'cart_item_after_actions';
	case PANEL_AFTER_CONTENT     = 'panel_after_content';
	case FOOTER_START            = 'footer_start';
}
