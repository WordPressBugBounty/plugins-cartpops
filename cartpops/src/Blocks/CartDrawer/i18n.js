/**
 * CartPops Cart Drawer — i18n helper.
 *
 * @package
 */

import { store } from '@wordpress/interactivity';

/**
 * Apply sprintf-style placeholder substitution to a string.
 *
 * Supports both positional (`%s`) and numbered (`%1$s`, `%2$s`) placeholders,
 * mirroring WordPress' sprintf behaviour closely enough for translated UI
 * strings. Positional `%s` consume arguments left-to-right; numbered
 * placeholders pick the argument at the given 1-based index. A literal
 * percent sign is written as `%%`.
 *
 * @param {string} str  Template string.
 * @param {...*}   args Replacement arguments.
 * @return {string} Formatted string.
 */
export function sprintf( str, ...args ) {
	let positional = 0;
	return str.replace( /%(?:(\d+)\$)?([%s])/g, ( match, index, type ) => {
		if ( type === '%' ) {
			return '%';
		}
		const arg =
			index !== undefined
				? args[ parseInt( index, 10 ) - 1 ]
				: args[ positional++ ];
		return arg === undefined ? match : String( arg );
	} );
}

/**
 * Get a translated string from the i18n state and substitute placeholders.
 *
 * Looks up `key` in the Interactivity state's `i18n` map (falling back to the
 * key itself) and runs sprintf-style substitution with the remaining args.
 *
 * @param {string} key  i18n key.
 * @param {...*}   args Replacement arguments.
 * @return {string} Translated, formatted string.
 */
export function i18n( key, ...args ) {
	const { state: s } = store( 'cartpops' );
	const str = s.i18n?.[ key ] || key;
	return args.length ? sprintf( str, ...args ) : str;
}
