<?php
/**
 * Bounded PHP-serialization reader that never constructs serialized objects.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Migration;

/**
 * Reads the subset of PHP's serialization grammar used by WordPress options.
 *
 * Object properties may be inspected as scalar paths, but no serialized class
 * is instantiated and no object payload is returned to callers.
 */
final class SafeSerializedReader {

	/**
	 * Serialized input for the current parse.
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
	 * Total input byte length.
	 *
	 * @var int
	 */
	private int $length = 0;

	/**
	 * Nodes consumed by the current parse.
	 *
	 * @var int
	 */
	private int $nodes = 0;

	/**
	 * Maximum permitted nesting depth.
	 *
	 * @var int
	 */
	private int $max_depth = 64;

	/**
	 * Maximum permitted node count.
	 *
	 * @var int
	 */
	private int $max_nodes = 10000;

	/**
	 * First value-free unsafe graph reason.
	 *
	 * @var string
	 */
	private string $unsafe_kind = '';

	/**
	 * Decode a serialized scalar/array, rejecting objects and references.
	 *
	 * Plain strings that merely begin with a serialization token are returned
	 * unchanged when they are not a complete WordPress-compatible serialized
	 * value.
	 *
	 * @param string $raw       Raw database bytes.
	 * @param int    $max_bytes Maximum permitted byte length.
	 * @param int    $max_depth Maximum permitted nesting depth.
	 * @param int    $max_nodes Maximum permitted graph nodes.
	 * @return array{serialized: bool, safe: bool, value: mixed, warning: string}
	 */
	public function decode( string $raw, int $max_bytes, int $max_depth, int $max_nodes ): array {
		if ( strlen( $raw ) > $max_bytes ) {
			return array(
				'serialized' => $this->looks_serialized( $raw ),
				'safe'       => false,
				'value'      => null,
				'warning'    => 'serialized_payload_too_large',
			);
		}

		$candidate = trim( $raw );
		if ( ! $this->looks_serialized( $candidate ) ) {
			return array(
				'serialized' => false,
				'safe'       => true,
				'value'      => $raw,
				'warning'    => '',
			);
		}

		try {
			$this->start( $candidate, $max_depth, $max_nodes );
			$value = $this->parse_value( true, array(), null, 0 );
			$this->require_complete();
		} catch ( SafeSerializedReaderException $exception ) {
			return array(
				'serialized' => true,
				'safe'       => false,
				'value'      => null,
				'warning'    => $exception->getMessage(),
			);
		}

		if ( '' !== $this->unsafe_kind ) {
			return array(
				'serialized' => true,
				'safe'       => false,
				'value'      => null,
				'warning'    => $this->unsafe_kind,
			);
		}

		return array(
			'serialized' => true,
			'safe'       => true,
			'value'      => $value,
			'warning'    => '',
		);
	}

	/**
	 * Extract only explicitly requested scalar/container paths.
	 *
	 * Serialized objects and their irrelevant siblings are parsed opaquely.
	 * Their classes are never loaded or constructed.
	 *
	 * @param string   $raw       Raw serialized database bytes.
	 * @param string[] $paths     Dot-separated paths to retain.
	 * @param int      $max_bytes Maximum permitted byte length.
	 * @param int      $max_depth Maximum permitted nesting depth.
	 * @param int      $max_nodes Maximum permitted graph nodes.
	 * @return array{success: bool, serialized: bool, values: array<string, mixed>, warning: string}
	 */
	public function extract_paths( string $raw, array $paths, int $max_bytes, int $max_depth, int $max_nodes ): array {
		if ( strlen( $raw ) > $max_bytes ) {
			return array(
				'success'    => false,
				'serialized' => $this->looks_serialized( $raw ),
				'values'     => array(),
				'warning'    => 'serialized_payload_too_large',
			);
		}

		$candidate = trim( $raw );
		if ( ! $this->looks_serialized( $candidate ) ) {
			return array(
				'success'    => false,
				'serialized' => false,
				'values'     => array(),
				'warning'    => '',
			);
		}

		$wanted  = array_fill_keys( $paths, true );
		$values  = array();
		$collect = static function ( array $path, mixed $value, bool $is_container ) use ( $wanted, &$values ): void {
			$name = implode( '.', $path );
			if ( isset( $wanted[ $name ] ) ) {
				$values[ $name ] = $is_container ? true : $value;
			}
		};

		try {
			$this->start( $candidate, $max_depth, $max_nodes );
			$this->parse_value( false, array(), $collect, 0 );
			$this->require_complete();
		} catch ( SafeSerializedReaderException $exception ) {
			return array(
				'success'    => false,
				'serialized' => true,
				'values'     => array(),
				'warning'    => $exception->getMessage(),
			);
		}

		return array(
			'success'    => true,
			'serialized' => true,
			'values'     => $values,
			'warning'    => '',
		);
	}

