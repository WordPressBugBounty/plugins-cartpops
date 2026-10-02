<?php
/**
 * Site-scoped CartPops entitlement resolver.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Licensing;

/** Convert the external SDK boundary into one bounded licensing decision. */
final class FreemiusEntitlementAdapter {
	private const PRODUCT_ID = 7061;

	/**
	 * Bind the optional canonical Freemius runtime.
	 *
	 * @param FreemiusRuntime|null $runtime External SDK boundary, or null when unavailable.
	 */
	public function __construct( private readonly ?FreemiusRuntime $runtime ) {}

	/**
	 * Resolve entitlement for one explicitly supplied WordPress blog identity.
	 *
	 * @param int $site_id Current WordPress blog identity.
	 */
	public function resolve( int $site_id ): EntitlementState {
		if ( 1 > $site_id ) {
			return EntitlementState::invalid_site_id();
		}
		if ( null === $this->runtime ) {
			return EntitlementState::runtime_unavailable();
		}

		try {
			if ( ! $this->runtime->is_available() ) {
				return EntitlementState::runtime_unavailable();
			}

			if ( ! $this->runtime->product_id_matches( self::PRODUCT_ID ) ) {
				return EntitlementState::runtime_identity_mismatch();
			}
			if ( ! $this->runtime->site_blog_id_matches( $site_id ) ) {
				return EntitlementState::runtime_identity_mismatch();
			}
			$entitlement = $this->runtime->premium_code_entitlement();
			if ( false === $entitlement ) {
				return EntitlementState::unentitled();
			}
			if ( true !== $entitlement ) {
				return EntitlementState::malformed_runtime_response();
			}

			return EntitlementState::entitled();
		} catch ( \Throwable ) {
			return EntitlementState::runtime_failure();
		}
	}
}
