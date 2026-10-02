<?php
/**
 * Cart Drawer image presentation policy.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Blocks\CartDrawer;

/** Materializes a safe, server-owned image field for each cart item. */
final class CartItemImage {

	/** Version shared with the browser implementation and policy corpus. */
	public const POLICY_VERSION = 'cartpops-image-url-v4';

	private const URL_MAX_LENGTH   = 8192;
	private const URL_TAIL_PATTERN = '~\A(?:/[A-Za-z0-9._\~!$&\'()*+,;=:@%/-]*)?(?:\?[A-Za-z0-9._\~!$&\'()*+,;=:@%/?-]*)?(?:#[A-Za-z0-9._\~!$&\'()*+,;=:@%/?-]*)?\z~D';

	/**
	 * Apply the exact, edition-neutral grammar also used in the browser.
	 *
	 * The policy accepts root-relative and HTTP(S) WooCommerce media URLs.
	 * Data URIs are deliberately rejected because keeping active and malformed
	 * payloads out is safer than maintaining two binary decoders in lockstep.
	 *
	 * @param mixed $candidate Candidate image URL.
	 */
	public static function normalize_url( mixed $candidate ): ?string {
		if (
			! is_string( $candidate )
			|| '' === $candidate
			|| strlen( $candidate ) > self::URL_MAX_LENGTH
			|| trim( $candidate ) !== $candidate
			|| 1 === preg_match( '/[^\x21-\x7e]/', $candidate )
			|| str_contains( $candidate, '\\' )
		) {
			return null;
		}

		if ( str_starts_with( $candidate, '/' ) ) {
			return str_starts_with( $candidate, '//' ) || ! self::has_valid_tail( $candidate )
				? null
				: self::escape_final_url( $candidate );
		}

		if (
			1 !== preg_match( '/\Ahttps?:\/\/([^\/?#]+)((?:[\/?#].*)?)\z/D', $candidate, $parts )
			|| ! self::has_valid_authority( $parts[1] )
			|| ! self::has_valid_tail( $parts[2] )
		) {
			return null;
		}

		return self::escape_final_url( $candidate );
	}

	/**
	 * Add the exact safe image source consumed by the Interactivity directive.
	 *
	 * @param array<string, mixed> $item            Cart presentation item.
	 * @param mixed                $placeholder_url WooCommerce placeholder URL.
	 * @return array<string, mixed>
	 */
	public static function materialize( array $item, mixed $placeholder_url ): array {
		$has_server_decision = array_key_exists( 'cartpopsImageSrc', $item );
		$candidate           = null;
		if ( $has_server_decision ) {
			$candidate = $item['cartpopsImageSrc'];
		} else {
			$images      = $item['images'] ?? null;
			$first_image = is_array( $images ) ? ( $images[0] ?? null ) : null;
			if ( is_array( $first_image ) ) {
				$candidate = $first_image['src'] ?? null;
			}
		}

		$normalized_candidate = $has_server_decision && '' === $candidate
			? ''
			: self::normalize_url( $candidate );

		$item['cartpopsImageSrc'] = $normalized_candidate
			?? self::normalize_url( $placeholder_url )
			?? '';
		return $item;
	}

	/**
	 * Validate a host and optional port without platform URL normalization.
	 *
	 * @param string $authority URL authority without the scheme delimiter.
	 * @return bool Whether the authority belongs to the shared grammar.
	 */
	private static function has_valid_authority( string $authority ): bool {
		if ( str_contains( $authority, '@' ) || str_contains( $authority, '%' ) ) {
			return false;
		}

		if ( str_starts_with( $authority, '[' ) ) {
			$closing_bracket = strpos( $authority, ']' );
			if (
				false === $closing_bracket
				|| false !== strpos( $authority, ']', $closing_bracket + 1 )
				|| ! self::has_valid_ipv6( substr( $authority, 1, $closing_bracket - 1 ) )
			) {
				return false;
			}

			$remainder = substr( $authority, $closing_bracket + 1 );
			return '' === $remainder
				|| ( str_starts_with( $remainder, ':' ) && self::has_valid_port( substr( $remainder, 1 ), false ) );
		}

		if ( str_contains( $authority, '[' ) || str_contains( $authority, ']' ) || substr_count( $authority, ':' ) > 1 ) {
			return false;
		}

		$separator = strrpos( $authority, ':' );
		$host      = false === $separator ? $authority : substr( $authority, 0, $separator );
		$port      = false === $separator ? null : substr( $authority, $separator + 1 );

		if ( '' === $host || ! self::has_valid_host( $host ) ) {
			return false;
		}
		if ( null === $port ) {
			return true;
		}

		return self::has_valid_port( $port, true );
	}

