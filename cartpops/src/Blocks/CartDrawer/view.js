/**
 * CartPops Cart Drawer — Interactivity API store.
 *
 * @package
 */

import { store, getContext } from '@wordpress/interactivity';
import { i18n } from './i18n';
import {
	cartpopsFetch,
	ensureCartToken,
	readBoundedCartpopsErrorMessage,
	storeApiFetch,
} from './api';
import {
	applyStoreCartItemPresentation,
	installCartDrawerEventBridge,
	readCartFromWpData,
	readStoreCartItemPresentations,
	scheduleWooCartSurfaceSync,
	utf8ByteLength,
} from './browser-runtime';
import { trapFocus } from './focus-trap';
import {
	isCartHydrationTrusted,
	markCartHydrationPending,
	markCartHydrationTrusted,
	projectCartLineControls,
	projectCartPopsLineAuthorities,
	projectStoreCartLineAuthorities,
	synchronizeInitialCart,
	validateAuthoritativeCartState,
} from './initial-cart';
import {
	installCartHydrationAccessibility,
	preserveServerEachChildren,
	removeLegacyServerEachChildren,
	removePreservedServerEachChildren,
	requiresLegacyEachAdapter,
	releaseLegacyHydrationGuards,
} from './interactivity-compat';
import { isCouponRemovable } from './coupon-state';
import { saveCartSnapshot } from './snapshot';
import { materializeCartItemImage } from './image-url';
import { invokeOptionalCartDrawerExtension } from './optional-extension';
import { resolveCartAddPolicy } from './cart-add-trigger-policy';
import { installProductFormAddInterceptor } from './product-form-add';
import { installLegacyCartPopsGlobal } from './legacy-api';
import { projectLinePrice, storeLineSubtotalMinor } from './line-price';
import { projectSecondaryAction } from './secondary-action';

const ADDED_TO_CART_ANNOUNCEMENT_DELAY_MS = 50;
const EXTERNAL_SUPPLEMENTARY_REFRESH_DELAY_MS = 50;
const EXTERNAL_SUPPLEMENTARY_REFRESH_CLEANUP_KEY = Symbol.for(
	'cartpops.cartDrawerExternalSupplementaryRefreshCleanup'
);
const CUSTOMER_ERROR_MESSAGE_MAX_CHARS = 500;
const RECOMMENDATION_NAME_MAX_CHARS = 160;
const RECOMMENDATION_CONTROL_LABEL_MAX_CHARS = 200;
const RECOMMENDATION_BUTTON_LABEL_MAX_CHARS = 260;
const RECOMMENDATION_CONTROL_TEXT_MAX_CHARS = 80;
const RECOMMENDATION_URL_MAX_CHARS = 8192;
const RECOMMENDATION_BUTTON_MODES = new Set( [ 'icon', 'text', 'text_icon' ] );
const MAX_STORE_SHIPPING_PACKAGES = 20;
const MAX_STORE_SHIPPING_RATES_PER_PACKAGE = 50;
const LIGHT_CART_MAX_FEES = 100;
const LIGHT_CART_PRESENTATION_MAX_BYTES = 1000;
const LIGHT_CART_CURRENCY_MAX_BYTES = 100;
const LIGHT_CART_TEXT_HAZARD =
	/[\u0000-\u001f\u007f-\u009f\u061c\u200e\u200f\u2028-\u202e\u2066-\u2069\ufeff<>]/u;
const RECOMMENDATION_TEXT_HAZARD =
	/[\u0000-\u001f\u007f-\u009f\u061c\u200e\u200f\u2028-\u202e\u2066-\u2069\ud800-\udfff<>]/u;
const RECOMMENDATION_TEXT_HAZARDS =
	/[\u0000-\u001f\u007f-\u009f\u061c\u200e\u200f\u2028-\u202e\u2066-\u2069\ud800-\udfff<>]/gu;

/** @type {AbortController|null} Active fetch controller — aborted on supersede. */
let fetchController = null;
/** Generation counter — stale fetches silently ignored after await. */
let fetchGeneration = 0;
/** Generation counter for supplementary fetches — separate from fetchCart. */
let suppGeneration = 0;
/** Coalesced authority refresh after an external Woo cart is accepted. */
let externalSupplementaryRefreshTimerId = null;
/** @type {number|null} Single timer for the optional generic cart-add announcement. */
let addedToCartAnnouncementTimerId = null;
/** Monotonic identity preventing a superseded announcement from publishing. */
let addedToCartAnnouncementGeneration = 0;
/** Per-operation Undo expiry timers keyed by immutable removal intent ID. */
const removalUndoTimers = new Map();
let focusTrapCleanup = null;
let focusActivationFrame = null;
/** Flag set before openDrawer() when cart data is already processed. */
let skipNextFetch = false;
/** Debounce map for quantity updates — exact identity is its generation token. */
const pendingQuantityUpdates = new Map();
/**
 * Removal intents keyed by cart item key. Each immutable record retains the
 * detached item snapshot needed to reconcile or restore that one operation.
 */
const removalIntents = new Map();
/** Monotonic identity used to reject stale async removal settlements. */
let removalIntentSequence = 0;
/** Global operation queue — all cart-mutating operations chain on this. */
let cartQueue = Promise.resolve();
/** Monotonic identity for the latest customer coupon-apply request. */
let couponApplySequence = 0;
/** Monotonic identity invalidating coupon work after newer cart authority. */
let couponAuthorityGeneration = 0;
/** Batch of item keys queued for removal — flushed by debounce timer. */
const pendingBatch = new Set();
/** Debounce timer ID for batch flush. */
let batchTimerId = null;
/** Timestamp of the last successful processCartResponse — avoids redundant fetches. */
let lastCartUpdate = 0;
/** Last Store selector object accepted through the external Woo bridge. */
let lastAcceptedStoreCart = null;
/** Active payload-less refresh transaction shared by one mutation burst. */
let externalCartRefreshOwner = null;
/** Last complete cart presentation accepted from the server. */
let trustedCartPresentation = null;
/** Mutable publication detached from the immutable retained presentation. */
let retainedCartPublication = [];
/** Retained-hydration DOM accessibility controller. */
let hydrationAccessibility = null;
/** Drawer node and active modal layer currently owning focus containment. */
let activeDrawer = null;
let focusTrapLayer = null;
/** Suppress the hydration fallback close after a handled nested Escape. */
let suppressNextFallbackDrawerClose = false;
/**
 * Inline body overflow declaration owned by the current open cycle.
 *
 * @type {{ body: HTMLElement, previousValue: string, previousPriority: string, ownedValue: string, ownedPriority: string }|null}
 */
let bodyScrollLock = null;

/**
 * Lock page scrolling while retaining the exact inline declaration to restore.
 */
function acquireBodyScrollLock() {
	const body = document.body;
	if ( ! body || bodyScrollLock ) {
		return;
	}

	const previousValue = body.style.getPropertyValue( 'overflow' );
	const previousPriority = body.style.getPropertyPriority( 'overflow' );
	body.style.setProperty( 'overflow', 'hidden' );
	bodyScrollLock = {
		body,
		previousValue,
		previousPriority,
		ownedValue: body.style.getPropertyValue( 'overflow' ),
		ownedPriority: body.style.getPropertyPriority( 'overflow' ),
	};
}

/**
 * Restore page scrolling only while CartPops still owns the declaration.
 */
function releaseBodyScrollLock() {
	const lock = bodyScrollLock;
	bodyScrollLock = null;
	if ( ! lock ) {
		return;
	}

	const { body } = lock;
	if (
		body.style.getPropertyValue( 'overflow' ) !== lock.ownedValue ||
		body.style.getPropertyPriority( 'overflow' ) !== lock.ownedPriority
	) {
		return;
	}

	if ( lock.previousValue === '' ) {
		body.style.removeProperty( 'overflow' );
		return;
	}
	body.style.setProperty(
		'overflow',
		lock.previousValue,
		lock.previousPriority
	);
}

/**
 * Fill the edition-neutral client state without manufacturing optional-edition
 * fields when the optional storefront module is absent.
 *
 * @param {Object} currentState CartPops Interactivity API state proxy.
 * @return {boolean} Whether the server supplied a valid hydrated cart.
 */
function initializeSharedCartDrawerState( currentState ) {
	const originalCartCount = currentState.cartCount;
	const originalCartItems = currentState.cartItems;
	const projectedCartItems = Array.isArray( originalCartItems )
		? projectCartPopsLineAuthorities( originalCartItems )
		: originalCartItems;
	const validated = validateAuthoritativeCartState(
		projectedCartItems,
		originalCartCount
	);

	currentState._initialCartHydrationShadow = {
		cartCount: originalCartCount,
		cartItems: originalCartItems,
	};
	currentState.cartItems = projectedCartItems;

	for ( const key of [ 'coupons', 'cartFees', 'recommendations' ] ) {
		if ( ! Array.isArray( currentState[ key ] ) ) {
			currentState[ key ] = [];
		}
	}
	currentState.recommendationButtonMode = RECOMMENDATION_BUTTON_MODES.has(
		currentState.recommendationButtonMode
	)
		? currentState.recommendationButtonMode
		: 'icon';
	currentState.recommendationButtonText = recommendationButtonDisplayText(
		currentState.recommendationButtonText,
		currentState.i18n?.add
	);
	currentState.recommendations = normalizeRecommendations(
		currentState.recommendations
	);
	const secondaryAction = projectSecondaryAction(
		currentState.secondaryAction
	);
	if ( secondaryAction ) {
		currentState.secondaryAction = secondaryAction;
	} else {
		delete currentState.secondaryAction;
	}

	for ( const key of [
		'cartTotal',
		'cartSubtotal',
		'cartShipping',
		'cartTax',
		'couponCode',
		'error',
		'liveMessage',
	] ) {
		if ( typeof currentState[ key ] !== 'string' ) {
			currentState[ key ] = '';
		}
	}

	for ( const key of [
		'isOpen',
		'isLoading',
		'couponApplying',
		'couponExpanded',
	] ) {
		if ( typeof currentState[ key ] !== 'boolean' ) {
			currentState[ key ] = false;
		}
	}

	for ( const key of [ 'recsConfig', 'config', 'i18n' ] ) {
		if (
			! currentState[ key ] ||
			typeof currentState[ key ] !== 'object' ||
			Array.isArray( currentState[ key ] )
		) {
			currentState[ key ] = {};
		}
	}

	if ( ! Object.prototype.hasOwnProperty.call( currentState, 'undoItem' ) ) {
		currentState.undoItem = null;
	}
	if (
		! Object.prototype.hasOwnProperty.call( currentState, 'previousFocus' )
	) {
		currentState.previousFocus = null;
	}

	if ( validated ) {
		markCartHydrationTrusted( currentState );
		return true;
	}

	// Keep the server-rendered values intact while their DOM presentation is
	// retained. Trust guards prevent them from authorizing client mutations.
	markCartHydrationPending( currentState );
	return false;
}

/**
 * Resolve the optional edition module through the current shared store.
 *
 * @param {string} phase  Allowlisted extension phase.
 * @param {Object} detail Bounded phase details.
 */
function runOptionalExtension( phase, detail = {} ) {
	return invokeOptionalCartDrawerExtension(
		() => store( 'cartpops' ),
		state,
		phase,
		detail
	);
}

/**
 * Release the focus trap bound to the current drawer node.
 *
 * @return {void}
 */
function releaseDrawerFocusTrap() {
	if ( focusTrapCleanup ) {
		focusTrapCleanup();
	}
	focusTrapCleanup = null;
	focusTrapLayer = null;
}

/**
 * Resolve an action's exact initiating element.
 *
 * @param {Event|HTMLElement|undefined} source Action event or explicit node.
 * @return {HTMLElement|null} Initiating element when available.
 */
function actionInitiator( source ) {
	if ( source?.currentTarget?.nodeType === 1 ) {
		return source.currentTarget;
	}
	if ( source?.nodeType === 1 ) {
		return source;
	}
	// External open events have no node ref from which to resolve a document.
	// eslint-disable-next-line @wordpress/no-global-active-element
	const activeElement = document.activeElement;
	return activeElement?.nodeType === 1 ? activeElement : null;
}

/**
 * Find the drawer controlled by an initiator, falling back to shared state.
 *
 * @param {HTMLElement|null} initiator Initiating control.
 * @return {HTMLElement|null} Matching drawer.
 */
function resolveDrawer( initiator = null ) {
	const controlledId = initiator?.getAttribute?.( 'aria-controls' );
	const controlled = controlledId
		? document.getElementById( controlledId )
		: null;
	if ( controlled?.classList?.contains( 'cpops-drawer' ) ) {
		return controlled;
	}

	const stateDrawer = state?.drawerId
		? document.getElementById( state.drawerId )
		: null;
	if ( stateDrawer?.classList?.contains( 'cpops-drawer' ) ) {
		return stateDrawer;
	}

	return document.querySelector( '.cpops-drawer' );
}

/**
 * Select the topmost active dialog inside a drawer.
 *
 * @param {HTMLElement} drawer Drawer root.
 * @return {HTMLElement} Active focus layer.
 */
function topmostDrawerLayer( drawer ) {
	const optionalLayer = runOptionalExtension( 'focus-layer', { drawer } );
	if (
		optionalLayer?.nodeType === 1 &&
		( optionalLayer === drawer || drawer.contains( optionalLayer ) )
	) {
		return optionalLayer;
	}
	return drawer;
}

/**
 * Bind focus containment to the exact drawer node currently in the document.
 *
 * WooCommerce fragments may replace the complete CartPops island while it is
 * open, so node identity—not only `state.isOpen`—owns the trap lifecycle.
 *
 * @param {HTMLElement|null} drawer Current drawer node.
 * @return {void}
 */
function activateDrawerFocus( drawer ) {
	if ( ! drawer || ! state?.isOpen ) {
		releaseDrawerFocusTrap();
		activeDrawer = null;
		return;
	}

	activeDrawer = drawer;
	const layer = topmostDrawerLayer( drawer );
	if ( layer === focusTrapLayer && focusTrapCleanup ) {
		return;
	}

	releaseDrawerFocusTrap();
	focusTrapCleanup = trapFocus( layer );
	focusTrapLayer = layer;
}

/**
 * Activate the current top layer after reactive attributes have settled.
 *
 * @param {HTMLElement|null} returnFocus Optional nested-layer return point.
 * @return {void}
 */
function scheduleDrawerFocus( returnFocus = null ) {
	if ( focusActivationFrame !== null ) {
		cancelAnimationFrame( focusActivationFrame );
	}
	focusActivationFrame = requestAnimationFrame( () => {
		focusActivationFrame = null;
		if ( ! state.isOpen ) {
			return;
		}

		const drawer =
			activeDrawer?.isConnected === false
				? resolveDrawer()
				: activeDrawer || resolveDrawer();
		if ( ! drawer ) {
			return;
		}

		activateDrawerFocus( drawer );
		const layer = topmostDrawerLayer( drawer );
		if (
			returnFocus?.focus &&
			returnFocus.isConnected !== false &&
			layer.contains( returnFocus )
		) {
			returnFocus.focus();
		}
	} );
}

/**
 * Whether optimistic or server cart mutations may use current client state.
 *
 * @return {boolean} Whether initial cart hydration has been verified.
 */
function cartMutationAllowed() {
	if ( isCartHydrationTrusted( state ) ) {
		return true;
	}

	state.error = state.liveMessage || i18n( 'requestFailed' );
	return false;
}

/**
 * Return cart items only after the complete collection is authoritative.
 *
 * @return {Array} Safe cart items or an empty non-authoritative working view.
 */
function trustedCartItems() {
	return isCartHydrationTrusted( state ) && Array.isArray( state.cartItems )
		? state.cartItems
		: [];
}

