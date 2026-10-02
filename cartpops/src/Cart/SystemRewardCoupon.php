<?php
/**
 * Persisted ownership for CartPops' internal reward coupon.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Cart;

/**
 * Owns one collision-resistant virtual coupon identifier and its per-cart mark.
 */
final class SystemRewardCoupon {
	public const OPTION_NAME          = 'cartpops_system_reward_coupon';
	public const ROTATION_LOCK_OPTION = 'cartpops_system_reward_coupon_lock';

	private const SESSION_KEY      = 'cartpops_system_reward_coupon_code';
	private const CODE_PREFIX      = 'cartpops-system-reward-';
	private const MAX_OPTION_BYTES = 4096;

	/**
	 * Request-local ownership markers.
	 *
	 * @var \WeakMap<\WC_Cart, string>|null
	 */
	private static ?\WeakMap $request_markers = null;

	/**
	 * Resolve or safely rotate the persisted identifier.
	 *
	 * Database lookup is used only to avoid a merchant collision. Ownership is
	 * established by the persisted option plus an exact per-cart marker, never by
	 * a recognizable code string or by the absence of a coupon post.
	 *
	 * @param \WC_Cart                           $cart  Cart requesting the identifier.
	 * @param SystemRewardCouponOptionStore|null $store Explicit persistence boundary.
	 */
	public static function code_for_cart( \WC_Cart $cart, ?SystemRewardCouponOptionStore $store = null ): string {
		unset( $cart );
		$store   ??= new WordPressSystemRewardCouponOptionStore();
		$state_row = self::read_option_row( self::OPTION_NAME, $store );
		if ( false === $state_row ) {
			return '';
		}
		$state = self::state_from_row( $state_row );
		if ( null !== $state && ! self::real_coupon_exists( $state['current_code'] ) ) {
			return $state['current_code'];
		}

		$lock_token = self::acquire_rotation_lock( $store );
		if ( null === $lock_token ) {
			// Another request owns rotation. Reuse only a fully settled,
			// collision-free value; otherwise fail closed until the next totals pass.
			$state = self::state( $store );
			return null !== $state && ! self::real_coupon_exists( $state['current_code'] )
				? $state['current_code']
				: '';
		}

		try {
			// Re-read after acquiring the site-local lock. A concurrent request may
			// have completed the transition while this request was waiting.
			$state_row = self::read_option_row( self::OPTION_NAME, $store );
			if ( false === $state_row ) {
				return '';
			}
			$state = self::state_from_row( $state_row );
			if ( null !== $state && ! self::real_coupon_exists( $state['current_code'] ) ) {
				return $state['current_code'];
			}

			for ( $attempt = 0; $attempt < 64; ++$attempt ) {
				try {
					$candidate = self::CODE_PREFIX . bin2hex( random_bytes( 16 ) );
				} catch ( \Throwable ) {
					return '';
				}
				if ( self::real_coupon_exists( $candidate ) ) {
					continue;
				}

				$new_state = array(
					'version'      => 1,
					'current_code' => $candidate,
				);
				if ( null === $state_row ) {
					if ( $store->add_non_autoloaded( self::OPTION_NAME, $new_state ) ) {
						$stored = self::read_option_row( self::OPTION_NAME, $store );
						return is_array( $stored ) && self::state_from_row( $stored ) === $new_state
							? $candidate
							: '';
					}

					$concurrent = self::state( $store );
					if ( null !== $concurrent && ! self::real_coupon_exists( $concurrent['current_code'] ) ) {
						return $concurrent['current_code'];
					}
					return '';
				}

				if ( self::compare_replace_option( self::OPTION_NAME, $state_row['raw'], $new_state, $store ) ) {
					$stored = self::read_option_row( self::OPTION_NAME, $store );
					return is_array( $stored ) && self::state_from_row( $stored ) === $new_state
						? $candidate
						: '';
				}

				// A writer which did not own our lock may still have raced us. Adopt
				// only a complete collision-free successor; never overwrite it from
				// a stale observation.
				$concurrent = self::state( $store );
				return null !== $concurrent && ! self::real_coupon_exists( $concurrent['current_code'] )
					? $concurrent['current_code']
					: '';
			}
		} finally {
			self::release_rotation_lock( $lock_token, $store );
		}

		return '';
	}

	/**
	 * Whether this exact previously marked identifier now collides.
	 *
	 * @param string                             $code  Coupon identifier.
	 * @param SystemRewardCouponOptionStore|null $store Explicit persistence boundary.
	 */
	public static function requires_rotation( string $code, ?SystemRewardCouponOptionStore $store = null ): bool {
		$state = self::state( $store );
		$code  = strtolower( $code );

		return '' !== $code
			&& null !== $state
			&& $state['current_code'] === $code
			&& self::real_coupon_exists( $code );
	}