	/**
	 * Validate localhost, strict dotted IPv4, or conservative ASCII DNS.
	 *
	 * @param string $host Authority host without a port.
	 * @return bool Whether the host is unambiguous and safe.
	 */
	private static function has_valid_host( string $host ): bool {
		if ( strlen( $host ) > 253 ) {
			return false;
		}
		if ( 0 === strcasecmp( $host, 'localhost' ) ) {
			return true;
		}

		if ( 1 === preg_match( '/\A[0-9.]+\z/D', $host ) ) {
			return self::has_valid_ipv4( $host );
		}

		$labels     = explode( '.', $host );
		$last_label = end( $labels );
		if ( is_string( $last_label ) && 1 === preg_match( '/\A(?:[0-9]+|0x[0-9A-F]+)\z/iD', $last_label ) ) {
			return false;
		}

		foreach ( $labels as $label ) {
			if (
				'' === $label
				|| strlen( $label ) > 63
				|| 1 !== preg_match( '/\A[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\z/D', $label )
			) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Validate an exact dotted-decimal IPv4 address.
	 *
	 * @param string $host Candidate IPv4 address.
	 * @return bool Whether all four octets are canonical decimal values.
	 */
	private static function has_valid_ipv4( string $host ): bool {
		$octets = explode( '.', $host );
		if ( 4 !== count( $octets ) ) {
			return false;
		}
		foreach ( $octets as $octet ) {
			if ( 1 !== preg_match( '/\A(?:0|[1-9][0-9]{0,2})\z/D', $octet ) || (int) $octet > 255 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Validate a bracketed IPv6 literal without platform normalization.
	 *
	 * @param string $literal Candidate IPv6 address without brackets.
	 * @return bool Whether the literal has an exact RFC 4291 text shape.
	 */
	private static function has_valid_ipv6( string $literal ): bool {
		if ( '' === $literal || 1 !== preg_match( '/\A[0-9A-F:.]+\z/iD', $literal ) ) {
			return false;
		}

		$groups_value = $literal;
		if ( str_contains( $literal, '.' ) ) {
			$ipv4_separator = strrpos( $literal, ':' );
			if ( false === $ipv4_separator || ! self::has_valid_ipv4( substr( $literal, $ipv4_separator + 1 ) ) ) {
				return false;
			}
			$groups_value = substr( $literal, 0, $ipv4_separator ) . ':0:0';
		}

		$compression      = strpos( $groups_value, '::' );
		$last_compression = strrpos( $groups_value, '::' );
		if ( false !== $compression && $last_compression !== $compression ) {
			return false;
		}

		if ( false === $compression ) {
			$groups = explode( ':', $groups_value );
			return 8 === count( $groups ) && self::has_valid_ipv6_groups( $groups );
		}

		$left         = substr( $groups_value, 0, $compression );
		$right        = substr( $groups_value, $compression + 2 );
		$left_groups  = '' === $left ? array() : explode( ':', $left );
		$right_groups = '' === $right ? array() : explode( ':', $right );
		return self::has_valid_ipv6_groups( $left_groups )
			&& self::has_valid_ipv6_groups( $right_groups )
			&& count( $left_groups ) + count( $right_groups ) < 8;
	}

	/**
	 * Validate a list of hexadecimal IPv6 groups.
	 *
	 * @param array $groups Candidate groups.
	 * @phpstan-param list<string> $groups
	 * @return bool Whether every group has one to four hexadecimal digits.
	 */
	private static function has_valid_ipv6_groups( array $groups ): bool {
		foreach ( $groups as $group ) {
			if ( 1 !== preg_match( '/\A[0-9A-F]{1,4}\z/iD', $group ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Validate an optional TCP port without integer-parser normalization.
	 *
	 * @param string $port                Candidate port bytes.
	 * @param bool   $allow_leading_zeros Whether WordPress preserves this authority shape.
	 * @return bool Whether the value is an in-range decimal port.
	 */
	private static function has_valid_port( string $port, bool $allow_leading_zeros ): bool {
		if ( '' === $port || 1 !== preg_match( '/\A[0-9]+\z/D', $port ) ) {
			return false;
		}
		if ( ! $allow_leading_zeros && 1 !== preg_match( '/\A[1-9][0-9]{0,4}\z/D', $port ) ) {
			return false;
		}

		$significant_digits = $allow_leading_zeros ? ltrim( $port, '0' ) : $port;
		return '' !== $significant_digits
			&& strlen( $significant_digits ) <= 5
			&& (int) $significant_digits >= 1
			&& (int) $significant_digits <= 65535;
	}

	/**
	 * Validate the RFC 3986 path/query/fragment subset and percent escapes.
	 *
	 * @param string $tail URL tail beginning with path, query, fragment, or empty.
	 * @return bool Whether the exact value belongs to the shared grammar.
	 */
	private static function has_valid_tail( string $tail ): bool {
		return 1 === preg_match( self::URL_TAIL_PATTERN, $tail )
			&& ! str_contains( $tail, ';//' )
			&& 0 === preg_match( '/%(?![0-9A-F]{2})/i', $tail )
			&& 0 === preg_match( '/%(?:0[0-9A-F]|1[0-9A-F]|5C|7F)/i', $tail );
	}

	/**
	 * Apply WordPress escaping last and reject any policy-changing rewrite.
	 *
	 * @param string $candidate Explicitly validated image URL.
	 * @return string|null Exact escaped URL or null if WordPress changes it.
	 */
	private static function escape_final_url( string $candidate ): ?string {
		if ( ! function_exists( 'esc_url_raw' ) ) {
			return $candidate;
		}

		$escaped = \esc_url_raw( $candidate, array( 'http', 'https' ) );
		return is_string( $escaped ) && $escaped === $candidate ? $candidate : null;
	}
}