/**
 * Resolve a directive context row against the latest authoritative cart.
 *
 * A keyed Interactivity context can retain the object rendered for the prior
 * directive pass. The key remains authoritative, but quantity and other
 * mutable fields must come from current state before calculating a mutation.
 *
 * @param {*} contextItem Item exposed by the current directive context.
 * @return {Object|null} Latest trusted row with the same key.
 */
function liveContextCartItem( contextItem ) {
	if (
		! contextItem ||
		typeof contextItem !== 'object' ||
		typeof contextItem.key !== 'string' ||
		contextItem.key === ''
	) {
		return null;
	}

	return (
		trustedCartItems().find( ( item ) => item.key === contextItem.key ) ||
		null
	);
}

/**
 * Re-read and normalize the latest exact-key cart row before a mutation.
 *
 * @param {*} contextItem Directive context row, which may be stale.
 * @return {Object|null} Current fail-closed control projection.
 */
function liveAuthorizedCartItem( contextItem ) {
	const item = liveContextCartItem( contextItem );
	return item ? projectCartLineControls( item ) : null;
}

/**
 * Read a non-negative JavaScript-safe minor-unit value without coercion.
 *
 * @param {*} value Candidate raw minor-unit amount.
 * @return {number|null} Safe amount or a rejection marker.
 */
function safeMinorUnits( value ) {
	if ( Number.isSafeInteger( value ) && value >= 0 ) {
		return value;
	}
	if ( typeof value !== 'string' || ! /^\d+$/.test( value ) ) {
		return null;
	}
	const amount = Number( value );
	return Number.isSafeInteger( amount ) ? amount : null;
}

/**
 * Prove that every bounded Store API package has exactly one selected rate.
 *
 * Shipping totals alone cannot distinguish a calculated free method from an
 * uncalculated cart. The exact Store API flags and selected rates provide that
 * missing authority without making optional shipping-calculator state shared.
 *
 * @param {*} cart Candidate Store API cart.
 * @return {boolean} Whether the cart proves a complete shipping selection.
 */
function hasSelectedStoreShippingRates( cart ) {
	if (
		cart?.needs_shipping !== true ||
		cart?.has_calculated_shipping !== true ||
		! Array.isArray( cart.shipping_rates ) ||
		cart.shipping_rates.length === 0 ||
		cart.shipping_rates.length > MAX_STORE_SHIPPING_PACKAGES
	) {
		return false;
	}

	return cart.shipping_rates.every( ( shippingPackage ) => {
		if (
			! shippingPackage ||
			typeof shippingPackage !== 'object' ||
			Array.isArray( shippingPackage ) ||
			! Array.isArray( shippingPackage.shipping_rates ) ||
			shippingPackage.shipping_rates.length === 0 ||
			shippingPackage.shipping_rates.length >
				MAX_STORE_SHIPPING_RATES_PER_PACKAGE
		) {
			return false;
		}

		let selected = 0;
		for ( const rate of shippingPackage.shipping_rates ) {
			if (
				! rate ||
				typeof rate !== 'object' ||
				Array.isArray( rate ) ||
				typeof rate.selected !== 'boolean'
			) {
				return false;
			}
			if ( rate.selected === true ) {
				selected += 1;
			}
		}

		return selected === 1;
	} );
}

/**
 * Project one customer-visible shipping total from authoritative Store state.
 *
 * @param {*}       cart         Candidate Store API cart.
 * @param {boolean} taxInclusive Whether drawer shipping includes shipping tax.
 * @param {*}       freeLabel    Server-localized WooCommerce free label.
 * @return {string} Formatted paid/free shipping, or an empty fail-closed value.
 */
export function projectStoreShippingTotal( cart, taxInclusive, freeLabel ) {
	if (
		typeof taxInclusive !== 'boolean' ||
		! hasSelectedStoreShippingRates( cart )
	) {
		return '';
	}

	const shipping = safeMinorUnits( cart?.totals?.total_shipping );
	const tax = taxInclusive
		? safeMinorUnits( cart?.totals?.total_shipping_tax )
		: 0;
	if ( shipping === null || tax === null ) {
		return '';
	}

	const display = shipping + tax;
	if ( ! Number.isSafeInteger( display ) ) {
		return '';
	}
	if ( display === 0 ) {
		return typeof freeLabel === 'string' && freeLabel !== ''
			? freeLabel
			: '';
	}

	return formatPrice( String( display ), cart.totals );
}

/**
 * Re-project a line's prices from its unit price for an optimistic quantity.
 * The authoritative response replaces it with WooCommerce's line subtotal.
 *
 * @param {Object} item Cart line carrying its new quantity.
 * @return {Object} Line with matching line-total presentation.
 */
function optimisticLinePrice( item ) {
	const currency = item.prices?.currency_symbol
		? item.prices
		: state._currencyTotals;
	if ( ! currency?.currency_symbol ) {
		return item;
	}
	return {
		...item,
		...projectLinePrice( item, {
			format: ( minor ) => formatPrice( String( minor ), currency ),
			eachTemplate: state.i18n?.priceEach,
		} ),
	};
}

/**
 * Publish a bounded optimistic quantity/count/price delta.
 *
 * @param {Object} item        Latest authorized cart line.
 * @param {number} newQuantity Exact target quantity.
 * @return {boolean} Whether the cart row and count were updated.
 */
function applyOptimisticQuantity( item, newQuantity ) {
	const delta = newQuantity - item.quantity;
	const newCartCount = state.cartCount + delta;
	if (
		! Number.isSafeInteger( newQuantity ) ||
		newQuantity < 1 ||
		! Number.isSafeInteger( delta ) ||
		delta === 0 ||
		! Number.isSafeInteger( newCartCount ) ||
		newCartCount < 0
	) {
		return false;
	}

	state.cartItems = trustedCartItems().map( ( current ) =>
		current.key === item.key
			? projectCartLineControls(
					optimisticLinePrice( { ...current, quantity: newQuantity } )
			  )
			: current
	);
	state.cartCount = newCartCount;

	const unitPrice = safeMinorUnits( item.prices?.price );
	const rawSubtotal = safeMinorUnits( state._rawSubtotal );
	const rawTotal = safeMinorUnits( state._rawTotal );
	const priceDelta = unitPrice === null ? Number.NaN : unitPrice * delta;
	const nextSubtotal =
		rawSubtotal === null ? Number.NaN : rawSubtotal + priceDelta;
	const nextTotal = rawTotal === null ? Number.NaN : rawTotal + priceDelta;
	if (
		Number.isSafeInteger( priceDelta ) &&
		Number.isSafeInteger( nextSubtotal ) &&
		nextSubtotal >= 0 &&
		Number.isSafeInteger( nextTotal ) &&
		nextTotal >= 0 &&
		state._currencyTotals?.currency_symbol
	) {
		state._rawSubtotal = nextSubtotal;
		state._rawTotal = nextTotal;
		state.cartSubtotal = formatPrice(
			String( nextSubtotal ),
			state._currencyTotals
		);
		state.cartTotal = formatPrice(
			String( nextTotal ),
			state._currencyTotals
		);
		runOptionalExtension( 'subtotal-changed', {
			subtotalMinor: state._rawSubtotal,
		} );
	}

	return true;
}

/**
 * Recursively detach one accepted cart presentation value.
 *
 * @param {*}       value  Cart presentation value to detach.
 * @param {boolean} freeze Whether to freeze every detached container.
 * @param {WeakMap} seen   Already detached object identities.
 * @return {*} Recursively detached presentation value.
 */
function cloneCartPresentation( value, freeze, seen = new WeakMap() ) {
	if ( value === null || typeof value !== 'object' ) {
		return value;
	}
	if ( seen.has( value ) ) {
		return seen.get( value );
	}

	const clone = Array.isArray( value ) ? [] : {};
	seen.set( value, clone );
	for ( const key of Object.keys( value ) ) {
		clone[ key ] = cloneCartPresentation( value[ key ], freeze, seen );
	}
	return freeze ? Object.freeze( clone ) : clone;
}

/**
 * Recursively detach and freeze internal retained presentation state.
 *
 * @param {*}       value Cart presentation value to detach.
 * @param {WeakMap} seen  Already detached object identities.
 * @return {*} Recursively detached and frozen presentation value.
 */
function cloneFrozenCartPresentation( value, seen = new WeakMap() ) {
	return cloneCartPresentation( value, true, seen );
}

/**
 * Recursively detach a presentation for WordPress reactive publication.
 *
 * Interactivity proxies require extensible arrays and objects. Internal
 * retained fallbacks remain frozen, but published values must stay mutable.
 *
 * @param {*} value Cart presentation value to detach.
 * @return {*} Recursively detached mutable presentation value.
 */
function cloneMutableCartPresentation( value ) {
	return cloneCartPresentation( value, false );
}

/**
 * Retain an immutable presentation while fresh cart authority is pending.
 */
function rememberTrustedCartPresentation() {
	trustedCartPresentation = cloneFrozenCartPresentation(
		Array.isArray( state.cartItems ) ? state.cartItems : []
	);
	retainedCartPublication = cloneMutableCartPresentation(
		trustedCartPresentation
	);
}

/**
 * Enter a fail-closed cart revalidation state without discarding the last
 * complete presentation.
 */
function beginCartRevalidation() {
	if ( isCartHydrationTrusted( state ) ) {
		rememberTrustedCartPresentation();
	}
	markCartHydrationPending( state );
	state.liveMessage = i18n( 'cartRefreshing' );
	hydrationAccessibility?.update();
}

/**
 * Keep cart mutations blocked while exposing a stable recovery announcement.
 */
function markCartRevalidationFailed() {
	markCartHydrationPending( state );
	exposeCustomerFailure();
	hydrationAccessibility?.update();
}

/**
 * Announce one localized CartPops-owned failure without exposing exception or
 * server response details.
 *
 * @param {string} messageKey Safe CartPops translation key.
 * @return {string} Localized customer message.
 */
function exposeCustomerFailure( messageKey = 'requestFailed' ) {
	const message = i18n( messageKey );
	state.error = message;
	state.liveMessage = message;
	return message;
}

/**
 * Reduce one server-owned customer message to bounded plain text.
 *
 * CartPops renders status through `data-wp-text`, but the response remains an
 * untrusted integration boundary: markup, controls, bidi overrides, blank
 * values, and oversized messages all fail to the localized generic copy.
 *
 * @param {*} value Candidate REST/WooCommerce message.
 * @return {string} Safe bounded customer text, or an empty rejection marker.
 */
function normalizeCustomerErrorMessage( value ) {
	if ( typeof value !== 'string' ) {
		return '';
	}

	const message = value
		.replace( /<[^>]*>/g, ' ' )
		.replace( /[<>]/g, ' ' )
		.replace(
			/[\u0000-\u001f\u007f-\u009f\u202a-\u202e\u2066-\u2069]/g,
			' '
		)
		.replace( /\s+/g, ' ' )
		.trim();
	if (
		message === '' ||
		Array.from( message ).length > CUSTOMER_ERROR_MESSAGE_MAX_CHARS
	) {
		return '';
	}

	return message;
}

/**
 * Publish a pre-sanitized server failure or the localized safe fallback.
 *
 * @param {string} message Safe bounded REST/WooCommerce message.
 * @return {string} Published customer message.
 */
function exposeCouponApplyFailure( message ) {
	const safeMessage = normalizeCustomerErrorMessage( message );
	if ( safeMessage === '' ) {
		return exposeCustomerFailure();
	}
	state.error = safeMessage;
	state.liveMessage = safeMessage;
	return safeMessage;
}

/**
 * Let an optional edition add presentation-only fields to validated cart rows.
 * The extension cannot add, remove, reorder, or replace cart-line identities.
 *
 * @param {Object} cart  Authoritative Store API cart.
 * @param {Array}  items Shared mapped cart rows.
 * @return {Array} Safe shared or extended cart rows.
 */
function projectOptionalCartItems( cart, items ) {
	let projected;
	try {
		projected = runOptionalExtension( 'cart-items-project', {
			cart: cloneFrozenCartPresentation( cart ),
			items: cloneFrozenCartPresentation( items ),
		} );
		if (
			! Array.isArray( projected ) ||
			projected.length !== items.length ||
			! projected.every(
				( item, index ) =>
					item &&
					typeof item === 'object' &&
					! Array.isArray( item ) &&
					item.key === items[ index ].key
			)
		) {
			return items;
		}
		return cloneMutableCartPresentation( projected );
	} catch {
		// A hostile optional result must not corrupt or interrupt shared rows.
		return items;
	}
}

/**
 * Validate and filter one complete server cart without accepting partial data.
 *
 * @param {*}       items                       Candidate item collection.
 * @param {*}       expectedCount               Optional server-declared aggregate.
 * @param {Object}  [options]
 * @param {boolean} [options.reconcileRemovals] Reconcile recoverable intents against this read.
 * @return {{items: Array, count: number, removalReconciliation: Array}|null} Safe current client cart.
 */
function validateCurrentCart( items, expectedCount, options = {} ) {
	const { reconcileRemovals = false } = options;
	const authoritative = validateAuthoritativeCartState(
		items,
		expectedCount
	);
	if ( ! authoritative ) {
		return null;
	}

	const imageSafeItems = projectCartPopsLineAuthorities(
		authoritative.items
	).map( ( item ) =>
		materializeCartItemImage( item, state.config?.placeholderImageUrl )
	);
	const reconciliation = [];
	const filteredItems = imageSafeItems.filter( ( item ) => {
		const intent = removalIntents.get( item.key );
		if ( ! intent ) {
			return true;
		}
		if (
			reconcileRemovals &&
			( intent.phase === 'reconciling' || intent.phase === 'confirmed' )
		) {
			reconciliation.push( { intent, present: true } );
			return true;
		}
		return false;
	} );

	if ( reconcileRemovals ) {
		const presentKeys = new Set(
			reconciliation.map( ( entry ) => entry.intent.key )
		);
		for ( const intent of removalIntents.values() ) {
			if (
				( intent.phase === 'reconciling' ||
					intent.phase === 'confirmed' ) &&
				! presentKeys.has( intent.key )
			) {
				reconciliation.push( { intent, present: false } );
			}
		}
	}

	const validated = validateAuthoritativeCartState( filteredItems );
	return validated
		? { ...validated, removalReconciliation: reconciliation }
		: null;
}

/** Publish the number of removals whose write outcome still needs recovery. */
function publishRemovalRecoveryCount() {
	state.removalRecoveryCount = [ ...removalIntents.values() ].filter(
		( intent ) => intent.phase === 'reconciling'
	).length;
}

/**
 * Install a new immutable removal intent.
 *
 * @param {Object} item  Detached cart item snapshot.
 * @param {number} index Original presentation index.
 * @return {Object} Installed intent.
 */
function createRemovalIntent( item, index ) {
	const intent = Object.freeze( {
		id: ++removalIntentSequence,
		key: item.key,
		item,
		index,
		phase: 'queued',
		outcome: 'pending',
		undoRequested: false,
		undoExpired: false,
		compensationAttempts: 0,
	} );
	removalIntents.set( intent.key, intent );
	publishRemovalRecoveryCount();
	return intent;
}

/**
 * Replace an intent only while the exact async operation still owns its key.
 *
 * @param {Object} intent  Expected current intent.
 * @param {Object} changes Next phase/outcome fields.
 * @return {Object|null} Updated intent, or null when stale.
 */
function transitionRemovalIntent( intent, changes ) {
	const current = intent && removalIntents.get( intent.key );
	if ( ! current || current.id !== intent.id ) {
		return null;
	}
	const next = Object.freeze( { ...current, ...changes } );
	removalIntents.set( intent.key, next );
	publishRemovalRecoveryCount();
	return next;
}

/**
 * Retire an intent only if the exact expected operation is still current.
 *
 * @param {Object} intent Expected current intent.
 * @return {boolean} Whether the intent was retired.
 */
