/**
 * Realm-wide CartPops cart/session authority coordinator.
 *
 * Every compiled copy resolves the same versioned cell. The binding and shape
 * are immutable, while the nine coordinator fields deliberately remain
 * mutable. Same-realm JavaScript can inspect or change these values; this is a
 * coordination primitive, not a secret boundary.
 *
 * @package
 */

const COORDINATOR_SYMBOL = Symbol.for(
	'cartpops.cart-session-authority.coordinator.v1'
);
const COORDINATOR_BRAND = Symbol.for(
	'cartpops.cart-session-authority.coordinator.brand.v1'
);
const MUTABLE_FIELDS = Object.freeze( [
	'cartTokenBootstrapPromise',
	'authorityRefreshPromise',
	'cartSessionBusyDelayPromise',
	'authorityOwner',
	'committedAuthority',
	'authorityRefreshRequired',
	'authorityEpoch',
	'authorityOwnerEpoch',
	'authorityRefreshLease',
] );
const EXACT_FIELDS = Object.freeze( [ 'brand', 'version', ...MUTABLE_FIELDS ] );

/**
 * Whether a value can hold an owner, authority snapshot, lease, or promise.
 *
 * @param {*} value Candidate reference.
 * @return {boolean} Whether the reference has a supported broad shape.
 */
function isNullableReference( value ) {
	return (
		value === null ||
		( typeof value === 'object' && value !== null ) ||
		typeof value === 'function'
	);
}

/**
 * Whether a value is null or a promise-like asynchronous coordinator value.
 *
 * @param {*} value Candidate promise.
 * @return {boolean} Whether callers can safely await the value.
 */
function isNullablePromise( value ) {
	return (
		value === null ||
		( isNullableReference( value ) && typeof value.then === 'function' )
	);
}

/**
 * Require the exact sealed coordinator schema and field descriptor contract.
 *
 * @param {*} candidate Candidate global cell.
 * @return {boolean} Whether every schema and value invariant is exact.
 */
function isExactCoordinator( candidate ) {
	try {
		if (
			! candidate ||
			typeof candidate !== 'object' ||
			Object.getPrototypeOf( candidate ) !== Object.prototype ||
			! Object.isSealed( candidate )
		) {
			return false;
		}
		const keys = Reflect.ownKeys( candidate );
		if (
			keys.length !== EXACT_FIELDS.length ||
			! EXACT_FIELDS.every( ( field ) => keys.includes( field ) )
		) {
			return false;
		}

		for ( const field of EXACT_FIELDS ) {
			const descriptor = Object.getOwnPropertyDescriptor(
				candidate,
				field
			);
			if (
				! descriptor ||
				descriptor.enumerable !== true ||
				descriptor.configurable !== false ||
				descriptor.writable !== MUTABLE_FIELDS.includes( field ) ||
				! Object.prototype.hasOwnProperty.call( descriptor, 'value' )
			) {
				return false;
			}
		}

		return (
			candidate.brand === COORDINATOR_BRAND &&
			candidate.version === 1 &&
			isNullablePromise( candidate.cartTokenBootstrapPromise ) &&
			isNullablePromise( candidate.authorityRefreshPromise ) &&
			isNullablePromise( candidate.cartSessionBusyDelayPromise ) &&
			isNullableReference( candidate.authorityOwner ) &&
			isNullableReference( candidate.committedAuthority ) &&
			typeof candidate.authorityRefreshRequired === 'boolean' &&
			Number.isSafeInteger( candidate.authorityEpoch ) &&
			candidate.authorityEpoch >= 0 &&
			Number.isSafeInteger( candidate.authorityOwnerEpoch ) &&
			candidate.authorityOwnerEpoch >= 0 &&
			isNullableReference( candidate.authorityRefreshLease )
		);
	} catch {
		return false;
	}
}

/**
 * Create the sole exact mutable coordinator shape.
 *
 * @return {Object} Sealed coordinator cell.
 */
function createCoordinator() {
	const initialValues = {
		authorityRefreshRequired: false,
		authorityEpoch: 0,
		authorityOwnerEpoch: 0,
	};
	const descriptors = {
		brand: {
			value: COORDINATOR_BRAND,
			enumerable: true,
			writable: false,
			configurable: false,
		},
		version: {
			value: 1,
			enumerable: true,
			writable: false,
			configurable: false,
		},
	};
	for ( const field of MUTABLE_FIELDS ) {
		descriptors[ field ] = {
			value: Object.prototype.hasOwnProperty.call( initialValues, field )
				? initialValues[ field ]
				: null,
			enumerable: true,
			writable: true,
			configurable: false,
		};
	}

	return Object.seal( Object.defineProperties( {}, descriptors ) );
}

/**
 * Resolve or install the exact coordinator for one JavaScript realm.
 *
 * A preoccupied inherited, accessor-backed, writable, enumerable, malformed,
 * or wrong-version binding is never replaced and fails closed.
 *
 * @param {Object} scope Global-like scope; injectable for isolated tests.
 * @return {Object|null} Exact shared coordinator, or null when unavailable.
 */
export function resolveCartSessionAuthority( scope = globalThis ) {
	try {
		if (
			! scope ||
			( typeof scope !== 'object' && typeof scope !== 'function' )
		) {
			return null;
		}
		const existing = Object.getOwnPropertyDescriptor(
			scope,
			COORDINATOR_SYMBOL
		);
		if ( existing ) {
			return existing.configurable === false &&
				existing.enumerable === false &&
				existing.writable === false &&
				Object.prototype.hasOwnProperty.call( existing, 'value' ) &&
				isExactCoordinator( existing.value )
				? existing.value
				: null;
		}
		if ( COORDINATOR_SYMBOL in scope ) {
			return null;
		}

		const coordinator = createCoordinator();
		Object.defineProperty( scope, COORDINATOR_SYMBOL, {
			value: coordinator,
			enumerable: false,
			writable: false,
			configurable: false,
		} );
		const installed = Object.getOwnPropertyDescriptor(
			scope,
			COORDINATOR_SYMBOL
		);
		return installed?.value === coordinator &&
			isExactCoordinator( coordinator )
			? coordinator
			: null;
	} catch {
		return null;
	}
}
