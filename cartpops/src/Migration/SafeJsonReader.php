<?php
/**
 * Bounded JSON reader with duplicate-object-key rejection.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Migration;

/**
 * PHP's json_decode() silently keeps the final duplicate object member. This
 * pre-parser validates the JSON grammar and rejects collisions before decode.
 */
final class SafeJsonReader {

	/**
	 * Exact JSON bytes under inspection.
	 *
	 * @var string
	 */
	private string $input = '';

	/**
	 * Current byte offset.
	 *
	 * @var int
	 */
	private int $offset = 0;

	/**
	 * Total input bytes.
	 *
	 * @var int
	 */
	private int $length = 0;

	/**
	 * Parsed node count.
	 *
	 * @var int
	 */
	private int $nodes = 0;

	/**
	 * Maximum nesting depth.
	 *
	 * @var int
	 */
	private int $max_depth = 64;

	/**
	 * Maximum parsed node count.
	 *
	 * @var int
	 */
	private int $max_nodes = 10000;

	/**
	 * Decode one bounded JSON array/object into associative arrays.
	 *
	 * @param string $raw       Exact JSON bytes.
	 * @param int    $max_bytes Maximum input bytes.
	 * @param int    $max_depth Maximum nesting depth.
	 * @param int    $max_nodes Maximum parsed node count.
	 * @return array{success: bool, value: mixed}
	 */
	public function decode( string $raw, int $max_bytes, int $max_depth, int $max_nodes ): array {
		if ( strlen( $raw ) > $max_bytes ) {
			return array(
				'success' => false,
				'value'   => null,
			);
		}
		$this->input     = $raw;
		$this->offset    = 0;
		$this->length    = strlen( $raw );
		$this->nodes     = 0;
		$this->max_depth = $max_depth;
		$this->max_nodes = $max_nodes;

		try {
			$this->skip_whitespace();
			$this->parse_value( 0 );
			$this->skip_whitespace();
			if ( $this->offset !== $this->length ) {
				return array(
					'success' => false,
					'value'   => null,
				);
			}
			$value = json_decode( $raw, true, $max_depth, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING );
		} catch ( \JsonException | SafeJsonReaderException ) {
			return array(
				'success' => false,
				'value'   => null,
			);
		}

		return array(
			'success' => is_array( $value ),
			'value'   => is_array( $value ) ? $value : null,
		);
	}

	/**
	 * Parse one JSON value without constructing object instances.
	 *
	 * @param int $depth Current nesting depth.
	 * @throws SafeJsonReaderException When syntax or bounds are invalid.
	 */
	private function parse_value( int $depth ): void {
		++$this->nodes;
		if ( $this->nodes > $this->max_nodes || $depth > $this->max_depth ) {
			throw new SafeJsonReaderException();
		}
		$this->skip_whitespace();
		$token = $this->input[ $this->offset ] ?? '';
		if ( '{' === $token ) {
			$this->parse_object( $depth + 1 );
			return;
		}
		if ( '[' === $token ) {
			$this->parse_array( $depth + 1 );
			return;
		}
		if ( '"' === $token ) {
			$this->parse_string();
			return;
		}
		foreach ( array( 'true', 'false', 'null' ) as $literal ) {
			if ( substr( $this->input, $this->offset, strlen( $literal ) ) === $literal ) {
				$this->offset += strlen( $literal );
				return;
			}
		}
		$remaining = substr( $this->input, $this->offset );
		if ( 1 !== preg_match( '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+\-]?[0-9]+)?/', $remaining, $match ) ) {
			throw new SafeJsonReaderException();
		}
		$this->offset += strlen( $match[0] );
	}

	/**
	 * Parse one object while rejecting duplicate member names.
	 *
	 * @param int $depth Current nesting depth.
	 * @throws SafeJsonReaderException When syntax or keys are invalid.
	 */
	private function parse_object( int $depth ): void {
		++$this->offset;
		$this->skip_whitespace();
		if ( '}' === ( $this->input[ $this->offset ] ?? '' ) ) {
			++$this->offset;
			return;
		}
		$keys = array();
		while ( true ) {
			$this->skip_whitespace();
			if ( '"' !== ( $this->input[ $this->offset ] ?? '' ) ) {
				throw new SafeJsonReaderException();
			}
			$raw_key = $this->parse_string();
			try {
				$key = json_decode( $raw_key, true, 2, JSON_THROW_ON_ERROR );
			} catch ( \JsonException ) {
				throw new SafeJsonReaderException();
			}
			if ( ! is_string( $key ) || isset( $keys[ 's:' . $key ] ) ) {
				throw new SafeJsonReaderException();
			}
			$keys[ 's:' . $key ] = true;
			$this->skip_whitespace();
			$this->expect( ':' );
			$this->parse_value( $depth );
			$this->skip_whitespace();
			$separator = $this->input[ $this->offset ] ?? '';
			if ( '}' === $separator ) {
				++$this->offset;
				return;
			}
			$this->expect( ',' );
		}
	}

	/**
	 * Parse one JSON list.
	 *
	 * @param int $depth Current nesting depth.
	 * @throws SafeJsonReaderException When syntax is invalid.
	 */
	private function parse_array( int $depth ): void {
		++$this->offset;
		$this->skip_whitespace();
		if ( ']' === ( $this->input[ $this->offset ] ?? '' ) ) {
			++$this->offset;
			return;
		}
		while ( true ) {
			$this->parse_value( $depth );
			$this->skip_whitespace();
			$separator = $this->input[ $this->offset ] ?? '';
			if ( ']' === $separator ) {
				++$this->offset;
				return;
			}
			$this->expect( ',' );
		}
	}

	/**
	 * Consume one JSON string and return its exact quoted bytes.
	 *
	 * @throws SafeJsonReaderException When the string is incomplete.
	 */
	private function parse_string(): string {
		$start = $this->offset;
		$this->expect( '"' );
		while ( $this->offset < $this->length ) {
			$character = $this->input[ $this->offset++ ];
			if ( '"' === $character ) {
				return substr( $this->input, $start, $this->offset - $start );
			}
			if ( '\\' !== $character ) {
				if ( ord( $character ) < 0x20 ) {
					throw new SafeJsonReaderException();
				}
				continue;
			}
			$escaped = $this->input[ $this->offset++ ] ?? '';
			if ( ! in_array( $escaped, array( '"', '\\', '/', 'b', 'f', 'n', 'r', 't', 'u' ), true ) ) {
				throw new SafeJsonReaderException();
			}
			if ( 'u' === $escaped ) {
				$hex = substr( $this->input, $this->offset, 4 );
				if ( 4 !== strlen( $hex ) || 1 !== preg_match( '/^[0-9a-f]{4}$/iD', $hex ) ) {
					throw new SafeJsonReaderException();
				}
				$this->offset += 4;
			}
		}
		throw new SafeJsonReaderException();
	}

	/**
	 * Advance past insignificant JSON whitespace.
	 */
	private function skip_whitespace(): void {
		while ( $this->length > $this->offset && str_contains( " \t\r\n", $this->input[ $this->offset ] ) ) {
			++$this->offset;
		}
	}

	/**
	 * Consume an expected punctuation byte.
	 *
	 * @param string $expected Expected byte.
	 * @throws SafeJsonReaderException When the next byte differs.
	 */
	private function expect( string $expected ): void {
		if ( ! hash_equals( $expected, $this->input[ $this->offset ] ?? '' ) ) {
			throw new SafeJsonReaderException();
		}
		++$this->offset;
	}
}
