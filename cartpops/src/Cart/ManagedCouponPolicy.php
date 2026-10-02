<?php
/**
 * CartPops-owned coupon policy.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Cart;

/** Recognize only the current, collision-free reward coupon owned by this cart. */
final class ManagedCouponPolicy {
	/**
	 * Create the exact coupon ownership policy.
	 *
	 * @param SystemRewardCouponOptionStore|null $store Exact option-store seam, or the production store.
	 */
	public function __construct( private readonly ?SystemRewardCouponOptionStore $store = null ) {}

	/**
	 * Whether CartPops can prove ownership of this exact coupon on this cart.
	 *
	 * @param \WC_Cart $cart Cart whose ownership marker is checked.
	 * @param string   $code Exact coupon code.
	 */
	public function is_managed( \WC_Cart $cart, string $code ): bool {
		return SystemRewardCoupon::is_system_coupon( $cart, $code, $this->store );
	}
}
