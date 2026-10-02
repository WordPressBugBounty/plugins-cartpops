/**
 * Determine whether WordPress core lacks safe `data-wp-each-child` hydration.
 *
 * The client VDOM guard landed in WordPress 6.9. Unparseable versions take the
 * conservative compatibility path, which is safe and idempotent.
 *
 * @param {string} version WordPress version string.
 * @return {boolean} Whether the pre-6.9 adapter is required.
 */
export function requiresLegacyEachAdapter( version ) {
	const match = String( version || '' ).match( /^(\d+)\.(\d+)/ );
	if ( ! match ) {
		return true;
	}

	const major = Number( match[ 1 ] );
	const minor = Number( match[ 2 ] );
	return major < 6 || ( major === 6 && minor < 9 );
}

/**
 * Release only CartPops' pre-6.9 SSR guard after its clone adapter has run.
 *
 * Without the drawer module, older core must not walk contextless SSR clones.
 * The server-owned aside stays intact under data-wp-ignore until this runtime
 * can prepare its rows. Unmarked third-party ignore regions remain untouched.
 *
 * @param {Object}   options
 * @param {Document} options.documentScope Document containing CartPops islands.
 * @return {number} Number of owned guards released.
 */
export function releaseLegacyHydrationGuards( {
	documentScope = globalThis.document,
} = {} ) {
	if ( ! documentScope?.querySelectorAll ) {
		return 0;
	}

	let released = 0;
	for ( const drawer of documentScope.querySelectorAll(
		'aside.cpops-drawer[data-cartpops-legacy-hydration-guard="true"]'
	) ) {
		if (
			drawer
				.closest( '[data-wp-interactive]' )
				?.getAttribute( 'data-wp-interactive' ) !== 'cartpops' ||
			drawer.getAttribute( 'data-wp-ignore' ) !== ''
		) {
			continue;
		}
		drawer.removeAttribute( 'data-wp-ignore' );
		drawer.removeAttribute( 'data-cartpops-legacy-hydration-guard' );
		released++;
	}
	return released;
}

/**
 * Remove pre-6.9 server-rendered `data-wp-each-child` clones before client
 * hydration.
 *
 * Older WordPress server processors emit those clones, while their client VDOM
 * still walks their directives without the item context and then discards
 * them. Removing only the clones lets the documented `data-wp-each` template
 * recreate the same items from Interactivity state. With JavaScript disabled
 * this function never runs, so the progressively enhanced SSR markup remains.
 *
 * @param {Object}   options
 * @param {boolean}  options.enabled       Whether the pre-6.9 adapter is required.
 * @param {Document} options.documentScope Document containing CartPops islands.
 * @return {number} Number of server-rendered clones removed.
 */
export function removeLegacyServerEachChildren( {
	enabled,
	documentScope = globalThis.document,
} = {} ) {
	if ( ! enabled || ! documentScope?.querySelectorAll ) {
		return 0;
	}

	let removed = 0;
	const islands = documentScope.querySelectorAll(
		'[data-wp-interactive="cartpops"]'
	);

	for ( const island of islands ) {
		for ( const child of island.querySelectorAll(
			'[data-wp-each-child]'
		) ) {
			child.remove();
			removed++;
		}
	}

	return removed;
}

const preservedInteractionEvents = [
	'auxclick',
	'beforeinput',
	'change',
	'click',
	'dblclick',
	'focus',
	'input',
	'keydown',
	'keypress',
	'keyup',
	'mousedown',
	'pointerdown',
	'submit',
	'touchstart',
];

const cartMutationControlSelector = '[data-cpops-cart-mutation]';

const cartMutationInteractionEvents = [
	'change',
	'click',
	'input',
	'keydown',
	'keypress',
	'keyup',
	'submit',
];

const HYDRATION_ACCESSIBILITY_KEY = Symbol.for(
	'cartpops.cartDrawerHydrationAccessibility'
);
const CARTPOPS_ISLAND_SELECTOR = '[data-wp-interactive="cartpops"]';
const CARTPOPS_LIVE_REGION_SELECTOR = '[data-cartpops-live-region]';

/**
 * Whether an element can invoke a CartPops cart mutation.
 *
 * The edition-neutral marker lets shared hydration safety cover optional
 * controls without naming their actions, selectors, or product concepts.
 *
 * @param {Element} element Candidate control.
 * @return {boolean} Whether the element must be disabled while revalidating.
 */