	/**
	 * Reset parser state for one bounded value.
	 *
	 * @param string $input     Serialized input.
	 * @param int    $max_depth Maximum permitted nesting depth.
	 * @param int    $max_nodes Maximum permitted graph nodes.
	 */
	private function start( string $input, int $max_depth, int $max_nodes ): void {
		$this->input       = $input;
		$this->offset      = 0;
		$this->length      = strlen( $input );
		$this->nodes       = 0;
		$this->max_depth   = $max_depth;
		$this->max_nodes   = $max_nodes;
		$this->unsafe_kind = '';
	}

	/**
	 * Parse one value.
	 *
	 * @param bool                                      $build     Whether safe arrays should be returned.
	 * @param array<int, string>                        $path      Current path.
	 * @param (\Closure(array, mixed, bool): void)|null $collector Selective path collector.
	 * @param int                                       $depth     Current container depth.
	 * @return mixed
	 * @throws SafeSerializedReaderException When the graph is malformed or exceeds a bound.
	 */
	private function parse_value( bool $build, array $path, ?\Closure $collector, int $depth ): mixed {
		++$this->nodes;
		if ( $this->nodes > $this->max_nodes ) {
			throw new SafeSerializedReaderException( 'serialized_graph_too_large' );
		}
		if ( $depth > $this->max_depth ) {
			throw new SafeSerializedReaderException( 'serialized_graph_too_deep' );
		}
		if ( $this->offset >= $this->length ) {
			throw new SafeSerializedReaderException( 'malformed_serialized_payload' );
		}

		$token = $this->input[ $this->offset ];
		if ( 'N' === $token ) {
			$this->expect( 'N;' );
			$this->collect( $collector, $path, null, false );
			return null;
		}

		return match ( $token ) {
			'b'     => $this->parse_boolean( $path, $collector ),
			'i'     => $this->parse_integer( $path, $collector ),
			'd'     => $this->parse_double( $path, $collector ),
			's'     => $this->parse_string( $path, $collector ),
			'a'     => $this->parse_array( $build, $path, $collector, $depth ),
			'O'     => $this->parse_object( $path, $collector, $depth ),
			'C'     => $this->parse_custom_object( $path, $collector ),
			'E'     => $this->parse_enum( $path, $collector ),
			'R', 'r' => $this->parse_reference( $path, $collector ),
			default => throw new SafeSerializedReaderException( 'malformed_serialized_payload' ),
		};
	}

	/**
	 * Parse a serialized boolean.
	 *
	 * @param array<int, string>                        $path      Current path.
	 * @param (\Closure(array, mixed, bool): void)|null $collector Selective path collector.
	 * @throws SafeSerializedReaderException When the value is malformed.
	 */
	private function parse_boolean( array $path, ?\Closure $collector ): bool {
		$this->expect( 'b:' );
		$value = $this->read_until( ';' );
		if ( '0' !== $value && '1' !== $value ) {
			throw new SafeSerializedReaderException( 'malformed_serialized_payload' );
		}
		$decoded = '1' === $value;
		$this->collect( $collector, $path, $decoded, false );
		return $decoded;
	}

