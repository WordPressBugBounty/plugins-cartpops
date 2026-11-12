<?php
/**
 * Remembers bounded Freemius identity extractions by their exact source bytes.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Migration;

/**
 * Avoids re-parsing an unchanged Freemius account row on every request.
 *
 * Upgrade detection reads a few closed identity paths from `fs_accounts`, which
 * every Freemius-powered plugin on the site shares and which can hold thousands
 * of serialized nodes. Extraction is a pure function of the exact row bytes and
 * the closed path list, so its result is remembered under a SHA-256 of both:
 * once per request in memory and across requests in one small, value-free JSON
 * option. Any changed byte, path list or purpose misses the memo and parses
 * again; a missing, malformed or foreign memo is ignored, never trusted.
 */
final class FreemiusIdentityPathMemo {

	public const OPTION = 'cartpops_freemius_identity_memo_v1';

	private const SCHEMA_VERSION   = 1;
	private const MAX_STORED_BYTES = 16384;
	private const MAX_ENTRIES      = 8;

	/**
	 * Request-local results keyed by exact memo key.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private static array $request = array();

	/**
	 * Return the remembered extraction for exact bytes, computing it at most once.
	 *
	 * @param string                                  $purpose Closed caller identifier.
	 * @param string                                  $raw     Exact Freemius option bytes.
	 * @param string[]                                $paths   Closed path allowlist the extraction honors.
	 * @param \Closure(): (array<string, mixed>|null) $extract Pure extraction of $paths from $raw.
	 * @return array<string, mixed>|null
	 */
	public static function extract( string $purpose, string $raw, array $paths, \Closure $extract ): ?array {
		$key = self::key( $purpose, $raw, $paths );
		if ( array_key_exists( $key, self::$request ) ) {
			return self::$request[ $key ];
		}

		$entries = self::stored_entries();
		if ( array_key_exists( $key, $entries ) && self::result_is_valid( $entries[ $key ], $paths ) ) {
			self::$request[ $key ] = $entries[ $key ];
			return $entries[ $key ];
		}

		$result = $extract();
		$result = self::result_is_valid( $result, $paths ) ? $result : null;

		self::$request[ $key ] = $result;
		self::remember( $entries, $key, $result );
		return $result;
	}

	/** Forget request-local results, as a new page request would. */
	public static function forget_request_memo(): void {
		self::$request = array();
	}

	/**
	 * Hash the purpose, closed path list and exact bytes without copying the row.
	 *
	 * @param string   $purpose Closed caller identifier.
	 * @param string   $raw     Exact source bytes.
	 * @param string[] $paths   Closed path list.
	 */
	private static function key( string $purpose, string $raw, array $paths ): string {
		$context = hash_init( 'sha256' );
		hash_update( $context, $purpose . "\0" . implode( "\n", $paths ) . "\0" );
		hash_update( $context, $raw );
		return hash_final( $context );
	}

	/**
	 * Whether a result has the closed shape an extraction may return.
	 *
	 * @param mixed    $result Candidate extraction result.
	 * @param string[] $paths  Paths the extraction may report.
	 */
	private static function result_is_valid( mixed $result, array $paths ): bool {
		if ( null === $result ) {
			return true;
		}
		if ( ! is_array( $result ) ) {
			return false;
		}
		$allowed = array_fill_keys( $paths, true );
		foreach ( $result as $path => $value ) {
			if ( ! is_string( $path ) || ! isset( $allowed[ $path ] ) || ! ( null === $value || is_scalar( $value ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Read the stored memo entries, ignoring anything outside the closed schema.
	 *
	 * Entries are keyed by their exact memo key, so the site and network account
	 * rows of a multisite install are remembered side by side.
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
		foreach ( $decoded['entries'] as $key => $result ) {
			if ( is_string( $key ) && 1 === preg_match( '/\A[a-f0-9]{64}\z/D', $key ) ) {
				$entries[ $key ] = $result;
			}
		}

		return array_slice( $entries, -self::MAX_ENTRIES, null, true );
	}

	/**
	 * Persist one result best-effort; a failed write only costs a reparse.
	 *
	 * @param array<string, mixed>      $entries Current stored entries, oldest first.
	 * @param string                    $key     Exact memo key.
	 * @param array<string, mixed>|null $result  Extraction result.
	 */
	private static function remember( array $entries, string $key, ?array $result ): void {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}
		unset( $entries[ $key ] );
		$entries         = array_slice( $entries, -( self::MAX_ENTRIES - 1 ), null, true );
		$entries[ $key ] = $result;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode() would rewrite invalid UTF-8, so a remembered value could differ from the parsed one; failure skips the write instead.
		$encoded = json_encode(
			array(
				'schema_version' => self::SCHEMA_VERSION,
				'entries'        => $entries,
			),
			JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES
		);
		if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_STORED_BYTES ) {
			return;
		}

		// Read on every request alongside the Freemius row it describes, so autoload it.
		update_option( self::OPTION, $encoded, true );
	}
}
