<?php
/**
 * One-use, request-local proof for one exact legacy paid row.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Migration;

/** Keep raw Freemius account bytes out of the entitlement capability. */
final class LegacyPaidEntitlementPlan {

	/**
	 * Issue a bridge-owned plan for one exact site and raw row.
	 *
	 * The owner is opaque and the issuing bridge separately registers the exact
	 * object identity. Constructing an equal tuple cannot create a usable plan.
	 *
	 * @param object $owner   Opaque bridge identity.
	 * @param int    $blog_id Exact captured WordPress blog.
	 * @param string $raw     Exact legacy option bytes.
	 * @throws \InvalidArgumentException When the binding is invalid.
	 */
	public static function issue( object $owner, int $blog_id, string $raw ): self {
		if ( $blog_id <= 0 || '' === $raw ) {
			throw new \InvalidArgumentException( 'Invalid legacy paid entitlement binding.' );
		}

		return new self( $owner, $blog_id, strlen( $raw ), hash( 'sha256', $raw ) );
	}

	/**
	 * Bind one opaque owner to value-free exact-row evidence.
	 *
	 * @param object $owner      Opaque bridge identity.
	 * @param int    $blog_id    Exact captured WordPress blog.
	 * @param int    $byte_length Exact raw-row byte length.
	 * @param string $checksum   SHA-256 of the exact raw row.
	 */
	private function __construct(
		private readonly object $owner,
		private readonly int $blog_id,
		private readonly int $byte_length,
		private readonly string $checksum
	) {}

	/**
	 * Match one bridge owner, site, and exact raw row without decoding it.
	 *
	 * @param object $owner   Opaque bridge identity.
	 * @param int    $blog_id Exact captured WordPress blog.
	 * @param string $raw     Exact locked fs_accounts bytes.
	 */
	public function matches( object $owner, int $blog_id, string $raw ): bool {
		return $owner === $this->owner
			&& $blog_id === $this->blog_id
			&& strlen( $raw ) === $this->byte_length
			&& hash_equals( $this->checksum, hash( 'sha256', $raw ) );
	}

	/**
	 * Request-local capabilities may never be persisted.
	 *
	 * @throws \LogicException Always.
	 */
	public function __serialize(): array {
		throw new \LogicException( 'A legacy paid entitlement plan cannot be serialized.' );
	}

	/**
	 * Request-local capabilities may never be restored.
	 *
	 * @param array<mixed> $data Serialized payload, which is never accepted.
	 * @throws \LogicException Always.
	 */
	public function __unserialize( array $data ): void {
		unset( $data );
		throw new \LogicException( 'A legacy paid entitlement plan cannot be unserialized.' );
	}

	/** Keep row fingerprints out of debugger output. */
	public function __debugInfo(): array {
		return array( 'state' => 'opaque_legacy_paid_entitlement_plan' );
	}
}