function retireRemovalIntent( intent ) {
	const current = intent && removalIntents.get( intent.key );
	if ( ! current || current.id !== intent.id ) {
		return false;
	}
	const timer = removalUndoTimers.get( current.id );
	if ( timer ) {
		clearTimeout( timer );
		removalUndoTimers.delete( current.id );
	}
	removalIntents.delete( intent.key );
	if ( state.undoIntentId === current.id ) {
		state.undoItem = null;
		state.undoIntentId = null;
	}
	publishRemovalRecoveryCount();
	return true;
}

/**
 * Clear the undo presentation if it belongs to one of the given intents.
 *
 * @param {Array} intents Removal intents being settled.
 */
function clearRemovalUndo( intents ) {
	const ids = new Set( intents.map( ( intent ) => intent.id ) );
	if ( state.undoItem && ids.has( state.undoIntentId ) ) {
		state.undoItem = null;
		state.undoIntentId = null;
	}
	for ( const id of ids ) {
		const timer = removalUndoTimers.get( id );
		if ( timer ) {
			clearTimeout( timer );
			removalUndoTimers.delete( id );
		}
	}
}

/**
 * Present and expire Undo for one exact removal operation.
 *
 * @param {Object} intent Current removal intent.
 */
function scheduleRemovalUndo( intent ) {
	state.undoItem = cloneMutableCartPresentation( intent.item );
	state.undoIntentId = intent.id;
	const existing = removalUndoTimers.get( intent.id );
	if ( existing ) {
		clearTimeout( existing );
	}
	const timer = setTimeout( () => {
		removalUndoTimers.delete( intent.id );
		const current = removalIntents.get( intent.key );
		if ( ! current || current.id !== intent.id ) {
			return;
		}
		if ( state.undoIntentId === intent.id ) {
			state.undoItem = null;
			state.undoIntentId = null;
		}
		const expired = transitionRemovalIntent( current, {
			undoExpired: true,
		} );
		if ( expired?.phase === 'confirmed' ) {
			retireRemovalIntent( expired );
		}
	}, 5000 );
	removalUndoTimers.set( intent.id, timer );
}

/**
 * Restore one or more failed removals from their detached item snapshots.
 * Reverse intent order plus the captured indices reconstructs the original
 * ordering even when adjacent items were removed in one debounce window.
 *
 * @param {Array}   intents                   Exact current intents to restore.
 * @param {Object}  [options]
 * @param {boolean} [options.announceFailure] Announce a failed write.
 * @param {boolean} [options.adjustTotals]    Reapply optimistic totals for restored items.
 */
function restoreRemovalIntents(
	intents,
	{ announceFailure = false, adjustTotals = true } = {}
) {
	const current = intents
		.map( ( expected ) => {
			const intent = removalIntents.get( expected.key );
			return intent?.id === expected.id ? intent : null;
		} )
		.filter( Boolean );
	if ( current.length === 0 ) {
		return;
	}

	const items = [
		...( Array.isArray( state.cartItems ) ? state.cartItems : [] ),
	];
	const restored = [];
	for ( const intent of [ ...current ].sort( ( a, b ) => b.id - a.id ) ) {
		if ( ! items.some( ( item ) => item.key === intent.key ) ) {
			items.splice(
				Math.min( Math.max( intent.index, 0 ), items.length ),
				0,
				cloneMutableCartPresentation( intent.item )
			);
			restored.push( intent );
		}
	}

	for ( const intent of current ) {
		retireRemovalIntent( intent );
		pendingBatch.delete( intent.key );
	}
	clearRemovalUndo( current );
	state.cartItems = cloneMutableCartPresentation( items );
	state.cartCount = items.reduce(
		( count, item ) => count + Math.max( Number( item.quantity ) || 0, 0 ),
		0
	);
	dispatchCountUpdate( state.cartCount );

	if ( adjustTotals ) {
		for ( const intent of restored ) {
			const itemTotal =
				parseInt( intent.item.prices?.price || '0', 10 ) *
				( intent.item.quantity || 1 );
			if (
				itemTotal &&
				state._rawTotal !== null &&
				state._rawTotal !== undefined &&
				state._currencyTotals?.currency_symbol
			) {
				state._rawSubtotal += itemTotal;
				state._rawTotal += itemTotal;
			}
		}
	}
	if (
		adjustTotals &&
		restored.length > 0 &&
		state._currencyTotals?.currency_symbol
	) {
		state.cartSubtotal = formatPrice(
			String( state._rawSubtotal ),
			state._currencyTotals
		);
		state.cartTotal = formatPrice(
			String( state._rawTotal ),
			state._currencyTotals
		);
	}
	runOptionalExtension( 'cart-presentation-restored', {
		items: state.cartItems,
		restoredItems: restored.map( ( intent ) => intent.item ),
		subtotalMinor: state._rawSubtotal,
	} );
	if ( announceFailure ) {
		exposeCustomerFailure();
	} else if ( restored.length === 1 ) {
		state.liveMessage = i18n( 'restoredToCart', restored[ 0 ].item.name );
	}
	saveCartSnapshot();
}

/**
 * Commit a read-only authoritative cart reconciliation.
 *
 * @param {Array} reconciliation Validated presence results.
 * @return {{restored: Array, compensation: Array}} Settlement follow-up.
 */
function commitRemovalReconciliation( reconciliation ) {
	const restored = [];
	const compensation = [];
	for ( const { intent, present } of reconciliation ) {
		if ( removalIntents.get( intent.key )?.id !== intent.id ) {
			continue;
		}
		if ( present ) {
			restored.push( intent );
			retireRemovalIntent( intent );
			continue;
		}

		const confirmed = transitionRemovalIntent( intent, {
			phase: 'confirmed',
			outcome: 'removed',
		} );
		if ( ! confirmed ) {
			continue;
		}
		if ( confirmed.undoRequested ) {
			state.undoItem = cloneMutableCartPresentation( confirmed.item );
			state.undoIntentId = confirmed.id;
			if ( confirmed.compensationAttempts === 0 ) {
				compensation.push( confirmed );
			}
		} else if (
			confirmed.undoExpired ||
			state.undoIntentId !== confirmed.id
		) {
			retireRemovalIntent( confirmed );
		}
	}
	clearRemovalUndo( restored );
	return { restored, compensation };
}

/**
 * Serialize reconciliation compensation behind the exact cart operation that
 * produced it. Each queued callback revalidates the immutable intent ID.
 *
 * @param {Array} intents Confirmed removals whose Undo still needs one add.
 */
function enqueueRemovalCompensations( intents ) {
	for ( const expected of intents ) {
		cartQueue = cartQueue
			.catch( () => {} )
			.then( () => {
				const current = removalIntents.get( expected.key );
				if (
					! current ||
					current.id !== expected.id ||
					current.phase !== 'confirmed' ||
					! current.undoRequested ||
					current.compensationAttempts !== 0
				) {
					return false;
				}
				return compensateRemovalIntent( current );
			} );
	}
}

/**
 * Exact Woo pre-callback nonce failures prove that no removal write ran.
 *
 * @param {*} child Batch child response.
 * @return {boolean} Whether the child proves its callback did not run.
 */
function isDefinitiveRemovalNoWrite( child ) {
	return (
		( child?.status === 401 || child?.status === 403 ) &&
		( child?.body?.code === 'woocommerce_rest_missing_nonce' ||
			child?.body?.code === 'woocommerce_rest_invalid_nonce' )
	);
}

/**
 * Surface a generic recovery state without exposing transport internals.
 *
 */
function exposeRemovalFailure() {
	exposeCustomerFailure();
}

/**
 * Mark ambiguous intents and perform exactly one read-only cart reconciliation.
 *
 * @param {Array} intents Exact in-flight intents.
 * @return {Promise<boolean>} Whether authoritative reconciliation succeeded.
 */
async function reconcileAmbiguousRemovalIntents( intents ) {
	const reconciling = intents
		.map( ( intent ) =>
			transitionRemovalIntent( intent, {
				phase: 'reconciling',
				outcome: 'ambiguous',
			} )
		)
		.filter( Boolean );
	if ( reconciling.length === 0 ) {
		return true;
	}

	exposeRemovalFailure();
	const accepted = await store( 'cartpops' ).actions.fetchCart();
	if ( ! accepted ) {
		return false;
	}

	const restored = reconciling.filter(
		( intent ) =>
			! removalIntents.has( intent.key ) &&
			state.cartItems.some( ( item ) => item.key === intent.key )
	);
	if ( restored.length > 0 ) {
		exposeRemovalFailure();
	}
	return true;
}

/**
 * Compensate one confirmed removal after the customer requested Undo.
 *
 * @param {Object} intent Confirmed current removal intent.
 * @return {Promise<boolean>} Whether the compensation completed.
 */
async function compensateRemovalIntent( intent ) {
	const compensating = transitionRemovalIntent( intent, {
		phase: 'compensating',
		outcome: 'removed',
		compensationAttempts: intent.compensationAttempts + 1,
	} );
	if ( ! compensating ) {
		return false;
	}

	try {
		const cart = await storeApiFetch( '/cart/add-item', {
			method: 'POST',
			body: {
				id: compensating.item.id,
				quantity: compensating.item.quantity,
				variation: compensating.item.variation || [],
			},
		} );
		retireRemovalIntent( compensating );
		if ( ! completeOwnedCartMutation( processCartResponse( cart ) ) ) {
			throw new Error( 'invalid_cart_response' );
		}
		runSupplementaryFetches( 'cart-change' );
		state.liveMessage = i18n( 'restoredToCart', compensating.item.name );
		return true;
	} catch {
		const ambiguous = transitionRemovalIntent( compensating, {
			phase: 'reconciling',
			outcome: 'ambiguous',
		} );
		exposeRemovalFailure();
		if ( ambiguous ) {
			const accepted = await store( 'cartpops' ).actions.fetchCart();
			const current = removalIntents.get( ambiguous.key );
			if (
				accepted &&
				current?.id === ambiguous.id &&
				current.phase === 'confirmed' &&
				current.undoRequested
			) {
				const retryable = transitionRemovalIntent( current, {
					undoRequested: false,
				} );
				state.undoItem = retryable
					? cloneMutableCartPresentation( retryable.item )
					: null;
				state.undoIntentId = retryable?.id || null;
				exposeRemovalFailure();
			}
		}
		return false;
	}
}

/**
 * Release pending hydration only after item data and count were applied together.
 */
function releaseCartHydration() {
	rememberTrustedCartPresentation();
	markCartHydrationTrusted( state );
	if (
		state.liveMessage === i18n( 'cartRefreshing' ) ||
		state.liveMessage === i18n( 'requestFailed' )
	) {
		state.liveMessage = '';
	}
	hydrationAccessibility?.update();
	removePreservedServerEachChildren();
}

/**
 * Reject an incomplete or unsafe cart update without exposing filter failures.
 *
 * The previously rendered cart stays intact but cannot authorize mutations
 * until a later complete response has been validated atomically.
 *
 * @return {false} Cart response rejection marker.
 */
function rejectCartResponse() {
	beginCartRevalidation();
	markCartRevalidationFailed();
	return false;
}

/**
 * Build a bounded signature for authoritative coupon state.
 *
 * @param {*} coupons Candidate coupon collection.
 * @return {string|null} Stable state signature, or null for malformed input.
 */
function couponStateSignature( coupons ) {
	if ( ! Array.isArray( coupons ) || coupons.length > 100 ) {
		return null;
	}

	const stateShape = [];
	for ( const coupon of coupons ) {
		const totals = coupon?.totals;
		if (
			! coupon ||
			typeof coupon !== 'object' ||
			Array.isArray( coupon ) ||
			typeof coupon.key !== 'string' ||
			coupon.key.length === 0 ||
			coupon.key.length > 512 ||
			typeof coupon.code !== 'string' ||
			coupon.code.length > 512 ||
			typeof coupon.label !== 'string' ||
			coupon.label.length === 0 ||
			coupon.label.length > 1000 ||
			typeof coupon.is_system !== 'boolean' ||
			typeof coupon.removable !== 'boolean' ||
			! totals ||
			typeof totals !== 'object' ||
			Array.isArray( totals ) ||
			! [ totals.total_discount, totals.total_discount_tax ].every(
				( value ) =>
					typeof value === 'string' && /^\d{1,64}$/.test( value )
			) ||
			typeof totals.currency_code !== 'string' ||
			totals.currency_code.length === 0 ||
			totals.currency_code.length > 16 ||
			! Number.isInteger( totals.currency_minor_unit ) ||
			totals.currency_minor_unit < 0 ||
			totals.currency_minor_unit > 8 ||
			! [
				totals.currency_symbol,
				totals.currency_decimal_separator,
				totals.currency_thousand_separator,
				totals.currency_prefix,
				totals.currency_suffix,
			].every(
				( value ) => typeof value === 'string' && value.length <= 100
			)
		) {
			return null;
		}

		if (
			coupon.is_system
				? coupon.key !== 'cart-reward' ||
				  coupon.code !== '' ||
				  coupon.removable
				: coupon.key !== `coupon:${ coupon.code }` ||
				  coupon.code.length === 0 ||
				  ! coupon.removable
		) {
			return null;
		}

		stateShape.push( {
			key: coupon.key,
			code: coupon.code,
			label: coupon.label,
			isSystem: coupon.is_system,
			removable: coupon.removable,
			totals: {
				totalDiscount: totals.total_discount,
				totalDiscountTax: totals.total_discount_tax,
				currencyCode: totals.currency_code,
				currencySymbol: totals.currency_symbol,
				currencyMinorUnit: totals.currency_minor_unit,
				currencyDecimalSeparator: totals.currency_decimal_separator,
				currencyThousandSeparator: totals.currency_thousand_separator,
				currencyPrefix: totals.currency_prefix,
				currencySuffix: totals.currency_suffix,
			},
		} );
	}

	return JSON.stringify( stateShape );
}

/**
 * Whether a decoded light-cart value is an ordinary object record.
 *
 * @param {*} value Candidate value.
 * @return {boolean} Whether the value is a plain record.
 */
function isPlainLightCartRecord( value ) {
	if (
		value === null ||
		typeof value !== 'object' ||
		Array.isArray( value )
	) {
		return false;
	}
	try {
		const prototype = Object.getPrototypeOf( value );
		return prototype === Object.prototype || prototype === null;
	} catch {
		return false;
	}
}

/**
 * Validate one server-formatted string without rewriting its presentation.
 *
 * @param {*}       value                               Candidate presentation.
 * @param {number}  maxBytes                            Maximum UTF-8 bytes.
 * @param {Object}  [options]
 * @param {boolean} [options.allowEmpty=false]          Whether the exact empty string is valid.
 * @param {boolean} [options.allowWhitespaceOnly=false] Whether whitespace-only metadata is valid.
 * @return {string|null} Exact accepted string or a rejection marker.
 */
function boundedLightCartText(
	value,
	maxBytes,
	{ allowEmpty = false, allowWhitespaceOnly = false } = {}
) {
	if (
		typeof value !== 'string' ||
		utf8ByteLength( value ) > maxBytes ||
		LIGHT_CART_TEXT_HAZARD.test( value )
	) {
		return null;
	}
	if ( value === '' ) {
		return allowEmpty ? value : null;
	}
	if ( ! allowWhitespaceOnly && value.trim() === '' ) {
		return null;
	}
	return value;
}

/**
 * Read one exact legacy minor-unit string without coercion or overflow.
 *
 * @param {*} value Candidate legacy value.
 * @return {number|null} Safe integer amount or a rejection marker.
 */
function canonicalLegacyMinorUnits( value ) {
	if (
		typeof value !== 'string' ||
		! /^(?:0|[1-9][0-9]{0,15})$/.test( value )
	) {
		return null;
	}
	const amount = Number( value );
	return Number.isSafeInteger( amount ) && amount >= 0 ? amount : null;
}

