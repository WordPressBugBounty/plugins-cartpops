<?php
/**
 * Canonical recommendation add-button presentation values.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Recommendations;

/** Keep stored, migrated, and paid-projected button values on one exact grammar. */
final class RecommendationButtonPresentation {
	public const DEFAULT_MODE        = 'icon';
	public const DEFAULT_TEXT        = 'Add';
	public const MAX_TEXT_CODEPOINTS = 80;

	private const MODES = array( 'icon', 'text', 'text_icon' );

	/**
	 * Whether a candidate is one exact supported presentation mode.
	 *
	 * @param mixed $mode Candidate presentation mode.
	 */
	public static function mode_is_valid( mixed $mode ): bool {
		return is_string( $mode ) && in_array( $mode, self::MODES, true );
	}

	/**
	 * Convert malformed presentation modes to the shared icon default.
	 *
	 * @param mixed $mode Candidate presentation mode.
	 */
	public static function normalize_mode( mixed $mode ): string {
		return self::mode_is_valid( $mode ) ? $mode : self::DEFAULT_MODE;
	}

	/**
	 * Return one safe customer-visible label, or null when it must fail closed.
	 *
	 * Markup is deliberately reduced to its text content. Directional controls,
	 * non-whitespace control characters, malformed UTF-8, and empty results are
	 * rejected instead of being repaired into misleading customer copy.
	 *
	 * @param mixed $text Candidate customer-facing label.
	 */
	public static function sanitize_text_or_null( mixed $text ): ?string {
		if ( ! is_string( $text ) || 1 !== preg_match( '//u', $text ) ) {
			return null;
		}

		$text = wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );
		if (
			1 === preg_match(
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

		$count = preg_match_all( '/./us', $text, $characters );
		if ( false === $count ) {
			return null;
		}
		if ( $count > self::MAX_TEXT_CODEPOINTS ) {
			$text = implode( '', array_slice( $characters[0], 0, self::MAX_TEXT_CODEPOINTS ) );
		}

		$text = trim( $text );
		return '' !== $text ? $text : null;
	}

	/**
	 * Convert every unsafe or empty label to the dormant shared default.
	 *
	 * @param mixed $text Candidate customer-facing label.
	 */
	public static function sanitize_text( mixed $text ): string {
		return self::sanitize_text_or_null( $text ) ?? self::DEFAULT_TEXT;
	}

	/**
	 * Project the canonical contract into translated customer-facing text.
	 *
	 * Stored and filtered defaults remain the exact language-neutral `Add`.
	 *
	 * @param mixed $text               Candidate stored label.
	 * @param mixed $translated_default Translated default label.
	 */
	public static function display_text( mixed $text, mixed $translated_default ): string {
		$canonical = self::sanitize_text_or_null( $text );
		if ( null !== $canonical && self::DEFAULT_TEXT !== $canonical ) {
			return $canonical;
		}

		return self::sanitize_text( $translated_default );
	}

	/** Prevent construction of this static value grammar. */
	private function __construct() {}
}
