/**
 * CartPops Cart Drawer — REST / Store API helpers.
 *
 * @package
 */

import { store } from '@wordpress/interactivity';
import { resolveCartSessionAuthority } from './cart-session-authority';
import { i18n } from './i18n';

const URL_PARSE_ORIGIN = 'https://cartpops.invalid';
const CARTPOPS_JSON_BODY_MAX_BYTES = 1024;
const CARTPOPS_JSON_BODY_MAX_READS = 64;
const CARTPOPS_JSON_BODY_MAX_EMPTY_READS = 8;
const CARTPOPS_JSON_BODY_READ_TIMEOUT_MS = 250;
const CARTPOPS_ERROR_MESSAGE_MAX_CODEPOINTS = 500;
const CART_SESSION_BUSY_RETRY_DELAY_MS = 25;
const RETRYABLE_AUTHORITY_DENIAL_STATUSES = new Set( [ 401, 403 ] );
const RETRYABLE_AUTHORITY_DENIAL_CODES = new Set( [
	'rest_cookie_invalid_nonce',
	'cartpops_invalid_nonce',
	'cartpops_invalid_cart_token',
] );
const CART_SESSION_BUSY_STATUSES = new Set( [ 409 ] );
const CART_SESSION_BUSY_CODES = new Set( [ 'cart_session_busy' ] );
const NativeArrayBuffer = globalThis.ArrayBuffer;
const NativeUint8Array = globalThis.Uint8Array;
const nativeArrayBufferIsView =
	NativeArrayBuffer.isView.bind( NativeArrayBuffer );
const arrayBufferByteLengthGetter = Object.getOwnPropertyDescriptor(
	NativeArrayBuffer.prototype,
	'byteLength'
).get;
const typedArrayPrototype = Object.getPrototypeOf( NativeUint8Array.prototype );
const typedArrayBufferGetter = Object.getOwnPropertyDescriptor(
	typedArrayPrototype,
	'buffer'
).get;
const typedArrayByteOffsetGetter = Object.getOwnPropertyDescriptor(
	typedArrayPrototype,
	'byteOffset'
).get;
const typedArrayByteLengthGetter = Object.getOwnPropertyDescriptor(
	typedArrayPrototype,
	'byteLength'
).get;
const dataViewBufferGetter = Object.getOwnPropertyDescriptor(
	globalThis.DataView.prototype,
	'buffer'
).get;
const dataViewByteOffsetGetter = Object.getOwnPropertyDescriptor(
	globalThis.DataView.prototype,
	'byteOffset'
).get;
const dataViewByteLengthGetter = Object.getOwnPropertyDescriptor(
	globalThis.DataView.prototype,
	'byteLength'
).get;
const initialAuthorityCoordinator = resolveCartSessionAuthority();

/**
 * Return the still-exact realm coordinator or disable network authority.
 *
 * @return {Object} Shared mutable coordinator cell.
 */
function authorityCoordinator() {
	const current = resolveCartSessionAuthority();
	if (
		! initialAuthorityCoordinator ||
		current !== initialAuthorityCoordinator
	) {
		throw new Error( 'CartPops cart authority is unavailable.' );
	}
	return current;
}

/**
 * Decode one small byte buffer as strict UTF-8 without relying on the optional
 * TextDecoder global. Invalid, truncated, overlong, surrogate, and out-of-range
 * sequences fail closed.
 *
 * @param {Uint8Array} bytes UTF-8 bytes.
 * @return {string|null} Decoded text, or null for invalid UTF-8.
 */
function decodeStrictUtf8( bytes ) {
	let text = '';
	for ( let index = 0; index < bytes.length;  ) {
		const first = bytes[ index++ ];
		if ( first <= 0x7f ) {
			text += String.fromCodePoint( first );
			continue;
		}

		let needed;
		let point;
		let minimum;
		if ( first >= 0xc2 && first <= 0xdf ) {
			needed = 1;
			point = first - 0xc0;
			minimum = 0x80;
		} else if ( first >= 0xe0 && first <= 0xef ) {
			needed = 2;
			point = first - 0xe0;
			minimum = 0x800;
		} else if ( first >= 0xf0 && first <= 0xf4 ) {
			needed = 3;
			point = first - 0xf0;
			minimum = 0x10000;
		} else {
			return null;
		}

		if ( index + needed > bytes.length ) {
			return null;
		}
		for ( let offset = 0; offset < needed; offset++ ) {
			const next = bytes[ index++ ];
			if ( next < 0x80 || next > 0xbf ) {
				return null;
			}
			point = point * 64 + ( next - 0x80 );
		}

		if (
			point < minimum ||
			point > 0x10ffff ||
			( point >= 0xd800 && point <= 0xdfff )
		) {
			return null;
		}
		text += String.fromCodePoint( point );
	}
	return text;
}

/**
 * Parse an absolute or root-relative URL without changing its output form.
 *
 * @param {string} value URL to parse.
 * @return {{url: URL, output: 'absolute'|'protocol-relative'|'relative'}} Parsed URL and serialization mode.
 */
function parseUrl( value ) {
	const source = String( value || '/wp-json/' );
	let output = 'relative';
	if ( /^[a-z][a-z\d+.-]*:/i.test( source ) ) {
		output = 'absolute';
	} else if ( source.startsWith( '//' ) ) {
		output = 'protocol-relative';
	}
	return { url: new URL( source, URL_PARSE_ORIGIN ), output };
}

/**
 * Serialize a parsed URL in the same absolute/relative form supplied.
 *
 * @param {URL}    url
 * @param {string} output
 * @return {string} Serialized URL.
 */
function serializeUrl( url, output ) {
	if ( output === 'absolute' ) {
		return url.href;
	}
	if ( output === 'protocol-relative' ) {
		return `//${ url.host }${ url.pathname }${ url.search }${ url.hash }`;
	}
	return `${ url.pathname }${ url.search }${ url.hash }`;
}

/**
 * Join a REST base path and route with exactly one separator.
 *
 * @param {string} basePath Base pathname or decoded `rest_route` value.
 * @param {string} path     Route relative to the REST root.
 * @return {string} Joined route.
 */
function joinRoute( basePath, path ) {
	const base = String( basePath || '/' ).replace( /\/+$/, '' );
	const route = String( path || '' ).replace( /^\/+/, '' );
	return `${ base || '' }/${ route }`;
}

/**
 * Append encoded query values to a URL without disturbing existing params.
 *
 * @param {string} url         Base URL.
 * @param {Object} queryParams Query values to encode.
 * @return {string} URL with encoded query values.
 */
function appendQueryParams( url, queryParams ) {
	const parsed = parseUrl( url );

	for ( const [ key, value ] of Object.entries( queryParams ) ) {
		if ( value !== undefined && value !== null ) {
			parsed.url.searchParams.append( key, String( value ) );
		}
	}
	return serializeUrl( parsed.url, parsed.output );
}

/**
 * Build a CartPops REST URL for both pretty and plain WordPress permalinks.
 *
 * @param {string} restUrl     WordPress REST root from `get_rest_url()`.
 * @param {string} path        Route relative to the REST root.
 * @param {Object} queryParams Query values to encode.
 * @return {string} Complete REST URL.
 */
export function buildRestUrl( restUrl, path, queryParams = {} ) {
	const parsed = parseUrl( restUrl );
	if ( parsed.url.searchParams.has( 'rest_route' ) ) {
		parsed.url.searchParams.set(
			'rest_route',
			joinRoute( parsed.url.searchParams.get( 'rest_route' ), path )
		);
	} else {
		parsed.url.pathname = joinRoute( parsed.url.pathname, path );
	}

	return appendQueryParams(
		serializeUrl( parsed.url, parsed.output ),
		queryParams
	);
}

