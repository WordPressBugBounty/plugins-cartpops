<?php
/**
 * Exact WooCommerce session state and hydration basis.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Cart;

/** Keeps Woo's live dirty state distinct from its durable session row. */
final class WooSessionState {
	private const MAX_SESSION_BYTES     = 8388608;
	private const MAX_SESSION_ENTRIES   = 4096;
	private const MAX_SESSION_DEPTH     = 32;
	private const MAX_CUSTOMER_ID_BYTES = 32;

	/**
	 * Cart/session keys that must match exact durable hydration.
	 *
	 * @var string[]
	 */
	private const CART_CRITICAL_KEYS = array(
		'applied_coupons',
		'cart',
		'cart_totals',
		'coupon_discount_tax_totals',
		'coupon_discount_totals',
		'order_awaiting_payment',
		'removed_cart_contents',
	);

	/**
	 * Store exact live, durable, and reflection-backed session state.
	 *
	 * @param object                                                    $session Session handler.
	 * @param array<string, mixed>                                      $live_data Exact live data.
	 * @param bool                                                      $live_dirty Exact dirty flag.
	 * @param \ReflectionProperty                                       $data_property Live-data property.
	 * @param \ReflectionProperty                                       $dirty_property Dirty property.
	 * @param string                                                    $table Woo session table.
	 * @param string                                                    $customer_id Woo customer/session ID.
	 * @param array{session_value: string, session_expiry: string}|null $database_row Exact durable row.
	 */
	private function __construct(
		private readonly object $session,
		private readonly array $live_data,
		private readonly bool $live_dirty,
		private readonly \ReflectionProperty $data_property,
		private readonly \ReflectionProperty $dirty_property,
		private readonly string $table,
		private readonly string $customer_id,
		private readonly ?array $database_row,
	) {}

	/**
	 * Capture the live payload and exact durable row without flushing either.
	 *
	 * @param object|null $session Session handler.
	 * @throws \RuntimeException When a supported Woo session cannot be captured.
	 */
	public static function capture( ?object $session ): ?self {
		if ( null === $session ) {
			return null;
		}
		$data_property  = self::property( $session, '_data' );
		$dirty_property = self::property( $session, '_dirty' );
		if ( null === $data_property || null === $dirty_property ) {
			throw new \RuntimeException( 'WooCommerce live session state cannot be captured exactly.' );
		}
		$live_data  = $data_property->getValue( $session );
		$live_dirty = $dirty_property->getValue( $session );
		if ( ! is_array( $live_data ) || ! is_bool( $live_dirty ) ) {
			throw new \RuntimeException( 'WooCommerce live session state has an unsupported shape.' );
		}

		$identity = self::database_identity( $session );
		if ( null === $identity || ! self::database_is_available() ) {
			throw new \RuntimeException( 'WooCommerce persisted session state cannot be captured exactly.' );
		}

		return new self(
			$session,
			$live_data,
			$live_dirty,
			$data_property,
			$dirty_property,
			$identity['table'],
			$identity['customer_id'],
			self::read_database_row( $identity['table'], $identity['customer_id'] )
		);
	}

	/**
	 * After the row lock is held, reject stale hydration and first-write races.
	 *
	 * @param CartSessionTransactionMode $mode Exact hydration policy.
	 * @throws CartSessionConflictException When durable state changed or is newer.
	 * @phpstan-throws \RuntimeException When current state cannot be read exactly.
	 */
	public function assert_current_basis( CartSessionTransactionMode $mode ): void {
		$current_row  = self::read_database_row( $this->table, $this->customer_id );
		$current_live = $this->current_live_data();
		if ( $current_row !== $this->database_row || $current_live !== $this->live_data ) {
			$this->refresh_and_disable_persistence( $current_row );
			throw new CartSessionConflictException( 'The WooCommerce session changed before its lock was acquired.' );
		}

		$durable = null === $this->database_row
			? array()
			: self::decode_session_payload( $this->database_row['session_value'] );
		if (
			! self::live_contains_durable_basis( $this->live_data, $durable )
			&& ! ( CartSessionTransactionMode::LOCAL_DIRTY_OBSERVATION === $mode && $this->live_dirty )
		) {
			$this->refresh_and_disable_persistence( $current_row );
			throw new CartSessionConflictException( 'The WooCommerce session was hydrated from stale durable state.' );
		}
	}