function isCartMutationControl( element ) {
	return element.matches( cartMutationControlSelector );
}

/**
 * Preserve one attribute exactly for later restoration.
 *
 * @param {Element} element       Element containing the attribute.
 * @param {string}  attributeName Attribute to preserve.
 * @return {{present: boolean, value: string|null}} Attribute state.
 */
function captureAttribute( element, attributeName ) {
	return {
		present: element.hasAttribute( attributeName ),
		value: element.getAttribute( attributeName ),
	};
}

/**
 * Restore one previously captured attribute.
 *
 * @param {Element} element        Target element.
 * @param {string}  attributeName  Attribute to restore.
 * @param {Object}  attributeState Captured state.
 * @return {void}
 */
function restoreAttribute( element, attributeName, attributeState ) {
	if ( attributeState.present ) {
		element.setAttribute( attributeName, attributeState.value ?? '' );
	} else {
		element.removeAttribute( attributeName );
	}
}

/**
 * Toggle a class only when its semantic state actually changes.
 *
 * Mutation observers watch the drawer classes, so idempotent writes must not
 * schedule another reconciliation turn.
 *
 * @param {Element} element   Target element.
 * @param {string}  className Class to update.
 * @param {boolean} enabled   Whether the class must be present.
 * @return {void}
 */
function setClassState( element, className, enabled ) {
	if ( element.classList.contains( className ) !== enabled ) {
		element.classList.toggle( className, enabled );
	}
}

/**
 * Keep retained cart presentation safe and accessible during revalidation.
 *
 * The returned controller must be updated after hydration state transitions.
 * A child-list observer applies the same state to rows added by an
 * Interactivity API reconciliation without depending on a particular core
 * version's directive timing.
 *
 * @param {Object}   options
 * @param {Object}   options.globalScope    Shared module global.
 * @param {Document} options.documentScope  CartPops document.
 * @param {Function} options.isPending      Read current hydration status.
 * @param {Function} options.isOpen         Read current drawer visibility.
 * @param {Function} options.activateDrawer Retarget the active focus trap.
 * @param {Function} options.closeDrawer    Close the current drawer.
 * @param {Function} options.getLiveMessage Read the CartPops-owned status.
 * @return {{update: Function, disconnect: Function}} Accessibility controller.
 */
