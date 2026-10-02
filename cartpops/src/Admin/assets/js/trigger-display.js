/**
 * Resolve the canonical trigger value shown by the V2 admin.
 *
 * @package
 */

/**
 * @param {*} trigger Canonical trigger value.
 * @return {string} One of the two admin select values.
 */
export function triggerDisplayValue( trigger ) {
	if ( trigger === undefined ) {
		return 'add_to_cart';
	}

	return trigger === 'add_to_cart' ? 'add_to_cart' : 'launcher';
}
