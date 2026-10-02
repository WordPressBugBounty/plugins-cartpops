<?php
/**
 * Current-blog paid execution guard.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Licensing;

use CartPops\REST\NamespaceRequestPolicy;
use CartPops\REST\RestEdition;

/** Keep physically present Pro callbacks inert outside an entitled current blog. */
final class PaidRuntimeGuard {
	/**
	 * Idempotent paid-infrastructure verifier for the current blog.
	 *
	 * @var \Closure(): bool
	 */
	private readonly \Closure $prerequisites;

	/**
	 * Complete paid-graph activation boundary for this request.
	 *
	 * @var \Closure(): bool
	 */
	private readonly \Closure $runtime_active;

	/**
	 * Per-blog verified infrastructure, never entitlement.
	 *
	 * @var array<int, true>
	 */
	private array $ready_sites = array();

	/**
	 * Create a fresh current-blog execution boundary.
	 *
	 * @param EditionAuthority        $authority     Current-blog edition authority.
	 * @param (\Closure(): bool)|null $prerequisites Idempotent paid-infrastructure verifier.
	 * @param (\Closure(): bool)|null $runtime_active Complete paid-graph activation verifier.
	 */
	public function __construct(
		private readonly EditionAuthority $authority,
		?\Closure $prerequisites = null,
		?\Closure $runtime_active = null,
	) {
		$this->prerequisites  = $prerequisites ?? static fn(): bool => true;
		$this->runtime_active = $runtime_active ?? static fn(): bool => true;
	}

	/**
	 * Whether paid code may execute safely for the current blog right now.
	 *
	 * @phpstan-impure Licensing, blog context, and prerequisite callbacks can
	 * change between observations.
	 */
	public function allows_execution(): bool {
		if ( ! ( $this->runtime_active )() || ! $this->paid_code_is_allowed() ) {
			return false;
		}

		$site_id = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0;
		if ( $site_id < 1 ) {
			return false;
		}
		if ( isset( $this->ready_sites[ $site_id ] ) ) {
			return true;
		}

		try {
			if ( true !== ( $this->prerequisites )() ) {
				return false;
			}
		} catch ( \Throwable ) {
			return false;
		}

		// Preparation may execute WordPress hooks or external boundaries. Bind its
		// success back to the exact captured blog and re-resolve licensing before
		// this infrastructure state can authorize a callback or enter the cache.
		if ( get_current_blog_id() !== $site_id || ! $this->paid_code_is_allowed() ) {
			return false;
		}
		if ( get_current_blog_id() !== $site_id ) {
			return false;
		}

		$this->ready_sites[ $site_id ] = true;
		return true;
	}

	/**
	 * Re-evaluate the current-blog edition authority.
	 *
	 * @phpstan-impure Shared SDK state and blog identity can change while a
	 * delegated prerequisite executes.
	 */
	private function paid_code_is_allowed(): bool {
		return $this->authority->allows_paid_code();
	}

	/**
	 * Wrap a paid action callback with a fresh current-blog decision.
	 *
	 * @param callable $callback Paid callback.
	 * @return \Closure(mixed...): void
	 */
	public function action( callable $callback ): \Closure {
		return function ( mixed ...$arguments ) use ( $callback ): void {
			if ( $this->allows_execution() ) {
				$callback( ...$arguments );
			}
		};
	}

	/**
	 * Wrap a paid filter callback, preserving the incoming value when denied.
	 *
	 * @param callable $callback Paid callback.
	 * @return \Closure(mixed, mixed...): mixed
	 */
	public function filter( callable $callback ): \Closure {
		return function ( mixed $value, mixed ...$arguments ) use ( $callback ): mixed {
			return $this->allows_execution()
				? $callback( $value, ...$arguments )
				: $value;
		};
	}

	/**
	 * Deny every manifest-declared Pro REST route before its permission or
	 * controller callback can observe the wrong blog.
	 *
	 * @param mixed            $result  Earlier pre-dispatch result.
	 * @param mixed            $server  REST server (unused; required by core).
	 * @param \WP_REST_Request $request Current request.
	 * @return mixed|\WP_Error
	 */
	public function enforce_rest_edition( mixed $result, mixed $server, \WP_REST_Request $request ): mixed {
		unset( $server );
		if ( ! method_exists( $request, 'get_method' ) || ! method_exists( $request, 'get_route' ) ) {
			return $result;
		}
		$method = $request->get_method();
		$route  = $request->get_route();
		$policy = is_string( $method ) && is_string( $route )
			? NamespaceRequestPolicy::find( $method, $route )
			: null;

		if ( null === $policy || RestEdition::PRO !== $policy->edition ) {
			return $result;
		}
		if ( ! $this->authority->allows_paid_code() ) {
			return new \WP_Error(
				'cartpops_paid_feature_unavailable',
				__( 'This CartPops feature requires an active Pro entitlement for this site.', 'cartpops' ),
				array( 'status' => 403 )
			);
		}
		if ( ! $this->allows_execution() ) {
			return new \WP_Error(
				'cartpops_paid_feature_not_ready',
				__( 'This CartPops feature is temporarily unavailable while its data is prepared.', 'cartpops' ),
				array( 'status' => 503 )
			);
		}

		return $result;
	}
}
