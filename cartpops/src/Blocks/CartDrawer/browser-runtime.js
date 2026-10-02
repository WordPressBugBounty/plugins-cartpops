const CART_ITEM_PRESENTATION_MAX_ITEMS = 100;
const CART_ITEM_PRESENTATION_MAX_KEY_BYTES = 512;
const CART_ITEM_PRESENTATION_MAX_ENCODED_BYTES = 1048576;
const CUSTOM_PRESENTATION_KEY = /^[A-Za-z][A-Za-z0-9_.-]{0,63}$/;

/**
 * Return the UTF-8 byte length used by the PHP presentation contract.
 *
 * @param {string} value Candidate text.
 * @return {number} UTF-8 byte length or positive infinity for invalid text.
 */
export function utf8ByteLength( value ) {
	let bytes = 0;
	for ( const character of value ) {
		const codePoint = character.codePointAt( 0 );
		if (
			codePoint === undefined ||
			( codePoint >= 0xd800 && codePoint <= 0xdfff )
		) {
			return Number.POSITIVE_INFINITY;
		}
		if ( codePoint <= 0x7f ) {
			bytes += 1;
		} else if ( codePoint <= 0x7ff ) {
			bytes += 2;
		} else if ( codePoint <= 0xffff ) {
			bytes += 3;
		} else {
			bytes += 4;
		}
	}
	return bytes;
}

/**
 * Return a bounded string or null.
 *
 * @param {*}       value           Candidate text.
 * @param {number}  maxBytes        Maximum UTF-8 bytes.
 * @param {boolean} requireNonEmpty Whether an empty string is invalid.
 * @return {string|null} Validated text.
 */
function boundedText( value, maxBytes, requireNonEmpty = false ) {
	if (
		typeof value !== 'string' ||
		( requireNonEmpty && value.length === 0 ) ||
		utf8ByteLength( value ) > maxBytes
	) {
		return null;
	}
	return value;
}

/**
 * Whether a decoded JSON value is an ordinary object map.
 *
 * @param {*} value Candidate value.
 * @return {boolean} Whether the value is an ordinary object map.
 */
function isPlainObject( value ) {
	if (
		value === null ||
		typeof value !== 'object' ||
		Array.isArray( value )
	) {
		return false;
	}
	const prototype = Object.getPrototypeOf( value );
	return prototype === Object.prototype || prototype === null;
}

/**
 * Recursively copy one bounded JSON presentation value.
 *
 * @param {*}                 value   Candidate value.
 * @param {number}            depth   Current nesting depth.
 * @param {{ nodes: number }} counter Shared node counter.
 * @return {{ valid: boolean, value?: * }} Normalization result.
 */
function normalizeCustomPresentationValue( value, depth, counter ) {
	counter.nodes++;
	if ( depth > 4 || counter.nodes > 64 ) {
		return { valid: false };
	}
	if ( value === null || typeof value === 'boolean' ) {
		return { valid: true, value };
	}
	if ( typeof value === 'number' ) {
		return Number.isFinite( value ) &&
			Math.abs( value ) <= Number.MAX_SAFE_INTEGER &&
			( ! Number.isInteger( value ) || Number.isSafeInteger( value ) )
			? { valid: true, value }
			: { valid: false };
	}
	if ( typeof value === 'string' ) {
		return utf8ByteLength( value ) <= 1024
			? { valid: true, value }
			: { valid: false };
	}
	if ( Array.isArray( value ) ) {
		if ( value.length > 16 ) {
			return { valid: false };
		}
		const list = [];
		for ( const entry of value ) {
			const normalized = normalizeCustomPresentationValue(
				entry,
				depth + 1,
				counter
			);
			if ( ! normalized.valid ) {
				return { valid: false };
			}
			list.push( normalized.value );
		}
		return { valid: true, value: list };
	}
	if ( ! isPlainObject( value ) ) {
		return { valid: false };
	}

	const keys = Object.keys( value );
	if (
		keys.length > 16 ||
		keys.some( ( key ) => ! CUSTOM_PRESENTATION_KEY.test( key ) )
	) {
		return { valid: false };
	}
	const object = {};
	for ( const key of keys ) {
		const normalized = normalizeCustomPresentationValue(
			value[ key ],
			depth + 1,
			counter
		);
		if ( ! normalized.valid ) {
			return { valid: false };
		}
		object[ key ] = normalized.value;
	}
	return { valid: true, value: object };
}

