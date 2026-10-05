<?php
/**
 * Version-driven database/upgrade routine.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Setup;

use CartPops\Admin\SettingsRepository;
use CartPops\Database\DatabaseCommitOutcomeUnknownException;
use CartPops\Database\OwnedTransaction;
use CartPops\Migration\CanonicalDecimal;
use CartPops\Migration\CanonicalInteger;
use CartPops\Migration\LegacyCompatibilityTerminalFence;
use CartPops\Migration\LegacySettingsMigrator;
use CartPops\Migration\MigrationOutcome;
use CartPops\Migration\MigrationOptionStore;
use CartPops\Migration\MigrationDatabaseState;
use CartPops\Migration\NetworkMigrationState;
use CartPops\Migration\NetworkOptionStore;
use CartPops\Migration\PreSdkLegacyInstallEvidence;
use CartPops\Migration\SafeSerializedReader;

/**
 * Version-driven database/upgrade routine.
 *
 * The activation hook in cartpops.php only runs on the first activation of a
 * single site. It does NOT run on plugin updates, nor on multisite subsites
 * that were never individually activated. This upgrader closes that gap: on
 * every request it first converges the V1 settings migration, then compares
 * the stored cartpops_db_version option with CARTPOPS_VERSION. Physically
 * optional edition work is composed through SiteUpgradeExtension; shared and
 * stripped Free builds never name or load a concrete paid implementation.
 */
final class Upgrader {

	/**
	 * Option that stores the DB schema version the site was last migrated to.
	 */
	private const VERSION_OPTION = 'cartpops_db_version';

	/** Site-local, authenticated review of preserved V1 JavaScript. */
	private const CUSTOM_JS_REVIEW_ACTION = 'cartpops_review_legacy_javascript';

	private const HIDE_WARNINGS_ACTION = 'cartpops_hide_migration_warnings';

	/** Per-user key of the migration result whose warnings notice was hidden. */
	private const HIDDEN_WARNINGS_META = 'cartpops_hidden_migration_warnings';

	/**
	 * Code-defined name of the step that failed the last upgrade, never stored data.
	 *
	 * @var string
	 */
	private string $failure_step = '';

	/** Public documentation the upgrade notices link to. */
	public const DOCS_URL = 'https://docs.cartpops.com';

	/** Documentation for a store that is paused or stopped after upgrading. */
	public const DOCS_STOPPED_AFTER_UPGRADING = '/troubleshooting/stopped-after-upgrading';

	/** Bytes of preserved custom JavaScript shown in the review notice. */
	private const MAX_CUSTOM_JS_DISPLAY_BYTES = 20000;

	/** Bound exact version parsing on the request hot path. */
	private const MAX_DATABASE_VERSION_BYTES = 255;

	/** Bound adversarial prerelease graphs without restricting ordinary SemVer. */
	private const MAX_DATABASE_PRERELEASE_IDENTIFIERS = 32;

	/** One-time boundary for SettingsRepository's historical shape conversions. */
	private const SETTINGS_NORMALIZATION_OPTION = 'cartpops_settings_normalization_version';

	/** Current settings-normalization contract, including retired trigger and drawer-order cleanup. */
	private const SETTINGS_NORMALIZATION_SCHEMA_VERSION = 3;

	/** Exact bounded bytes retained before historical settings normalization. */
	private const SETTINGS_NORMALIZATION_RECOVERY_OPTION = 'cartpops_settings_pre_normalization_v1';

	/** Maximum exact settings row accepted by the normalization boundary. */
	private const MAX_SETTINGS_NORMALIZATION_BYTES = 4194304;

	/** Maximum aggregate recovery envelope retained across CAS retries. */
	private const MAX_SETTINGS_NORMALIZATION_RECOVERY_BYTES = 4194304;

	/** Maximum distinct exact settings candidates retained for recovery. */
	private const MAX_SETTINGS_NORMALIZATION_RECOVERY_RECORDS = 20;

	/**
	 * Maximum nodes accepted for one recovery-envelope decode.
	 *
	 * The exact 20-record shape consumes 161 nodes: nine for the root
	 * envelope and eight for each of 19 additional checksum-keyed records.
	 * The remaining 32 nodes are conservative parser headroom; exact shape,
	 * byte, depth, and record-count validation still fail closed.
	 */
	private const MAX_SETTINGS_NORMALIZATION_RECOVERY_NODES = 193;

	/**
	 * Network-wide progress for bounded migration of unvisited sites.
	 */
	private const NETWORK_STATE_OPTION = 'cartpops_v1_network_migration_state';

	/**
	 * Maximum number of sites processed by one request.
	 */
	private const MAX_NETWORK_BATCH = 100;

	/** Maximum lifetime of one network worker's owner fence. */
	private const NETWORK_LEASE_TTL = 120;

	/** Bounded lock wait for one switched-site mutation transaction. */
	private const SITE_MUTATION_LOCK_WAIT_TIMEOUT = 5;

	/**
	 * Current-site legacy settings migrator.
	 *
	 * @var LegacySettingsMigrator
	 */
	private LegacySettingsMigrator $legacy_settings_migrator;

	/**
	 * Exact uncached option persistence for schema markers.
	 *
	 * @var MigrationOptionStore
	 */
	private MigrationOptionStore $store;

	/**
	 * Exact paired option reader for the bounded request hot path.
	 *
	 * @var UpgradeOptionReader
	 */
	private UpgradeOptionReader $option_reader;

	/**
	 * Physically optional edition-specific upgrade graph.
	 *
	 * @var SiteUpgradeExtension|null
	 */
	private ?SiteUpgradeExtension $extension;

	/**
	 * Exact network-option persistence with raw CAS support.
	 *
	 * @var NetworkOptionStore
	 */
	private NetworkOptionStore $network_store;

	/**
	 * Value-free owner token for this network worker.
	 *
	 * @var string
	 */
	private string $network_owner;

	/**
	 * Testable wall clock.
	 *
	 * @var \Closure(): int
	 */
	private \Closure $clock;

	/**
	 * Prevent duplicate retry hooks when Plugin retries a BUSY outcome once.
	 *
	 * @var bool
	 */
	private bool $retry_hook_registered = false;

	/**
	 * Prevent duplicate notice hooks.
	 *
	 * @var bool
	 */
	private bool $notices_registered = false;

	/**
	 * Prevent duplicate network hooks.
	 *
	 * @var bool
	 */
	private bool $network_hooks_registered = false;

	/**
	 * Constructor.
	 *
	 * @param LegacySettingsMigrator|null      $legacy_settings_migrator Optional migration boundary.
	 * @param \Closure(): int|null             $clock                     Optional wall-clock boundary.
	 * @param string|null                      $network_owner             Optional network-worker owner token.
	 * @param SiteUpgradeExtension|null        $extension                 Optional edition-specific upgrade graph.
	 * @param PreSdkLegacyInstallEvidence|null $pre_sdk_legacy_evidence Value-free identity captured before SDK initialization.
	 */
	public function __construct( ?LegacySettingsMigrator $legacy_settings_migrator = null, ?\Closure $clock = null, ?string $network_owner = null, ?SiteUpgradeExtension $extension = null, ?PreSdkLegacyInstallEvidence $pre_sdk_legacy_evidence = null ) {
		$this->legacy_settings_migrator = $legacy_settings_migrator ?? new LegacySettingsMigrator( null, null, null, null, null, $pre_sdk_legacy_evidence );
		$this->extension                = $extension;
		$this->network_store            = new NetworkOptionStore();
		$this->clock                    = $clock ?? static fn(): int => time();
		$this->network_owner            = $network_owner ?? wp_generate_uuid4();
	}

	/**
	 * Register hooks.
	 *
	 * The plugin invokes maybe_upgrade synchronously before resolving settings
	 * consumers. The init hook is a defensive integration fallback. Network
	 * administrators additionally coordinate a bounded batch of unvisited sites.
	 *
	 * @param bool $schedule_retry     Whether to register an init retry.
	 * @param bool $coordinate_network Whether to coordinate network sites.
	 */
	public function register( bool $schedule_retry = true, bool $coordinate_network = true ): void {
		if ( $schedule_retry && ! $this->retry_hook_registered ) {
			add_action( 'init', array( $this, 'run_upgrade_hook' ), 20 );
			$this->retry_hook_registered = true;
		}

		if ( ! is_admin() ) {
			return;
		}

		if ( ! $this->notices_registered ) {
			add_action( 'admin_notices', array( $this, 'render_migration_notice' ) );
			add_action( 'admin_post_' . self::CUSTOM_JS_REVIEW_ACTION, array( $this, 'review_legacy_javascript' ) );
			add_action( 'admin_post_' . self::HIDE_WARNINGS_ACTION, array( $this, 'hide_migration_warnings' ) );
			if ( is_multisite() && is_network_admin() ) {
				add_action( 'network_admin_notices', array( $this, 'render_migration_notice' ) );
			}
			$this->notices_registered = true;
		}
		if ( $coordinate_network && is_multisite() && is_network_admin() && ! $this->network_hooks_registered ) {
			add_action( 'admin_init', array( $this, 'run_network_migration_batch' ), 5 );
			$this->network_hooks_registered = true;
		}
	}

	/** Name of the step that failed the last upgrade on this request, or ''. */
	public function failure_step(): string {
		return $this->failure_step;
	}

	/**
	 * Fail the upgrade and remember which step did, for diagnostics.
	 *
	 * @param string $step Code-defined step name.
	 */
	private function failed( string $step ): UpgradeOutcome {
		// An anonymous exception's class name embeds a file path; keep only codes.
		$this->failure_step = 1 === preg_match( '/\A[a-z0-9_]{1,96}\z/D', $step ) ? $step : 'unexpected_error';
		return UpgradeOutcome::FAILED;
	}

	/**
	 * Run the upgrade routine when the stored version differs from the current one.
	 */
	public function maybe_upgrade(): UpgradeOutcome {
		return $this->upgrade_current_site();
	}

	/** WordPress init callback; typed callers use upgrade_current_site(). */
	public function run_upgrade_hook(): void {
		$this->upgrade_current_site();
	}

	/**
	 * Converge and verify the complete current-site upgrade operation.
	 *
	 * @param bool $verify_runtime Whether schema, cron, and cleanup must be reverified.
	 */
	public function upgrade_current_site( bool $verify_runtime = false ): UpgradeOutcome {
		$this->failure_step = '';
		try {
			$context = SiteUpgradeContext::capture();
			return $context->run(
				fn(): UpgradeOutcome => $this->converge_current_site( $context, $verify_runtime, true )
			);
		} catch ( \Throwable $error ) {
			return $this->failed( 'unexpected_' . strtolower( ( new \ReflectionClass( $error ) )->getShortName() ) );
		}
	}

