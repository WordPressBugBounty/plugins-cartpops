<?php
/**
 * Exactly-once rate enforcement after REST permission checks.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\REST;

/** Consume the closed namespace rate declaration before invoking a controller. */
final class NamespaceRateLimitMiddleware {
	/**
	 * Whether the hook has already been registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Construct the namespace middleware.
	 *
	 * @param RateLimiter $rate_limiter Shared typed limiter.
	 */
	public function __construct( private readonly RateLimiter $rate_limiter ) {}

	/** Register the post-permission, pre-controller boundary once. */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}
		add_filter( 'rest_dispatch_request', array( $this, 'guard' ), 10, 4 );
		$this->registered = true;
	}

	/**
	 * Consume every declared dimension only after route permission succeeded.
	 *
	 * WordPress may evaluate permission callbacks more than once. This hook runs
	 * at the stable callback boundary, and RateLimiter::allow_once() additionally
	 * memoizes by request object so another invocation cannot double-charge.
	 *
	 * WordPress runs `rest_request_before_callbacks` before the route permission
	 * callback, so it cannot safely resolve a Cart-Token-backed Woo identity.
	 * `rest_dispatch_request` is the first core seam after successful permission
	 * evaluation and immediately before the controller callback.
	 *
	 * @param mixed            $dispatch_result Earlier dispatch short circuit.
	 * @param \WP_REST_Request $request         Matched request.
	 * @param string           $route           Matched route.
	 * @param mixed            $handler         Matched route handler (unused).
	 * @return mixed
	 */
	public function guard( mixed $dispatch_result, \WP_REST_Request $request, string $route, mixed $handler ): mixed {
		unset( $handler );

		$method = method_exists( $request, 'get_method' ) ? $request->get_method() : '';
		if ( ! is_string( $method ) || ! is_string( $route ) ) {
			return $dispatch_result;
		}

		$policy = NamespaceRequestPolicy::find( strtoupper( $method ), $route );
		if ( null === $policy || null === $policy->rate_bucket ) {
			return $dispatch_result;
		}
		if ( ! $policy->rate_wired ) {
			return $this->failure_response( RateLimitDecision::invalid_configuration() );
		}

		$decision = $this->rate_limiter->allow_once(
			$request,
			$policy->rate_bucket,
			$policy->rate_limit,
			$policy->rate_window,
			$policy->rate_dimensions
		);
		return $decision->is_allowed() ? $dispatch_result : $this->failure_response( $decision );
	}

	/**
	 * Build a value-free short-circuit response with bounded rate metadata.
	 *
	 * @param RateLimitDecision $decision Typed limiter decision.
	 */
	private function failure_response( RateLimitDecision $decision ): \WP_REST_Response {
		$exhausted = RateLimitStatus::EXHAUSTED === $decision->status;
		$response  = new \WP_REST_Response(
			array(
				'code'    => $exhausted ? 'cartpops_rate_limit_exhausted' : 'cartpops_rate_limit_unavailable',
				'message' => $exhausted
					? __( 'Too many requests. Please wait and try again.', 'cartpops' )
					: __( 'Request limiting is temporarily unavailable. Please try again.', 'cartpops' ),
			),
			$exhausted ? 429 : 503
		);
		if ( is_callable( array( $response, 'header' ) ) ) {
			foreach ( $decision->response_headers() as $name => $value ) {
				$response->header( $name, $value );
			}
		}
		return $response;
	}
}
