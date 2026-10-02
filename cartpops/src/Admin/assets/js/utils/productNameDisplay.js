const MODES = new Set( [ 'two_lines', 'single_line', 'full' ] );

/**
 * Keep untrusted persisted/admin state on the same closed contract as PHP.
 *
 * @param {*} value Candidate product-name display mode.
 * @return {string} Supported mode or the fresh-V2 default.
 */
export function normalizeProductNameDisplay( value ) {
	return typeof value === 'string' && MODES.has( value )
		? value
		: 'two_lines';
}