/**
 * Whether a response header is a bounded JWT-like Cart-Token value.
 * Signature verification remains a server responsibility.
 *
 * @param {*} value
 * @return {boolean} Whether the value can be sent as a Cart-Token.
 */
function isValidCartToken( value ) {
	return (
		typeof value === 'string' &&
		value.length > 0 &&
		value.length <= 2048 &&
		/^[A-Za-z0-9_=.-]+$/.test( value ) &&
		value.split( '.' ).length === 3
	);
}

/**
 * Whether a cached WordPress REST nonce is safe to send as a header.
 *
 * @param {*} value
 * @return {boolean} Whether the nonce can be sent as a request header.
 */
function isValidRestNonce( value ) {
	return (
		typeof value === 'string' &&
		value !== '' &&
		value !== '0' &&
		value.length <= 128 &&
		! /\s/.test( value )
	);
}

/**
 * Merge caller headers with authoritative headers without dropping a native
 * Headers input. Authority names always replace case-insensitive caller
 * variants so stale credentials cannot be sent alongside current ones.
 *
 * @param {Headers|Object|Array} input         Caller-supplied headers.
 * @param {Object}               authoritative Live authority headers.
 * @return {Object} Complete request headers.
 */
function mergeRequestHeaders( input, authoritative ) {
	let merged = {};
	if ( input ) {
		if (
			( typeof Headers !== 'undefined' && input instanceof Headers ) ||
			Array.isArray( input )
		) {
			merged = Object.fromEntries( new Headers( input ).entries() );
		} else {
			merged = { ...input };
		}
	}

	for ( const [ name, value ] of Object.entries( authoritative ) ) {
		const normalizedName = name.toLowerCase();
		for ( const existingName of Object.keys( merged ) ) {
			if ( existingName.toLowerCase() === normalizedName ) {
				delete merged[ existingName ];
			}
		}
		merged[ name ] = value;
	}

	return merged;
}

/**
 * Normalize caller headers into immutable Fetch entries at request entry.
 *
 * @param {Headers|Object|Array|undefined} input Caller-supplied headers.
 * @return {Array} Frozen normalized header entries.
 */
function snapshotRequestHeaders( input ) {
	const entries = [ ...new Headers( input ) ].map( ( entry ) =>
		Object.freeze( entry )
	);
	return Object.freeze( entries );
}

/**
 * Snapshot a request body before any asynchronous authority work begins.
 *
 * Strings and immutable/copyable Fetch body values may be replayed after a
 * definitive authority denial or an exact no-write session conflict. FormData
 * is copied for the first request, but its generated multipart boundary is not
 * byte-stable across Fetch attempts, so it is never replayed. Streams and
 * unknown body objects remain one-shot.
 *
 * @param {*} body Caller-supplied Fetch body.
 * @return {{body: *, retrySafe: boolean}} Frozen body contract.
 */
function snapshotRequestBody( body ) {
	if ( body === undefined || body === null || typeof body === 'string' ) {
		return { body, retrySafe: true };
	}

	if (
		typeof URLSearchParams !== 'undefined' &&
		body instanceof URLSearchParams
	) {
		return {
			body: new URLSearchParams( body.toString() ),
			retrySafe: true,
		};
	}

	if ( typeof Blob !== 'undefined' && body instanceof Blob ) {
		return { body, retrySafe: true };
	}

	try {
		const byteLength = Reflect.apply(
			arrayBufferByteLengthGetter,
			body,
			[]
		);
		const copy = new NativeArrayBuffer( byteLength );
		const source = new NativeUint8Array( body );
		const target = new NativeUint8Array( copy );
		for ( let index = 0; index < byteLength; index++ ) {
			target[ index ] = source[ index ];
		}
		return { body: copy, retrySafe: true };
	} catch {
		// The value is not an ArrayBuffer with platform internal slots.
	}

	if ( nativeArrayBufferIsView( body ) ) {
		let sourceBuffer;
		let byteOffset;
		let byteLength;
		try {
			sourceBuffer = Reflect.apply( typedArrayBufferGetter, body, [] );
			byteOffset = Reflect.apply( typedArrayByteOffsetGetter, body, [] );
			byteLength = Reflect.apply( typedArrayByteLengthGetter, body, [] );
		} catch {
			try {
				sourceBuffer = Reflect.apply( dataViewBufferGetter, body, [] );
				byteOffset = Reflect.apply(
					dataViewByteOffsetGetter,
					body,
					[]
				);
				byteLength = Reflect.apply(
					dataViewByteLengthGetter,
					body,
					[]
				);
			} catch {
				return { body, retrySafe: false };
			}
		}
		const source = new NativeUint8Array(
			sourceBuffer,
			byteOffset,
			byteLength
		);
		const copy = new NativeUint8Array( byteLength );
		for ( let index = 0; index < byteLength; index++ ) {
			copy[ index ] = source[ index ];
		}
		return {
			body: copy,
			retrySafe: true,
		};
	}

	if ( typeof FormData !== 'undefined' && body instanceof FormData ) {
		try {
			const copy = new FormData();
			for ( const [ name, value ] of body.entries() ) {
				copy.append( name, value );
			}
			return { body: copy, retrySafe: false };
		} catch {
			return { body, retrySafe: false };
		}
	}

	return { body, retrySafe: false };
}

/**
 * Read every own request option exactly once.
 *
 * Non-enumerable options are included because callers may construct a
 * RequestInit with property descriptors instead of an object literal.
 *
 * @param {Object} options Caller RequestInit.
 * @return {Object} Detached own-property values.
 */
function snapshotOwnRequestOptions( options ) {
	const captured = {};
	for ( const key of Reflect.ownKeys( options ) ) {
		captured[ key ] = options[ key ];
	}
	return captured;
}

/**
 * Capture the effective caller RequestInit at function entry.
 *
 * Fetch options are shallow scalar/reference values. Headers and mutable body
 * containers need their own snapshots so later caller changes cannot alter a
 * retry. CartPops always enforces same-origin credentials.
 *
 * @param {Object} options Caller RequestInit.
 * @return {{options: Object, headers: Array, retrySafe: boolean}} Request snapshot.
 */
function snapshotCartpopsRequest( options ) {
	const captured = snapshotOwnRequestOptions( options );
	const headers = snapshotRequestHeaders( captured.headers );
	delete captured.headers;
	captured.credentials = 'same-origin';

	let retrySafe = true;
	if ( Object.prototype.hasOwnProperty.call( captured, 'body' ) ) {
		const bodySnapshot = snapshotRequestBody( captured.body );
		captured.body = bodySnapshot.body;
		retrySafe = bodySnapshot.retrySafe;
	}

	return {
		options: Object.freeze( captured ),
		headers,
		retrySafe,
	};
}

/**
 * Classify a Store body without reading arbitrary caller-owned properties.
 *
 * @param {*} body Caller-supplied Store API body.
 * @return {'json'|'fetch'|'uncertain'} Safe body classification.
 */
