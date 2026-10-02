<?php
/**
 * Bounded CartPops entitlement result.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Licensing;

/** Immutable caller-facing licensing decision. */
final class EntitlementState implements \JsonSerializable, \Stringable {
	private const STATUS_ENTITLED          = 'entitled';
	private const STATUS_UNENTITLED        = 'unentitled';
	private const STATUS_RETRYABLE_UNKNOWN = 'retryable_unknown';

	private const REASON_PREMIUM_CODE_ALLOWED       = 'premium_code_allowed';
	private const REASON_PREMIUM_CODE_DENIED        = 'premium_code_denied';
	private const REASON_INVALID_SITE_ID            = 'invalid_site_id';
	private const REASON_RUNTIME_UNAVAILABLE        = 'runtime_unavailable';
	private const REASON_RUNTIME_FAILURE            = 'runtime_failure';
	private const REASON_RUNTIME_IDENTITY_MISMATCH  = 'runtime_identity_mismatch';
	private const REASON_MALFORMED_RUNTIME_RESPONSE = 'malformed_runtime_response';

	/**
	 * Exact reason-to-status vocabulary accepted by every construction path.
	 *
	 * @var array<string, string>
	 */
	private const CANONICAL_STATUS_BY_REASON = array(
		self::REASON_PREMIUM_CODE_ALLOWED       => self::STATUS_ENTITLED,
		self::REASON_PREMIUM_CODE_DENIED        => self::STATUS_UNENTITLED,
		self::REASON_INVALID_SITE_ID            => self::STATUS_RETRYABLE_UNKNOWN,
		self::REASON_RUNTIME_UNAVAILABLE        => self::STATUS_RETRYABLE_UNKNOWN,
		self::REASON_RUNTIME_FAILURE            => self::STATUS_RETRYABLE_UNKNOWN,
		self::REASON_RUNTIME_IDENTITY_MISMATCH  => self::STATUS_RETRYABLE_UNKNOWN,
		self::REASON_MALFORMED_RUNTIME_RESPONSE => self::STATUS_RETRYABLE_UNKNOWN,
	);

	/**
	 * Caller-relevant outcome.
	 *
	 * @var string
	 */
	private readonly string $status;

	/**
	 * Bounded machine-readable reason.
	 *
	 * @var string
	 */
	private readonly string $reason_code;

	/**
	 * Create one canonical bounded state.
	 *
	 * @param string $reason_code Canonical machine-readable reason.
	 */
	private function __construct( string $reason_code ) {
		$this->status      = self::CANONICAL_STATUS_BY_REASON[ $reason_code ];
		$this->reason_code = $reason_code;
	}

	/** Return the canonical affirmative entitlement state. */
	public static function entitled(): self {
		return new self( self::REASON_PREMIUM_CODE_ALLOWED );
	}

	/** Return the canonical negative entitlement state. */
	public static function unentitled(): self {
		return new self( self::REASON_PREMIUM_CODE_DENIED );
	}

	/** Return a retryable state for an invalid caller-supplied site identity. */
	public static function invalid_site_id(): self {
		return new self( self::REASON_INVALID_SITE_ID );
	}

	/** Return a retryable state when the canonical runtime is unavailable. */
	public static function runtime_unavailable(): self {
		return new self( self::REASON_RUNTIME_UNAVAILABLE );
	}

	/** Return a retryable state when the canonical runtime throws. */
	public static function runtime_failure(): self {
		return new self( self::REASON_RUNTIME_FAILURE );
	}

	/** Return a retryable state when the SDK is bound to another identity. */
	public static function runtime_identity_mismatch(): self {
		return new self( self::REASON_RUNTIME_IDENTITY_MISMATCH );
	}

	/** Return a retryable state for a noncanonical SDK entitlement value. */
	public static function malformed_runtime_response(): self {
		return new self( self::REASON_MALFORMED_RUNTIME_RESPONSE );
	}

	/** Return the caller-relevant outcome. */
	public function status(): string {
		return $this->safe_output()['status'];
	}

	/** Return the bounded machine-readable reason. */
	public function reason_code(): string {
		return $this->safe_output()['reason_code'];
	}

	/** Whether the canonical runtime affirmatively allows premium code. */
	public function is_entitled(): bool {
		return $this->has_reason( self::REASON_PREMIUM_CODE_ALLOWED );
	}

	/** Whether the canonical runtime affirmatively denied premium code. */
	public function is_unentitled(): bool {
		return $this->has_reason( self::REASON_PREMIUM_CODE_DENIED );
	}

	/** Whether a caller must fail closed and retry later. */
	public function is_retryable_unknown(): bool {
		return self::STATUS_RETRYABLE_UNKNOWN === $this->status && $this->has_reason( $this->reason_code );
	}

	/** Return the bounded outcome and reason without SDK data. */
	public function __toString(): string {
		$output = $this->safe_output();

		return $output['status'] . ':' . $output['reason_code'];
	}

	/**
	 * Return only bounded machine-readable state.
	 *
	 * @return array{status: string, reason_code: string}
	 */
	public function jsonSerialize(): array {
		return $this->safe_output();
	}

	/**
	 * Keep native serialization bounded to the same two allowlisted values.
	 *
	 * @return array{status: string, reason_code: string}
	 */
	public function __serialize(): array {
		return $this->safe_output();
	}

	/**
	 * Rehydrate only an exact canonical tuple in canonical field order.
	 *
	 * @param array<array-key, mixed> $data Native serialized property payload.
	 *
	 * @throws \UnexpectedValueException When the payload is not exactly canonical.
	 */
	public function __unserialize( array $data ): void {
		if (
			isset( $this->status ) ||
			isset( $this->reason_code ) ||
			array( 'status', 'reason_code' ) !== array_keys( $data ) ||
			! is_string( $data['status'] ) ||
			! is_string( $data['reason_code'] ) ||
			! self::is_canonical_tuple( $data['status'], $data['reason_code'] )
		) {
			throw new \UnexpectedValueException( 'Serialized entitlement state is invalid.' );
		}

		$this->status      = $data['status'];
		$this->reason_code = $data['reason_code'];
	}

	/**
	 * Keep debugger output bounded to the same two allowlisted values.
	 *
	 * @return array{status: string, reason_code: string}
	 */
	public function __debugInfo(): array {
		return $this->safe_output();
	}

	/**
	 * Return the complete safe representation.
	 *
	 * @return array{status: string, reason_code: string}
	 *
	 * @throws \LogicException When an impossible noncanonical in-memory state is encountered.
	 */
	private function safe_output(): array {
		if ( ! self::is_canonical_tuple( $this->status, $this->reason_code ) ) {
			throw new \LogicException( 'Entitlement state is invalid.' );
		}

		return array(
			'status'      => $this->status,
			'reason_code' => $this->reason_code,
		);
	}

	/**
	 * Whether this object is the exact canonical tuple for one reason.
	 *
	 * @param string $reason_code Canonical reason to compare.
	 */
	private function has_reason( string $reason_code ): bool {
		return $reason_code === $this->reason_code && self::is_canonical_tuple( $this->status, $reason_code );
	}

	/**
	 * Whether a status/reason pair is in the one closed vocabulary.
	 *
	 * @param string $status      Caller-relevant outcome.
	 * @param string $reason_code Machine-readable reason.
	 */
	private static function is_canonical_tuple( string $status, string $reason_code ): bool {
		return isset( self::CANONICAL_STATUS_BY_REASON[ $reason_code ] ) &&
			self::CANONICAL_STATUS_BY_REASON[ $reason_code ] === $status;
	}
}
