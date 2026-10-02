<?php
/**
 * Immutable complete CartPops REST route policy set.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\REST;

/** Own the closed 12-shared/12-Pro policy manifest without process-global state. */
final class RestRoutePolicySet {
	public const ROUTE_ROOT = '/cartpops/v1';

	private const EXPECTED_HANDLER_COUNT = 24;
	private const EXPECTED_SHARED_COUNT  = 12;
	private const EXPECTED_PRO_COUNT     = 12;

	/**
	 * Exact keyed policy manifest.
	 *
	 * @var array<string, RestRoutePolicy>
	 */
	private readonly array $policies;

	/**
	 * Shared route policies.
	 *
	 * @var RestRoutePolicy[]
	 */
	private readonly array $shared_policies;

	/**
	 * Physically paid route policies.
	 *
	 * @var RestRoutePolicy[]
	 */
	private readonly array $pro_policies;

	/**
	 * Build the one fixed product manifest.
	 *
	 * @throws \LogicException When the closed edition split is incomplete.
	 */
	private function __construct() {
		$policies = self::build_registry( self::definitions(), true );
		$shared   = array_values(
			array_filter( $policies, static fn( RestRoutePolicy $policy ): bool => RestEdition::SHARED === $policy->edition )
		);
		$pro      = array_values(
			array_filter( $policies, static fn( RestRoutePolicy $policy ): bool => RestEdition::PRO === $policy->edition )
		);
		if ( self::EXPECTED_SHARED_COUNT !== count( $shared ) || self::EXPECTED_PRO_COUNT !== count( $pro ) ) {
			throw new \LogicException( 'CartPops REST edition policy set is incomplete.' );
		}

		$this->policies        = $policies;
		$this->shared_policies = $shared;
		$this->pro_policies    = $pro;
	}

	/** Return a fresh immutable product policy set. */
	public static function production(): self {
		return new self();
	}

	/**
	 * Validate an arbitrary definition set for compatibility callers and tooling.
	 *
	 * @param RestRoutePolicy[] $definitions Definitions to validate.
	 * @return array<string, RestRoutePolicy>
	 */
	public static function validate_definitions( array $definitions ): array {
		return self::build_registry( $definitions, false );
	}

	/**
	 * Return the exact keyed policy manifest.
	 *
	 * @return array<string, RestRoutePolicy>
	 */
	public function all(): array {
		return $this->policies;
	}

	/**
	 * Return exact shared route policies.
	 *
	 * @return RestRoutePolicy[]
	 */
	public function shared(): array {
		return $this->shared_policies;
	}

	/**
	 * Return exact physically paid route policies.
	 *
	 * @return RestRoutePolicy[]
	 */
	public function pro(): array {
		return $this->pro_policies;
	}

	/**
	 * Find an exact policy, treating HEAD as its GET handler.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Exact namespace route path.
	 */
	public function find( string $method, string $path ): ?RestRoutePolicy {
		$method = strtoupper( $method );
		if ( 'HEAD' === $method ) {
			$method = 'GET';
		}

		return $this->policies[ $method . ' ' . $path ] ?? null;
	}

	/**
	 * Return policies declared for one exact path.
	 *
	 * @param string $path Exact namespace route path.
	 * @return RestRoutePolicy[]
	 */
	public function for_path( string $path ): array {
		return array_values(
			array_filter(
				$this->policies,
				static fn( RestRoutePolicy $policy ): bool => $path === $policy->path
			)
		);
	}

	/** Return any rate declarations that violate the production wiring invariant. */
	public function declared_but_unwired_rates(): array {
		return array_values(
			array_filter(
				$this->policies,
				static fn( RestRoutePolicy $policy ): bool => null !== $policy->rate_bucket && ! $policy->rate_wired
			)
		);
	}

