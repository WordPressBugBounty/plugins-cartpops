<?php
/**
 * Immutable CartPops product capability matrix.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Edition;

/** Describe physical feature ownership without making runtime licensing decisions. */
final class EditionRegistry {
	/**
	 * Capability value to physical ownership.
	 *
	 * @var array<string, EditionOwnership>
	 */
	private readonly array $ownership;

	/**
	 * Build only the closed product matrix.
	 *
	 * @throws \LogicException When the fixed matrix omits a capability.
	 */
	private function __construct() {
		$shared = array(
			EditionCapability::CART_DRAWER,
			EditionCapability::CART_LAUNCHER,
			EditionCapability::CART_MANAGEMENT,
			EditionCapability::COUPONS,
			EditionCapability::TOTALS,
			EditionCapability::BASIC_DESIGN,
			EditionCapability::BASIC_WOOCOMMERCE_RECOMMENDATIONS,
		);
		$pro    = array(
			EditionCapability::SHIPPING_METER,
			EditionCapability::REWARDS,
			EditionCapability::SMART_ADDONS,
			EditionCapability::BUNDLE_BUILDER,
			EditionCapability::NOTIFICATIONS,
			EditionCapability::PRODUCT_SPOTLIGHT,
			EditionCapability::ANALYTICS,
			EditionCapability::ADVANCED_RECOMMENDATIONS,
			EditionCapability::CUSTOM_RECOMMENDATIONS,
			EditionCapability::AUTOMATION,
		);

		$ownership = array();
		foreach ( $shared as $capability ) {
			$ownership[ $capability->value ] = EditionOwnership::SHARED;
		}
		foreach ( $pro as $capability ) {
			$ownership[ $capability->value ] = EditionOwnership::PRO;
		}
		if ( count( EditionCapability::cases() ) !== count( $ownership ) ) {
			throw new \LogicException( 'CartPops edition capability matrix is incomplete.' );
		}

		$this->ownership = $ownership;
	}

	/** Return a fresh immutable copy of the fixed product matrix. */
	public static function product(): self {
		return new self();
	}

	/**
	 * Return physical source ownership for one typed capability.
	 *
	 * @param EditionCapability $capability Typed product capability.
	 */
	public function ownership( EditionCapability $capability ): EditionOwnership {
		return $this->ownership[ $capability->value ];
	}

	/**
	 * Return capabilities owned by one physical source tier.
	 *
	 * @param EditionOwnership $ownership Physical source tier.
	 * @return EditionCapability[]
	 */
	public function capabilities( EditionOwnership $ownership ): array {
		return array_values(
			array_filter(
				EditionCapability::cases(),
				fn( EditionCapability $capability ): bool => $ownership === $this->ownership( $capability )
			)
		);
	}

	/**
	 * Return the complete typed product matrix.
	 *
	 * @return array<int, array{capability: EditionCapability, ownership: EditionOwnership}>
	 */
	public function matrix(): array {
		return array_map(
			fn( EditionCapability $capability ): array => array(
				'capability' => $capability,
				'ownership'  => $this->ownership( $capability ),
			),
			EditionCapability::cases()
		);
	}
}
