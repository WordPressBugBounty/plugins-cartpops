<?php
/**
 * Current-blog runtime edition authority.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Licensing;

use CartPops\Edition\PhysicalEditionAuthority;

/** Resolve physical edition and paid entitlement without cross-blog caching. */
final class EditionAuthority {
	/**
	 * Current-blog canonical entitlement adapter.
	 *
	 * @var FreemiusEntitlementAdapter
	 */
	private readonly FreemiusEntitlementAdapter $entitlements;

	/**
	 * Bind the optional exact Freemius runtime.
	 *
	 * @param FreemiusRuntime|null $runtime Exact runtime, or null to fail closed.
	 */
	private function __construct( private readonly ?FreemiusRuntime $runtime ) {
		$this->entitlements = new FreemiusEntitlementAdapter( $runtime );
	}

	/**
	 * Build the authority around the optional canonical SDK object.
	 *
	 * @param mixed $sdk External SDK object, or null.
	 */
	public static function from_sdk( mixed $sdk ): self {
		return new self( is_object( $sdk ) ? new FreemiusRuntime( $sdk ) : null );
	}

	/**
	 * Whether the packaged runtime is the exact physical Pro edition.
	 *
	 * This deliberately does not inspect or cache current-site entitlement. It
	 * is package classification only and must never authorize loading or wiring
	 * paid implementation without a fresh current_state() decision.
	 */
	public function is_premium_build(): bool {
		return PhysicalEditionAuthority::from_package()->has_paid_source();
	}

	/** Resolve a fresh state for the current WordPress blog. */
	public function current_state(): EditionState {
		$site_id     = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0;
		$entitlement = $this->entitlements->resolve( $site_id );
		$physical    = PhysicalEditionAuthority::from_package();

		if ( $physical->is_verified_free() ) {
			return EditionState::free( $entitlement );
		}
		if ( ! $physical->has_paid_source() ) {
			return EditionState::unknown( 'physical_source_unverified', $entitlement );
		}
		if ( $entitlement->is_retryable_unknown() ) {
			return EditionState::premium_blocked( $entitlement->reason_code(), $entitlement );
		}
		if ( null === $this->runtime ) {
			return EditionState::premium_blocked( 'runtime_unavailable', $entitlement );
		}

		try {
			$premium = $this->runtime->premium_edition();
		} catch ( \Throwable ) {
			return EditionState::premium_blocked( 'runtime_failure', EntitlementState::runtime_failure() );
		}

		if ( ! is_bool( $premium ) ) {
			return EditionState::premium_blocked( 'malformed_runtime_edition', $entitlement );
		}
		if ( ! $premium ) {
			return EditionState::premium_blocked( 'runtime_edition_inconsistent', $entitlement );
		}

		return EditionState::premium( $entitlement );
	}

	/** Whether paid implementation may execute for the current blog right now. */
	public function allows_paid_code(): bool {
		return $this->current_state()->allows_paid_code();
	}
}