	/**
	 * Whether this exact applied code is currently owned by CartPops.
	 *
	 * @param \WC_Cart                           $cart  Cart whose ownership marker is checked.
	 * @param string                             $code  Coupon identifier.
	 * @param SystemRewardCouponOptionStore|null $store Explicit persistence boundary.
	 */
	public static function is_system_coupon( \WC_Cart $cart, string $code, ?SystemRewardCouponOptionStore $store = null ): bool {
		$code   = strtolower( $code );
		$state  = self::state( $store );
		$marked = self::marked_code( $cart );

		return '' !== $code
			&& null !== $state
			&& $state['current_code'] === $code
			&& $marked === $code
			&& ! self::real_coupon_exists( $code );
	}

	/**
	 * Whether a virtual-coupon lookup targets the persisted current identifier.
	 *
	 * @param string                             $code  Coupon identifier.
	 * @param SystemRewardCouponOptionStore|null $store Explicit persistence boundary.
	 */
	public static function is_current_identifier( string $code, ?SystemRewardCouponOptionStore $store = null ): bool {
		$state = self::state( $store );
		$code  = strtolower( $code );

		return null !== $state
			&& '' !== $code
			&& $state['current_code'] === $code
			&& ! self::real_coupon_exists( $code );
	}

	/**
	 * Mark the exact identifier which CartPops auto-applied to this cart.
	 *
	 * @param \WC_Cart $cart Cart receiving the entitlement.
	 * @param string   $code Exact persisted identifier.
	 */
	public static function mark_applied( \WC_Cart $cart, string $code ): void {
		self::$request_markers        ??= new \WeakMap();
		self::$request_markers[ $cart ] = strtolower( $code );

		$session = self::session();
		if ( null !== $session && method_exists( $session, 'set' ) ) {
			$session->set( self::SESSION_KEY, strtolower( $code ) );
		}
	}

	/**
	 * Return the exact auto-applied identifier, including a pre-rotation mark.
	 *
	 * @param \WC_Cart $cart Cart whose marker is read.
	 */
	public static function marked_code( \WC_Cart $cart ): string {
		$session = self::session();
		if ( null !== $session && method_exists( $session, 'get' ) ) {
			$marked = $session->get( self::SESSION_KEY, '' );
			if ( is_string( $marked ) && '' !== $marked ) {
				return strtolower( $marked );
			}
		}

		return null !== self::$request_markers && isset( self::$request_markers[ $cart ] )
			? self::$request_markers[ $cart ]
			: '';
	}

	/**
	 * Clear only the marker CartPops previously wrote for this cart.
	 *
	 * @param \WC_Cart $cart Cart whose marker is cleared.
	 */
	public static function clear_applied( \WC_Cart $cart ): void {
		if ( null !== self::$request_markers && isset( self::$request_markers[ $cart ] ) ) {
			unset( self::$request_markers[ $cart ] );
		}

		$session = self::session();
		if ( null !== $session && method_exists( $session, '__unset' ) ) {
			$session->__unset( self::SESSION_KEY );
		}
	}

	/**
	 * Read the current validated ownership state.
	 *
	 * @param SystemRewardCouponOptionStore|null $store Explicit persistence boundary.
	 * @return array{version: int, current_code: string}|null
	 */
	private static function state( ?SystemRewardCouponOptionStore $store = null ): ?array {
		$row = self::read_option_row( self::OPTION_NAME, $store );
		return is_array( $row ) ? self::state_from_row( $row ) : null;
	}

	/**
	 * Parse one exact uncached option row as a valid state document.
	 *
	 * @param array{raw: string, value: mixed}|null $row Exact database row.
	 * @return array{version: int, current_code: string}|null
	 */
	private static function state_from_row( ?array $row ): ?array {
		$state = $row['value'] ?? null;
		if (
			! is_array( $state )
			|| 2 !== count( $state )
			|| ! array_key_exists( 'version', $state )
			|| ! array_key_exists( 'current_code', $state )
			|| 1 !== $state['version']
			|| ! is_string( $state['current_code'] )
		) {
			return null;
		}

		$code = strtolower( $state['current_code'] );
		if ( 1 !== preg_match( '/^cartpops-system-reward-[a-f0-9]{32}$/', $code ) ) {
			return null;
		}

		return array(
			'version'      => 1,
			'current_code' => $code,
		);
	}

	/**
	 * Check whether a merchant coupon owns the exact code.
	 *
	 * @param string $code Coupon identifier.
	 */
	private static function real_coupon_exists( string $code ): bool {
		$coupon_id = function_exists( 'wc_get_coupon_id_by_code' ) ? wc_get_coupon_id_by_code( $code ) : 0;
		return is_numeric( $coupon_id ) && (int) $coupon_id > 0;
	}