/**
 * Project current or legacy raw pricing authority onto exact safe values.
 *
 * @param {Object} data Validated light-cart root record.
 * @return {Object|null} Safe raw authority or a rejection marker.
 */
function projectLightCartRawAuthority( data ) {
	const hasLegacySubtotal = Object.prototype.hasOwnProperty.call(
		data,
		'rawSubtotal'
	);
	const hasLegacyMinorUnit = Object.prototype.hasOwnProperty.call(
		data,
		'currencyMinorUnit'
	);
	const legacySubtotal = hasLegacySubtotal
		? canonicalLegacyMinorUnits( data.rawSubtotal )
		: null;
	const legacyMinorUnit = hasLegacyMinorUnit ? data.currencyMinorUnit : null;

	if (
		( hasLegacySubtotal && legacySubtotal === null ) ||
		( hasLegacyMinorUnit &&
			( ! Number.isInteger( legacyMinorUnit ) ||
				legacyMinorUnit < 0 ||
				legacyMinorUnit > 8 ) )
	) {
		return null;
	}

	const rawTotals = data.rawTotals;
	if (
		! isPlainLightCartRecord( rawTotals ) ||
		! Number.isSafeInteger( rawTotals.subtotal ) ||
		rawTotals.subtotal < 0 ||
		! Number.isSafeInteger( rawTotals.total ) ||
		rawTotals.total < 0 ||
		! Number.isInteger( rawTotals.currency_minor_unit ) ||
		rawTotals.currency_minor_unit < 0 ||
		rawTotals.currency_minor_unit > 8
	) {
		return null;
	}

	const currencyText = {};
	for ( const key of [
		'currency_symbol',
		'currency_prefix',
		'currency_suffix',
		'currency_decimal_separator',
		'currency_thousand_separator',
	] ) {
		const decimalSeparator = key === 'currency_decimal_separator';
		const accepted = boundedLightCartText(
			rawTotals[ key ],
			LIGHT_CART_CURRENCY_MAX_BYTES,
			{
				allowEmpty: ! decimalSeparator,
				allowWhitespaceOnly: ! decimalSeparator,
			}
		);
		if ( accepted === null ) {
			return null;
		}
		currencyText[ key ] = accepted;
	}

	if (
		( hasLegacySubtotal && legacySubtotal !== rawTotals.subtotal ) ||
		( hasLegacyMinorUnit &&
			legacyMinorUnit !== rawTotals.currency_minor_unit )
	) {
		return null;
	}

	return {
		currencyTotals: {
			subtotal: rawTotals.subtotal,
			total: rawTotals.total,
			currency_minor_unit: rawTotals.currency_minor_unit,
			...currencyText,
		},
		rawSubtotal: rawTotals.subtotal,
		rawTotal: rawTotals.total,
		currencyMinorUnit: rawTotals.currency_minor_unit,
	};
}

/**
 * Validate and project one complete CartPops light-cart response atomically.
 *
 * @param {*} data Candidate response.
 * @return {Object|null} Safe projection or a rejection marker.
 */
function projectLightCartResponse( data ) {
	if (
		! isPlainLightCartRecord( data ) ||
		couponStateSignature( data.coupons ) === null
	) {
		return null;
	}

	const currentCart = validateCurrentCart( data.cartItems, data.cartCount, {
		reconcileRemovals: true,
	} );
	if ( ! currentCart ) {
		return null;
	}

	const emptyCart = data.cartCount === 0 && data.cartItems.length === 0;
	const cartTotal = boundedLightCartText(
		data.cartTotal,
		LIGHT_CART_PRESENTATION_MAX_BYTES,
		{ allowEmpty: emptyCart }
	);
	const cartSubtotal = boundedLightCartText(
		data.cartSubtotal,
		LIGHT_CART_PRESENTATION_MAX_BYTES,
		{ allowEmpty: emptyCart }
	);
	const cartShipping = boundedLightCartText(
		data.cartShipping,
		LIGHT_CART_PRESENTATION_MAX_BYTES,
		{ allowEmpty: true }
	);
	const cartTax = boundedLightCartText(
		data.cartTax,
		LIGHT_CART_PRESENTATION_MAX_BYTES,
		{ allowEmpty: true }
	);
	if (
		[ cartTotal, cartSubtotal, cartShipping, cartTax ].includes( null ) ||
		! Array.isArray( data.cartFees ) ||
		data.cartFees.length > LIGHT_CART_MAX_FEES
	) {
		return null;
	}

	const cartFees = [];
	for ( const fee of data.cartFees ) {
		if ( ! isPlainLightCartRecord( fee ) ) {
			return null;
		}
		const name = boundedLightCartText(
			fee.name,
			LIGHT_CART_PRESENTATION_MAX_BYTES
		);
		const total = boundedLightCartText(
			fee.total,
			LIGHT_CART_PRESENTATION_MAX_BYTES
		);
		if ( name === null || total === null ) {
			return null;
		}
		cartFees.push( { name, total } );
	}

	const rawAuthority = projectLightCartRawAuthority( data );
	if ( ! rawAuthority ) {
		return null;
	}

	const coupons = data.coupons;
	const projectedData = {
		cartItems: currentCart.items,
		cartCount: currentCart.count,
		cartTotal,
		cartSubtotal,
		cartFees,
		cartShipping,
		cartTax,
		coupons,
		rawSubtotal: String( rawAuthority.rawSubtotal ),
		currencyMinorUnit: rawAuthority.currencyMinorUnit,
	};
	projectedData.rawTotals = rawAuthority.currencyTotals;

	return {
		currentCart,
		cartTotal,
		cartSubtotal,
		cartFees,
		cartShipping,
		cartTax,
		coupons,
		rawAuthority,
		data: projectedData,
	};
}

/**
 * Publish a fully validated light-cart projection as one authority change.
 *
 * @param {Object}  projection                                Validated light-cart projection.
 * @param {Object}  [options]
 * @param {boolean} [options.suppressUnchangedCount=false]    Avoid duplicate count events.
 * @param {boolean} [options.supersedeFetch=true]             Supersede a different in-flight GET.
 * @param {string}  [options.source='cartpops-rest-mutation'] Optional extension source.
 * @return {true} Accepted marker.
 */
function applyLightCartProjection(
	projection,
	{
		suppressUnchangedCount = false,
		supersedeFetch = true,
		source = 'cartpops-rest-mutation',
	} = {}
) {
	const previousCartCount = state.cartCount;
	if ( supersedeFetch ) {
		supersedeActiveCartFetch();
	}

	state.cartItems = cloneMutableCartPresentation(
		projection.currentCart.items
	);
	state.cartCount = projection.currentCart.count;
	const removalSettlement = commitRemovalReconciliation(
		projection.currentCart.removalReconciliation
	);
	enqueueRemovalCompensations( removalSettlement.compensation );
	releaseCartHydration();
	if ( ! suppressUnchangedCount || previousCartCount !== state.cartCount ) {
		dispatchCountUpdate( state.cartCount );
	}
	state.cartTotal = projection.cartTotal;
	state.cartSubtotal = projection.cartSubtotal;
	state.cartFees = projection.cartFees;
	state.cartShipping = projection.cartShipping;
	state.cartTax = projection.cartTax;
	replaceCoupons( projection.coupons );
	state.error = '';

	state._rawSubtotal = projection.rawAuthority.rawSubtotal;
	state._rawTotal = projection.rawAuthority.rawTotal;
	state._currencyTotals = projection.rawAuthority.currencyTotals;
	runOptionalExtension( 'cart-accepted', {
		data: projection.data,
		items: state.cartItems,
		source,
		subtotalMinor: state._rawSubtotal,
		totalMinor: state._rawTotal,
		currencyTotals: state._currencyTotals,
	} );

	lastCartUpdate = Date.now();
	saveCartSnapshot();
	return true;
}

/**
 * Apply authoritative coupon state.
 *
 * @param {*} coupons Authoritative response coupons.
 * @return {boolean} Whether coupon authority changed or was malformed.
 */
function replaceCoupons( coupons ) {
	const next = couponStateSignature( coupons );
	if ( next === null ) {
		return false;
	}
	const previous = couponStateSignature( state.coupons );
	const changed = previous === null || previous !== next;
	state.coupons = coupons;
	couponAuthorityGeneration++;
	runOptionalExtension( 'coupon-accepted', {
		changed,
		coupons: state.coupons,
	} );
	return changed;
}

/**
 * Read only CartPops' safe Store API coupon authority. Core Woo coupons can
 * contain internal reward identifiers and are never suitable for drawer state.
 *
 * @param {*} cart WooCommerce Store API cart.
 * @return {Array|null} Valid safe coupons, or null when authority is absent.
 */
function storeCouponAuthority( cart ) {
	const coupons = cart?.extensions?.cartpops?.coupons;
	return couponStateSignature( coupons ) === null ? null : coupons;
}

/**
 * Give optional projections a cart whose coupon field is already safe.
 *
 * @param {Object}     cart        Store API cart.
 * @param {Array|null} safeCoupons Valid extension coupons, when present.
 * @return {Object} Shallow detached cart with no raw coupon identifiers.
 */
function safeStoreCartForExtensions( cart, safeCoupons ) {
	return {
		...cart,
		coupons: cloneMutableCartPresentation( safeCoupons ?? state.coupons ),
	};
}

/**
 * Apply safe Store coupon authority or preserve the last trusted presentation
 * while scheduling one authoritative CartPops read.
 *
 * @param {Array|null} safeCoupons Valid extension coupons, when present.
 * @return {boolean} Whether trusted coupon state changed.
 */
function applyStoreCouponAuthority( safeCoupons ) {
	if ( safeCoupons === null ) {
		// The Store cart's items/totals are newer even when its optional safe
		// coupon projection is absent. Invalidate older full-cart coupon writes
		// before the corrective CartPops read is allowed to settle.
		couponAuthorityGeneration++;
		requestExternalCartRefresh();
		// Items and totals were independently validated; missing coupon
		// extension data must not revoke their trusted mutation authority.
		releaseCartHydration();
		return false;
	}
	return replaceCoupons( safeCoupons );
}

/** Supersede an older CartPops read without aborting its proxy promise chain. */
function supersedeActiveCartFetch() {
	fetchController = null;
	fetchGeneration++;
	state.isLoading = false;
	const refreshOwner = externalCartRefreshOwner;
	if ( refreshOwner ) {
		refreshOwner.superseded = true;
		refreshOwner.dirty = false;
		externalCartRefreshOwner = null;
		refreshOwner.settle( false );
	}
}

/**
 * Process a Store API cart response and update reactive state.
 * Called by fetchCart and directly by mutation actions that get the
 * full cart back from the Store API (remove-item, update-item, etc.).
 * @param {Object}  cart
 * @param {Object}  [options]
 * @param {boolean} [options.rejectInvalid=true]           Whether malformed trusted-operation data blocks mutations.
 * @param {boolean} [options.suppressUnchangedCount=false] Avoid duplicate external count events.
 */
function processCartResponse(
	cart,
	{ rejectInvalid = true, suppressUnchangedCount = false } = {}
) {
	const reject = () => ( rejectInvalid ? rejectCartResponse() : false );
	const previousCartCount = state.cartCount;
	let safeCoupons;
	let cartForExtensions;
	let currentCart;
	let rawCartAccepted = false;
	try {
		const rawCart = validateAuthoritativeCartState( cart?.items );
		if ( ! rawCart ) {
			return reject();
		}
		rawCartAccepted = true;
		const storeItemPresentations = readStoreCartItemPresentations( cart );
		const sharedItems = rawCart.items.map( ( item ) => {
			const price = item.prices?.price;
			const regularPrice = item.prices?.regular_price;
			const isOnSale =
				regularPrice &&
				price &&
				regularPrice !== price &&
				parseInt( regularPrice, 10 ) > parseInt( price, 10 );
			const mapped = {
				...item,
				// Parent product_id from CartPops item extension — falls back to
				// item.id for simple products or when the extension is absent.
				product_id: item.extensions?.cartpops?.product_id || item.id,
				short_description: stripTags( item.short_description || '' ),
				variationSummary: ( item.variation || [] )
					.map( ( v ) => `${ v.attribute }: ${ v.value }` )
					.join( ', ' ),
				formattedPrice: price ? formatPrice( price, item.prices ) : '',
				formattedRegularPrice: isOnSale
					? formatPrice( regularPrice, item.prices )
					: '',
				isOnSale: !! isOnSale,
				extraLines: [],
			};
			Object.assign(
				mapped,
				projectLinePrice( mapped, {
					format: ( minor ) =>
						formatPrice(
							String( minor ),
							item.totals?.currency_symbol
								? item.totals
								: item.prices
						),
					lineMinor: storeLineSubtotalMinor(
						item,
						state.config?.taxDisplayCart
					),
					eachTemplate: state.i18n?.priceEach,
				} )
			);
			return applyStoreCartItemPresentation(
				mapped,
				item,
				storeItemPresentations
			);
		} );
		safeCoupons = storeCouponAuthority( cart );
		cartForExtensions = safeStoreCartForExtensions( cart, safeCoupons );
		const mappedItems = projectStoreCartLineAuthorities(
			cart,
			projectOptionalCartItems( cartForExtensions, sharedItems )
		);
		const validatedCart = validateCurrentCart( mappedItems, undefined, {
			reconcileRemovals: true,
		} );
		if ( ! validatedCart ) {
			return rejectCartResponse();
		}
		const presentation = cloneFrozenCartPresentation( validatedCart.items );
		const validatedPresentation = validateAuthoritativeCartState(
			presentation,
			validatedCart.count
		);
		currentCart = validatedPresentation
			? {
					...validatedPresentation,
					removalReconciliation: validatedCart.removalReconciliation,
			  }
			: null;
	} catch {
		return rawCartAccepted ? rejectCartResponse() : reject();
	}
	if ( ! currentCart ) {
		return rejectCartResponse();
	}

	// The generation counter makes an older CartPops GET inert. Avoid abort()
	// because its rejection can leak through the Interactivity API proxy chain.
	supersedeActiveCartFetch();

	state.cartItems = cloneMutableCartPresentation( currentCart.items );
	state.cartCount = currentCart.count;
	const removalSettlement = commitRemovalReconciliation(
		currentCart.removalReconciliation
	);
	enqueueRemovalCompensations( removalSettlement.compensation );
	releaseCartHydration();
	if ( ! suppressUnchangedCount || previousCartCount !== state.cartCount ) {
		dispatchCountUpdate( state.cartCount );
	}
	state.cartTotal = cart.totals?.total_price
		? formatPrice( cart.totals.total_price, cart.totals )
		: '';
	const taxIncl = state.config?.taxDisplayCart === 'incl';
	const subtotalValue =
		taxIncl && cart.totals?.total_items_tax
			? String(
					parseInt( cart.totals.total_items, 10 ) +
						parseInt( cart.totals.total_items_tax, 10 )
			  )
			: cart.totals?.total_items;
	state.cartSubtotal = subtotalValue
		? formatPrice( subtotalValue, cart.totals )
		: '';
	state.cartFees = ( cart.fees || [] ).map( ( fee ) => {
		const feeValue =
			taxIncl && fee.totals?.total_tax
				? String(
						parseInt( fee.totals.total, 10 ) +
							parseInt( fee.totals.total_tax, 10 )
				  )
				: fee.totals?.total;
		return {
			name: fee.name,
			total: fee.totals ? formatPrice( feeValue, fee.totals ) : '',
		};
	} );
	state.cartShipping = projectStoreShippingTotal(
		cart,
		taxIncl,
		state.i18n?.freeShipping
	);
	state.cartTax =
		cart.totals?.total_tax && parseInt( cart.totals.total_tax, 10 ) > 0
			? formatPrice( cart.totals.total_tax, cart.totals )
			: '';
	applyStoreCouponAuthority( safeCoupons );
	state.error = '';

	// Store raw values for optimistic price updates in quantity changes.
	state._rawSubtotal = parseInt( subtotalValue || '0', 10 );
	state._rawTotal = parseInt( cart.totals?.total_price || '0', 10 );
	state._currencyTotals = cart.totals || {};
	runOptionalExtension( 'cart-accepted', {
		cart: cartForExtensions,
		items: state.cartItems,
		source: 'store-api',
		subtotalMinor: state._rawSubtotal,
		totalMinor: state._rawTotal,
		currencyTotals: state._currencyTotals,
	} );

	lastCartUpdate = Date.now();
	saveCartSnapshot();
	return true;
}