export function installCartHydrationAccessibility( {
	globalScope = globalThis,
	documentScope = globalScope?.document,
	isPending = () => false,
	isOpen = null,
	activateDrawer = () => {},
	closeDrawer = null,
	getLiveMessage = null,
} = {} ) {
	globalScope?.[ HYDRATION_ACCESSIBILITY_KEY ]?.disconnect?.();

	const controlStates = new Map();
	const drawerStates = new Map();
	const closeStates = new Map();
	const openIslandStates = new Map();
	const openOverlayStates = new Map();
	const openDrawerStates = new Map();
	let pending = false;
	let activeDrawer = null;
	let managedOpenCycle = false;
	let active = true;
	let controller = null;
	let updateScheduled = false;
	const scopedObservers = new Map();

	const findMutationControl = ( target ) => {
		let element = target?.nodeType === 1 ? target : target?.parentElement;
		const drawer = element?.closest?.( '.cpops-drawer' );
		while ( element && drawer?.contains( element ) ) {
			if ( isCartMutationControl( element ) ) {
				return element;
			}
			if ( element === drawer ) {
				break;
			}
			element = element.parentElement;
		}
		return null;
	};

	const blockMutation = ( event ) => {
		if ( event.type === 'keydown' && event.key === 'Escape' ) {
			return;
		}
		if ( ! pending || ! findMutationControl( event.target ) ) {
			return;
		}
		event.preventDefault();
		event.stopImmediatePropagation();
	};

	for ( const eventName of cartMutationInteractionEvents ) {
		documentScope?.addEventListener?.( eventName, blockMutation, true );
	}

	const closeIfStillOpen = () => {
		if (
			! active ||
			typeof closeDrawer !== 'function' ||
			typeof isOpen !== 'function' ||
			! isOpen()
		) {
			return;
		}
		closeDrawer();
		controller?.update?.();
	};

	const dismissDrawer = ( event ) => {
		if (
			! active ||
			typeof closeDrawer !== 'function' ||
			typeof isOpen !== 'function' ||
			! isOpen()
		) {
			return;
		}

		const isEscape = event.type === 'keydown' && event.key === 'Escape';
		const close =
			event.type === 'click'
				? event.target?.closest?.( '.cpops-drawer__close' )
				: null;
		const isClose = Boolean(
			close?.closest?.( '[data-wp-interactive="cartpops"]' )
		);
		if ( ! isEscape && ! isClose ) {
			return;
		}

		// Let a hydrated Interactivity directive handle the event first. If a
		// replacement island has not been bound yet, the registered controller
		// closes it on the next microtask without installing a duplicate action.
		Promise.resolve().then( () => {
			const registered = globalScope?.[ HYDRATION_ACCESSIBILITY_KEY ];
			registered?.closeIfStillOpen?.();
		} );
	};
	documentScope?.addEventListener?.( 'click', dismissDrawer );
	documentScope?.addEventListener?.( 'keydown', dismissDrawer );

	const disableControl = ( element ) => {
		if ( ! controlStates.has( element ) ) {
			controlStates.set( element, {
				ariaDisabled: captureAttribute( element, 'aria-disabled' ),
				disabled:
					'disabled' in element ? Boolean( element.disabled ) : null,
				tabindex: captureAttribute( element, 'tabindex' ),
			} );
		}

		if ( 'disabled' in element ) {
			if ( ! element.disabled ) {
				element.disabled = true;
			}
		} else if ( element.getAttribute( 'tabindex' ) !== '-1' ) {
			element.setAttribute( 'tabindex', '-1' );
		}
		if ( element.getAttribute( 'aria-disabled' ) !== 'true' ) {
			element.setAttribute( 'aria-disabled', 'true' );
		}
	};

	const restoreControl = ( element, original ) => {
		if ( original.disabled !== null ) {
			element.disabled = original.disabled;
		}
		restoreAttribute( element, 'aria-disabled', original.ariaDisabled );
		restoreAttribute( element, 'tabindex', original.tabindex );
	};

	const restoreControls = () => {
		for ( const [ element, original ] of controlStates ) {
			restoreControl( element, original );
		}
		controlStates.clear();
	};

	const isAttached = ( element ) =>
		Boolean( documentScope?.documentElement?.contains?.( element ) );

	const restoreDetachedState = () => {
		for ( const [ element, original ] of controlStates ) {
			if ( ! isAttached( element ) ) {
				restoreControl( element, original );
				controlStates.delete( element );
			}
		}
		for ( const [ drawer, original ] of drawerStates ) {
			if ( ! isAttached( drawer ) ) {
				restoreAttribute( drawer, 'aria-busy', original );
				drawerStates.delete( drawer );
			}
		}
		for ( const [ close, pointerEvents ] of closeStates ) {
			if ( ! isAttached( close ) ) {
				close.style.pointerEvents = pointerEvents;
				closeStates.delete( close );
			}
		}
		for ( const [ island, wasOpen ] of openIslandStates ) {
			if ( ! isAttached( island ) ) {
				setClassState( island, 'cpops-drawer-open', wasOpen );
				openIslandStates.delete( island );
			}
		}
		for ( const [ overlay, wasVisible ] of openOverlayStates ) {
			if ( ! isAttached( overlay ) ) {
				setClassState( overlay, 'cpops-overlay-visible', wasVisible );
				openOverlayStates.delete( overlay );
			}
		}
		for ( const [ drawer, original ] of openDrawerStates ) {
			if ( ! isAttached( drawer ) ) {
				setClassState( drawer, 'cpops-drawer-open', original.open );
				restoreAttribute( drawer, 'aria-hidden', original.ariaHidden );
				restoreAttribute( drawer, 'inert', original.inert );
				openDrawerStates.delete( drawer );
			}
		}
	};

	const applyOpenState = ( islands ) => {
		if ( typeof isOpen !== 'function' ) {
			return;
		}

		const open = Boolean( isOpen() );
		if ( open ) {
			managedOpenCycle = true;
		} else if ( ! managedOpenCycle ) {
			return;
		}
		let replacementDrawer = null;
		for ( const island of islands ) {
			const drawers = island.querySelectorAll( '.cpops-drawer' );
			if ( drawers.length === 0 ) {
				continue;
			}
			if ( ! openIslandStates.has( island ) ) {
				openIslandStates.set(
					island,
					island.classList.contains( 'cpops-drawer-open' )
				);
			}
			setClassState( island, 'cpops-drawer-open', open );

			for ( const overlay of island.querySelectorAll(
				'.cpops-overlay'
			) ) {
				if ( ! openOverlayStates.has( overlay ) ) {
					openOverlayStates.set(
						overlay,
						overlay.classList.contains( 'cpops-overlay-visible' )
					);
				}
				setClassState( overlay, 'cpops-overlay-visible', open );
			}

			for ( const drawer of drawers ) {
				if ( ! openDrawerStates.has( drawer ) ) {
					openDrawerStates.set( drawer, {
						open: drawer.classList.contains( 'cpops-drawer-open' ),
						ariaHidden: captureAttribute( drawer, 'aria-hidden' ),
						inert: captureAttribute( drawer, 'inert' ),
					} );
				}
				setClassState( drawer, 'cpops-drawer-open', open );
				const ariaHidden = open ? 'false' : 'true';
				if ( drawer.getAttribute( 'aria-hidden' ) !== ariaHidden ) {
					drawer.setAttribute( 'aria-hidden', ariaHidden );
				}
				if ( open ) {
					if ( drawer.hasAttribute( 'inert' ) ) {
						drawer.removeAttribute( 'inert' );
					}
					replacementDrawer ||= drawer;
				} else if ( ! drawer.hasAttribute( 'inert' ) ) {
					drawer.setAttribute( 'inert', '' );
				}
			}
		}

		if ( open && replacementDrawer !== activeDrawer ) {
			activeDrawer = replacementDrawer;
			activateDrawer( replacementDrawer );
		} else if ( ! open && activeDrawer ) {
			activeDrawer = null;
			activateDrawer( null );
		}
	};

	const restoreOpenState = () => {
		if ( activeDrawer ) {
			activeDrawer = null;
			activateDrawer( null );
		}
		for ( const [ island, wasOpen ] of openIslandStates ) {
			setClassState( island, 'cpops-drawer-open', wasOpen );
		}
		openIslandStates.clear();
		for ( const [ overlay, wasVisible ] of openOverlayStates ) {
			setClassState( overlay, 'cpops-overlay-visible', wasVisible );
		}
		openOverlayStates.clear();
		for ( const [ drawer, original ] of openDrawerStates ) {
			setClassState( drawer, 'cpops-drawer-open', original.open );
			restoreAttribute( drawer, 'aria-hidden', original.ariaHidden );
			restoreAttribute( drawer, 'inert', original.inert );
		}
		openDrawerStates.clear();
	};

	const applyPendingState = ( islands ) => {
		restoreDetachedState();
		for ( const island of islands ) {
			for ( const drawer of island.querySelectorAll( '.cpops-drawer' ) ) {
				if ( ! drawerStates.has( drawer ) ) {
					drawerStates.set(
						drawer,
						captureAttribute( drawer, 'aria-busy' )
					);
				}
				if ( drawer.getAttribute( 'aria-busy' ) !== 'true' ) {
					drawer.setAttribute( 'aria-busy', 'true' );
				}

				for ( const control of drawer.querySelectorAll( '*' ) ) {
					if ( isCartMutationControl( control ) ) {
						disableControl( control );
					}
				}

				for ( const close of drawer.querySelectorAll(
					'.cpops-drawer__close'
				) ) {
					if ( ! closeStates.has( close ) ) {
						closeStates.set( close, close.style.pointerEvents );
					}
					if ( close.style.pointerEvents !== 'auto' ) {
						close.style.pointerEvents = 'auto';
					}
				}
			}
		}
	};

	const restorePendingState = () => {
		restoreControls();
		for ( const [ drawer, original ] of drawerStates ) {
			restoreAttribute( drawer, 'aria-busy', original );
		}
		drawerStates.clear();

		for ( const [ close, pointerEvents ] of closeStates ) {
			close.style.pointerEvents = pointerEvents;
		}
		closeStates.clear();
	};

	// A freshly cloned WooCommerce fragment is observable before core binds its
	// data-wp-text directive. Synchronize only CartPops' dedicated node so the
	// customer hears current state without touching extension or theme regions.
	const synchronizeLiveRegion = ( islands ) => {
		if ( typeof getLiveMessage !== 'function' ) {
			return;
		}

		const activeIsland =
			activeDrawer?.closest?.( CARTPOPS_ISLAND_SELECTOR ) ||
			islands.find( ( island ) =>
				island.querySelector( '.cpops-drawer' )
			);
		const liveRegion = activeIsland?.querySelector?.(
			CARTPOPS_LIVE_REGION_SELECTOR
		);
		if ( ! liveRegion ) {
			return;
		}

		const message = String( getLiveMessage() || '' );
		const textNode = [ ...liveRegion.childNodes ].find(
			( child ) => child.nodeType === 3
		);
		if ( textNode ) {
			if ( textNode.data !== message ) {
				textNode.data = message;
			}
			return;
		}

		const appendedTextNode =
			liveRegion.ownerDocument?.createTextNode?.( message );
		if ( appendedTextNode ) {
			liveRegion.appendChild( appendedTextNode );
		}
	};

	const Observer = documentScope?.defaultView?.MutationObserver;
	const observeIsland = ( island, observer ) => {
		observer.observe( island, {
			attributes: true,
			childList: true,
			characterData: true,
			subtree: true,
		} );
	};
	const scheduleUpdate = () => {
		if ( updateScheduled || ! active ) {
			return;
		}
		updateScheduled = true;
		Promise.resolve().then( () => {
			updateScheduled = false;
			controller?.update?.();
		} );
	};
	const synchronizeScopedObservers = ( islands ) => {
		if ( ! Observer ) {
			return;
		}
		const attached = new Set( islands );
		for ( const [ island, observer ] of scopedObservers ) {
			observer.disconnect();
			if ( ! attached.has( island ) ) {
				scopedObservers.delete( island );
			}
		}
		for ( const island of islands ) {
			let scopedObserver = scopedObservers.get( island );
			if ( ! scopedObserver ) {
				scopedObserver = new Observer( () => scheduleUpdate() );
				scopedObservers.set( island, scopedObserver );
			}
			observeIsland( island, scopedObserver );
		}
	};
	const update = () => {
		const registered = globalScope?.[ HYDRATION_ACCESSIBILITY_KEY ];
		if ( controller && registered && registered !== controller ) {
			registered.update?.();
			return;
		}
		if ( ! active ) {
			return;
		}
		for ( const observer of scopedObservers.values() ) {
			observer.disconnect();
		}
		const islands = [
			...( documentScope?.querySelectorAll?.(
				CARTPOPS_ISLAND_SELECTOR
			) || [] ),
		];
		pending = Boolean( isPending() );
		restoreDetachedState();
		applyOpenState( islands );
		if ( pending ) {
			applyPendingState( islands );
		} else {
			restorePendingState();
		}
		synchronizeLiveRegion( islands );
		synchronizeScopedObservers( islands );
	};

	const containsCartPopsIsland = ( node ) =>
		node?.nodeType === 1 &&
		( node.matches?.( CARTPOPS_ISLAND_SELECTOR ) ||
			node.querySelector?.( CARTPOPS_ISLAND_SELECTOR ) );
	const discoveryObserver = Observer
		? new Observer( ( records ) => {
				if (
					records.some( ( record ) =>
						[ ...record.addedNodes, ...record.removedNodes ].some(
							containsCartPopsIsland
						)
					)
				) {
					scheduleUpdate();
				}
		  } )
		: null;
	const observationRoot =
		documentScope?.body || documentScope?.documentElement || documentScope;
	if ( observationRoot ) {
		discoveryObserver?.observe( observationRoot, {
			childList: true,
			subtree: true,
		} );
	}

	controller = {
		closeIfStillOpen,
		update,
		disconnect() {
			if ( ! active ) {
				return;
			}
			active = false;
			pending = false;
			restorePendingState();
			restoreOpenState();
			discoveryObserver?.disconnect();
			for ( const observer of scopedObservers.values() ) {
				observer.disconnect();
			}
			scopedObservers.clear();
			for ( const eventName of cartMutationInteractionEvents ) {
				documentScope?.removeEventListener?.(
					eventName,
					blockMutation,
					true
				);
			}
			documentScope?.removeEventListener?.( 'click', dismissDrawer );
			documentScope?.removeEventListener?.( 'keydown', dismissDrawer );
			if ( globalScope?.[ HYDRATION_ACCESSIBILITY_KEY ] === controller ) {
				delete globalScope[ HYDRATION_ACCESSIBILITY_KEY ];
			}
		},
	};
	Object.defineProperty( globalScope, HYDRATION_ACCESSIBILITY_KEY, {
		configurable: true,
		value: controller,
	} );
	update();
	return controller;
}

