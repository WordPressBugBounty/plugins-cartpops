/**
 * Return a safe customer-facing coupon label.
 *
 * @param {Object|null|undefined} coupon Coupon response state.
 * @return {string} Customer-visible label.
 */
export function couponLabel( coupon ) {
	if ( typeof coupon?.label === 'string' && coupon.label.length > 0 ) {
		return coupon.label;
	}

	return typeof coupon?.code === 'string' ? coupon.code : '';
}

/**
 * Whether the drawer may send a customer coupon-removal request.
 *
 * Malformed state and internal entitlements fail closed. The server performs
 * the same authority check; this helper only prevents a misleading UI action.
 *
 * @param {Object|null|undefined} coupon Coupon response state.
 * @return {boolean} Whether a remove action is valid.
 */
export function isCouponRemovable( coupon ) {
	return Boolean(
		coupon &&
			coupon.is_system !== true &&
			coupon.removable === true &&
			typeof coupon.code === 'string' &&
			coupon.code.length > 0
	);
}
