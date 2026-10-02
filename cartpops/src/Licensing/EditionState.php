<?php
/**
 * Bounded physical-edition and entitlement result.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Licensing;

/** Immutable caller-facing edition decision. */
final class EditionState implements \JsonSerializable {
	private const EDITION_FREE    = 'free';
	private const EDITION_PRO     = 'pro';
	private const EDITION_UNKNOWN = 'unknown';

	private const REASON_FREE_BUILD            = 'physical_free_source';
	private const REASON_PREMIUM_BUILD         = 'physical_pro_source';
	private const REASON_PHYSICAL_UNVERIFIED   = 'physical_source_unverified';
	private const REASON_RUNTIME_UNAVAILABLE   = 'runtime_unavailable';
	private const REASON_RUNTIME_FAILURE       = 'runtime_failure';
	private const REASON_MALFORMED_EDITION     = 'malformed_runtime_edition';
	private const REASON_INCONSISTENT_EDITION  = 'runtime_edition_inconsistent';
	private const REASON_IDENTITY_MISMATCH     = 'runtime_identity_mismatch';
	private const REASON_INVALID_SITE_ID       = 'invalid_site_id';
	private const REASON_MALFORMED_ENTITLEMENT = 'malformed_runtime_response';

	/**
	 * Closed edition mapping for every caller-visible reason.
	 *
	 * @var array<string, string>
	 */
	private const EDITION_BY_REASON = array(
		self::REASON_FREE_BUILD            => self::EDITION_FREE,
		self::REASON_PREMIUM_BUILD         => self::EDITION_PRO,
		self::REASON_PHYSICAL_UNVERIFIED   => self::EDITION_UNKNOWN,
		self::REASON_RUNTIME_UNAVAILABLE   => self::EDITION_PRO,
		self::REASON_RUNTIME_FAILURE       => self::EDITION_PRO,
		self::REASON_MALFORMED_EDITION     => self::EDITION_PRO,
		self::REASON_INCONSISTENT_EDITION  => self::EDITION_PRO,
		self::REASON_IDENTITY_MISMATCH     => self::EDITION_PRO,
		self::REASON_INVALID_SITE_ID       => self::EDITION_PRO,
		self::REASON_MALFORMED_ENTITLEMENT => self::EDITION_PRO,
	);

	/**
	 * Build one closed edition state.
	 *
	 * @param string           $reason_code Bounded edition reason.
	 * @param EntitlementState $entitlement Bounded entitlement state.
	 * @throws \InvalidArgumentException When the reason is outside the closed map.
	 */
	private function __construct(
		private readonly string $reason_code,
		private readonly EntitlementState $entitlement,
	) {
		if ( ! isset( self::EDITION_BY_REASON[ $reason_code ] ) ) {
			throw new \InvalidArgumentException( 'Unknown edition reason.' );
		}
	}

	/**
	 * Return a verified physical Free state.
	 *
	 * @param EntitlementState $entitlement Bounded current-blog entitlement.
	 */
	public static function free( EntitlementState $entitlement ): self {
		return new self( self::REASON_FREE_BUILD, $entitlement );
	}

	/**
	 * Return a verified physical Pro state.
	 *
	 * @param EntitlementState $entitlement Bounded current-blog entitlement.
	 */
	public static function premium( EntitlementState $entitlement ): self {
		return new self( self::REASON_PREMIUM_BUILD, $entitlement );
	}

	/**
	 * Return verified physical Pro with one fail-closed runtime diagnostic.
	 *
	 * @param string           $reason_code Bounded runtime reason.
	 * @param EntitlementState $entitlement Bounded current-blog entitlement.
	 * @throws \InvalidArgumentException When the reason is not a blocked Pro reason.
	 */
	public static function premium_blocked( string $reason_code, EntitlementState $entitlement ): self {
		if ( self::EDITION_PRO !== ( self::EDITION_BY_REASON[ $reason_code ] ?? null ) || self::REASON_PREMIUM_BUILD === $reason_code ) {
			throw new \InvalidArgumentException( 'Blocked premium reason is invalid.' );
		}

		return new self( $reason_code, $entitlement );
	}

	/**
	 * Return a fail-closed state with one bounded diagnostic.
	 *
	 * @param string           $reason_code Bounded edition reason.
	 * @param EntitlementState $entitlement Bounded current-blog entitlement.
	 * @throws \InvalidArgumentException When the reason is not physical-source uncertainty.
	 */
	public static function unknown( string $reason_code, EntitlementState $entitlement ): self {
		if ( self::REASON_PHYSICAL_UNVERIFIED !== $reason_code ) {
			throw new \InvalidArgumentException( 'Unknown physical-edition reason is invalid.' );
		}

		return new self( $reason_code, $entitlement );
	}

	/** Return a bounded reason for the physical-edition decision. */
	public function edition_reason_code(): string {
		return $this->reason_code;
	}

	/** Return the verified physical edition, or unknown. */
	public function edition(): string {
		return self::EDITION_BY_REASON[ $this->reason_code ];
	}

	/** Return the bounded entitlement decision used by this state. */
	public function entitlement(): EntitlementState {
		return $this->entitlement;
	}

	/** Whether this is a verified physical Free build. */
	public function is_free_build(): bool {
		return self::EDITION_FREE === $this->edition();
	}

	/** Whether this is a verified physical Pro build. */
	public function is_premium_build(): bool {
		return self::EDITION_PRO === $this->edition();
	}

	/** Whether paid implementation may execute for the current blog. */
	public function allows_paid_code(): bool {
		return self::REASON_PREMIUM_BUILD === $this->reason_code
			&& $this->entitlement->is_entitled();
	}

	/**
	 * Return only bounded state suitable for the admin bootstrap.
	 *
	 * @return array{edition: string, edition_reason_code: string, paid_code_allowed: bool, entitlement: array{status: string, reason_code: string}}
	 */
	public function jsonSerialize(): array {
		return array(
			'edition'             => $this->edition(),
			'edition_reason_code' => $this->edition_reason_code(),
			'paid_code_allowed'   => $this->allows_paid_code(),
			'entitlement'         => $this->entitlement->jsonSerialize(),
		);
	}
}