/**
 * Make a preserved server row inert even when the browser lacks `inert`.
 *
 * The row is discarded after authority is established, so interactive
 * attributes do not need to be restored. Capture listeners also protect old
 * browsers and programmatic activation while the snapshot remains visible.
 *
 * @param {Element} child Preserved server-rendered cart row.
 * @return {void}
 */
function makePreservedRowInert( child ) {
	child.setAttribute( 'inert', '' );
	child.setAttribute( 'aria-hidden', 'true' );

	const interactiveSelector =
		'a[href], button, input, select, textarea, [tabindex], [role], [contenteditable]';
	for ( const element of [ child, ...child.querySelectorAll( '*' ) ] ) {
		if ( ! element.matches( interactiveSelector ) ) {
			continue;
		}
		if ( element.matches( 'button, input, select, textarea' ) ) {
			element.disabled = true;
		}

		if ( element.hasAttribute( 'href' ) ) {
			element.removeAttribute( 'href' );
		}
		if ( element.hasAttribute( 'contenteditable' ) ) {
			element.setAttribute( 'contenteditable', 'false' );
		}

		element.setAttribute( 'aria-disabled', 'true' );
		element.setAttribute( 'tabindex', '-1' );
	}

	const blockInteraction = ( event ) => {
		event.preventDefault();
		event.stopImmediatePropagation();
		if ( event.type === 'focus' && event.target?.blur ) {
			event.target.blur();
		}
	};

	for ( const eventName of preservedInteractionEvents ) {
		child.addEventListener( eventName, blockInteraction, true );
	}
}