	/** Prevent any later shutdown callback from persisting a conflicted request. */
	public function quarantine_after_conflict(): void {
		$this->dirty_property->setValue( $this->session, false );
		$this->disable_shutdown_persistence();
		$this->clear_session_cache();
	}

	/**
	 * Retain a server-render's exact local Woo state but prevent stale shutdown.
	 *
	 * @throws \RuntimeException When local state cannot be restored or persistence cannot be disabled.
	 */
	public function quarantine_after_observation_conflict(): void {
		$this->data_property->setValue( $this->session, $this->live_data );
		$this->dirty_property->setValue( $this->session, $this->live_dirty );
		if ( $this->current_live_data() !== $this->live_data ) {
			throw new \RuntimeException( 'WooCommerce local observation state could not be preserved.' );
		}
		$this->disable_shutdown_persistence();
		$this->clear_session_cache();
	}

	/**
	 * Restore the exact durable and live layers after database rollback.
	 *
	 * @throws \RuntimeException When a successor row exists or live restoration fails.
	 */
	public function restore(): void {
		$current = self::read_database_row( $this->table, $this->customer_id );
		if ( $current !== $this->database_row ) {
			throw new \RuntimeException( 'A newer WooCommerce session row superseded this request.' );
		}
		$this->data_property->setValue( $this->session, $this->live_data );
		$this->dirty_property->setValue( $this->session, $this->live_dirty );
		if ( $this->current_live_data() !== $this->live_data ) {
			throw new \RuntimeException( 'WooCommerce live session data was not restored.' );
		}
		$this->clear_session_cache();
	}

	/**
	 * Persist the current live session once and prove exact durable bytes.
	 *
	 * @throws \RuntimeException When persistence or exact durable readback fails.
	 */
	public function persist_current(): void {
		if ( ! method_exists( $this->session, 'save_data' ) ) {
			throw new \RuntimeException( 'WooCommerce session cannot be persisted.' );
		}
		global $wpdb;
		self::clear_database_error( $wpdb );
		$this->session->save_data();
		if ( '' !== self::database_error( $wpdb ) ) {
			throw new \RuntimeException( 'WooCommerce session persistence was rejected by the database.' );
		}
		$current_live = $this->current_live_data();
		$row          = self::read_database_row( $this->table, $this->customer_id );
		if ( null === $row ) {
			throw new \RuntimeException( 'WooCommerce session changes were not persisted.' );
		}
		if ( maybe_serialize( $current_live ) !== $row['session_value'] ) {
			throw new \RuntimeException( 'WooCommerce session persistence did not retain exact bytes.' );
		}
	}

	/**
	 * Prevent shutdown persistence and evict caches after an unknown outcome.
	 *
	 * Durable state is deliberately not guessed or overwritten.
	 */
	public function quarantine_after_unknown_outcome(): void {
		$this->dirty_property->setValue( $this->session, false );
		$this->disable_shutdown_persistence();
		$this->clear_session_cache();
	}

	/**
	 * Resolve exact classic, Store API, or explicit local-adapter identity.
	 *
	 * @param object $session Session handler.
	 * @return array{table: string, customer_id: string}|null
	 */
	public static function database_identity( object $session ): ?array {
		if ( $session instanceof WooSessionIdentityAdapter ) {
			return self::validate_identity( $session->cartpops_database_identity() );
		}
		$session_class = get_class( $session );
		$is_classic    = 'WC_Session_Handler' === $session_class;
		$is_store      = hash_equals( 'Automattic\\WooCommerce\\StoreApi\\SessionHandler', $session_class )
			&& is_subclass_of( $session, 'WC_Session' );
		if ( ! $is_classic && ! $is_store ) {
			return null;
		}
		$table = self::string_property( $session, '_table' ) ?? self::string_property( $session, 'table' );
		if ( null === $table || ! method_exists( $session, 'get_customer_id' ) ) {
			return null;
		}
		$customer_id = $session->get_customer_id();
		return self::validate_identity(
			array(
				'table'       => $table,
				'customer_id' => is_int( $customer_id ) || is_string( $customer_id ) ? (string) $customer_id : '',
			)
		);
	}

