/**
 * CartPops Cart Drawer — focus trap.
 *
 * @package
 */

/**
 * Trap focus within an element.
 *
 * @param {HTMLElement} element Element to trap focus within.
 * @return {Function} Cleanup function that removes listeners/observers.
 */
export function trapFocus( element ) {
	const focusableSelectors =
		'button, a[href], input:not([type="hidden"]), select, textarea, summary, [contenteditable="true"], [tabindex]:not([tabindex="-1"])';
	let cachedFocusable = null;
	let cacheTimer = null;

	/**
	 * Whether a candidate participates in the active layer's tab order.
	 *
	 * @param {HTMLElement} candidate Candidate interactive element.
	 * @return {boolean} Whether the element can receive focus.
	 */
	function isFocusable( candidate ) {
		if (
			candidate.disabled ||
			candidate.getAttribute( 'aria-disabled' ) === 'true' ||
			candidate.closest( '[hidden], [inert], [aria-hidden="true"]' ) ||
			candidate.closest( '.cpops-hidden' )
		) {
			return false;
		}

		const view = candidate.ownerDocument?.defaultView;
		const style = view?.getComputedStyle?.( candidate );
		return (
			! style ||
			( style.display !== 'none' && style.visibility !== 'hidden' )
		);
	}

	function getFocusable() {
		if ( ! cachedFocusable ) {
			cachedFocusable = [
				...element.querySelectorAll( focusableSelectors ),
			].filter( isFocusable );
		}
		return cachedFocusable;
	}

	function invalidateCache() {
		cachedFocusable = null;
		if ( cacheTimer ) {
			clearTimeout( cacheTimer );
		}
		cacheTimer = setTimeout( () => {
			cachedFocusable = null;
		}, 200 );
	}

	const observer = new MutationObserver( invalidateCache );
	observer.observe( element, {
		childList: true,
		subtree: true,
		attributes: true,
		attributeFilter: [
			'aria-disabled',
			'aria-hidden',
			'class',
			'disabled',
			'hidden',
			'inert',
			'style',
			'tabindex',
		],
	} );

	function handleTab( e ) {
		if ( e.key !== 'Tab' ) {
			return;
		}

		const focusable = getFocusable();
		if ( focusable.length === 0 ) {
			return;
		}

		const first = focusable[ 0 ];
		const last = focusable[ focusable.length - 1 ];
		const activeElement = element.ownerDocument.activeElement;

		if (
			! element.contains( activeElement ) ||
			! focusable.includes( activeElement )
		) {
			e.preventDefault();
			( e.shiftKey ? last : first ).focus();
		} else if ( e.shiftKey && activeElement === first ) {
			e.preventDefault();
			last.focus();
		} else if ( ! e.shiftKey && activeElement === last ) {
			e.preventDefault();
			first.focus();
		}
	}

	const ownerDocument = element.ownerDocument;
	ownerDocument.addEventListener( 'keydown', handleTab, true );

	// Each active layer declares its preferred entry point. Fall back to the
	// first available control so every dialog receives focus on activation.
	const initialFocus = element.querySelector(
		'[data-cartpops-dialog-initial-focus]'
	);
	const focusTarget =
		initialFocus && isFocusable( initialFocus )
			? initialFocus
			: getFocusable()[ 0 ];
	if ( focusTarget ) {
		focusTarget.focus();
	}

	return () => {
		ownerDocument.removeEventListener( 'keydown', handleTab, true );
		observer.disconnect();
		if ( cacheTimer ) {
			clearTimeout( cacheTimer );
		}
	};
}
