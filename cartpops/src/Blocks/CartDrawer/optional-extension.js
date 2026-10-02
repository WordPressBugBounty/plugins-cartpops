/**
 * Edition-neutral Cart Drawer extension boundary.
 *
 * Optional edition modules register one disjoint Interactivity action named
 * `runOptionalCartDrawerExtension`. The shared drawer invokes it only through
 * this bounded protocol, so shared and optional modules can be evaluated in
 * either order without importing one another or duplicating registries.
 *
 * @package
 */

const ALLOWED_PHASES = new Set( [
	'cart-items-project',
	'cart-accepted',
	'cart-presentation-restored',
	'cart-item-removed',
	'subtotal-changed',
	'coupon-changing',
	'coupon-accepted',
	'coupon-applied',
	'supplementary-refresh',
	'recommendations-accepted',
	'recommendation-added',
	'drawer-opened',
	'drawer-closing',
	'escape-key',
	'focus-layer',
	'cart-expired',
	'cart-add-detected',
	'checkout-selected',
] );

const MAX_DETAIL_KEYS = 16;

/**
 * Record a safely observable extension failure without disrupting the drawer.
 *
 * @param {Object} state Shared Interactivity state.
 * @param {string} phase Rejected extension phase.
 */
function recordFailure( state, phase ) {
	try {
		const current = state.optionalExtensionStatus || {};
		state.optionalExtensionStatus = {
			...current,
			available: true,
			failures: Math.max( 0, Number( current.failures ) || 0 ) + 1,
			lastPhase: phase,
		};
	} catch {
		// Observability is best-effort; the shared workflow must still continue.
	}
}

/**
 * Snapshot a small plain data record without executing accessors or Proxy traps.
 *
 * @param {*} detail Candidate phase details.
 * @return {Object|null} Frozen own-data snapshot, or null when unsafe.
 */
function snapshotDetail( detail ) {
	try {
		if (
			! detail ||
			typeof detail !== 'object' ||
			Array.isArray( detail )
		) {
			return null;
		}
		const prototype = Object.getPrototypeOf( detail );
		if ( prototype !== Object.prototype && prototype !== null ) {
			return null;
		}
		const keys = Reflect.ownKeys( detail );
		if (
			keys.length > MAX_DETAIL_KEYS ||
			keys.some( ( key ) => typeof key !== 'string' )
		) {
			return null;
		}

		const descriptors = Object.getOwnPropertyDescriptors( detail );
		const snapshot = {};
		for ( const key of keys ) {
			const descriptor = descriptors[ key ];
			if (
				! descriptor ||
				! Object.prototype.hasOwnProperty.call( descriptor, 'value' )
			) {
				return null;
			}
			Object.defineProperty( snapshot, key, {
				configurable: false,
				enumerable: true,
				value: descriptor.value,
				writable: false,
			} );
		}
		return Object.freeze( snapshot );
	} catch {
		return null;
	}
}

/**
 * Invoke the optional drawer extension through the shared store.
 *
 * The boundary is deliberately single-owner and phase-bounded. Synchronous
 * failures and rejected promises are converted to `undefined`, allowing every
 * shared workflow to continue with its normal Free behavior.
 *
 * @param {Function} getStore Resolve the current `cartpops` store.
 * @param {Object}   state    Shared Interactivity state.
 * @param {string}   phase    Allowlisted lifecycle phase.
 * @param {Object}   detail   Bounded phase details.
 * @return {*|Promise<*>|undefined} Optional result, or undefined on absence/failure.
 */
export function invokeOptionalCartDrawerExtension(
	getStore,
	state,
	phase,
	detail = {}
) {
	if ( ! ALLOWED_PHASES.has( phase ) ) {
		return undefined;
	}
	const detailSnapshot = snapshotDetail( detail );
	if ( ! detailSnapshot ) {
		recordFailure( state, phase );
		return undefined;
	}

	let handler;
	try {
		handler = getStore()?.actions?.runOptionalCartDrawerExtension;
	} catch {
		recordFailure( state, phase );
		return undefined;
	}

	if ( typeof handler !== 'function' ) {
		try {
			state.optionalExtensionStatus = {
				...( state.optionalExtensionStatus || {} ),
				available: false,
				lastPhase: phase,
			};
		} catch {
			// Observability is best-effort.
		}
		return undefined;
	}

	try {
		const current = state.optionalExtensionStatus || {};
		state.optionalExtensionStatus = {
			...current,
			available: true,
			invocations: Math.max( 0, Number( current.invocations ) || 0 ) + 1,
			lastPhase: phase,
		};
	} catch {
		// Observability is best-effort.
	}

	try {
		const result = handler( phase, detailSnapshot );
		if ( result && typeof result.then === 'function' ) {
			return Promise.resolve( result ).catch( () => {
				recordFailure( state, phase );
				return undefined;
			} );
		}
		return result;
	} catch {
		recordFailure( state, phase );
		return undefined;
	}
}
