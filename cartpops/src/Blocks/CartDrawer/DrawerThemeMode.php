<?php
/**
 * Cart Drawer theme-mode normalization.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Blocks\CartDrawer;

/**
 * Keep persisted presentation values on the closed storefront allowlist.
 */
final class DrawerThemeMode {
	/**
	 * Supported server-rendered presentation values.
	 *
	 * @var array<string, true>
	 */
	private const ALLOWED = array(
		'auto'  => true,
		'light' => true,
		'dark'  => true,
	);

	/**
	 * Normalize an untrusted persisted setting to a supported mode.
	 *
	 * @param mixed $value Persisted setting candidate.
	 */
	public static function normalize( mixed $value ): string {
		return is_string( $value ) && isset( self::ALLOWED[ $value ] )
			? $value
			: 'auto';
	}
}
