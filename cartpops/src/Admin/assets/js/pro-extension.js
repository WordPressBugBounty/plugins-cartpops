/**
 * Fail-closed access to the physically separate Pro admin application.
 */

export const PRO_ADMIN_EXTENSION_VERSION = 3;

export const PRO_ADMIN_PAGE_KEYS = Object.freeze( [
	'smart-addons',
	'smart-bar',
	'bundle-builder',
	'analytics',
] );

export const PRO_ADMIN_COMPONENT_KEYS = Object.freeze( [
	'DashboardInsights',
	'ShippingMeterSettings',
	'DrawerPreviewEnhancements',
	'CustomRecommendationSelection',
	'RecommendationButtonPreview',
	'RecommendationButtonSettings',
	'SecondaryActionSettings',
	'AnalyticsResetActions',
] );

const PRO_ADMIN_EXTENSION_KEYS = Object.freeze( [
	'version',
	'pages',
	'components',
] );

/**
 * Snapshot exactly the declared own data properties from an untrusted value.
 * Every reflective operation is guarded because a Proxy may throw from any
 * trap. Accessors are rejected without invoking their getter.
 *
 * @param {*}        value        Candidate registry.
 * @param {string[]} expectedKeys Closed key list.
 * @return {Object|null} Null-prototype snapshot, or null.
 */
function readExactOwnDataValues( value, expectedKeys ) {
	try {
		if (
			null === value ||
			( typeof value !== 'object' && typeof value !== 'function' )
		) {
			return null;
		}

		const ownKeys = Reflect.ownKeys( value );
		if (
			ownKeys.length !== expectedKeys.length ||
			! expectedKeys.every( ( key ) => ownKeys.includes( key ) )
		) {
			return null;
		}

		const snapshot = Object.create( null );
		for ( const key of expectedKeys ) {
			const descriptor = Reflect.getOwnPropertyDescriptor( value, key );
			if (
				! descriptor ||
				! Object.prototype.hasOwnProperty.call( descriptor, 'value' )
			) {
				return null;
			}

			const readValue = Reflect.get( value, key, value );
			if ( readValue !== descriptor.value ) {
				return null;
			}
			snapshot[ key ] = descriptor.value;
		}

		return snapshot;
	} catch {
		return null;
	}
}

/**
 * Return a complete Pro extension only for an authorized admin payload.
 *
 * The server remains authoritative. A partial or unexpected browser registry
 * is treated exactly like an absent Pro application.
 *
 * @return {Object|null} Complete Pro extension, or null.
 */
export function getProAdminExtension() {
	try {
		if ( window.cartpopsAdmin?.isPro !== true ) {
			return null;
		}

		const extension = readExactOwnDataValues(
			window.cartpopsProAdmin,
			PRO_ADMIN_EXTENSION_KEYS
		);
		if (
			null === extension ||
			extension.version !== PRO_ADMIN_EXTENSION_VERSION
		) {
			return null;
		}

		const pages = readExactOwnDataValues(
			extension.pages,
			PRO_ADMIN_PAGE_KEYS
		);
		const components = readExactOwnDataValues(
			extension.components,
			PRO_ADMIN_COMPONENT_KEYS
		);
		if (
			null === pages ||
			null === components ||
			! PRO_ADMIN_PAGE_KEYS.every(
				( page ) => typeof pages[ page ] === 'function'
			) ||
			! PRO_ADMIN_COMPONENT_KEYS.every(
				( component ) => typeof components[ component ] === 'function'
			)
		) {
			return null;
		}

		return Object.freeze( {
			version: extension.version,
			pages: Object.freeze( { ...pages } ),
			components: Object.freeze( { ...components } ),
		} );
	} catch {
		return null;
	}
}
