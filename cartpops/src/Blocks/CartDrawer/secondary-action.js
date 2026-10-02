const SECONDARY_ACTION_KEYS = [
	'mode',
	'text',
	'navigationUrl',
	'closesDrawer',
	'classes',
];
const SECONDARY_ACTION_MODES = new Set( [
	'continue_shopping',
	'view_cart',
	'custom_url',
] );
const SECONDARY_ACTION_TEXT_HAZARD =
	/[\u0000-\u001f\u007f-\u009f\u061c\u200e\u200f\u2028-\u202e\u2066-\u2069\ud800-\udfff<>]/u;
const SECONDARY_ACTION_CLASS = /^[A-Za-z_][A-Za-z0-9_-]{0,63}$/u;
const SECONDARY_ACTION_ENCODED_URL_HAZARD =
	/%(?:0[0-9a-f]|1[0-9a-f]|20|7f|2f|3a|40|5c)/iu;

/**
 * Whether a URL matches the exact inert server grammar.
 *
 * @param {*} candidate Candidate navigation URL.
 * @return {boolean} Whether the URL is safe for direct anchor output.
 */
function isSecondaryActionUrl( candidate ) {
	if (
		typeof candidate !== 'string' ||
		candidate.length === 0 ||
		candidate.length > 2048 ||
		/[^!-~]/u.test( candidate ) ||
		candidate.includes( '\\' ) ||
		SECONDARY_ACTION_ENCODED_URL_HAZARD.test( candidate )
	) {
		return false;
	}

	if ( candidate.startsWith( '/' ) ) {
		return ! candidate.startsWith( '//' );
	}

	try {
		if ( ! /^https?:\/\//iu.test( candidate ) ) {
			return false;
		}
		const parsed = new URL( candidate );
		return (
			[ 'http:', 'https:' ].includes( parsed.protocol ) &&
			parsed.hostname !== '' &&
			( /^\[[0-9a-f:.]+\]$/iu.test( parsed.hostname ) ||
				/^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/u.test(
					parsed.hostname
				) ) &&
			parsed.username === '' &&
			parsed.password === ''
		);
	} catch {
		return false;
	}
}

/**
 * Whether text is already in the exact canonical server form.
 *
 * @param {*} candidate Candidate customer label.
 * @return {boolean} Whether the label is safe plain text.
 */
function isSecondaryActionText( candidate ) {
	return (
		typeof candidate === 'string' &&
		candidate.length > 0 &&
		[ ...candidate ].length <= 80 &&
		! SECONDARY_ACTION_TEXT_HAZARD.test( candidate ) &&
		candidate.replace( /[\p{Z}\s]+/gu, ' ' ).trim() === candidate
	);
}

/**
 * Project one complete paid presentation, or fail closed.
 *
 * @param {*} candidate Optional edition state.
 * @return {Object|null} Exact secondary action when every field agrees.
 */
export function projectSecondaryAction( candidate ) {
	const keys =
		candidate &&
		typeof candidate === 'object' &&
		! Array.isArray( candidate )
			? Object.keys( candidate )
			: [];
	if (
		keys.length !== SECONDARY_ACTION_KEYS.length ||
		! SECONDARY_ACTION_KEYS.every( ( key ) => keys.includes( key ) ) ||
		! SECONDARY_ACTION_MODES.has( candidate.mode ) ||
		! isSecondaryActionText( candidate.text )
	) {
		return null;
	}

	if ( candidate.mode === 'continue_shopping' ) {
		if (
			candidate.navigationUrl !== null ||
			candidate.closesDrawer !== true
		) {
			return null;
		}
	} else if (
		candidate.closesDrawer !== false ||
		! isSecondaryActionUrl( candidate.navigationUrl )
	) {
		return null;
	}

	const modeClass = {
		continue_shopping: 'cpops-continue-shopping-btn',
		view_cart: 'cpops-view-cart-btn',
		custom_url: 'cpops-custom-btn',
	}[ candidate.mode ];
	const baseline = [
		'cpops-checkout-secondary',
		'cpops-secondary-action',
		modeClass,
	];
	if (
		! Array.isArray( candidate.classes ) ||
		candidate.classes.length < baseline.length ||
		candidate.classes.length > 16 ||
		! baseline.every(
			( className, index ) => candidate.classes[ index ] === className
		) ||
		candidate.classes.some(
			( className ) =>
				typeof className !== 'string' ||
				! SECONDARY_ACTION_CLASS.test( className )
		) ||
		new Set( candidate.classes ).size !== candidate.classes.length
	) {
		return null;
	}

	return {
		mode: candidate.mode,
		text: candidate.text,
		navigationUrl: candidate.navigationUrl,
		closesDrawer: candidate.closesDrawer,
		classes: [ ...candidate.classes ],
	};
}
