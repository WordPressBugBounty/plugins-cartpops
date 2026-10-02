<?php
/**
 * Public REST fixed-window rate limiter.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\REST;

/** Enforce independent privacy-hashed identity and client-IP dimensions. */
final class RateLimiter {

	public const DIMENSION_IDENTITY = 'identity';
	public const DIMENSION_IP       = 'ip';

	private const MAX_LIMIT  = 10000;
	private const MAX_WINDOW = 86400;

	/**
	 * Verified identity resolver.
	 *
	 * @var \Closure(): string
	 */
	private \Closure $identity_resolver;

	/**
	 * Trusted-proxy-aware client IP resolver.
	 *
	 * @var \Closure(): string
	 */
	private \Closure $ip_resolver;

	/**
	 * Unix timestamp resolver.
	 *
	 * @var \Closure(): mixed
	 */
	private \Closure $clock;

	/**
	 * Site-secret resolver.
	 *
	 * @var \Closure(): string
	 */
	private \Closure $salt_resolver;

	/**
	 * Value-free diagnostic logger.
	 *
	 * @var \Closure(string): void
	 */
	private \Closure $logger;

	/**
	 * Per-request exactly-once decision cache.
	 *
	 * @var \WeakMap<object, array<string, RateLimitDecision>>
	 */
	private \WeakMap $request_decisions;