/**
 * Update only totals/fees/coupons from a Store API cart response.
 * Used after batch removal — client-side cartItems is source of truth,
 * server provides the correct recalculated totals.
 *
 * @param {Object}  cart                       Store API cart response.
 * @param {Object}  [options]
 * @param {boolean} [options.skipPrices=false] When true, do NOT update
 *                                             subtotal/total/_rawSubtotal/_rawTotal. Used when more removals are
 *                                             still pending — the optimistic values are more accurate than the
 *                                             server's totals which still include not-yet-confirmed removals.
 */
function updateTotalsFromCart( cart, { skipPrices = false } = {} ) {
	const safeCoupons = storeCouponAuthority( cart );
	const cartForExtensions = safeStoreCartForExtensions( cart, safeCoupons );
	supersedeActiveCartFetch();

	const taxIncl = state.config?.taxDisplayCart === 'incl';

	if ( ! skipPrices ) {
		state.cartTotal = cart.totals?.total_price
			? formatPrice( cart.totals.total_price, cart.totals )
			: '';
		const subtotalValue =
			taxIncl && cart.totals?.total_items_tax
				? String(
						parseInt( cart.totals.total_items, 10 ) +
							parseInt( cart.totals.total_items_tax, 10 )
				  )
				: cart.totals?.total_items;
		state.cartSubtotal = subtotalValue
			? formatPrice( subtotalValue, cart.totals )
			: '';
		state._rawSubtotal = parseInt( subtotalValue || '0', 10 );
		state._rawTotal = parseInt( cart.totals?.total_price || '0', 10 );
	}

	// Always update fees, shipping, tax, coupons — these can't be
	// calculated client-side and the server values are authoritative.
	state.cartFees = ( cart.fees || [] ).map( ( fee ) => {
		const feeValue =
			taxIncl && fee.totals?.total_tax
				? String(
						parseInt( fee.totals.total, 10 ) +
							parseInt( fee.totals.total_tax, 10 )
				  )
				: fee.totals?.total;
		return {
			name: fee.name,
			total: fee.totals ? formatPrice( feeValue, fee.totals ) : '',
		};
	} );
	state.cartShipping = projectStoreShippingTotal(
		cart,
		taxIncl,
		state.i18n?.freeShipping
	);
	state.cartTax =
		cart.totals?.total_tax && parseInt( cart.totals.total_tax, 10 ) > 0
			? formatPrice( cart.totals.total_tax, cart.totals )
			: '';
	const couponsChanged = applyStoreCouponAuthority( safeCoupons );
	state.error = '';
	state._currencyTotals = cart.totals || {};
	runOptionalExtension( 'cart-accepted', {
		cart: cartForExtensions,
		items: state.cartItems,
		source: 'totals-only',
		subtotalMinor: state._rawSubtotal,
		totalMinor: state._rawTotal,
		currencyTotals: state._currencyTotals,
	} );

	lastCartUpdate = Date.now();
	saveCartSnapshot();
	return couponsChanged;
}

/**
 * Process a lightweight CartPops REST response (pre-formatted strings).
 * Used by the custom coupon endpoint which bypasses the heavy Store API
 * serialization (shipping rates, full item schema, extensions).
 * @param {Object}  data
 * @param {Object}  [options]
 * @param {boolean} [options.suppressUnchangedCount=false] Avoid duplicate external count events.
 */
function processLightCartResponse(
	data,
	{ suppressUnchangedCount = false } = {}
) {
	const projection = projectLightCartResponse( data );
	return projection
		? applyLightCartProjection( projection, { suppressUnchangedCount } )
		: false;
}

/**
 * Publish one coalesced WooCommerce surface refresh only after the current
 * CartPops-owned mutation response has been validated and applied.
 *
 * @param {boolean} accepted                     Whether authoritative cart state was applied.
 * @param {Object}  [options]
 * @param {boolean} [options.completeState=true] Whether that state is the complete
 *                                               resulting cart. Removals keep
 *                                               client lines and need the
 *                                               follow-up read.
 * @return {boolean} The accepted marker for caller control flow.
 */
function completeOwnedCartMutation( accepted, { completeState = true } = {} ) {
	if ( accepted !== true ) {
		return false;
	}

	scheduleWooCartSurfaceSync( { completeState } );
	return true;
}

/** Cancel one not-yet-started supplementary read. */
function cancelExternalSupplementaryRefresh() {
	if ( externalSupplementaryRefreshTimerId === null ) {
		return false;
	}
	clearTimeout( externalSupplementaryRefreshTimerId );
	externalSupplementaryRefreshTimerId = null;
	return true;
}

/**
 * Coalesce accepted external Woo carts onto one authoritative optional read.
 *
 * The accepted Store response may update subtotal-derived progress immediately,
 * but messages, tier qualification, rewards, and accessible labels remain
 * server-owned supplementary data.
 */
function scheduleExternalSupplementaryRefresh() {
	if ( externalSupplementaryRefreshTimerId !== null ) {
		return;
	}
	externalSupplementaryRefreshTimerId = setTimeout( () => {
		externalSupplementaryRefreshTimerId = null;
		void runSupplementaryFetches( 'external-cart-change' );
	}, EXTERNAL_SUPPLEMENTARY_REFRESH_DELAY_MS );
}

/**
 * Apply one external cart. Store selector identity suppresses unrelated
 * wp.data notifications without guessing at hook- or edition-projected state.
 *
 * @param {Object} data   Store API or CartPops cart response.
 * @param {string} source Stable wire-shape identity.
 * @return {boolean} Whether the cart was valid and is current.
 */
function acceptExternalCart( data, source ) {
	if (
		source === 'store-api' &&
		( ! validateAuthoritativeCartState( data?.items ) ||
			! data?.totals ||
			typeof data.totals !== 'object' ||
			Array.isArray( data.totals ) ||
			! Array.isArray( data.fees ) )
	) {
		return false;
	}
	if (
		source !== 'store-api' &&
		( ! validateAuthoritativeCartState(
			data?.cartItems,
			data?.cartCount
		) ||
			! [
				data?.cartTotal,
				data?.cartSubtotal,
				data?.cartShipping,
				data?.cartTax,
			].every( ( value ) => typeof value === 'string' ) ||
			! Array.isArray( data?.cartFees ) ||
			couponStateSignature( data?.coupons ) === null )
	) {
		return false;
	}

	if ( source === 'store-api' ) {
		if ( data === lastAcceptedStoreCart ) {
			supersedeActiveCartFetch();
			if ( storeCouponAuthority( data ) === null ) {
				requestExternalCartRefresh();
			}
			lastCartUpdate = Date.now();
			state.error = '';
			releaseCartHydration();
			return true;
		}
	}

	const accepted =
		source === 'store-api'
			? processCartResponse( data, {
					rejectInvalid: false,
					suppressUnchangedCount: true,
			  } )
			: processLightCartResponse( data, {
					suppressUnchangedCount: true,
			  } );
	if ( accepted && source === 'store-api' ) {
		lastAcceptedStoreCart = data;
	}
	if ( accepted ) {
		scheduleExternalSupplementaryRefresh();
	}
	return accepted;
}

/**
 * Fetch shared supplementary drawer data. An optional edition may satisfy the
 * same request with a superset response; otherwise Free performs its own
 * recommendations-only request.
 *
 * @param {string} reason Bounded refresh reason exposed to an optional edition.
 * @return {Promise<boolean>} Whether current supplementary data was accepted.
 */
async function runSupplementaryFetches( reason = 'cart-refresh' ) {
	// An explicit current read supersedes the not-yet-started external timer.
	cancelExternalSupplementaryRefresh();
	const gen = ++suppGeneration;

	let data = await runOptionalExtension( 'supplementary-refresh', {
		reason,
	} );
	if ( gen !== suppGeneration ) {
		return false;
	}
	if (
		! data ||
		typeof data !== 'object' ||
		Array.isArray( data ) ||
		! Object.prototype.hasOwnProperty.call( data, 'recommendations' )
	) {
		try {
			const response = await cartpopsFetch(
				'cartpops/v1/drawer-data',
				{ credentials: 'same-origin' },
				{ _: Date.now(), fields: 'recommendations' }
			);
			data = response.ok ? await response.json() : null;
		} catch {
			data = null;
		}
	}

	if (
		gen !== suppGeneration ||
		! data ||
		typeof data !== 'object' ||
		Array.isArray( data ) ||
		! Array.isArray( data.recommendations ) ||
		data.recommendations.length > 100
	) {
		return false;
	}

	state.recommendations = normalizeRecommendations( data.recommendations );
	resetRecommendationScroll();
	runOptionalExtension( 'recommendations-accepted', {
		recommendations: state.recommendations,
	} );
	return true;
}

/**
 * Return horizontal recommendations to their first card once a new list renders.
 *
 * Scroll snapping keeps the previously snapped card in view at its new index,
 * so a reordered list would otherwise open part-scrolled with a clipped card.
 */
function resetRecommendationScroll() {
	if ( typeof requestAnimationFrame !== 'function' ) {
		return;
	}
	requestAnimationFrame( () => {
		document
			.querySelectorAll( '.cpops-recs__list--horizontal' )
			.forEach( ( list ) => {
				list.scrollLeft = 0;
			} );
	} );
}

/**
 * Accept only a plain, bounded authoritative label from the shared endpoint.
 *
 * @param {*}      value    Candidate text.
 * @param {number} maxChars Maximum Unicode code points.
 * @return {string} Safe label or an empty rejection sentinel.
 */
function acceptedRecommendationText( value, maxChars ) {
	if (
		typeof value !== 'string' ||
		value.length > maxChars * 2 ||
		RECOMMENDATION_TEXT_HAZARD.test( value )
	) {
		return '';
	}
	const trimmed = value.trim();
	if ( trimmed === '' || Array.from( trimmed ).length > maxChars ) {
		return '';
	}
	return trimmed;
}

/**
 * Project the canonical recommendation text into translated display text.
 *
 * The exact `Add` sentinel remains stable in settings and filters. At the
 * customer boundary it is replaced with the server-provided translation.
 *
 * @param {*} value             Canonical or custom recommendation text.
 * @param {*} translatedDefault Server-provided translation of `Add`.
 * @return {string} Safe customer-facing text.
 */
export function recommendationButtonDisplayText( value, translatedDefault ) {
	const canonical = acceptedRecommendationText(
		value,
		RECOMMENDATION_CONTROL_TEXT_MAX_CHARS
	);
	if ( canonical && canonical !== 'Add' ) {
		return canonical;
	}

	return (
		acceptedRecommendationText(
			translatedDefault,
			RECOMMENDATION_CONTROL_TEXT_MAX_CHARS
		) || 'Add'
	);
}

/**
 * Reduce an untrusted async product name to bounded plain text.
 *
 * @param {*}      value    Candidate product name.
 * @param {number} maxChars Maximum Unicode code points.
 * @return {string} Sanitized text.
 */
function sanitizedRecommendationText( value, maxChars ) {
	if ( typeof value !== 'string' ) {
		return '';
	}
	const plain = value
		.slice( 0, maxChars * 8 )
		.replace( /<[^>]*>/gu, ' ' )
		.replace( RECOMMENDATION_TEXT_HAZARDS, ' ' )
		.replace( /\s+/gu, ' ' )
		.trim();
	return Array.from( plain ).slice( 0, maxChars ).join( '' ).trim();
}

/**
 * Resolve one async recommendation label without sharing context across rows.
 *
 * @param {*}      product Candidate recommendation payload.
 * @param {string} action  Canonical recommendation action.
 * @return {string} Bounded localized control text.
 */
function recommendationControlText( product, action ) {
	const authoritative = acceptedRecommendationText(
		product?.control_text,
		RECOMMENDATION_CONTROL_TEXT_MAX_CHARS
	);
	if ( authoritative ) {
		return authoritative;
	}
	const fallback = i18n(
		action === 'select_options' ? 'selectOptions' : 'addToCart'
	);
	return (
		sanitizedRecommendationText(
			fallback,
			RECOMMENDATION_CONTROL_TEXT_MAX_CHARS
		) || ( action === 'select_options' ? 'Select options' : 'Add to cart' )
	);
}

/**
 * Resolve one action-specific accessible label without sharing row context.
 *
 * @param {*}      product     Candidate recommendation payload.
 * @param {string} action      Canonical recommendation action.
 * @param {string} controlText Safe visible control text.
 * @return {string} Bounded localized accessible name.
 */
function recommendationControlLabel( product, action, controlText ) {
	const candidate =
		product?.control_label ??
		( action === 'add' ? product?.add_label : undefined );
	const authoritative = acceptedRecommendationText(
		candidate,
		RECOMMENDATION_CONTROL_LABEL_MAX_CHARS
	);
	if ( authoritative ) {
		return authoritative;
	}

	const productName = sanitizedRecommendationText(
		product?.name,
		RECOMMENDATION_NAME_MAX_CHARS
	);
	const constructed = sanitizedRecommendationText(
		productName
			? i18n(
					action === 'select_options'
						? 'selectOptionsForProduct'
						: 'addProductToCart',
					productName
			  )
			: controlText,
		RECOMMENDATION_CONTROL_LABEL_MAX_CHARS
	);
	return constructed || controlText;
}

/**
 * Compose the paid visible button text with the product name. Icon mode keeps
 * the exact per-product label supplied by the shared recommendation engine.
 *
 * @param {*}      product       Candidate recommendation payload.
 * @param {string} fallbackLabel Safe per-product recommendation label.
 * @return {string} Bounded accessible name.
 */
function recommendationButtonControlLabel( product, fallbackLabel ) {
	const mode = RECOMMENDATION_BUTTON_MODES.has(
		state.recommendationButtonMode
	)
		? state.recommendationButtonMode
		: 'icon';
	if ( mode === 'icon' ) {
		return fallbackLabel;
	}

	const visibleText = acceptedRecommendationText(
		state.recommendationButtonText,
		RECOMMENDATION_CONTROL_TEXT_MAX_CHARS
	);
	const productName = sanitizedRecommendationText(
		product?.name,
		RECOMMENDATION_NAME_MAX_CHARS
	);
	if ( ! visibleText || ! productName ) {
		return fallbackLabel;
	}

	return (
		sanitizedRecommendationText(
			`${ visibleText }: ${ productName }`,
			RECOMMENDATION_BUTTON_LABEL_MAX_CHARS
		) || fallbackLabel
	);
}

/**
 * Accept only a bounded HTTP(S) or unambiguous root-relative navigation URL.
 *
 * @param {*} value Candidate product permalink.
 * @return {string} Exact accepted URL or an empty rejection sentinel.
 */
function acceptedRecommendationUrl( value ) {
	if (
		typeof value !== 'string' ||
		value === '' ||
		value.length > RECOMMENDATION_URL_MAX_CHARS ||
		value.trim() !== value ||
		/[\u0000-\u0020\u007f\\]/u.test( value )
	) {
		return '';
	}

	try {
		if ( value.startsWith( '/' ) ) {
			if ( value.startsWith( '//' ) ) {
				return '';
			}
			new URL(
				value,
				globalThis.location?.origin || 'https://cartpops.invalid'
			);
			return value;
		}

		if ( ! /^https?:\/\//iu.test( value ) ) {
			return '';
		}
		const parsed = new URL( value );
		if (
			! [ 'http:', 'https:' ].includes( parsed.protocol ) ||
			! parsed.hostname ||
			parsed.username ||
			parsed.password
		) {
			return '';
		}
		return value;
	} catch {
		return '';
	}
}

