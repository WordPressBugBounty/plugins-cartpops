<?php
/**
 * V1 drawer URL filter compatibility.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Compatibility;

/** Keeps the two public V1 drawer URL filters safe for V2 navigation. */
final class LegacyDrawerUrl {

	private const MAX_URL_LENGTH = 8192;

	/**
	 * Apply the V1 checkout URL filter once and validate its result.
	 *
	 * @param string $canonical_url Canonical WooCommerce checkout URL.
	 */
	public static function checkout( string $canonical_url ): string {
		return self::apply( 'cartpops_checkout_button_url', $canonical_url );
	}

	/**
	 * Apply the V1 empty-cart URL filter once and validate its result.
	 *
	 * @param string $canonical_url Canonical WooCommerce shop URL.
	 */
	public static function empty_cart( string $canonical_url ): string {
		return self::apply( 'cartpops_empty_cart_button_url', $canonical_url );
	}

	/**
	 * Apply one single-value filter and fail closed to its canonical URL.
	 *
	 * @param string $hook          Public V1 filter name.
	 * @param string $canonical_url Canonical WooCommerce URL.
	 */
	private static function apply( string $hook, string $canonical_url ): string {
		$filtered = apply_filters( $hook, $canonical_url );

		return self::normalize( $filtered )
			?? self::normalize( $canonical_url )
			?? '';
	}

	/**
	 * Accept an HTTP(S) URL or an unambiguous root-relative site URL.
	 *
	 * @param mixed $candidate Candidate URL returned by an extension.
	 */
	private static function normalize( mixed $candidate ): ?string {
		if ( ! is_string( $candidate ) || ! self::has_supported_grammar( $candidate ) ) {
			return null;
		}

		$normalized = esc_url_raw( $candidate, array( 'http', 'https' ) );
		return is_string( $normalized ) && self::has_supported_grammar( $normalized )
			? $normalized
			: null;
	}

	/**
	 * Validate the same closed navigation grammar before and after WordPress.
	 *
	 * @param string $candidate Raw or WordPress-normalized URL.
	 */
	private static function has_supported_grammar( string $candidate ): bool {
		if (
			'' === $candidate
			|| strlen( $candidate ) > self::MAX_URL_LENGTH
			|| trim( $candidate ) !== $candidate
			|| str_contains( $candidate, '\\' )
			|| 1 === preg_match( '/[\x00-\x20\x7f]/', $candidate )
		) {
			return false;
		}

		$parts = wp_parse_url( $candidate );
		if ( ! is_array( $parts ) ) {
			return false;
		}

		if ( str_starts_with( $candidate, '/' ) ) {
			return ! str_starts_with( $candidate, '//' )
				&& ! isset( $parts['scheme'] )
				&& ! isset( $parts['host'] )
				&& ! isset( $parts['user'] )
				&& ! isset( $parts['pass'] );
		}

		$scheme = isset( $parts['scheme'] ) && is_string( $parts['scheme'] )
			? strtolower( $parts['scheme'] )
			: '';
		$host   = $parts['host'] ?? null;

		return 1 === preg_match( '/\Ahttps?:\/\//iD', $candidate )
			&& in_array( $scheme, array( 'http', 'https' ), true )
			&& is_string( $host )
			&& '' !== $host
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] );
	}
}
