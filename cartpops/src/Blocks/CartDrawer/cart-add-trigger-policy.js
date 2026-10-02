/**
 * Pure presentation and reconciliation policy for successful WooCommerce adds.
 *
 * @package
 */

const AUTO_OPEN_TRIGGERS = new Set( [ 'add_to_cart' ] );

/**
 * Whether a configured trigger opens the CartPops drawer after a cart add.
 *
 * An absent value preserves the pre-policy behavior until every renderer
 * publishes the setting. Present but malformed values fail closed.
 *
 * @param {*} trigger Configured trigger value.
 * @return {boolean} Whether the trigger permits automatic opening.
 */
export function triggerAutoOpensDrawer( trigger ) {
	return trigger === undefined || AUTO_OPEN_TRIGGERS.has( trigger );
}

/**
 * Whether an element carries V1's exact per-button auto-open opt-out.
 *
 * @param {*} candidate Candidate DOM element.
 * @return {boolean} Whether the exact opt-out attribute is present.
 */
function hasExactClassicOptOut( candidate ) {
	try {
		return (
			typeof candidate?.getAttribute === 'function' &&
			candidate.getAttribute( 'data-cpops-cart-open' ) === 'false'
		);
	} catch {
		return false;
	}
}

/**
 * Find V1's classic-button opt-out across WooCommerce/jQuery event shapes.
 *
 * @param {*} classicEvent  WooCommerce's jQuery event argument.
 * @param {*} classicButton WooCommerce's button or jQuery button argument.
 * @return {boolean} Whether any supported event origin explicitly opts out.
 */
export function classicAddOptsOutOfAutoOpen( classicEvent, classicButton ) {
	const candidates = [ classicButton ];

	try {
		if ( typeof classicButton?.get === 'function' ) {
			candidates.push( classicButton.get( 0 ) );
		}
	} catch {
		// Keep inspecting the remaining standard WooCommerce event origins.
	}

	candidates.push(
		classicButton?.[ 0 ],
		classicEvent?.target,
		classicEvent?.currentTarget
	);

	return candidates.some( hasExactClassicOptOut );
}

/**
 * Resolve the complete post-add policy without reading browser globals.
 *
 * @param {Object}  options
 * @param {*}       options.trigger       Configured trigger value.
 * @param {boolean} options.cartProcessed Whether Woo cart state was accepted.
 * @param {*}       options.classicEvent  Optional classic jQuery event.
 * @param {*}       options.classicButton Optional classic button argument.
 * @return {{shouldOpenDrawer: boolean, shouldSkipNextFetch: boolean, shouldFetchCart: boolean}}
 * Post-add presentation and reconciliation decisions.
 */
export function resolveCartAddPolicy( {
	trigger,
	cartProcessed = false,
	classicEvent,
	classicButton,
} = {} ) {
	const shouldOpenDrawer = Boolean(
		triggerAutoOpensDrawer( trigger ) &&
			! classicAddOptsOutOfAutoOpen( classicEvent, classicButton )
	);

	return {
		shouldOpenDrawer,
		shouldSkipNextFetch: Boolean( cartProcessed && shouldOpenDrawer ),
		shouldFetchCart: ! cartProcessed,
	};
}
