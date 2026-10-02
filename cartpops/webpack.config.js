const configs = require( '@wordpress/scripts/config/webpack.config' );
const fs = require( 'fs' );
const path = require( 'path' );

const buildPath = path.resolve( __dirname, 'assets/build' );
const proSourcePath = path.resolve( __dirname, 'src/Pro' );
const hasProSource = fs.existsSync( proSourcePath );

// With --experimental-modules, @wordpress/scripts exports [scriptConfig, moduleConfig].
// Without it, a single config is exported.
const isArray = Array.isArray( configs );
const scriptConfig = isArray ? configs[ 0 ] : configs;

// Custom entries for admin dashboard, editor scripts, and styles.
// Note: block entries use capital "Blocks/" to match the block.json copies
// that wp-scripts mirrors from src/Blocks/ — paths are case-sensitive on Linux.
// View script modules are auto-discovered from block.json viewScriptModule.
const customEntries = {
	// Admin dashboard (React).
	'admin/index': path.resolve( __dirname, 'src/Admin/assets/js/index.js' ),

	// Block editor scripts.
	'Blocks/cart-drawer/index': path.resolve(
		__dirname,
		'src/Blocks/CartDrawer/index.js'
	),
	'Blocks/cart-launcher/index': path.resolve(
		__dirname,
		'src/Blocks/CartLauncher/index.js'
	),

	// Block frontend styles.
	'Blocks/cart-drawer/style-index': path.resolve(
		__dirname,
		'src/Blocks/CartDrawer/style.scss'
	),
	'Blocks/cart-launcher/style-index': path.resolve(
		__dirname,
		'src/Blocks/CartLauncher/style.scss'
	),

	// Freemius removes the complete /src/Pro root from Free packages. A present
	// root keeps every exact Pro entry mandatory so a partial Pro tree fails the
	// build instead of silently producing an incomplete paid edition.
	...( hasProSource
		? {
				'Pro/admin/index': path.resolve(
					__dirname,
					'src/Pro/Admin/assets/js/index.js'
				),
				'Pro/Blocks/shipping-meter/index': path.resolve(
					__dirname,
					'src/Pro/Blocks/ShippingMeter/index.js'
				),
				'Pro/Blocks/shipping-meter/style-index': path.resolve(
					__dirname,
					'src/Pro/Blocks/ShippingMeter/style.scss'
				),
				'Pro/Storefront/cart-drawer/style': path.resolve(
					__dirname,
					'src/Pro/Storefront/CartDrawer/style.scss'
				),
		  }
		: {} ),
};

/**
 * Preserve discovered entries regardless of wp-scripts' function/object form.
 *
 * @param {Object|Function|undefined} entry     Discovered entry authority.
 * @param {Object}                    additions Exact CartPops entries.
 */
const mergeEntries = ( entry, additions ) => {
	if ( typeof entry === 'function' ) {
		return async () => ( { ...( await entry() ), ...additions } );
	}
	return { ...( entry || {} ), ...additions };
};

/**
 * Keep shared module and chunk identities stable when the complete Pro graph is
 * absent from a Freemius-stripped Free source tree.
 *
 * @param {Object|undefined} optimization Upstream webpack optimization.
 */
const stableOptimization = ( optimization ) => ( {
	...( optimization || {} ),
	chunkIds: 'named',
	moduleIds: 'named',
} );

const customScriptConfig = {
	...scriptConfig,
	entry: mergeEntries( scriptConfig.entry, customEntries ),
	optimization: stableOptimization( scriptConfig.optimization ),
	output: { ...scriptConfig.output, path: buildPath },
};

if ( isArray ) {
	// Module config builds viewScriptModule entries as native ES modules.
	const moduleConfig = configs[ 1 ];
	const customModuleEntries = hasProSource
		? {
				'Pro/Blocks/ShippingMeter/view': path.resolve(
					__dirname,
					'src/Pro/Blocks/ShippingMeter/view.js'
				),
				'Pro/Storefront/cart-drawer/view': path.resolve(
					__dirname,
					'src/Pro/Storefront/CartDrawer/view.js'
				),
		  }
		: {};
	const customModuleConfig = {
		...moduleConfig,
		entry: mergeEntries( moduleConfig.entry, customModuleEntries ),
		optimization: stableOptimization( moduleConfig.optimization ),
		output: { ...moduleConfig.output, path: buildPath },
	};
	module.exports = [ customScriptConfig, customModuleConfig ];
} else {
	module.exports = customScriptConfig;
}
