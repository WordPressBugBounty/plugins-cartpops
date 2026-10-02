<?php
/**
 * Closed CartPops product capability vocabulary.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Edition;

/** Product behavior owned by either shared source or the physical Pro edition. */
enum EditionCapability: string {
	case CART_DRAWER                       = 'cart_drawer';
	case CART_LAUNCHER                     = 'cart_launcher';
	case CART_MANAGEMENT                   = 'cart_management';
	case COUPONS                           = 'coupons';
	case TOTALS                            = 'totals';
	case BASIC_DESIGN                      = 'basic_design';
	case BASIC_WOOCOMMERCE_RECOMMENDATIONS = 'basic_woocommerce_recommendations';
	case SHIPPING_METER                    = 'shipping_meter';
	case REWARDS                           = 'rewards';
	case SMART_ADDONS                      = 'smart_addons';
	case BUNDLE_BUILDER                    = 'bundle_builder';
	case NOTIFICATIONS                     = 'notifications';
	case PRODUCT_SPOTLIGHT                 = 'product_spotlight';
	case ANALYTICS                         = 'analytics';
	case ADVANCED_RECOMMENDATIONS          = 'advanced_recommendations';
	case CUSTOM_RECOMMENDATIONS            = 'custom_recommendations';
	case AUTOMATION                        = 'automation';
}
