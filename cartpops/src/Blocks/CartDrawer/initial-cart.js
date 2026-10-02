const ARRAY_DEFAULTS = [ 'coupons', 'cartFees', 'recommendations' ];

const CART_ITEM_KEY_MAX_LENGTH = 128;
const CART_LINE_MAX_ITEMS = 100;
const CART_LINE_NOTICE_MAX_BYTES = 500;
const CART_LINE_NOTICE_HAZARD =
	/[\u0000-\u001f\u007f-\u009f\u061c\u200e\u200f\u202a-\u202e\u2066-\u2069\ufeff]/u;
const CART_LINE_HTML_ENTITY =
	/&(?:#[xX][0-9A-Fa-f]+|#[0-9]+|[A-Za-z][A-Za-z0-9]+);/u;
const INITIAL_SYNCHRONIZATION_KEY = Symbol.for(
	'cartpops.cartDrawerInitialSynchronization'
);

const STRING_DEFAULTS = [
	'cartTotal',
	'cartSubtotal',
	'cartShipping',
	'cartTax',
	'couponCode',
	'error',
	'liveMessage',
];

const BOOLEAN_DEFAULTS = [ 'isOpen', 'isLoading', 'couponApplying' ];

const OBJECT_DEFAULTS = [ 'recsConfig', 'config', 'i18n' ];

/**
 * Count exact UTF-8 bytes while rejecting isolated surrogate code units.
 *
 * @param {string} value Candidate text.
 * @return {number|null} Encoded byte length or invalid-text marker.
 */
function utf8ByteLength( value ) {
	let bytes = 0;
	for ( let index = 0; index < value.length; index++ ) {
		const code = value.charCodeAt( index );
		if ( code <= 0x7f ) {
			bytes++;
		} else if ( code <= 0x7ff ) {
			bytes += 2;
		} else if ( code >= 0xd800 && code <= 0xdbff ) {
			const next = value.charCodeAt( index + 1 );
			if ( next < 0xdc00 || next > 0xdfff ) {
				return null;
			}
			bytes += 4;
			index++;
		} else if ( code >= 0xdc00 && code <= 0xdfff ) {
			return null;
		} else {
			bytes += 3;
		}
	}
	return bytes;
}

/**
 * Build a non-actionable authority projection without discarding the line.
 *
 * @param {*} item Cart item whose current quantity should be retained.
 * @return {Object} Complete fail-closed line authority.
 */
function lockedCartLineAuthority( item ) {
	const quantity =
		Number.isSafeInteger( item?.quantity ) && item.quantity > 0
			? item.quantity
			: 1;
	return {
		visible: false,
		quantityEditable: false,
		removable: false,
		quantityLimits: {
			minimum: quantity,
			maximum: quantity,
			multipleOf: 1,
			unlimited: false,
		},
		backorderNotice: '',
		optionalLocked: false,
	};
}

/**
 * Whether a value is the exact cart-key grammar shared with PHP and REST.
 *
 * @param {*} value Candidate cart key.
 * @return {boolean} Whether the key is safe for exact identity matching.
 */
export function isExactCartLineKey( value ) {
	return (
		typeof value === 'string' &&
		value.length > 0 &&
		value.length <= CART_ITEM_KEY_MAX_LENGTH &&
		/^[A-Za-z0-9._:-]+$/.test( value )
	);
}

/**
 * Normalize the server-owned policy fields without coercion.
 *
 * @param {*}       candidate                       Candidate authority carrier.
 * @param {Object}  [options]
 * @param {boolean} [options.requireOptionalLocked] Require CartPops REST/SSR edition state.
 * @return {Object|null} Exact normalized authority or a rejection marker.
 */
export function normalizeCartLineAuthority(
	candidate,
	{ requireOptionalLocked = false } = {}
) {
	if (
		! candidate ||
		typeof candidate !== 'object' ||
		Array.isArray( candidate ) ||
		typeof candidate.visible !== 'boolean' ||
		typeof candidate.quantityEditable !== 'boolean' ||
		typeof candidate.removable !== 'boolean' ||
		typeof candidate.backorderNotice !== 'string' ||
		( requireOptionalLocked &&
			typeof candidate.optionalLocked !== 'boolean' )
	) {
		return null;
	}

	const limits = candidate.quantityLimits;
	if (
		! limits ||
		typeof limits !== 'object' ||
		Array.isArray( limits ) ||
		Object.keys( limits ).length !== 4 ||
		! [ 'minimum', 'maximum', 'multipleOf' ].every( ( key ) =>
			Number.isSafeInteger( limits[ key ] )
		) ||
		typeof limits.unlimited !== 'boolean' ||
		limits.minimum < 1 ||
		limits.maximum < limits.minimum ||
		limits.multipleOf < 1 ||
		limits.minimum % limits.multipleOf !== 0 ||
		limits.maximum % limits.multipleOf !== 0
	) {
		return null;
	}

	const notice = candidate.backorderNotice;
	const noticeBytes = utf8ByteLength( notice );
	if (
		noticeBytes === null ||
		noticeBytes > CART_LINE_NOTICE_MAX_BYTES ||
		notice.replace( /^ +| +$/g, '' ) !== notice ||
		CART_LINE_NOTICE_HAZARD.test( notice ) ||
		/[<>]/u.test( notice ) ||
		CART_LINE_HTML_ENTITY.test( notice )
	) {
		return null;
	}

	const optionalLocked = requireOptionalLocked
		? candidate.optionalLocked
		: false;
	if (
		( ! candidate.visible &&
			( candidate.quantityEditable ||
				candidate.removable ||
				notice !== '' ) ) ||
		( optionalLocked &&
			( candidate.quantityEditable || candidate.removable ) )
	) {
		return null;
	}

	return {
		visible: candidate.visible,
		quantityEditable: candidate.quantityEditable,
		removable: candidate.removable,
		quantityLimits: {
			minimum: limits.minimum,
			maximum: limits.maximum,
			multipleOf: limits.multipleOf,
			unlimited: limits.unlimited,
		},
		backorderNotice: notice,
		optionalLocked,
	};
}

/**
 * Compare two canonical authority projections field-for-field.
 *
 * @param {Object} first  First normalized projection.
 * @param {Object} second Second normalized projection.
 * @return {boolean} Whether both carriers state the same policy.
 */
function cartLineAuthoritiesAgree( first, second ) {
	return (
		first.visible === second.visible &&
		first.quantityEditable === second.quantityEditable &&
		first.removable === second.removable &&
		first.backorderNotice === second.backorderNotice &&
		first.quantityLimits.minimum === second.quantityLimits.minimum &&
		first.quantityLimits.maximum === second.quantityLimits.maximum &&
		first.quantityLimits.multipleOf === second.quantityLimits.multipleOf &&
		first.quantityLimits.unlimited === second.quantityLimits.unlimited
	);
}

/**
 * Derive customer control state from a newly normalized exact row.
 *
 * @param {*} item Cart item with server-owned authority.
 * @return {Object} Item with bounded render state.
 */
export function projectCartLineControls( item ) {
	const authority = normalizeCartLineAuthority( item, {
		requireOptionalLocked: true,
	} );
	const safeAuthority = authority || lockedCartLineAuthority( item );
	const quantity = item?.quantity;
	const limits = safeAuthority.quantityLimits;
	const quantityAligned =
		Number.isSafeInteger( quantity ) &&
		quantity >= limits.minimum &&
		quantity <= limits.maximum &&
		quantity % limits.multipleOf === 0;
	const nextQuantity = quantityAligned
		? quantity + limits.multipleOf
		: Number.NaN;
	const previousQuantity = quantityAligned
		? quantity - limits.multipleOf
		: Number.NaN;
	const canRemove = safeAuthority.visible && safeAuthority.removable;
	const canEdit =
		safeAuthority.visible &&
		safeAuthority.quantityEditable &&
		! safeAuthority.optionalLocked &&
		quantityAligned;

	return {
		...item,
		...safeAuthority,
		showQuantityControls: canEdit,
		showRemoveControl: canRemove && ! safeAuthority.optionalLocked,
		canIncrementQuantity:
			canEdit &&
			Number.isSafeInteger( nextQuantity ) &&
			nextQuantity <= limits.maximum,
		canDecrementQuantity:
			canEdit && ( previousQuantity >= limits.minimum || canRemove ),
		hasBackorderNotice:
			safeAuthority.visible && safeAuthority.backorderNotice !== '',
	};
}

/**
 * Apply CartPops SSR/REST authority to every underlying financial row.
 *
 * @param {*} items Candidate CartPops cart items.
 * @return {Array} Rows with either exact authority or complete locks.
 */
export function projectCartPopsLineAuthorities( items ) {
	if ( ! Array.isArray( items ) ) {
		return [];
	}
	return items.map( ( item ) => projectCartLineControls( item ) );
}

/**
 * Read the two Store API line-authority carriers and reconcile exact rows.
 *
 * @param {*}     cart        WooCommerce Store API cart.
 * @param {Array} mappedItems Customer presentation mapped in Store order.
 * @return {Array} Mapped items with authoritative or fail-closed controls.
 */
export function projectStoreCartLineAuthorities( cart, mappedItems ) {
	const sourceItems = cart?.items;
	const presentations = cart?.extensions?.cartpops?.item_presentations;
	const lockedKeysSource = cart?.extensions?.cartpops?.optional_locked_keys;
	let carriersValid =
		Array.isArray( sourceItems ) &&
		Array.isArray( mappedItems ) &&
		sourceItems.length === mappedItems.length &&
		Array.isArray( presentations ) &&
		presentations.length === sourceItems.length &&
		presentations.length <= CART_LINE_MAX_ITEMS &&
		Array.isArray( lockedKeysSource ) &&
		lockedKeysSource.length <= CART_LINE_MAX_ITEMS;
	const presentationAuthorities = new Map();
	const sourceKeys = new Set();
	const lockedKeys = new Set();

	if ( carriersValid ) {
		for ( const sourceItem of sourceItems ) {
			if (
				! isExactCartLineKey( sourceItem?.key ) ||
				sourceKeys.has( sourceItem.key )
			) {
				carriersValid = false;
				break;
			}
			sourceKeys.add( sourceItem.key );
		}
	}

	if ( carriersValid ) {
		for ( const presentation of presentations ) {
			const authority = normalizeCartLineAuthority( presentation );
			if (
				! isExactCartLineKey( presentation?.key ) ||
				! sourceKeys.has( presentation.key ) ||
				! authority ||
				presentationAuthorities.has( presentation.key )
			) {
				carriersValid = false;
				break;
			}
			presentationAuthorities.set( presentation.key, authority );
		}
	}

	if ( carriersValid ) {
		for ( const key of lockedKeysSource ) {
			if (
				! isExactCartLineKey( key ) ||
				lockedKeys.has( key ) ||
				! sourceKeys.has( key )
			) {
				carriersValid = false;
				break;
			}
			lockedKeys.add( key );
		}
	}

	return mappedItems.map( ( item, index ) => {
		const sourceItem = sourceItems?.[ index ];
		const perItem = sourceItem?.extensions?.cartpops;
		const perItemAuthority = normalizeCartLineAuthority( perItem );
		const presentationAuthority = presentationAuthorities.get( item?.key );
		const optionalLocked = lockedKeys.has( item?.key );
		const exact =
			carriersValid &&
			isExactCartLineKey( item?.key ) &&
			item.key === sourceItem?.key &&
			perItem?.key === item.key &&
			Boolean( perItemAuthority ) &&
			Boolean( presentationAuthority ) &&
			cartLineAuthoritiesAgree(
				perItemAuthority,
				presentationAuthority
			) &&
			( ! optionalLocked ||
				( ! perItemAuthority.quantityEditable &&
					! perItemAuthority.removable ) );
		return projectCartLineControls( {
			...item,
			...( exact
				? { ...perItemAuthority, optionalLocked }
				: lockedCartLineAuthority( item ) ),
		} );
	} );
}

/**
 * Whether one item has the minimum shape needed by the drawer runtime.
 *
 * @param {*} item Candidate hydrated cart item.
 * @return {boolean} Whether property access and quantity math are safe.
 */
function isRuntimeSafeItem( item ) {
	return Boolean(
		item &&
			typeof item === 'object' &&
			! Array.isArray( item ) &&
			isExactCartLineKey( item.key ) &&
			Number.isSafeInteger( item.quantity ) &&
			item.quantity > 0
	);
}

/**
 * Validate the item records needed by the current drawer runtime and return
 * their safe quantity sum without constraining legitimate product fields.
 *
 * @param {Array} items Cart items from server state.
 * @return {{valid: boolean, count: number}} Validation result and safe total.
 */
function validateHydratedItems( items ) {
	let count = 0;
	const keys = new Set();
	for ( const item of items ) {
		if ( ! isRuntimeSafeItem( item ) || keys.has( item.key ) ) {
			return { valid: false, count: 0 };
		}
		keys.add( item.key );
		const nextCount = count + item.quantity;
		if ( ! Number.isSafeInteger( nextCount ) ) {
			return { valid: false, count: 0 };
		}
		count = nextCount;
	}

	return { valid: true, count };
}

/**
 * Validate one authoritative cart item/count pair before applying either.
 *
 * Omitting `expectedCount` is supported for Store API responses that expose
 * item quantities but no CartPops aggregate field.
 *
 * @param {*} items         Candidate cart items.
 * @param {*} expectedCount Optional declared aggregate.
 * @return {{items: Array, count: number}|null} Validated atomic cart state.
 */
export function validateAuthoritativeCartState( items, expectedCount ) {
	if ( ! Array.isArray( items ) ) {
		return null;
	}

	const validation = validateHydratedItems( items );
	if ( ! validation.valid ) {
		return null;
	}
	if (
		expectedCount !== undefined &&
		( ! Number.isSafeInteger( expectedCount ) ||
			expectedCount < 0 ||
			expectedCount !== validation.count )
	) {
		return null;
	}

	return { items, count: validation.count };
}

/**
 * Mark current cart state as pending authoritative verification.
 *
 * @param {Object} state CartPops Interactivity API state proxy.
 */
export function markCartHydrationPending( state ) {
	state._cartHydrationTrusted = false;
	state._cartHydrationPending = true;
	state.isLoading = true;
}

/**
 * Mark an atomically applied response as trusted cart state.
 *
 * @param {Object} state CartPops Interactivity API state proxy.
 */
export function markCartHydrationTrusted( state ) {
	state._cartHydrationTrusted = true;
	state._cartHydrationPending = false;
	state.isLoading = false;
}

/**
 * Whether cart-dependent mutations may use current client state.
 *
 * @param {Object} state CartPops Interactivity API state proxy.
 * @return {boolean} Whether the state has authoritative cart identity/content.
 */
export function isCartHydrationTrusted( state ) {
	return state._cartHydrationTrusted !== false;
}

/**
 * Fill client-only or malformed state without overwriting valid PHP hydration.
 *
 * WordPress parses `wp_interactivity_state()` before this module and then
 * merges the JavaScript store definition over it. Cart state defaults therefore
 * belong here, after store registration, where they can be applied only when
 * the corresponding server value is absent or unusable.
 *
 * @param {Object} state CartPops Interactivity API state proxy.
 * @return {boolean} Whether the initial server cart count and items were valid.
 */
export function initializeCartDrawerState( state ) {
	const originalCartCount = state.cartCount;
	const originalCartItems = state.cartItems;
	const hadHydratedItems = Array.isArray( originalCartItems );
	const hydratedItems = hadHydratedItems
		? validateHydratedItems( originalCartItems )
		: { valid: false, count: 0 };
	const hadHydratedCart =
		Number.isSafeInteger( originalCartCount ) &&
		originalCartCount >= 0 &&
		hadHydratedItems &&
		hydratedItems.valid &&
		originalCartCount === hydratedItems.count;

	state._initialCartHydrationShadow = {
		cartCount: originalCartCount,
		cartItems: originalCartItems,
	};

	for ( const key of ARRAY_DEFAULTS ) {
		if ( ! Array.isArray( state[ key ] ) ) {
			state[ key ] = [];
		}
	}

	for ( const key of STRING_DEFAULTS ) {
		if ( typeof state[ key ] !== 'string' ) {
			state[ key ] = '';
		}
	}

	for ( const key of BOOLEAN_DEFAULTS ) {
		if ( typeof state[ key ] !== 'boolean' ) {
			state[ key ] = false;
		}
	}

	for ( const key of OBJECT_DEFAULTS ) {
		if (
			! state[ key ] ||
			typeof state[ key ] !== 'object' ||
			Array.isArray( state[ key ] )
		) {
			state[ key ] = {};
		}
	}

	if ( ! Object.prototype.hasOwnProperty.call( state, 'undoItem' ) ) {
		state.undoItem = null;
	}
	if ( ! Object.prototype.hasOwnProperty.call( state, 'previousFocus' ) ) {
		state.previousFocus = null;
	}

	if ( hadHydratedCart ) {
		markCartHydrationTrusted( state );
	} else {
		markCartHydrationPending( state );
	}

	return hadHydratedCart;
}

/**
 * Return the cross-module startup registry for live Interactivity state.
 *
 * `Symbol.for()` keeps this boundary stable if a compiled module is evaluated
 * again in the same page. A WeakMap prevents state proxies from being retained.
 *
 * @return {WeakMap<object, string>} One decision per state proxy.
 */
function initialSynchronizationRegistry() {
	const current = globalThis[ INITIAL_SYNCHRONIZATION_KEY ];
	if ( current instanceof WeakMap ) {
		return current;
	}

	const registry = new WeakMap();
	Object.defineProperty( globalThis, INITIAL_SYNCHRONIZATION_KEY, {
		configurable: true,
		value: registry,
	} );
	return registry;
}

/**
 * Interpret WooCommerce's public cache cookies without mistaking the
 * `woocommerce_items_in_cart=1` presence hint for an item count. A populated
 * cart is identified only by its exact bounded cart hash.
 *
 * @param {string} cookieHeader `document.cookie` value.
 * @return {{status: 'populated'|'empty'|'unknown', cartHash: string|null}} Parsed cookie identity.
 */
export function readWooCartCookie( cookieHeader ) {
	const parts = String( cookieHeader || '' )
		.split( ';' )
		.map( ( part ) => part.trim() )
		.filter( Boolean );
	const valuesFor = ( name ) =>
		parts
			.filter( ( part ) => {
				const separator = part.indexOf( '=' );
				return separator > 0 && part.slice( 0, separator ) === name;
			} )
			.map( ( part ) => part.slice( part.indexOf( '=' ) + 1 ) );
	const itemHints = valuesFor( 'woocommerce_items_in_cart' );
	const cartHashes = valuesFor( 'woocommerce_cart_hash' );

	if ( itemHints.length !== 1 || cartHashes.length > 1 ) {
		return { status: 'unknown', cartHash: null };
	}

	if (
		itemHints[ 0 ] !== '1' ||
		cartHashes.length !== 1 ||
		! /^[a-f\d]{32}$/i.test( cartHashes[ 0 ] )
	) {
		return { status: 'unknown', cartHash: null };
	}

	return { status: 'populated', cartHash: cartHashes[ 0 ].toLowerCase() };
}

/**
 * Reconcile server-hydrated state with WooCommerce's cache-busting cookie.
 * Ambiguous or contradictory cookie state triggers an authoritative refresh,
 * but never destroys hydrated data before that request succeeds.
 *
 * @param {Object}   options
 * @param {string}   options.cookieHeader
 * @param {Object}   options.state
 * @param {boolean}  [options.hasHydratedCart=true]
 * @param {boolean}  [options.forceRefresh=false]
 * @param {Function} options.requestCart
 * @param {Function} options.markFresh
 * @return {'refreshing'|'current'} Synchronization state.
 */
export function synchronizeInitialCart( {
	cookieHeader,
	state,
	hasHydratedCart = true,
	forceRefresh = false,
	requestCart,
	markFresh,
} ) {
	const initialSynchronization = initialSynchronizationRegistry();
	if ( initialSynchronization.has( state ) ) {
		return initialSynchronization.get( state );
	}

	const cookie = readWooCartCookie( cookieHeader );
	const hydrationTrusted = hasHydratedCart && isCartHydrationTrusted( state );
	const cookieMatches =
		hydrationTrusted &&
		( ( cookie.status === 'empty' && state.cartCount === 0 ) ||
			( cookie.status === 'populated' &&
				state.cartCount > 0 &&
				typeof state.cartHash === 'string' &&
				cookie.cartHash === state.cartHash.toLowerCase() ) );

	if ( cookieMatches && forceRefresh !== true ) {
		markFresh();
		initialSynchronization.set( state, 'current' );
		return 'current';
	}

	markCartHydrationPending( state );

	try {
		requestCart()?.catch?.( () => {} );
	} catch ( error ) {
		// A synchronous request failure leaves the hydrated UI usable.
	}
	initialSynchronization.set( state, 'refreshing' );
	return 'refreshing';
}