	/**
	 * Return exact fixed route definitions.
	 *
	 * @return RestRoutePolicy[]
	 */
	private static function definitions(): array {
		$both = array( RateLimiter::DIMENSION_IP, RateLimiter::DIMENSION_IDENTITY );

		return array(
			self::route( 'GET', '/cartpops/v1/settings', RestRouteClass::ADMIN, RestEdition::SHARED ),
			self::rate( 'POST', '/cartpops/v1/settings', RestRouteClass::ADMIN, RestEdition::SHARED, 262144, 'settings_update', 30, 60, $both ),
			self::rate( 'DELETE', '/cartpops/v1/settings', RestRouteClass::ADMIN, RestEdition::SHARED, 0, 'settings_reset', 5, 3600, $both ),
			self::route( 'GET', '/cartpops/v1/settings/export', RestRouteClass::ADMIN, RestEdition::SHARED ),
			self::rate( 'POST', '/cartpops/v1/settings/import', RestRouteClass::ADMIN, RestEdition::SHARED, 1048576, 'settings_import', 5, 3600, $both ),

			self::rate( 'POST', '/cartpops/v1/analytics/events', RestRouteClass::MUTATION, RestEdition::PRO, 4096, 'analytics_events', 60, 60, $both, true ),
			self::rate( 'DELETE', '/cartpops/v1/analytics/reset', RestRouteClass::ADMIN, RestEdition::PRO, 0, 'analytics_reset', 5, 3600, $both, true ),
			self::route( 'GET', '/cartpops/v1/analytics/summary', RestRouteClass::ADMIN, RestEdition::PRO ),

			self::route( 'GET', '/cartpops/v1/bundle-builder/companions', RestRouteClass::SESSION_READ, RestEdition::PRO ),
			self::route( 'GET', '/cartpops/v1/bundle-builder/configs', RestRouteClass::GLOBAL_READ, RestEdition::PRO ),
			self::rate( 'POST', '/cartpops/v1/bundle-builder/add', RestRouteClass::MUTATION, RestEdition::PRO, 32768, 'bundle_add', 20, 60, $both ),

			self::route( 'GET', '/cartpops/v1/products/search', RestRouteClass::ADMIN, RestEdition::PRO ),
			self::route( 'GET', '/cartpops/v1/conditions/types', RestRouteClass::ADMIN, RestEdition::PRO ),
			self::rate( 'POST', '/cartpops/v1/spotlight/add', RestRouteClass::MUTATION, RestEdition::PRO, 16384, 'spotlight_add', 20, 60, $both ),

			self::route( 'GET', '/cartpops/v1/cart', RestRouteClass::SESSION_READ, RestEdition::SHARED ),
			self::rate( 'POST', '/cartpops/v1/cart/remove-items', RestRouteClass::MUTATION, RestEdition::SHARED, 32768, 'cart_remove_items', 30, 60, $both ),
			self::rate( 'POST', '/cartpops/v1/coupon', RestRouteClass::MUTATION, RestEdition::SHARED, 8192, 'coupon_apply', 10, 60, $both ),
			self::rate( 'DELETE', '/cartpops/v1/coupon', RestRouteClass::MUTATION, RestEdition::SHARED, 8192, 'coupon_remove', 20, 60, $both ),
			self::route( 'GET', '/cartpops/v1/drawer-data', RestRouteClass::SESSION_READ, RestEdition::SHARED ),

			self::route( 'GET', '/cartpops/v1/recommendations', RestRouteClass::SESSION_READ, RestEdition::SHARED ),
			self::rate( 'POST', '/cartpops/v1/recommendations/add', RestRouteClass::MUTATION, RestEdition::SHARED, 8192, 'recommendation_add', 20, 60, $both ),
			self::route( 'GET', '/cartpops/v1/shipping-meter', RestRouteClass::SESSION_READ, RestEdition::PRO ),
			self::route( 'GET', '/cartpops/v1/smart-addons', RestRouteClass::SESSION_READ, RestEdition::PRO ),
			self::rate( 'POST', '/cartpops/v1/smart-addons/toggle', RestRouteClass::MUTATION, RestEdition::PRO, 8192, 'smart_addon_toggle', 30, 60, $both ),
		);
	}

