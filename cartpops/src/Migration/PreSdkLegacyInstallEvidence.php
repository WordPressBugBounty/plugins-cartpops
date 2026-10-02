<?php
/**
 * Captures value-free V1 installation evidence before Freemius can update it.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Migration;

/**
 * Immutable request evidence for an otherwise indistinguishable zero-option V1 site.
 *
 * Freemius persists the current plugin version during SDK initialization. Capture
 * therefore happens before the SDK starts and retains only the exact historical
 * basename plus proven blog/network boundary, never account, customer, license,
 * credential, or entitlement values.
 */
final class PreSdkLegacyInstallEvidence {

	public const NETWORK_COHORT_OPTION          = 'cartpops_v1_network_legacy_cohort_v1';
	public const SITE_EVIDENCE_OPTION           = 'cartpops_v1_pre_sdk_evidence_v1';
	public const SITE_MARKER_MISSING            = 'missing';
	public const SITE_MARKER_CURRENT            = 'current';
	public const SITE_MARKER_PROMOTABLE_LEGACY  = 'promotable_legacy';
	public const SITE_MARKER_LEGACY_UNAVAILABLE = 'legacy_unavailable';
	public const SITE_MARKER_FUTURE             = 'future';
	public const SITE_MARKER_INVALID            = 'invalid';

	private const SCHEMA_VERSION           = 1;
	private const SITE_MARKER_SCHEMA       = 2;
	private const COMPLETION_OPTION        = 'cartpops_v1_migration_version';
	private const MAX_RAW_BYTES            = 1048576;
	private const BOUNDED_VALUE_BYTES      = self::MAX_RAW_BYTES + 1;
	private const MAX_CONTROL_BYTES        = 4096;
	private const BOUNDED_CONTROL_BYTES    = self::MAX_CONTROL_BYTES + 1;
	private const BOUNDED_VALUE_ALIAS      = 'cartpops_pre_sdk_bounded_value';
	private const BOUNDED_LENGTH_ALIAS     = 'cartpops_pre_sdk_byte_length';
	private const BOUNDED_PROBE_ALIAS      = 'cartpops_pre_sdk_probe';
	private const BOUNDED_AUTOLOAD_ALIAS   = 'cartpops_pre_sdk_autoload';
	private const MAX_VALUE_DEPTH          = 64;
	private const MAX_VALUE_NODES          = 10000;
	private const LEGACY_NETWORK_BASENAMES = array(
		'cartpops/cartpops.php',
		'cartpops-pro/cartpops.php',
	);

	/**
	 * Construct an immutable value-free capture.
	 *
	 * @param int|null    $proven_site_id       Exact directly proven site.
	 * @param string|null $proven_site_basename Exact historical site edition.
	 * @param int|null    $network_id           Exact network at capture time.
	 * @param int|null    $network_high_water   Immutable historical network boundary.
	 * @param string|null $network_basename     Exact historical network edition.
	 * @param bool        $capture_available    Whether every required pre-SDK read completed.
	 */
	private function __construct(
		private readonly ?int $proven_site_id,
		private readonly ?string $proven_site_basename,
		private readonly ?int $network_id,
		private readonly ?int $network_high_water,
		private readonly ?string $network_basename,
		private readonly bool $capture_available
	) {}

	/** Return a clean capture containing no positive legacy evidence. */
	public static function unknown(): self {
		$network_id = function_exists( 'get_current_network_id' ) ? get_current_network_id() : null;
		$network_id = is_int( $network_id ) && $network_id > 0 ? $network_id : null;
		return new self( null, null, $network_id, null, null, true );
	}

	/** Return a value-free failed-capture state that must never prove V2-born. */
	public static function unavailable(): self {
		$network_id = function_exists( 'get_current_network_id' ) ? get_current_network_id() : null;
		$network_id = is_int( $network_id ) && $network_id > 0 ? $network_id : null;
		return new self( null, null, $network_id, null, null, false );
	}

	/**
	 * Capture current-site and network evidence before Freemius initialization.
	 *
	 * @throws MigrationReadException When an exact database boundary is uncertain.
	 */
	public static function capture(): self {
		$blog_id    = CanonicalInteger::parse( get_current_blog_id(), 1 );
		$network_id = CanonicalInteger::parse( get_current_network_id(), 1 );
		if ( null === $blog_id || null === $network_id ) {
			throw new MigrationReadException( 'pre_sdk_site_identity_unavailable' );
		}

		$site_basename = self::current_site_historical_basename( $blog_id );
		$cohort        = is_multisite() ? self::network_legacy_cohort() : null;
		if ( get_current_blog_id() !== $blog_id || get_current_network_id() !== $network_id ) {
			throw new MigrationReadException( 'pre_sdk_site_identity_drift' );
		}
		if (
			null !== $site_basename
			&& null !== $cohort
			&& $blog_id <= $cohort['max_site_id']
			&& $site_basename !== $cohort['basename']
		) {
			throw new MigrationReadException( 'pre_sdk_legacy_identity_conflict' );
		}

		$historical_basename = $site_basename;
		if ( null === $historical_basename && null !== $cohort && $blog_id <= $cohort['max_site_id'] ) {
			$historical_basename = $cohort['basename'];
		}
		$evidence = new self(
			null !== $site_basename ? $blog_id : null,
			$site_basename,
			$network_id,
			$cohort['max_site_id'] ?? null,
			$cohort['basename'] ?? null,
			true
		);
		if ( null !== $historical_basename ) {
			self::preserve_site_identity_before_sdk( $blog_id, $network_id, $historical_basename, $evidence );
		}

		return $evidence;
	}