function classifyStoreBody( body ) {
	if ( ! body || typeof body !== 'object' ) {
		return 'fetch';
	}

	try {
		if ( typeof FormData !== 'undefined' && body instanceof FormData ) {
			return 'fetch';
		}
		if (
			typeof URLSearchParams !== 'undefined' &&
			body instanceof URLSearchParams
		) {
			return 'fetch';
		}
		if ( typeof Blob !== 'undefined' && body instanceof Blob ) {
			return 'fetch';
		}
		try {
			Reflect.apply( arrayBufferByteLengthGetter, body, [] );
			return 'fetch';
		} catch {
			// Not an ArrayBuffer with platform internal slots.
		}
		if ( nativeArrayBufferIsView( body ) ) {
			return 'fetch';
		}
		if (
			typeof ReadableStream !== 'undefined' &&
			body instanceof ReadableStream
		) {
			return 'fetch';
		}
		if ( Array.isArray( body ) ) {
			return 'json';
		}
		const prototype = Object.getPrototypeOf( body );
		return prototype === Object.prototype || prototype === null
			? 'json'
			: 'uncertain';
	} catch {
		return 'uncertain';
	}
}

/**
 * Snapshot the complete Store API RequestInit before authority work begins.
 *
 * Every own option is read exactly once. Caller headers are
 * normalized immediately, JSON values are serialized immediately, and
 * one-shot bodies are marked non-replayable.
 *
 * @param {Object} options Caller RequestInit.
 * @return {{options: Object, headers: Array, retrySafe: boolean, jsonBody: boolean}} Frozen request snapshot.
 */
function snapshotStoreRequest( options ) {
	const captured = snapshotOwnRequestOptions( options );
	const headers = snapshotRequestHeaders( captured.headers );
	delete captured.headers;

	let retrySafe = true;
	let jsonBody = false;
	if ( Object.prototype.hasOwnProperty.call( captured, 'body' ) ) {
		const bodyType = classifyStoreBody( captured.body );
		if ( bodyType === 'json' ) {
			captured.body = JSON.stringify( captured.body );
			jsonBody = true;
		} else if ( bodyType === 'uncertain' ) {
			retrySafe = false;
		} else {
			const bodySnapshot = snapshotRequestBody( captured.body );
			captured.body = bodySnapshot.body;
			retrySafe = bodySnapshot.retrySafe;
		}
	}

	return Object.freeze( {
		options: Object.freeze( captured ),
		headers,
		retrySafe,
		jsonBody,
	} );
}

/**
 * Create one complete immutable authority value.
 *
 * @param {string} cartToken  WooCommerce Cart-Token.
 * @param {string} restNonce  WordPress REST nonce or an empty guest value.
 * @param {string} storeNonce WooCommerce Store API nonce or an empty value.
 * @return {{cartToken: string, restNonce: string, storeNonce: string}|null} Validated snapshot.
 */
function createAuthoritySnapshot( cartToken, restNonce = '', storeNonce = '' ) {
	if (
		! isValidCartToken( cartToken ) ||
		( restNonce !== '' && ! isValidRestNonce( restNonce ) ) ||
		( storeNonce !== '' && ! isValidRestNonce( storeNonce ) )
	) {
		return null;
	}

	return Object.freeze( { cartToken, restNonce, storeNonce } );
}

/**
 * Capture valid rendered authority fields for a token-bootstrapping Store read.
 *
 * This value is request-local and is never committed without a valid Store
 * response Cart-Token. Keeping the rendered REST nonce here preserves the
 * authenticated WordPress identity while the Store session is established.
 *
 * @param {Object} state CartPops state proxy.
 * @return {{cartToken: string, restNonce: string, storeNonce: string}} Detached request authority.
 */
function readStoreAttemptAuthority( state ) {
	return Object.freeze( {
		cartToken: isValidCartToken( state.cartToken ) ? state.cartToken : '',
		restNonce: isValidRestNonce( state.restNonce ) ? state.restNonce : '',
		storeNonce: isValidRestNonce( state.nonce ) ? state.nonce : '',
	} );
}

/**
 * Select the current Interactivity state and isolate module authority by owner.
 *
 * @return {Object} Current CartPops state proxy.
 */
function authorityState() {
	const state = store( 'cartpops' ).state;
	const coordinator = authorityCoordinator();
	if ( coordinator.authorityOwner !== state ) {
		coordinator.authorityOwner = state;
		coordinator.authorityOwnerEpoch++;
		coordinator.committedAuthority = null;
		coordinator.authorityRefreshRequired = false;
		coordinator.authorityEpoch++;
		coordinator.cartTokenBootstrapPromise = null;
		coordinator.authorityRefreshPromise = null;
		coordinator.authorityRefreshLease = null;
	}
	return state;
}

/**
 * Capture the authority generation and exact tuple used by one operation.
 *
 * @return {{owner: Object, ownerEpoch: number, epoch: number, authority: Object|null}} Immutable lease.
 */
function captureAuthorityLease() {
	const owner = authorityState();
	const coordinator = authorityCoordinator();
	return Object.freeze( {
		owner,
		ownerEpoch: coordinator.authorityOwnerEpoch,
		epoch: coordinator.authorityEpoch,
		authority: coordinator.committedAuthority,
	} );
}

/**
 * Whether an operation still belongs to the same exact state-proxy lifetime.
 *
 * The monotonic owner epoch distinguishes A -> B -> A substitution even when
 * the proxy object and every scalar credential later repeat.
 *
 * @param {Object} lease Captured authority lease.
 * @return {boolean} Whether the request URL and owner still match.
 */
function isCurrentAuthorityOwner( lease ) {
	const state = authorityState();
	return (
		lease?.owner === state &&
		lease.ownerEpoch === authorityCoordinator().authorityOwnerEpoch
	);
}

/**
 * Whether a response still belongs to the current authority generation.
 *
 * @param {Object} lease Captured authority lease.
 * @return {boolean} Whether the lease can publish.
 */
function isCurrentAuthorityLease( lease ) {
	const state = authorityState();
	const coordinator = authorityCoordinator();
	return (
		lease?.owner === state &&
		lease.ownerEpoch === coordinator.authorityOwnerEpoch &&
		lease.epoch === coordinator.authorityEpoch &&
		lease.authority === coordinator.committedAuthority
	);
}

/**
 * Capture all cached authority fields synchronously into one value.
 *
 * @param {Object} state CartPops state proxy.
 * @return {{cartToken: string, restNonce: string, storeNonce: string}|null} Cached snapshot.
 */
function readCachedAuthority( state ) {
	const cartToken = state.cartToken;
	const restNonce = state.restNonce;
	const storeNonce = state.nonce;
	return createAuthoritySnapshot(
		cartToken,
		isValidRestNonce( restNonce ) ? restNonce : '',
		isValidRestNonce( storeNonce ) ? storeNonce : ''
	);
}

/**
 * Publish one complete authority snapshot to the module and state mirrors.
 *
 * @param {{cartToken: string, restNonce: string, storeNonce: string}} authority Exact authority.
 * @param {Object|null}                                                lease     Expected authority generation, when response-derived.
 * @return {{cartToken: string, restNonce: string, storeNonce: string}|null} Committed snapshot, or null after supersession.
 */
function commitAuthority( authority, lease = null ) {
	if ( lease && ! isCurrentAuthorityLease( lease ) ) {
		return null;
	}
	const state = authorityState();
	const coordinator = authorityCoordinator();
	coordinator.committedAuthority = authority;
	coordinator.authorityRefreshRequired = false;
	coordinator.authorityEpoch++;
	mirrorAuthorityState( state, authority );
	return authority;
}

/**
 * Repair mutable Interactivity state mirrors from one accepted tuple.
 *
 * @param {Object} state     Current CartPops state proxy.
 * @param {Object} authority Exact accepted authority.
 * @return {void}
 */
function mirrorAuthorityState( state, authority ) {
	state.cartToken = authority.cartToken;
	state.restNonce = authority.restNonce;
	state.nonce = authority.storeNonce;
	state.isUserLoggedIn = authority.restNonce !== '';
}