	/**
	 * Converge shared state, optionally followed by the current-request extension.
	 *
	 * Network keyset migration deliberately sets $include_extension to false:
	 * Freemius 2.13.4 binds its site entity to the request that initialized the
	 * SDK, while ordinary switch_to_blog() changes only WordPress context. Paid
	 * state therefore remains observably pending until a request initialized for
	 * that exact blog calls upgrade_current_site().
	 *
	 * @param SiteUpgradeContext        $context           Exact whole-convergence binding.
	 * @param bool                      $verify_runtime    Whether shared runtime state must be reverified.
	 * @param bool                      $include_extension Whether the current request may converge paid state.
	 * @param MigrationOptionStore|null $store           Caller-owned transaction cache boundary.
	 */
	private function converge_current_site( SiteUpgradeContext $context, bool $verify_runtime, bool $include_extension, ?MigrationOptionStore $store = null ): UpgradeOutcome {
		$this->store         = $store ?? new MigrationOptionStore( $context );
		$this->option_reader = new UpgradeOptionReader( $context );
		$upgrade_controls    = $this->option_reader->read_version();
		if ( ! $upgrade_controls['success'] ) {
			return $this->failed( 'version_record_unreadable' );
		}
		$version       = $upgrade_controls['row'];
		$version_state = $this->classify_database_version( $version );
		if ( 'future' === $version_state ) {
			return UpgradeOutcome::FUTURE_VERSION;
		}
		if ( 'corrupt' === $version_state ) {
			return $this->failed( 'version_record_corrupt' );
		}

		$extension_plan = null;
		if ( $include_extension && null !== $this->extension ) {
			try {
				$preparation = $this->extension->prepare( $context );
			} catch ( \Throwable ) {
				return $this->failed( 'pro_upgrade_preparation_error' );
			}
			$preflight = $preparation->terminal_outcome();
			if ( UpgradeOutcome::FAILED === $preflight ) {
				return $this->failed( 'pro_upgrade_preflight_failed' );
			}
			if ( UpgradeOutcome::FUTURE_VERSION === $preflight ) {
				return $preflight;
			}
			$extension_plan = $preparation->plan();
			if ( null === $extension_plan && UpgradeOutcome::NOT_APPLICABLE !== $preflight ) {
				return $this->failed( 'pro_upgrade_plan_missing' );
			}
		}

		// This must run before the schema-version return: V1 settings migration
		// has its own journal/version and is required even on a current V2 schema.
		$migration = $this->legacy_settings_migrator->migrate( $context, $this->store );
		if ( MigrationOutcome::BUSY === $migration ) {
			return UpgradeOutcome::BUSY;
		}
		if ( MigrationOutcome::FUTURE_VERSION === $migration ) {
			return UpgradeOutcome::FUTURE_VERSION;
		}
		if ( MigrationOutcome::MAINTENANCE_REQUIRED === $migration ) {
			return UpgradeOutcome::MAINTENANCE_REQUIRED;
		}
		if ( ! in_array( $migration, array( MigrationOutcome::COMPLETE, MigrationOutcome::NOT_APPLICABLE ), true ) ) {
			return $this->failed( 'settings_migration_failed' );
		}
		$terminal = MigrationOutcome::NOT_APPLICABLE === $migration
			? UpgradeOutcome::NOT_APPLICABLE
			: UpgradeOutcome::COMPLETE;

		$normalization = $this->normalize_legacy_settings_shapes();
		if ( UpgradeOutcome::COMPLETE !== $normalization ) {
			return $normalization;
		}

		$version = $this->store->read( self::VERSION_OPTION );
		if ( ! $version['success'] ) {
			return $this->failed( 'version_record_unreadable_after_migration' );
		}
		$version_state = $this->classify_database_version( $version );
		if ( 'future' === $version_state ) {
			return UpgradeOutcome::FUTURE_VERSION;
		}
		if ( 'corrupt' === $version_state ) {
			return $this->failed( 'version_record_corrupt_after_migration' );
		}

		if ( null !== $extension_plan && null !== $this->extension ) {
			$verification = 'current' === $version_state && ! $verify_runtime
				? RuntimeVerification::CONVERGE_PENDING
				: RuntimeVerification::VERIFY;
			try {
				$extension_outcome = $this->extension->execute( $extension_plan, $verification, $context );
			} catch ( \Throwable ) {
				return $this->failed( 'pro_upgrade_error' );
			}
			if ( UpgradeOutcome::FAILED === $extension_outcome ) {
				return $this->failed( 'pro_upgrade_incomplete' );
			}
			if ( ! in_array( $extension_outcome, array( UpgradeOutcome::COMPLETE, UpgradeOutcome::NOT_APPLICABLE ), true ) ) {
				return $extension_outcome;
			}
		}

		if ( 'current' === $version_state ) {
			if (
				! $this->store->autoload_is( $version['autoload'], false )
				&& ! $this->store->compare_and_swap( self::VERSION_OPTION, $version['raw_value'], CARTPOPS_VERSION, false )
			) {
				return $this->failed( 'version_autoload_repair' );
			}
			if ( ! $verify_runtime ) {
				return $terminal;
			}
		}

		// Record the migrated version last so a fatal mid-upgrade re-runs it.
		// Stored with autoload disabled — it is only read on admin requests.
		if ( ! $this->store->persist( self::VERSION_OPTION, CARTPOPS_VERSION, false ) ) {
			return $this->failed( 'version_write' );
		}

		$verified = $this->store->read( self::VERSION_OPTION );
		return $verified['success']
			&& $verified['exists']
			&& CARTPOPS_VERSION === $verified['raw_value']
			&& $this->store->autoload_is( $verified['autoload'], false )
			? $terminal
			: $this->failed( 'version_verify' );
	}

	/**
	 * Classify one exact database-version row without accepting loose numbers.
	 *
	 * @param array{success: bool, exists: bool, raw_value: string, autoload: string} $row Exact version row.
	 * @return string One of missing, older, current, future, or corrupt.
	 */
	private function classify_database_version( array $row ): string {
		if ( ! $row['exists'] ) {
			return 'missing';
		}
		$version    = $row['raw_value'];
		$comparison = self::compare_canonical_versions( $version, CARTPOPS_VERSION );
		if ( null === $comparison ) {
			return 'corrupt';
		}

		if ( 0 === $comparison ) {
			return CARTPOPS_VERSION === $version ? 'current' : 'corrupt';
		}

		return $comparison > 0 ? 'future' : 'older';
	}

	/**
	 * Compare two canonical SemVer values without PHP's special prerelease map.
	 *
	 * @param string $candidate Stored candidate version.
	 * @param string $current   Current plugin version.
	 * @return int|null Negative, zero, or positive precedence; null for invalid input.
	 */
	private static function compare_canonical_versions( string $candidate, string $current ): ?int {
		$left  = self::parse_canonical_version( $candidate );
		$right = self::parse_canonical_version( $current );
		if ( null === $left || null === $right ) {
			return null;
		}

		for ( $index = 0; $index < 3; ++$index ) {
			$comparison = self::compare_numeric_identifier( $left['core'][ $index ], $right['core'][ $index ] );
			if ( 0 !== $comparison ) {
				return $comparison;
			}
		}

		$left_prerelease  = $left['prerelease'];
		$right_prerelease = $right['prerelease'];
		if ( null === $left_prerelease || null === $right_prerelease ) {
			if ( null === $left_prerelease && null === $right_prerelease ) {
				return 0;
			}
			return null === $left_prerelease ? 1 : -1;
		}

		$shared = min( count( $left_prerelease ), count( $right_prerelease ) );
		for ( $index = 0; $index < $shared; ++$index ) {
			$left_identifier  = $left_prerelease[ $index ];
			$right_identifier = $right_prerelease[ $index ];
			$left_numeric     = 1 === preg_match( '/^[0-9]+$/D', $left_identifier );
			$right_numeric    = 1 === preg_match( '/^[0-9]+$/D', $right_identifier );
			if ( $left_numeric && $right_numeric ) {
				$comparison = self::compare_numeric_identifier( $left_identifier, $right_identifier );
			} elseif ( $left_numeric ) {
				$comparison = -1;
			} elseif ( $right_numeric ) {
				$comparison = 1;
			} else {
				$lexical    = strcmp( $left_identifier, $right_identifier );
				$comparison = $lexical < 0 ? -1 : ( $lexical > 0 ? 1 : 0 );
			}
			if ( 0 !== $comparison ) {
				return $comparison;
			}
		}

		return count( $left_prerelease ) <=> count( $right_prerelease );
	}

	/**
	 * Parse one bounded canonical SemVer without build metadata.
	 *
	 * @param string $version Raw version string.
	 * @return array{core: array{string, string, string}, prerelease: array<int, string>|null}|null
	 */
	private static function parse_canonical_version( string $version ): ?array {
		$length = strlen( $version );
		if ( 0 === $length || self::MAX_DATABASE_VERSION_BYTES < $length ) {
			return null;
		}

		$separator = strpos( $version, '-' );
		$core_raw  = false === $separator ? $version : substr( $version, 0, $separator );
		$pre_raw   = false === $separator ? null : substr( $version, $separator + 1 );
		$core      = explode( '.', $core_raw );
		if ( 3 !== count( $core ) ) {
			return null;
		}
		foreach ( $core as $identifier ) {
			if ( 1 !== preg_match( '/^(?:0|[1-9][0-9]*)$/D', $identifier ) ) {
				return null;
			}
		}

		$prerelease = null;
		if ( null !== $pre_raw ) {
			if ( '' === $pre_raw ) {
				return null;
			}
			$prerelease = explode( '.', $pre_raw );
			if ( self::MAX_DATABASE_PRERELEASE_IDENTIFIERS < count( $prerelease ) ) {
				return null;
			}
			foreach ( $prerelease as $identifier ) {
				if ( 1 !== preg_match( '/^[0-9A-Za-z-]+$/D', $identifier ) ) {
					return null;
				}
				if ( 1 === preg_match( '/^[0-9]+$/D', $identifier ) && 1 < strlen( $identifier ) && '0' === $identifier[0] ) {
					return null;
				}
			}
		}

		/**
		 * Canonical three-component core.
		 *
		 * @var array{string, string, string} $core
		 */
		return array(
			'core'       => $core,
			'prerelease' => $prerelease,
		);
	}