	/**
	 * Read the current reflection-backed live session payload.
	 *
	 * @return array<string, mixed>
	 * @throws \RuntimeException When the live payload shape is unsupported.
	 */
	private function current_live_data(): array {
		$data = $this->data_property->getValue( $this->session );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'WooCommerce live session state cannot be read.' );
		}
		return $data;
	}

	/** Determine whether the exact raw database seam is available. */
	private static function database_is_available(): bool {
		global $wpdb;
		return is_object( $wpdb )
			&& method_exists( $wpdb, 'prepare' )
			&& method_exists( $wpdb, 'get_row' );
	}

	/**
	 * Read one exact bounded durable Woo session row.
	 *
	 * @param string $table Validated Woo session table.
	 * @param string $customer_id Exact Woo customer/session ID.
	 * @return array{session_value: string, session_expiry: string}|null
	 * @throws \RuntimeException When the row cannot be read or exceeds safe bounds.
	 */
	private static function read_database_row( string $table, string $customer_id ): ?array {
		global $wpdb;
		self::clear_database_error( $wpdb );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Validated Woo table identifier.
		$query = $wpdb->prepare( "SELECT session_value, session_expiry FROM {$table} WHERE session_key = %s", $customer_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Exact uncached durable read.
		$row = $wpdb->get_row( $query, ARRAY_A );
		if ( '' !== self::database_error( $wpdb ) ) {
			throw new \RuntimeException( 'WooCommerce persisted session row could not be read.' );
		}
		if ( null === $row ) {
			return null;
		}
		if (
			! is_array( $row )
			|| ! is_string( $row['session_value'] ?? null )
			|| strlen( $row['session_value'] ) > self::MAX_SESSION_BYTES
			|| ! is_scalar( $row['session_expiry'] ?? null )
		) {
			throw new \RuntimeException( 'WooCommerce persisted session row has an unsupported shape.' );
		}
		return array(
			'session_value'  => $row['session_value'],
			'session_expiry' => (string) $row['session_expiry'],
		);
	}

	/**
	 * Validate an explicit durable Woo session identity.
	 *
	 * @param mixed $identity Candidate identity record.
	 * @return array{table: string, customer_id: string}|null
	 */
	private static function validate_identity( mixed $identity ): ?array {
		if (
			! is_array( $identity )
			|| ! is_string( $identity['table'] ?? null )
			|| 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $identity['table'] )
			|| ! is_string( $identity['customer_id'] ?? null )
			|| '' === $identity['customer_id']
			|| strlen( $identity['customer_id'] ) > self::MAX_CUSTOMER_ID_BYTES
		) {
			return null;
		}
		return array(
			'table'       => $identity['table'],
			'customer_id' => $identity['customer_id'],
		);
	}

	/**
	 * Safely decode a bounded scalar/array Woo session payload.
	 *
	 * @param string $payload Exact durable Woo session bytes.
	 * @throws \RuntimeException When serialized data is malformed or unsafe.
	 */
	private static function decode_session_payload( string $payload ): array {
		if ( '' === $payload ) {
			return array();
		}
		try {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Woo stores this bounded field as PHP serialization; classes are forbidden explicitly.
			$value = @unserialize( $payload, array( 'allowed_classes' => false ) );
		} catch ( \Throwable $error ) {
			unset( $error );
			throw new \RuntimeException( 'WooCommerce persisted session data is malformed.' );
		}
		if ( ! is_array( $value ) ) {
			throw new \RuntimeException( 'WooCommerce persisted session data has an unsupported shape.' );
		}
		$entries = 0;
		if ( ! self::is_safe_value( $value, 0, $entries ) ) {
			throw new \RuntimeException( 'WooCommerce persisted session data exceeds safe bounds.' );
		}
		return $value;
	}

	/**
	 * Recursively reject objects/resources and bound container complexity.
	 *
	 * @param mixed $value Candidate nested value.
	 * @param int   $depth Current nesting depth.
	 * @param int   $entries Running bounded entry count.
	 */
	private static function is_safe_value( mixed $value, int $depth, int &$entries ): bool {
		if ( $depth > self::MAX_SESSION_DEPTH || $entries > self::MAX_SESSION_ENTRIES ) {
			return false;
		}
		if ( ! is_array( $value ) ) {
			return is_null( $value ) || is_scalar( $value );
		}
		foreach ( $value as $key => $item ) {
			++$entries;
			if ( ( ! is_int( $key ) && ! is_string( $key ) ) || ! self::is_safe_value( $item, $depth + 1, $entries ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Ensure durable keys were not changed/removed and cart keys were not added.
	 *
	 * @param array<string, mixed> $live Live payload hydrated by Woo.
	 * @param array<string, mixed> $durable Exact durable payload before locking.
	 */
	private static function live_contains_durable_basis( array $live, array $durable ): bool {
		foreach ( $durable as $key => $value ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exact safe scalar/array comparison must match Woo's native serialization semantics.
			if ( ! is_string( $key ) || ! array_key_exists( $key, $live ) || serialize( $live[ $key ] ) !== serialize( $value ) ) {
				return false;
			}
		}
		foreach ( $live as $key => $value ) {
			if ( ! is_string( $key ) ) {
				return false;
			}
			$is_critical = in_array( $key, self::CART_CRITICAL_KEYS, true ) || str_starts_with( $key, 'cartpops_' );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exact safe scalar/array comparison must match Woo's native serialization semantics.
			if ( $is_critical && ( ! array_key_exists( $key, $durable ) || serialize( $value ) !== serialize( $durable[ $key ] ) ) ) {
				return false;
			}
		}
		return true;
	}

	/** Evict Woo's session object-cache entry. */
	private function clear_session_cache(): void {
		if (
			! defined( 'WC_SESSION_CACHE_GROUP' )
			|| ! class_exists( 'WC_Cache_Helper' )
			|| ! function_exists( 'wp_cache_delete' )
		) {
			return;
		}
		$prefix = \WC_Cache_Helper::get_cache_prefix( WC_SESSION_CACHE_GROUP );
		wp_cache_delete( $prefix . $this->customer_id, WC_SESSION_CACHE_GROUP );
	}

	/**
	 * Replace stale live state with locked durable truth, then forbid writes.
	 *
	 * @param array{session_value: string, session_expiry: string}|null $row Exact locked durable row.
	 * @throws \RuntimeException When exact durable refresh or persistence quarantine fails.
	 */
	private function refresh_and_disable_persistence( ?array $row ): void {
		$data = null === $row ? array() : self::decode_session_payload( $row['session_value'] );
		$this->data_property->setValue( $this->session, $data );
		$this->dirty_property->setValue( $this->session, false );
		if ( $this->current_live_data() !== $data ) {
			throw new \RuntimeException( 'WooCommerce stale session state could not be quarantined.' );
		}
		$this->disable_shutdown_persistence();
		$this->clear_session_cache();
	}

	/**
	 * Remove Woo's exact native shutdown writer for this conflicted request.
	 *
	 * @throws \RuntimeException When deferred persistence cannot be disabled exactly.
	 */
	private function disable_shutdown_persistence(): void {
		if ( $this->session instanceof WooSessionPersistenceAdapter ) {
			if ( ! $this->session->cartpops_disable_persistence() ) {
				throw new \RuntimeException( 'WooCommerce deferred session persistence could not be disabled.' );
			}
			return;
		}
		if ( function_exists( 'remove_action' ) ) {
			remove_action( 'shutdown', array( $this->session, 'save_data' ), 20 );
			return;
		}
		throw new \RuntimeException( 'WooCommerce deferred session persistence is unsupported.' );
	}

	/**
	 * Resolve a protected property across supported Woo versions.
	 *
	 * @param object $subject Supported Woo object.
	 * @param string $name Exact protected property name.
	 */
	private static function property( object $subject, string $name ): ?\ReflectionProperty {
		try {
			$property = ( new \ReflectionObject( $subject ) )->getProperty( $name );
			return $property;
		} catch ( \ReflectionException $error ) {
			unset( $error );
			return null;
		}
	}

	/**
	 * Read a canonical protected table property.
	 *
	 * @param object $subject Supported Woo object.
	 * @param string $name Exact protected property name.
	 */
	private static function string_property( object $subject, string $name ): ?string {
		$property = self::property( $subject, $name );
		if ( null === $property ) {
			return null;
		}
		try {
			$value = $property->getValue( $subject );
		} catch ( \Error $error ) {
			unset( $error );
			return null;
		}
		return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_]+$/D', $value ) ? $value : null;
	}

	/**
	 * Clear mutable wpdb error state without static-analysis literal narrowing.
	 *
	 * @param object $database WordPress database object.
	 */
	private static function clear_database_error( object $database ): void {
		/**
		 * Typed WordPress database object.
		 *
		 * @var \wpdb $database
		 */
		$database->last_error = '';
	}

	/**
	 * Read mutable wpdb error state after the immediately preceding query.
	 *
	 * @param object $database WordPress database object.
	 * @phpstan-impure The value is mutated externally by wpdb operations.
	 */
	private static function database_error( object $database ): string {
		/**
		 * Typed WordPress database object.
		 *
		 * @var \wpdb $database
		 */
		return (string) $database->last_error;
	}
}