/**
 * Invalidate all authority mirrors without retaining a partial probe result.
 *
 * @param {boolean}     requireLiveProof Whether the next caller must re-probe both authorities.
 * @param {Object|null} lease            Expected generation, when invalidating an attempt.
 * @return {boolean} Whether the current authority was invalidated.
 */
function invalidateAuthority( requireLiveProof = false, lease = null ) {
	if ( lease && ! isCurrentAuthorityLease( lease ) ) {
		return false;
	}
	const state = authorityState();
	const coordinator = authorityCoordinator();
	coordinator.committedAuthority = null;
	coordinator.authorityRefreshRequired =
		coordinator.authorityRefreshRequired || requireLiveProof;
	coordinator.authorityEpoch++;
	state.cartToken = '';
	state.restNonce = '';
	state.nonce = '';
	state.isUserLoggedIn = false;
	return true;
}

/**
 * Return an authority that superseded an asynchronous operation, if accepted.
 *
 * @param {Object} lease Operation's original generation.
 * @return {Promise<Object|null>} Exact newer authority, when available.
 */
async function supersedingAuthority( lease ) {
	if ( ! isCurrentAuthorityOwner( lease ) ) {
		return null;
	}
	const coordinator = authorityCoordinator();
	const refreshPromise = coordinator.authorityRefreshPromise;
	const refreshLease = coordinator.authorityRefreshLease;
	if (
		refreshPromise &&
		refreshLease?.owner === lease.owner &&
		refreshLease.ownerEpoch === lease.ownerEpoch
	) {
		const refreshed = await refreshPromise;
		if ( refreshed && isCurrentAuthorityOwner( lease ) ) {
			return refreshed;
		}
	}
	if ( ! isCurrentAuthorityOwner( lease ) ) {
		return null;
	}
	return lease.epoch !== coordinator.authorityEpoch
		? coordinator.committedAuthority
		: null;
}

/**
 * Read Store API authority headers without mutating global state.
 *
 * @param {Response} response Store API response.
 * @return {{cartToken: string, storeNonce: string}} Valid probe result.
 */
function readStoreAuthorityHeaders( response ) {
	const cartToken = response.headers?.get( 'Cart-Token' );
	const nonceHeader = response.headers?.get( 'Nonce' );
	const alternateNonceHeader = response.headers?.get(
		'X-WC-Store-API-Nonce'
	);
	const storeNonce = nonceHeader || alternateNonceHeader || '';
	return Object.freeze( {
		cartToken: isValidCartToken( cartToken ) ? cartToken : '',
		storeNonce: isValidRestNonce( storeNonce ) ? storeNonce : '',
	} );
}

/**
 * Derive a complete authority snapshot from one successful Store response.
 *
 * Missing rotation headers retain the exact authority used for that attempt;
 * no mutable state is reread.
 *
 * @param {Response}    response           Store API response.
 * @param {Object|null} attemptedAuthority Authority sent with the request.
 * @return {{cartToken: string, restNonce: string, storeNonce: string}|null} Complete rotated snapshot.
 */
function authorityFromStoreResponse( response, attemptedAuthority ) {
	const returned = readStoreAuthorityHeaders( response );
	const cartToken = returned.cartToken || attemptedAuthority?.cartToken || '';
	const restNonce = attemptedAuthority?.restNonce || '';
	const storeNonce =
		returned.storeNonce || attemptedAuthority?.storeNonce || '';
	return createAuthoritySnapshot( cartToken, restNonce, storeNonce );
}

/**
 * Publish Store response rotation only when its exact request generation is
 * still current. Omitted headers do not create a redundant generation.
 *
 * @param {Response} response           Store API response.
 * @param {Object}   attemptedAuthority Exact authority used by the request.
 * @param {Object}   lease              Request authority lease.
 * @return {Object|null} Accepted authority, or null after supersession.
 */
function commitStoreResponseAuthority( response, attemptedAuthority, lease ) {
	const rotated = authorityFromStoreResponse( response, attemptedAuthority );
	if ( ! rotated || ! isCurrentAuthorityLease( lease ) ) {
		return null;
	}
	if (
		attemptedAuthority &&
		rotated.cartToken === attemptedAuthority.cartToken &&
		rotated.restNonce === attemptedAuthority.restNonce &&
		rotated.storeNonce === attemptedAuthority.storeNonce
	) {
		mirrorAuthorityState( lease.owner, attemptedAuthority );
		return attemptedAuthority;
	}
	return commitAuthority( rotated, lease );
}

/**
 * Probe the live Store API session without publishing partial authority.
 *
 * @param {string} storeApiBase Captured Store API root.
 * @return {Promise<{cartToken: string, storeNonce: string}>} Valid probe result.
 */
async function probeStoreAuthority( storeApiBase ) {
	const response = await fetch( buildRestUrl( storeApiBase, 'cart' ), {
		cache: 'no-store',
		credentials: 'same-origin',
	} );
	const result = readStoreAuthorityHeaders( response );
	if ( ! response.ok || ! result.cartToken ) {
		throw new Error( i18n( 'requestFailed' ) );
	}
	return result;
}

/**
 * Bootstrap a complete authority snapshot from cached REST identity and a live
 * Store session probe. Concurrent callers share the exact committed value.
 *
 * @param {Object|null} ownerLease Optional request owner/URL lease.
 * @return {Promise<{cartToken: string, restNonce: string, storeNonce: string}|null>} Complete authority.
 */
async function bootstrapAuthority( ownerLease = null ) {
	if ( ownerLease && ! isCurrentAuthorityOwner( ownerLease ) ) {
		return null;
	}
	authorityState();
	const coordinator = authorityCoordinator();
	if ( coordinator.authorityRefreshPromise ) {
		if (
			ownerLease &&
			( coordinator.authorityRefreshLease?.owner !== ownerLease.owner ||
				coordinator.authorityRefreshLease.ownerEpoch !==
					ownerLease.ownerEpoch )
		) {
			return null;
		}
		const refreshed = await coordinator.authorityRefreshPromise;
		if (
			refreshed &&
			( ! ownerLease || isCurrentAuthorityOwner( ownerLease ) )
		) {
			return refreshed;
		}
		throw new Error( i18n( 'requestFailed' ) );
	}
	if ( coordinator.cartTokenBootstrapPromise ) {
		const authority = await coordinator.cartTokenBootstrapPromise;
		return ! ownerLease || isCurrentAuthorityOwner( ownerLease )
			? authority
			: null;
	}

	const state = authorityState();
	const storeApiBase = state.storeApiBase;
	const cachedRestNonce = isValidRestNonce( state.restNonce )
		? state.restNonce
		: '';
	const lease = captureAuthorityLease();
	const attempt = ( async () => {
		try {
			const storeResult = await probeStoreAuthority( storeApiBase );
			const authority = createAuthoritySnapshot(
				storeResult.cartToken,
				cachedRestNonce,
				storeResult.storeNonce
			);
			if ( ! authority ) {
				throw new Error( i18n( 'requestFailed' ) );
			}
			const committed = commitAuthority( authority, lease );
			if ( committed ) {
				return committed;
			}
			const superseding = await supersedingAuthority( lease );
			if ( superseding ) {
				return superseding;
			}
			throw new Error( i18n( 'requestFailed' ) );
		} catch ( error ) {
			const superseding = await supersedingAuthority( lease );
			if ( superseding ) {
				return superseding;
			}
			invalidateAuthority( true, lease );
			throw error;
		}
	} )();

	coordinator.cartTokenBootstrapPromise = attempt;
	try {
		const authority = await attempt;
		return ! ownerLease || isCurrentAuthorityOwner( ownerLease )
			? authority
			: null;
	} finally {
		if ( coordinator.cartTokenBootstrapPromise === attempt ) {
			coordinator.cartTokenBootstrapPromise = null;
		}
	}
}

