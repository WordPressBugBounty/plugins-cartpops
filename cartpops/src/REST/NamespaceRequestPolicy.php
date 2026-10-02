<?php
/**
 * Closed CartPops REST namespace request policy.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\REST;

/** Backward-compatible static facade over the immutable route policy authority. */
final class NamespaceRequestPolicy {

	public const ROUTE_ROOT = RestRoutePolicySet::ROUTE_ROOT;

	/**
	 * Return the validated production policy map.
	 *
	 * @return array<string, RestRoutePolicy> Map of "METHOD /path" to policy.
	 */
	public static function registry(): array {
		return RestRoutePolicySet::production()->all();
	}

	/**
	 * Validate an arbitrary definition set, used by manifest tests and tooling.
	 *
	 * @param RestRoutePolicy[] $definitions Definitions to validate.
	 * @return array<string, RestRoutePolicy>
	 */
	public static function validate_definitions( array $definitions ): array {
		return RestRoutePolicySet::validate_definitions( $definitions );
	}

	/**
	 * Find the exact route policy, treating HEAD as its GET handler.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Exact namespace route path.
	 */
	public static function find( string $method, string $path ): ?RestRoutePolicy {
		return RestRoutePolicySet::production()->find( $method, $path );
	}

	/**
	 * Return policies declared for one exact path.
	 *
	 * @param string $path Exact namespace route path.
	 * @return RestRoutePolicy[]
	 */
	public static function for_path( string $path ): array {
		return RestRoutePolicySet::production()->for_path( $path );
	}

	/** Return any rate declarations that violate the production wiring invariant. */
	public static function declared_but_unwired_rates(): array {
		return RestRoutePolicySet::production()->declared_but_unwired_rates();
	}
}
