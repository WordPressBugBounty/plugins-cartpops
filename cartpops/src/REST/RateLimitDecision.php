<?php
/**
 * Value object returned by REST rate limits.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\REST;

/** Immutable typed result with bounded, response-safe metadata. */
final class RateLimitDecision {

	/**
	 * Create a typed rate-limit decision.
	 *
	 * @param RateLimitStatus $status      Outcome category.
	 * @param int             $limit       Configured request limit.
	 * @param int             $remaining   Tightest remaining capacity.
	 * @param int             $retry_after Bounded seconds until every dimension permits a request.
	 */
	public function __construct(
		public readonly RateLimitStatus $status,
		public readonly int $limit = 0,
		public readonly int $remaining = 0,
		public readonly int $retry_after = 0
	) {}

	/**
	 * Return an allowed decision with aggregate capacity metadata.
	 *
	 * @param int $limit     Configured request limit.
	 * @param int $remaining Tightest remaining capacity.
	 */
	public static function allowed( int $limit, int $remaining = 0 ): self {
		return new self( RateLimitStatus::ALLOWED, $limit, max( 0, $remaining ) );
	}

	/**
	 * Return an exhausted decision with bounded response metadata.
	 *
	 * @param int $limit       Configured request limit.
	 * @param int $retry_after Seconds until every dimension permits a request.
	 */
	public static function exhausted( int $limit, int $retry_after ): self {
		return new self( RateLimitStatus::EXHAUSTED, $limit, 0, max( 1, min( 86400, $retry_after ) ) );
	}

	/** Return a persistence-unavailable decision. */
	public static function store_unavailable(): self {
		return new self( RateLimitStatus::STORE_UNAVAILABLE );
	}

	/** Return an invalid-configuration decision. */
	public static function invalid_configuration(): self {
		return new self( RateLimitStatus::INVALID_CONFIGURATION );
	}

	/** Return a missing-required-identity decision. */
	public static function missing_identity(): self {
		return new self( RateLimitStatus::MISSING_IDENTITY );
	}

	/** Whether the request may proceed. */
	public function is_allowed(): bool {
		return RateLimitStatus::ALLOWED === $this->status;
	}

	/**
	 * Return only bounded public rate metadata; no bucket or identity values.
	 *
	 * @return array<string, string>
	 */
	public function response_headers(): array {
		if ( RateLimitStatus::EXHAUSTED !== $this->status ) {
			return array();
		}

		return array(
			'Retry-After'           => (string) $this->retry_after,
			'X-RateLimit-Limit'     => (string) $this->limit,
			'X-RateLimit-Remaining' => '0',
		);
	}
}
