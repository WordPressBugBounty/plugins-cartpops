<?php
/**
 * Canonical grammar for legacy CartPops option keys.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Migration;

/**
 * Keeps discovery, snapshots, dispositions, and journals on one grammar.
 */
final class LegacyOptionKey {

	public const MAX_BYTES = 191;

	/**
	 * Whether a value is one canonical legacy CartPops option key.
	 *
	 * @param mixed $value Candidate option key.
	 */
	public static function is_valid( mixed $value ): bool {
		return is_string( $value )
			&& strlen( $value ) <= self::MAX_BYTES
			&& 1 === preg_match( '/\Acartpops_[a-z0-9_]+\z/D', $value );
	}
}
