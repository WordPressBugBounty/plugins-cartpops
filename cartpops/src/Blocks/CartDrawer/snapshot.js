/**
 * CartPops Cart Drawer — sessionStorage cart snapshot.
 *
 * Persists cart state to sessionStorage for instant restore on cached pages.
 * Full-page caching (e.g. Kinsta) serves stale wp_interactivity_state() —
 * this lets us show the previous cart data instantly while fetchCart() runs.
 *
 * @package
 */

import { store } from '@wordpress/interactivity';

const CART_CACHE_KEY = 'cpops_cart';

let snapshotTimerId = null;

/**
 * Persist the current cart state to sessionStorage (debounced 500ms).
 */
export function saveCartSnapshot() {
	const { state } = store( 'cartpops' );
	if ( snapshotTimerId ) {
		clearTimeout( snapshotTimerId );
	}
	snapshotTimerId = setTimeout( () => {
		snapshotTimerId = null;
		try {
			sessionStorage.setItem(
				CART_CACHE_KEY,
				JSON.stringify( {
					ts: Date.now(),
					items: state.cartItems,
					count: state.cartCount,
					total: state.cartTotal,
					subtotal: state.cartSubtotal,
					fees: state.cartFees,
					shipping: state.cartShipping,
					tax: state.cartTax,
					coupons: state.coupons,
					_rawSubtotal: state._rawSubtotal,
					_rawTotal: state._rawTotal,
					_currencyTotals: state._currencyTotals,
				} )
			);
		} catch ( e ) {
			// sessionStorage unavailable.
		}
	}, 500 );
}

/**
 * Restore cart state from a sessionStorage snapshot.
 *
 * @param {Set} pendingRemovals Item keys currently being removed — filtered
 *                              out so a restored snapshot never re-adds them.
 * @return {boolean} True if a usable snapshot was restored.
 */
export function restoreCartSnapshot( pendingRemovals ) {
	try {
		const json = sessionStorage.getItem( CART_CACHE_KEY );
		if ( ! json ) {
			return false;
		}
		const d = JSON.parse( json );
		if ( ! d.items || d.items.length === 0 ) {
			return false;
		}

		// Discard stale snapshots (older than 30 seconds).
		if ( d.ts && Date.now() - d.ts > 30000 ) {
			return false;
		}

		const { state } = store( 'cartpops' );
		state.cartItems = ( d.items || [] ).filter(
			( item ) => ! pendingRemovals.has( item.key )
		);
		state.cartCount = state.cartItems.reduce(
			( sum, i ) => sum + ( i.quantity || 1 ),
			0
		);
		state.cartTotal = d.total || '';
		state.cartSubtotal = d.subtotal || '';
		state.cartFees = d.fees || [];
		state.cartShipping = d.shipping || '';
		state.cartTax = d.tax || '';
		state.coupons = d.coupons || [];
		if ( d._currencyTotals ) {
			state._rawSubtotal = d._rawSubtotal || 0;
			state._rawTotal = d._rawTotal || 0;
			state._currencyTotals = d._currencyTotals;
		}
		return true;
	} catch ( e ) {
		return false;
	}
}
