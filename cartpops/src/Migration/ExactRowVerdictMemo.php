<?php
/**
 * Remembers value-free verdicts about exact option bytes.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Migration;

/**
 * Avoids re-parsing unchanged migration rows on every request.
 *
 * A store updated from version 1 keeps a write-once migration snapshot and its
 * retained V1 option rows. Every request re-checks them, and both can hold
 * thousands of serialized nodes. A verdict about them (a closed list of
 * value-free codes) is a pure function of the exact bytes and the caller's
 * binding, so it is remembered under a SHA-256 of both: once per request in
 * memory and across requests in one small, value-free JSON option. Any changed
 * byte, binding, purpose or plugin version misses the memo and decides again; a missing,
 * malformed or foreign memo is ignored, never trusted. A null verdict means
 * "not proven" and is never stored across requests, so a row that fails its
 * check keeps failing it in full on every request.
 */
final class ExactRowVerdictMemo {

	public const OPTION = 'cartpops_row_verdict_memo_v1';

	private const SCHEMA_VERSION   = 1;
	private const MAX_STORED_BYTES = 16384;
	private const MAX_ENTRIES      = 16;
	private const MAX_CODES        = 32;

	/**
	 * Request-local verdicts keyed by exact memo key.
	 *
	 * @var array<string, string[]|null>
	 */
	private static array $request = array();

	/**
	 * Return the remembered verdict for exact inputs, deciding at most once.
	 *
	 * @param string                      $purpose Closed caller identifier, including any validator version.
	 * @param array<int|string, string>   $inputs  Exact bytes and binding the verdict is a pure function of.
	 * @param \Closure(): (string[]|null) $decide  Pure decision; null means not proven.
	 * @return string[]|null
	 */
	public static function decide( string $purpose, array $inputs, \Closure $decide ): ?array {
		$key = self::key( defined( 'CARTPOPS_VERSION' ) ? (string) CARTPOPS_VERSION : '', $purpose, $inputs );
		if ( array_key_exists( $key, self::$request ) ) {
			return self::$request[ $key ];
		}

		$entries = self::stored_entries();
		if ( isset( $entries[ $key ] ) && self::verdict_is_valid( $entries[ $key ] ) ) {
			self::$request[ $key ] = $entries[ $key ];
			return $entries[ $key ];
		}

		$verdict = $decide();
		$verdict = self::verdict_is_valid( $verdict ) ? $verdict : null;

		self::$request[ $key ] = $verdict;
		if ( null !== $verdict ) {
			self::remember( $entries, $key, $verdict );
		}
		return $verdict;
	}

	/** Forget request-local verdicts, as a new page request would. */
	public static function forget_request_memo(): void {
		self::$request = array();
	}

	/**
	 * Hash the plugin version, purpose and length-prefixed inputs without copying the rows.
	 *
	 * A verdict is policy: a release may tighten a check, so a verdict never
	 * outlives the plugin version that made it.
	 *
	 * @param string                    $version Plugin version that decided.
	 * @param string                    $purpose Closed caller identifier.
	 * @param array<int|string, string> $inputs  Exact inputs.
	 */
	private static function key( string $version, string $purpose, array $inputs ): string {
		$context = hash_init( 'sha256' );
		hash_update( $context, $version . "\0" . $purpose . "\0" . count( $inputs ) . "\0" );
		foreach ( $inputs as $name => $input ) {
			hash_update( $context, $name . "\0" . strlen( $input ) . "\0" );
			hash_update( $context, $input );
		}
		return hash_final( $context );
	}

	/**
	 * Whether a verdict is a closed, value-free list of codes.
	 *
	 * @param mixed $verdict Candidate verdict.
	 * @phpstan-assert-if-true string[] $verdict
	 */
	private static function verdict_is_valid( mixed $verdict ): bool {
		if ( ! is_array( $verdict ) || ! array_is_list( $verdict ) || count( $verdict ) > self::MAX_CODES ) {
			return false;
		}
		foreach ( $verdict as $code ) {
			if ( ! is_string( $code ) || 1 !== preg_match( '/\A[a-z0-9_]{1,96}\z/D', $code ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Read the stored memo entries, ignoring anything outside the closed schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function stored_entries(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}
		$stored = get_option( self::OPTION, '' );
		if ( ! is_string( $stored ) || '' === $stored || strlen( $stored ) > self::MAX_STORED_BYTES ) {
			return array();
		}
		$decoded = json_decode( $stored, true, 8 );
		if (
			! is_array( $decoded )
			|| self::SCHEMA_VERSION !== ( $decoded['schema_version'] ?? null )
			|| ! is_array( $decoded['entries'] ?? null )
		) {
			return array();
		}

		$entries = array();
		foreach ( $decoded['entries'] as $key => $verdict ) {
			if ( is_string( $key ) && 1 === preg_match( '/\A[a-f0-9]{64}\z/D', $key ) ) {
				$entries[ $key ] = $verdict;
			}
		}

		return array_slice( $entries, -self::MAX_ENTRIES, null, true );
	}

	/**
	 * Persist one verdict best-effort; a failed write only costs a re-check.
	 *
	 * @param array<string, mixed> $entries Current stored entries, oldest first.
	 * @param string               $key     Exact memo key.
	 * @param string[]             $verdict Proven verdict.
	 */
	private static function remember( array $entries, string $key, array $verdict ): void {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}
		unset( $entries[ $key ] );
		$entries         = array_slice( $entries, -( self::MAX_ENTRIES - 1 ), null, true );
		$entries[ $key ] = $verdict;

		$encoded = wp_json_encode(
			array(
				'schema_version' => self::SCHEMA_VERSION,
				'entries'        => $entries,
			)
		);
		if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_STORED_BYTES ) {
			return;
		}

		// Read on every request alongside the rows it describes, so autoload it.
		update_option( self::OPTION, $encoded, true );
	}
}