/**
 * Return one committed snapshot, waiting for any coordinated refresh first.
 *
 * @param {Object|null} ownerLease Optional request owner/URL lease.
 * @return {Promise<{cartToken: string, restNonce: string, storeNonce: string}|null>} Complete authority.
 */
async function ensureAuthoritySnapshot( ownerLease = null ) {
	if ( ownerLease && ! isCurrentAuthorityOwner( ownerLease ) ) {
		return null;
	}
	authorityState();
	const coordinator = authorityCoordinator();
	if ( coordinator.authorityRefreshPromise ) {
		if (
			ownerLease &&
			( coordinator.authorityRefreshLease?.owner !== ownerLease.owner ||
				coordinator.authorityRefreshLease.ownerEpoch !==
					ownerLease.ownerEpoch )
		) {
			return null;
		}
		const refreshed = await coordinator.authorityRefreshPromise;
		if (
			refreshed &&
			( ! ownerLease || isCurrentAuthorityOwner( ownerLease ) )
		) {
			return refreshed;
		}
		throw new Error( i18n( 'requestFailed' ) );
	}
	const current = {
		state: authorityState(),
		authority: coordinator.committedAuthority,
	};
	if ( current.authority ) {
		return current.authority;
	}
	if ( coordinator.authorityRefreshRequired ) {
		const refreshed = await refreshAuthority( ownerLease );
		if ( refreshed ) {
			return refreshed;
		}
		throw new Error( i18n( 'requestFailed' ) );
	}

	const cached = readCachedAuthority( current.state );
	if ( cached ) {
		return commitAuthority( cached );
	}
	return bootstrapAuthority( ownerLease );
}

/**
 * Bootstrap a signed Cart-Token from a same-origin Store API cart read.
 * Concurrent callers share one request. Existing tokens are deliberately not
 * sent so a live cookie/session transition becomes the authority.
 *
 * @param {{force?: boolean}} options
 * @return {Promise<string>} Signed Cart-Token for the live browser session.
 */
export async function ensureCartToken( { force = false } = {} ) {
	if ( force ) {
		const refreshed = await refreshAuthority();
		if ( ! refreshed ) {
			throw new Error( i18n( 'requestFailed' ) );
		}
		return refreshed.cartToken;
	}
	return ( await ensureAuthoritySnapshot() ).cartToken;
}

/**
 * Refresh logged-in cookie authority with an explicit outcome.
 *
 * @param {string} nonceRefreshUrl Captured WordPress nonce refresh URL.
 * @return {Promise<{status: 'authenticated'|'logged-out'|'failed', restNonce: string}>} Exact refresh result.
 */
async function probeRestNonceAuthority( nonceRefreshUrl ) {
	if ( typeof nonceRefreshUrl !== 'string' || nonceRefreshUrl === '' ) {
		return Object.freeze( { status: 'failed', restNonce: '' } );
	}

	try {
		const response = await fetch(
			appendQueryParams( nonceRefreshUrl, { _: Date.now() } ),
			{
				cache: 'no-store',
				credentials: 'same-origin',
			}
		);
		if ( ! response.ok && response.status !== 400 ) {
			return Object.freeze( { status: 'failed', restNonce: '' } );
		}

		const freshNonce = ( await response.text() ).trim();
		if ( freshNonce === '0' ) {
			return Object.freeze( { status: 'logged-out', restNonce: '' } );
		}
		if ( ! response.ok ) {
			return Object.freeze( { status: 'failed', restNonce: '' } );
		}
		if ( ! isValidRestNonce( freshNonce ) ) {
			return Object.freeze( { status: 'failed', restNonce: '' } );
		}

		return Object.freeze( {
			status: 'authenticated',
			restNonce: freshNonce,
		} );
	} catch ( error ) {
		return Object.freeze( { status: 'failed', restNonce: '' } );
	}
}

/**
 * Refresh logged-in cookie authority through WordPress core only.
 * A `0` response means the live browser is logged out and clears stale state.
 * Anonymous REST nonces are never fetched or stored.
 *
 * @return {Promise<string|null>} Current logged-in REST nonce, if any.
 */
export async function refreshRestNonce() {
	const authority = await refreshAuthority();
	return authority?.restNonce || null;
}

/**
 * Refresh Cart-Token plus current WordPress cookie/nonce state once.
 *
 * @param {Object|null} expectedLease Optional request generation that owns the refresh.
 * @return {Promise<{cartToken: string, restNonce: string, storeNonce: string}|null>} Exact refreshed authority, or null on ambiguity.
 */
async function refreshAuthority( expectedLease = null ) {
	if ( expectedLease && ! isCurrentAuthorityOwner( expectedLease ) ) {
		return null;
	}
	authorityState();
	const coordinator = authorityCoordinator();
	if ( coordinator.authorityRefreshPromise ) {
		if (
			expectedLease &&
			( coordinator.authorityRefreshLease?.owner !==
				expectedLease.owner ||
				coordinator.authorityRefreshLease.ownerEpoch !==
					expectedLease.ownerEpoch )
		) {
			return null;
		}
		const refreshed = await coordinator.authorityRefreshPromise;
		return ! expectedLease || isCurrentAuthorityOwner( expectedLease )
			? refreshed
			: null;
	}

	if ( expectedLease && ! isCurrentAuthorityLease( expectedLease ) ) {
		const superseding = await supersedingAuthority( expectedLease );
		if ( superseding || ! isCurrentAuthorityOwner( expectedLease ) ) {
			return superseding;
		}
		if ( coordinator.committedAuthority ) {
			return null;
		}
	}

	const state = expectedLease?.owner || authorityState();
	const storeApiBase = state.storeApiBase;
	const nonceRefreshUrl = state.nonceRefreshUrl;
	if (
		! invalidateAuthority(
			true,
			isCurrentAuthorityLease( expectedLease ) ? expectedLease : null
		)
	) {
		return expectedLease ? supersedingAuthority( expectedLease ) : null;
	}
	const lease = captureAuthorityLease();

	const attempt = ( async () => {
		const [ cartResult, restResult ] = await Promise.allSettled( [
			probeStoreAuthority( storeApiBase ),
			probeRestNonceAuthority( nonceRefreshUrl ),
		] );
		if (
			cartResult.status !== 'fulfilled' ||
			restResult.status !== 'fulfilled' ||
			restResult.value.status === 'failed'
		) {
			invalidateAuthority( true, lease );
			return null;
		}

		const authority = createAuthoritySnapshot(
			cartResult.value.cartToken,
			restResult.value.restNonce,
			cartResult.value.storeNonce
		);
		if ( ! authority ) {
			invalidateAuthority( true, lease );
			return null;
		}
		return commitAuthority( authority, lease );
	} )();

	coordinator.authorityRefreshPromise = attempt;
	coordinator.authorityRefreshLease = lease;
	try {
		const authority = await attempt;
		return ! expectedLease || isCurrentAuthorityOwner( expectedLease )
			? authority
			: null;
	} finally {
		if ( coordinator.authorityRefreshPromise === attempt ) {
			coordinator.authorityRefreshPromise = null;
			coordinator.authorityRefreshLease = null;
		}
	}
}

/**
 * Read at most the allowed number of bytes from a cloned response body.
 *
 * Native browser responses use their stream so an oversized body is stopped
 * before it is buffered. A wall-clock deadline plus read/progress limits make
 * this fail closed even for a clone whose stream never settles or completes.
 *
 * @param {Response} response Cloned response.
 * @return {Promise<string|null>} Complete bounded text, or null when unsafe.
 */