/**
 * Canonicalize one server recommendation into the closed drawer shape.
 *
 * @param {*} product Candidate recommendation row.
 * @return {Object|null} Canonical row or a fail-closed rejection.
 */
function normalizeRecommendation( product ) {
	if (
		! product ||
		typeof product !== 'object' ||
		Array.isArray( product ) ||
		! Number.isSafeInteger( product.id ) ||
		product.id <= 0 ||
		! [ 'add', 'select_options' ].includes( product.action )
	) {
		return null;
	}

	const action = product.action;
	const permalink = acceptedRecommendationUrl( product.permalink );
	if ( action === 'select_options' && ! permalink ) {
		return null;
	}
	const controlText = recommendationControlText( product, action );
	const controlLabel = recommendationControlLabel(
		product,
		action,
		controlText
	);
	const recommendationButtonLabel =
		action === 'add'
			? recommendationButtonControlLabel( product, controlLabel )
			: controlLabel;
	let formattedPrice = '';
	if ( typeof product.formatted_price === 'string' ) {
		formattedPrice = product.formatted_price;
	} else if ( typeof product.formattedPrice === 'string' ) {
		formattedPrice = product.formattedPrice;
	}

	return {
		id: product.id,
		name: typeof product.name === 'string' ? product.name : '',
		price:
			typeof product.price === 'string' ||
			typeof product.price === 'number'
				? String( product.price )
				: '',
		formatted_price:
			typeof product.formatted_price === 'string'
				? product.formatted_price
				: '',
		formattedPrice,
		image: typeof product.image === 'string' ? product.image : '',
		permalink,
		action,
		control_text: controlText,
		control_label: controlLabel,
		recommendation_button_label: recommendationButtonLabel,
		add_label: action === 'add' ? controlLabel : '',
		is_add_action: action === 'add',
		is_select_options_action: action === 'select_options',
	};
}

/**
 * Canonicalize a bounded recommendation list and drop malformed rows.
 *
 * @param {Array} products Candidate recommendation rows.
 * @return {Array} Canonical recommendation rows.
 */
function normalizeRecommendations( products ) {
	if ( ! Array.isArray( products ) || products.length > 100 ) {
		return [];
	}
	return products
		.map( ( product ) => normalizeRecommendation( product ) )
		.filter( Boolean );
}

/**
 * Debounced server sync for quantity changes. Unsent clicks coalesce, while
 * an intent queued behind an older request survives that request's response.
 * @param {string} key
 * @param {number} quantity
 */
function debouncedQuantityUpdate( key, quantity ) {
	const pending = pendingQuantityUpdates.get( key );
	if ( pending ) {
		clearTimeout( pending.timeoutId );
	}

	const update = { quantity, timeoutId: null };
	update.timeoutId = setTimeout( () => {
		cartQueue = cartQueue
			.catch( () => {} )
			.then( () => {
				if ( pendingQuantityUpdates.get( key ) !== update ) {
					return;
				}
				pendingQuantityUpdates.delete( key );
				const item = liveAuthorizedCartItem( { key } );
				const limits = item?.quantityLimits;
				if (
					! isCartHydrationTrusted( state ) ||
					! item?.showQuantityControls ||
					! Number.isSafeInteger( quantity ) ||
					quantity < limits.minimum ||
					quantity > limits.maximum ||
					quantity % limits.multipleOf !== 0
				) {
					return;
				}

				return storeApiFetch( '/cart/update-item', {
					method: 'POST',
					body: { key, quantity },
				} ).then( ( cart ) => {
					if (
						! completeOwnedCartMutation(
							processCartResponse( cart )
						)
					) {
						throw new Error( 'invalid_cart_response' );
					}
					runSupplementaryFetches( 'cart-change' );
				} );
			} )
			.catch( () => {
				store( 'cartpops' )
					.actions.fetchCart()
					.catch( () => {} );
				exposeCustomerFailure();
			} );
	}, 300 );

	pendingQuantityUpdates.set( key, update );
}

/**
 * Read the child results from either supported WooCommerce batch shape.
 *
 * @param {*} batch Parsed batch response.
 * @return {Array} Child response list, or an empty invalid marker.
 */
function removalBatchResponses( batch ) {
	if ( Array.isArray( batch?.responses ) ) {
		return batch.responses;
	}
	return Array.isArray( batch ) ? batch : [];
}

/**
 * Flush pending removal keys as a single WC Store API batch request.
 * Chained on cartQueue — only totals are taken from the server response,
 * cartItems stays client-side (source of truth for removals).
 */
function flushRemovalBatch() {
	batchTimerId = null;
	const keys = [ ...pendingBatch ];
	pendingBatch.clear();
	if ( keys.length === 0 ) {
		return;
	}

	let activeIntents = [];
	cartQueue = cartQueue
		.catch( () => {} )
		.then( async () => {
			// Filter keys that were undo'd between queuing and flushing.
			activeIntents = keys
				.map( ( key ) => removalIntents.get( key ) )
				.filter( ( intent ) => intent?.phase === 'queued' )
				.map( ( intent ) =>
					transitionRemovalIntent( intent, {
						phase: 'in-flight',
						outcome: 'pending',
					} )
				)
				.filter( Boolean );
			if ( activeIntents.length === 0 ) {
				return;
			}

			let batch;
			try {
				batch = await storeApiFetch( '/batch', {
					method: 'POST',
					body: {
						requests: activeIntents.map( ( intent ) => ( {
							path: '/wc/store/v1/cart/remove-item',
							method: 'POST',
							cache: 'no-store',
							body: { key: intent.key },
						} ) ),
					},
				} );
			} catch {
				await reconcileAmbiguousRemovalIntents( activeIntents );
				return;
			}

			const responses = removalBatchResponses( batch );
			if ( responses.length !== activeIntents.length ) {
				await reconcileAmbiguousRemovalIntents( activeIntents );
				return;
			}

			let cart = null;
			let confirmedRemovalNeedsSync = false;
			const restore = [];
			const ambiguous = [];
			const compensation = [];
			for ( let index = 0; index < activeIntents.length; index++ ) {
				const intent = activeIntents[ index ];
				const child = responses[ index ];
				const succeeded =
					Number.isInteger( child?.status ) &&
					child.status >= 200 &&
					child.status < 300;
				if ( succeeded ) {
					const confirmed = transitionRemovalIntent( intent, {
						phase: 'confirmed',
						outcome: 'removed',
					} );
					if ( confirmed?.undoRequested ) {
						compensation.push( confirmed );
					} else if (
						confirmed &&
						( confirmed.undoExpired ||
							state.undoIntentId !== confirmed.id )
					) {
						retireRemovalIntent( confirmed );
					}
					if ( confirmed && ! confirmed.undoRequested ) {
						confirmedRemovalNeedsSync = true;
					}
					if ( child.body?.totals ) {
						cart = child.body;
					}
					continue;
				}

				if ( isDefinitiveRemovalNoWrite( child ) ) {
					restore.push( intent );
				} else {
					ambiguous.push( intent );
				}
			}

			if ( restore.length > 0 ) {
				restoreRemovalIntents( restore, {
					announceFailure: true,
					adjustTotals: ! cart?.totals,
				} );
			}

			if ( cart?.totals ) {
				const hasMorePending = [ ...removalIntents.values() ].some(
					( intent ) =>
						intent.phase === 'queued' ||
						intent.phase === 'in-flight' ||
						intent.phase === 'reconciling'
				);
				const couponsChanged = updateTotalsFromCart( cart, {
					skipPrices: hasMorePending,
				} );
				if ( couponsChanged ) {
					runSupplementaryFetches( 'coupon-change' );
				}
			}
			if ( restore.length > 0 ) {
				exposeRemovalFailure();
			}

			for ( const intent of compensation ) {
				if ( ! ( await compensateRemovalIntent( intent ) ) ) {
					confirmedRemovalNeedsSync = true;
				}
			}
			if ( ambiguous.length > 0 ) {
				await reconcileAmbiguousRemovalIntents( ambiguous );
			}
			// Only totals came from the server, so the read that follows the
			// fragment refresh still reconciles lines and supplementary data.
			completeOwnedCartMutation( confirmedRemovalNeedsSync, {
				completeState: false,
			} );
		} )
		.catch( async () => {
			const unresolved = activeIntents
				.map( ( expected ) => {
					const intent = removalIntents.get( expected.key );
					return intent?.id === expected.id ? intent : null;
				} )
				.filter(
					( intent ) =>
						intent &&
						( intent.phase === 'in-flight' ||
							intent.phase === 'reconciling' )
				);
			if ( unresolved.length > 0 ) {
				await reconcileAmbiguousRemovalIntents( unresolved );
			} else {
				exposeRemovalFailure();
			}
		} );
}