/**
 * Normalize the PHP-owned custom presentation map.
 *
 * @param {*} value Candidate custom presentation.
 * @return {Object|null} Safe object map.
 */
function normalizeCustomPresentation( value ) {
	if ( ! isPlainObject( value ) ) {
		return null;
	}
	const keys = Object.keys( value );
	if (
		keys.length > 16 ||
		keys.some( ( key ) => ! CUSTOM_PRESENTATION_KEY.test( key ) )
	) {
		return null;
	}

	const counter = { nodes: 0 };
	const result = {};
	for ( const key of keys ) {
		const normalized = normalizeCustomPresentationValue(
			value[ key ],
			1,
			counter
		);
		if ( ! normalized.valid ) {
			return null;
		}
		result[ key ] = normalized.value;
	}
	try {
		return utf8ByteLength( JSON.stringify( result ) ) <= 8192
			? result
			: null;
	} catch ( error ) {
		return null;
	}
}

/**
 * Normalize the built-in plain-text row list.
 *
 * @param {*} value Candidate row list.
 * @return {Array|null} Safe text rows.
 */
function normalizeExtraLines( value ) {
	if ( ! Array.isArray( value ) || value.length > 20 ) {
		return null;
	}
	const result = [];
	for ( const line of value ) {
		if ( ! isPlainObject( line ) ) {
			return null;
		}
		const keys = Object.keys( line );
		if (
			keys.length !== 2 ||
			! keys.includes( 'label' ) ||
			! keys.includes( 'value' )
		) {
			return null;
		}
		const label = boundedText( line.label, 160, true );
		const text = boundedText( line.value, 1000 );
		if ( label === null || text === null ) {
			return null;
		}
		result.push( { label, value: text } );
	}
	return result;
}

/**
 * Read the exact bounded PHP presentation list from the CartPops Store API
 * namespace. Any malformed row rejects the optional collection, not the cart.
 *
 * @param {Object} cart Store API cart.
 * @return {Map<string, Object>} Safe presentation keyed by cart item key.
 */
export function readStoreCartItemPresentations( cart ) {
	const source = cart?.extensions?.cartpops?.item_presentations;
	if (
		! Array.isArray( source ) ||
		source.length > CART_ITEM_PRESENTATION_MAX_ITEMS
	) {
		return new Map();
	}

	const presentations = new Map();
	for ( const candidate of source ) {
		if ( ! isPlainObject( candidate ) ) {
			return new Map();
		}
		const key = boundedText(
			candidate.key,
			CART_ITEM_PRESENTATION_MAX_KEY_BYTES,
			true
		);
		const extraLines = normalizeExtraLines( candidate.extraLines );
		const customPresentation = normalizeCustomPresentation(
			candidate.customPresentation
		);
		if (
			key === null ||
			presentations.has( key ) ||
			extraLines === null ||
			customPresentation === null
		) {
			return new Map();
		}

		const presentation = { extraLines, customPresentation };
		for ( const [ field, maxBytes, requireNonEmpty ] of [
			[ 'name', 500, true ],
			[ 'short_description', 2000, false ],
			[ 'variationSummary', 1000, false ],
		] ) {
			if ( ! Object.prototype.hasOwnProperty.call( candidate, field ) ) {
				continue;
			}
			const text = boundedText(
				candidate[ field ],
				maxBytes,
				requireNonEmpty
			);
			if ( text === null ) {
				return new Map();
			}
			presentation[ field ] = text;
		}
		presentations.set( key, presentation );
	}

	try {
		return utf8ByteLength( JSON.stringify( source ) ) <=
			CART_ITEM_PRESENTATION_MAX_ENCODED_BYTES
			? presentations
			: new Map();
	} catch ( error ) {
		return new Map();
	}
}

/**
 * Read the exact authoritative Store API locked-key carrier.
 *
 * Any invalid, duplicate, or overbound key rejects the complete optional
 * carrier so a partial list cannot change paid row authority.
 *
 * @param {Object} cart Store API cart.
 * @return {Set<string>} Exact validated locked cart-item keys.
 */
