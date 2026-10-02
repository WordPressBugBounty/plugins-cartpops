<?php
/**
 * Cart Drawer product-name presentation normalization.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Blocks\CartDrawer;

/** Keep persisted product-name presentation on the closed storefront allowlist. */
final class DrawerProductNameDisplay {
	/** Fresh V2 installs retain the existing two-line presentation. */
	public const DEFAULT = 'two_lines';

	/**
	 * Supported server-rendered presentation values.
	 *
	 * @var array<string, true>
	 */
	private const ALLOWED = array(
		self::DEFAULT => true,
		'single_line' => true,
		'full'        => true,
	);

	/**
	 * Normalize an untrusted persisted setting to a supported mode.
	 *
	 * @param mixed $value Persisted setting candidate.
	 */
	public static function normalize( mixed $value ): string {
		return is_string( $value ) && isset( self::ALLOWED[ $value ] )
			? $value
			: self::DEFAULT;
	}
}