	/**
	 * Create a typed limiter around explicit persistence and identity boundaries.
	 *
	 * @param RateLimitStore                $store             Atomic persistence boundary.
	 * @param (callable(): string)|null     $identity_resolver Verified user/Woo session resolver.
	 * @param (callable(): string)|null     $ip_resolver       Trusted-proxy-aware IP resolver.
	 * @param (callable(): mixed)|null      $clock             Unix timestamp resolver.
	 * @param (callable(): string)|null     $salt_resolver     Site-secret resolver for HMAC keys.
	 * @param (callable(string): void)|null $logger            Value-free diagnostic logger.
	 */
	public function __construct(
		private readonly RateLimitStore $store = new AtomicRateLimitStore(),
		?callable $identity_resolver = null,
		?callable $ip_resolver = null,
		?callable $clock = null,
		?callable $salt_resolver = null,
		?callable $logger = null
	) {
		$this->identity_resolver = \Closure::fromCallable(
			$identity_resolver ?? array( self::class, 'current_identity' )
		);
		$this->ip_resolver       = \Closure::fromCallable(
			$ip_resolver ?? static fn(): string => ( new ClientIpResolver() )->resolve()
		);
		$this->clock             = \Closure::fromCallable( $clock ?? 'time' );
		$this->salt_resolver     = \Closure::fromCallable(
			$salt_resolver ?? static fn(): string => function_exists( 'wp_salt' ) ? (string) wp_salt( 'nonce' ) : ''
		);
		$this->logger            = \Closure::fromCallable(
			$logger ?? static function ( string $code ): void {
				error_log( 'CartPops REST rate limit: ' . $code ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Value-free operational diagnostic for a fail-closed security boundary.
			}
		);
		$this->request_decisions = new \WeakMap();
	}

	/**
	 * Temporary boolean adapter for controllers outside this assignment. New
	 * route code must consume the typed decision instead.
	 *
	 * @param string $bucket Stable route bucket.
	 * @param int    $limit  Maximum requests per window.
	 * @param int    $window Fixed-window length in seconds.
	 */
	public static function check( string $bucket, int $limit, int $window ): bool {
		return ( new self() )->allow( $bucket, $limit, $window )->is_allowed();
	}

	/**
	 * Consume all required dimensions once.
	 *
	 * @param string   $bucket     Stable route bucket.
	 * @param int      $limit      Maximum requests per window.
	 * @param int      $window     Fixed-window length in seconds.
	 * @param string[] $dimensions Required dimensions.
	 */
	public function allow(
		string $bucket,
		int $limit,
		int $window,
		array $dimensions = array( self::DIMENSION_IP, self::DIMENSION_IDENTITY )
	): RateLimitDecision {
		if ( ! $this->valid_configuration( $bucket, $limit, $window, $dimensions ) ) {
			return $this->diagnostic( RateLimitDecision::invalid_configuration() );
		}

		try {
			$identity = in_array( self::DIMENSION_IDENTITY, $dimensions, true )
				? ( $this->identity_resolver )()
				: '';
			$ip       = in_array( self::DIMENSION_IP, $dimensions, true )
				? ( $this->ip_resolver )()
				: '';
			$salt     = ( $this->salt_resolver )();
		} catch ( \InvalidArgumentException ) {
			return $this->diagnostic( RateLimitDecision::invalid_configuration() );
		} catch ( \Throwable ) {
			return $this->diagnostic( RateLimitDecision::store_unavailable() );
		}

		if (
			( in_array( self::DIMENSION_IDENTITY, $dimensions, true ) && ! $this->valid_identity( $identity ) )
			|| ( in_array( self::DIMENSION_IP, $dimensions, true ) && ! $this->valid_ip( $ip ) )
		) {
			return $this->diagnostic( RateLimitDecision::missing_identity() );
		}
		if ( ! is_string( $salt ) || strlen( $salt ) < 32 || strlen( $salt ) > 4096 ) {
			return $this->diagnostic( RateLimitDecision::invalid_configuration() );
		}

		$values = array(
			self::DIMENSION_IP       => $ip,
			self::DIMENSION_IDENTITY => $identity,
		);
		try {
			$now = ( $this->clock )();
		} catch ( \Throwable ) {
			return $this->diagnostic( RateLimitDecision::invalid_configuration() );
		}
		if ( ! is_int( $now ) || $now < 1 ) {
			return $this->diagnostic( RateLimitDecision::invalid_configuration() );
		}

		$keys = array();
		foreach ( $dimensions as $dimension ) {
			$prefix = self::DIMENSION_IP === $dimension ? 'p' : 'i';
			$keys[] = 'cartpops_' . $prefix . '_' . hash_hmac(
				'sha256',
				$bucket . '|' . $dimension . '|' . $values[ $dimension ],
				$salt
			);
		}

		try {
			$decision = $this->store->consume_all( $keys, $limit, $window, $now );
		} catch ( \Throwable ) {
			return $this->diagnostic( RateLimitDecision::store_unavailable() );
		}
		return $decision->is_allowed() || RateLimitStatus::EXHAUSTED === $decision->status
			? $decision
			: $this->diagnostic( $decision );
	}

	/**
	 * Memoize a decision on the request so repeated framework evaluation cannot
	 * consume quota more than once.
	 *
	 * @param object   $request    WP_REST_Request at runtime; object keeps this testable.
	 * @param string   $bucket     Stable route bucket.
	 * @param int      $limit      Maximum requests per window.
	 * @param int      $window     Fixed-window length in seconds.
	 * @param string[] $dimensions Required dimensions.
	 */
	public function allow_once(
		object $request,
		string $bucket,
		int $limit,
		int $window,
		array $dimensions = array( self::DIMENSION_IP, self::DIMENSION_IDENTITY )
	): RateLimitDecision {
		$cache_dimensions = $dimensions;
		sort( $cache_dimensions, SORT_STRING );
		$cache_key = hash( 'sha256', $bucket . '|' . $limit . '|' . $window . '|' . implode( ',', $cache_dimensions ) );
		$cached    = $this->request_decisions[ $request ] ?? array();
		if ( isset( $cached[ $cache_key ] ) ) {
			return $cached[ $cache_key ];
		}

		$decision                            = $this->allow( $bucket, $limit, $window, $dimensions );
		$cached[ $cache_key ]                = $decision;
		$this->request_decisions[ $request ] = $cached;
		return $decision;
	}

	/** Delete one bounded batch of expired CartPops rows during core cleanup. */
	public static function cleanup_expired(): void {
		( new AtomicRateLimitStore() )->cleanup( time() );
	}

	/**
	 * Validate public limiter configuration before resolving identity values.
	 *
	 * @param string   $bucket     Stable route bucket.
	 * @param int      $limit      Maximum requests per window.
	 * @param int      $window     Fixed-window length in seconds.
	 * @param string[] $dimensions Required dimensions.
	 */
	private function valid_configuration( string $bucket, int $limit, int $window, array $dimensions ): bool {
		return 1 === preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/D', $bucket )
			&& $limit >= 1
			&& $limit <= self::MAX_LIMIT
			&& $window >= 1
			&& $window <= self::MAX_WINDOW
			&& array() !== $dimensions
			&& count( $dimensions ) === count( array_unique( $dimensions ) )
			&& array() === array_diff( $dimensions, array( self::DIMENSION_IP, self::DIMENSION_IDENTITY ) );
	}

	/**
	 * Whether a resolved identity uses an accepted bounded form.
	 *
	 * @param mixed $identity Candidate verified identity.
	 */
	private function valid_identity( mixed $identity ): bool {
		return is_string( $identity )
			&& strlen( $identity ) <= 160
			&& 1 === preg_match( '/^(user:[1-9][0-9]{0,19}|session:[A-Za-z0-9._-]{1,128})$/D', $identity );
	}

	/**
	 * Whether a resolved client IP is syntactically valid.
	 *
	 * @param mixed $ip Candidate IP value.
	 */
	private function valid_ip( mixed $ip ): bool {
		return is_string( $ip ) && false !== filter_var( $ip, FILTER_VALIDATE_IP );
	}

	/**
	 * Emit a value-free internal status code and preserve the typed decision.
	 *
	 * @param RateLimitDecision $decision Typed infrastructure failure.
	 */
	private function diagnostic( RateLimitDecision $decision ): RateLimitDecision {
		try {
			( $this->logger )( $decision->status->value );
		} catch ( \Throwable ) {
			return $decision;
		}
		return $decision;
	}

	/** Use only an authenticated WP user or WooCommerce's active server session. */
	private static function current_identity(): string {
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			$user_id = get_current_user_id();
			return $user_id > 0 ? 'user:' . $user_id : '';
		}

		if ( ! function_exists( 'WC' ) ) {
			return '';
		}
		$woocommerce = WC();
		$session     = is_object( $woocommerce ) ? ( $woocommerce->session ?? null ) : null;
		if ( ! is_object( $session ) || ! is_callable( array( $session, 'get_customer_id' ) ) ) {
			return '';
		}
		$customer_id = $session->get_customer_id();
		if ( ! is_string( $customer_id ) && ! is_int( $customer_id ) ) {
			return '';
		}

		$customer_id = (string) $customer_id;
		return 1 === preg_match( '/^[A-Za-z0-9._-]{1,128}$/D', $customer_id )
			? 'session:' . $customer_id
			: '';
	}
}