	/**
	 * Build one policy without limiter intent.
	 *
	 * @param string         $method      Exact HTTP method.
	 * @param string         $path        Exact namespace route path.
	 * @param RestRouteClass $route_class Authority/cache classification.
	 * @param RestEdition    $edition     Physical edition ownership.
	 * @param int            $body_cap    Maximum raw request-body bytes.
	 */
	private static function route(
		string $method,
		string $path,
		RestRouteClass $route_class,
		RestEdition $edition,
		int $body_cap = 0
	): RestRoutePolicy {
		return new RestRoutePolicy( $method, $path, $route_class, $edition, $body_cap );
	}

	/**
	 * Build one policy with limiter intent.
	 *
	 * @param string         $method      Exact HTTP method.
	 * @param string         $path        Exact namespace route path.
	 * @param RestRouteClass $route_class Authority/cache classification.
	 * @param RestEdition    $edition     Physical edition ownership.
	 * @param int            $body_cap    Maximum raw request-body bytes.
	 * @param string         $bucket      Limiter bucket.
	 * @param int            $limit       Maximum requests per window.
	 * @param int            $window      Fixed-window seconds.
	 * @param string[]       $dimensions  Independent required dimensions.
	 * @param bool           $wired       Whether production consumes the declaration.
	 */
	private static function rate(
		string $method,
		string $path,
		RestRouteClass $route_class,
		RestEdition $edition,
		int $body_cap,
		string $bucket,
		int $limit,
		int $window,
		array $dimensions,
		bool $wired = true
	): RestRoutePolicy {
		return new RestRoutePolicy(
			$method,
			$path,
			$route_class,
			$edition,
			$body_cap,
			$bucket,
			$limit,
			$window,
			$dimensions,
			$wired
		);
	}

	/**
	 * Build and fail-closed validate a definition set.
	 *
	 * @param RestRoutePolicy[] $definitions Definition list.
	 * @param bool              $require_complete Require the production handler count.
	 * @return array<string, RestRoutePolicy>
	 * @throws \LogicException When a definition is invalid, duplicated, or incomplete.
	 */
	private static function build_registry( array $definitions, bool $require_complete ): array {
		$registry = array();
		foreach ( $definitions as $definition ) {
			if ( ! $definition instanceof RestRoutePolicy ) {
				throw new \LogicException( 'CartPops REST policy contains an invalid definition.' );
			}
			if ( ! in_array( $definition->method, array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
				throw new \LogicException( 'CartPops REST policy contains an unknown HTTP method.' );
			}
			if (
				1 !== preg_match( '#^' . preg_quote( self::ROUTE_ROOT, '#' ) . '/[a-z0-9/-]+$#D', $definition->path )
				|| $definition->raw_body_cap < 0
				|| $definition->raw_body_cap > 1048576
			) {
				throw new \LogicException( 'CartPops REST policy contains an invalid route or body cap.' );
			}
			if (
				( null === $definition->rate_bucket && ( 0 !== $definition->rate_limit || 0 !== $definition->rate_window || array() !== $definition->rate_dimensions || $definition->rate_wired ) )
				|| ( null !== $definition->rate_bucket && ( $definition->rate_limit < 1 || $definition->rate_window < 1 || array() === $definition->rate_dimensions ) )
			) {
				throw new \LogicException( 'CartPops REST policy contains an invalid rate declaration.' );
			}
			$key = $definition->key();
			if ( isset( $registry[ $key ] ) ) {
				throw new \LogicException( 'CartPops REST policy contains a duplicate method handler.' );
			}
			$registry[ $key ] = $definition;
		}

		if ( $require_complete && self::EXPECTED_HANDLER_COUNT !== count( $registry ) ) {
			throw new \LogicException( 'CartPops REST policy is incomplete.' );
		}
		ksort( $registry, SORT_STRING );

		return $registry;
	}
}