	/**
	 * Parse a serialized integer.
	 *
	 * @param array<int, string>                        $path      Current path.
	 * @param (\Closure(array, mixed, bool): void)|null $collector Selective path collector.
	 * @throws SafeSerializedReaderException When the value is malformed.
	 */
	private function parse_integer( array $path, ?\Closure $collector ): int {
		$this->expect( 'i:' );
		$value = $this->read_until( ';' );
		if ( 1 !== preg_match( '/^-?\d+$/D', $value ) ) {
			throw new SafeSerializedReaderException( 'malformed_serialized_payload' );
		}
		$negative = str_starts_with( $value, '-' );
		$digits   = ltrim( $negative ? substr( $value, 1 ) : $value, '0' );
		$digits   = '' === $digits ? '0' : $digits;
		$limit    = $negative ? substr( (string) PHP_INT_MIN, 1 ) : (string) PHP_INT_MAX;
		if ( strlen( $digits ) > strlen( $limit ) || ( strlen( $digits ) === strlen( $limit ) && strcmp( $digits, $limit ) > 0 ) ) {
			throw new SafeSerializedReaderException( 'malformed_serialized_payload' );
		}
		$decoded = (int) $value;
		$this->collect( $collector, $path, $decoded, false );
		return $decoded;
	}

	/**
	 * Parse a serialized float.
	 *
	 * @param array<int, string>                        $path      Current path.
	 * @param (\Closure(array, mixed, bool): void)|null $collector Selective path collector.
	 * @throws SafeSerializedReaderException When the value is malformed.
	 */
	private function parse_double( array $path, ?\Closure $collector ): float {
		$this->expect( 'd:' );
		$value = $this->read_until( ';' );
		if ( ! is_numeric( $value ) ) {
			throw new SafeSerializedReaderException( 'malformed_serialized_payload' );
		}
		$decoded = (float) $value;
		if ( ! is_finite( $decoded ) ) {
			throw new SafeSerializedReaderException( 'malformed_serialized_payload' );
		}
		$this->collect( $collector, $path, $decoded, false );
		return $decoded;
	}

	/**
	 * Parse a byte-length-prefixed serialized string.
	 *
	 * @param array<int, string>                        $path      Current path.
	 * @param (\Closure(array, mixed, bool): void)|null $collector Selective path collector.
	 * @throws SafeSerializedReaderException When the value is malformed.
	 */
	private function parse_string( array $path, ?\Closure $collector ): string {
		$this->expect( 's:' );
		$byte_length = $this->read_length();
		$this->expect( '"' );
		if ( $byte_length > $this->length - $this->offset ) {
			throw new SafeSerializedReaderException( 'malformed_serialized_payload' );
		}
		$value         = substr( $this->input, $this->offset, $byte_length );
		$this->offset += $byte_length;
		$this->expect( '";' );
		$this->collect( $collector, $path, $value, false );
		return $value;
	}

	/**
	 * Parse a serialized array.
	 *
	 * @param bool                                      $build     Whether to retain safe children.
	 * @param array<int, string>                        $path      Current path.
	 * @param (\Closure(array, mixed, bool): void)|null $collector Selective path collector.
	 * @param int                                       $depth     Current container depth.
	 * @return array<mixed>
	 * @throws SafeSerializedReaderException When the value is malformed or exceeds a bound.
	 */
	private function parse_array( bool $build, array $path, ?\Closure $collector, int $depth ): array {
		$this->expect( 'a:' );
		$count = $this->read_length();
		$this->expect( '{' );
		$this->collect( $collector, $path, null, true );
		$value     = array();
		$seen_keys = array();
		for ( $index = 0; $index < $count; ++$index ) {
			$key = $this->parse_value( true, array(), null, $depth + 1 );
			if ( ! is_int( $key ) && ! is_string( $key ) ) {
				throw new SafeSerializedReaderException( 'malformed_serialized_payload' );
			}
			// PHP normalizes canonical numeric string array keys to integers. Use
			// the same key semantics for collision detection so a crafted row
			// cannot silently replace an earlier control value during decoding.
			if ( array_key_exists( $key, $seen_keys ) ) {
				throw new SafeSerializedReaderException( 'duplicate_serialized_key' );
			}
			$seen_keys[ $key ] = true;
			$child_path        = array_merge( $path, array( (string) $key ) );
			$child             = $this->parse_value( $build, $child_path, $collector, $depth + 1 );
			if ( $build ) {
				$value[ $key ] = $child;
			}
		}
		$this->expect( '}' );
		return $value;
	}