export function readStoreOptionalLockedKeys( cart ) {
	const source = cart?.extensions?.cartpops?.optional_locked_keys;
	if (
		! Array.isArray( source ) ||
		source.length > CART_ITEM_PRESENTATION_MAX_ITEMS
	) {
		return new Set();
	}

	const keys = new Set();
	for ( const candidate of source ) {
		const key = boundedText(
			candidate,
			CART_ITEM_PRESENTATION_MAX_KEY_BYTES,
			true
		);
		if ( key === null || keys.has( key ) ) {
			return new Set();
		}
		keys.add( key );
	}
	return keys;
}

/**
 * Apply only the server-owned presentation for the exact Store API row.
 *
 * @param {*}                   item          Mapped cart item.
 * @param {Object}              sourceItem    Store API cart item.
 * @param {Map<string, Object>} presentations Validated PHP presentations.
 * @return {*} Item with its exact optional presentation.
 */
export function applyStoreCartItemPresentation(
	item,
	sourceItem,
	presentations
) {
	const presentation = presentations?.get?.( sourceItem?.key );
	return presentation && item !== null && typeof item === 'object'
		? { ...item, ...presentation }
		: item;
}

/**
 * Read the current WC Blocks cart through `wp.data` when that optional store
 * is available.
 *
 * @param {Object} globalScope Browser global scope.
 * @return {Object|null} Store API-shaped cart data or null.
 */
export function readCartFromWpData( globalScope = globalThis ) {
	try {
		const cartStore = globalScope?.wp?.data?.select?.( 'wc/store/cart' );
		if ( typeof cartStore?.getCartData !== 'function' ) {
			return null;
		}
		const cartData = cartStore.getCartData();
		if (
			typeof cartStore.hasFinishedResolution === 'function' &&
			! cartStore.hasFinishedResolution( 'getCartData' )
		) {
			return null;
		}
		return Array.isArray( cartData?.items ) ? cartData : null;
	} catch ( error ) {
		return null;
	}
}

/**
 * Subscribe to resolved WC Blocks cart identity changes without owning any
 * CartPops event bridge. Missing or late `wp.data` remains an optional feature.
 *
 * @param {Object}   options
 * @param {Object}   options.globalScope
 * @param {Document} options.documentScope
 * @param {Function} options.onCartChange
 * @return {Function} Idempotent cleanup callback.
 */
export function subscribeToResolvedWooCartChanges( {
	globalScope = globalThis,
	documentScope = globalScope?.document,
	onCartChange,
} = {} ) {
	let active = true;
	let installed = false;
	let lastResolvedCartData = null;
	let unsubscribe = null;
	const retryCleanups = [];

	const observeCart = () => {
		if ( ! active ) {
			return;
		}
		const cart = readCartFromWpData( globalScope );
		if ( ! cart || cart === lastResolvedCartData ) {
			return;
		}
		lastResolvedCartData = cart;
		try {
			onCartChange( cart );
		} catch ( error ) {
			// Optional consumers cannot be allowed to break the wp.data listener.
		}
	};

	const install = () => {
		const subscribe = globalScope?.wp?.data?.subscribe;
		if (
			! active ||
			installed ||
			typeof subscribe !== 'function' ||
			typeof onCartChange !== 'function'
		) {
			return false;
		}

		try {
			const candidateUnsubscribe = subscribe(
				observeCart,
				'wc/store/cart'
			);
			installed = true;
			unsubscribe =
				typeof candidateUnsubscribe === 'function'
					? candidateUnsubscribe
					: null;
			return true;
		} catch ( error ) {
			// The optional Blocks store may be absent or only partially loaded.
			return false;
		}
	};

	if ( ! install() && typeof onCartChange === 'function' ) {
		const retry = () => {
			if ( active ) {
				install();
			}
		};
		if (
			documentScope?.readyState === 'loading' &&
			typeof documentScope.addEventListener === 'function'
		) {
			documentScope.addEventListener( 'DOMContentLoaded', retry, {
				once: true,
			} );
			retryCleanups.push( () =>
				documentScope.removeEventListener?.( 'DOMContentLoaded', retry )
			);
		} else if (
			documentScope?.readyState !== 'complete' &&
			typeof globalScope?.addEventListener === 'function'
		) {
			globalScope.addEventListener( 'load', retry, { once: true } );
			retryCleanups.push( () =>
				globalScope.removeEventListener?.( 'load', retry )
			);
		} else {
			queueMicrotask( retry );
		}
	}

	let cleaned = false;
	return () => {
		if ( cleaned ) {
			return;
		}
		cleaned = true;
		active = false;
		for ( const cleanup of retryCleanups ) {
			try {
				cleanup();
			} catch ( error ) {
				// Optional browser cleanup must not prevent later teardown work.
			}
		}
		if ( typeof unsubscribe === 'function' ) {
			try {
				unsubscribe();
			} catch ( error ) {
				// A broken optional data store must not break module replacement.
			}
		}
	};
}

