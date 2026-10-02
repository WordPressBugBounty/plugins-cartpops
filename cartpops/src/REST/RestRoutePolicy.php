<?php
/**
 * Declarative policy for one REST method handler.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\REST;

/** Immutable policy for one exact HTTP method and route path. */
final class RestRoutePolicy {
	/**
	 * Exact credentialed browser-origin policy.
	 *
	 * @var RestCorsPolicy
	 */
	public readonly RestCorsPolicy $cors_policy;

	/**
	 * Response cache policy.
	 *
	 * @var RestCachePolicy
	 */
	public readonly RestCachePolicy $cache_policy;

	/**
	 * JSONP handling policy.
	 *
	 * @var RestJsonpPolicy
	 */
	public readonly RestJsonpPolicy $jsonp_policy;

	/**
	 * Create one validated-by-registry route policy definition.
	 *
	 * @param string         $method          Exact HTTP method.
	 * @param string         $path            Exact namespace route path.
	 * @param RestRouteClass $route_class     Authority/cache classification.
	 * @param RestEdition    $edition         Edition ownership classification.
	 * @param int            $raw_body_cap    Maximum raw request-body bytes.
	 * @param string|null    $rate_bucket     Declared limiter bucket.
	 * @param int            $rate_limit      Maximum requests per window.
	 * @param int            $rate_window     Fixed-window length in seconds.
	 * @param string[]       $rate_dimensions Required independent limiter dimensions.
	 * @param bool           $rate_wired      Whether this route consumes its declaration today.
	 */
	public function __construct(
		public readonly string $method,
		public readonly string $path,
		public readonly RestRouteClass $route_class,
		public readonly RestEdition $edition,
		public readonly int $raw_body_cap,
		public readonly ?string $rate_bucket = null,
		public readonly int $rate_limit = 0,
		public readonly int $rate_window = 0,
		public readonly array $rate_dimensions = array(),
		public readonly bool $rate_wired = false
	) {
		$this->cors_policy  = RestCorsPolicy::SAME_SITE_CREDENTIALS;
		$this->cache_policy = RestRouteClass::GLOBAL_READ === $route_class
			? RestCachePolicy::PUBLIC
			: RestCachePolicy::PRIVATE_NO_STORE;
		$this->jsonp_policy = RestJsonpPolicy::REJECT;
	}

	/** Return the unique registry key. */
	public function key(): string {
		return $this->method . ' ' . $this->path;
	}

	/** Whether responses require private, non-cacheable treatment. */
	public function is_private(): bool {
		return RestCachePolicy::PRIVATE_NO_STORE === $this->cache_policy;
	}
}