	/**
	 * Parse object properties without constructing the serialized class.
	 *
	 * @param array<int, string>                        $path      Current path.
	 * @param (\Closure(array, mixed, bool): void)|null $collector Selective path collector.
	 * @param int                                       $depth     Current container depth.
	 * @return null
	 * @throws SafeSerializedReaderException When the value is malformed or exceeds a bound.
	 */
	private function parse_object( array $path, ?\Closure $collector, int $depth ): mixed {
		$this->expect( 'O:' );
		$class_length = $this->read_length();
		$this->expect( '"' );
		$this->skip_bytes( $class_length );
		$this->expect( '":' );
		$property_count = $this->read_length();
		$this->expect( '{' );
		$this->unsafe_kind = '' === $this->unsafe_kind ? 'unsafe_serialized_object' : $this->unsafe_kind;
		$this->collect( $collector, $path, null, true );
		$seen_properties = array();
		for ( $index = 0; $index < $property_count; ++$index ) {
			$key = $this->parse_value( true, array(), null, $depth + 1 );
			if ( ! is_string( $key ) ) {
				throw new SafeSerializedReaderException( 'malformed_serialized_payload' );
			}
			$property = $this->normalize_property_name( $key );
			if ( isset( $seen_properties[ $property ] ) ) {
				throw new SafeSerializedReaderException( 'duplicate_serialized_key' );
			}
			$seen_properties[ $property ] = true;
			$this->parse_value( false, array_merge( $path, array( $property ) ), $collector, $depth + 1 );
		}
		$this->expect( '}' );
		return null;
	}

	/**
	 * Skip a custom-serialized object payload without constructing it.
	 *
	 * @param array<int, string>                        $path      Current path.
	 * @param (\Closure(array, mixed, bool): void)|null $collector Selective path collector.
	 * @return null
	 * @throws SafeSerializedReaderException When the value is malformed.
	 */
	private function parse_custom_object( array $path, ?\Closure $collector ): mixed {
		$this->expect( 'C:' );
		$class_length = $this->read_length();
		$this->expect( '"' );
		$this->skip_bytes( $class_length );
		$this->expect( '":' );
		$payload_length = $this->read_length();
		$this->expect( '{' );
		$this->skip_bytes( $payload_length );
		$this->expect( '}' );
		$this->unsafe_kind = '' === $this->unsafe_kind ? 'unsafe_serialized_object' : $this->unsafe_kind;
		$this->collect( $collector, $path, null, true );
		return null;
	}

	/**
	 * Skip a serialized enum without loading its class.
	 *
	 * @param array<int, string>                        $path      Current path.
	 * @param (\Closure(array, mixed, bool): void)|null $collector Selective path collector.
	 * @return null
	 * @throws SafeSerializedReaderException When the value is malformed.
	 */
	private function parse_enum( array $path, ?\Closure $collector ): mixed {
		$this->expect( 'E:' );
		$length = $this->read_length();
		$this->expect( '"' );
		$this->skip_bytes( $length );
		$this->expect( '";' );
		$this->unsafe_kind = '' === $this->unsafe_kind ? 'unsafe_serialized_object' : $this->unsafe_kind;
		$this->collect( $collector, $path, null, true );
		return null;
	}

	/**
	 * Consume but reject a serialized graph reference.
	 *
	 * @param array<int, string>                        $path      Current path.
	 * @param (\Closure(array, mixed, bool): void)|null $collector Selective path collector.
	 * @throws SafeSerializedReaderException When the value is malformed.
	 */
	private function parse_reference( array $path, ?\Closure $collector ): never {
		unset( $path, $collector );
		throw new SafeSerializedReaderException( 'serialized_reference' );
	}