const WOO_CART_SURFACE_SYNC_KEY = Symbol.for( 'cartpops.wooCartSurfaceSync' );
const WOO_CART_SURFACE_SYNC_DELAY_MS = 50;

/**
 * Ask classic WooCommerce to refresh its fragments without manufacturing an
 * add/remove event that could open a third-party mini-cart or skew analytics.
 *
 * @param {Object}   globalScope   Browser global scope.
 * @param {Document} documentScope Browser document.
 */
function refreshClassicCartFragments( globalScope, documentScope ) {
	const jQueryFactory = globalScope?.jQuery;
	if ( typeof jQueryFactory !== 'function' ) {
		return;
	}

	try {
		const jqueryBody = jQueryFactory( documentScope?.body );
		jqueryBody?.trigger?.( 'wc_fragment_refresh' );
	} catch ( error ) {
		// Classic fragments are optional and must not block Blocks invalidation.
	}
}

/**
 * Invalidate WooCommerce's public Blocks cart data store when it is present.
 * The store's mounted consumers own the resulting Store API resolution.
 *
 * @param {Object} globalScope Browser global scope.
 * @return {boolean} Whether an available invalidation action was invoked.
 */
function invalidateBlocksCartStore( globalScope ) {
	const data = globalScope?.wp?.data;
	if ( typeof data?.dispatch !== 'function' ) {
		return false;
	}

	try {
		const cartActions = data.dispatch( 'wc/store/cart' );
		if (
			typeof cartActions?.invalidateResolutionForStoreSelector !==
				'function' &&
			typeof cartActions?.invalidateResolutionForStore !== 'function'
		) {
			return false;
		}
		const result =
			typeof cartActions?.invalidateResolutionForStoreSelector ===
			'function'
				? cartActions.invalidateResolutionForStoreSelector(
						'getCartData'
				  )
				: cartActions?.invalidateResolutionForStore?.();
		result?.catch?.( () => {} );
		return true;
	} catch ( error ) {
		// The optional store may be absent, registering, or already disposed.
		return false;
	}
}

/**
 * Coalesce confirmed CartPops-owned mutations onto one outward WooCommerce
 * surface refresh. This boundary emits only refresh/invalidation signals;
 * inbound fragment and Blocks cart events never call it.
 *
 * @param {Object}   [options]
 * @param {Object}   [options.globalScope]
 * @param {Document} [options.documentScope]
 * @return {boolean} Whether the refresh is scheduled or already pending.
 */
export function scheduleWooCartSurfaceSync( {
	globalScope = globalThis,
	documentScope = globalScope?.document,
} = {} ) {
	if (
		! globalScope ||
		( typeof globalScope !== 'object' && typeof globalScope !== 'function' )
	) {
		return false;
	}

	try {
		if ( globalScope[ WOO_CART_SURFACE_SYNC_KEY ] ) {
			return true;
		}
	} catch ( error ) {
		return false;
	}

	const setTimer =
		typeof globalScope.setTimeout === 'function'
			? globalScope.setTimeout.bind( globalScope )
			: globalThis.setTimeout;
	if ( typeof setTimer !== 'function' ) {
		return false;
	}

	const owner = {};
	const flush = () => {
		try {
			if ( globalScope[ WOO_CART_SURFACE_SYNC_KEY ] !== owner ) {
				return;
			}
			delete globalScope[ WOO_CART_SURFACE_SYNC_KEY ];
		} catch ( error ) {
			return;
		}

		refreshClassicCartFragments( globalScope, documentScope );
		if ( ! invalidateBlocksCartStore( globalScope ) ) {
			try {
				// Woo's exposed DOM compatibility signal refreshes IAPI-only carts.
				// Verified in pinned Woo 11; not a documented stable public API.
				globalScope.dispatchEvent?.(
					new globalScope.CustomEvent(
						'wc-blocks_store_sync_required',
						{
							detail: { type: 'from_@wordpress/data' },
						}
					)
				);
			} catch ( error ) {
				// Missing optional browser/Woo APIs must not affect the mutation.
			}
		}
	};

	try {
		globalScope[ WOO_CART_SURFACE_SYNC_KEY ] = owner;
		setTimer( flush, WOO_CART_SURFACE_SYNC_DELAY_MS );
		return true;
	} catch ( error ) {
		try {
			if ( globalScope[ WOO_CART_SURFACE_SYNC_KEY ] === owner ) {
				delete globalScope[ WOO_CART_SURFACE_SYNC_KEY ];
			}
		} catch ( cleanupError ) {
			// A non-writable global already made this optional integration inert.
		}
		return false;
	}
}

