<?php
/**
 * Premium-only authorization boundary for legacy paid migrations.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Migration;

/**
 * Issue and consume opaque, exact-row-bound migration capabilities.
 *
 * Concrete premium code performs the live authority decision only during
 * prepare(). The final consume() method is value-only and safe inside the
 * terminal database transaction.
 */
abstract class LegacyPaidEntitlementBridge {

	/**
	 * Opaque capability issuer identity.
	 *
	 * @var object
	 */
	private readonly object $plan_owner;

	/**
	 * Exact issued plans that have not yet been consumed.
	 *
	 * @var \WeakMap<LegacyPaidEntitlementPlan, true>
	 */
	private \WeakMap $issued_plans;

	/** Initialize an empty request-local capability registry. */
	public function __construct() {
		$this->plan_owner   = new \stdClass();
		$this->issued_plans = new \WeakMap();
	}

	/** Prevent a clone from sharing live capability authority. */
	private function __clone() {}

	/**
	 * Prepare one exact current-blog plan before any database lock is acquired.
	 *
	 * @param int    $blog_id Exact captured WordPress blog.
	 * @param string $raw     Exact legacy fs_accounts bytes.
	 */
	final public function prepare( int $blog_id, string $raw ): ?LegacyPaidEntitlementPlan {
		if ( $blog_id <= 0 || '' === $raw || get_current_blog_id() !== $blog_id ) {
			return null;
		}

		try {
			$allowed = $this->allows_current_blog( $blog_id );
		} catch ( \Throwable ) {
			return null;
		}
		if ( ! $allowed || get_current_blog_id() !== $blog_id ) {
			return null;
		}

		$plan                        = LegacyPaidEntitlementPlan::issue( $this->plan_owner, $blog_id, $raw );
		$this->issued_plans[ $plan ] = true;
		return $plan;
	}

	/**
	 * Consume one exact issued plan without invoking edition or SDK behavior.
	 *
	 * A failed match also consumes the object, preventing drifted plans from
	 * being replayed against a later row.
	 *
	 * @param LegacyPaidEntitlementPlan $plan    Exact issued capability.
	 * @param int                       $blog_id Exact captured WordPress blog.
	 * @param string                    $raw     Exact locked fs_accounts bytes.
	 */
	final public function consume( LegacyPaidEntitlementPlan $plan, int $blog_id, string $raw ): bool {
		if ( ! isset( $this->issued_plans[ $plan ] ) ) {
			return false;
		}
		unset( $this->issued_plans[ $plan ] );

		return $plan->matches( $this->plan_owner, $blog_id, $raw );
	}

	/**
	 * Resolve live physical-edition and entitlement authority outside locks.
	 *
	 * @param int $blog_id Exact captured WordPress blog.
	 */
	abstract protected function allows_current_blog( int $blog_id ): bool;

	/**
	 * Live capability issuers may never be persisted.
	 *
	 * @throws \LogicException Always.
	 */
	public function __serialize(): array {
		throw new \LogicException( 'A legacy paid entitlement bridge cannot be serialized.' );
	}

	/**
	 * Live capability issuers may never be restored.
	 *
	 * @param array<mixed> $data Serialized payload, which is never accepted.
	 * @throws \LogicException Always.
	 */
	public function __unserialize( array $data ): void {
		unset( $data );
		throw new \LogicException( 'A legacy paid entitlement bridge cannot be unserialized.' );
	}
}
