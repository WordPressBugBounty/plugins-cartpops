<?php
/**
 * Dormant shared grammar for the paid secondary drawer action.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Settings;

/** Keep persisted and migrated secondary-action values on one inert grammar. */
final class SecondaryActionSettings {
	public const DEFAULT_MODE        = 'none';
	public const MAX_TEXT_CODEPOINTS = 80;
	public const MAX_URL_BYTES       = 2048;

	private const MODES = array( 'none', 'continue_shopping', 'view_cart', 'custom_url' );

	/**
	 * Whether a candidate is one exact stored mode.
	 *
	 * @param mixed $mode Candidate stored mode.
	 */
	public static function mode_is_valid( mixed $mode ): bool {
		return is_string( $mode ) && in_array( $mode, self::MODES, true );
	}

	/**
	 * Resolve malformed modes to the inert fresh-install default.
	 *
	 * @param mixed $mode Candidate stored mode.
	 */
	public static function normalize_mode( mixed $mode ): string {
		return self::mode_is_valid( $mode ) ? $mode : self::DEFAULT_MODE;
	}

	/**
	 * Return bounded plain customer text, or null when the complete value is unsafe.
	 *
	 * Markup, malformed UTF-8, controls, bidirectional overrides, empty text, and
	 * oversized values are rejected instead of being partially repaired.
	 *
	 * @param mixed $text Candidate customer-facing text.
	 */
	public static function sanitize_custom_text_or_null( mixed $text ): ?string {
		if ( ! is_string( $text ) || 1 !== preg_match( '//u', $text ) ) {
			return null;
		}

		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if (
			1 === preg_match( '/[<>]/u', $text )
			|| 1 === preg_match(
				'/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{061C}\x{200E}\x{200F}\x{2028}-\x{202E}\x{2066}-\x{2069}]/u',
				$text
			)
		) {
			return null;
		}

		$text = preg_replace( '/[\p{Z}\s]+/u', ' ', $text );
		if ( ! is_string( $text ) ) {
			return null;
		}
		$text = trim( $text );
		if ( '' === $text ) {
			return null;
		}

		$count = preg_match_all( '/./us', $text );
		return false !== $count && $count <= self::MAX_TEXT_CODEPOINTS ? $text : null;
	}

	/**
	 * Convert unsafe or empty custom text into dormant empty storage.
	 *
	 * @param mixed $text Candidate customer-facing text.
	 */
	public static function sanitize_custom_text( mixed $text ): string {
		return self::sanitize_custom_text_or_null( $text ) ?? '';
	}

	/**
	 * Return an unambiguous root-relative or absolute HTTP(S) URL.
	 *
	 * Only visible ASCII is accepted. Encoded structural separators and encoded
	 * controls are rejected so downstream decoders cannot turn a stored path into
	 * a protocol-relative or credential-bearing destination.
	 *
	 * @param mixed $url Candidate stored destination.
	 */
	public static function sanitize_custom_url_or_null( mixed $url ): ?string {
		if (
			! is_string( $url )
			|| '' === $url
			|| strlen( $url ) > self::MAX_URL_BYTES
			|| 1 !== preg_match( '//u', $url )
			|| 1 === preg_match( '/[^\x21-\x7E]/D', $url )
			|| str_contains( $url, '\\' )
			|| 1 === preg_match( '/%(?:0[0-9A-F]|1[0-9A-F]|20|7F|2F|3A|40|5C)/i', $url )
		) {
			return null;
		}

		if ( str_starts_with( $url, '/' ) ) {
			return str_starts_with( $url, '//' ) ? null : $url;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This strict grammar requires an already absolute URL; it does not repair scheme-less input.
		$parts = parse_url( $url );
		if (
			! is_array( $parts )
			|| ! isset( $parts['scheme'], $parts['host'] )
			|| ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true )
			|| '' === (string) $parts['host']
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| false === filter_var( $url, FILTER_VALIDATE_URL )
		) {
			return null;
		}

		return $url;
	}

	/**
	 * Convert an unsafe or empty custom URL into dormant empty storage.
	 *
	 * @param mixed $url Candidate stored destination.
	 */
	public static function sanitize_custom_url( mixed $url ): string {
		return self::sanitize_custom_url_or_null( $url ) ?? '';
	}

	/** Prevent construction of this static grammar. */
	private function __construct() {}
}
