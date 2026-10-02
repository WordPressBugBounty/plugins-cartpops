/**
 * Format admin-preview and analytics amounts with the store currency settings.
 */

const FALLBACK_CURRENCY = Object.freeze( {
	symbol: '$',
	position: 'left',
	decimals: 2,
	decimalSeparator: '.',
	thousandSeparator: '',
} );

const VALID_POSITIONS = Object.freeze( [
	'left',
	'left_space',
	'right',
	'right_space',
] );

const MISSING_VALUE = Symbol( 'missing-value' );

const UNSAFE_TEXT =
	/[\u0000-\u001f\u007f-\u009f\u061c\u200b-\u200f\u2028-\u202e\u2060\u2066-\u2069\ufeff<>&]/u;

/**
 * Detect an unpaired UTF-16 surrogate without rejecting valid Unicode symbols.
 *
 * @param {string} value Candidate text.
 * @return {boolean} Whether the text contains an unpaired surrogate.
 */
function hasLoneSurrogate( value ) {
	for ( let index = 0; index < value.length; index++ ) {
		const unit = value.charCodeAt( index );
		if ( unit >= 0xd800 && unit <= 0xdbff ) {
			const next = value.charCodeAt( index + 1 );
			if ( index + 1 >= value.length || next < 0xdc00 || next > 0xdfff ) {
				return true;
			}
			index++;
		} else if ( unit >= 0xdc00 && unit <= 0xdfff ) {
			return true;
		}
	}

	return false;
}

/**
 * Check text read from the localized admin payload.
 *
 * @param {string} value Candidate text.
 * @return {boolean} Whether the text contains unsafe display characters.
 */
function hasUnsafeText( value ) {
	return UNSAFE_TEXT.test( value ) || hasLoneSurrogate( value );
}

/**
 * Read an own data property without invoking an accessor. Reflective reads are
 * guarded because localized browser data can be replaced by a hostile Proxy.
 *
 * @param {*}      value Candidate object.
 * @param {string} key   Property name.
 * @return {*} Property value, or the missing-value sentinel.
 */
function readOwnDataValue( value, key ) {
	try {
		if (
			null === value ||
			( typeof value !== 'object' && typeof value !== 'function' )
		) {
			return MISSING_VALUE;
		}

		const descriptor = Reflect.getOwnPropertyDescriptor( value, key );
		if (
			! descriptor ||
			! Object.prototype.hasOwnProperty.call( descriptor, 'value' )
		) {
			return MISSING_VALUE;
		}

		return descriptor.value;
	} catch {
		return MISSING_VALUE;
	}
}

/**
 * Check a WooCommerce price separator.
 *
 * WooCommerce supports an empty thousands separator, but a decimal separator
 * must be one printable, non-numeric Unicode character.
 *
 * @param {*}       value      Candidate separator.
 * @param {boolean} allowEmpty Whether an empty value is valid.
 * @return {boolean} Whether the separator is safe to use.
 */
function isValidSeparator( value, allowEmpty ) {
	if ( typeof value !== 'string' ) {
		return false;
	}
	if ( value === '' ) {
		return allowEmpty;
	}

	return (
		Array.from( value ).length === 1 &&
		! /[\d+\-]/u.test( value ) &&
		! hasUnsafeText( value )
	);
}

/**
 * Normalize a number or complete numeric string without partial parsing.
 *
 * @param {*}      amount   Candidate amount.
 * @param {number} fallback Value used when the candidate is not finite.
 * @return {number} Finite amount.
 */
export function normalizeCurrencyAmount( amount, fallback = 0 ) {
	const safeFallback = Number.isFinite( fallback ) ? fallback : 0;
	if ( typeof amount === 'number' ) {
		return Number.isFinite( amount ) ? amount : safeFallback;
	}
	if ( typeof amount !== 'string' || amount.trim() === '' ) {
		return safeFallback;
	}

	const normalized = Number( amount.trim() );
	return Number.isFinite( normalized ) ? normalized : safeFallback;
}

