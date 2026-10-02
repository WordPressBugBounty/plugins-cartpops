<?php
/**
 * V1 powered-by link compatibility.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Compatibility;

/** Resolves a bounded Freemius partner link through the public V1 filter. */
final class LegacyPoweredByLink {

	private const BASE_URL        = 'https://r.freemius.com/7061/';
	private const MAX_CODE_LENGTH = 20;
	private const MAX_URL_LENGTH  = 2048;

	/**
	 * Normalize the historical numeric partner identifier.
	 *
	 * An empty value remains a supported disabled state. Every non-empty value
	 * must be a canonical positive base-10 integer without surrounding space.
	 *
	 * @param mixed $candidate Stored or migrated partner code.
	 */
	public static function normalize_partner_code( mixed $candidate ): ?string {
		if ( is_int( $candidate ) ) {
			$candidate = (string) $candidate;
		}
		if ( ! is_string( $candidate ) || strlen( $candidate ) > self::MAX_CODE_LENGTH ) {
			return null;
		}
		if ( '' === $candidate ) {
			return '';
		}

		return 1 === preg_match( '/^[1-9][0-9]{0,19}$/D', $candidate )
			? $candidate
			: null;
	}

	/**
	 * Build the exact partner URL and apply the V1 link filter once.
	 *
	 * @param mixed $candidate_code Stored partner code.
	 */
	public static function resolve( mixed $candidate_code ): ?string {
		$code = self::normalize_partner_code( $candidate_code );
		if ( null === $code || '' === $code ) {
			return null;
		}

		$canonical = self::BASE_URL . $code . '/';
		$filtered  = apply_filters( 'cartpops_powered_by_link', $canonical );

		return self::normalize_url( $filtered ) ?? $canonical;
	}

	/**
	 * Accept only a complete, credential-free HTTP(S) destination.
	 *
	 * @param mixed $candidate Filter result.
	 */
	private static function normalize_url( mixed $candidate ): ?string {
		if ( ! is_string( $candidate ) || ! self::has_supported_url_grammar( $candidate ) ) {
			return null;
		}

		$normalized = esc_url_raw( $candidate, array( 'http', 'https' ) );
		return is_string( $normalized ) && self::has_supported_url_grammar( $normalized )
			? $normalized
			: null;
	}

	/**
	 * Validate the same absolute URL grammar before and after WordPress.
	 *
	 * @param string $candidate Raw or normalized destination.
	 */
	private static function has_supported_url_grammar( string $candidate ): bool {
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
		$scheme = isset( $parts['scheme'] ) && is_string( $parts['scheme'] )
			? strtolower( $parts['scheme'] )
			: '';
		$host   = $parts['host'] ?? null;
		if (
			1 !== preg_match( '/\Ahttps?:\/\//iD', $candidate )
			|| ! in_array( $scheme, array( 'http', 'https' ), true )
			|| ! is_string( $host )
			|| '' === $host
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
		) {
			return false;
		}

		return true;
	}
}
