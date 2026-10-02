<?php
/**
 * Proxy-aware REST client IP resolution.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\REST;

/** Resolve a client IP without trusting forwarded headers by default. */
final class ClientIpResolver {

	private const MAX_TRUSTED_CIDRS  = 32;
	private const MAX_FORWARDED_HOPS = 10;
	private const MAX_HEADER_BYTES   = 1024;

	/**
	 * Create a resolver with an explicit test seam or filtered runtime config.
	 *
	 * @param string[]|null $trusted_cidrs Explicit trusted proxies, or null to use the filter.
	 */
	public function __construct( private readonly ?array $trusted_cidrs = null ) {}

	/**
	 * Resolve the immediate peer, or a strictly parsed forwarded chain when the
	 * immediate peer is an explicitly trusted proxy.
	 *
	 * @param array<string, mixed>|null $server Optional test/server seam.
	 * @throws \InvalidArgumentException When trusted-proxy configuration is invalid.
	 */
	public function resolve( ?array $server = null ): string {
		$server = $server ?? $_SERVER;
		$remote = $this->canonical_ip( $server['REMOTE_ADDR'] ?? null );
		if ( '' === $remote ) {
			return '';
		}

		$trusted = $this->trusted_networks();
		if ( ! $this->belongs_to_any( $remote, $trusted ) ) {
			return $remote;
		}

		$forwarded = $server['HTTP_X_FORWARDED_FOR'] ?? '';
		if ( ! is_string( $forwarded ) || '' === $forwarded || strlen( $forwarded ) > self::MAX_HEADER_BYTES ) {
			return '';
		}

		$parts = explode( ',', $forwarded );
		if ( count( $parts ) > self::MAX_FORWARDED_HOPS ) {
			return '';
		}

		$chain = array();
		foreach ( $parts as $part ) {
			if ( trim( $part ) !== $part && '' === trim( $part ) ) {
				return '';
			}
			$ip = $this->canonical_ip( trim( $part ) );
			if ( '' === $ip ) {
				return '';
			}
			$chain[] = $ip;
		}
		$chain[] = $remote;

		for ( $index = count( $chain ) - 1; $index >= 0; --$index ) {
			if ( ! $this->belongs_to_any( $chain[ $index ], $trusted ) ) {
				return $chain[ $index ];
			}
		}

		return $chain[0] ?? '';
	}

	/**
	 * Parse the bounded trusted-proxy CIDR configuration.
	 *
	 * @return array<int, array{network: string, prefix: int}>
	 * @throws \InvalidArgumentException When trusted-proxy configuration is invalid.
	 */
	private function trusted_networks(): array {
		$cidrs = $this->trusted_cidrs;
		if ( null === $cidrs ) {
			$cidrs = function_exists( 'apply_filters' )
				? apply_filters( 'cartpops_rest_trusted_proxy_cidrs', array() )
				: array();
		}

		if ( ! is_array( $cidrs ) || count( $cidrs ) > self::MAX_TRUSTED_CIDRS ) {
			throw new \InvalidArgumentException( 'Invalid CartPops trusted-proxy configuration.' );
		}

		$networks = array();
		foreach ( $cidrs as $cidr ) {
			if ( ! is_string( $cidr ) || strlen( $cidr ) > 64 || 1 !== substr_count( $cidr, '/' ) ) {
				throw new \InvalidArgumentException( 'Invalid CartPops trusted-proxy configuration.' );
			}
			[ $address, $prefix_text ] = explode( '/', $cidr, 2 );
			if ( '' === $prefix_text || 1 !== preg_match( '/^(0|[1-9][0-9]{0,2})$/D', $prefix_text ) ) {
				throw new \InvalidArgumentException( 'Invalid CartPops trusted-proxy configuration.' );
			}
			if ( false === filter_var( $address, FILTER_VALIDATE_IP ) ) {
				throw new \InvalidArgumentException( 'Invalid CartPops trusted-proxy configuration.' );
			}
			$binary = inet_pton( $address );
			if ( false === $binary ) {
				throw new \InvalidArgumentException( 'Invalid CartPops trusted-proxy configuration.' );
			}
			$prefix = (int) $prefix_text;
			$bits   = 4 === strlen( $binary ) ? 32 : 128;
			if ( 0 === $prefix || $prefix > $bits ) {
				throw new \InvalidArgumentException( 'Invalid CartPops trusted-proxy configuration.' );
			}
			$networks[] = array(
				'network' => $binary,
				'prefix'  => $prefix,
			);
		}

		return $networks;
	}

	/**
	 * Test whether an IP belongs to any configured network.
	 *
	 * @param string                                          $ip       Canonical IP address.
	 * @param array<int, array{network: string, prefix: int}> $networks Parsed networks.
	 */
	private function belongs_to_any( string $ip, array $networks ): bool {
		$binary = inet_pton( $ip );
		if ( false === $binary ) {
			return false;
		}

		foreach ( $networks as $network ) {
			if ( strlen( $binary ) === strlen( $network['network'] ) && $this->prefix_matches( $binary, $network['network'], $network['prefix'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Compare the leading CIDR bits of two packed addresses.
	 *
	 * @param string $address Packed candidate address.
	 * @param string $network Packed network address.
	 * @param int    $prefix  CIDR prefix length.
	 */
	private function prefix_matches( string $address, string $network, int $prefix ): bool {
		$bytes = intdiv( $prefix, 8 );
		$bits  = $prefix % 8;
		if ( $bytes > 0 && substr( $address, 0, $bytes ) !== substr( $network, 0, $bytes ) ) {
			return false;
		}
		if ( 0 === $bits ) {
			return true;
		}

		$mask = ( 0xff << ( 8 - $bits ) ) & 0xff;
		return ( ord( $address[ $bytes ] ) & $mask ) === ( ord( $network[ $bytes ] ) & $mask );
	}

	/**
	 * Return one canonical IPv4/IPv6 string or an empty invalid sentinel.
	 *
	 * @param mixed $value Candidate server value.
	 */
	private function canonical_ip( mixed $value ): string {
		if (
			! is_string( $value )
			|| '' === $value
			|| trim( $value ) !== $value
			|| strlen( $value ) > 45
			|| false === filter_var( $value, FILTER_VALIDATE_IP )
		) {
			return '';
		}
		$binary = inet_pton( $value );
		if ( false === $binary ) {
			return '';
		}
		$canonical = inet_ntop( $binary );
		return false === $canonical ? '' : strtolower( $canonical );
	}
}