const EVENT_BRIDGE_KEY = Symbol.for( 'cartpops.cartDrawerEventBridge' );

/**
 * Install the document-level WooCommerce and CartPops event bridge once.
 * Re-evaluating a module replaces the previous bridge instead of stacking
 * handlers, and classic jQuery events remain an optional integration.
 *
 * @param {Object}   options
 * @param {Object}   options.globalScope
 * @param {Document} options.documentScope
 * @param {Function} [options.onBlocksAdded]
 * @param {Function} [options.onBlocksRemoved]
 * @param {Function} [options.onBlocksCartChanged]
 * @param {Function} [options.onClassicAdded]
 * @param {Function} [options.onClassicRemoved]
 * @param {Function} [options.onClassicRefreshed]
 * @param {Function} [options.onMiniCartReplace]
 * @param {Function} [options.onDocumentClick]
 * @param {Function} [options.onOpen]
 * @param {Function} [options.onClose]
 * @param {Function} [options.onToggle]
 * @return {Function} Idempotent cleanup callback.
 */
export function installCartDrawerEventBridge( {
	globalScope = globalThis,
	documentScope = globalScope?.document,
	onBlocksAdded,
	onBlocksRemoved,
	onBlocksCartChanged,
	onClassicAdded,
	onClassicRemoved,
	onClassicRefreshed,
	onMiniCartReplace,
	onDocumentClick,
	onOpen,
	onClose,
	onToggle,
} = {} ) {
	globalScope?.[ EVENT_BRIDGE_KEY ]?.cleanup?.();

	const cleanupTasks = [];
	let bridgeActive = true;
	const mutationQueues = [];
	const createMutationQueue = ( onBlocks, onClassic ) => {
		const pendingClassic = [];
		const pendingBlocks = [];
		let flushQueued = false;
		const flush = () => {
			flushQueued = false;
			if ( ! bridgeActive ) {
				pendingClassic.length = 0;
				pendingBlocks.length = 0;
				return;
			}

			const translatedBlocks = pendingBlocks.filter(
				( mutation ) => mutation.translatedCandidate
			);
			const paired = Math.min(
				pendingClassic.length,
				translatedBlocks.length
			);
			for ( const args of pendingClassic ) {
				onClassic?.( ...args );
			}
			let pairedBlocksRemaining = paired;
			for ( const mutation of pendingBlocks ) {
				if (
					mutation.translatedCandidate &&
					pairedBlocksRemaining > 0
				) {
					pairedBlocksRemaining--;
					continue;
				}
				onBlocks?.( ...mutation.args );
			}
			pendingClassic.length = 0;
			pendingBlocks.length = 0;
		};
		const scheduleFlush = () => {
			if ( flushQueued ) {
				return;
			}
			flushQueued = true;
			queueMicrotask( flush );
		};
		const queueBlocks = ( ...args ) => {
			const detail = args[ 0 ]?.detail;
			pendingBlocks.push( {
				args,
				// Woo 9–11's classic bridge emits an explicit empty detail.
				// Native Blocks mutations carry meaningful detail and stay distinct.
				translatedCandidate:
					detail !== null &&
					typeof detail === 'object' &&
					! Array.isArray( detail ) &&
					Object.keys( detail ).length === 0,
			} );
			scheduleFlush();
		};
		const queueClassic = ( ...args ) => {
			pendingClassic.push( args );
			scheduleFlush();
		};
		const clear = () => {
			pendingClassic.length = 0;
			pendingBlocks.length = 0;
		};
		mutationQueues.push( clear );
		return { queueBlocks, queueClassic };
	};
	const addQueue = createMutationQueue( onBlocksAdded, onClassicAdded );
	const removeQueue = createMutationQueue(
		onBlocksRemoved,
		onClassicRemoved
	);
	const addDomListener = ( target, eventName, handler, options ) => {
		if ( typeof handler !== 'function' || ! target?.addEventListener ) {
			return;
		}
		target.addEventListener( eventName, handler, options );
		cleanupTasks.push( () =>
			target.removeEventListener( eventName, handler, options )
		);
	};
	const replaceMiniCartClick = ( event ) => {
		if ( event?.defaultPrevented ) {
			return;
		}

		const target = event?.target;
		if ( typeof target?.closest !== 'function' ) {
			return;
		}
		if (
			target.closest(
				'[data-wp-interactive="cartpops"], .cpops-modal, .cpops-drawer, .cpops-modal-wrap'
			)
		) {
			return;
		}
		const trigger = target.closest( '.wc-block-mini-cart__button' );
		if ( ! trigger ) {
			return;
		}

		let handled = false;
		try {
			handled = true === onMiniCartReplace?.( trigger, event );
		} catch ( error ) {
			// A missing or broken drawer must leave Woo's native trigger usable.
			return;
		}
		if ( ! handled ) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();
		event.stopImmediatePropagation();
	};

	addDomListener(
		documentScope?.body,
		'wc-blocks_added_to_cart',
		typeof onBlocksAdded === 'function' ? addQueue.queueBlocks : undefined
	);
	addDomListener(
		documentScope?.body,
		'wc-blocks_removed_from_cart',
		typeof onBlocksRemoved === 'function'
			? removeQueue.queueBlocks
			: undefined
	);
	addDomListener(
		documentScope,
		'click',
		typeof onMiniCartReplace === 'function'
			? replaceMiniCartClick
			: undefined,
		true
	);
	addDomListener( documentScope, 'click', onDocumentClick );
	addDomListener( documentScope, 'cartpops:open', onOpen );
	addDomListener( documentScope, 'cartpops:close', onClose );
	addDomListener( documentScope, 'cartpops:toggle', onToggle );

	const jQueryFactory = globalScope?.jQuery;
	if ( typeof jQueryFactory === 'function' ) {
		try {
			const jqueryBody = jQueryFactory( documentScope?.body );
			if ( typeof jqueryBody?.on === 'function' ) {
				const addJqueryListener = ( events, handler ) => {
					if ( typeof handler !== 'function' ) {
						return;
					}
					jqueryBody.on( events, handler );
					cleanupTasks.push( () =>
						jqueryBody.off?.( events, handler )
					);
				};
				addJqueryListener(
					'added_to_cart',
					typeof onClassicAdded === 'function'
						? addQueue.queueClassic
						: undefined
				);
				addJqueryListener(
					'removed_from_cart',
					typeof onClassicRemoved === 'function'
						? removeQueue.queueClassic
						: undefined
				);
				addJqueryListener(
					'updated_wc_div wc_fragments_refreshed',
					onClassicRefreshed
				);
			}
		} catch ( error ) {
			// A broken optional integration must not prevent drawer hydration.
		}
	}

	if ( typeof onBlocksCartChanged === 'function' ) {
		cleanupTasks.push(
			subscribeToResolvedWooCartChanges( {
				globalScope,
				documentScope,
				onCartChange: onBlocksCartChanged,
			} )
		);
	}

	const token = {};
	let cleaned = false;
	const cleanup = () => {
		if ( cleaned ) {
			return;
		}
		cleaned = true;
		bridgeActive = false;
		for ( const clearMutationQueue of mutationQueues ) {
			clearMutationQueue();
		}
		for ( const cleanupTask of cleanupTasks ) {
			cleanupTask();
		}
		if ( globalScope?.[ EVENT_BRIDGE_KEY ]?.token === token ) {
			delete globalScope[ EVENT_BRIDGE_KEY ];
		}
	};

	if ( globalScope ) {
		globalScope[ EVENT_BRIDGE_KEY ] = { token, cleanup };
	}

	return cleanup;
}