const { state } = store( 'cartpops', {
	state: {
		removalRecoveryCount: 0,
		undoIntentId: null,
		optionalExtensionStatus: {
			available: false,
			failures: 0,
			invocations: 0,
			lastPhase: '',
		},

		get hasFees() {
			return state.cartFees.length > 0;
		},

		get hasCoupons() {
			return state.coupons.length > 0;
		},

		get recommendationButtonShowsText() {
			return RECOMMENDATION_BUTTON_MODES.has(
				state.recommendationButtonMode
			)
				? [ 'text', 'text_icon' ].includes(
						state.recommendationButtonMode
				  )
				: false;
		},

		get recommendationButtonShowsIcon() {
			return RECOMMENDATION_BUTTON_MODES.has(
				state.recommendationButtonMode
			)
				? [ 'icon', 'text_icon' ].includes(
						state.recommendationButtonMode
				  )
				: true;
		},

		get errorHidden() {
			return ! state.error;
		},

		get couponDiscount() {
			if ( state.coupons.length === 0 ) {
				return '';
			}
			const totals = state.coupons[ 0 ]?.totals;
			const includeTax = state.config?.taxDisplayCart === 'incl';
			const total = state.coupons.reduce(
				( sum, c ) =>
					sum +
					parseInt( c.totals?.total_discount || '0', 10 ) +
					( includeTax
						? parseInt( c.totals?.total_discount_tax || '0', 10 )
						: 0 ),
				0
			);
			return '-' + formatPrice( total, totals );
		},

		get showTaxLine() {
			// Only show btw as a separate line for excl-tax display.
			// For incl-tax, the tax note is shown after the total instead.
			return (
				state.config?.taxEnabled &&
				state.config?.taxDisplayCart === 'excl' &&
				state.cartTax !== ''
			);
		},

		get showTaxNote() {
			// Show "(incl X btw)" after the total for incl-tax display.
			return (
				state.config?.taxEnabled &&
				state.config?.taxDisplayCart === 'incl' &&
				state.cartTax !== ''
			);
		},

		get taxNoteText() {
			if ( ! state.showTaxNote ) {
				return '';
			}
			const label = state.config?.taxLabel || 'btw';
			return `(${ i18n( 'inclTax', state.cartTax, label ) })`;
		},

		get isEmpty() {
			return (
				isCartHydrationTrusted( state ) &&
				trustedCartItems().length === 0
			);
		},

		get renderCartItems() {
			const items = isCartHydrationTrusted( state )
				? trustedCartItems()
				: retainedCartPublication;
			return items.filter( ( item ) => item?.visible === true );
		},

		get drawerAriaHidden() {
			return state.isOpen ? 'false' : 'true';
		},

		get drawerInert() {
			return ! state.isOpen;
		},

		get launcherLabel() {
			return state.isOpen ? i18n( 'closeCart' ) : i18n( 'openCart' );
		},

		get undoMessage() {
			if ( ! state.undoItem ) {
				return '';
			}
			return i18n( 'removed', state.undoItem.name );
		},

		get recsEmpty() {
			return (
				! state.recommendations || state.recommendations.length === 0
			);
		},
	},

	actions: {
		handleDrawerClick( event ) {
			// Prevent theme-level document click handlers (e.g. Blocksy)
			// from closing the drawer when clicking inside it.
			event.stopPropagation();
			event.stopImmediatePropagation();
		},

		handleOverlayClick( event ) {
			// Guard: only close if the click is genuinely on the overlay.
			// DOM check: ignore clicks from inside the drawer element.
			if ( event.target.closest( '.cpops-drawer' ) ) {
				return;
			}

			// Coordinate check: if the overlay sits on top of the drawer due to
			// a CSS stacking issue, the click target is the overlay even though the
			// user clicked in the drawer area. Detect this and bail out.
			const drawerEl = activeDrawer || resolveDrawer();
			if ( drawerEl ) {
				const rect = drawerEl.getBoundingClientRect();
				if (
					event.clientX >= rect.left &&
					event.clientX <= rect.right &&
					event.clientY >= rect.top &&
					event.clientY <= rect.bottom
				) {
					return;
				}
			}

			store( 'cartpops' ).actions.closeDrawer();
		},

		openDrawer( source, { forceFetch = false } = {} ) {
			// If already open, refresh data and return.
			if ( state.isOpen ) {
				if ( skipNextFetch ) {
					// Fragment already updated cart — just fetch supplementary data.
					skipNextFetch = false;
					runSupplementaryFetches( 'drawer-refresh' );
				} else {
					store( 'cartpops' )
						.actions.fetchCart()
						.catch( () => {} );
				}
				return;
			}

			const initiator = actionInitiator( source );
			state.previousFocus = initiator;
			activeDrawer = resolveDrawer( initiator );
			state.isOpen = true;
			acquireBodyScrollLock();
			hydrationAccessibility?.update?.();
			runOptionalExtension( 'drawer-opened', { source: initiator } );

			// Fetch fresh cart data unless pre-populated (e.g. from wp.data or fragments).
			if ( skipNextFetch ) {
				skipNextFetch = false;
				runSupplementaryFetches( 'drawer-open' );
			} else {
				// Skip fetch if one is already running (e.g. stale cache detection)
				// or if cart was just refreshed (within last 10 s). An unresolved
				// removal outcome always requires a fresh authoritative read.
				const cartFresh = Date.now() - lastCartUpdate < 10000;
				const removalRecoveryRequired = state.removalRecoveryCount > 0;
				const hydrationRecoveryRequired =
					! isCartHydrationTrusted( state );
				if (
					forceFetch ||
					( ! fetchController &&
						( ! cartFresh ||
							removalRecoveryRequired ||
							hydrationRecoveryRequired ) )
				) {
					// fetchCart success handler calls runSupplementaryFetches,
					// so we don't call it separately here.
					store( 'cartpops' )
						.actions.fetchCart( {
							showLoading: trustedCartItems().length === 0,
						} )
						.catch( () => {} );
				} else {
					// Cart is fresh, only fetch supplementary data.
					runSupplementaryFetches( 'drawer-open' );
				}
			}

			// Set up focus only if this open cycle survives until the frame.
			scheduleDrawerFocus();
		},

		closeDrawer() {
			suppressNextFallbackDrawerClose = false;
			const wasOpen = state.isOpen;
			runOptionalExtension( 'drawer-closing', { wasOpen } );
			state.isOpen = false;
			releaseBodyScrollLock();
			if ( focusActivationFrame !== null ) {
				cancelAnimationFrame( focusActivationFrame );
				focusActivationFrame = null;
			}
			releaseDrawerFocusTrap();
			activeDrawer = null;
			hydrationAccessibility?.update?.();

			// Consume the initiating control before restoring it so a duplicate
			// close path cannot restore focus twice.
			const returnFocus = state.previousFocus;
			state.previousFocus = null;
			if ( wasOpen && returnFocus?.focus ) {
				returnFocus.focus();
			}
		},

		activateSecondaryAction() {
			const secondaryAction = projectSecondaryAction(
				state.secondaryAction
			);
			if (
				secondaryAction?.mode !== 'continue_shopping' ||
				secondaryAction.closesDrawer !== true ||
				secondaryAction.navigationUrl !== null
			) {
				return;
			}

			store( 'cartpops' ).actions.closeDrawer();
		},

		toggleDrawer( event ) {
			if ( state.isOpen ) {
				store( 'cartpops' ).actions.closeDrawer( event );
			} else {
				store( 'cartpops' ).actions.openDrawer( event );
			}
		},

		handleKeydown( event ) {
			if ( event.key !== 'Escape' || ! state.isOpen ) {
				return;
			}

			event.preventDefault?.();
			event.stopImmediatePropagation?.();
			if ( runOptionalExtension( 'escape-key', { event } ) === true ) {
				return;
			}

			store( 'cartpops' ).actions.closeDrawer( event );
		},

		async fetchCart( { showLoading = false, externalSource = '' } = {} ) {
			// Hybrid approach: AbortController cancels the HTTP request (releases
			// WooCommerce session lock fast), generation counter ignores stale results.
			// AbortError is caught at promise-level (.catch) to prevent it from
			// leaking through the Interactivity API Proxy's separate promise chain.
			if ( fetchController ) {
				fetchController.abort();
			}
			const controller = new AbortController();
			fetchController = controller;
			const myGen = ++fetchGeneration;
			beginCartRevalidation();

			try {
				if ( showLoading ) {
					state.isLoading = true;
				}

				// Use CartPops' own endpoint instead of the WC Store API GET /cart.
				// It calculates totals before returning, keeping the drawer total in
				// sync with the authoritative WooCommerce session.
				const response = await cartpopsFetch(
					'cartpops/v1/cart',
					{
						signal: controller.signal,
					},
					{ _: Date.now() }
				).catch( ( err ) => {
					if ( err.name === 'AbortError' ) {
						return null;
					}
					throw err;
				} );

				// Aborted or superseded by a newer call.
				if ( ! response || myGen !== fetchGeneration ) {
					return false;
				}

				if ( ! response.ok ) {
					throw new Error( i18n( 'requestFailed' ) );
				}

				const data = await response.json();
				if ( myGen !== fetchGeneration ) {
					return false;
				}
				const projection = projectLightCartResponse( data );
				if ( ! projection ) {
					throw new Error( i18n( 'requestFailed' ) );
				}
				if ( myGen !== fetchGeneration ) {
					return false;
				}
				applyLightCartProjection( projection, {
					suppressUnchangedCount: Boolean( externalSource ),
					supersedeFetch: false,
					source: externalSource
						? 'cartpops-rest-external'
						: 'cartpops-rest',
				} );

				runSupplementaryFetches( 'cart-refresh' );
				return true;
			} catch {
				if ( myGen === fetchGeneration ) {
					if ( isCartHydrationTrusted( state ) ) {
						exposeCustomerFailure();
					} else {
						markCartRevalidationFailed();
					}
				}
				return false;
			} finally {
				if ( fetchController === controller ) {
					fetchController = null;
				}
				if ( myGen === fetchGeneration ) {
					state.isLoading = false;
				}
			}
		},

		removeItem() {
			if ( ! cartMutationAllowed() ) {
				return;
			}
			const ctx = getContext();
			const item = liveAuthorizedCartItem( ctx.item );
			if ( ! item?.showRemoveControl || removalIntents.has( item.key ) ) {
				return;
			}

			// Cancel any pending quantity debounce for this item — otherwise
			// the debounce fires after removal and re-adds the item server-side.
			const pendingQty = pendingQuantityUpdates.get( item.key );
			if ( pendingQty ) {
				clearTimeout( pendingQty.timeoutId );
				pendingQuantityUpdates.delete( item.key );
			}

			// Optimistic: remove from UI immediately.
			const removedIndex = state.cartItems.findIndex(
				( i ) => i.key === item.key
			);
			if ( removedIndex < 0 ) {
				return;
			}
			let removedItem;
			try {
				removedItem = cloneFrozenCartPresentation(
					state.cartItems[ removedIndex ]
				);
			} catch {
				exposeRemovalFailure();
				return;
			}
			const intent = createRemovalIntent( removedItem, removedIndex );
			state.cartItems = state.cartItems.filter(
				( i ) => i.key !== item.key
			);
			state.cartCount = Math.max(
				0,
				state.cartCount - ( removedItem?.quantity || 1 )
			);
			dispatchCountUpdate( state.cartCount );

			// Optimistic price update — instant total feedback.
			const itemTotal =
				parseInt( removedItem?.prices?.price || '0', 10 ) *
				( removedItem?.quantity || 1 );
			if (
				itemTotal &&
				state._rawTotal !== null &&
				state._rawTotal !== undefined &&
				state._currencyTotals?.currency_symbol
			) {
				state._rawSubtotal = Math.max(
					0,
					state._rawSubtotal - itemTotal
				);
				state._rawTotal = Math.max(
					0,
					( state._rawTotal || 0 ) - itemTotal
				);
				state.cartSubtotal = formatPrice(
					String( state._rawSubtotal ),
					state._currencyTotals
				);
				state.cartTotal = formatPrice(
					String( state._rawTotal ),
					state._currencyTotals
				);
			}
			runOptionalExtension( 'cart-item-removed', {
				item: removedItem,
				items: state.cartItems,
				subtotalMinor: state._rawSubtotal,
			} );

			// Show undo (if enabled in settings).
			if ( state.config?.showUndo !== false ) {
				scheduleRemovalUndo( intent );
			}

			state.liveMessage = i18n(
				'removedFromCart',
				removedItem?.name || i18n( 'item' )
			);

			// Queue for batch removal — debounced 300ms, then flushed as
			// a single WC Store API /batch request via flushRemovalBatch().
			pendingBatch.add( item.key );
			if ( batchTimerId ) {
				clearTimeout( batchTimerId );
			}
			batchTimerId = setTimeout( flushRemovalBatch, 300 );
		},

		undoRemove() {
			if ( ! cartMutationAllowed() ) {
				return;
			}
			const item = state.undoItem;
			if ( ! item ) {
				return;
			}
			const intent = removalIntents.get( item.key );
			if ( ! intent || intent.id !== state.undoIntentId ) {
				state.undoItem = null;
				state.undoIntentId = null;
				return;
			}

			clearRemovalUndo( [ intent ] );

			// Still in pending batch (not yet sent)? Restore locally.
			if ( intent.phase === 'queued' ) {
				pendingBatch.delete( item.key );
				restoreRemovalIntents( [ intent ] );
				return;
			}

			if (
				intent.phase === 'in-flight' ||
				intent.phase === 'reconciling'
			) {
				transitionRemovalIntent( intent, { undoRequested: true } );
				state.liveMessage = i18n( 'cartRefreshing' );
				return;
			}

			if ( intent.phase === 'confirmed' ) {
				const requested = transitionRemovalIntent( intent, {
					undoRequested: true,
				} );
				cartQueue = cartQueue
					.catch( () => {} )
					.then( () => compensateRemovalIntent( requested ) );
			}
		},

		incrementQuantity() {
			if ( ! cartMutationAllowed() ) {
				return;
			}
			const ctx = getContext();
			const item = liveAuthorizedCartItem( ctx.item );
			if ( ! item?.canIncrementQuantity ) {
				return;
			}

			const newQty = item.quantity + item.quantityLimits.multipleOf;
			if ( ! applyOptimisticQuantity( item, newQty ) ) {
				return;
			}

			// Debounced server sync — rapid clicks produce one POST.
			debouncedQuantityUpdate( item.key, newQty );
		},

		decrementQuantity() {
			if ( ! cartMutationAllowed() ) {
				return;
			}
			const ctx = getContext();
			const item = liveAuthorizedCartItem( ctx.item );
			if ( ! item?.canDecrementQuantity ) {
				return;
			}

			const newQty = item.quantity - item.quantityLimits.multipleOf;
			if ( newQty < item.quantityLimits.minimum ) {
				return store( 'cartpops' ).actions.removeItem();
			}
			if ( ! applyOptimisticQuantity( item, newQty ) ) {
				return;
			}

			// Debounced server sync — rapid clicks produce one POST.
			debouncedQuantityUpdate( item.key, newQty );
		},

		setCouponCode( event ) {
			state.couponCode = event.target.value;
		},

		// Short phone screens collapse the coupon form behind a toggle.
		toggleCouponForm( event ) {
			state.couponExpanded = ! state.couponExpanded;
			if ( ! state.couponExpanded ) {
				return;
			}
			const input = event?.target
				?.closest?.( '.cpops-coupon' )
				?.querySelector?.( '.cpops-coupon__input' );
			focusWhenRendered( input );
		},

		handleCouponKeydown( event ) {
			if ( event.key !== 'Enter' || event.isComposing ) {
				return;
			}
			event.preventDefault();
			store( 'cartpops' ).actions.applyCoupon();
		},

		applyCoupon() {
			if ( ! cartMutationAllowed() ) {
				return;
			}
			state.error = '';
			const code = state.couponCode.trim();
			if ( ! code ) {
				return;
			}
			const operationId = ++couponApplySequence;
			let authorityAtDispatch = null;
			let customerFailureMessage = '';
			runOptionalExtension( 'coupon-changing', { operation: 'apply' } );

			// Coupon authority stays byte-for-byte unchanged until the server
			// returns a complete validated cart response. This prevents a failed
			// duplicate/invalid apply from deleting a coupon Woo still owns.
			state.couponCode = '';
			state.couponApplying = true;

			cartQueue = cartQueue
				.catch( () => {} )
				.then( async () => {
					authorityAtDispatch = couponAuthorityGeneration;
					const res = await cartpopsFetch(
						'cartpops/v1/coupon',
						{
							method: 'POST',
							headers: {
								'Content-Type': 'application/json',
							},
							body: JSON.stringify( { code } ),
						},
						{ _: Date.now() }
					);

					if ( ! res?.ok ) {
						customerFailureMessage =
							await readBoundedCartpopsErrorMessage( res );
						throw new Error( i18n( 'requestFailed' ) );
					}
					if ( authorityAtDispatch !== couponAuthorityGeneration ) {
						return;
					}

					const data = await res.json();
					if ( authorityAtDispatch !== couponAuthorityGeneration ) {
						return;
					}
					if (
						! completeOwnedCartMutation(
							processLightCartResponse( data )
						)
					) {
						throw new Error( i18n( 'requestFailed' ) );
					}
					state.liveMessage = i18n( 'couponApplied', code );
					runOptionalExtension( 'coupon-applied', { code } );
					await runSupplementaryFetches( 'coupon-change' );
				} )
				.catch( () => {
					if (
						operationId !== couponApplySequence ||
						authorityAtDispatch !== couponAuthorityGeneration
					) {
						return;
					}
					exposeCouponApplyFailure( customerFailureMessage );
				} )
				.finally( () => {
					if ( operationId === couponApplySequence ) {
						state.couponApplying = false;
					}
				} );
		},

		removeCoupon() {
			if ( ! cartMutationAllowed() ) {
				return;
			}
			const ctx = getContext();
			const coupon = ctx.coupon;
			if ( ! isCouponRemovable( coupon ) ) {
				return;
			}
			const code = coupon.code;

			cartQueue = cartQueue
				.catch( () => {} )
				.then( async () => {
					if ( ! cartMutationAllowed() ) {
						return;
					}
					runOptionalExtension( 'coupon-changing', {
						operation: 'remove',
					} );
					const authorityAtDispatch = couponAuthorityGeneration;
					try {
						const res = await cartpopsFetch(
							'cartpops/v1/coupon',
							{
								method: 'DELETE',
							},
							{ _: Date.now(), code }
						);

						if ( ! res?.ok ) {
							throw new Error( i18n( 'requestFailed' ) );
						}
						if (
							authorityAtDispatch !== couponAuthorityGeneration
						) {
							throw new Error( i18n( 'requestFailed' ) );
						}

						const data = await res.json();
						if (
							authorityAtDispatch !== couponAuthorityGeneration
						) {
							throw new Error( i18n( 'requestFailed' ) );
						}
						const projection = projectLightCartResponse( data );
						if (
							! projection ||
							authorityAtDispatch !== couponAuthorityGeneration
						) {
							throw new Error( i18n( 'requestFailed' ) );
						}
						if (
							! completeOwnedCartMutation(
								applyLightCartProjection( projection )
							)
						) {
							throw new Error( i18n( 'requestFailed' ) );
						}
					} catch {
						try {
							await store( 'cartpops' ).actions.fetchCart();
						} catch {
							// The trusted presentation stays intact and revalidation
							// remains failed closed when the recovery read also fails.
						}
						exposeCustomerFailure();
						return;
					}

					state.liveMessage = i18n( 'couponRemoved', code );
					await runSupplementaryFetches( 'coupon-change' ).catch(
						() => false
					);
				} );
		},

		addRecommendation() {
			if ( ! cartMutationAllowed() ) {
				return;
			}
			const ctx = getContext();
			const rec = ctx.rec;
			if (
				! rec ||
				rec.action !== 'add' ||
				! Number.isSafeInteger( rec.id ) ||
				rec.id <= 0
			) {
				return;
			}

			cartQueue = cartQueue
				.catch( () => {} )
				.then( async () => {
					const response = await cartpopsFetch(
						'cartpops/v1/recommendations/add',
						{
							method: 'POST',
							headers: {
								'Content-Type': 'application/json',
							},
							body: JSON.stringify( { product_id: rec.id } ),
						}
					);

					if ( ! response.ok ) {
						throw new Error( i18n( 'requestFailed' ) );
					}

					const data = await response.json();
					if (
						! completeOwnedCartMutation(
							processLightCartResponse( data )
						)
					) {
						throw new Error( i18n( 'requestFailed' ) );
					}
					runSupplementaryFetches( 'cart-change' );

					state.liveMessage = i18n( 'addedToCart', rec.name );
					runOptionalExtension( 'recommendation-added', {
						recommendation: rec,
					} );
				} )
				.catch( () => {
					exposeCustomerFailure();
				} );
		},

		scrollRecsPrev() {
			const list = document.querySelector(
				'.cpops-recs__list--horizontal'
			);
			if ( list ) {
				list.scrollBy( { left: -210, behavior: 'smooth' } );
			}
		},

		scrollRecsNext() {
			const list = document.querySelector(
				'.cpops-recs__list--horizontal'
			);
			if ( list ) {
				list.scrollBy( { left: 210, behavior: 'smooth' } );
			}
		},

		/** Internal edition-neutral service actions for an optional module. */
		canMutateCart() {
			return cartMutationAllowed();
		},

		enqueueCartMutation( operation ) {
			if ( typeof operation !== 'function' ) {
				return Promise.resolve( false );
			}
			const queued = cartQueue
				.catch( () => {} )
				.then( () => operation() );
			cartQueue = queued.catch( () => {} );
			return queued;
		},

		acceptLightCartResponse( data ) {
			return completeOwnedCartMutation(
				processLightCartResponse( data )
			);
		},

		/**
		 * Accept the Store cart an optional module's own write returned. Its
		 * supplementary data (meter, notifications, add-ons) is refreshed by
		 * the read that follows the published fragment refresh.
		 *
		 * @param {*} data Store API cart response.
		 * @return {boolean} Whether the cart was accepted.
		 */
		acceptStoreCartResponse( data ) {
			return completeOwnedCartMutation( processCartResponse( data ), {
				completeState: false,
			} );
		},

		/**
		 * Apply a Store cart read; a read changes nothing to publish.
		 *
		 * @param {*} data Store API cart response.
		 * @return {boolean} Whether the cart was accepted.
		 */
		acceptStoreCartRead( data ) {
			return processCartResponse( data ) === true;
		},

		synchronizeConfirmedCartMutation() {
			return completeOwnedCartMutation( true );
		},

		refreshSupplementaryData( reason = 'extension-change' ) {
			return runSupplementaryFetches( reason );
		},

		announceCustomerFailure( messageKey = 'requestFailed' ) {
			return exposeCustomerFailure( messageKey );
		},

		refreshFocusLayer( returnFocus = null ) {
			scheduleDrawerFocus( returnFocus );
		},

		suppressNextFallbackClose() {
			suppressNextFallbackDrawerClose = true;
			setTimeout( () => {
				suppressNextFallbackDrawerClose = false;
			}, 0 );
		},
	},
} );