	/**
	 * Persist the closed site identity before Freemius can mutate its source row.
	 *
	 * The write is first-writer, bounded, non-autoloaded, and contains only the
	 * schema, exact site/network IDs, and exact historical Free/Pro basename. A
	 * concurrent terminal migration either consumes this row or is observed immediately
	 * afterward so a late insert cannot survive a genuine completion.
	 *
	 * @param int    $blog_id    Exact current blog ID.
	 * @param int    $network_id Exact current network ID.
	 * @param string $basename   Exact historical Free or Pro basename.
	 * @param self   $evidence   Proof-aware request-local capture.
	 * @throws MigrationReadException When the closed retry authority cannot be proven durable.
	 */
	private static function preserve_site_identity_before_sdk( int $blog_id, int $network_id, string $basename, self $evidence ): void {
		global $wpdb;

		if (
			! in_array( $basename, self::LEGACY_NETWORK_BASENAMES, true )
			|| ! is_object( $wpdb )
			|| ! isset( $wpdb->last_error )
			|| ! is_string( $wpdb->last_error )
			|| ! method_exists( $wpdb, 'get_blog_prefix' )
			|| ! method_exists( $wpdb, 'get_results' )
			|| ! method_exists( $wpdb, 'prepare' )
			|| ! method_exists( $wpdb, 'query' )
		) {
			throw new MigrationReadException( 'pre_sdk_site_evidence_unavailable' );
		}

		$database      = $wpdb;
		$prefix        = $database->get_blog_prefix( $blog_id );
		$options_table = is_string( $prefix ) ? $prefix . 'options' : '';
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $options_table ) ) {
			throw new MigrationReadException( 'pre_sdk_site_evidence_unavailable' );
		}
		self::assert_site_binding( $database, $blog_id, $network_id );

		$completion = self::read_site_control_option( $database, $options_table, self::COMPLETION_OPTION );
		self::assert_site_binding( $database, $blog_id, $network_id );
		if ( self::completion_is_current( $completion ) ) {
			return;
		}

		$record = array(
			'schema_version' => self::SITE_MARKER_SCHEMA,
			'site_id'        => $blog_id,
			'network_id'     => $network_id,
			'basename'       => $basename,
		);
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Closed scalar-only internal marker must match WordPress' deterministic stored bytes without invoking option deserialization.
		$raw = serialize( $record );
		$row = self::read_site_control_option( $database, $options_table, self::SITE_EVIDENCE_OPTION );
		self::assert_site_binding( $database, $blog_id, $network_id );
		$marker = $evidence->classify_site_marker( $row, $blog_id, $network_id );
		if (
			$row['exists']
			&& self::SITE_MARKER_PROMOTABLE_LEGACY === $marker['kind']
			&& $record === $marker['record']
		) {
			$database->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact old bytes are replaced only while current pre-SDK source evidence independently proves identity.
			$replaced = $database->query(
				$database->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The validated current-blog table is immutable; values use placeholders.
					"UPDATE {$options_table} SET option_value = %s, autoload = %s WHERE option_name = %s AND BINARY option_value = %s",
					$raw,
					'no',
					self::SITE_EVIDENCE_OPTION,
					$row['raw_value']
				)
			);
			self::assert_site_binding( $database, $blog_id, $network_id );
			if ( false === $replaced || '' !== MigrationDatabaseState::last_error( $database ) || ! in_array( (int) $replaced, array( 0, 1 ), true ) ) {
				throw new MigrationReadException( 'pre_sdk_site_evidence_write_failed' );
			}
			if ( 1 === (int) $replaced ) {
				self::invalidate_site_option_cache( self::SITE_EVIDENCE_OPTION );
			}
			$row = self::read_site_control_option( $database, $options_table, self::SITE_EVIDENCE_OPTION );
			self::assert_site_binding( $database, $blog_id, $network_id );
		}
		if ( ! $row['exists'] ) {
			$database->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Earliest exact first-writer preservation must precede SDK initialization.
			$inserted = $database->query(
				$database->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The validated current-blog table is immutable; values use placeholders.
					"INSERT IGNORE INTO {$options_table} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
					self::SITE_EVIDENCE_OPTION,
					$raw,
					'no'
				)
			);
			self::assert_site_binding( $database, $blog_id, $network_id );
			if ( false === $inserted || '' !== MigrationDatabaseState::last_error( $database ) || ! in_array( (int) $inserted, array( 0, 1 ), true ) ) {
				throw new MigrationReadException( 'pre_sdk_site_evidence_write_failed' );
			}
			if ( 1 === (int) $inserted ) {
				self::invalidate_site_option_cache( self::SITE_EVIDENCE_OPTION );
			}
			$row = self::read_site_control_option( $database, $options_table, self::SITE_EVIDENCE_OPTION );
			self::assert_site_binding( $database, $blog_id, $network_id );
		}

		self::assert_site_evidence_row( $row, $record, $raw );
		if ( ! self::autoload_is_disabled( $row['autoload'] ) ) {
			if ( ! self::autoload_is_recognized( $row['autoload'] ) ) {
				throw new MigrationReadException( 'pre_sdk_site_evidence_invalid' );
			}
			$database->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact bytes prevent retargeting while repairing internal autoload metadata.
			$repaired = $database->query(
				$database->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The validated current-blog table is immutable; values use placeholders.
					"UPDATE {$options_table} SET option_value = %s, autoload = %s WHERE option_name = %s AND BINARY option_value = %s",
					$raw,
					'no',
					self::SITE_EVIDENCE_OPTION,
					$row['raw_value']
				)
			);
			self::assert_site_binding( $database, $blog_id, $network_id );
			if ( false === $repaired || '' !== MigrationDatabaseState::last_error( $database ) ) {
				throw new MigrationReadException( 'pre_sdk_site_evidence_write_failed' );
			}
			self::invalidate_site_option_cache( self::SITE_EVIDENCE_OPTION );
			$row = self::read_site_control_option( $database, $options_table, self::SITE_EVIDENCE_OPTION );
			self::assert_site_binding( $database, $blog_id, $network_id );
			self::assert_site_evidence_row( $row, $record, $raw );
			if ( ! self::autoload_is_disabled( $row['autoload'] ) ) {
				throw new MigrationReadException( 'pre_sdk_site_evidence_write_failed' );
			}
		}

		$completion = self::read_site_control_option( $database, $options_table, self::COMPLETION_OPTION );
		self::assert_site_binding( $database, $blog_id, $network_id );
		if ( ! self::completion_is_current( $completion ) ) {
			return;
		}

		$database->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Remove only this exact late marker after observing a concurrent genuine completion.
		$deleted = $database->query(
			$database->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The validated current-blog table is immutable; values use placeholders.
				"DELETE FROM {$options_table} WHERE option_name = %s AND BINARY option_value = %s",
				self::SITE_EVIDENCE_OPTION,
				$raw
			)
		);
		self::assert_site_binding( $database, $blog_id, $network_id );
		if ( false === $deleted || '' !== MigrationDatabaseState::last_error( $database ) ) {
			throw new MigrationReadException( 'pre_sdk_site_evidence_write_failed' );
		}
		if ( 1 === (int) $deleted ) {
			self::invalidate_site_option_cache( self::SITE_EVIDENCE_OPTION );
		}
		$remaining = self::read_site_control_option( $database, $options_table, self::SITE_EVIDENCE_OPTION );
		self::assert_site_binding( $database, $blog_id, $network_id );
		if ( $remaining['exists'] ) {
			throw new MigrationReadException( 'pre_sdk_site_evidence_write_failed' );
		}
	}

	/**
	 * Classify one durable site marker through the single proof-aware authority.
	 *
	 * A schema-1 row is promotable only when its exact canonical bytes agree with
	 * direct current-site historical source evidence captured before the SDK. A
	 * network cohort can establish a missing marker, but can never promote an
	 * unscoped first-writer row whose network of origin is unknowable.
	 *
	 * @param array<string, mixed> $row        Exact raw option row or gap.
	 * @param int                  $blog_id    Exact intended blog ID.
	 * @param int                  $network_id Exact intended network ID.
	 * @return array{kind: string, basename: string|null, record: array<string, mixed>|null, legacy_exact: bool}
	 */
	public function classify_site_marker( array $row, int $blog_id, int $network_id ): array {
		$missing = array(
			'kind'         => self::SITE_MARKER_MISSING,
			'basename'     => null,
			'record'       => null,
			'legacy_exact' => false,
		);
		if ( false === ( $row['exists'] ?? null ) ) {
			return $missing;
		}
		if (
			true !== ( $row['exists'] ?? null )
			|| ! is_string( $row['raw_value'] ?? null )
			|| strlen( $row['raw_value'] ) > self::MAX_CONTROL_BYTES
			|| true === ( $row['overbound'] ?? false )
		) {
			$missing['kind'] = self::SITE_MARKER_INVALID;
			return $missing;
		}

		$decoded = ( new SafeSerializedReader() )->decode(
			$row['raw_value'],
			self::MAX_CONTROL_BYTES,
			self::MAX_VALUE_DEPTH,
			self::MAX_VALUE_NODES
		);
		if ( ! $decoded['safe'] || ! is_array( $decoded['value'] ) ) {
			$missing['kind'] = self::SITE_MARKER_INVALID;
			return $missing;
		}
		$candidate = $decoded['value'];
		$schema    = CanonicalInteger::parse( $candidate['schema_version'] ?? null, 1 );
		if ( null !== $schema && $schema > self::SITE_MARKER_SCHEMA ) {
			$missing['kind'] = self::SITE_MARKER_FUTURE;
			return $missing;
		}

		if ( self::SCHEMA_VERSION === $schema ) {
			$basename = is_string( $candidate['basename'] ?? null ) ? $candidate['basename'] : null;
			$record   = array(
				'schema_version' => self::SCHEMA_VERSION,
				'site_id'        => $blog_id,
				'basename'       => $basename,
			);
			$exact    = in_array( $basename, self::LEGACY_NETWORK_BASENAMES, true )
				&& $record === $candidate
				&& hash_equals( self::serialize_site_marker( $record ), $row['raw_value'] );
			$direct   = $exact
				&& $this->capture_available
				&& $this->network_id === $network_id
				&& $this->proven_site_id === $blog_id
				&& $this->proven_site_basename === $basename;
			if ( $direct ) {
				$scoped = array(
					'schema_version' => self::SITE_MARKER_SCHEMA,
					'site_id'        => $blog_id,
					'network_id'     => $network_id,
					'basename'       => $basename,
				);
				return array(
					'kind'         => self::SITE_MARKER_PROMOTABLE_LEGACY,
					'basename'     => $basename,
					'record'       => $scoped,
					'legacy_exact' => true,
				);
			}

			return array(
				'kind'         => self::SITE_MARKER_LEGACY_UNAVAILABLE,
				'basename'     => $exact ? $basename : null,
				'record'       => null,
				'legacy_exact' => $exact,
			);
		}

		if ( self::SITE_MARKER_SCHEMA === $schema ) {
			$basename = is_string( $candidate['basename'] ?? null ) ? $candidate['basename'] : null;
			$record   = array(
				'schema_version' => self::SITE_MARKER_SCHEMA,
				'site_id'        => $blog_id,
				'network_id'     => $network_id,
				'basename'       => $basename,
			);
			if (
				in_array( $basename, self::LEGACY_NETWORK_BASENAMES, true )
				&& $record === $candidate
				&& hash_equals( self::serialize_site_marker( $record ), $row['raw_value'] )
			) {
				return array(
					'kind'         => self::SITE_MARKER_CURRENT,
					'basename'     => $basename,
					'record'       => $record,
					'legacy_exact' => false,
				);
			}
		}

		$missing['kind'] = self::SITE_MARKER_INVALID;
		return $missing;
	}

	/**
	 * Serialize one closed scalar-only marker to its exact canonical bytes.
	 *
	 * @param array<string, mixed> $record Closed marker record.
	 */
	private static function serialize_site_marker( array $record ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exact internal marker bytes are the CAS authority.
		return serialize( $record );
	}

	/**
	 * Resolve only positive evidence for the current network/site.
	 *
	 * Returning null deliberately leaves ordinary option and post detection active.
	 * This API never returns false because a pre-SDK miss is not proof of a fresh V2
	 * installation.
	 *
	 * @param int $blog_id Candidate current-blog ID.
	 */
	public function for_site( int $blog_id ): ?bool {
		return null !== $this->historical_basename_for_site( $blog_id ) ? true : null;
	}

	/**
	 * Return the exact historical Free or Pro basename for one proven site.
	 *
	 * The basename is product-edition identity only. It contains no customer,
	 * license, credential, or entitlement data.
	 *
	 * @param int $blog_id Candidate current-blog ID.
	 */
	public function historical_basename_for_site( int $blog_id ): ?string {
		$blog_id            = CanonicalInteger::parse( $blog_id, 1 );
		$current_network_id = CanonicalInteger::parse( get_current_network_id(), 1 );
		if ( null === $blog_id || null === $current_network_id || $current_network_id !== $this->network_id ) {
			return null;
		}
		if ( $blog_id === $this->proven_site_id ) {
			return $this->proven_site_basename;
		}
		if ( null !== $this->network_high_water && $blog_id <= $this->network_high_water ) {
			return $this->network_basename;
		}

		return null;
	}

	/** Return the value-free immutable network boundary, primarily for verification. */
	public function captured_network_high_water(): ?int {
		return $this->network_high_water;
	}

	/** Whether the pre-SDK evidence read completed without uncertainty. */
	public function capture_was_available(): bool {
		return $this->capture_available;
	}

	/**
	 * Return or atomically establish the immutable V1 network cohort.
	 *
	 * Historical Freemius identity is insufficient by itself. WordPress must also
	 * independently show one exact V1 Free or Pro basename as network-active.
	 *
	 * @throws MigrationReadException When network evidence cannot be read exactly.
	 */
	public static function network_legacy_high_water(): ?int {
		$cohort = self::network_legacy_cohort();
		return $cohort['max_site_id'] ?? null;
	}

	/**
	 * Return or establish the exact value-free historical network cohort.
	 *
	 * @return array{max_site_id: int, basename: string}|null
	 * @throws MigrationReadException When network evidence cannot be read exactly.
	 */
	private static function network_legacy_cohort(): ?array {
		if ( ! is_multisite() ) {
			return null;
		}

		$network_id = CanonicalInteger::parse( get_current_network_id(), 1 );
		if ( null === $network_id ) {
			throw new MigrationReadException( 'database_read_failed_network_cohort' );
		}
		$network_store = new NetworkOptionStore();
		$stored        = $network_store->read_bounded( self::NETWORK_COHORT_OPTION, self::MAX_RAW_BYTES );
		if ( ! $stored['success'] ) {
			throw new MigrationReadException( 'database_read_failed_network_cohort' );
		}
		if ( $stored['exists'] ) {
			if ( $stored['overbound'] ) {
				throw new MigrationReadException( 'invalid_network_legacy_cohort' );
			}
			$cohort = self::validate_network_cohort_raw( $stored['raw_value'], $network_id );
			if (
				! $network_store->is_non_autoloaded( $stored['autoload'] )
				&& ! $network_store->repair_autoload_bounded( self::NETWORK_COHORT_OPTION, $stored['raw_value'], self::MAX_RAW_BYTES )
			) {
				throw new MigrationReadException( 'network_cohort_autoload_repair_failed' );
			}
			return $cohort;
		}

		$accounts = self::read_network_option( 'fs_accounts', $network_id );
		if ( ! $accounts['exists'] ) {
			return null;
		}
		$identity = self::inspect_accounts_raw( $accounts['raw_value'] );
		if ( null === $identity || ! $identity['product_match'] || ! $identity['historical_version'] ) {
			return null;
		}

		$active = self::read_network_option( 'active_sitewide_plugins', $network_id );
		if ( ! $active['exists'] ) {
			return null;
		}
		$decoded = ( new SafeSerializedReader() )->decode(
			$active['raw_value'],
			self::MAX_RAW_BYTES,
			self::MAX_VALUE_DEPTH,
			self::MAX_VALUE_NODES
		);
		if ( ! $decoded['safe'] || ! is_array( $decoded['value'] ) ) {
			return null;
		}
		$matched = $identity['path'];
		if ( ! array_key_exists( $matched, $decoded['value'] ) ) {
			return null;
		}

		global $wpdb;
		if (
			! is_object( $wpdb )
			|| ! isset( $wpdb->last_error )
			|| ! is_string( $wpdb->last_error )
			|| ! method_exists( $wpdb, 'get_var' )
			|| ! method_exists( $wpdb, 'prepare' )
			|| ! isset( $wpdb->blogs )
			|| ! is_string( $wpdb->blogs )
		) {
			throw new MigrationReadException( 'database_read_failed_network_high_water' );
		}
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One exact high-water read freezes the historical cohort before SDK mutation.
		$maximum = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WordPress owns the table identifier; the network ID uses a value placeholder.
				"SELECT MAX(blog_id) FROM {$wpdb->blogs} WHERE site_id = %d",
				$network_id
			)
		);
		$maximum = CanonicalInteger::parse( $maximum, 1 );
		if (
			'' !== MigrationDatabaseState::last_error( $wpdb )
			|| null === $maximum
			|| get_current_network_id() !== $network_id
		) {
			throw new MigrationReadException( 'database_read_failed_network_high_water' );
		}

		$cohort  = array(
			'schema_version' => self::SCHEMA_VERSION,
			'network_id'     => $network_id,
			'max_site_id'    => $maximum,
			'basename'       => $matched,
		);
		$created = $network_store->add_immutable_bounded( self::NETWORK_COHORT_OPTION, $cohort, self::MAX_RAW_BYTES );
		if ( ! $created['success'] || ! $created['row']['exists'] ) {
			throw new MigrationReadException( 'network_cohort_write_failed' );
		}

		return self::validate_network_cohort_raw( $created['row']['raw_value'], $network_id );
	}

	/**
	 * Decode only the bounded CartPops product identity from Freemius storage.
	 *
	 * @param string $raw Exact fs_accounts bytes.
	 * @return array{product_match: bool, historical_version: bool, plugin_data_present: bool, network_activated: bool, site_present: bool, site_blog_id: int|null, site_plugin_id: int|null, site_uninstalled: bool|null, path: string}|null
	 */
	public static function inspect_accounts_raw( string $raw ): ?array {
		$trimmed = ltrim( $raw );
		if ( '' === $trimmed || strlen( $raw ) > self::MAX_RAW_BYTES ) {
			return null;
		}

		$paths = array(
			'id_slug_type_path_map.7061.slug',
			'id_slug_type_path_map.7061.type',
			'id_slug_type_path_map.7061.path',
			'plugin_data.cartpops',
			'plugin_data.cartpops.plugin_version',
			'plugin_data.cartpops.is_network_activated',
			'sites.cartpops',
			'sites.cartpops.blog_id',
			'sites.cartpops.plugin_id',
			'sites.cartpops.is_uninstalled',
		);
		if ( in_array( $trimmed[0], array( '{', '[' ), true ) ) {
			$decoded = ( new SafeJsonReader() )->decode(
				$trimmed,
				self::MAX_RAW_BYTES,
				self::MAX_VALUE_DEPTH,
				self::MAX_VALUE_NODES
			);
			if ( ! $decoded['success'] || ! is_array( $decoded['value'] ) ) {
				return null;
			}
			$values = self::extract_array_paths( $decoded['value'], $paths );
		} else {
			$extracted = ( new SafeSerializedReader() )->extract_paths(
				$raw,
				$paths,
				self::MAX_RAW_BYTES,
				self::MAX_VALUE_DEPTH,
				self::MAX_VALUE_NODES
			);
			if ( ! $extracted['success'] ) {
				return null;
			}
			$values = $extracted['values'];
		}

		$slug    = $values['id_slug_type_path_map.7061.slug'] ?? null;
		$type    = $values['id_slug_type_path_map.7061.type'] ?? null;
		$path    = $values['id_slug_type_path_map.7061.path'] ?? null;
		$version = $values['plugin_data.cartpops.plugin_version'] ?? null;

		return array(
			'product_match'       => 'cartpops' === $slug
				&& ( null === $type || 'plugin' === $type )
				&& is_string( $path )
				&& in_array( $path, self::LEGACY_NETWORK_BASENAMES, true ),
			'historical_version'  => self::is_historical_plugin_version( $version ),
			'plugin_data_present' => true === ( $values['plugin_data.cartpops'] ?? false ),
			'network_activated'   => true === ( $values['plugin_data.cartpops.is_network_activated'] ?? false ),
			'site_present'        => true === ( $values['sites.cartpops'] ?? false ),
			'site_blog_id'        => CanonicalInteger::parse( $values['sites.cartpops.blog_id'] ?? null, 1 ),
			'site_plugin_id'      => CanonicalInteger::parse( $values['sites.cartpops.plugin_id'] ?? null, 1 ),
			'site_uninstalled'    => is_bool( $values['sites.cartpops.is_uninstalled'] ?? null ) ? $values['sites.cartpops.is_uninstalled'] : null,
			'path'                => is_string( $path ) ? $path : '',
		);
	}

	/**
	 * Read one bounded internal site option without WordPress deserialization.
	 *
	 * @param object $database      Exact captured wpdb object.
	 * @param string $options_table Validated current-blog options table.
	 * @param string $option        Closed internal option name.
	 * @return array{exists: bool, overbound: bool, raw_value: string, autoload: string}
	 * @throws MigrationReadException When the exact row cannot be read safely.
	 */
	private static function read_site_control_option( object $database, string $options_table, string $option ): array {
		$query = $database->prepare(
			'SELECT OCTET_LENGTH(option_value) AS ' . self::BOUNDED_LENGTH_ALIAS
				. ', LEFT(BINARY option_value, %d) AS ' . self::BOUNDED_VALUE_ALIAS
				. ', autoload AS ' . self::BOUNDED_AUTOLOAD_ALIAS
				. ', 1 AS ' . self::BOUNDED_PROBE_ALIAS
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The caller validated the current-blog table; values use placeholders.
				. " FROM {$options_table} WHERE option_name = %s LIMIT 2",
			self::BOUNDED_CONTROL_BYTES,
			$option
		);
		return self::read_bounded_rows(
			$query,
			'database_read_failed_pre_sdk_site_control',
			self::MAX_CONTROL_BYTES,
			true
		);
	}

	/**
	 * Recognize one current completion marker without accepting malformed state.
	 *
	 * @param array{exists: bool, overbound: bool, raw_value: string, autoload: string} $row Exact bounded completion row.
	 * @throws MigrationReadException When an existing completion is unsafe or unsupported.
	 */
	private static function completion_is_current( array $row ): bool {
		if ( ! $row['exists'] ) {
			return false;
		}
		if ( $row['overbound'] ) {
			throw new MigrationReadException( 'pre_sdk_completion_invalid' );
		}
		$decoded = ( new SafeSerializedReader() )->decode(
			$row['raw_value'],
			self::MAX_CONTROL_BYTES,
			self::MAX_VALUE_DEPTH,
			self::MAX_VALUE_NODES
		);
		$version = $decoded['safe'] ? CanonicalInteger::parse( $decoded['value'], 0 ) : null;
		if ( null === $version || $version > self::SCHEMA_VERSION ) {
			throw new MigrationReadException( 'pre_sdk_completion_invalid' );
		}

		return self::SCHEMA_VERSION === $version;
	}

	/**
	 * Require one exact closed marker and reject all conflicting bytes or metadata.
	 *
	 * @param array{exists: bool, overbound: bool, raw_value: string, autoload: string}   $row Exact bounded marker row.
	 * @param array{schema_version: int, site_id: int, network_id: int, basename: string} $record Intended value-free marker.
	 * @param string                                                                      $raw Canonical serialized marker bytes.
	 * @throws MigrationReadException When the row is absent, conflicting, or unsafe.
	 */
	private static function assert_site_evidence_row( array $row, array $record, string $raw ): void {
		if ( ! $row['exists'] || $row['overbound'] || ! hash_equals( $raw, $row['raw_value'] ) ) {
			throw new MigrationReadException( 'pre_sdk_site_evidence_conflict' );
		}
		$decoded = ( new SafeSerializedReader() )->decode(
			$row['raw_value'],
			self::MAX_CONTROL_BYTES,
			self::MAX_VALUE_DEPTH,
			self::MAX_VALUE_NODES
		);
		if ( ! $decoded['safe'] || $record !== $decoded['value'] ) {
			throw new MigrationReadException( 'pre_sdk_site_evidence_conflict' );
		}
	}

	/**
	 * Require the same wpdb, blog, and network after each earliest mutation.
	 *
	 * @param object $database   Exact database object captured before the operation.
	 * @param int    $blog_id    Exact current blog ID.
	 * @param int    $network_id Exact current network ID.
	 * @throws MigrationReadException When the site or database binding drifts.
	 */
	private static function assert_site_binding( object $database, int $blog_id, int $network_id ): void {
		global $wpdb;

		if (
			$wpdb !== $database
			|| get_current_blog_id() !== $blog_id
			|| get_current_network_id() !== $network_id
		) {
			throw new MigrationReadException( 'pre_sdk_site_identity_drift' );
		}
	}

	/**
	 * Return whether WordPress recognizes the exact autoload metadata.
	 *
	 * @param string $autoload Exact stored autoload metadata.
	 */
	private static function autoload_is_recognized( string $autoload ): bool {
		return in_array( strtolower( $autoload ), array( 'yes', 'no', 'on', 'off', 'auto', 'auto-on', 'auto-off' ), true );
	}

	/**
	 * Return whether the marker is explicitly excluded from autoload.
	 *
	 * @param string $autoload Exact stored autoload metadata.
	 */
	private static function autoload_is_disabled( string $autoload ): bool {
		return in_array( strtolower( $autoload ), array( 'no', 'off', 'auto-off' ), true );
	}

	/**
	 * Invalidate only the exact site option and both aggregate options caches.
	 *
	 * @param string $option Exact site option name.
	 */
	private static function invalidate_site_option_cache( string $option ): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $option, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}

	/**
	 * Prove the current site's historical product identity without retaining raw bytes.
	 *
	 * @param int $blog_id Exact current-blog ID.
	 * @throws MigrationReadException When the site row cannot be read exactly.
	 */
	private static function current_site_historical_basename( int $blog_id ): ?string {
		global $wpdb;

		if (
			! is_object( $wpdb )
			|| ! method_exists( $wpdb, 'get_blog_prefix' )
			|| ! method_exists( $wpdb, 'get_results' )
			|| ! method_exists( $wpdb, 'prepare' )
		) {
			throw new MigrationReadException( 'database_read_failed_freemius_site' );
		}
		$database      = $wpdb;
		$prefix        = $database->get_blog_prefix( $blog_id );
		$options_table = is_string( $prefix ) ? $prefix . 'options' : '';
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $options_table ) ) {
			throw new MigrationReadException( 'database_read_failed_freemius_site' );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The validated current-blog table is immutable; values use placeholders.
		$query = $database->prepare(
			'SELECT OCTET_LENGTH(option_value) AS ' . self::BOUNDED_LENGTH_ALIAS . ', LEFT(BINARY option_value, %d) AS ' . self::BOUNDED_VALUE_ALIAS . ', 1 AS ' . self::BOUNDED_PROBE_ALIAS . " FROM {$options_table} WHERE option_name = %s LIMIT 2",
			self::BOUNDED_VALUE_BYTES,
			'fs_accounts'
		);
		$row   = self::read_bounded_rows( $query, 'database_read_failed_freemius_site' );
		if (
			$wpdb !== $database
			|| get_current_blog_id() !== $blog_id
		) {
			throw new MigrationReadException( 'database_read_failed_freemius_site' );
		}
		if ( ! $row['exists'] || $row['overbound'] || '' === $row['raw_value'] ) {
			return null;
		}

		$identity = self::inspect_accounts_raw( $row['raw_value'] );
		if ( null === $identity || ! $identity['product_match'] || ! $identity['historical_version'] ) {
			return null;
		}
		if ( $identity['site_present'] ) {
			return 7061 === $identity['site_plugin_id'] && $blog_id === $identity['site_blog_id']
				? $identity['path']
				: null;
		}

		return $identity['plugin_data_present'] ? $identity['path'] : null;
	}

	/**
	 * Read one external network row with duplicate and unsafe-value detection.
	 *
	 * @param string $option     Exact external option name.
	 * @param int    $network_id Exact current network ID.
	 * @return array{exists: bool, overbound: bool, raw_value: string, autoload: string}
	 * @throws MigrationReadException When the row cannot be read exactly.
	 */
	private static function read_network_option( string $option, int $network_id ): array {
		global $wpdb;

		if (
			! is_object( $wpdb )
			|| ! method_exists( $wpdb, 'get_results' )
			|| ! method_exists( $wpdb, 'prepare' )
			|| ! isset( $wpdb->sitemeta )
			|| ! is_string( $wpdb->sitemeta )
		) {
			throw new MigrationReadException( 'database_read_failed_network_option' );
		}
		$database = $wpdb;
		$query    = $database->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WordPress owns the table identifier; values use placeholders.
			'SELECT OCTET_LENGTH(meta_value) AS ' . self::BOUNDED_LENGTH_ALIAS . ', LEFT(BINARY meta_value, %d) AS ' . self::BOUNDED_VALUE_ALIAS . ', 1 AS ' . self::BOUNDED_PROBE_ALIAS . " FROM {$database->sitemeta} WHERE site_id = %d AND meta_key = %s LIMIT 2",
			self::BOUNDED_VALUE_BYTES,
			$network_id,
			$option
		);
		$row = self::read_bounded_rows( $query, 'database_read_failed_network_option' );
		if (
			$wpdb !== $database
			|| get_current_network_id() !== $network_id
		) {
			throw new MigrationReadException( 'database_read_failed_network_option' );
		}

		return $row;
	}

	/**
	 * Execute one race-free bounded row read without materializing an unrestricted value.
	 *
	 * The statement returns the exact octet length plus at most the configured
	 * maximum plus one binary byte. An oversized prefix is never passed to a
	 * parser.
	 *
	 * @param string $query           Fully prepared bounded query.
	 * @param string $failure         Value-free failure diagnostic.
	 * @param int    $maximum_bytes   Maximum accepted exact row size.
	 * @param bool   $include_autoload Whether the query includes the closed autoload alias.
	 * @return array{exists: bool, overbound: bool, raw_value: string, autoload: string}
	 * @throws MigrationReadException When the row is duplicate, malformed, or uncertain.
	 */
	private static function read_bounded_rows( string $query, string $failure, int $maximum_bytes = self::MAX_RAW_BYTES, bool $include_autoload = false ): array {
		global $wpdb;

		$database             = $wpdb;
		$database->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared caller query returns only one exact bounded external row.
		$rows = $database->get_results( $query, ARRAY_A );
		if (
			'' !== MigrationDatabaseState::last_error( $database )
			|| $wpdb !== $database
			|| ! is_array( $rows )
			|| count( $rows ) > 1
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Closed value-free internal diagnostic, never rendered directly.
			throw new MigrationReadException( $failure );
		}
		if ( array() === $rows ) {
			return array(
				'exists'    => false,
				'overbound' => false,
				'raw_value' => '',
				'autoload'  => '',
			);
		}

		$row  = reset( $rows );
		$keys = is_array( $row ) ? array_keys( $row ) : array();
		sort( $keys, SORT_STRING );
		$expected = array( self::BOUNDED_LENGTH_ALIAS, self::BOUNDED_PROBE_ALIAS, self::BOUNDED_VALUE_ALIAS );
		if ( $include_autoload ) {
			$expected[] = self::BOUNDED_AUTOLOAD_ALIAS;
		}
		sort( $expected, SORT_STRING );
		$length   = is_array( $row ) ? CanonicalInteger::parse( $row[ self::BOUNDED_LENGTH_ALIAS ] ?? null, 0 ) : null;
		$value    = is_array( $row ) ? ( $row[ self::BOUNDED_VALUE_ALIAS ] ?? null ) : null;
		$autoload = is_array( $row ) && $include_autoload ? ( $row[ self::BOUNDED_AUTOLOAD_ALIAS ] ?? null ) : '';
		if (
			$expected !== $keys
			|| 1 !== CanonicalInteger::parse( is_array( $row ) ? ( $row[ self::BOUNDED_PROBE_ALIAS ] ?? null ) : null, 1, 1 )
			|| null === $length
			|| ! is_string( $value )
			|| ! is_string( $autoload )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Closed value-free internal diagnostic, never rendered directly.
			throw new MigrationReadException( $failure );
		}
		if ( $length > $maximum_bytes ) {
			if ( strlen( $value ) !== $maximum_bytes + 1 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Closed value-free internal diagnostic, never rendered directly.
				throw new MigrationReadException( $failure );
			}
			return array(
				'exists'    => true,
				'overbound' => true,
				'raw_value' => '',
				'autoload'  => $autoload,
			);
		}
		if ( strlen( $value ) !== $length ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Closed value-free internal diagnostic, never rendered directly.
			throw new MigrationReadException( $failure );
		}

		return array(
			'exists'    => true,
			'overbound' => false,
			'raw_value' => $value,
			'autoload'  => $autoload,
		);
	}

	/**
	 * Validate one exact value-free immutable cohort record.
	 *
	 * @param string $raw        Exact serialized cohort bytes.
	 * @param int    $network_id Exact current network ID.
	 * @return array{max_site_id: int, basename: string}
	 * @throws MigrationReadException When the cohort is malformed or belongs elsewhere.
	 */
	private static function validate_network_cohort_raw( string $raw, int $network_id ): array {
		$decoded = ( new SafeSerializedReader() )->decode(
			$raw,
			self::MAX_RAW_BYTES,
			self::MAX_VALUE_DEPTH,
			self::MAX_VALUE_NODES
		);
		if ( ! $decoded['safe'] || ! is_array( $decoded['value'] ) ) {
			throw new MigrationReadException( 'invalid_network_legacy_cohort' );
		}
		$cohort = $decoded['value'];
		$schema = CanonicalInteger::parse( $cohort['schema_version'] ?? null, 1 );
		if ( null !== $schema && $schema > self::SCHEMA_VERSION ) {
			throw new MigrationReadException( 'future_network_legacy_cohort' );
		}
		$keys     = array_keys( $cohort );
		$expected = array( 'basename', 'max_site_id', 'network_id', 'schema_version' );
		sort( $keys, SORT_STRING );
		$maximum = CanonicalInteger::parse( $cohort['max_site_id'] ?? null, 1 );
		if (
			$expected !== $keys
			|| self::SCHEMA_VERSION !== $schema
			|| CanonicalInteger::parse( $cohort['network_id'] ?? null, 1 ) !== $network_id
			|| ! in_array( $cohort['basename'] ?? '', self::LEGACY_NETWORK_BASENAMES, true )
			|| null === $maximum
		) {
			throw new MigrationReadException( 'invalid_network_legacy_cohort' );
		}

		return array(
			'max_site_id' => $maximum,
			'basename'    => $cohort['basename'],
		);
	}

	/**
	 * Extract a closed list of paths without retaining the source graph.
	 *
	 * @param array<string|int, mixed> $value Decoded bounded source graph.
	 * @param string[]                 $paths Exact allowed paths.
	 * @return array<string, mixed>
	 */
	private static function extract_array_paths( array $value, array $paths ): array {
		$result = array();
		foreach ( $paths as $path ) {
			$node = $value;
			foreach ( explode( '.', $path ) as $segment ) {
				if ( ! is_array( $node ) || ! array_key_exists( $segment, $node ) ) {
					$node = null;
					break;
				}
				$node = $node[ $segment ];
			}
			if ( null !== $node ) {
				$result[ $path ] = is_array( $node ) ? true : $node;
			}
		}

		return $result;
	}

	/**
	 * Accept only an unambiguous dotted V1 plugin version.
	 *
	 * @param mixed $version Candidate plugin version.
	 */
	private static function is_historical_plugin_version( mixed $version ): bool {
		return is_string( $version )
			&& 1 === preg_match( '/^(?:0|1)(?:\.(?:0|[1-9][0-9]*)){1,4}(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?$/D', $version )
			&& version_compare( $version, '2.0.0', '<' );
	}
}
