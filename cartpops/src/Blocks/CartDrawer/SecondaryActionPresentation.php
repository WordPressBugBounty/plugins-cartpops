<?php
/**
 * Fail-closed projection for the optional paid drawer action.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Blocks\CartDrawer;

use CartPops\Settings\SecondaryActionSettings;

/** Keep optional-edition state inert until its complete presentation is exact. */
final class SecondaryActionPresentation {
	private const KEYS = array( 'mode', 'text', 'navigationUrl', 'closesDrawer', 'classes' );

	/**
	 * Project one exact server presentation, or omit it atomically.
	 *
	 * @param mixed $candidate Optional edition state.
	 *
	 * @return array{mode: string, text: string, navigationUrl: string|null, closesDrawer: bool, classes: list<string>}|null
	 */
	public static function project( mixed $candidate ): ?array {
		if ( ! is_array( $candidate ) || count( $candidate ) !== count( self::KEYS ) ) {
			return null;
		}
		foreach ( self::KEYS as $key ) {
			if ( ! array_key_exists( $key, $candidate ) ) {
				return null;
			}
		}
		if ( array_diff( array_keys( $candidate ), self::KEYS ) ) {
			return null;
		}

		$mode = $candidate['mode'];
		if ( ! in_array( $mode, array( 'continue_shopping', 'view_cart', 'custom_url' ), true ) ) {
			return null;
		}

		$text = SecondaryActionSettings::sanitize_custom_text_or_null( $candidate['text'] );
		if ( null === $text || $text !== $candidate['text'] ) {
			return null;
		}

		$navigation_url = $candidate['navigationUrl'];
		$closes_drawer  = $candidate['closesDrawer'];
		if ( 'continue_shopping' === $mode ) {
			if ( null !== $navigation_url || true !== $closes_drawer ) {
				return null;
			}
		} else {
			$canonical_url = SecondaryActionSettings::sanitize_custom_url_or_null( $navigation_url );
			if ( null === $canonical_url || $canonical_url !== $navigation_url || false !== $closes_drawer ) {
				return null;
			}
		}

		$classes = $candidate['classes'];
		if ( ! is_array( $classes ) || ! array_is_list( $classes ) || count( $classes ) < 3 || count( $classes ) > 16 ) {
			return null;
		}

		$mode_class = match ( $mode ) {
			'continue_shopping' => 'cpops-continue-shopping-btn',
			'custom_url'        => 'cpops-custom-btn',
			default             => 'cpops-view-cart-btn',
		};
		$baseline = array( 'cpops-checkout-secondary', 'cpops-secondary-action', $mode_class );
		if ( array_slice( $classes, 0, count( $baseline ) ) !== $baseline ) {
			return null;
		}

		$seen = array();
		foreach ( $classes as $class ) {
			if (
				! is_string( $class )
				|| 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/D', $class )
				|| isset( $seen[ $class ] )
			) {
				return null;
			}
			$seen[ $class ] = true;
		}

		return $candidate;
	}

	/** Prevent construction of the static projector. */
	private function __construct() {}
}
