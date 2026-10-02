/**
 * Cart-line price presentation, following WooCommerce's cart page: the main
 * price is the line subtotal before coupons, in the store's cart tax display,
 * and the unit price is secondary once the quantity exceeds one.
 *
 * `CartStateBuilder::line_price_presentation()` produces the same fields for
 * server rendering, so hydration and later Store API updates agree.
 *
 * @package
 */

const DEFAULT_EACH_TEMPLATE = '%s each';

/**
 * Parse a non-negative minor-unit amount without coercing malformed input.
 *
 * @param {*} value Candidate amount.
 * @return {number|null} Safe integer amount, or null.
 */
function minorUnits( value ) {
	if ( Number.isSafeInteger( value ) && value >= 0 ) {
		return value;
	}
	if ( typeof value !== 'string' || ! /^\d{1,16}$/.test( value ) ) {
		return null;
	}
	const parsed = Number( value );
	return Number.isSafeInteger( parsed ) ? parsed : null;
}

/**
 * Multiply a unit amount by a quantity when the result stays exact.
 *
 * @param {number|null} unit     Unit amount in minor units.
 * @param {number}      quantity Line quantity.
 * @return {number|null} Line amount, or null.
 */
function lineAmount( unit, quantity ) {
	if ( unit === null ) {
		return null;
	}
	const amount = unit * quantity;
	return Number.isSafeInteger( amount ) ? amount : null;
}

/**
 * Read a Store API line subtotal in the configured cart tax display.
 *
 * @param {Object} item           Store API cart item.
 * @param {string} taxDisplayCart WooCommerce `woocommerce_tax_display_cart`.
 * @return {number|null} Line subtotal in minor units, or null.
 */
export function storeLineSubtotalMinor( item, taxDisplayCart ) {
	const subtotal = minorUnits( item?.totals?.line_subtotal );
	if ( subtotal === null || taxDisplayCart !== 'incl' ) {
		return subtotal;
	}
	const tax = minorUnits( item.totals.line_subtotal_tax );
	const total = tax === null ? null : subtotal + tax;
	return Number.isSafeInteger( total ) ? total : null;
}

/**
 * Project the line total, struck regular line total and "each" label.
 *
 * @param {Object}      item                   Cart item with `quantity`, `prices`,
 *                                             `isOnSale` and `formattedPrice`.
 * @param {Object}      options
 * @param {Function}    options.format         Formats minor units for display.
 * @param {number|null} [options.lineMinor]    Authoritative line subtotal.
 * @param {string}      [options.eachTemplate] Translated "%s each" template.
 * @return {{formattedLineTotal: string, formattedRegularLineTotal: string, unitPriceEach: string}}
 * Line price presentation.
 */
export function projectLinePrice(
	item,
	{ format, lineMinor = null, eachTemplate } = {}
) {
	const quantity =
		Number.isSafeInteger( item?.quantity ) && item.quantity > 0
			? item.quantity
			: 1;
	const unitPrice =
		typeof item?.formattedPrice === 'string' ? item.formattedPrice : '';
	const line =
		minorUnits( lineMinor ) ??
		lineAmount( minorUnits( item?.prices?.price ), quantity );
	const regularLine = item?.isOnSale
		? lineAmount( minorUnits( item?.prices?.regular_price ), quantity )
		: null;
	const template =
		typeof eachTemplate === 'string' && /%(?:1\$)?s/.test( eachTemplate )
			? eachTemplate
			: DEFAULT_EACH_TEMPLATE;

	return {
		formattedLineTotal: line === null ? unitPrice : format( line ),
		formattedRegularLineTotal:
			line !== null && regularLine !== null && regularLine > line
				? format( regularLine )
				: '',
		unitPriceEach:
			line !== null && quantity > 1 && unitPrice !== ''
				? template.replace( /%(?:1\$)?s/, () => unitPrice )
				: '',
	};
}
