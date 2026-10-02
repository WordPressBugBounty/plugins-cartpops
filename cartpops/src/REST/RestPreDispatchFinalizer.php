<?php
/**
 * Immutable expectation for one CartPops REST pre-dispatch lifecycle.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\REST;

/** Correlate a pre-dispatch decision with the later served-response boundary. */
final class RestPreDispatchFinalizer {

	/**
	 * Construct one value-free lifecycle expectation.
	 *
	 * @param string                 $method          Exact request method.
	 * @param string                 $route           Exact request route.
	 * @param int|null               $denial_status   Bounded status already selected by policy.
	 * @param \WP_REST_Response|null $policy_response Safe response selected by policy, such as OPTIONS.
	 */
	private function __construct(
		private readonly string $method,
		private readonly string $route,
		private readonly ?int $denial_status,
		private readonly ?\WP_REST_Response $policy_response
	) {}

	/**
	 * Capture the policy result without retaining request or customer data.
	 *
	 * @param \WP_REST_Request $request Normalized CartPops request.
	 * @param mixed            $result  Value-free policy result.
	 */
	public static function capture( \WP_REST_Request $request, mixed $result ): self {
		$status   = null;
		$response = null;
		if ( $result instanceof \WP_Error ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && is_int( $data['status'] ?? null )
				? self::bounded_status( $data['status'] )
				: 500;
		} elseif ( $result instanceof \WP_REST_Response ) {
			$response = clone $result;
		}

		return new self(
			self::request_method( $request ),
			self::request_route( $request ),
			$status,
			$response
		);
	}

	/**
	 * Create a fail-closed expectation when batch correlation capacity is lost.
	 *
	 * @param \WP_REST_Request $request Current CartPops request.
	 */
	public static function failed( \WP_REST_Request $request ): self {
		return new self( self::request_method( $request ), self::request_route( $request ), 500, null );
	}

	/**
	 * Whether this expectation can correlate a core batch clone with its original.
	 *
	 * @param \WP_REST_Request $request Candidate original batch request.
	 */
	public function matches( \WP_REST_Request $request ): bool {
		return self::request_method( $request ) === $this->method
			&& self::request_route( $request ) === $this->route;
	}

	/**
	 * Resolve an unconsumed expectation at the served-response seam.
	 *
	 * A policy-owned response is returned unchanged apart from cloning. A known
	 * denial keeps only its bounded status. Otherwise reaching this seam proves
	 * that route matching and permission callbacks were skipped; an unequivocal
	 * later HTTP denial may retain its status, while every success-shaped value
	 * becomes a generic 500.
	 *
	 * @param mixed $observed Response produced after CartPops pre-dispatch policy.
	 */
	public function served_response( mixed $observed ): \WP_REST_Response {
		if ( null !== $this->policy_response ) {
			return clone $this->policy_response;
		}

		$status = $this->denial_status;
		if ( null === $status && $observed instanceof \WP_HTTP_Response ) {
			$observed_status = $observed->get_status();
			$status          = is_int( $observed_status )
				? self::bounded_status( $observed_status )
				: 500;
		}

		return new \WP_REST_Response(
			array(
				'code'    => 'cartpops_pre_dispatch_error',
				'message' => __( 'The REST request was rejected.', 'cartpops' ),
				'data'    => array( 'status' => $status ?? 500 ),
			),
			$status ?? 500
		);
	}

	/**
	 * Return an HTTP denial status, or fail closed for success/invalid values.
	 *
	 * @param int $status Candidate response status.
	 */
	private static function bounded_status( int $status ): int {
		return $status >= 400 && $status <= 599 ? $status : 500;
	}

	/**
	 * Return the request method without trusting an unsupported implementation.
	 *
	 * @param \WP_REST_Request $request Candidate REST request.
	 */
	private static function request_method( \WP_REST_Request $request ): string {
		if ( ! method_exists( $request, 'get_method' ) ) {
			return '';
		}
		$method = $request->get_method();
		return is_string( $method ) ? strtoupper( $method ) : '';
	}

	/**
	 * Return the request route without trusting an unsupported implementation.
	 *
	 * @param \WP_REST_Request $request Candidate REST request.
	 */
	private static function request_route( \WP_REST_Request $request ): string {
		if ( ! method_exists( $request, 'get_route' ) ) {
			return '';
		}
		$route = $request->get_route();
		return is_string( $route ) ? $route : '';
	}
}