async function readBoundedCartpopsJsonBody( response ) {
	if ( ! response.body || typeof response.body.getReader !== 'function' ) {
		return null;
	}

	let reader;
	try {
		reader = response.body.getReader();
	} catch {
		return null;
	}
	if ( ! reader || typeof reader !== 'object' ) {
		return null;
	}
	const cancel = () => {
		if ( typeof reader.cancel !== 'function' ) {
			return;
		}
		try {
			Promise.resolve( reader.cancel() ).catch( () => undefined );
		} catch {
			// Cancellation is best-effort and must never extend the body deadline.
		}
	};
	const release = () => {
		if ( typeof reader.releaseLock !== 'function' ) {
			return;
		}
		try {
			reader.releaseLock();
		} catch {
			// A hostile clone cannot turn cleanup failure into a retry signal.
		}
	};
	try {
		const contentLengthValue = response.headers?.get( 'Content-Length' );
		if ( contentLengthValue !== null && contentLengthValue !== undefined ) {
			const normalizedLength = String( contentLengthValue ).trim();
			if (
				! /^\d+$/.test( normalizedLength ) ||
				Number( normalizedLength ) > CARTPOPS_JSON_BODY_MAX_BYTES
			) {
				cancel();
				release();
				return null;
			}
		}
	} catch {
		cancel();
		release();
		return null;
	}

	const timeoutMarker = Object.freeze( { timedOut: true } );
	let timeoutId;
	const deadline = new Promise( ( resolve ) => {
		timeoutId = setTimeout(
			() => resolve( timeoutMarker ),
			CARTPOPS_JSON_BODY_READ_TIMEOUT_MS
		);
	} );
	let bytes = 0;
	let reads = 0;
	let emptyReads = 0;
	const chunks = [];
	try {
		while ( true ) {
			if ( reads >= CARTPOPS_JSON_BODY_MAX_READS ) {
				cancel();
				return null;
			}
			reads++;
			let read;
			try {
				read = reader.read();
			} catch {
				cancel();
				return null;
			}
			const chunk = await Promise.race( [
				Promise.resolve( read ).then(
					( value ) => ( { value } ),
					() => ( { failed: true } )
				),
				deadline,
			] );
			if ( chunk === timeoutMarker || chunk.failed ) {
				cancel();
				return null;
			}
			if ( ! chunk.value || typeof chunk.value !== 'object' ) {
				cancel();
				return null;
			}
			if ( chunk.value.done ) {
				break;
			}
			const value = chunk.value.value;
			if (
				! nativeArrayBufferIsView( value ) ||
				! ( value instanceof NativeUint8Array )
			) {
				cancel();
				return null;
			}
			let chunkByteLength;
			try {
				chunkByteLength = Reflect.apply(
					typedArrayByteLengthGetter,
					value,
					[]
				);
			} catch {
				cancel();
				return null;
			}
			if ( chunkByteLength === 0 ) {
				emptyReads++;
				if ( emptyReads > CARTPOPS_JSON_BODY_MAX_EMPTY_READS ) {
					cancel();
					return null;
				}
				continue;
			}
			emptyReads = 0;
			bytes += chunkByteLength;
			if ( bytes > CARTPOPS_JSON_BODY_MAX_BYTES ) {
				cancel();
				return null;
			}
			chunks.push( value );
		}
		const body = new NativeUint8Array( bytes );
		let offset = 0;
		for ( const chunk of chunks ) {
			body.set( chunk, offset );
			offset += Reflect.apply( typedArrayByteLengthGetter, chunk, [] );
		}
		return decodeStrictUtf8( body );
	} catch {
		cancel();
		return null;
	} finally {
		clearTimeout( timeoutId );
		release();
	}
}

/**
 * Read one bounded public message from a parsed CartPops error record.
 *
 * @param {*} body Candidate parsed response body.
 * @return {string} Bounded server message or an empty fallback marker.
 */
function boundedCartpopsErrorMessage( body ) {
	if (
		body === null ||
		typeof body !== 'object' ||
		Array.isArray( body ) ||
		! Object.prototype.hasOwnProperty.call( body, 'message' ) ||
		typeof body.message !== 'string' ||
		Array.from( body.message ).length >
			CARTPOPS_ERROR_MESSAGE_MAX_CODEPOINTS
	) {
		return '';
	}
	return body.message;
}

/**
 * Read one bounded public message from a cloned CartPops error response.
 *
 * The original response remains untouched for its owner. Unsafe, incomplete,
 * malformed, or oversized bodies/messages fail closed to an empty marker so
 * callers can publish their localized generic fallback.
 *
 * @param {Response} response Original response, never consumed here.
 * @return {Promise<string>} Bounded server message or an empty fallback marker.
 */
export async function readBoundedCartpopsErrorMessage( response ) {
	if ( ! response || typeof response.clone !== 'function' ) {
		return '';
	}

	try {
		const text = await readBoundedCartpopsJsonBody( response.clone() );
		if ( text === null ) {
			return '';
		}
		return boundedCartpopsErrorMessage( JSON.parse( text ) );
	} catch {
		return '';
	}
}

/**
 * Classify one exact CartPops error and return its optional bounded message.
 *
 * The original response is never consumed. The clone uses the same byte,
 * progress, deadline, cancellation, and strict UTF-8 gates as every CartPops
 * response classifier.
 *
 * @param {Response} response       Original response, never consumed here.
 * @param {number}   expectedStatus Exact HTTP status.
 * @param {string}   expectedCode   Exact JSON error code.
 * @return {Promise<{message: string}|null>} Matched outcome or null.
 */
export async function readBoundedCartpopsErrorOutcome(
	response,
	expectedStatus,
	expectedCode
) {
	if (
		! response ||
		response.status !== expectedStatus ||
		! Number.isInteger( expectedStatus ) ||
		typeof expectedCode !== 'string' ||
		! /^[a-z0-9_]{1,128}$/.test( expectedCode ) ||
		typeof response.clone !== 'function'
	) {
		return null;
	}

	try {
		const text = await readBoundedCartpopsJsonBody( response.clone() );
		if ( text === null ) {
			return null;
		}
		const body = JSON.parse( text );
		if (
			body === null ||
			typeof body !== 'object' ||
			Array.isArray( body ) ||
			body.code !== expectedCode
		) {
			return null;
		}
		return { message: boundedCartpopsErrorMessage( body ) };
	} catch {
		return null;
	}
}

/**
 * Classify one exact JSON code from a bounded cloned response body.
 *
 * @param {Response} response        Original response, never consumed here.
 * @param {Set}      allowedStatuses Exact HTTP statuses for the classifier.
 * @param {Set}      allowedCodes    Exact JSON codes for the classifier.
 * @return {Promise<boolean>} Whether the clone proves an allowlisted code.
 */
async function hasBoundedClonedJsonCode(
	response,
	allowedStatuses,
	allowedCodes
) {
	if (
		! allowedStatuses.has( response.status ) ||
		typeof response.clone !== 'function'
	) {
		return false;
	}

	try {
		const text = await readBoundedCartpopsJsonBody( response.clone() );
		if ( text === null ) {
			return false;
		}
		const body = JSON.parse( text );
		return (
			body !== null &&
			typeof body === 'object' &&
			! Array.isArray( body ) &&
			allowedCodes.has( body.code )
		);
	} catch {
		return false;
	}
}

/**
 * Whether a cloned CartPops response proves an authority denial that is safe
 * to replay after refreshing the Cart-Token and WordPress REST nonce.
 *
 * @param {Response} response CartPops REST response.
 * @return {Promise<boolean>} Whether the denial code is explicitly allowed.
 */