/**
 * Preserve server-rendered cart rows while their client identity is untrusted.
 *
 * WordPress may otherwise reconcile `data-wp-each-child` nodes against an
 * unsafe or stale state value before CartPops can obtain cart authority. The
 * rows remain visible, but all Interactivity directives are removed so they
 * are inert snapshots rather than mutation-capable client state.
 *
 * @param {Object}   options
 * @param {Document} options.documentScope Document containing CartPops islands.
 * @return {number} Number of server rows preserved.
 */
export function preserveServerEachChildren( {
	documentScope = globalThis.document,
} = {} ) {
	if ( ! documentScope?.querySelectorAll ) {
		return 0;
	}

	let preserved = 0;
	for ( const island of documentScope.querySelectorAll(
		'[data-wp-interactive="cartpops"]'
	) ) {
		for ( const child of island.querySelectorAll(
			'.cpops-cart-item[data-wp-each-child]'
		) ) {
			child.setAttribute( 'data-cartpops-ssr-preserved', 'true' );
			makePreservedRowInert( child );
			for ( const element of [
				child,
				...child.querySelectorAll( '*' ),
			] ) {
				for ( const attribute of [ ...element.attributes ] ) {
					if ( attribute.name.startsWith( 'data-wp-' ) ) {
						element.removeAttribute( attribute.name );
					}
				}
			}
			preserved++;
		}
	}

	return preserved;
}

/**
 * Remove inert SSR rows after one authoritative cart response is accepted.
 *
 * @param {Object}   options
 * @param {Document} options.documentScope Document containing CartPops islands.
 * @return {number} Number of preserved rows removed.
 */
export function removePreservedServerEachChildren( {
	documentScope = globalThis.document,
} = {} ) {
	if ( ! documentScope?.querySelectorAll ) {
		return 0;
	}

	const children = documentScope.querySelectorAll(
		'[data-wp-interactive="cartpops"] [data-cartpops-ssr-preserved="true"]'
	);
	for ( const child of children ) {
		child.remove();
	}
	return children.length;
}
