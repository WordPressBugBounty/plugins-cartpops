<?php
/**
 * Transactional cleanup of physically absent paid cart behavior.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Cart;

/** Remove only exact paid metadata and a proven CartPops-owned reward coupon. */
final class StalePaidCartArtifactSanitizer {
	/** Exact paid cart-line metadata. Shared recommendation provenance is excluded. */
	private const PAID_ITEM_KEYS = array(
		'cartpops_free_gift',
		'cartpops_gift_proof',
		'cartpops_spotlight_offer',
		'cartpops_spotlight_proof',
		'cartpops_spotlight_discount',
		'cartpops_spotlight_token',
		'cartpops_spotlight_config_fingerprint',
		'cartpops_spotlight_config_product',
		'_cartpops_upsell_parent',
		'_cartpops_upsell_discount',
		'_cartpops_upsell_group_size',
		'_cartpops_bundle_trigger_key',
		'_cartpops_bundle_group_id',
		'_cartpops_bundle_members',
		'_cartpops_bundle_member',
		'_cartpops_bundle_proof',
	);

	/** Paid-only values of the otherwise shared provenance key. */
	private const PAID_SOURCE_VALUES = array( 'bundle_builder', 'spotlight' );

	/**
	 * Create the narrow stale-artifact sanitizer.
	 *
	 * @param ManagedCouponPolicy $coupon_policy Exact coupon-ownership policy.
	 */
	public function __construct( private readonly ManagedCouponPolicy $coupon_policy = new ManagedCouponPolicy() ) {}

	/**
	 * Remove stale paid artifacts with one totals pass and one verified commit.
	 *
	 * Unrelated cart keys, quantities, products, settings, licensing records, and
	 * Smart Add-on session choices remain outside this module's ownership.
	 *
	 * @param \WC_Cart $cart Cart to sanitize.
	 */
	public function sanitize( \WC_Cart $cart ): StalePaidCartArtifactSanitizationResult {
		if ( ! $this->has_stale_artifacts( $cart ) ) {
			return StalePaidCartArtifactSanitizationResult::UNCHANGED;
		}

		try {
			$changed = CartMutationTransaction::execute(
				$cart,
				fn(): bool => $this->sanitize_locked_cart( $cart )
			);
		} catch ( CartSessionConflictException ) {
			return StalePaidCartArtifactSanitizationResult::BLOCKED;
		} catch ( CartCommitOutcomeUnknownException ) {
			return StalePaidCartArtifactSanitizationResult::OUTCOME_UNKNOWN;
		}

		return $changed
			? StalePaidCartArtifactSanitizationResult::SANITIZED
			: StalePaidCartArtifactSanitizationResult::UNCHANGED;
	}

	/**
	 * Whether the current in-memory cart exposes anything this module owns.
	 *
	 * @param \WC_Cart $cart Cart to inspect.
	 */
	private function has_stale_artifacts( \WC_Cart $cart ): bool {
		foreach ( $cart->get_cart() as $item ) {
			if ( is_array( $item ) && $this->item_has_paid_metadata( $item ) ) {
				return true;
			}
		}

		foreach ( $cart->get_applied_coupons() as $code ) {
			if ( is_string( $code ) && $this->coupon_policy->is_managed( $cart, $code ) ) {
				return true;
			}
		}

		return '' !== SystemRewardCoupon::marked_code( $cart );
	}

	/**
	 * Apply only owned changes while the cart transaction is held.
	 *
	 * @param \WC_Cart $cart Locked cart to sanitize.
	 */
	private function sanitize_locked_cart( \WC_Cart $cart ): bool {
		$contents = $cart->get_cart();
		$changed  = false;
		foreach ( $contents as $key => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$sanitized = $this->sanitize_item( $item );
			if ( $sanitized !== $item ) {
				$contents[ $key ] = $sanitized;
				$changed          = true;
			}
		}
		if ( $changed ) {
			$cart->set_cart_contents( $contents );
		}

		$current = $cart->get_applied_coupons();
		$desired = array_values(
			array_filter(
				$current,
				fn( mixed $code ): bool => ! is_string( $code ) || ! $this->coupon_policy->is_managed( $cart, $code )
			)
		);
		if ( array_values( $current ) !== $desired ) {
			$cart->set_applied_coupons( $desired );
			$changed = true;
		}

		if ( '' !== SystemRewardCoupon::marked_code( $cart ) ) {
			SystemRewardCoupon::clear_applied( $cart );
			$changed = true;
		}

		return $changed;
	}

	/**
	 * Whether a cart line contains exact paid metadata.
	 *
	 * @param array<string, mixed> $item Cart line.
	 */
	private function item_has_paid_metadata( array $item ): bool {
		foreach ( self::PAID_ITEM_KEYS as $key ) {
			if ( array_key_exists( $key, $item ) ) {
				return true;
			}
		}

		return isset( $item['_cartpops_source'] )
			&& in_array( $item['_cartpops_source'], self::PAID_SOURCE_VALUES, true );
	}

	/**
	 * Remove only exact keys this module owns.
	 *
	 * @param array<string, mixed> $item Cart line.
	 * @return array<string, mixed>
	 */
	private function sanitize_item( array $item ): array {
		foreach ( self::PAID_ITEM_KEYS as $key ) {
			unset( $item[ $key ] );
		}
		if ( isset( $item['_cartpops_source'] ) && in_array( $item['_cartpops_source'], self::PAID_SOURCE_VALUES, true ) ) {
			unset( $item['_cartpops_source'] );
		}

		return $item;
	}
}