async function isRetryableAuthorityDenial( response ) {
	return hasBoundedClonedJsonCode(
		response,
		RETRYABLE_AUTHORITY_DENIAL_STATUSES,
		RETRYABLE_AUTHORITY_DENIAL_CODES
	);
}

/**
 * Whether a cloned CartPops response carries the exact retry-safe conflict.
 *
 * @param {Response} response CartPops REST response.
 * @return {Promise<boolean>} Whether the server proved no write occurred.
 */
async function isRetryableCartSessionBusy( response ) {
	return hasBoundedClonedJsonCode(
		response,
		CART_SESSION_BUSY_STATUSES,
		CART_SESSION_BUSY_CODES
	);
}

/**
 * Share one short contention delay between concurrent CartPops requests.
 *
 * @param {AbortSignal} [signal] Caller cancellation signal.
 * @return {Promise<void>} Resolves when another bounded attempt may begin.
 */
async function waitForCartSessionBusyRetry( signal ) {
	if ( signal?.aborted ) {
		throw new DOMException( 'The operation was aborted.', 'AbortError' );
	}
	const coordinator = authorityCoordinator();
	if ( ! coordinator.cartSessionBusyDelayPromise ) {
		const delay = new Promise( ( resolve ) => {
			setTimeout( resolve, CART_SESSION_BUSY_RETRY_DELAY_MS );
		} );
		coordinator.cartSessionBusyDelayPromise = delay;
		delay.then( () => {
			if ( coordinator.cartSessionBusyDelayPromise === delay ) {
				coordinator.cartSessionBusyDelayPromise = null;
			}
		} );
	}

	const delay = coordinator.cartSessionBusyDelayPromise;
	if ( ! signal || typeof signal.addEventListener !== 'function' ) {
		return delay;
	}

	return new Promise( ( resolve, reject ) => {
		let settled = false;
		const finish = ( callback ) => {
			if ( settled ) {
				return;
			}
			settled = true;
			signal.removeEventListener( 'abort', abort );
			callback();
		};
		const abort = () => {
			finish( () =>
				reject(
					new DOMException(
						'The operation was aborted.',
						'AbortError'
					)
				)
			);
		};
		signal.addEventListener( 'abort', abort, { once: true } );
		delay.then( () => finish( resolve ) );
	} );
}

/**
 * Make a request to a CartPops REST endpoint (`/cartpops/v1/*`).
 *
 * Always sends a Store API-bootstrapped Cart-Token. A logged-in REST nonce is
 * additive when available. On one authority failure, a replay-safe request
 * refreshes both live cookie authorities and retries exactly once. One-shot
 * requests return the original response without an authority side effect.
 *
 * `path` is the route after the REST base, e.g. `cartpops/v1/coupon`;
 * query values belong in `queryParams`. Returns the raw Response — callers
 * handle `.ok` / `.json()` themselves.
 *
 * @param {string} path
 * @param {Object} options
 * @param {Object} queryParams
 */
export async function cartpopsFetch( path, options = {}, queryParams = {} ) {
	const operationLease = captureAuthorityLease();
	const { restUrl } = operationLease.owner;
	const url = buildRestUrl( restUrl, path, queryParams );
	const request = snapshotCartpopsRequest( options );
	let authority = await ensureAuthoritySnapshot( operationLease );
	if ( ! authority || ! isCurrentAuthorityOwner( operationLease ) ) {
		throw new Error( i18n( 'requestFailed' ) );
	}

	const doFetch = async () => {
		if ( ! isCurrentAuthorityOwner( operationLease ) ) {
			return null;
		}
		let authorityLease = captureAuthorityLease();
		if ( authorityLease.authority !== authority ) {
			authority = await ensureAuthoritySnapshot( operationLease );
			if ( ! authority || ! isCurrentAuthorityOwner( operationLease ) ) {
				return null;
			}
			authorityLease = captureAuthorityLease();
			if ( authorityLease.authority ) {
				authority = authorityLease.authority;
			}
		}
		const attemptAuthority = authority;
		const authorityHeaders = {
			'Cart-Token': attemptAuthority.cartToken,
		};
		if ( isValidRestNonce( attemptAuthority.restNonce ) ) {
			authorityHeaders[ 'X-WP-Nonce' ] = attemptAuthority.restNonce;
		}
		const response = await fetch( url, {
			...request.options,
			headers: mergeRequestHeaders( request.headers, authorityHeaders ),
		} );
		return Object.freeze( {
			response,
			authority: attemptAuthority,
			lease: authorityLease,
		} );
	};

	let response;
	let attempts = 0;
	let authorityRefreshUsed = false;
	do {
		const attempt = await doFetch();
		if ( ! attempt ) {
			break;
		}
		response = attempt.response;
		attempts++;
		if ( attempts >= 3 ) {
			break;
		}
		if ( ! request.retrySafe ) {
			break;
		}
		if ( ! isCurrentAuthorityOwner( operationLease ) ) {
			break;
		}

		if (
			! authorityRefreshUsed &&
			( await isRetryableAuthorityDenial( response ) )
		) {
			authorityRefreshUsed = true;
			const refreshedAuthority =
				( await supersedingAuthority( attempt.lease ) ) ||
				( await refreshAuthority( attempt.lease ) );
			if (
				refreshedAuthority &&
				isCurrentAuthorityOwner( operationLease )
			) {
				authority = refreshedAuthority;
				continue;
			}
			break;
		}

		if ( await isRetryableCartSessionBusy( response ) ) {
			await waitForCartSessionBusyRetry( request.options.signal );
			continue;
		}
		break;
	} while ( attempts < 3 );

	return response || null;
}

/**
 * Build exact Store API authority headers for one request attempt.
 *
 * @param {Object|null} authority        Complete attempt authority.
 * @param {boolean}     includeCartToken Whether the endpoint mutates the cart.
 * @return {Object} Attempt authority headers.
 */
function storeAuthorityHeaders( authority, includeCartToken ) {
	const headers = {};
	if ( includeCartToken && isValidCartToken( authority?.cartToken ) ) {
		headers[ 'Cart-Token' ] = authority.cartToken;
	}
	if ( isValidRestNonce( authority?.storeNonce ) ) {
		headers.Nonce = authority.storeNonce;
		headers[ 'X-WC-Store-API-Nonce' ] = authority.storeNonce;
	}
	return headers;
}

/**
 * Rebuild a captured WooCommerce batch for one exact authority attempt.
 *
 * @param {string}     serializedBody Frozen caller batch JSON.
 * @param {Object}     authority      Complete attempt authority.
 * @param {Array|null} indexes        Original child indexes to include.
 * @return {string} Attempt-specific batch JSON.
 */
function buildAuthorizedBatchBody( serializedBody, authority, indexes = null ) {
	const batch = JSON.parse( serializedBody );
	if (
		! batch ||
		typeof batch !== 'object' ||
		! Array.isArray( batch.requests )
	) {
		return serializedBody;
	}
	const selected = indexes || batch.requests.map( ( item, index ) => index );
	const childAuthority = storeAuthorityHeaders( authority, true );
	return JSON.stringify( {
		...batch,
		requests: selected.map( ( index ) => {
			const child = batch.requests[ index ];
			return {
				...child,
				headers: mergeRequestHeaders( child?.headers, childAuthority ),
			};
		} ),
	} );
}

/**
 * Return validated batch child responses.
 *
 * @param {*} data Parsed Store API response.
 * @return {Array|null} Child response array, when structurally usable.
 */
