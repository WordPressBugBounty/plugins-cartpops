/**
 * CartPops Cart Launcher — Shares store with Cart Drawer.
 *
 * The launcher uses the same 'cartpops' store defined in the Cart Drawer.
 * No additional store registration needed — the Interactivity API merges stores.
 * Cart data freshness is handled by the drawer's stale cache detection.
 *
 * @package
 */

import { store } from '@wordpress/interactivity';

store( 'cartpops' );

const BADGE_POP_CLASS = 'cpops-launcher__badge--pop';

/**
 * Replay the badge entrance only when its rendered count changes.
 *
 * The count is bound with data-wp-text, so hydration may rewrite the same text;
 * comparing against the last seen value keeps page loads free of animation.
 *
 * @param {HTMLElement} badge Launcher count badge.
 */
function animateBadgeOnCountChange( badge ) {
	let count = badge.textContent;
	new window.MutationObserver( () => {
		if ( badge.textContent === count ) {
			return;
		}
		count = badge.textContent;
		badge.classList.remove( BADGE_POP_CLASS );
		// Reading layout restarts the animation when counts change in quick succession.
		void badge.offsetWidth;
		badge.classList.add( BADGE_POP_CLASS );
	} ).observe( badge, {
		childList: true,
		characterData: true,
		subtree: true,
	} );
	badge.addEventListener( 'animationend', () =>
		badge.classList.remove( BADGE_POP_CLASS )
	);
}

if ( typeof window !== 'undefined' && 'MutationObserver' in window ) {
	document
		.querySelectorAll( '.cpops-launcher__badge' )
		.forEach( animateBadgeOnCountChange );
}