/**
 * Return a plain fixed-point string. JavaScript switches to exponent notation
 * at 1e21 even for toFixed(), so expand that bounded representation before
 * applying store separators.
 *
 * @param {number} amount   Finite amount.
 * @param {number} decimals Decimal precision.
 * @return {string} Fixed-point decimal string.
 */
function toFixedString( amount, decimals ) {
	const fixed = amount.toFixed( decimals );
	if ( ! /e/i.test( fixed ) ) {
		return fixed;
	}

	const [ coefficient, exponentValue ] = Math.abs( amount )
		.toExponential()
		.split( 'e' );
	const digits = coefficient.replace( '.', '' );
	const integerLength = Number( exponentValue ) + 1;
	const integer = digits.padEnd( integerLength, '0' );

	return `${ amount < 0 ? '-' : '' }${ integer }${
		decimals > 0 ? `.${ '0'.repeat( decimals ) }` : ''
	}`;
}

/**
 * Read and normalize the currency metadata localized by the admin bootstrap.
 * Any incomplete or malformed payload falls back atomically to the historical
 * USD/two-decimal display.
 *
 * @return {Object} Normalized currency configuration.
 */
function getCurrency() {
	try {
		if ( typeof window === 'undefined' ) {
			return FALLBACK_CURRENCY;
		}

		const admin = readOwnDataValue( window, 'cartpopsAdmin' );
		const currency = readOwnDataValue( admin, 'currency' );
		const symbol = readOwnDataValue( currency, 'symbol' );
		const position = readOwnDataValue( currency, 'position' );
		const decimals = readOwnDataValue( currency, 'decimals' );
		const decimalSeparator = readOwnDataValue(
			currency,
			'decimal_separator'
		);
		const thousandSeparator = readOwnDataValue(
			currency,
			'thousand_separator'
		);
		const normalizedSymbol =
			typeof symbol === 'string' ? symbol.trim() : '';

		if (
			normalizedSymbol === '' ||
			normalizedSymbol.length > 16 ||
			hasUnsafeText( normalizedSymbol ) ||
			! VALID_POSITIONS.includes( position ) ||
			! Number.isInteger( decimals ) ||
			decimals < 0 ||
			decimals > 8 ||
			! isValidSeparator( decimalSeparator, false ) ||
			! isValidSeparator( thousandSeparator, true ) ||
			( thousandSeparator !== '' &&
				thousandSeparator === decimalSeparator )
		) {
			return FALLBACK_CURRENCY;
		}

		return {
			symbol: normalizedSymbol,
			position,
			decimals,
			decimalSeparator,
			thousandSeparator,
		};
	} catch {
		return FALLBACK_CURRENCY;
	}
}

/**
 * Format a finite numeric amount using the current WooCommerce currency.
 * Invalid amounts render as a deterministic zero without coercing objects.
 *
 * @param {*} amount Candidate amount.
 * @return {string} Store-currency amount.
 */
export function formatCurrency( amount ) {
	const currency = getCurrency();
	const safeAmount = normalizeCurrencyAmount( amount );
	const parts = toFixedString( safeAmount, currency.decimals ).split( '.' );
	const hasMinus = parts[ 0 ].startsWith( '-' );
	const negative = hasMinus && Number( parts.join( '.' ) ) !== 0;
	const integer = hasMinus ? parts[ 0 ].slice( 1 ) : parts[ 0 ];
	const groupedInteger = integer.replace(
		/\B(?=(\d{3})+(?!\d))/g,
		currency.thousandSeparator
	);
	const formattedNumber = `${ groupedInteger }${
		currency.decimals > 0 ? currency.decimalSeparator + parts[ 1 ] : ''
	}`;
	let formattedCurrency;

	switch ( currency.position ) {
		case 'left':
			formattedCurrency = currency.symbol + formattedNumber;
			break;
		case 'left_space':
			formattedCurrency = currency.symbol + ' ' + formattedNumber;
			break;
		case 'right_space':
			formattedCurrency = formattedNumber + ' ' + currency.symbol;
			break;
		default:
			formattedCurrency = formattedNumber + currency.symbol;
	}

	return `${ negative ? '-' : '' }${ formattedCurrency }`;
}