	/**
	 * Read a non-negative serialized length field.
	 *
	 * @throws SafeSerializedReaderException When the length is malformed.
	 */
	private function read_length(): int {
		$value  = $this->read_until( ':' );
		$length = CanonicalInteger::parse( $value );
		if ( null === $length ) {
			throw new SafeSerializedReaderException( 'malformed_serialized_payload' );
		}
		return $length;
	}

	/**
	 * Read bytes through a required delimiter.
	 *
	 * @param string $delimiter Required delimiter.
	 * @throws SafeSerializedReaderException When the delimiter is absent.
	 */
	private function read_until( string $delimiter ): string {
		$position = strpos( $this->input, $delimiter, $this->offset );
		if ( false === $position ) {
			throw new SafeSerializedReaderException( 'malformed_serialized_payload' );
		}
		$value        = substr( $this->input, $this->offset, $position - $this->offset );
		$this->offset = $position + strlen( $delimiter );
		return $value;
	}

	/**
	 * Advance over an opaque, bounded byte payload.
	 *
	 * @param int $length Byte count.
	 * @throws SafeSerializedReaderException When the payload exceeds the input.
	 */
	private function skip_bytes( int $length ): void {
		if ( $length < 0 || $length > $this->length - $this->offset ) {
			throw new SafeSerializedReaderException( 'malformed_serialized_payload' );
		}
		$this->offset += $length;
	}

	/**
	 * Consume an exact grammar literal.
	 *
	 * @param string $literal Expected bytes.
	 * @throws SafeSerializedReaderException When the bytes do not match.
	 */
	private function expect( string $literal ): void {
		$length = strlen( $literal );
		if ( substr( $this->input, $this->offset, $length ) !== $literal ) {
			throw new SafeSerializedReaderException( 'malformed_serialized_payload' );
		}
		$this->offset += $length;
	}

	/**
	 * Require that the parser consumed the complete input.
	 *
	 * @throws SafeSerializedReaderException When trailing bytes remain.
	 */
	private function require_complete(): void {
		if ( $this->offset !== $this->length ) {
			throw new SafeSerializedReaderException( 'malformed_serialized_payload' );
		}
	}

	/**
	 * Match WordPress' strict serialized-value envelope before parsing.
	 *
	 * @param string $value Candidate value.
	 */
	private function looks_serialized( string $value ): bool {
		$value = trim( $value );
		if ( 'N;' === $value ) {
			return true;
		}
		$length = strlen( $value );
		if ( $length < 4 || ':' !== $value[1] || ( ';' !== $value[ $length - 1 ] && '}' !== $value[ $length - 1 ] ) ) {
			return false;
		}

		return match ( $value[0] ) {
			's'     => 1 === preg_match( '/^s:\d+:".*";$/sD', $value ),
			'a', 'O', 'E' => 1 === preg_match( '/^[aOE]:\d+:/sD', $value ),
			'b', 'i', 'R', 'r' => 1 === preg_match( '/^[biRr]:[0-9.E+\-]+;$/sD', $value ),
			'd'     => 1 === preg_match( '/^d:(?:[0-9.E+\-]+|INF|-INF|NAN);$/sD', $value ),
			'C'     => 1 === preg_match( '/^C:\d+:".*";?$/sD', $value ) || str_ends_with( $value, '}' ),
			default => false,
		};
	}

	/**
	 * Deliver one selected scalar or container marker.
	 *
	 * @param (\Closure(array, mixed, bool): void)|null $collector    Selective path collector.
	 * @param array<int, string>                        $path         Current path.
	 * @param mixed                                     $value        Decoded value.
	 * @param bool                                      $is_container Whether the value is a container marker.
	 */
	private function collect( ?\Closure $collector, array $path, mixed $value, bool $is_container ): void {
		if ( null !== $collector ) {
			$collector( $path, $value, $is_container );
		}
	}

	/**
	 * Normalize PHP's protected/private serialized property key syntax.
	 *
	 * @param string $name Serialized property name.
	 */
	private function normalize_property_name( string $name ): string {
		$position = strrpos( $name, "\0" );
		return false === $position ? $name : substr( $name, $position + 1 );
	}
}