if ( Array.isArray( state.cartItems ) ) {
	state.cartItems = state.cartItems.map( ( item ) =>
		materializeCartItemImage( item, state.config?.placeholderImageUrl )
	);
}
const hasHydratedCart = initializeSharedCartDrawerState( state );

// Full-page caching can serve stale wp_interactivity_state(). Reconcile it
// with WooCommerce's cart-count cookie, but only an authoritative response may
// erase hydrated cart data when the cookie is absent or malformed.
const initialSynchronizationState = synchronizeInitialCart( {
	cookieHeader: document.cookie,
	state,
	hasHydratedCart,
	forceRefresh: state.config?.forceFragmentsRefresh === true,
	requestCart: () => store( 'cartpops' ).actions.fetchCart(),
	markFresh: () => {
		lastCartUpdate = Date.now();
	},
} );

if ( typeof installCartHydrationAccessibility === 'function' ) {
	hydrationAccessibility = installCartHydrationAccessibility( {
		isPending: () => ! isCartHydrationTrusted( state ),
		isOpen: () => Boolean( state.isOpen ),
		activateDrawer: ( drawer ) => activateDrawerFocus( drawer ),
		getLiveMessage: () => state.liveMessage,
		closeDrawer: () => {
			if ( suppressNextFallbackDrawerClose ) {
				suppressNextFallbackDrawerClose = false;
				return;
			}
			store( 'cartpops' ).actions.closeDrawer();
		},
	} );
}

if ( initialSynchronizationState === 'refreshing' ) {
	preserveServerEachChildren();
} else {
	rememberTrustedCartPresentation();
	// WordPress before 6.9 does not safely discard the SSR clones generated for
	// data-wp-each. Trusted state can recreate them from the server payload.
	removeLegacyServerEachChildren( {
		enabled: requiresLegacyEachAdapter( state.wpVersion ),
	} );
}
releaseLegacyHydrationGuards();

/**
 * Focus a control once the directive pass that reveals it has rendered.
 *
 * @param {HTMLElement|null|undefined} element    Control to focus.
 * @param {number}                     framesLeft Remaining frames to wait.
 */
function focusWhenRendered( element, framesLeft = 3 ) {
	if ( typeof element?.focus !== 'function' ) {
		return;
	}
	if (
		framesLeft <= 0 ||
		typeof requestAnimationFrame !== 'function' ||
		element.getClientRects?.().length > 0
	) {
		element.focus();
		return;
	}
	requestAnimationFrame( () => focusWhenRendered( element, framesLeft - 1 ) );
}

/**
 * Strip HTML tags from a string.
 * @param {string} html
 */
function stripTags( html ) {
	return html ? html.replace( /<[^>]*>/g, '' ) : '';
}

/**
 * Dispatch cart count update for external integrations (e.g. Blocksy header badge).
 * @param {number} count
 */
function dispatchCountUpdate( count ) {
	document.dispatchEvent(
		new CustomEvent( 'cartpops:count-updated', { detail: { count } } )
	);
}

/**
 * Format a Store API price (in minor units) to a display string.
 * @param {number|string} minorUnits
 * @param {Object}        totals
 */
function formatPrice( minorUnits, totals ) {
	const decimals = totals?.currency_minor_unit ?? 2;
	const symbol = totals?.currency_symbol ?? '$';
	const prefix = totals?.currency_prefix || '';
	const suffix = totals?.currency_suffix || '';
	const decSep = totals?.currency_decimal_separator || '.';
	const thousandSep = totals?.currency_thousand_separator ?? '';
	const raw = (
		parseInt( minorUnits, 10 ) / Math.pow( 10, decimals )
	).toFixed( decimals );
	const [ whole, fraction ] = raw.split( '.' );
	const groupedWhole = thousandSep
		? whole.replace( /\B(?=(\d{3})+(?!\d))/g, () => thousandSep )
		: whole;
	const value =
		decimals > 0
			? `${ groupedWhole }${ decSep }${ fraction }`
			: groupedWhole;

	// If neither prefix nor suffix contains the symbol, prepend it.
	if ( ! prefix && ! suffix ) {
		return `${ symbol }\u00A0${ value }`;
	}

	return `${ prefix }${ value }${ suffix }`;
}

/**
 * Retrigger the optional generic cart-add live announcement.
 *
 * Clearing in one task and publishing in the next makes the same configured
 * message observable for sequential additions. A current cart error always
 * wins, and the generation guard prevents superseded callbacks from writing.
 */
function queueAddedToCartAnnouncement() {
	const generation = ++addedToCartAnnouncementGeneration;
	if ( addedToCartAnnouncementTimerId !== null ) {
		clearTimeout( addedToCartAnnouncementTimerId );
		addedToCartAnnouncementTimerId = null;
	}

	if ( ! state.config?.announceAddedToCart ) {
		return;
	}

	const message = i18n( 'addedToCart' );
	if ( ! message ) {
		return;
	}

	state.liveMessage = '';
	addedToCartAnnouncementTimerId = setTimeout( () => {
		if ( generation !== addedToCartAnnouncementGeneration ) {
			return;
		}

		addedToCartAnnouncementTimerId = null;
		if ( state.error || ! state.config?.announceAddedToCart ) {
			return;
		}

		state.liveMessage = message;
	}, ADDED_TO_CART_ANNOUNCEMENT_DELAY_MS );
}

/**
 * Collapse a mutation burst onto one read, while one mutation arriving after
 * that read began marks it dirty and receives exactly one trailing read.
 *
 * @return {Promise<boolean>} Whether an authoritative cart is current.
 */
function requestExternalCartRefresh() {
	if ( externalCartRefreshOwner ) {
		externalCartRefreshOwner.dirty = true;
		return externalCartRefreshOwner.promise;
	}

	let settleRefresh;
	const refreshPromise = new Promise( ( resolve ) => {
		settleRefresh = resolve;
	} );
	const refreshOwner = {
		dirty: false,
		promise: refreshPromise,
		settle: settleRefresh,
		superseded: false,
	};
	// Publish ownership before work starts. The worker clears it synchronously
	// with its final clean observation, before settling the shared promise.
	externalCartRefreshOwner = refreshOwner;
	( async () => {
		let accepted = false;
		try {
			do {
				refreshOwner.dirty = false;
				try {
					accepted = await store( 'cartpops' ).actions.fetchCart( {
						externalSource: 'cartpops-rest',
					} );
				} catch {
					accepted = false;
				}
				if ( refreshOwner.superseded ) {
					accepted = false;
					break;
				}
			} while ( refreshOwner.dirty );
			return accepted;
		} finally {
			if ( externalCartRefreshOwner === refreshOwner ) {
				externalCartRefreshOwner = null;
			}
			refreshOwner.dirty = false;
			refreshOwner.settle( refreshOwner.superseded ? false : accepted );
		}
	} )();
	return refreshOwner.promise;
}

/**
 * Complete a WooCommerce add by reconciling state and applying presentation
 * policy independently.
 *
 * @param {boolean} cartProcessed Whether event data updated CartPops state.
 * @param {*}       classicEvent  Optional classic jQuery event.
 * @param {*}       classicButton Optional classic WooCommerce button.
 */
function completeAddedToCart( cartProcessed, classicEvent, classicButton ) {
	const policy = resolveCartAddPolicy( {
		trigger: state.config?.trigger,
		cartProcessed,
		classicEvent,
		classicButton,
	} );

	// A processed cart only bypasses openDrawer's fetch when this exact event
	// immediately opens it. Never carry that bypass into a later manual open.
	skipNextFetch = policy.shouldSkipNextFetch;

	if ( policy.shouldOpenDrawer ) {
		store( 'cartpops' ).actions.openDrawer( undefined, {
			forceFetch: policy.shouldFetchCart,
		} );
	} else if ( policy.shouldFetchCart ) {
		requestExternalCartRefresh();
	}

	queueAddedToCartAnnouncement();
}

/**
 * Listen for WooCommerce Blocks add-to-cart events.
 */
function handleBlocksAddedToCart() {
	refreshExternalCartAuthority();
	runOptionalExtension( 'cart-add-detected' );

	// Reuse resolved wp.data presentation. The newer Product Button can publish
	// it after this event; the passive subscription then reconciles that update.
	const cart = readCartFromWpData();
	const cartProcessed = Boolean(
		cart && acceptExternalCart( cart, 'store-api' )
	);
	completeAddedToCart( cartProcessed );
}

/**
 * Keep shared launcher state current after a native Blocks removal without
 * opening the drawer or announcing an action performed by another control.
 */
function handleBlocksRemovedFromCart() {
	refreshExternalCartAuthority();
	const cart = readCartFromWpData();
	if ( ! cart || ! acceptExternalCart( cart, 'store-api' ) ) {
		return requestExternalCartRefresh();
	}
	return true;
}

/**
 * Apply resolved wc/store/cart changes for quantity, coupon, shipping, tax,
 * add, and removal operations. Store resolution alone does not prove that a
 * fallback request is needed when the candidate is malformed.
 *
 * @param {Object} cart Resolved Store API cart.
 */
function handleBlocksCartChanged( cart ) {
	acceptExternalCart( cart, 'store-api' );
}

/**
 * Parse CartPops' inert JSON fragment without accepting a different script or
 * ambiguous wrapper supplied under the same Woo fragment selector.
 *
 * @param {*} fragments WooCommerce fragment map.
 * @return {Object|null} Parsed lightweight cart, or null.
 */
function parseClassicCartFragment( fragments ) {
	const markup = fragments?.[ '.cartpops-cart-json' ];
	if ( typeof markup !== 'string' || markup === '' ) {
		return null;
	}

	try {
		const template = document.createElement( 'template' );
		template.innerHTML = markup;
		const elements = template.content?.children;
		if ( ! elements || elements.length !== 1 ) {
			return null;
		}
		const script = elements[ 0 ];
		if (
			! script.matches(
				'script.cartpops-cart-json[type="application/json"]'
			)
		) {
			return null;
		}
		const data = JSON.parse( script.textContent );
		return data && typeof data === 'object' && ! Array.isArray( data )
			? data
			: null;
	} catch {
		return null;
	}
}

/**
 * Accept a classic fragment response once.
 *
 * @param {*} fragments WooCommerce fragment map.
 * @return {boolean} Whether a complete cart is current.
 */
function acceptClassicCartFragments( fragments ) {
	const data = parseClassicCartFragment( fragments );
	return Boolean( data && acceptExternalCart( data, 'cartpops-rest' ) );
}

// Also listen for classic WC add-to-cart.
// Fragments avoid a separate cart projection read; the cookie authority still
// needs the coordinated Store/nonce probes before the next cart request.
function handleClassicAddedToCart( event, fragments, _cartHash, button ) {
	refreshExternalCartAuthority();
	runOptionalExtension( 'cart-add-detected' );
	const cartProcessed = acceptClassicCartFragments( fragments );
	completeAddedToCart( cartProcessed, event, button );
}

/**
 * Apply a classic mini-cart removal silently, falling back when the fragment
 * payload is absent or malformed.
 *
 * @param {*} _event    jQuery event.
 * @param {*} fragments WooCommerce fragment map.
 */
function handleClassicRemovedFromCart( _event, fragments ) {
	refreshExternalCartAuthority();
	if ( ! acceptClassicCartFragments( fragments ) ) {
		return requestExternalCartRefresh();
	}
	return true;
}

/**
 * Native and classic Woo mutations use the cookie cart, which can differ from
 * an earlier empty Store cart token. Start the handoff before any subsequent
 * request, including fallback for absent/malformed projections. Existing
 * transport waits for this singleflight refresh and never reuses failed authority.
 */
function refreshExternalCartAuthority() {
	void ensureCartToken( { force: true } ).catch( () => {
		// Keep accepted presentation; the next normal request reports failure or
		// obtains a complete fresh authority. Never restore the stale token here.
	} );
}

/** Refresh after cart-page changes and cross-tab/forced fragment refreshes. */
function handleClassicCartRefreshed() {
	return requestExternalCartRefresh();
}

// Notify an optional module when checkout begins from inside the drawer.
function handleDocumentClick( event ) {
	const link = event.target?.closest?.(
		'.cpops-btn--checkout, a[href*="checkout"]'
	);
	if ( link && link.closest( '.cpops-drawer' ) ) {
		runOptionalExtension( 'checkout-selected' );
	}
}

/**
 * Replace Woo's Mini Cart interaction only when the server-authorized mode and
 * a live CartPops drawer both make the handoff possible.
 *
 * @param {HTMLElement} trigger Native Woo Mini Cart trigger.
 * @return {boolean} Whether CartPops accepted ownership of the click.
 */
function handleMiniCartReplace( trigger ) {
	if (
		state.config?.miniCartMode !== 'replace' ||
		trigger?.nodeType !== 1 ||
		! resolveDrawer( trigger )
	) {
		return false;
	}

	try {
		const openDrawer = store( 'cartpops' )?.actions?.openDrawer;
		if ( typeof openDrawer !== 'function' ) {
			return false;
		}
		openDrawer( trigger );
		return true === state.isOpen;
	} catch ( error ) {
		return false;
	}
}

// Listen for custom events from theme integrations (e.g. Blocksy).
// Inline scripts can't access @wordpress/interactivity directly,
// so they dispatch these events and we handle them here.
try {
	globalThis[ EXTERNAL_SUPPLEMENTARY_REFRESH_CLEANUP_KEY ]?.();
	globalThis[ EXTERNAL_SUPPLEMENTARY_REFRESH_CLEANUP_KEY ] = () => {
		cancelExternalSupplementaryRefresh();
		suppGeneration += 1;
	};
} catch {
	// A locked global can only disable hot-replacement cleanup, not runtime use.
}
// The bridge attaches its jQuery listeners only when jQuery is already here.
const bridgeHearsJquery = typeof globalThis.jQuery === 'function';
installCartDrawerEventBridge( {
	onBlocksAdded: handleBlocksAddedToCart,
	onBlocksRemoved: handleBlocksRemovedFromCart,
	onBlocksCartChanged: handleBlocksCartChanged,
	onClassicAdded: handleClassicAddedToCart,
	onClassicRemoved: handleClassicRemovedFromCart,
	onClassicRefreshed: handleClassicCartRefreshed,
	onMiniCartReplace: handleMiniCartReplace,
	onDocumentClick: handleDocumentClick,
	onOpen: () => store( 'cartpops' ).actions.openDrawer(),
	onClose: () => store( 'cartpops' ).actions.closeDrawer(),
	onToggle: () => store( 'cartpops' ).actions.toggleDrawer(),
} );

// V1 themes and snippets call window.CartPops.drawer.show(); keep them working.
installLegacyCartPopsGlobal();

// Classic single-product forms add in the background when the store enables
// it. A confirmed add re-enters the classic path above through Woo's jQuery
// `added_to_cart` event, or directly when the bridge cannot hear jQuery.
installProductFormAddInterceptor( {
	getConfig: () => state.config,
	willOpenDrawer: ( origin ) =>
		resolveCartAddPolicy( {
			trigger: state.config?.trigger,
			classicButton: origin,
		} ).shouldOpenDrawer,
	onAdded: ( fragments, cartHash, origin ) =>
		handleClassicAddedToCart( undefined, fragments, cartHash, origin ),
	bridgeHearsJquery,
} );