	/**
	 * Acquire one short-lived site-local option lock for identifier rotation.
	 *
	 * @param SystemRewardCouponOptionStore $store Exact persistence boundary.
	 */
	private static function acquire_rotation_lock( SystemRewardCouponOptionStore $store ): ?string {
		try {
			$token = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable ) {
			return null;
		}
		$value = array(
			'token'   => $token,
			'expires' => time() + 30,
		);
		if ( $store->add_non_autoloaded( self::ROTATION_LOCK_OPTION, $value ) ) {
			$row = self::read_option_row( self::ROTATION_LOCK_OPTION, $store );
			return is_array( $row ) && $row['value'] === $value ? $token : null;
		}

		$existing      = self::read_option_row( self::ROTATION_LOCK_OPTION, $store );
		$existing_lock = is_array( $existing ) ? self::lock_from_row( $existing ) : null;
		if (
			is_array( $existing )
			&& null !== $existing_lock
			&& $existing_lock['expires'] < time()
			&& self::compare_replace_option( self::ROTATION_LOCK_OPTION, $existing['raw'], $value, $store )
		) {
			return $token;
		}

		return null;
	}

	/**
	 * Release only the exact lock owned by this request.
	 *
	 * @param string                        $token Exact lock-owner token.
	 * @param SystemRewardCouponOptionStore $store Exact persistence boundary.
	 */
	private static function release_rotation_lock( string $token, SystemRewardCouponOptionStore $store ): void {
		$current = self::read_option_row( self::ROTATION_LOCK_OPTION, $store );
		$lock    = is_array( $current ) ? self::lock_from_row( $current ) : null;
		if (
			is_array( $current )
			&& null !== $lock
			&& hash_equals( $token, $lock['token'] )
		) {
			self::compare_delete_option( self::ROTATION_LOCK_OPTION, $current['raw'], $store );
		}
	}

	/**
	 * Read one option directly from the current site's table, bypassing caches.
	 *
	 * @param string                             $name  Site-local option name.
	 * @param SystemRewardCouponOptionStore|null $store Explicit persistence boundary.
	 * @return array{raw: string, value: mixed}|null|false False means read failure.
	 */
	private static function read_option_row( string $name, ?SystemRewardCouponOptionStore $store = null ): array|false|null {
		$store ??= new WordPressSystemRewardCouponOptionStore();
		$raw     = $store->read_raw( $name );
		if ( false === $raw ) {
			return false;
		}
		if ( null === $raw ) {
			return null;
		}

		return array(
			'raw'   => $raw,
			'value' => self::decode_internal_option( $raw ),
		);
	}

	/**
	 * Decode a bounded internal option without instantiating serialized classes.
	 *
	 * @param string $raw Exact serialized database bytes.
	 * @return array<mixed>|null
	 */
	private static function decode_internal_option( string $raw ): ?array {
		if ( '' === $raw || strlen( $raw ) > self::MAX_OPTION_BYTES ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Converts malformed internal option warnings into a fail-closed null result.
		set_error_handler( static fn(): bool => true );
		try {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Internal data is bounded and class construction is explicitly disabled.
			$value = unserialize( $raw, array( 'allowed_classes' => false ) );
		} catch ( \Throwable ) {
			return null;
		} finally {
			restore_error_handler();
		}

		return is_array( $value ) ? $value : null;
	}

	/**
	 * Validate one exact lock document.
	 *
	 * @param array{raw: string, value: mixed} $row Exact option row.
	 * @return array{token: string, expires: int}|null
	 */
	private static function lock_from_row( array $row ): ?array {
		$value = $row['value'];
		if (
			! is_array( $value )
			|| 2 !== count( $value )
			|| ! array_key_exists( 'token', $value )
			|| ! array_key_exists( 'expires', $value )
			|| ! is_string( $value['token'] )
			|| 1 !== preg_match( '/^[a-f0-9]{32}$/', $value['token'] )
			|| ! is_int( $value['expires'] )
		) {
			return null;
		}

		return array(
			'token'   => $value['token'],
			'expires' => $value['expires'],
		);
	}

	/**
	 * Replace only the exact option row which was previously observed.
	 *
	 * @param string                             $name         Site-local option name.
	 * @param string                             $expected_raw Exact serialized value previously read.
	 * @param mixed                              $replacement  Replacement option value.
	 * @param SystemRewardCouponOptionStore|null $store        Explicit persistence boundary.
	 */
	private static function compare_replace_option( string $name, string $expected_raw, mixed $replacement, ?SystemRewardCouponOptionStore $store = null ): bool {
		$store ??= new WordPressSystemRewardCouponOptionStore();
		return $store->compare_replace_non_autoloaded( $name, $expected_raw, $replacement );
	}

	/**
	 * Delete only the exact option row which was previously observed.
	 *
	 * @param string                             $name         Site-local option name.
	 * @param string                             $expected_raw Exact serialized value previously read.
	 * @param SystemRewardCouponOptionStore|null $store        Explicit persistence boundary.
	 */
	private static function compare_delete_option( string $name, string $expected_raw, ?SystemRewardCouponOptionStore $store = null ): bool {
		$store ??= new WordPressSystemRewardCouponOptionStore();
		return $store->compare_delete( $name, $expected_raw );
	}

	/** Return WooCommerce's current session boundary when available. */
	private static function session(): ?object {
		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		$session     = is_object( $woocommerce ) ? ( $woocommerce->session ?? null ) : null;
		return is_object( $session ) ? $session : null;
	}
}
