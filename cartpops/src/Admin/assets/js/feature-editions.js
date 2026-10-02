/**
 * Admin feature edition assignments.
 *
 * This catalog controls upgrade affordances only. Runtime authorization remains
 * the responsibility of the server-side edition and entitlement boundaries.
 */
export const FEATURE_EDITIONS = Object.freeze( {
	drawer: 'free',
	launcher: 'free',
	recommendations: 'free',
	'custom-recommendations': 'pro',
	'shipping-meter': 'pro',
	'smart-addons': 'pro',
	'smart-bar': 'pro',
	'bundle-builder': 'pro',
	analytics: 'pro',
} );

/**
 * Determine whether an admin feature should display a Pro affordance.
 *
 * @param {string} feature Feature key.
 * @return {boolean} Whether the feature belongs to the Pro edition.
 */
export function isProFeature( feature ) {
	return FEATURE_EDITIONS[ feature ] === 'pro';
}