	/**
	 * Compare canonical unsigned decimals without converting them to integers.
	 *
	 * @param string $left  Left numeric identifier.
	 * @param string $right Right numeric identifier.
	 */
	private static function compare_numeric_identifier( string $left, string $right ): int {
		$length_comparison = strlen( $left ) <=> strlen( $right );
		if ( 0 !== $length_comparison ) {
			return $length_comparison;
		}
		$lexical = strcmp( $left, $right );
		return $lexical < 0 ? -1 : ( $lexical > 0 ? 1 : 0 );
	}

	/**
	 * Move historical read-time rewrites and retired settings values behind one
	 * durable version gate before customer-facing services may resolve them.
	 */
	private function normalize_legacy_settings_shapes(): UpgradeOutcome {
		$normalization_controls = $this->option_reader->read_normalization_and_settings();
		if ( ! $normalization_controls['success'] ) {
			return $this->failed( 'normalization_read' );
		}
		$marker       = $normalization_controls['rows'][ self::SETTINGS_NORMALIZATION_OPTION ];
		$prior_schema = 0;
		if ( $marker['exists'] ) {
			$classified = $this->classify_settings_normalization_marker( $marker['raw_value'] );
			if ( 'future' === $classified['kind'] ) {
				return UpgradeOutcome::FUTURE_VERSION;
			}
			if ( ! in_array( $classified['kind'], array( 'current', 'older' ), true ) ) {
				return $this->failed( 'normalization_marker_invalid' );
			}
			$prior_schema = (int) $classified['record']['schema_version'];
			$settings     = $normalization_controls['rows']['cartpops_settings'];
			if ( 'current' === $classified['kind'] && $this->settings_match_normalization_marker( $settings, $classified['record'] ) ) {
				if (
					! $this->store->autoload_is( $marker['autoload'], false )
					&& ! $this->store->compare_and_swap(
						self::SETTINGS_NORMALIZATION_OPTION,
						$marker['raw_value'],
						$classified['record'],
						false,
						4096
					)
				) {
					return $this->failed( 'normalization_marker_autoload_repair' );
				}
				return UpgradeOutcome::COMPLETE;
			}
		}

		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			if ( 0 < $attempt ) {
				$normalization_controls = $this->option_reader->read_normalization_and_settings();
				if ( ! $normalization_controls['success'] ) {
					return $this->failed( 'normalization_reread' );
				}
				$marker = $normalization_controls['rows'][ self::SETTINGS_NORMALIZATION_OPTION ];
				if ( $marker['exists'] ) {
					$classified = $this->classify_settings_normalization_marker( $marker['raw_value'] );
					if ( 'future' === $classified['kind'] ) {
						return UpgradeOutcome::FUTURE_VERSION;
					}
					if ( ! in_array( $classified['kind'], array( 'current', 'older' ), true ) ) {
						return $this->failed( 'normalization_marker_invalid_on_retry' );
					}
					$prior_schema = (int) $classified['record']['schema_version'];
				} else {
					$prior_schema = 0;
				}
			}
			$settings = $normalization_controls['rows']['cartpops_settings'];
			if ( ! $settings['success'] ) {
				return $this->failed( 'settings_unreadable' );
			}
			$normalized = $this->normalize_settings_row( $settings, $prior_schema );
			if ( null === $normalized ) {
				return $this->failed( 'settings_normalize' );
			}
			$expected_raw   = $settings['raw_value'];
			$normalized_raw = $this->store->serialize_value( $normalized );
			if ( strlen( $normalized_raw ) > self::MAX_SETTINGS_NORMALIZATION_BYTES ) {
				return $this->failed( 'settings_too_large' );
			}
			if ( $settings['exists'] && ! hash_equals( $settings['raw_value'], $normalized_raw ) ) {
				if ( ! $this->preserve_settings_normalization_recovery( $settings['raw_value'] ) ) {
					return $this->failed( 'settings_recovery_copy' );
				}
				if ( ! $this->store->compare_and_swap( 'cartpops_settings', $settings['raw_value'], $normalized, true, self::MAX_SETTINGS_NORMALIZATION_BYTES ) ) {
					continue;
				}
				$expected_raw = $normalized_raw;
			}

			$current_controls = $this->option_reader->read_normalization_and_settings();
			if ( ! $current_controls['success'] ) {
				return $this->failed( 'normalization_verify_read' );
			}
			$current        = $current_controls['rows']['cartpops_settings'];
			$current_marker = $current_controls['rows'][ self::SETTINGS_NORMALIZATION_OPTION ];
			if (
				$marker['exists'] !== $current_marker['exists']
				|| ( $marker['exists'] && ! hash_equals( $marker['raw_value'], $current_marker['raw_value'] ) )
			) {
				if ( $current_marker['exists'] ) {
					$concurrent = $this->classify_settings_normalization_marker( $current_marker['raw_value'] );
					if ( 'future' === $concurrent['kind'] ) {
						return UpgradeOutcome::FUTURE_VERSION;
					}
					if ( ! in_array( $concurrent['kind'], array( 'current', 'older' ), true ) ) {
						return $this->failed( 'normalization_marker_concurrent_invalid' );
					}
				}
				continue;
			}
			if ( $settings['exists'] !== $current['exists'] || ! hash_equals( $expected_raw, $current['raw_value'] ) ) {
				continue;
			}
			$record = $this->settings_normalization_record( $current );
			if ( ! $this->persist_settings_normalization_marker( $marker, $record ) ) {
				continue;
			}

			$verified = $this->option_reader->read_normalization_and_settings();
			if ( ! $verified['success'] ) {
				return $this->failed( 'normalization_final_read' );
			}
			$verified_marker   = $verified['rows'][ self::SETTINGS_NORMALIZATION_OPTION ];
			$verified_settings = $verified['rows']['cartpops_settings'];
			if ( ! $verified_marker['success'] || ! $verified_settings['success'] || ! $verified_marker['exists'] ) {
				return $this->failed( 'normalization_final_missing' );
			}
			$classified = $this->classify_settings_normalization_marker( $verified_marker['raw_value'] );
			if ( 'future' === $classified['kind'] ) {
				return UpgradeOutcome::FUTURE_VERSION;
			}
			if ( ! in_array( $classified['kind'], array( 'current', 'older' ), true ) ) {
				return $this->failed( 'normalization_final_marker_invalid' );
			}
			if (
				'current' === $classified['kind']
				&& $this->settings_match_normalization_marker( $verified_settings, $classified['record'] )
			) {
				return UpgradeOutcome::COMPLETE;
			}
		}

