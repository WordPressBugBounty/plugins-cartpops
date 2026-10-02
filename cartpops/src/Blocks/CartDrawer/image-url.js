/**
 * Cart Drawer image presentation policy shared by every client cart ingress.
 *
 * @package
 */

/** Version shared with PHP and the policy corpus. */
export const CARTPOPS_IMAGE_URL_POLICY_VERSION = 'cartpops-image-url-v4';

const IMAGE_URL_MAX_LENGTH = 8192;
const ABSOLUTE_IMAGE_URL = /^https?:\/\/([^/?#]+)((?:[/?#].*)?)$/;
const IMAGE_URL_TAIL =
	/^(?:\/[A-Za-z0-9._~!$&'()*+,;=:@%/-]*)?(?:\?[A-Za-z0-9._~!$&'()*+,;=:@%/?-]*)?(?:#[A-Za-z0-9._~!$&'()*+,;=:@%/?-]*)?$/;
const DNS_LABEL = /^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/;

/**
 * Validate the RFC 3986 path/query/fragment subset and percent escapes.
 *
 * @param {string} tail URL tail beginning with path, query, fragment, or empty.
 * @return {boolean} Whether the exact value belongs to the shared grammar.
 */
function hasValidTail( tail ) {
	return (
		IMAGE_URL_TAIL.test( tail ) &&
		! tail.includes( ';//' ) &&
		! /%(?![0-9A-F]{2})/i.test( tail ) &&
		! /%(?:0[0-9A-F]|1[0-9A-F]|5C|7F)/i.test( tail )
	);
}

/**
 * Validate an exact dotted-decimal IPv4 address.
 *
 * @param {string} host Candidate IPv4 address.
 * @return {boolean} Whether all four octets are canonical decimal values.
 */
function hasValidIpv4( host ) {
	const octets = host.split( '.' );
	return (
		octets.length === 4 &&
		octets.every(
			( octet ) =>
				/^(?:0|[1-9][0-9]{0,2})$/.test( octet ) &&
				Number( octet ) <= 255
		)
	);
}

/**
 * Validate a bracketed IPv6 literal without browser normalization.
 *
 * An embedded dotted-decimal IPv4 tail counts as two IPv6 groups. Zone
 * identifiers are excluded by the authority boundary before this function.
 *
 * @param {string} literal Candidate IPv6 address without brackets.
 * @return {boolean} Whether the literal has an exact RFC 4291 text shape.
 */
function hasValidIpv6( literal ) {
	if ( literal === '' || ! /^[0-9A-F:.]+$/i.test( literal ) ) {
		return false;
	}

	let groupsValue = literal;
	if ( literal.includes( '.' ) ) {
		const ipv4Separator = literal.lastIndexOf( ':' );
		if (
			ipv4Separator < 0 ||
			! hasValidIpv4( literal.slice( ipv4Separator + 1 ) )
		) {
			return false;
		}
		groupsValue = `${ literal.slice( 0, ipv4Separator ) }:0:0`;
	}

	const compression = groupsValue.indexOf( '::' );
	if (
		compression !== -1 &&
		compression !== groupsValue.lastIndexOf( '::' )
	) {
		return false;
	}

	const validGroup = ( group ) => /^[0-9A-F]{1,4}$/i.test( group );
	if ( compression === -1 ) {
		const groups = groupsValue.split( ':' );
		return groups.length === 8 && groups.every( validGroup );
	}

	const left = groupsValue.slice( 0, compression );
	const right = groupsValue.slice( compression + 2 );
	const leftGroups = left === '' ? [] : left.split( ':' );
	const rightGroups = right === '' ? [] : right.split( ':' );
	return (
		leftGroups.every( validGroup ) &&
		rightGroups.every( validGroup ) &&
		leftGroups.length + rightGroups.length < 8
	);
}

/**
 * Validate an optional TCP port without number-parser normalization.
 *
 * @param {string}  port              Candidate port bytes.
 * @param {boolean} allowLeadingZeros Whether WordPress preserves this authority shape.
 * @return {boolean} Whether the value is an in-range decimal port.
 */
function hasValidPort( port, allowLeadingZeros ) {
	if ( port === '' || ! /^[0-9]+$/.test( port ) ) {
		return false;
	}
	if ( ! allowLeadingZeros && ! /^[1-9][0-9]{0,4}$/.test( port ) ) {
		return false;
	}

	const significantDigits = allowLeadingZeros
		? port.replace( /^0+/, '' )
		: port;
	return (
		significantDigits !== '' &&
		significantDigits.length <= 5 &&
		Number( significantDigits ) >= 1 &&
		Number( significantDigits ) <= 65535
	);
}

/**
 * Validate localhost, strict dotted IPv4, or conservative ASCII DNS.
 *
 * @param {string} host Authority host without a port.
 * @return {boolean} Whether the host is unambiguous and safe.
 */
function hasValidHost( host ) {
	if ( host.length > 253 ) {
		return false;
	}
	if ( host.toLowerCase() === 'localhost' ) {
		return true;
	}
	if ( /^[0-9.]+$/.test( host ) ) {
		return hasValidIpv4( host );
	}

	const labels = host.split( '.' );
	const lastLabel = labels.at( -1 );
	if ( /^(?:[0-9]+|0x[0-9A-F]+)$/i.test( lastLabel ) ) {
		return false;
	}

	return labels.every(
		( label ) =>
			label !== '' && label.length <= 63 && DNS_LABEL.test( label )
	);
}

/**
 * Validate a host and optional port without browser URL normalization.
 *
 * @param {string} authority URL authority without the scheme delimiter.
 * @return {boolean} Whether the authority belongs to the shared grammar.
 */
function hasValidAuthority( authority ) {
	if ( authority.includes( '@' ) || authority.includes( '%' ) ) {
		return false;
	}

	if ( authority.startsWith( '[' ) ) {
		const closingBracket = authority.indexOf( ']' );
		if (
			closingBracket < 0 ||
			authority.indexOf( ']', closingBracket + 1 ) >= 0 ||
			! hasValidIpv6( authority.slice( 1, closingBracket ) )
		) {
			return false;
		}

		const remainder = authority.slice( closingBracket + 1 );
		return (
			remainder === '' ||
			( remainder.startsWith( ':' ) &&
				hasValidPort( remainder.slice( 1 ), false ) )
		);
	}

	if (
		authority.includes( '[' ) ||
		authority.includes( ']' ) ||
		( authority.match( /:/g ) || [] ).length > 1
	) {
		return false;
	}

	const separator = authority.lastIndexOf( ':' );
	const host = separator < 0 ? authority : authority.slice( 0, separator );
	const port = separator < 0 ? null : authority.slice( separator + 1 );
	if ( host === '' || ! hasValidHost( host ) ) {
		return false;
	}
	if ( port === null ) {
		return true;
	}

	return hasValidPort( port, true );
}

/**
 * Apply the exact, edition-neutral grammar also used by PHP.
 *
 * Root-relative and HTTP(S) WooCommerce media URLs are accepted. Data URIs
 * are deliberately rejected rather than maintaining two binary decoders that
 * could disagree about active, malformed, or oversized payloads.
 *
 * @param {*} candidate Candidate image URL.
 * @return {string|null} Exact accepted URL or null.
 */
export function normalizeCartItemImageUrl( candidate ) {
	if (
		typeof candidate !== 'string' ||
		candidate === '' ||
		candidate.length > IMAGE_URL_MAX_LENGTH ||
		candidate !== candidate.trim() ||
		/[^\x21-\x7e]/.test( candidate ) ||
		candidate.includes( '\\' )
	) {
		return null;
	}

	if ( candidate.startsWith( '/' ) ) {
		return candidate.startsWith( '//' ) || ! hasValidTail( candidate )
			? null
			: candidate;
	}

	const absolute = candidate.match( ABSOLUTE_IMAGE_URL );
	return absolute &&
		hasValidAuthority( absolute[ 1 ] ) &&
		hasValidTail( absolute[ 2 ] )
		? candidate
		: null;
}

/**
 * Add the one image source consumed by server/client directive rendering.
 *
 * @param {Object} item           Cart presentation item.
 * @param {*}      placeholderUrl Server-provided WooCommerce placeholder.
 * @return {Object} Detached item with a safe image presentation field.
 */
export function materializeCartItemImage( item, placeholderUrl ) {
	const hasServerDecision = Object.prototype.hasOwnProperty.call(
		item ?? {},
		'cartpopsImageSrc'
	);
	const images = Array.isArray( item?.images ) ? item.images : [];
	const firstImage = images[ 0 ];
	const rawCandidate =
		firstImage !== null &&
		typeof firstImage === 'object' &&
		! Array.isArray( firstImage )
			? firstImage.src
			: null;
	const candidate = hasServerDecision ? item.cartpopsImageSrc : rawCandidate;
	const normalizedCandidate =
		hasServerDecision && candidate === ''
			? ''
			: normalizeCartItemImageUrl( candidate );
	return {
		...item,
		cartpopsImageSrc:
			normalizedCandidate ??
			normalizeCartItemImageUrl( placeholderUrl ) ??
			'',
	};
}