function batchResponses( data ) {
	let responses = null;
	if ( Array.isArray( data?.responses ) ) {
		responses = data.responses;
	} else if ( Array.isArray( data ) ) {
		responses = data;
	}
	return responses?.every(
		( child ) =>
			child &&
			typeof child === 'object' &&
			Number.isInteger( child.status )
	)
		? responses
		: null;
}

/**
 * Read the immutable batch children captured at request entry.
 *
 * @param {string} serializedBody Captured Store request body.
 * @return {Array|null} Exact child request list, when structurally valid.
 */
function batchRequests( serializedBody ) {
	try {
		const data = JSON.parse( serializedBody );
		return Array.isArray( data?.requests ) &&
			data.requests.every(
				( child ) => child && typeof child === 'object'
			)
			? data.requests
			: null;
	} catch {
		return null;
	}
}

/**
 * Whether WooCommerce proved one allowlisted child stopped before its callback.
 *
 * @param {Object} childRequest  Captured batch child request.
 * @param {Object} childResponse Corresponding batch child response.
 * @return {boolean} Whether replay is proven safe.
 */
function isProvenNoWriteBatchNonceFailure( childRequest, childResponse ) {
	const code = childResponse?.body?.code;
	return (
		String( childRequest?.method || '' ).toUpperCase() === 'POST' &&
		childRequest?.path === '/wc/store/v1/cart/remove-item' &&
		( childResponse?.status === 401 || childResponse?.status === 403 ) &&
		( code === 'woocommerce_rest_missing_nonce' ||
			code === 'woocommerce_rest_invalid_nonce' )
	);
}

/**
 * Mark a dispatched Store child retry whose write outcome cannot be proven.
 *
 * Status zero is outside HTTP and therefore cannot be mistaken for either a
 * successful write or WooCommerce's exact pre-callback nonce rejection.
 *
 * @return {Object} Synthetic ambiguous child result.
 */
function ambiguousRetriedBatchChild() {
	return {
		status: 0,
		body: { code: 'cartpops_store_retry_ambiguous' },
	};
}

/**
 * Make a WC Store API request.
 *
 * @param {string} endpoint
 * @param {Object} options
 */
export async function storeApiFetch( endpoint, options = {} ) {
	const request = snapshotStoreRequest( options );
	const method = String( request.options.method || 'GET' ).toUpperCase();
	const mutating = ! [ 'GET', 'HEAD', 'OPTIONS' ].includes( method );
	const operationLease = captureAuthorityLease();
	const initialState = operationLease.owner;
	const storeApiBase = initialState.storeApiBase;
	const url = buildRestUrl( storeApiBase, endpoint );
	const isBatch = String( endpoint ).replace( /^\/+|\/+$/g, '' ) === 'batch';
	const capturedBatchRequests =
		isBatch && typeof request.options.body === 'string'
			? batchRequests( request.options.body )
			: null;
	const coordinator = authorityCoordinator();
	let storeAuthority;
	if (
		mutating ||
		coordinator.authorityRefreshPromise ||
		coordinator.authorityRefreshRequired
	) {
		storeAuthority = await ensureAuthoritySnapshot( operationLease );
		if ( ! storeAuthority || ! isCurrentAuthorityOwner( operationLease ) ) {
			throw new Error( i18n( 'requestFailed' ) );
		}
	} else {
		storeAuthority = coordinator.committedAuthority;
		if ( ! storeAuthority ) {
			const cached = readCachedAuthority( initialState );
			storeAuthority = cached
				? commitAuthority( cached )
				: readStoreAttemptAuthority( initialState );
		}
	}
	const doFetch = async ( batchIndexes = null ) => {
		if ( ! isCurrentAuthorityOwner( operationLease ) ) {
			return null;
		}
		const attemptAuthority = storeAuthority;
		const authorityLease = captureAuthorityLease();
		const authorityHeaders = storeAuthorityHeaders(
			attemptAuthority,
			mutating
		);
		const requestHeaders = mergeRequestHeaders(
			request.headers,
			authorityHeaders
		);
		if (
			request.jsonBody &&
			! Object.keys( requestHeaders ).some(
				( name ) => name.toLowerCase() === 'content-type'
			)
		) {
			requestHeaders[ 'Content-Type' ] = 'application/json';
		}

		const attemptOptions = {
			...request.options,
			credentials: 'same-origin',
			keepalive: method === 'POST',
			headers: requestHeaders,
		};
		if ( isBatch && typeof request.options.body === 'string' ) {
			attemptOptions.body = buildAuthorizedBatchBody(
				request.options.body,
				attemptAuthority,
				batchIndexes
			);
		}
		const response = await fetch( url, attemptOptions );
		return Object.freeze( {
			response,
			authority: attemptAuthority,
			lease: authorityLease,
		} );
	};

	const storeAttempt = await doFetch();
	if ( ! storeAttempt ) {
		throw new Error( i18n( 'requestFailed' ) );
	}
	const response = storeAttempt.response;

	if ( ! response.ok ) {
		if ( response.status === 401 || response.status === 403 ) {
			invalidateAuthority( true, storeAttempt.lease );
		}
		throw new Error( i18n( 'requestFailed' ) );
	}
	let data = await response.json();
	let acceptInitialResponseAuthority = true;
	if ( isBatch ) {
		const children = batchResponses( data );
		const exactBatchShape =
			children &&
			capturedBatchRequests &&
			children.length === capturedBatchRequests.length;
		const authFailureIndexes = exactBatchShape
			? children
					.map( ( child, index ) =>
						isProvenNoWriteBatchNonceFailure(
							capturedBatchRequests[ index ],
							child
						)
							? index
							: null
					)
					.filter( ( index ) => index !== null )
			: [];
		if ( request.retrySafe && authFailureIndexes.length > 0 ) {
			acceptInitialResponseAuthority = false;
			const refreshedAuthority =
				( await supersedingAuthority( storeAttempt.lease ) ) ||
				( await refreshAuthority( storeAttempt.lease ) );
			if (
				refreshedAuthority &&
				isCurrentAuthorityOwner( operationLease )
			) {
				storeAuthority = refreshedAuthority;
				const merged = [ ...children ];
				for ( const index of authFailureIndexes ) {
					merged[ index ] = ambiguousRetriedBatchChild();
				}
				data = Array.isArray( data )
					? merged
					: { ...data, responses: merged };
				try {
					const retryAttempt = await doFetch( authFailureIndexes );
					if ( ! retryAttempt ) {
						return data;
					}
					const retryResponse = retryAttempt.response;
					if ( retryResponse.ok ) {
						const retryData = await retryResponse.json();
						const retriedChildren = batchResponses( retryData );
						if (
							retriedChildren &&
							retriedChildren.length === authFailureIndexes.length
						) {
							for (
								let index = 0;
								index < retriedChildren.length;
								index++
							) {
								merged[ authFailureIndexes[ index ] ] =
									retriedChildren[ index ];
							}
							data = Array.isArray( data )
								? merged
								: { ...data, responses: merged };
							commitStoreResponseAuthority(
								retryResponse,
								retryAttempt.authority,
								retryAttempt.lease
							);
						}
					} else if (
						retryResponse.status === 401 ||
						retryResponse.status === 403
					) {
						invalidateAuthority( true, retryAttempt.lease );
					}
				} catch {
					// A retry transport/body ambiguity cannot erase known initial
					// child outcomes or authorize another write attempt.
				}
			}
		}
	}

	if ( acceptInitialResponseAuthority ) {
		commitStoreResponseAuthority(
			response,
			storeAttempt.authority,
			storeAttempt.lease
		);
	}

	return data;
}