		return UpgradeOutcome::BUSY;
	}
	/**
	 * Safely normalize only known historical SettingsRepository shapes/values.
	 *
	 * @param array{success: bool, exists: bool, raw_value: string, autoload: string} $row          Exact settings row.
	 * @param int                                                                     $prior_schema Exact successfully completed normalization schema.
	 * @return array<string, mixed>|null
	 */
	private function normalize_settings_row( array $row, int $prior_schema ): ?array {
		if ( ! $row['exists'] ) {
			return array();
		}
		$decoded = ( new SafeSerializedReader() )->decode( $row['raw_value'], self::MAX_SETTINGS_NORMALIZATION_BYTES, 64, 10000 );
		if ( ! $decoded['safe'] || ! is_array( $decoded['value'] ) ) {
			return null;
		}
		$settings = $decoded['value'];
		if ( is_array( $settings['general'] ?? null ) ) {
			if ( 2 > $prior_schema && array_key_exists( 'mini_cart_mode', $settings['general'] ) ) {
				$settings['general']['mini_cart_mode'] = SettingsRepository::normalize_mini_cart_mode( $settings['general']['mini_cart_mode'] );
			}
			if ( array_key_exists( 'trigger', $settings['general'] ) ) {
				$settings['general']['trigger'] = SettingsRepository::normalize_trigger( $settings['general']['trigger'] );
			}
		}
		if ( is_array( $settings['drawer'] ?? null ) ) {
			unset( $settings['drawer']['sections_order'] );
		}
		if ( is_array( $settings['notifications'] ?? null ) ) {
			unset(
				$settings['notifications']['enabled'],
				$settings['notifications']['type'],
				$settings['notifications']['position'],
				$settings['notifications']['duration']
			);
		}
		if ( 1 <= $prior_schema ) {
			return $settings;
		}
		if ( array_key_exists( 'magic_upsells', $settings ) ) {
			if ( ! array_key_exists( 'bundle_builder', $settings ) ) {
				$settings['bundle_builder'] = $settings['magic_upsells'];
			}
			unset( $settings['magic_upsells'] );
		}
		if ( ! is_array( $settings['bundle_builder'] ?? null ) ) {
			return $settings;
		}
		$upsells = $settings['bundle_builder']['upsells'] ?? null;
		if ( ! is_array( $upsells ) ) {
			return $settings;
		}
		foreach ( $upsells as &$upsell ) {
			if ( ! is_array( $upsell ) ) {
				continue;
			}
			$companions = $upsell['companions'] ?? null;
			if ( array_key_exists( 'discount', $upsell ) || ! is_array( $companions ) || array() === $companions ) {
				continue;
			}
			$discount = 0.0;
			foreach ( $companions as &$companion ) {
				if ( ! is_array( $companion ) ) {
					continue;
				}
				$value    = CanonicalDecimal::parse( $companion['discount'] ?? 0, 0.0, 100.0 );
				$discount = max( $discount, $value ?? 0.0 );
				unset( $companion['discount'] );
			}
			unset( $companion );
			$upsell['discount'] = (int) $discount;
		}
		unset( $upsell );
		$settings['bundle_builder']['upsells'] = $upsells;

		$sanitized                  = ( new SettingsRepository() )->sanitize( array( 'bundle_builder' => $settings['bundle_builder'] ) );
		$settings['bundle_builder'] = $sanitized['bundle_builder'] ?? $settings['bundle_builder'];

		return $settings;
	}

	/**
	 * Persist every distinct exact candidate observed before normalization.
	 *
	 * @param string $raw Exact pre-normalization settings bytes.
	 */
	private function preserve_settings_normalization_recovery( string $raw ): bool {
		$record = array(
			'schema_version' => 1,
			'raw_value'      => $raw,
			'checksum'       => hash( 'sha256', $raw ),
		);
		if (
			strlen( $raw ) > self::MAX_SETTINGS_NORMALIZATION_BYTES
			|| strlen( $this->store->serialize_value( $record ) ) > self::MAX_SETTINGS_NORMALIZATION_RECOVERY_BYTES
		) {
			return false;
		}

		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			$current = $this->store->read_bounded(
				self::SETTINGS_NORMALIZATION_RECOVERY_OPTION,
				self::MAX_SETTINGS_NORMALIZATION_RECOVERY_BYTES
			);
			if ( ! $current['success'] ) {
				return false;
			}
			if ( ! $current['exists'] ) {
				$stored = $this->store->add_immutable(
					self::SETTINGS_NORMALIZATION_RECOVERY_OPTION,
					$record,
					false,
					self::MAX_SETTINGS_NORMALIZATION_RECOVERY_BYTES
				);
				if ( ! $stored['success'] ) {
					return false;
				}
				$current = $stored['row'];
			}

			$decoded = ( new SafeSerializedReader() )->decode(
				$current['raw_value'],
				self::MAX_SETTINGS_NORMALIZATION_RECOVERY_BYTES,
				8,
				self::MAX_SETTINGS_NORMALIZATION_RECOVERY_NODES
			);
			$stored  = $decoded['safe'] && is_array( $decoded['value'] ) ? $decoded['value'] : null;
			if ( ! $this->settings_normalization_recovery_is_valid( $stored, true ) ) {
				return false;
			}
			if ( $this->settings_normalization_recovery_contains( $stored, $record['checksum'] ) ) {
				return $this->store->autoload_is( $current['autoload'], false )
					|| $this->store->compare_and_swap(
						self::SETTINGS_NORMALIZATION_RECOVERY_OPTION,
						$current['raw_value'],
						$stored,
						false,
						self::MAX_SETTINGS_NORMALIZATION_RECOVERY_BYTES
					);
			}

			$additional                        = is_array( $stored['additional_records'] ?? null )
				? $stored['additional_records']
				: array();
			$additional[ $record['checksum'] ] = $record;
			$stored['additional_records']      = $additional;
			if (
				! $this->settings_normalization_recovery_is_valid( $stored, true )
				|| strlen( $this->store->serialize_value( $stored ) ) > self::MAX_SETTINGS_NORMALIZATION_RECOVERY_BYTES
			) {
				return false;
			}
			if (
				$this->store->compare_and_swap(
					self::SETTINGS_NORMALIZATION_RECOVERY_OPTION,
					$current['raw_value'],
					$stored,
					false,
					self::MAX_SETTINGS_NORMALIZATION_RECOVERY_BYTES
				)
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Validate the bounded recovery envelope and each exact candidate.
	 *
	 * @param mixed $stored Candidate recovery envelope or nested record.
	 * @param bool  $allow_additional Whether the envelope may contain retries.
	 */
	private function settings_normalization_recovery_is_valid( mixed $stored, bool $allow_additional ): bool {
		if ( ! is_array( $stored ) ) {
			return false;
		}
		$expected = array( 'schema_version', 'raw_value', 'checksum' );
		if ( $allow_additional && array_key_exists( 'additional_records', $stored ) ) {
			$expected[] = 'additional_records';
		}
		$keys = array_keys( $stored );
		sort( $keys, SORT_STRING );
		sort( $expected, SORT_STRING );
		if (
			$keys !== $expected
			|| 1 !== CanonicalInteger::parse( $stored['schema_version'] ?? null, 1 )
			|| ! is_string( $stored['raw_value'] ?? null )
			|| strlen( $stored['raw_value'] ) > self::MAX_SETTINGS_NORMALIZATION_RECOVERY_BYTES
			|| ! is_string( $stored['checksum'] ?? null )
			|| ! hash_equals( hash( 'sha256', $stored['raw_value'] ), $stored['checksum'] )
		) {
			return false;
		}
		if ( ! array_key_exists( 'additional_records', $stored ) ) {
			return true;
		}
		$additional = $stored['additional_records'];
		if (
			! is_array( $additional )
			|| array_is_list( $additional )
			|| count( $additional ) + 1 > self::MAX_SETTINGS_NORMALIZATION_RECOVERY_RECORDS
		) {
			return false;
		}
		foreach ( $additional as $checksum => $nested ) {
			if (
				! is_string( $checksum )
				|| ! is_array( $nested )
				|| ( $nested['checksum'] ?? null ) !== $checksum
				|| ! $this->settings_normalization_recovery_is_valid( $nested, false )
			) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether the recovery envelope already retains one exact checksum.
	 *
	 * @param array<string, mixed> $stored   Valid recovery envelope.
	 * @param string               $checksum Exact candidate checksum.
	 */
	private function settings_normalization_recovery_contains( array $stored, string $checksum ): bool {
		return hash_equals( (string) ( $stored['checksum'] ?? '' ), $checksum )
			|| isset( $stored['additional_records'][ $checksum ] );
	}

	/**
	 * Bind the normalization marker to exact settings bytes.
	 *
	 * @param array{success: bool, exists: bool, raw_value: string, autoload: string} $settings Exact settings row.
	 * @return array{schema_version: int, settings_exist: bool, settings_sha: string}
	 */
	private function settings_normalization_record( array $settings ): array {
		return array(
			'schema_version' => self::SETTINGS_NORMALIZATION_SCHEMA_VERSION,
			'settings_exist' => $settings['exists'],
			'settings_sha'   => hash( 'sha256', $settings['raw_value'] ),
		);
	}

	/**
	 * Classify a strict normalization marker.
	 *
	 * @param string $raw Exact marker bytes.
	 * @return array{kind: string, record: array<string, mixed>}
	 */
	private function classify_settings_normalization_marker( string $raw ): array {
		$decoded = ( new SafeSerializedReader() )->decode( $raw, 4096, 8, 32 );
		if ( ! $decoded['safe'] || ! is_array( $decoded['value'] ) ) {
			return array(
				'kind'   => 'corrupt',
				'record' => array(),
			);
		}
		$record = $decoded['value'];
		$schema = CanonicalInteger::parse( $record['schema_version'] ?? null, 1 );
		if ( null !== $schema && $schema > self::SETTINGS_NORMALIZATION_SCHEMA_VERSION ) {
			return array(
				'kind'   => 'future',
				'record' => array(),
			);
		}
		$keys = array_keys( $record );
		sort( $keys, SORT_STRING );
		if (
			array( 'schema_version', 'settings_exist', 'settings_sha' ) !== $keys
			|| ! in_array( $schema, array( 1, 2, self::SETTINGS_NORMALIZATION_SCHEMA_VERSION ), true )
			|| ! is_bool( $record['settings_exist'] )
			|| ! is_string( $record['settings_sha'] )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $record['settings_sha'] )
		) {
			return array(
				'kind'   => 'corrupt',
				'record' => array(),
			);
		}

		return array(
			'kind'   => self::SETTINGS_NORMALIZATION_SCHEMA_VERSION === $schema ? 'current' : 'older',
			'record' => $record,
		);
	}

	/**
	 * Check that a marker still describes the exact settings row.
	 *
	 * @param array{success: bool, exists: bool, raw_value: string, autoload: string} $settings Exact settings row.
	 * @param array<string, mixed>                                                    $record   Marker record.
	 */
	private function settings_match_normalization_marker( array $settings, array $record ): bool {
		return ( $record['settings_exist'] ?? null ) === $settings['exists']
			&& is_string( $record['settings_sha'] ?? null )
			&& hash_equals( hash( 'sha256', $settings['raw_value'] ), $record['settings_sha'] );
	}

	/**
	 * First-writer/CAS marker transition bound to exact settings bytes.
	 *
	 * @param array{success: bool, exists: bool, raw_value: string, autoload: string} $marker Exact prior marker.
	 * @param array<string, mixed>                                                    $record Intended marker.
	 */
	private function persist_settings_normalization_marker( array $marker, array $record ): bool {
		if ( ! $marker['exists'] ) {
			$stored = $this->store->add_immutable( self::SETTINGS_NORMALIZATION_OPTION, $record, false, 4096 );
			return $stored['success'] && $stored['row']['exists'];
		}

		return $this->store->compare_and_swap(
			self::SETTINGS_NORMALIZATION_OPTION,
			$marker['raw_value'],
			$record,
			false,
			4096
		);
	}

	/**
	 * Return value-free current-site migration diagnostics.
	 *
	 * @return array{state: string, phase: string, schema_version: int, source_count: int, mapped_count: int, retained_count: int, warnings: string[], unmapped_keys: string[], snapshot_checksum: string}
	 */
	public function get_migration_status(): array {
		return $this->legacy_settings_migrator->get_status();
	}

	/**
	 * Return value-free network migration diagnostics.
	 *
	 * @return array{schema_version: int, status: string, last_site_id: int, failed_site_id: int, warning: string}
	 */
	public function get_network_migration_status(): array {
		$record = $this->read_network_state_record();
		$state  = 'current' === $record['kind'] ? $record['state'] : array();
		if ( 'future' === $record['kind'] ) {
			return array(
				'schema_version' => NetworkMigrationState::SCHEMA_VERSION + 1,
				'status'         => 'future_version',
				'last_site_id'   => 0,
				'failed_site_id' => 0,
				'warning'        => '',
			);
		}
		if ( in_array( $record['kind'], array( 'corrupt', 'read_failed' ), true ) ) {
			return array(
				'schema_version' => NetworkMigrationState::SCHEMA_VERSION,
				'status'         => 'failed',
				'last_site_id'   => 0,
				'failed_site_id' => 0,
				'warning'        => 'invalid_network_state',
			);
		}

		return array(
			'schema_version' => isset( $state['schema_version'] ) && is_int( $state['schema_version'] ) ? $state['schema_version'] : NetworkMigrationState::SCHEMA_VERSION,
			'status'         => isset( $state['status'] ) && is_string( $state['status'] ) ? $state['status'] : 'not_started',
			'last_site_id'   => isset( $state['last_site_id'] ) && is_int( $state['last_site_id'] ) ? $state['last_site_id'] : 0,
			'failed_site_id' => isset( $state['failed_site_id'] ) && is_int( $state['failed_site_id'] ) ? $state['failed_site_id'] : 0,
			'warning'        => isset( $state['warning'] ) && is_string( $state['warning'] ) ? $state['warning'] : '',
		);
	}

	/**
	 * WordPress action callback for the bounded network migration.
	 */
	public function run_network_migration_batch(): void {
		$this->migrate_network_batch();
	}

	/**
	 * Migrate a bounded set of sites, including sites never directly visited.
	 *
	 * Progress uses the last successfully processed blog ID as a keyset cursor.
	 * Deleting lower-ID sites cannot shift later sites past the cursor. A frozen
	 * high-water ID excludes sites created after the release cohort began. A
	 * failed site never advances the cursor, so it is retried. Only this worker's
	 * one exact switch is restored; unexpected nested drift remains observable.
	 *
	 * @param int $limit Maximum sites to process during this request.
	 * @return int Number of sites successfully processed.
	 */
	public function migrate_network_batch( int $limit = 20 ): int {
		if ( ! is_multisite() || ! current_user_can( 'manage_network_plugins' ) ) {
			return 0;
		}

		$limit = max( 1, min( self::MAX_NETWORK_BATCH, $limit ) );
		$lease = $this->acquire_network_lease();
		if ( null === $lease ) {
			return 0;
		}
		$state        = $lease['state'];
		$state_raw    = $lease['raw_value'];
		$last_site_id = $state['last_site_id'];
		$max_site_id  = $state['max_site_id'];
		try {
			$sites = $this->get_site_ids_after( $last_site_id, $max_site_id, $limit );
		} catch ( \RuntimeException ) {
			$this->persist_network_failure( $state, $state_raw, 0, 'network_site_query_failed' );
			return 0;
		}

		$processed = 0;
		foreach ( $sites as $site_id ) {
			if ( ! $this->renew_network_lease( $state, $state_raw ) ) {
				return $processed;
			}
			$blog_context = $this->capture_blog_context();
			$site_failed  = false;
			try {
				if ( ! switch_to_blog( $site_id ) ) {
					$site_failed = true;
				} elseif ( ! $this->switched_blog_context_is_exact( $blog_context, $site_id ) ) {
					$site_failed = true;
				} else {
					$site_context = SiteUpgradeContext::capture();
					$site_outcome = $this->converge_network_site_transactionally( $site_context );
					$site_failed  = ! in_array( $site_outcome, array( UpgradeOutcome::COMPLETE, UpgradeOutcome::NOT_APPLICABLE ), true );
				}
			} catch ( \Throwable ) {
				$site_failed = true;
			} finally {
				if ( ! $this->restore_blog_context( $blog_context, $site_id ) ) {
					$site_failed = true;
				}
			}
			if ( ! $site_failed && ! $this->site_remains_in_network( $blog_context, $site_id ) ) {
				$site_failed = true;
			}
			if ( $site_failed ) {
				$this->persist_network_failure( $state, $state_raw, $site_id, 'site_upgrade_failed' );
				return $processed;
			}
			// @phpstan-ignore-next-line -- The injected clock may advance while the site upgrade runs.
			if ( ! $this->renew_network_lease( $state, $state_raw ) ) {
				return $processed;
			}

			$next_state                 = $state;
			$next_state['last_site_id'] = $site_id;
			if ( ! $this->transition_network_state( $state, $state_raw, $next_state, $blog_context, $site_id ) ) {
				$this->persist_network_failure( $state, $state_raw, $site_id, 'site_upgrade_failed' );
				return $processed;
			}

			++$processed;
			$last_site_id = $site_id;
			$state        = $next_state;
			$state_raw    = $this->network_store->serialize_value( $next_state );
		}

		if ( ! $this->renew_network_lease( $state, $state_raw ) ) {
			return $processed;
		}
		try {
			$remaining = $this->get_site_ids_after( $last_site_id, $max_site_id, 1 );
		} catch ( \RuntimeException ) {
			$this->persist_network_failure( $state, $state_raw, 0, 'network_site_query_failed' );
			return $processed;
		}
		// @phpstan-ignore-next-line -- The database keyset read may outlive the current lease.
		if ( ! $this->renew_network_lease( $state, $state_raw ) ) {
			return $processed;
		}
		$final                     = $state;
		$final['status']           = array() === $remaining ? 'complete' : 'in_progress';
		$final['owner']            = '';
		$final['lease_expires_at'] = 0;
		$empty_range_context       = 'complete' === $final['status'] ? $this->capture_blog_context() : null;
		if ( ! $this->transition_network_state( $state, $state_raw, $final, null, null, $empty_range_context ) ) {
			$this->release_network_lease( $state, $state_raw );
		}

		return $processed;
	}

	/**
	 * Atomically converge one switched site's complete customer-data mutation.
	 *
	 * The exact membership row and every mutated table remain locked until the
	 * child transaction commits. Network progress remains outside this bounded
	 * transaction and advances only after the site has been restored.
	 *
	 * @param SiteUpgradeContext $context Exact switched-site binding.
	 */
	private function converge_network_site_transactionally( SiteUpgradeContext $context ): UpgradeOutcome {
		$store = new MigrationOptionStore( $context );
		$store->defer_cache_invalidation();
		try {
			$transaction = OwnedTransaction::begin(
				$context->database(),
				'REPEATABLE READ',
				self::SITE_MUTATION_LOCK_WAIT_TIMEOUT
			);
		} catch ( \Throwable ) {
			$store->discard_cache_invalidation();
			return UpgradeOutcome::FAILED;
		}

		try {
			MigrationDatabaseState::bind( $transaction );
		} catch ( \Throwable ) {
			$this->rollback_network_site_transaction( $transaction, $store );
			return UpgradeOutcome::FAILED;
		}

		try {
			$outcome = $context->run(
				function () use ( $context, $store, $transaction ): UpgradeOutcome {
					$fence = new LegacyCompatibilityTerminalFence( $context );
					if ( ! $fence->prepare_site_mutation_transaction() ) {
						return UpgradeOutcome::FAILED;
					}
					return $transaction->guard(
						fn(): UpgradeOutcome => $this->converge_current_site( $context, true, false, $store )
					);
				}
			);
			$transaction->assert_active();
			if ( ! in_array( $outcome, array( UpgradeOutcome::COMPLETE, UpgradeOutcome::NOT_APPLICABLE ), true ) ) {
				$this->rollback_network_site_transaction( $transaction, $store );
				return $outcome;
			}
			$context->assert_current();
			$transaction->commit();
			$store->commit_cache_invalidation();
			return $outcome;
		} catch ( DatabaseCommitOutcomeUnknownException ) {
			$store->commit_cache_invalidation();
			return UpgradeOutcome::FAILED;
		} catch ( \Throwable ) {
			$this->rollback_network_site_transaction( $transaction, $store );
			return UpgradeOutcome::FAILED;
		} finally {
			MigrationDatabaseState::release( $transaction );
			if ( $transaction->is_active() ) {
				$this->rollback_network_site_transaction( $transaction, $store );
			}
		}
	}

	/**
	 * Restore one failed site transaction and classify its cache visibility.
	 *
	 * @param OwnedTransaction     $transaction Exact owned site transaction.
	 * @param MigrationOptionStore $store       Deferred option-cache boundary.
	 */
	private function rollback_network_site_transaction( OwnedTransaction $transaction, MigrationOptionStore $store ): void {
		if ( ! $transaction->is_active() ) {
			$store->commit_cache_invalidation();
			return;
		}
		try {
			$transaction->rollback();
			$store->discard_cache_invalidation();
		} catch ( \Throwable ) {
			$store->commit_cache_invalidation();
		}
	}

	/**
	 * Acquire one bounded owner/generation lease over exact network-state bytes.
	 *
	 * A missing state is established with first-writer semantics from the
	 * independently proven V1 network cohort. A lost race reloads the winner;
	 * it never substitutes this request's independently calculated high-water.
	 *
	 * @return array{state: array<string, mixed>, raw_value: string}|null
	 */
	private function acquire_network_lease(): ?array {
		$record = $this->read_network_state_record();
		if ( 'missing' === $record['kind'] ) {
			try {
				$high_water = $this->legacy_settings_migrator->get_network_legacy_high_water();
			} catch ( \Throwable ) {
				return null;
			}
			if ( null === $high_water ) {
				return null;
			}
			$initial = NetworkMigrationState::initial(
				get_current_network_id(),
				$high_water,
				$this->network_owner,
				( $this->clock )() + self::NETWORK_LEASE_TTL
			);
			$added   = $this->network_store->add_immutable( self::NETWORK_STATE_OPTION, $initial );
			if ( ! $added['success'] ) {
				return null;
			}
			$record = $this->classify_network_row( $added['row'] );
			if ( $added['won'] && 'current' === $record['kind'] ) {
				return array(
					'state'     => $record['state'],
					'raw_value' => $record['raw_value'],
				);
			}
		}

		if ( 'current' !== $record['kind'] ) {
			return null;
		}
		$state = $record['state'];
		if ( 'complete' === $state['status'] ) {
			return null;
		}
		$now = ( $this->clock )();
		if ( '' !== $state['owner'] && $state['lease_expires_at'] > $now ) {
			return null;
		}
		if ( PHP_INT_MAX === $state['generation'] ) {
			return null;
		}

		$owned                     = $state;
		$owned['status']           = 'in_progress';
		$owned['failed_site_id']   = 0;
		$owned['warning']          = '';
		$owned['owner']            = $this->network_owner;
		$owned['generation']       = $state['generation'] + 1;
		$owned['lease_expires_at'] = $now + self::NETWORK_LEASE_TTL;
		if ( $this->network_store->compare_and_swap( self::NETWORK_STATE_OPTION, $record['raw_value'], $owned ) ) {
			return array(
				'state'     => $owned,
				'raw_value' => $this->network_store->serialize_value( $owned ),
			);
		}

		// A database client can report failure after the write committed. Adopt
		// only the exact owner/generation bytes this request attempted; another
		// worker's winner remains authoritative and cannot be overwritten here.
		$winner       = $this->read_network_state_record();
		$expected_raw = $this->network_store->serialize_value( $owned );
		if ( 'current' === $winner['kind'] && hash_equals( $expected_raw, $winner['raw_value'] ) ) {
			return array(
				'state'     => $winner['state'],
				'raw_value' => $winner['raw_value'],
			);
		}

		return null;
	}

	/**
	 * Read and classify the exact network progress row.
	 *
	 * @return array{kind: string, state: array<string, mixed>, raw_value: string}
	 */
	private function read_network_state_record(): array {
		$row = $this->network_store->read( self::NETWORK_STATE_OPTION );
		if ( ! $row['success'] ) {
			return array(
				'kind'      => 'read_failed',
				'state'     => array(),
				'raw_value' => '',
			);
		}
		if ( ! $row['exists'] ) {
			return array(
				'kind'      => 'missing',
				'state'     => array(),
				'raw_value' => '',
			);
		}

		$record = $this->classify_network_row( $row );
		if (
			'current' === $record['kind']
			&& ! $this->network_store->is_non_autoloaded( $row['autoload'] )
			&& ! $this->network_store->repair_autoload( self::NETWORK_STATE_OPTION, $row['raw_value'] )
		) {
			return array(
				'kind'      => 'read_failed',
				'state'     => array(),
				'raw_value' => '',
			);
		}

		return $record;
	}

	/**
	 * Classify a successful exact row.
	 *
	 * @param array{success: bool, exists: bool, raw_value: string, autoload: string} $row Exact row.
	 * @return array{kind: string, state: array<string, mixed>, raw_value: string}
	 */
	private function classify_network_row( array $row ): array {
		if ( ! $row['success'] || ! $row['exists'] ) {
			return array(
				'kind'      => 'read_failed',
				'state'     => array(),
				'raw_value' => '',
			);
		}
		$classified = NetworkMigrationState::classify( $row['raw_value'], get_current_network_id() );

		return array(
			'kind'      => $classified['kind'],
			'state'     => $classified['state'],
			'raw_value' => $row['raw_value'],
		);
	}

	/**
	 * CAS one non-regressing transition while this worker's fence is current.
	 *
	 * @param array<string, mixed>      $current             Current owned state.
	 * @param string                    $current_raw         Exact current database bytes.
	 * @param array<string, mixed>      $next                Intended successor.
	 * @param array<string, mixed>|null $membership_context  Captured network context for an advancing site cursor.
	 * @param int|null                  $membership_site_id  Exact site whose membership authorizes advancement.
	 * @param array<string, mixed>|null $empty_range_context Captured network context for a terminal empty-range CAS.
	 */
	private function transition_network_state(
		array $current,
		string $current_raw,
		array $next,
		?array $membership_context = null,
		?int $membership_site_id = null,
		?array $empty_range_context = null
	): bool {
		if (
			( $current['owner'] ?? null ) !== $this->network_owner
			|| ! is_int( $current['lease_expires_at'] ?? null )
			|| $current['lease_expires_at'] <= ( $this->clock )()
			|| ! hash_equals( $this->network_store->serialize_value( $current ), $current_raw )
			|| ( $next['network_id'] ?? null ) !== ( $current['network_id'] ?? null )
			|| ( $next['max_site_id'] ?? null ) !== ( $current['max_site_id'] ?? null )
			|| ( $next['generation'] ?? null ) !== ( $current['generation'] ?? null )
			|| ! is_int( $next['last_site_id'] ?? null )
			|| $next['last_site_id'] < $current['last_site_id']
			|| $next['last_site_id'] > $current['max_site_id']
			|| ! in_array( $next['owner'] ?? null, array( $this->network_owner, '' ), true )
		) {
			return false;
		}

		$classified = NetworkMigrationState::classify(
			$this->network_store->serialize_value( $next ),
			get_current_network_id()
		);
		if ( 'current' !== $classified['kind'] ) {
			return false;
		}
		if ( null !== $empty_range_context ) {
			$swapped = $this->compare_network_completion_with_empty_range( $current_raw, $next, $empty_range_context );
		} elseif ( null !== $membership_context || null !== $membership_site_id ) {
			$swapped = $this->compare_network_cursor_with_membership( $current_raw, $next, $membership_context, $membership_site_id );
		} else {
			$swapped = $this->network_store->compare_and_swap( self::NETWORK_STATE_OPTION, $current_raw, $next );
		}
		if ( $swapped ) {
			return true;
		}
		if (
			null !== $membership_context
			&& null !== $membership_site_id
			&& ! $this->site_remains_in_network( $membership_context, $membership_site_id )
		) {
			return false;
		}

		$winner       = $this->read_network_state_record();
		$expected_raw = $this->network_store->serialize_value( $next );
		return 'current' === $winner['kind'] && hash_equals( $expected_raw, $winner['raw_value'] );
	}

	/**
	 * Atomically bind one advancing cursor CAS to exact persisted membership.
	 *
	 * @param string                    $current_raw Exact current network-state bytes.
	 * @param array<string, mixed>      $next        Intended successor state.
	 * @param array<string, mixed>|null $context     Captured network context.
	 * @param int|null                  $site_id     Exact processed site.
	 */
	private function compare_network_cursor_with_membership(
		string $current_raw,
		array $next,
		?array $context,
		?int $site_id
	): bool {
		$blogs_table = is_array( $context ) ? ( $context['blogs_table'] ?? null ) : null;
		$network_id  = is_array( $context ) ? ( $context['network_id'] ?? null ) : null;
		if ( ! is_string( $blogs_table ) || ! is_int( $network_id ) || ! is_int( $site_id ) || $site_id <= 0 ) {
			return false;
		}

		return $this->network_store->compare_and_swap_if_site_in_network(
			self::NETWORK_STATE_OPTION,
			$current_raw,
			$next,
			$blogs_table,
			$site_id,
			$network_id
		);
	}

	/**
	 * Atomically bind terminal completion to the exact remaining keyset.
	 *
	 * @param string               $current_raw Exact current network-state bytes.
	 * @param array<string, mixed> $next        Intended terminal state.
	 * @param array<string, mixed> $context     Captured network context.
	 */
	private function compare_network_completion_with_empty_range( string $current_raw, array $next, array $context ): bool {
		$blogs_table = $context['blogs_table'] ?? null;
		$network_id  = $context['network_id'] ?? null;
		$last_site   = $next['last_site_id'] ?? null;
		$max_site    = $next['max_site_id'] ?? null;
		if (
			! is_string( $blogs_table )
			|| ! is_int( $network_id )
			|| ( $next['network_id'] ?? null ) !== $network_id
			|| ! is_int( $last_site )
			|| ! is_int( $max_site )
		) {
			return false;
		}

		return $this->network_store->compare_and_swap_if_network_range_empty(
			self::NETWORK_STATE_OPTION,
			$current_raw,
			$next,
			$blogs_table,
			$last_site,
			$max_site
		);
	}

	/**
	 * Renew and CAS-fence this exact worker generation before a site switch or
	 * durable transition. An expired worker may never resurrect its lease.
	 *
	 * @param array<string, mixed> $state     Current owned state, updated on success.
	 * @param string               $state_raw Exact current bytes, updated on success.
	 */
	private function renew_network_lease( array &$state, string &$state_raw ): bool {
		$now    = ( $this->clock )();
		$expiry = $state['lease_expires_at'] ?? null;
		if (
			( $state['owner'] ?? null ) !== $this->network_owner
			|| ! is_int( $expiry )
			|| $expiry <= $now
			|| $now > PHP_INT_MAX - self::NETWORK_LEASE_TTL
			|| PHP_INT_MAX === $expiry
		) {
			return false;
		}

		$renewed                     = $state;
		$renewed['lease_expires_at'] = max( $expiry + 1, $now + self::NETWORK_LEASE_TTL );
		if ( ! $this->transition_network_state( $state, $state_raw, $renewed ) ) {
			return false;
		}

		$state     = $renewed;
		$state_raw = $this->network_store->serialize_value( $renewed );
		return true;
	}

	/**
	 * Persist a value-free retryable network failure without advancing.
	 *
	 * @param array<string, mixed> $state          Current owned network state.
	 * @param string               $state_raw      Exact current state bytes.
	 * @param int                  $failed_site_id Site that could not converge.
	 * @param string               $warning        Value-free diagnostic code.
	 */
	private function persist_network_failure( array $state, string $state_raw, int $failed_site_id, string $warning ): void {
		if ( ! $this->renew_network_lease( $state, $state_raw ) ) {
			$this->release_network_lease( $state, $state_raw );
			return;
		}
		$failed                     = $state;
		$failed['status']           = 'failed';
		$failed['failed_site_id']   = $failed_site_id;
		$failed['warning']          = $warning;
		$failed['owner']            = '';
		$failed['lease_expires_at'] = 0;
		if ( ! $this->transition_network_state( $state, $state_raw, $failed ) ) {
			$this->release_network_lease( $state, $state_raw );
		}
	}

	/**
	 * Best-effort exact release after a transition failure.
	 *
	 * A stale worker's raw bytes cannot release a successor generation. The
	 * cursor and cohort are retained so a retry remains idempotent.
	 *
	 * @param array<string, mixed> $state     Current owned state.
	 * @param string               $state_raw Exact current bytes.
	 */
	private function release_network_lease( array $state, string $state_raw ): void {
		$idle                     = $state;
		$idle['status']           = 'in_progress';
		$idle['failed_site_id']   = 0;
		$idle['warning']          = '';
		$idle['owner']            = '';
		$idle['lease_expires_at'] = 0;
		$this->transition_network_state( $state, $state_raw, $idle );
	}

	/**
	 * Fetch a bounded, stable keyset of blog IDs after the supplied cursor.
	 *
	 * WP_Site_Query only supports offset pagination for arbitrary site lists.
	 * A raw keyset query is used so deletion of earlier sites cannot skip a site.
	 *
	 * @param int $last_site_id Last successfully processed blog ID.
	 * @param int $max_site_id Frozen cohort high-water ID.
	 * @param int $limit       Maximum IDs to return.
	 * @return int[]
	 * @throws \RuntimeException When the bounded site query is uncertain.
	 */
	private function get_site_ids_after( int $last_site_id, int $max_site_id, int $limit ): array {
		global $wpdb;

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A bounded keyset read avoids unsafe offset drift across multisite changes.
		$site_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT blog_id FROM {$wpdb->blogs} WHERE site_id = %d AND blog_id > %d AND blog_id <= %d ORDER BY blog_id ASC LIMIT %d",
				get_current_network_id(),
				$last_site_id,
				$max_site_id,
				$limit
			)
		);
		if ( '' !== MigrationDatabaseState::last_error( $wpdb ) || ! is_array( $site_ids ) ) {
			throw new \RuntimeException( 'network_site_query_failed' );
		}

		return array_values( array_map( 'intval', $site_ids ) );
	}

	/**
	 * Capture all WordPress blog-switch state that migration may disturb.
	 *
	 * @return array<string, mixed>
	 */
	private function capture_blog_context(): array {
		global $wpdb, $blog_id, $_wp_switched_stack, $switched;
		global $wp_current_blog_id, $wp_blog_stack, $wp_options, $wp_blog_options;

		return array(
			'blog_id'              => get_current_blog_id(),
			'network_id'           => get_current_network_id(),
			'global_blog_id'       => $blog_id ?? null,
			'wp_stack'             => isset( $_wp_switched_stack ) && is_array( $_wp_switched_stack ) ? $_wp_switched_stack : null,
			'switched'             => $switched ?? null,
			'prefix'               => isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '',
			'options_table'        => isset( $wpdb->options ) ? (string) $wpdb->options : '',
			'posts_table'          => isset( $wpdb->posts ) ? (string) $wpdb->posts : '',
			'database'             => is_object( $wpdb ) ? $wpdb : null,
			'blogs_table'          => isset( $wpdb->blogs ) && is_string( $wpdb->blogs ) ? $wpdb->blogs : '',
			'unit_current_present' => isset( $wp_current_blog_id ),
			'unit_current_blog_id' => $wp_current_blog_id ?? null,
			'unit_stack_present'   => isset( $wp_blog_stack ) && is_array( $wp_blog_stack ),
			'unit_blog_stack'      => isset( $wp_blog_stack ) && is_array( $wp_blog_stack ) ? $wp_blog_stack : null,
			'unit_options'         => isset( $wp_options ) && is_array( $wp_options ) ? $wp_options : null,
		);
	}

	/**
	 * Revalidate target membership after restoring the batch's origin context.
	 *
	 * @param array<string, mixed> $context Captured immutable batch context.
	 * @param int                  $site_id Target site just processed.
	 */
	private function site_remains_in_network( array $context, int $site_id ): bool {
		$database    = $context['database'] ?? null;
		$blogs_table = $context['blogs_table'] ?? null;
		$network_id  = $context['network_id'] ?? null;
		if ( ! is_object( $database ) || ! is_string( $blogs_table ) || ! is_int( $network_id ) || $network_id <= 0 ) {
			return false;
		}
		try {
			return SiteUpgradeContext::read_persisted_network_id( $database, $blogs_table, $site_id ) === $network_id;
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Restore only the one exact switch this worker deliberately performed.
	 *
	 * A nested or otherwise unsolicited switch is never unwound or repaired.
	 *
	 * @param array<string, mixed> $context Captured context.
	 * @param int                  $site_id Deliberate target site.
	 */
	private function restore_blog_context( array $context, int $site_id ): bool {
		if ( ! $this->switched_blog_context_is_exact( $context, $site_id ) ) {
			return false;
		}
		try {
			if ( ! restore_current_blog() ) {
				return false;
			}
		} catch ( \Throwable ) {
			return false;
		}

		return $this->original_blog_context_is_exact( $context );
	}

	/**
	 * Whether globals still represent exactly the worker's one deliberate switch.
	 *
	 * @param array<string, mixed> $context Captured origin context.
	 * @param int                  $site_id Deliberate target site.
	 */
	private function switched_blog_context_is_exact( array $context, int $site_id ): bool {
		global $wpdb, $_wp_switched_stack, $wp_blog_stack;

		$core_stack   = is_array( $context['wp_stack'] ?? null ) ? $context['wp_stack'] : array();
		$unit_stack   = is_array( $context['unit_blog_stack'] ?? null ) ? $context['unit_blog_stack'] : array();
		$core_stack[] = (int) ( $context['blog_id'] ?? 0 );
		$unit_stack[] = (int) ( $context['blog_id'] ?? 0 );
		$prefix       = is_object( $wpdb ) && method_exists( $wpdb, 'get_blog_prefix' ) ? $wpdb->get_blog_prefix( $site_id ) : null;

		return get_current_blog_id() === $site_id
			&& ( $context['database'] ?? null ) === $wpdb
			&& is_string( $prefix )
			&& ( $_wp_switched_stack ?? null ) === $core_stack
			&& ( empty( $context['unit_stack_present'] ) || ( $wp_blog_stack ?? null ) === $unit_stack )
			&& (string) ( $wpdb->prefix ?? '' ) === $prefix
			&& (string) ( $wpdb->options ?? '' ) === $prefix . 'options'
			&& (string) ( $wpdb->posts ?? '' ) === $prefix . 'posts';
	}

	/**
	 * Whether the one normal restore returned to the exact captured origin.
	 *
	 * @param array<string, mixed> $context Captured origin context.
	 */
	private function original_blog_context_is_exact( array $context ): bool {
		global $wpdb, $blog_id, $_wp_switched_stack, $switched, $wp_current_blog_id, $wp_blog_stack;

		return get_current_blog_id() === (int) ( $context['blog_id'] ?? 0 )
			&& ( $context['database'] ?? null ) === $wpdb
			&& ( $blog_id ?? null ) === ( $context['global_blog_id'] ?? null )
			&& ( $_wp_switched_stack ?? null ) === ( $context['wp_stack'] ?? null )
			&& ( $switched ?? null ) === ( $context['switched'] ?? null )
			&& ( empty( $context['unit_current_present'] ) || ( $wp_current_blog_id ?? null ) === ( $context['unit_current_blog_id'] ?? null ) )
			&& ( empty( $context['unit_stack_present'] ) || ( $wp_blog_stack ?? null ) === ( $context['unit_blog_stack'] ?? null ) )
			&& (string) ( $wpdb->prefix ?? '' ) === (string) ( $context['prefix'] ?? '' )
			&& (string) ( $wpdb->options ?? '' ) === (string) ( $context['options_table'] ?? '' )
			&& (string) ( $wpdb->posts ?? '' ) === (string) ( $context['posts_table'] ?? '' );
	}

	/**
	 * Render a value-free notice for migration failures or compatibility warnings.
	 */
	public function render_migration_notice(): void {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_network_plugins' ) ) {
			return;
		}

		if ( is_multisite() && is_network_admin() && current_user_can( 'manage_network_plugins' ) ) {
			$network_status = $this->get_network_migration_status();
			if ( 'failed' === $network_status['status'] ) {
				$message = sprintf(
					/* translators: %1$d: WordPress multisite blog ID. */
					__( 'CartPops network migration paused at site %1$d and will retry it without skipping later sites. Original settings were preserved.', 'cartpops' ),
					$network_status['failed_site_id']
				);
				echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
				$this->render_site_review_link( $network_status['failed_site_id'] );
				return;
			}
		}

		$status = $this->get_migration_status();
		if ( 'failed' === $status['state'] ) {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'CartPops could not finish updating your settings from version 1, so it is paused. Your settings are safe. Reload this page to try again, and contact CartPops support if this message stays.', 'cartpops' )
				. self::docs_link_html( '/upgrading-to-v2#notices-you-may-see' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper escapes every part.
				. '</p></div>';
			$this->render_custom_js_review();
			return;
		}

		$rules_retired = in_array( LegacySettingsMigrator::RULES_RETIREMENT_WARNING, $status['warnings'], true );
		if ( $rules_retired ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'Your automation rules from CartPops 1 are kept, but CartPops 2 no longer runs them.', 'cartpops' )
				. self::docs_link_html( '/upgrading-to-v2#automation-and-upsell-rules-are-retired-pro' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper escapes every part.
				. '</p></div>';
		}

		if ( 'maintenance_required' === $status['state'] ) {
			echo '<div class="notice notice-error"><p>'
				. esc_html( $this->paused_for_review_message( $status['warnings'] ) )
				. self::docs_link_html( '/upgrading-to-v2#notices-you-may-see' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper escapes every part.
				. '</p></div>';
			$this->render_custom_js_review();
			return;
		}

		if ( 'complete' === $status['state'] && array() !== $status['warnings'] ) {
			if ( $rules_retired && 1 === count( $status['warnings'] ) ) {
				return;
			}
			$key = self::migration_warnings_key( $status );
			if ( get_user_meta( get_current_user_id(), self::HIDDEN_WARNINGS_META, true ) === $key ) {
				return;
			}
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'CartPops updated your settings from version 1, but some could not be carried over exactly. Your original settings are kept.', 'cartpops' )
				. self::docs_link_html( '/upgrading-to-v2#settings-that-did-not-carry-over' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper escapes every part.
				. '</p>'
				. $this->migration_warning_details_html( MigrationWarningSummary::summarize( $status['warnings'], $status['unmapped_keys'] ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper escapes every part.
				. '<p><a href="' . esc_url( self::hide_migration_warnings_url( $key ) ) . '">' . esc_html__( 'Hide this notice', 'cartpops' ) . '</a></p>'
				. '</div>';
		}
	}

	/** Handle only the current site's explicitly reviewed, unchanged snapshot. */
	public function review_legacy_javascript(): void {
		// Exact method allowlist: do not normalize malformed input into an accepted token.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Strict comparison accepts only the literal POST method.
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'manage_options' ) || is_network_admin() ) {
			$this->finish_custom_js_review( __( 'This review requires a site administrator and a submitted review form.', 'cartpops' ), 403 );
			return;
		}

		$state = $this->legacy_settings_migrator->get_custom_js_review_state();
		// Read only the named POST fields; never select or switch a site from input.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Scalar nonce verification, exact token comparisons, and lowercase SHA256 validation below reject rather than normalize inputs.
		$fingerprint = isset( $_POST['cartpops_review_fingerprint'] ) && is_string( $_POST['cartpops_review_fingerprint'] ) ? wp_unslash( $_POST['cartpops_review_fingerprint'] ) : '';
		$nonce       = isset( $_POST['_wpnonce'] ) && is_string( $_POST['_wpnonce'] ) ? wp_unslash( $_POST['_wpnonce'] ) : '';
		if ( null === $state || $state['acknowledged']
			|| get_current_blog_id() !== $state['blog_id'] || get_current_network_id() !== $state['network_id']
			|| ( $_POST['cartpops_review_blog_id'] ?? null ) !== (string) $state['blog_id']
			|| ( $_POST['cartpops_review_network_id'] ?? null ) !== (string) $state['network_id']
			|| 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $fingerprint ) || ! hash_equals( $state['fingerprint'], $fingerprint )
			|| '1' !== ( $_POST['cartpops_review_confirm'] ?? null )
			|| ! wp_verify_nonce( $nonce, $this->custom_js_review_nonce_action( $state ) ) ) {
			// phpcs:enable WordPress.Security.ValidatedSanitizedInput
			$this->finish_custom_js_review( __( 'The review was not accepted. Return to this site dashboard and review the current notice again.', 'cartpops' ), 400 );
			return;
		}

		if ( ! $this->legacy_settings_migrator->acknowledge_custom_js_retirement( $fingerprint ) ) {
			$this->finish_custom_js_review( __( 'The saved migration state could not be acknowledged. No review was accepted; return to this site dashboard and try again.', 'cartpops' ), 409 );
			return;
		}

		// Retry through ordinary fresh-request boot, never reuse paused service/admission state.
		if ( wp_safe_redirect( get_admin_url( get_current_blog_id(), 'index.php' ), 303 ) ) {
			exit;
		}
		$this->finish_custom_js_review( __( 'JavaScript retirement review accepted. Return to this site dashboard to retry migration and review any remaining conditions. The original script is preserved and will not execute in V2.', 'cartpops' ), 200 );
	}

	/**
	 * Identify one migration result, so a new result shows its notice again.
	 *
	 * @param array{warnings: string[], unmapped_keys: string[], snapshot_checksum: string} $status Value-free status.
	 */
	private static function migration_warnings_key( array $status ): string {
		$warnings = $status['warnings'];
		$unmapped = $status['unmapped_keys'];
		sort( $warnings, SORT_STRING );
		sort( $unmapped, SORT_STRING );
		return substr( hash( 'sha256', (string) wp_json_encode( array( $status['snapshot_checksum'], $warnings, $unmapped ) ) ), 0, 32 );
	}

	/**
	 * A nonce-protected link that hides the warnings notice for the current user.
	 *
	 * @param string $key Migration result key.
	 */
	private static function hide_migration_warnings_url( string $key ): string {
		return get_admin_url( get_current_blog_id(), 'admin-post.php' ) . '?' . http_build_query(
			array(
				'action'   => self::HIDE_WARNINGS_ACTION,
				'key'      => $key,
				'_wpnonce' => wp_create_nonce( self::HIDE_WARNINGS_ACTION . ':' . $key ),
			),
			'',
			'&'
		);
	}

	/** Remember, for this user only, that they hid the current warnings notice. */
	public function hide_migration_warnings(): void {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Exact format and nonce checks below reject rather than normalize input.
		$key   = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? wp_unslash( $_GET['key'] ) : '';
		$nonce = isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ? wp_unslash( $_GET['_wpnonce'] ) : '';
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput
		if (
			( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) )
			|| 1 !== preg_match( '/\A[a-f0-9]{32}\z/D', $key )
			|| ! wp_verify_nonce( $nonce, self::HIDE_WARNINGS_ACTION . ':' . $key )
		) {
			wp_die( esc_html__( 'This link has expired. Return to the dashboard and try again.', 'cartpops' ), esc_html__( 'CartPops', 'cartpops' ), array( 'response' => 403 ) );
		}
		update_user_meta( get_current_user_id(), self::HIDDEN_WARNINGS_META, $key );
		if ( wp_safe_redirect( get_admin_url( get_current_blog_id(), 'index.php' ), 303 ) ) {
			exit;
		}
	}

	/**
	 * Say what a paused store waits for, and who can act on it.
	 *
	 * @param string[] $warnings Value-free migration status warnings.
	 */
	private function paused_for_review_message( array $warnings ): string {
		if ( in_array( 'legacy_custom_js_requires_review', $warnings, true ) ) {
			return current_user_can( 'manage_options' )
				? __( 'CartPops is paused until you review your old custom JavaScript below. Your settings are safe.', 'cartpops' )
				: __( 'CartPops is paused until a site administrator reviews the old custom JavaScript. Your settings are safe.', 'cartpops' );
		}
		return __( 'CartPops is paused until its update can finish. Your settings are safe. If you use CartPops Pro, check that your license is active for this site; otherwise, contact CartPops support.', 'cartpops' );
	}

	/**
	 * A "Learn more" link to one documentation page or section.
	 *
	 * @param string $path Documentation path, optionally with an #anchor.
	 */
	public static function docs_link_html( string $path ): string {
		return ' <a href="' . esc_url( self::DOCS_URL . $path ) . '" target="_blank" rel="noopener noreferrer">'
			. esc_html__( 'Learn more', 'cartpops' ) . '</a>';
	}

	/**
	 * Name the settings behind the warnings, without any stored values.
	 *
	 * @param array{reset: string[], retired: string[], changed: string[], custom_js: bool, pro: bool, other: int} $summary Grouped warnings.
	 */
	private function migration_warning_details_html( array $summary ): string {
		$sections = array(
			'reset'   => __( 'These settings had a value CartPops 2 could not read, so they now use their default. Check them in the CartPops settings:', 'cartpops' ),
			'retired' => __( 'These settings no longer exist in CartPops 2:', 'cartpops' ),
			'changed' => __( 'These settings now work a little differently:', 'cartpops' ),
		);
		$html     = '';
		foreach ( $sections as $group => $intro ) {
			if ( array() === $summary[ $group ] ) {
				continue;
			}
			$html .= '<p>' . esc_html( $intro ) . '</p><ul style="list-style:disc;margin-left:2em">';
			foreach ( $summary[ $group ] as $label ) {
				$html .= '<li>' . esc_html( $label ) . '</li>';
			}
			$html .= '</ul>';
		}
		if ( $summary['custom_js'] ) {
			$html .= '<p>' . esc_html__( 'Your custom JavaScript from version 1 is kept but not run.', 'cartpops' ) . '</p>';
		}
		if ( $summary['pro'] ) {
			$html .= '<p>' . esc_html__( 'Some Pro settings from version 1 are kept in your database but not used.', 'cartpops' ) . '</p>';
		}
		if ( 0 < $summary['other'] ) {
			$html .= '<p>' . esc_html(
				sprintf(
					/* translators: %d: number of other settings. */
					_n( '%d other setting could not be carried over.', '%d other settings could not be carried over.', $summary['other'], 'cartpops' ),
					$summary['other']
				)
			) . '</p>';
		}
		if ( '' === $html ) {
			return '';
		}
		return '<details style="margin:0 0 .75em"><summary style="cursor:pointer">'
			. esc_html__( 'See which settings', 'cartpops' ) . '</summary>' . $html . '</details>';
	}

	/**
	 * Show the preserved script read-only so it can be copied before acknowledging.
	 *
	 * @param string $code Preserved custom JavaScript, never executed.
	 */
	private function custom_js_code_html( string $code ): string {
		// WordPress escaping blanks invalid UTF-8, so drop only the invalid bytes.
		$code      = wp_check_invalid_utf8( $code, true );
		$truncated = strlen( $code ) > self::MAX_CUSTOM_JS_DISPLAY_BYTES;
		if ( $truncated ) {
			$code = function_exists( 'mb_strcut' )
				? mb_strcut( $code, 0, self::MAX_CUSTOM_JS_DISPLAY_BYTES, 'UTF-8' )
				: substr( $code, 0, self::MAX_CUSTOM_JS_DISPLAY_BYTES );
		}
		$html = '<details style="margin:0 0 .75em"><summary style="cursor:pointer">'
			. esc_html__( 'Show your saved custom JavaScript', 'cartpops' ) . '</summary>'
			. '<p>' . esc_html__( 'Copy it somewhere safe if you want to move it into your theme or a snippets plugin.', 'cartpops' ) . '</p>'
			. '<textarea readonly rows="8" class="large-text code" spellcheck="false">' . "\n" . esc_textarea( $code ) . '</textarea>';
		if ( $truncated ) {
			$html .= '<p>' . esc_html__( 'Only the beginning is shown. The full copy remains saved in your database.', 'cartpops' ) . '</p>';
		}
		return $html . '</details>';
	}

	/** Render no source values, only an opaque binding to the reviewed state. */
	private function render_custom_js_review(): void {
		if ( is_network_admin() ) {
			$this->render_site_review_link( get_current_blog_id() );
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$state = $this->legacy_settings_migrator->get_custom_js_review_state();
		if ( null === $state || $state['acknowledged'] || get_current_blog_id() !== $state['blog_id'] || get_current_network_id() !== $state['network_id'] ) {
			return;
		}
		echo '<div class="notice notice-warning"><h2>' . esc_html__( 'Review your old custom JavaScript', 'cartpops' ) . '</h2><p>'
			. esc_html__( 'CartPops 2 does not run the custom JavaScript from version 1. It stays saved in your database. Copy it if you still need it, then confirm below to finish the update.', 'cartpops' )
			. self::docs_link_html( '/upgrading-to-v2#custom-javascript-does-not-run' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper escapes every part.
			. '</p>'
			. $this->custom_js_code_html( $state['code'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper escapes every part.
			. '<form method="post" action="' . esc_url( get_admin_url( $state['blog_id'], 'admin-post.php' ) ) . '">';
		$fields = array(
			'action'                      => self::CUSTOM_JS_REVIEW_ACTION,
			'_wpnonce'                    => wp_create_nonce( $this->custom_js_review_nonce_action( $state ) ),
			'cartpops_review_blog_id'     => (string) $state['blog_id'],
			'cartpops_review_network_id'  => (string) $state['network_id'],
			'cartpops_review_fingerprint' => $state['fingerprint'],
		);
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		echo '<p><label><input type="checkbox" name="cartpops_review_confirm" value="1" required> '
			. esc_html__( 'I understand this custom JavaScript will not run in CartPops 2.', 'cartpops' )
			. '</label></p><p><button type="submit" class="button button-primary">'
			. esc_html__( 'Confirm and finish the update', 'cartpops' ) . '</button></p></form></div>';
	}

	/**
	 * Bind CSRF authority to this site and exact review state.
	 *
	 * @param array{fingerprint: string, blog_id: int, network_id: int, acknowledged: bool} $state Review binding.
	 */
	private function custom_js_review_nonce_action( array $state ): string {
		return self::CUSTOM_JS_REVIEW_ACTION . ':' . $state['blog_id'] . ':' . $state['network_id'] . ':' . $state['fingerprint'];
	}

	/**
	 * Network administrators must visit a single site's own review form.
	 *
	 * @param int $site_id Persisted site needing review, never request input.
	 */
	private function render_site_review_link( int $site_id ): void {
		echo '<div class="notice notice-info"><p><a href="' . esc_url( get_admin_url( $site_id, 'index.php' ) ) . '">'
			. esc_html__( 'Open this site dashboard to review its migration', 'cartpops' ) . '</a></p></div>';
	}

	/**
	 * A native, value-free response with no caller-controlled return destination.
	 *
	 * @param string $message Translated finite result message.
	 * @param int    $response HTTP status code.
	 */
	private function finish_custom_js_review( string $message, int $response ): void {
		wp_die(
			'<p>' . esc_html( $message ) . '</p><p><a href="' . esc_url( get_admin_url( get_current_blog_id(), 'index.php' ) ) . '">'
			. esc_html__( 'Return to this site dashboard', 'cartpops' ) . '</a></p>',
			esc_html__( 'CartPops migration review', 'cartpops' ),
			array( 'response' => (int) $response )
		);
	}
}
