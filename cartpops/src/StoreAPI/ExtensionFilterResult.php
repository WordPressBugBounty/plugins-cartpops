<?php
/**
 * Bounded Store API extension-filter result policy.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\StoreAPI;

/** Keeps third-party Store API additions JSON-safe and bounded. */
final class ExtensionFilterResult {
	private const MAX_DEPTH              = 8;
	private const MAX_NODES              = 512;
	private const MAX_COLLECTION_ENTRIES = 128;
	private const MAX_KEY_BYTES          = 128;
	private const MAX_STRING_BYTES       = 8192;
	private const MAX_ENCODED_BYTES      = 65536;

	/**
	 * Accept one complete filtered result or atomically restore its baseline.
	 *
	 * @param array<string, mixed> $baseline  Unfiltered extension data or schema.
	 * @param mixed                $candidate Complete public filter result.
	 * @return array<string, mixed>
	 */
	public static function accept_or_baseline( array $baseline, mixed $candidate ): array {
		if ( ! is_array( $candidate ) || ( array() !== $candidate && array_is_list( $candidate ) ) ) {
			return $baseline;
		}

		$nodes = 0;
		if ( ! self::is_bounded_json_value( $candidate, 1, $nodes ) ) {
			return $baseline;
		}

		$encoded = wp_json_encode( $candidate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, 16 );
		return false !== $encoded && strlen( $encoded ) <= self::MAX_ENCODED_BYTES
			? $candidate
			: $baseline;
	}

	/**
	 * Validate without coercing an arbitrary PHP value.
	 *
	 * @param mixed $value Candidate JSON value.
	 * @param int   $depth Current array depth.
	 * @param int   $nodes Shared node count.
	 */
	private static function is_bounded_json_value( mixed $value, int $depth, int &$nodes ): bool {
		++$nodes;
		if ( $depth > self::MAX_DEPTH || $nodes > self::MAX_NODES ) {
			return false;
		}
		if ( null === $value || is_bool( $value ) || is_int( $value ) ) {
			return true;
		}
		if ( is_float( $value ) ) {
			return is_finite( $value );
		}
		if ( is_string( $value ) ) {
			return strlen( $value ) <= self::MAX_STRING_BYTES && 1 === preg_match( '//u', $value );
		}
		if ( ! is_array( $value ) || count( $value ) > self::MAX_COLLECTION_ENTRIES ) {
			return false;
		}

		$is_list = array_is_list( $value );
		foreach ( $value as $key => $child ) {
			if (
				( $is_list && ! is_int( $key ) )
				|| (
					! $is_list
					&& (
						! is_string( $key )
						|| strlen( $key ) > self::MAX_KEY_BYTES
						|| 1 !== preg_match( '//u', $key )
					)
				)
				|| ! self::is_bounded_json_value( $child, $depth + 1, $nodes )
			) {
				return false;
			}
		}

		return true;
	}
}
