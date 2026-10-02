<?php
/**
 * Migrates CartPops V1 per-setting options into the V2 settings document.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Migration;

use CartPops\Admin\SettingsRepository;
use CartPops\Compatibility\LegacyPoweredByLink;
use CartPops\Recommendations\RecommendationButtonPresentation;
use CartPops\Settings\SecondaryActionSettings;
use CartPops\Setup\SiteUpgradeContext;
use CartPops\Setup\SiteUpgradeContextDrift;

/**
 * Migrates CartPops V1 per-setting options into the V2 settings document.
 */
final class LegacySettingsMigrator {

	public const SCHEMA_VERSION           = 1;
	public const RULES_RETIREMENT_WARNING = 'legacy_rules_behavior_retired';

	private const SETTINGS_OPTION               = 'cartpops_settings';
	private const SNAPSHOT_OPTION               = 'cartpops_v1_migration_snapshot_v1';
	private const JOURNAL_OPTION                = 'cartpops_v1_migration_journal';
	private const COMPLETION_OPTION             = 'cartpops_v1_migration_version';
	private const QUARANTINE_OPTION             = 'cartpops_v1_custom_js_quarantine_v1';
	private const JS_REVIEW_OPTION              = 'cartpops_v1_custom_js_review_v1';
	private const RECOVERY_OPTION               = 'cartpops_v2_migration_recovery_v1';
	private const LOCK_OPTION                   = 'cartpops_v1_migration_lock_v1';
	private const MAINTENANCE_OPTION            = 'cartpops_v1_migration_maintenance_v1';
	private const PROVENANCE_OPTION             = 'cartpops_v2_install_provenance_v1';
	private const PRE_SDK_EVIDENCE_OPTION       = PreSdkLegacyInstallEvidence::SITE_EVIDENCE_OPTION;
	private const PRE_SDK_MARKER_SCHEMA         = 2;
	private const COMPATIBILITY_OPTION          = 'cartpops_v1_paid_rules_disposition_v1';
	private const RULES_RETIREMENT_OPTION       = 'cartpops_v1_rules_retirement_v1';
	private const NORMALIZATION_OPTION          = 'cartpops_settings_normalization_version';
	private const NORMALIZATION_RECOVERY_OPTION = 'cartpops_settings_pre_normalization_v1';
	private const NORMALIZATION_SCHEMA_VERSION  = 3;
	private const NETWORK_COHORT_OPTION         = 'cartpops_v1_network_legacy_cohort_v1';
	private const LEGACY_RULE_PROBE_ROW         = '__cartpops_legacy_rule_probe__';
	private const ENTITLEMENT_DRIFT_DIAGNOSTIC  = 'legacy_paid_entitlement_state_uncertain';
	private const BOOT_CONTROL_OPTIONS          = array(
		self::COMPLETION_OPTION,
		self::PROVENANCE_OPTION,
		self::PRE_SDK_EVIDENCE_OPTION,
		self::SNAPSHOT_OPTION,
		self::JOURNAL_OPTION,
		self::MAINTENANCE_OPTION,
		self::LOCK_OPTION,
		self::RECOVERY_OPTION,
		self::QUARANTINE_OPTION,
		self::JS_REVIEW_OPTION,
		self::COMPATIBILITY_OPTION,
		self::RULES_RETIREMENT_OPTION,
		self::NORMALIZATION_OPTION,
		self::NORMALIZATION_RECOVERY_OPTION,
	);
	private const MAX_RAW_BYTES                 = 1048576;
	private const MAX_VALUE_DEPTH               = 64;
	private const MAX_VALUE_NODES               = 10000;
	private const MAX_SOURCE_RECORDS            = 500;
	private const MAX_SNAPSHOT_BYTES            = 4194304;
	private const MAX_INTERNAL_BYTES            = 4194304;
	private const MAX_RECOVERY_RECORDS          = 20;
	private const MAX_RECOVERY_BYTES            = 4194304;
	private const MAX_MONEY_VALUE               = 1000000000.0;
	private const MAX_TEXT_LENGTH               = 1000;
	private const LOCK_TTL                      = 120;
	private const LEGACY_NETWORK_BASENAMES      = array(
		'cartpops/cartpops.php',
		'cartpops-pro/cartpops.php',
	);
	private const CUSTOMER_TEXT_MAPPINGS        = array(
		'cartpops_drawer_header_title_text'         => 'drawer.header_title',
		'cartpops_generic_add_to_cart_message_text' => 'drawer.added_to_cart_message',
		'cartpops_coupon_title_text'                => 'drawer.coupon_title',
		'cartpops_coupon_input_placeholder_text'    => 'drawer.coupon_input_placeholder',
		'cartpops_coupon_button_text'               => 'drawer.coupon_button_text',
		'cartpops_subtotal_line_item_text'          => 'drawer.subtotal_label',
		'cartpops_discount_line_item_text'          => 'drawer.discount_label',
		'cartpops_total_line_item_text'             => 'drawer.total_label',
		'cartpops_checkout_button_empty_text'       => 'drawer.empty_button_text',
		'cartpops_drawer_empty_title_text'          => 'drawer.empty_title',
		'cartpops_drawer_empty_subtitle_text'       => 'drawer.empty_subtitle',
	);
	private const INLINE_COLOR_MAPPINGS         = array(
		'cartpops_color_cart_laucher_background'        => 'background',
		'cartpops_color_cart_laucher_text'              => 'text',
		'cartpops_color_cart_laucher_bubble_background' => 'badge_bg',
		'cartpops_color_cart_laucher_bubble_text'       => 'badge_text',
	);
	/**
	 * Decoded values from the immutable pre-migration snapshot.
	 *
	 * @var array<string, mixed>
	 */
	private array $source_values = array();

	/**
	 * Value-free disposition report keyed by source option name.
	 *
	 * @var array<string, array{status: string, targets: string[]}>
	 */
	private array $dispositions = array();

	/**
	 * Value-free migration warning codes.
	 *
	 * @var string[]
	 */
	private array $warnings = array();

	/**
	 * Unsafe serialized source payloads keyed by option name.
	 *
	 * @var array<string, string>
	 */
	private array $unsafe_sources = array();

	/**
	 * Names that were physically present in the V1 snapshot.
	 *
	 * @var array<string, true>
	 */
	private array $source_option_names = array();

	/**
	 * Explicit phase observer used by deterministic migration runners.
	 *
	 * @var \Closure(string): void|null
	 */
	private ?\Closure $checkpoint_observer;

	/**
	 * Warnings that could not be durably journaled during this request.
	 *
	 * @var string[]
	 */
	private array $runtime_warnings = array();

	/**
	 * Explicit decision for the otherwise indistinguishable zero-option V1 case.
	 *
	 * @var bool|null
	 */
	private ?bool $legacy_footprint_override;

	/**
	 * Request-local historical identity captured before Freemius initialization.
	 *
	 * @var PreSdkLegacyInstallEvidence|null
	 */
	private ?PreSdkLegacyInstallEvidence $pre_sdk_legacy_evidence;

	/**
	 * Exact uncached option persistence.
	 *
	 * @var MigrationOptionStore
	 */
	private MigrationOptionStore $store;

	/**
	 * Exact request-local site/database binding.
	 *
	 * @var SiteUpgradeContext
	 */
	private SiteUpgradeContext $site_context;

	/**
	 * Unique main-site persistence for immutable network migration records.
	 *
	 * @var NetworkOptionStore
	 */
	private NetworkOptionStore $network_store;

	/**
	 * Value-free owner token for this request.
	 *
	 * @var string
	 */
	private string $lock_owner;

	/**
	 * Injected wall clock.
	 *
	 * @var \Closure(): int
	 */
	private \Closure $clock;

	/**
	 * Exact serialized bytes of the currently held lock.
	 *
	 * @var string
	 */
	private string $held_lock_raw = '';

	/**
	 * Whether current-site evidence proves a V1 install.
	 *
	 * @var bool
	 */
	private bool $legacy_footprint_detected = false;

	/**
	 * Whether a closed retirement record proves prior V1 rule evidence.
	 *
	 * @var bool
	 */
	private bool $legacy_rules_retirement_detected = false;

	/**
	 * Durable value-free historical identity retained across interrupted retries.
	 *
	 * @var string|null
	 */
	private ?string $durable_pre_sdk_basename = null;

	/**
	 * Optional premium-only entitlement capability issuer.
	 *
	 * @var LegacyPaidEntitlementBridge|null
	 */
	private ?LegacyPaidEntitlementBridge $paid_entitlement_bridge;

	/**
	 * Constructor.
	 *
	 * @param \Closure(string): void|null      $test_checkpoint           Explicit phase observer; historical parameter name retained.
	 * @param bool|null                        $legacy_footprint_override Explicit ambiguous-install decision.
	 * @param \Closure(): int|null             $clock                     Explicit wall clock.
	 * @param string|null                      $lock_owner                Explicit value-free lock owner.
	 * @param LegacyPaidEntitlementBridge|null $paid_entitlement_bridge Optional premium-only entitlement bridge.
	 * @param PreSdkLegacyInstallEvidence|null $pre_sdk_legacy_evidence Value-free identity captured before SDK initialization.
	 */
	public function __construct( ?\Closure $test_checkpoint = null, ?bool $legacy_footprint_override = null, ?\Closure $clock = null, ?string $lock_owner = null, ?LegacyPaidEntitlementBridge $paid_entitlement_bridge = null, ?PreSdkLegacyInstallEvidence $pre_sdk_legacy_evidence = null ) {
		$this->checkpoint_observer       = $test_checkpoint;
		$this->legacy_footprint_override = $legacy_footprint_override;
		$this->pre_sdk_legacy_evidence   = $pre_sdk_legacy_evidence;
		$this->network_store             = new NetworkOptionStore();
		$this->clock                     = $clock ?? static fn(): int => time();
		$this->lock_owner                = $lock_owner ?? wp_generate_uuid4();
		$this->paid_entitlement_bridge   = $paid_entitlement_bridge;
	}

	/**
	 * Describe one exact, non-executed customization awaiting administrator review.
	 *
	 * @return array{fingerprint: string, blog_id: int, network_id: int, acknowledged: bool}|null
	 */
	public function get_custom_js_review_state(): ?array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return null;
		}
		return $this->custom_js_review_operation( null );
	}

	/**
	 * Explicitly accept retirement of only the exact currently preserved script.
	 *
	 * The admin request must independently verify its POST nonce and confirmation.
	 * This domain boundary additionally enforces site-administrator capability.
	 *
	 * @param string $expected_fingerprint Opaque identity displayed for review.
	 */
	public function acknowledge_custom_js_retirement( string $expected_fingerprint ): bool {
		if ( ! current_user_can( 'manage_options' ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected_fingerprint ) ) {
			return false;
		}
		$state = $this->custom_js_review_operation( $expected_fingerprint );
		return null !== $state && $state['acknowledged'];
	}

	/**
	 * Inspect or acknowledge under the same database/site locks as completion.
	 *
	 * @param string|null $expected_fingerprint Null performs a read-only inspection.
	 * @return array{fingerprint: string, blog_id: int, network_id: int, acknowledged: bool}|null
	 */
	private function custom_js_review_operation( ?string $expected_fingerprint ): ?array {
		try {
			$context = SiteUpgradeContext::capture();
			return $context->run(
				function () use ( $context, $expected_fingerprint ): ?array {
					$this->bind_site_context( $context );
					$control = $this->read_boot_control_state();
					if ( null !== $this->classify_site_control_state( $control['rows'] ) ) {
						return null;
					}
					if ( null !== $expected_fingerprint && MigrationOutcome::COMPLETE !== $this->acquire_lock() ) {
						return null;
					}
					$state = null;
					try {
						$result = ( new LegacyCompatibilityTerminalFence( $context ) )->run(
							$this->compatibility_terminal_option_names(),
							function ( bool $has_rules, array $locked, bool $overbound ) use ( $expected_fingerprint, &$state ): MigrationOutcome {
								$controls = $this->locked_control_rows( $locked );
								if ( $overbound || null !== $this->classify_site_control_state( $controls ) || ! current_user_can( 'manage_options' ) ) {
									return MigrationOutcome::FAILED;
								}
								$fingerprint = $this->custom_js_review_fingerprint( $locked, $controls );
								if ( null === $fingerprint ) {
									return MigrationOutcome::FAILED;
								}
								$record       = $this->custom_js_review_record( $fingerprint );
								$row          = $controls[ self::JS_REVIEW_OPTION ];
								$acknowledged = $row['exists'] && $record === $row['value'];
								if ( null !== $expected_fingerprint ) {
									if ( ! hash_equals( $fingerprint, $expected_fingerprint ) || ! $this->fence_is_current()
										|| ! $this->persist_locked_option( self::JS_REVIEW_OPTION, $row, $record, false )
										|| ! $this->custom_js_review_rows_unchanged( $locked, $record ) ) {
										return MigrationOutcome::FAILED;
									}
									$acknowledged = true;
								}
								$state = array(
									'fingerprint'  => $fingerprint,
									'blog_id'      => $this->site_context->blog_id(),
									'network_id'   => $this->site_context->network_id(),
									'acknowledged' => $acknowledged,
								);
								return MigrationOutcome::COMPLETE;
							},
							$this->store
						);
						return MigrationOutcome::COMPLETE === $result ? $state : null;
					} finally {
						if ( null !== $expected_fingerprint ) {
							$this->release_lock();
						}
					}
				}
			);
		} catch ( \Throwable ) {
			return null;
		}
	}

	/**
	 * Bind site/network and all three exact preserved rows, never script execution.
	 *
	 * @param array<string, array{raw_value: string, autoload: string}>                             $locked Locked raw rows.
	 * @param array<string, array{exists: bool, value: mixed, raw_value: string, autoload: string}> $controls Decoded controls.
	 */
	private function custom_js_review_fingerprint( array $locked, array $controls ): ?string {
		$source     = $locked['cartpops_custom_js'] ?? null;
		$snapshot   = $controls[ self::SNAPSHOT_OPTION ];
		$quarantine = $controls[ self::QUARANTINE_OPTION ];
		if ( null === $source || ! $snapshot['exists'] || ! $quarantine['exists']
			|| ! $this->snapshot_is_valid( $snapshot['value'] ) || ! $this->quarantine_record_is_valid( $quarantine['value'] )
			|| ! $this->store->autoload_is( $snapshot['autoload'], false ) || ! $this->store->autoload_is( $quarantine['autoload'], false )
			|| ( $snapshot['value']['options']['cartpops_custom_js'] ?? null ) !== $source
			|| $source['raw_value'] !== $quarantine['value']['raw_value']
			|| array(
				'status'  => 'quarantined',
				'targets' => array( self::QUARANTINE_OPTION ),
			) !== ( $snapshot['value']['dispositions']['cartpops_custom_js'] ?? null )
			|| ! in_array( 'legacy_custom_js_requires_review', ( new LegacyPaidStateInspector( $this->site_context ) )->inspect_raw_rows( array( 'cartpops_custom_js' => $source['raw_value'] ) ), true ) ) {
			return null;
		}
		return hash( 'sha256', $this->store->serialize_value( array( 1, $this->site_context->blog_id(), $this->site_context->network_id(), $source, $snapshot['raw_value'], $quarantine['raw_value'] ) ) );
	}

	/**
	 * Recheck exact protected rows after a write callback, before transaction commit.
	 *
	 * @param array<string, array{raw_value: string, autoload: string}> $locked Original locked rows.
	 * @param array<string, mixed>|null                                 $acknowledgment Exact required review record.
	 */
	private function custom_js_review_rows_unchanged( array $locked, ?array $acknowledgment = null ): bool {
		foreach ( array( 'cartpops_custom_js', self::SNAPSHOT_OPTION, self::QUARANTINE_OPTION ) as $option ) {
			$row = $this->store->read( $option );
			if ( ! $row['success'] || ! $row['exists'] || ! isset( $locked[ $option ] )
				|| $row['raw_value'] !== $locked[ $option ]['raw_value'] || $row['autoload'] !== $locked[ $option ]['autoload'] ) {
				return false;
			}
		}
		$acknowledgment = $acknowledgment ?? $this->locked_control_rows( $locked )[ self::JS_REVIEW_OPTION ]['value'];
		$row            = $this->store->read( self::JS_REVIEW_OPTION );
		if ( ! $row['success'] || ! $row['exists'] || $this->store->serialize_value( $acknowledgment ) !== $row['raw_value'] || ! $this->store->autoload_is( $row['autoload'], false ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Build the closed, site-bound retirement acknowledgment.
	 *
	 * @param string $fingerprint Exact preserved customization identity.
	 * @return array{schema_version: int, blog_id: int, network_id: int, fingerprint: string, status: string}
	 */
	private function custom_js_review_record( string $fingerprint ): array {
		return array(
			'schema_version' => 1,
			'blog_id'        => $this->site_context->blog_id(),
			'network_id'     => $this->site_context->network_id(),
			'fingerprint'    => $fingerprint,
			'status'         => 'retired',
		);
	}

	/**
	 * Validate only the closed current acknowledgment schema.
	 *
	 * @param mixed $record Untrusted stored acknowledgment.
	 */
	private function custom_js_review_record_is_valid( mixed $record ): bool {
		return is_array( $record ) && $this->has_exact_keys( $record, array( 'schema_version', 'blog_id', 'network_id', 'fingerprint', 'status' ) )
			&& 1 === $record['schema_version'] && is_int( $record['blog_id'] ) && $record['blog_id'] > 0
			&& is_int( $record['network_id'] ) && $record['network_id'] > 0
			&& is_string( $record['fingerprint'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $record['fingerprint'] ) && 'retired' === $record['status'];
	}

	/**
	 * Run the legacy settings migration for the current site.
	 *
	 * @param SiteUpgradeContext|null   $context Exact whole-convergence binding.
	 * @param MigrationOptionStore|null $store Exact caller-owned transaction cache boundary.
	 */
	public function migrate( ?SiteUpgradeContext $context = null, ?MigrationOptionStore $store = null ): MigrationOutcome {
		try {
			$context = $context ?? SiteUpgradeContext::capture();
			return $context->run(
				function () use ( $context, $store ): MigrationOutcome {
					$this->bind_site_context( $context, $store );
					return $this->migrate_bound();
				}
			);
		} catch ( SiteUpgradeContextDrift ) {
			return MigrationOutcome::FAILED;
		}
	}

	/** Run migration logic while the exact site fence is active. */
	private function migrate_bound(): MigrationOutcome {
		$this->runtime_warnings                 = array();
		$this->legacy_rules_retirement_detected = false;
		$this->durable_pre_sdk_basename         = null;

		try {
			$control         = $this->read_boot_control_state();
			$control_outcome = $this->classify_site_control_state( $control['rows'] );
			if ( null !== $control_outcome ) {
				return $control_outcome;
			}
			$historical_completion = $this->historical_completion_outcome( $control['rows'] );
			if ( null !== $historical_completion ) {
				return $historical_completion;
			}
			if ( $this->pre_sdk_marker_requires_direct_proof( $control['rows'][ self::PRE_SDK_EVIDENCE_OPTION ] ) ) {
				$this->runtime_warnings[] = 'pre_sdk_legacy_identity_network_unavailable';
				return MigrationOutcome::FAILED;
			}
			if ( ! ( new LegacyCompatibilityTerminalFence( $this->site_context ) )->membership_lock_is_transactional() ) {
				return MigrationOutcome::FAILED;
			}
			$this->bind_durable_pre_sdk_identity( $control['rows'] );
			$reopened = $this->preserve_pre_sdk_identity_and_reopen_false_v2_born( $control['rows'] );
			if ( null !== $reopened ) {
				if ( MigrationOutcome::COMPLETE !== $reopened ) {
					return $reopened;
				}
				$control         = $this->read_boot_control_state();
				$control_outcome = $this->classify_site_control_state( $control['rows'] );
				if ( null !== $control_outcome ) {
					return $control_outcome;
				}
				$historical_completion = $this->historical_completion_outcome( $control['rows'] );
				if ( null !== $historical_completion ) {
					return $historical_completion;
				}
				if ( $this->pre_sdk_marker_requires_direct_proof( $control['rows'][ self::PRE_SDK_EVIDENCE_OPTION ] ) ) {
					$this->runtime_warnings[] = 'pre_sdk_legacy_identity_network_unavailable';
					return MigrationOutcome::FAILED;
				}
				$this->bind_durable_pre_sdk_identity( $control['rows'] );
			}

			$paid_state                                = ( new LegacyPaidStateInspector( $this->site_context ) )->read();
			$paid_state                                = $this->apply_pre_sdk_historical_edition( $paid_state );
			$paid_entitlement_plan                     = $this->prepare_paid_entitlement_plan( $paid_state );
			$effective_diagnostics                     = null === $paid_entitlement_plan
				? $paid_state['diagnostics']
				: $this->without_paid_entitlement_adapter( $paid_state['diagnostics'] );
			$terminal_exists                           = $control['rows'][ self::COMPLETION_OPTION ]['exists'] || $control['rows'][ self::PROVENANCE_OPTION ]['exists'];
			$preflight_diagnostics                     = $terminal_exists
				? $effective_diagnostics
				: array_values( array_filter( $effective_diagnostics, array( LegacyPaidStateInspector::class, 'is_hard_diagnostic' ) ) );
			$compatibility_disposition                 = $control['rows'][ self::COMPATIBILITY_OPTION ];
			$rules_retirement                          = $control['rows'][ self::RULES_RETIREMENT_OPTION ];
			$legacy_disposition                        = $compatibility_disposition['exists'] && $this->legacy_paid_disposition_is_valid( $compatibility_disposition['value'] );
			$rules_retirement_valid                    = $rules_retirement['exists'] && $this->rules_retirement_record_is_valid( $rules_retirement['value'] );
			$this->legacy_rules_retirement_detected    = $rules_retirement_valid;
			$rules_retirement_is_repairable            = $rules_retirement['exists']
				&& self::SCHEMA_VERSION === $this->durable_record_schema( $rules_retirement['value'] );
			$rules_retirement_needs_locked_decision    = $rules_retirement_is_repairable && ! $rules_retirement_valid;
			$terminal_rules_need_record                = $terminal_exists && $control['has_legacy_rules'] && ! $rules_retirement_valid;
			$terminal_retirement_needs_autoload_repair = $terminal_exists
				&& $rules_retirement_valid
				&& ! $this->store->autoload_is( $rules_retirement['autoload'], false );
			if ( $control['rows'][ self::JS_REVIEW_OPTION ]['exists'] || $rules_retirement_needs_locked_decision || $terminal_rules_need_record || $terminal_retirement_needs_autoload_repair || array() !== $preflight_diagnostics || $legacy_disposition || $compatibility_disposition['exists'] ) {
				$lock = $this->acquire_lock();
				if ( MigrationOutcome::COMPLETE !== $lock ) {
					return $lock;
				}
				try {
					return $this->terminal_compatibility_outcome( null, null, $paid_entitlement_plan );
				} finally {
					$this->release_lock();
				}
			}

			$completion_outcome = $this->current_completion_outcome( $control['rows'][ self::COMPLETION_OPTION ] );
			if ( null !== $completion_outcome ) {
				return $completion_outcome;
			}

			$provenance = $control['rows'][ self::PROVENANCE_OPTION ];
			if ( $provenance['exists'] ) {
				return $this->provenance_outcome( $provenance );
			}

			$journal     = $control['rows'][ self::JOURNAL_OPTION ];
			$maintenance = $control['rows'][ self::MAINTENANCE_OPTION ];
			$snapshot    = $control['rows'][ self::SNAPSHOT_OPTION ];

			$journal_schema     = $journal['exists'] ? $this->durable_record_schema( $journal['value'] ) : self::SCHEMA_VERSION;
			$maintenance_schema = $maintenance['exists'] ? $this->durable_record_schema( $maintenance['value'] ) : self::SCHEMA_VERSION;
			$snapshot_schema    = $snapshot['exists'] ? $this->durable_record_schema( $snapshot['value'] ) : self::SCHEMA_VERSION;
			if ( $journal_schema > self::SCHEMA_VERSION || $maintenance_schema > self::SCHEMA_VERSION || $snapshot_schema > self::SCHEMA_VERSION ) {
				return MigrationOutcome::FUTURE_VERSION;
			}
			if (
				( $journal['exists'] && ( self::SCHEMA_VERSION !== $journal_schema || ! $this->journal_is_valid( $journal['value'] ) ) )
				|| ( $maintenance['exists'] && ( self::SCHEMA_VERSION !== $maintenance_schema || ! $this->maintenance_marker_is_valid( $maintenance['value'] ) ) )
				|| ( $snapshot['exists'] && ( self::SCHEMA_VERSION !== $snapshot_schema || ! $this->snapshot_is_valid( $snapshot['value'] ) ) )
			) {
				return $this->fail_without_journal( 'invalid_durable_migration_record' );
			}

			if ( $maintenance['exists'] ) {
				if (
					! $this->store->autoload_is( $maintenance['autoload'], false )
					&& ! $this->store->compare_and_swap( self::MAINTENANCE_OPTION, $maintenance['raw_value'], $maintenance['value'], false )
				) {
					return $this->fail_without_journal( 'maintenance_marker_autoload_repair_failed' );
				}
				return MigrationOutcome::MAINTENANCE_REQUIRED;
			}

			$lock = $this->acquire_lock();
			if ( MigrationOutcome::COMPLETE !== $lock ) {
				return $lock;
			}

			try {
				return $this->migrate_while_locked( $paid_entitlement_plan );
			} finally {
				$this->release_lock();
			}
		} catch ( MigrationMaintenanceException $exception ) {
			$this->runtime_warnings[] = $exception->getMessage();
			return $this->persist_maintenance_marker( $exception->getMessage() )
				? MigrationOutcome::MAINTENANCE_REQUIRED
				: $this->fail_without_journal( 'maintenance_marker_write_failed' );
		} catch ( MigrationReadException $exception ) {
			return $this->fail_without_journal( $exception->getMessage() );
		}
	}

	/**
	 * Check the exact value-free retained-resource schema.
	 *
	 * @param mixed $record Candidate disposition record.
	 */
	private function compatibility_disposition_is_valid( mixed $record ): bool {
		if (
			! is_array( $record )
			|| ! $this->has_exact_keys( $record, array( 'schema_version', 'resource', 'status', 'diagnostics' ) )
		) {
			return false;
		}
		if (
			self::SCHEMA_VERSION !== $this->durable_record_schema( $record )
			|| 'legacy_compatibility_state' !== ( $record['resource'] ?? null )
			|| 'retained' !== ( $record['status'] ?? null )
			|| ! is_array( $record['diagnostics'] ?? null )
			|| ! array_is_list( $record['diagnostics'] )
			|| array() === $record['diagnostics']
			|| count( $record['diagnostics'] ) > 8
		) {
			return false;
		}
		$allowed = array(
			'legacy_custom_js_requires_review',
			'legacy_custom_recommendations_require_conversion',
			'legacy_paid_entitlement_requires_adapter',
			self::ENTITLEMENT_DRIFT_DIAGNOSTIC,
			'legacy_paid_features_require_conversion',
			'legacy_rules_require_conversion',
			'legacy_paid_state_uncertain',
			'legacy_retained_behavior_requires_review',
		);
		$sorted  = array_values( array_unique( $record['diagnostics'] ) );
		sort( $sorted, SORT_STRING );
		return $sorted === $record['diagnostics']
			&& array() === array_diff( $record['diagnostics'], $allowed );
	}

	/**
	 * Recognize only the precise pre-correction record so the terminal fence can
	 * normalize its inaccurate paid-rule vocabulary without losing retry state.
	 *
	 * @param mixed $record Candidate legacy disposition.
	 */
	private function legacy_paid_disposition_is_valid( mixed $record ): bool {
		if (
			! is_array( $record )
			|| ! $this->has_exact_keys( $record, array( 'schema_version', 'resource', 'status', 'diagnostics' ) )
			|| self::SCHEMA_VERSION !== $this->durable_record_schema( $record )
			|| 'legacy_paid_state' !== ( $record['resource'] ?? null )
			|| 'retained' !== ( $record['status'] ?? null )
			|| ! is_array( $record['diagnostics'] ?? null )
			|| ! array_is_list( $record['diagnostics'] )
			|| array() === $record['diagnostics']
			|| count( $record['diagnostics'] ) > 6
		) {
			return false;
		}
		$allowed = array(
			'legacy_custom_js_requires_review',
			'legacy_paid_entitlement_requires_adapter',
			'legacy_paid_features_require_conversion',
			'legacy_paid_rules_require_conversion',
			'legacy_paid_state_uncertain',
			'legacy_retained_behavior_requires_review',
		);
		$sorted  = array_values( array_unique( $record['diagnostics'] ) );
		sort( $sorted, SORT_STRING );
		return $sorted === $record['diagnostics']
			&& array() === array_diff( $record['diagnostics'], $allowed );
	}

	/**
	 * Validate the closed, value-free record for deliberately retired V1 rules.
	 *
	 * @param mixed $record Candidate retirement disposition.
	 */
	private function rules_retirement_record_is_valid( mixed $record ): bool {
		return is_array( $record )
			&& $this->has_exact_keys( $record, array( 'schema_version', 'resource', 'status', 'warning' ) )
			&& self::SCHEMA_VERSION === $this->durable_record_schema( $record )
			&& 'cartpops_rules' === ( $record['resource'] ?? null )
			&& 'retired' === ( $record['status'] ?? null )
			&& self::RULES_RETIREMENT_WARNING === ( $record['warning'] ?? null );
	}

	/**
	 * Run the journalled migration while holding a verified fence.
	 *
	 * @param LegacyPaidEntitlementPlan|null $paid_entitlement_plan One-use exact-row entitlement capability.
	 *
	 * @throws MigrationMaintenanceException When the bounded durable snapshot cannot be created.
	 */
	private function migrate_while_locked( ?LegacyPaidEntitlementPlan $paid_entitlement_plan ): MigrationOutcome {
		$marker             = $this->read_internal_option( self::COMPLETION_OPTION );
		$completion_outcome = $this->current_completion_outcome( $marker );
		if ( null !== $completion_outcome ) {
			return $completion_outcome;
		}

		$snapshot_row = $this->read_internal_option( self::SNAPSHOT_OPTION );
		if ( ! $snapshot_row['exists'] ) {
			$snapshot = $this->create_snapshot();
			if ( ! $this->legacy_footprint_detected ) {
				return $this->commit_not_applicable_provenance( $paid_entitlement_plan );
			}
			if ( strlen( $this->store->serialize_value( $snapshot ) ) > self::MAX_SNAPSHOT_BYTES ) {
				throw new MigrationMaintenanceException( 'legacy_snapshot_bounds_exceeded' );
			}
			if ( ! $this->fence_is_current() ) {
				return MigrationOutcome::BUSY;
			}
			$added = $this->store->add_immutable( self::SNAPSHOT_OPTION, $snapshot, false );
			if ( ! $added['success'] ) {
				return $this->fail_without_journal( 'snapshot_write_failed' );
			}
			$snapshot_row = $this->read_internal_option( self::SNAPSHOT_OPTION );
			$snapshot     = $snapshot_row['value'];
			if ( ! is_array( $snapshot ) || ! $this->snapshot_is_valid( $snapshot ) ) {
				return $this->fail_without_journal( 'snapshot_checksum_mismatch' );
			}
			if ( ! $this->write_journal( 'snapshot', $snapshot ) ) {
				return $this->fail_without_journal( 'journal_snapshot_write_failed' );
			}
			$this->checkpoint( 'after_snapshot' );
		} else {
			$snapshot = $snapshot_row['value'];
			if ( ! is_array( $snapshot ) || ! $this->snapshot_is_valid( $snapshot ) ) {
				return $this->fail_without_journal( 'snapshot_checksum_mismatch' );
			}
			if (
				! $this->store->autoload_is( $snapshot_row['autoload'], false )
				&& ! $this->store->compare_and_swap( self::SNAPSHOT_OPTION, $snapshot_row['raw_value'], $snapshot, false )
			) {
				return $this->fail_without_journal( 'snapshot_autoload_repair_failed' );
			}
		}

		$options = isset( $snapshot['options'] ) && is_array( $snapshot['options'] ) ? $snapshot['options'] : array();
		$this->load_source_values( $options );
		$mapped = $this->build_mapped_settings( $options );

		if ( ! $this->quarantine_custom_js( $options ) ) {
			return $this->fail( 'custom_js_quarantine_write_failed', $snapshot );
		}

		$stored = array();
		if ( array() !== $mapped ) {
			$prepared = $this->prepare_existing_v2_settings();
			if ( ! $prepared['success'] ) {
				return $this->fail( 'v2_recovery_write_failed', $snapshot );
			}
			$stored   = $prepared['settings'];
			$settings = $this->merge_existing_over_mapped( $mapped, $stored );
			if ( ! $this->persist_settings_atomically( $prepared, $settings ) ) {
				return $this->fail( 'settings_write_failed', $snapshot );
			}
		}

		if ( ! $this->write_journal( 'settings_written', $snapshot ) ) {
			return $this->fail_without_journal( 'journal_settings_written_write_failed' );
		}
		$this->checkpoint( 'after_settings_write' );
		if ( $this->invalid_inline_launcher_color_requires_review() ) {
			throw new MigrationMaintenanceException( 'invalid_inline_launcher_color_retained' );
		}
		if ( $this->invalid_recommendation_button_requires_review() ) {
			throw new MigrationMaintenanceException( 'invalid_recommendation_button_presentation_retained' );
		}
		return $this->terminal_compatibility_outcome( MigrationOutcome::COMPLETE, $snapshot, $paid_entitlement_plan );
	}

	/**
	 * Preserve dormant paid presentation inside the locked compatibility decision.
	 *
	 * The immutable full V1 snapshot remains the recovery authority, but only the
	 * shared-storage presentation fields are written while paid behavior is still
	 * nonterminal. A later authorized retry consumes the same snapshot and runs the
	 * complete migration. Existing valid V2 values keep their normal precedence,
	 * and a site without historical V1 evidence remains byte-for-byte untouched.
	 *
	 * @throws MigrationMaintenanceException When the bounded durable snapshot cannot be created.
	 */
	private function persist_dormant_paid_presentation_mapping(): bool {
		$snapshot_row = $this->read_internal_option( self::SNAPSHOT_OPTION );
		if ( ! $snapshot_row['exists'] ) {
			$snapshot = $this->create_snapshot();
			if ( ! $this->legacy_footprint_detected ) {
				return true;
			}
			if ( strlen( $this->store->serialize_value( $snapshot ) ) > self::MAX_SNAPSHOT_BYTES ) {
				throw new MigrationMaintenanceException( 'legacy_snapshot_bounds_exceeded' );
			}
			if ( ! $this->fence_is_current() ) {
				return false;
			}
			$added = $this->store->add_immutable( self::SNAPSHOT_OPTION, $snapshot, false );
			if ( ! $added['success'] ) {
				return false;
			}
			$snapshot_row = $this->read_internal_option( self::SNAPSHOT_OPTION );
		}

		$snapshot = $snapshot_row['value'];
		if ( ! is_array( $snapshot ) || ! $this->snapshot_is_valid( $snapshot ) ) {
			return false;
		}
		if (
			! $this->store->autoload_is( $snapshot_row['autoload'], false )
			&& ! $this->store->compare_and_swap( self::SNAPSHOT_OPTION, $snapshot_row['raw_value'], $snapshot, false )
		) {
			return false;
		}

		$options = isset( $snapshot['options'] ) && is_array( $snapshot['options'] )
			? $snapshot['options']
			: array();
		$this->load_source_values( $options );
		if ( ! $this->legacy_footprint_detected ) {
			return true;
		}
		$mapped = $this->build_mapped_settings( $options );

		$this->runtime_warnings = array_values(
			array_unique( array_merge( $this->runtime_warnings, $this->warnings ) )
		);

		$recommendations = isset( $mapped['recommendations'] ) && is_array( $mapped['recommendations'] )
			? array_intersect_key(
				$mapped['recommendations'],
				array(
					'button_type' => true,
					'button_text' => true,
				)
			)
			: array();

		$secondary_action = isset( $mapped['secondary_action'] ) && is_array( $mapped['secondary_action'] )
			? array_intersect_key(
				$mapped['secondary_action'],
				array(
					'mode'        => true,
					'custom_url'  => true,
					'custom_text' => true,
				)
			)
			: array();
		if ( array() === $recommendations && array() === $secondary_action ) {
			return false;
		}

		$prepared = $this->prepare_existing_v2_settings( true );
		if ( ! $prepared['success'] ) {
			return false;
		}
		$settings = $this->merge_existing_over_mapped(
			array_filter(
				array(
					'recommendations'  => $recommendations,
					'secondary_action' => $secondary_action,
				),
				static fn( array $group ): bool => array() !== $group
			),
			$prepared['settings']
		);
		// Retain the existing key order as well as its values on an idempotent retry.
		$settings = array_replace_recursive( $prepared['settings'], $settings );

		return $this->persist_settings_atomically( $prepared, $settings );
	}

	/**
	 * Commit the exact, site-bound proof that this installation was born on V2.
	 *
	 * @param LegacyPaidEntitlementPlan|null $paid_entitlement_plan One-use exact-row entitlement capability.
	 */
	private function commit_not_applicable_provenance( ?LegacyPaidEntitlementPlan $paid_entitlement_plan ): MigrationOutcome {
		if ( ! $this->fence_is_current() ) {
			return MigrationOutcome::BUSY;
		}
		return $this->terminal_compatibility_outcome( MigrationOutcome::NOT_APPLICABLE, null, $paid_entitlement_plan );
	}

	/**
	 * Ask the optional premium bridge for one exact request-local capability.
	 *
	 * @param array{diagnostics: string[], raw_rows: array<string, string>} $paid_state Exact inspector result.
	 */
	private function prepare_paid_entitlement_plan( array $paid_state ): ?LegacyPaidEntitlementPlan {
		if (
			null === $this->paid_entitlement_bridge
			|| ! in_array( 'legacy_paid_entitlement_requires_adapter', $paid_state['diagnostics'], true )
			|| ! isset( $paid_state['raw_rows']['fs_accounts'] )
		) {
			return null;
		}

		$bridge = $this->paid_entitlement_bridge;
		$raw    = $paid_state['raw_rows']['fs_accounts'];
		try {
			return $this->site_context->guard(
				fn(): ?LegacyPaidEntitlementPlan => $bridge->prepare( $this->site_context->blog_id(), $raw )
			);
		} catch ( \Throwable ) {
			return null;
		}
	}

	/**
	 * Restore the exact V1 Pro diagnostic after the SDK updates its version row.
	 *
	 * The capture retains only the historical basename. A paid capability still
	 * requires the bridge to authorize the current blog and bind itself to the
	 * current exact fs_accounts bytes. Missing bytes remain fail-closed.
	 *
	 * @param array{diagnostics: string[], raw_rows: array<string, string>} $paid_state Exact inspector result.
	 * @return array{diagnostics: string[], raw_rows: array<string, string>}
	 */
	private function apply_pre_sdk_historical_edition( array $paid_state ): array {
		$basename = $this->historical_pre_sdk_basename();
		if ( 'cartpops-pro/cartpops.php' !== $basename ) {
			return $paid_state;
		}

		$raw                         = $paid_state['raw_rows']['fs_accounts'] ?? null;
		$paid_state['diagnostics'][] = is_string( $raw ) && '' !== $raw
			? 'legacy_paid_entitlement_requires_adapter'
			: 'legacy_paid_state_uncertain';
		$paid_state['diagnostics']   = array_values( array_unique( $paid_state['diagnostics'] ) );
		sort( $paid_state['diagnostics'], SORT_STRING );

		return $paid_state;
	}

	/**
	 * Remove only the entitlement adapter blocker from value-free diagnostics.
	 *
	 * @param string[] $diagnostics Value-free compatibility diagnostics.
	 * @return string[]
	 */
	private function without_paid_entitlement_adapter( array $diagnostics ): array {
		return array_values(
			array_filter(
				$diagnostics,
				static fn( string $diagnostic ): bool => 'legacy_paid_entitlement_requires_adapter' !== $diagnostic
			)
		);
	}

	/**
	 * Remove only a prior exact-row drift warning after a fresh plan succeeds.
	 *
	 * @param string[] $diagnostics Value-free compatibility diagnostics.
	 * @return string[]
	 */
	private function without_paid_entitlement_drift( array $diagnostics ): array {
		return array_values(
			array_filter(
				$diagnostics,
				static fn( string $diagnostic ): bool => self::ENTITLEMENT_DRIFT_DIAGNOSTIC !== $diagnostic
			)
		);
	}

	/**
	 * Remove only the deprecated generic paid-feature diagnostic.
	 *
	 * @param string[] $diagnostics Value-free compatibility diagnostics.
	 * @return string[]
	 */
	private function without_generic_paid_feature_diagnostic( array $diagnostics ): array {
		return array_values(
			array_filter(
				$diagnostics,
				static fn( string $diagnostic ): bool => 'legacy_paid_features_require_conversion' !== $diagnostic
			)
		);
	}

	/**
	 * Remove the superseded custom-recommendation blocker after the closed V1
	 * value grammar has been recognized and can be mapped to the V2 document.
	 *
	 * @param string[] $diagnostics Value-free compatibility diagnostics.
	 * @return string[]
	 */
	private function without_custom_recommendation_diagnostic( array $diagnostics ): array {
		return array_values(
			array_filter(
				$diagnostics,
				static fn( string $diagnostic ): bool => 'legacy_custom_recommendations_require_conversion' !== $diagnostic
			)
		);
	}

	/**
	 * Remove the old generic retained-behavior fence only after the current
	 * locked inspection proves that no such behavior remains.
	 *
	 * @param string[] $diagnostics Value-free compatibility diagnostics.
	 * @return string[]
	 */
	private function without_retained_behavior_diagnostic( array $diagnostics ): array {
		return array_values(
			array_filter(
				$diagnostics,
				static fn( string $diagnostic ): bool => 'legacy_retained_behavior_requires_review' !== $diagnostic
			)
		);
	}

	/**
	 * Remove only the superseded V1 rule-conversion fences.
	 *
	 * @param string[] $diagnostics Value-free compatibility diagnostics.
	 * @return string[]
	 */
	private function without_rule_conversion_diagnostics( array $diagnostics ): array {
		return array_values(
			array_filter(
				$diagnostics,
				static fn( string $diagnostic ): bool => ! in_array(
					$diagnostic,
					array( 'legacy_rules_require_conversion', 'legacy_paid_rules_require_conversion' ),
					true
				)
			)
		);
	}

	/**
	 * Make the final paid-compatibility decision and terminal write atomically.
	 *
	 * @param MigrationOutcome|null          $target                COMPLETE, NOT_APPLICABLE, or null when reconciling paid evidence.
	 * @param array<string, mixed>|null      $snapshot              Immutable source snapshot for a COMPLETE journal.
	 * @param LegacyPaidEntitlementPlan|null $paid_entitlement_plan One-use exact-row entitlement capability.
	 */
	private function terminal_compatibility_outcome( ?MigrationOutcome $target, ?array $snapshot, ?LegacyPaidEntitlementPlan $paid_entitlement_plan ): MigrationOutcome {
		if ( ! in_array( $target, array( null, MigrationOutcome::COMPLETE, MigrationOutcome::NOT_APPLICABLE ), true ) ) {
			return MigrationOutcome::FAILED;
		}
		if ( ! $this->fence_is_current() ) {
			return MigrationOutcome::BUSY;
		}

		$this->checkpoint( 'before_terminal_commit' );
		$fence            = new LegacyCompatibilityTerminalFence( $this->site_context );
		$resume_migration = false;
		$result           = $fence->run(
			$this->compatibility_terminal_option_names(),
			function ( bool $has_rules, array $locked, bool $compatibility_overbound ) use ( $target, $snapshot, $paid_entitlement_plan, &$resume_migration ): MigrationOutcome {
				$controls        = $this->locked_control_rows( $locked );
				$control_outcome = $this->classify_site_control_state( $controls );
				if ( null !== $control_outcome ) {
					return $control_outcome;
				}
				$lock = $locked[ self::LOCK_OPTION ] ?? null;
				if ( ! is_array( $lock ) || ! hash_equals( $this->held_lock_raw, $lock['raw_value'] ) ) {
					return MigrationOutcome::BUSY;
				}
				$historical_completion = $this->historical_completion_outcome( $controls, $locked );
				if ( null !== $historical_completion ) {
					return $historical_completion;
				}
				if ( $this->pre_sdk_marker_requires_direct_proof( $controls[ self::PRE_SDK_EVIDENCE_OPTION ] ) ) {
					$this->runtime_warnings[] = 'pre_sdk_legacy_identity_network_unavailable';
					return MigrationOutcome::FAILED;
				}
				$stored                       = $controls[ self::COMPATIBILITY_OPTION ];
				$stored_rules_reconciled      = $stored['exists']
					&& is_array( $stored['value']['diagnostics'] ?? null )
					&& (
						in_array( 'legacy_rules_require_conversion', $stored['value']['diagnostics'], true )
						|| in_array( 'legacy_paid_rules_require_conversion', $stored['value']['diagnostics'], true )
					);
				$retirement_row               = $controls[ self::RULES_RETIREMENT_OPTION ];
				$retirement_record_invalid    = $retirement_row['exists'] && ! $this->rules_retirement_record_is_valid( $retirement_row['value'] );
				$retirement_record_reconciled = $retirement_record_invalid && ( $has_rules || $stored_rules_reconciled );
				if ( $retirement_record_invalid && ! $retirement_record_reconciled ) {
					return MigrationOutcome::FAILED;
				}
				foreach ( $controls as $option => $row ) {
					if (
						self::RULES_RETIREMENT_OPTION !== $option
						&& $row['exists']
						&& ! $this->store->autoload_is( $row['autoload'], false )
						&& ! $this->persist_locked_option( $option, $row, $row['value'], false )
					) {
						return MigrationOutcome::FAILED;
					}
				}

				$paid_rows = array();
				foreach ( LegacyPaidStateInspector::option_names() as $option ) {
					if ( isset( $locked[ $option ] ) ) {
						$paid_rows[ $option ] = $locked[ $option ]['raw_value'];
					}
				}
				$paid_settings_recognized_for_reconciliation = false;
				try {
					$locked_paid_state                           = $this->apply_pre_sdk_historical_edition(
						array(
							'diagnostics' => ( new LegacyPaidStateInspector( $this->site_context ) )->inspect_raw_rows( $paid_rows ),
							'raw_rows'    => $paid_rows,
						)
					);
					$diagnostics                                 = $locked_paid_state['diagnostics'];
					$paid_settings_recognized_for_reconciliation = ! in_array( 'legacy_paid_state_uncertain', $diagnostics, true );
				} catch ( MigrationMaintenanceException | MigrationReadException ) {
					$diagnostics = array( 'legacy_paid_state_uncertain' );
				}
				if ( $compatibility_overbound ) {
					$diagnostics[]                               = 'legacy_paid_state_uncertain';
					$paid_settings_recognized_for_reconciliation = false;
				}
				$retained_behavior_still_active = in_array( 'legacy_retained_behavior_requires_review', $diagnostics, true )
					|| in_array( 'legacy_custom_js_requires_review', $diagnostics, true );
				$js_fingerprint                 = $this->custom_js_review_fingerprint( $locked, $controls );
				$js_reviewed                    = null !== $js_fingerprint && $controls[ self::JS_REVIEW_OPTION ]['exists']
					&& $this->custom_js_review_record( $js_fingerprint ) === $controls[ self::JS_REVIEW_OPTION ]['value'];
				if ( $js_reviewed ) {
					$diagnostics = array_values( array_diff( $diagnostics, array( 'legacy_custom_js_requires_review' ) ) );
				} elseif ( $controls[ self::JS_REVIEW_OPTION ]['exists'] ) {
					// A stale acknowledgment never becomes authority, even after completion.
					$diagnostics[] = 'legacy_custom_js_requires_review';
				}
				$stored_paid_features_reconciled          = false;
				$stored_custom_recommendations_reconciled = false;
				$stored_retained_behavior_reconciled      = false;
				if ( $stored['exists'] && is_array( $stored['value']['diagnostics'] ?? null ) ) {
					$existing = $stored['value']['diagnostics'];
					if ( $js_reviewed ) {
						$existing = array_values( array_diff( $existing, array( 'legacy_custom_js_requires_review' ) ) );
					}
					if ( $stored_rules_reconciled ) {
						$existing = $this->without_rule_conversion_diagnostics( $existing );
					}
					if ( $paid_settings_recognized_for_reconciliation && in_array( 'legacy_paid_features_require_conversion', $existing, true ) ) {
						$existing                        = $this->without_generic_paid_feature_diagnostic( $existing );
						$stored_paid_features_reconciled = true;
					}
					if ( $paid_settings_recognized_for_reconciliation && in_array( 'legacy_custom_recommendations_require_conversion', $existing, true ) ) {
						$existing                                 = $this->without_custom_recommendation_diagnostic( $existing );
						$stored_custom_recommendations_reconciled = true;
					}
					if (
						$paid_settings_recognized_for_reconciliation
						&& in_array( 'legacy_retained_behavior_requires_review', $existing, true )
						&& ! $retained_behavior_still_active
					) {
						$existing                            = $this->without_retained_behavior_diagnostic( $existing );
						$stored_retained_behavior_reconciled = true;
					}
					$diagnostics = array_merge( $existing, $diagnostics );
				}
				$retirement_autoload_needs_repair = $retirement_row['exists']
					&& ! $this->store->autoload_is( $retirement_row['autoload'], false );
				if (
					( $has_rules || $stored_rules_reconciled || $retirement_autoload_needs_repair )
					&& ! $this->ensure_rules_retirement_locked( $retirement_row )
				) {
					return MigrationOutcome::FAILED;
				}
				$stored_compatibility_reconciled = $js_reviewed || $stored_paid_features_reconciled
					|| $stored_custom_recommendations_reconciled
					|| $stored_retained_behavior_reconciled
					|| $stored_rules_reconciled;
				$entitlement_admitted            = false;
				if ( null !== $paid_entitlement_plan ) {
					$locked_accounts = $paid_rows['fs_accounts'] ?? '';
					if ( null !== $this->paid_entitlement_bridge ) {
						$entitlement_admitted = $this->paid_entitlement_bridge->consume(
							$paid_entitlement_plan,
							$this->site_context->blog_id(),
							$locked_accounts
						);
					}
					if ( ! $entitlement_admitted ) {
						$diagnostics[] = self::ENTITLEMENT_DRIFT_DIAGNOSTIC;
					}
				}
				if ( $entitlement_admitted ) {
					$diagnostics = $this->without_paid_entitlement_adapter( $diagnostics );
					$diagnostics = $this->without_paid_entitlement_drift( $diagnostics );
				}
				$diagnostics = array_values( array_unique( $diagnostics ) );
				sort( $diagnostics, SORT_STRING );

				// Preserve dormant presentation only after the locked rule and
				// entitlement checks succeed. A failed decision rolls these writes
				// back with its controls instead of leaving a partial settings edit.
				if ( null === $target && ! $this->persist_dormant_paid_presentation_mapping() ) {
					return $this->fail_without_journal( 'paid_presentation_preservation_failed' );
				}

				if ( array() !== $diagnostics ) {
					if ( ! $this->invalidate_terminal_controls_locked( $controls, $diagnostics ) ) {
						return MigrationOutcome::FAILED;
					}
					$record = $this->compatibility_disposition_record( $diagnostics );
					if ( ! $this->persist_locked_option( self::COMPATIBILITY_OPTION, $controls[ self::COMPATIBILITY_OPTION ], $record, false ) ) {
						return MigrationOutcome::FAILED;
					}
					$this->runtime_warnings = array_values( array_unique( array_merge( $this->runtime_warnings, $diagnostics ) ) );
					return MigrationOutcome::MAINTENANCE_REQUIRED;
				}
				if ( $js_reviewed && ! $this->custom_js_review_rows_unchanged( $locked ) ) {
					return MigrationOutcome::FAILED;
				}

				if ( null === $target ) {
					if ( $controls[ self::COMPLETION_OPTION ]['exists'] && $controls[ self::PROVENANCE_OPTION ]['exists'] ) {
						return MigrationOutcome::FAILED;
					}
					if ( $controls[ self::COMPLETION_OPTION ]['exists'] ) {
						if ( $stored['exists'] && ! $this->store->delete_if_raw( self::COMPATIBILITY_OPTION, $stored['raw_value'] ) ) {
							return MigrationOutcome::FAILED;
						}
						return ! $js_reviewed || $this->custom_js_review_rows_unchanged( $locked ) ? MigrationOutcome::COMPLETE : MigrationOutcome::FAILED;
					}
					if ( $controls[ self::PROVENANCE_OPTION ]['exists'] ) {
						if ( $stored['exists'] && ! $this->store->delete_if_raw( self::COMPATIBILITY_OPTION, $stored['raw_value'] ) ) {
							return MigrationOutcome::FAILED;
						}
						return MigrationOutcome::NOT_APPLICABLE;
					}
					if (
						! $retirement_record_reconciled
						&& ( ! $stored['exists'] || ( ! $entitlement_admitted && ! $stored_compatibility_reconciled ) )
					) {
						return MigrationOutcome::FAILED;
					}
					$resume_migration = true;
					return MigrationOutcome::COMPLETE;
				}
				if ( $stored['exists'] ) {
					if (
						( ! $entitlement_admitted && ! $stored_compatibility_reconciled )
						|| ! $this->store->delete_if_raw( self::COMPATIBILITY_OPTION, $stored['raw_value'] )
					) {
						return MigrationOutcome::FAILED;
					}
				}
				if ( $controls[ self::PROVENANCE_OPTION ]['exists'] || $controls[ self::COMPLETION_OPTION ]['exists'] ) {
					return MigrationOutcome::FAILED;
				}
				if ( MigrationOutcome::NOT_APPLICABLE === $target && $has_rules ) {
					$resume_migration = true;
					return MigrationOutcome::COMPLETE;
				}
				if ( MigrationOutcome::COMPLETE === $target ) {
					if ( null === $snapshot ) {
						return MigrationOutcome::FAILED;
					}
					$journal = $this->build_journal_record( 'complete', $snapshot );
					if ( null === $journal || ! $this->persist_locked_option( self::JOURNAL_OPTION, $controls[ self::JOURNAL_OPTION ], $journal, false ) ) {
						return MigrationOutcome::FAILED;
					}
					if ( ! $this->persist_locked_option( self::COMPLETION_OPTION, $controls[ self::COMPLETION_OPTION ], self::SCHEMA_VERSION, false ) ) {
						return MigrationOutcome::FAILED;
					}
					$preserved = $controls[ self::PRE_SDK_EVIDENCE_OPTION ];
					if ( $preserved['exists'] && ! $this->store->delete_if_raw( self::PRE_SDK_EVIDENCE_OPTION, $preserved['raw_value'] ) ) {
						return MigrationOutcome::FAILED;
					}
					return ! $js_reviewed || $this->custom_js_review_rows_unchanged( $locked ) ? MigrationOutcome::COMPLETE : MigrationOutcome::FAILED;
				}
				if ( MigrationOutcome::NOT_APPLICABLE === $target && $controls[ self::PRE_SDK_EVIDENCE_OPTION ]['exists'] ) {
					$basename = $this->pre_sdk_evidence_basename( $controls[ self::PRE_SDK_EVIDENCE_OPTION ] );
					if ( null === $basename ) {
						return MigrationOutcome::FAILED;
					}
					$this->durable_pre_sdk_basename = $basename;
					$resume_migration               = true;
					return MigrationOutcome::COMPLETE;
				}

				$provenance = array(
					'schema_version' => self::SCHEMA_VERSION,
					'kind'           => 'v2_born',
					'site_id'        => $this->site_context->blog_id(),
				);
				return $this->persist_locked_option( self::PROVENANCE_OPTION, $controls[ self::PROVENANCE_OPTION ], $provenance, false )
					? MigrationOutcome::NOT_APPLICABLE
					: MigrationOutcome::FAILED;
			},
			$this->store
		);

		if ( ! $resume_migration || MigrationOutcome::COMPLETE !== $result ) {
			return $result;
		}

		$fresh_state       = ( new LegacyPaidStateInspector( $this->site_context ) )->read();
		$fresh_state       = $this->apply_pre_sdk_historical_edition( $fresh_state );
		$fresh_plan        = $this->prepare_paid_entitlement_plan( $fresh_state );
		$fresh_diagnostics = null === $fresh_plan
			? $fresh_state['diagnostics']
			: $this->without_paid_entitlement_adapter( $fresh_state['diagnostics'] );
		$hard_diagnostics  = array_filter( $fresh_diagnostics, array( LegacyPaidStateInspector::class, 'is_hard_diagnostic' ) );
		return array() === $hard_diagnostics
			? $this->migrate_while_locked( $fresh_plan )
			: MigrationOutcome::MAINTENANCE_REQUIRED;
	}

	/**
	 * Return every option participating in the terminal compatibility fence.
	 *
	 * @return string[]
	 */
	private function compatibility_terminal_option_names(): array {
		return array_values( array_unique( array_merge( self::BOOT_CONTROL_OPTIONS, LegacyPaidStateInspector::option_names() ) ) );
	}

	/**
	 * Convert transaction-locked raw rows into the normal control representation.
	 *
	 * @param array<string, array{raw_value: string, autoload: string}> $locked Locked raw rows.
	 * @return array<string, array{exists: bool, value: mixed, raw_value: string, autoload: string}>
	 * @throws MigrationReadException When a locked control cannot be decoded safely.
	 */
	private function locked_control_rows( array $locked ): array {
		$rows = array_fill_keys( self::BOOT_CONTROL_OPTIONS, $this->missing_internal_option() );
		foreach ( self::BOOT_CONTROL_OPTIONS as $option ) {
			if ( ! isset( $locked[ $option ] ) ) {
				continue;
			}
			$decoded = ( new SafeSerializedReader() )->decode( $locked[ $option ]['raw_value'], self::MAX_INTERNAL_BYTES, self::MAX_VALUE_DEPTH, self::MAX_VALUE_NODES );
			if ( ! $decoded['safe'] ) {
				throw new MigrationReadException( 'unsafe_internal_option' );
			}
			$rows[ $option ] = array(
				'exists'    => true,
				'value'     => $decoded['value'],
				'raw_value' => $locked[ $option ]['raw_value'],
				'autoload'  => $locked[ $option ]['autoload'],
			);
		}
		return $rows;
	}

	/**
	 * Build a closed, value-free compatibility disposition.
	 *
	 * @param string[] $diagnostics Value-free compatibility codes.
	 * @return array<string, mixed>
	 */
	private function compatibility_disposition_record( array $diagnostics ): array {
		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'resource'       => 'legacy_compatibility_state',
			'status'         => 'retained',
			'diagnostics'    => $diagnostics,
		);
	}

	/**
	 * Build the exact durable declaration that V1 rule behavior is retired.
	 *
	 * @return array{schema_version: int, resource: string, status: string, warning: string}
	 */
	private function rules_retirement_record(): array {
		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'resource'       => 'cartpops_rules',
			'status'         => 'retired',
			'warning'        => self::RULES_RETIREMENT_WARNING,
		);
	}

	/**
	 * Persist or canonicalize the retirement disposition inside the rule fence.
	 *
	 * @param array{exists: bool, value: mixed, raw_value: string, autoload: string} $row Locked retirement row or gap.
	 */
	private function ensure_rules_retirement_locked( array $row ): bool {
		$record = $this->rules_retirement_record();
		if ( $row['exists'] && $record === $row['value'] && $this->store->autoload_is( $row['autoload'], false ) ) {
			$this->legacy_rules_retirement_detected = true;
			return true;
		}

		$persisted = $this->persist_locked_option( self::RULES_RETIREMENT_OPTION, $row, $record, false );
		if ( $persisted ) {
			$this->legacy_rules_retirement_detected = true;
		}
		return $persisted;
	}

	/**
	 * Delete terminal controls and demote a terminal journal before maintenance.
	 *
	 * @param array<string, array{exists: bool, value: mixed, raw_value: string, autoload: string}> $controls Locked controls.
	 * @param string[]                                                                              $diagnostics Value-free diagnostics.
	 */
	private function invalidate_terminal_controls_locked( array $controls, array $diagnostics ): bool {
		foreach ( array( self::COMPLETION_OPTION, self::PROVENANCE_OPTION ) as $option ) {
			if ( $controls[ $option ]['exists'] && ! $this->store->delete_if_raw( $option, $controls[ $option ]['raw_value'] ) ) {
				return false;
			}
		}

		$journal = $controls[ self::JOURNAL_OPTION ];
		if ( $journal['exists'] && is_array( $journal['value'] ) && 'complete' === ( $journal['value']['phase'] ?? null ) ) {
			$replacement             = $journal['value'];
			$replacement['phase']    = 'failed';
			$replacement['warnings'] = array_values( array_unique( array_merge( $replacement['warnings'], $diagnostics ) ) );
			sort( $replacement['warnings'], SORT_STRING );
			if ( ! $this->journal_is_valid( $replacement ) || ! $this->persist_locked_option( self::JOURNAL_OPTION, $journal, $replacement, false ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Return the terminal outcome for one current completion before diagnostics.
	 *
	 * A validated current completion is the final migration authority. Captured
	 * historical paid identity may never reopen it or make its continued validity
	 * depend on a later SDK/entitlement read.
	 *
	 * @param array{exists: bool, value: mixed, raw_value: string, autoload: string} $row Exact completion row.
	 */
	private function current_completion_outcome( array $row ): ?MigrationOutcome {
		if ( ! $row['exists'] ) {
			return null;
		}
		$version = CanonicalInteger::parse( $row['value'] );
		if ( null === $version ) {
			return $this->fail_without_journal( 'invalid_completion_marker' );
		}
		if ( $version > self::SCHEMA_VERSION ) {
			return MigrationOutcome::FUTURE_VERSION;
		}
		if ( self::SCHEMA_VERSION !== $version ) {
			return null;
		}
		if (
			! $this->store->autoload_is( $row['autoload'], false )
			&& ! $this->store->compare_and_swap( self::COMPLETION_OPTION, $row['raw_value'], self::SCHEMA_VERSION, false )
		) {
			return $this->fail_without_journal( 'completion_marker_autoload_repair_failed' );
		}

		return MigrationOutcome::COMPLETE;
	}

	/**
	 * Whether an already validated completion row is current for this schema.
	 *
	 * @param array{exists: bool, value: mixed, raw_value: string, autoload: string} $row Exact completion row.
	 */
	private function completion_is_current( array $row ): bool {
		return $row['exists'] && self::SCHEMA_VERSION === CanonicalInteger::parse( $row['value'] );
	}

	/**
	 * Honor terminal completion before historical paid diagnostics and remove a stale retry marker.
	 *
	 * The normal completion path still performs compatibility reconciliation. This
	 * shortcut applies only when exact request-local or durable pre-SDK identity is
	 * present. A conflicting pair fails closed without changing the completion or
	 * customer settings. An exact marker left by an interrupted late writer is
	 * compare-and-deleted after completion is validated, making cleanup retry-safe.
	 *
	 * @param array<string, array{exists: bool, value: mixed, raw_value: string, autoload: string}> $rows Exact control rows.
	 * @param array<string, array{raw_value: string, autoload: string}>|null                        $locked Already transaction-locked rows, if available.
	 */
	private function historical_completion_outcome( array $rows, ?array $locked = null ): ?MigrationOutcome {
		$completion = $rows[ self::COMPLETION_OPTION ];
		if ( ! $this->completion_is_current( $completion ) ) {
			return null;
		}

		$captured  = $this->captured_pre_sdk_basename();
		$preserved = $this->pre_sdk_evidence_basename( $rows[ self::PRE_SDK_EVIDENCE_OPTION ] );
		$authority = $this->classify_pre_sdk_evidence_row( $rows[ self::PRE_SDK_EVIDENCE_OPTION ] );
		$unscoped  = $authority['legacy_exact'];
		if ( null !== $captured && null !== $preserved && $captured !== $preserved ) {
			$this->runtime_warnings[] = 'pre_sdk_legacy_identity_conflict';
			return MigrationOutcome::FAILED;
		}
		if ( null === $captured && null === $preserved && ! $unscoped ) {
			return null;
		}

		// Retain earned completion's independence from later paid authority, but
		// hold exact customization evidence through its existing completion writes.
		if ( $rows[ self::JS_REVIEW_OPTION ]['exists'] || $rows[ self::QUARANTINE_OPTION ]['exists'] ) {
			if ( null === $locked ) {
				return ( new LegacyCompatibilityTerminalFence( $this->site_context ) )->run(
					$this->compatibility_terminal_option_names(),
					function ( bool $has_rules, array $fresh, bool $overbound ): MigrationOutcome {
						$controls = $this->locked_control_rows( $fresh );
						return $overbound ? MigrationOutcome::FAILED : ( $this->classify_site_control_state( $controls ) ?? $this->historical_completion_outcome( $controls, $fresh ) ?? MigrationOutcome::FAILED );
					},
					$this->store
				);
			}
			$quarantined     = $rows[ self::QUARANTINE_OPTION ]['value']['raw_value'] ?? '';
			$requires_review = $rows[ self::JS_REVIEW_OPTION ]['exists'] || in_array(
				'legacy_custom_js_requires_review',
				( new LegacyPaidStateInspector( $this->site_context ) )->inspect_raw_rows( array( 'cartpops_custom_js' => $quarantined ) ),
				true
			);
			if ( $requires_review ) {
				$fingerprint = $this->custom_js_review_fingerprint( $locked, $rows );
				if ( null === $fingerprint || ! $rows[ self::JS_REVIEW_OPTION ]['exists'] || $this->custom_js_review_record( $fingerprint ) !== $rows[ self::JS_REVIEW_OPTION ]['value'] ) {
					return MigrationOutcome::MAINTENANCE_REQUIRED;
				}
			}
		}

		$outcome = $this->current_completion_outcome( $completion );
		if ( MigrationOutcome::COMPLETE !== $outcome ) {
			return $outcome ?? MigrationOutcome::FAILED;
		}
		if (
			$rows[ self::PRE_SDK_EVIDENCE_OPTION ]['exists']
			&& ! $this->store->delete_if_raw( self::PRE_SDK_EVIDENCE_OPTION, $rows[ self::PRE_SDK_EVIDENCE_OPTION ]['raw_value'] )
		) {
			return MigrationOutcome::FAILED;
		}
		if ( null !== $locked && $rows[ self::JS_REVIEW_OPTION ]['exists'] && ! $this->custom_js_review_rows_unchanged( $locked ) ) {
			return MigrationOutcome::FAILED;
		}

		return MigrationOutcome::COMPLETE;
	}

	/**
	 * Durably preserve exact pre-SDK identity before reopening false provenance.
	 *
	 * The value-free site record is written first. If execution stops before or
	 * after the exact provenance delete, a later request can resume without the
	 * SDK-mutated Freemius version being mistaken for a fresh V2 install. A real
	 * completion marker always wins and is never reopened.
	 *
	 * @param array<string, array{exists: bool, value: mixed, raw_value: string, autoload: string}> $rows Exact preflight controls.
	 */
	private function preserve_pre_sdk_identity_and_reopen_false_v2_born( array $rows ): ?MigrationOutcome {
		$captured  = $this->captured_pre_sdk_basename();
		$preserved = $this->pre_sdk_evidence_basename( $rows[ self::PRE_SDK_EVIDENCE_OPTION ] );
		if ( null !== $captured && null !== $preserved && $captured !== $preserved ) {
			$this->runtime_warnings[] = 'pre_sdk_legacy_identity_conflict';
			return MigrationOutcome::FAILED;
		}

		$needs_preservation = null !== $captured && null === $preserved;
		$needs_reopen       = $rows[ self::PROVENANCE_OPTION ]['exists'] && null !== ( $preserved ?? $captured );
		if ( $this->completion_is_current( $rows[ self::COMPLETION_OPTION ] ) || ( ! $needs_preservation && ! $needs_reopen ) ) {
			return null;
		}

		$lock = $this->acquire_lock();
		if ( MigrationOutcome::COMPLETE !== $lock ) {
			return $lock;
		}
		try {
			$current         = $this->read_boot_control_state();
			$control_outcome = $this->classify_site_control_state( $current['rows'] );
			if ( null !== $control_outcome ) {
				return $control_outcome;
			}
			$historical_completion = $this->historical_completion_outcome( $current['rows'] );
			if ( null !== $historical_completion ) {
				return $historical_completion;
			}
			if ( $this->pre_sdk_marker_requires_direct_proof( $current['rows'][ self::PRE_SDK_EVIDENCE_OPTION ] ) ) {
				$this->runtime_warnings[] = 'pre_sdk_legacy_identity_network_unavailable';
				return MigrationOutcome::FAILED;
			}

			$preserved = $this->pre_sdk_evidence_basename( $current['rows'][ self::PRE_SDK_EVIDENCE_OPTION ] );
			if ( null !== $captured && null !== $preserved && $captured !== $preserved ) {
				$this->runtime_warnings[] = 'pre_sdk_legacy_identity_conflict';
				return MigrationOutcome::FAILED;
			}
			$historical_basename = $preserved ?? $captured;
			if ( null === $historical_basename ) {
				$this->runtime_warnings[] = 'pre_sdk_legacy_identity_unavailable';
				return MigrationOutcome::FAILED;
			}

			if ( null === $preserved ) {
				if ( ! $this->persist_pre_sdk_evidence( $current['rows'][ self::PRE_SDK_EVIDENCE_OPTION ], $historical_basename ) ) {
					return MigrationOutcome::FAILED;
				}
				$this->durable_pre_sdk_basename = $historical_basename;
				$this->checkpoint( 'after_pre_sdk_identity_preserved' );
			}

			$provenance = $current['rows'][ self::PROVENANCE_OPTION ];
			if (
				$provenance['exists']
				&& ! $this->store->delete_if_raw( self::PROVENANCE_OPTION, $provenance['raw_value'] )
			) {
				$this->runtime_warnings[] = 'false_v2_born_reopen_failed';
				return MigrationOutcome::FAILED;
			}
			if ( $provenance['exists'] ) {
				$this->checkpoint( 'after_false_v2_born_reopened' );
			}

			return MigrationOutcome::COMPLETE;
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Bind a validated durable identity for this migration request.
	 *
	 * @param array<string, array{exists: bool, value: mixed, raw_value: string, autoload: string}> $rows Exact controls.
	 */
	private function bind_durable_pre_sdk_identity( array $rows ): void {
		$this->durable_pre_sdk_basename = $this->completion_is_current( $rows[ self::COMPLETION_OPTION ] )
			? null
			: $this->pre_sdk_evidence_basename( $rows[ self::PRE_SDK_EVIDENCE_OPTION ] );
	}

	/** Return exact request-local pre-SDK identity for the current site. */
	private function captured_pre_sdk_basename(): ?string {
		if ( null === $this->pre_sdk_legacy_evidence ) {
			return null;
		}

		$basename = $this->pre_sdk_legacy_evidence->historical_basename_for_site( $this->site_context->blog_id() );
		return in_array( $basename, self::LEGACY_NETWORK_BASENAMES, true ) ? $basename : null;
	}

	/** Return durable identity first, then request-local capture. */
	private function historical_pre_sdk_basename(): ?string {
		return $this->durable_pre_sdk_basename ?? $this->captured_pre_sdk_basename();
	}

	/**
	 * Build the closed value-free retry authority for one site.
	 *
	 * @param string $basename Exact historical Free or Pro basename.
	 * @return array{schema_version: int, site_id: int, network_id: int, basename: string}
	 */
	private function pre_sdk_evidence_record( string $basename ): array {
		return array(
			'schema_version' => self::PRE_SDK_MARKER_SCHEMA,
			'site_id'        => $this->site_context->blog_id(),
			'network_id'     => $this->site_context->network_id(),
			'basename'       => $basename,
		);
	}

	/**
	 * Return a validated preserved basename or null for a missing row.
	 *
	 * @param array{exists: bool, value: mixed, raw_value: string, autoload: string} $row Exact option row.
	 */
	private function pre_sdk_evidence_basename( array $row ): ?string {
		$authority = $this->classify_pre_sdk_evidence_row( $row );
		return PreSdkLegacyInstallEvidence::SITE_MARKER_CURRENT === $authority['kind']
			? $authority['basename']
			: null;
	}

	/**
	 * Classify one exact marker row through the pre-SDK proof authority.
	 *
	 * @param array{exists: bool, value: mixed, raw_value: string, autoload: string} $row Exact option row.
	 * @return array{kind: string, basename: string|null, record: array<string, mixed>|null, legacy_exact: bool}
	 */
	private function classify_pre_sdk_evidence_row( array $row ): array {
		$evidence = $this->pre_sdk_legacy_evidence ?? PreSdkLegacyInstallEvidence::unavailable();
		return $evidence->classify_site_marker(
			$row,
			$this->site_context->blog_id(),
			$this->site_context->network_id()
		);
	}

	/**
	 * Whether an unscoped or noncanonical schema-1 row lacks direct site proof.
	 *
	 * @param array{exists: bool, value: mixed, raw_value: string, autoload: string} $row Exact option row.
	 */
	private function pre_sdk_marker_requires_direct_proof( array $row ): bool {
		return PreSdkLegacyInstallEvidence::SITE_MARKER_LEGACY_UNAVAILABLE === $this->classify_pre_sdk_evidence_row( $row )['kind'];
	}

	/**
	 * Establish or promote only one proof-authorized scoped site marker.
	 *
	 * @param array{exists: bool, value: mixed, raw_value: string, autoload: string} $row Locked row or gap.
	 * @param string                                                                 $basename Exact intended historical edition.
	 */
	private function persist_pre_sdk_evidence( array $row, string $basename ): bool {
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$authority = $this->classify_pre_sdk_evidence_row( $row );
			if ( PreSdkLegacyInstallEvidence::SITE_MARKER_MISSING === $authority['kind'] ) {
				$added = $this->store->add_immutable( self::PRE_SDK_EVIDENCE_OPTION, $this->pre_sdk_evidence_record( $basename ), false );
				if ( ! $added['success'] ) {
					$this->runtime_warnings[] = 'pre_sdk_legacy_identity_preservation_failed';
					return false;
				}
				$row = $this->read_internal_option( self::PRE_SDK_EVIDENCE_OPTION );
				continue;
			}
			if ( PreSdkLegacyInstallEvidence::SITE_MARKER_CURRENT === $authority['kind'] ) {
				if ( $basename !== $authority['basename'] ) {
					$this->runtime_warnings[] = 'pre_sdk_legacy_identity_conflict';
					return false;
				}
				if ( ! $this->store->autoload_is( $row['autoload'], false ) ) {
					return $this->store->compare_and_swap( self::PRE_SDK_EVIDENCE_OPTION, $row['raw_value'], $authority['record'], false );
				}
				return true;
			}
			if ( PreSdkLegacyInstallEvidence::SITE_MARKER_PROMOTABLE_LEGACY === $authority['kind'] ) {
				if ( $basename !== $authority['basename'] || ! is_array( $authority['record'] ) ) {
					$this->runtime_warnings[] = 'pre_sdk_legacy_identity_conflict';
					return false;
				}
				if ( $this->store->compare_and_swap( self::PRE_SDK_EVIDENCE_OPTION, $row['raw_value'], $authority['record'], false ) ) {
					return true;
				}
				$row = $this->read_internal_option( self::PRE_SDK_EVIDENCE_OPTION );
				continue;
			}
			if ( PreSdkLegacyInstallEvidence::SITE_MARKER_LEGACY_UNAVAILABLE === $authority['kind'] ) {
				$this->runtime_warnings[] = 'pre_sdk_legacy_identity_network_unavailable';
			} else {
				$this->runtime_warnings[] = 'invalid_durable_migration_record';
			}
			return false;
		}

		$this->runtime_warnings[] = 'pre_sdk_legacy_identity_preservation_failed';
		return false;
	}

	/**
	 * Persist one row whose current value/gap is locked by the terminal fence.
	 *
	 * @param string                                                                 $option   Exact option name.
	 * @param array{exists: bool, value: mixed, raw_value: string, autoload: string} $row      Locked row.
	 * @param mixed                                                                  $value    Intended value.
	 * @param bool                                                                   $autoload Required autoload policy.
	 */
	private function persist_locked_option( string $option, array $row, mixed $value, bool $autoload ): bool {
		if (
			self::PRE_SDK_EVIDENCE_OPTION === $option
			&& PreSdkLegacyInstallEvidence::SITE_MARKER_CURRENT !== $this->classify_pre_sdk_evidence_row( $row )['kind']
		) {
			return false;
		}
		if ( $row['exists'] ) {
			return $this->store->compare_and_swap( $option, $row['raw_value'], $value, $autoload );
		}
		$added = $this->store->insert_if_absent( $option, $value, $autoload );
		return $added['success'] && $added['won'];
	}

	/**
	 * Classify a durable provenance record without scanning legacy data.
	 *
	 * @param array{exists: bool, value: mixed, raw_value: string, autoload: string} $row Exact option row.
	 */
	private function provenance_outcome( array $row ): MigrationOutcome {
		$record = $row['value'];
		$schema = is_array( $record ) ? CanonicalInteger::parse( $record['schema_version'] ?? null, 1 ) : null;
		if ( null !== $schema && $schema > self::SCHEMA_VERSION ) {
			return MigrationOutcome::FUTURE_VERSION;
		}
		if ( ! $this->provenance_record_is_valid( $record ) ) {
			return $this->fail_without_journal( 'invalid_install_provenance' );
		}
		if (
			! $this->store->autoload_is( $row['autoload'], false )
			&& ! $this->store->compare_and_swap( self::PROVENANCE_OPTION, $row['raw_value'], $record, false )
		) {
			return $this->fail_without_journal( 'provenance_autoload_repair_failed' );
		}

		return MigrationOutcome::NOT_APPLICABLE;
	}

	/**
	 * Validate the exact current, site-bound provenance schema.
	 *
	 * @param mixed $record Candidate provenance record.
	 */
	private function provenance_record_is_valid( mixed $record ): bool {
		if ( ! is_array( $record ) ) {
			return false;
		}
		$keys = array_keys( $record );
		sort( $keys, SORT_STRING );

		return array( 'kind', 'schema_version', 'site_id' ) === $keys
			&& self::SCHEMA_VERSION === CanonicalInteger::parse( $record['schema_version'] ?? null, 1 )
			&& 'v2_born' === ( $record['kind'] ?? null )
			&& $this->site_context->blog_id() === CanonicalInteger::parse( $record['site_id'] ?? null, 1 );
	}

	/**
	 * Decode one already-bounded internal raw option.
	 *
	 * @param string $raw Exact option bytes.
	 */
	private function decode_internal_raw( string $raw ): mixed {
		$decoded = ( new SafeSerializedReader() )->decode(
			$raw,
			self::MAX_INTERNAL_BYTES,
			self::MAX_VALUE_DEPTH,
			self::MAX_VALUE_NODES
		);

		return $decoded['safe'] ? $decoded['value'] : null;
	}

	/**
	 * Read and safely decode one internal option.
	 *
	 * @param string $option Internal option name.
	 * @return array{exists: bool, value: mixed, raw_value: string, autoload: string}
	 * @throws MigrationReadException When the exact row cannot be read safely.
	 */
	private function read_internal_option( string $option ): array {
		$row = $this->store->read( $option );
		if ( ! $row['success'] ) {
			throw new MigrationReadException( 'database_read_failed_internal_option' );
		}
		if ( ! $row['exists'] ) {
			return array(
				'exists'    => false,
				'value'     => null,
				'raw_value' => '',
				'autoload'  => '',
			);
		}

		$decoded = ( new SafeSerializedReader() )->decode(
			$row['raw_value'],
			self::MAX_INTERNAL_BYTES,
			self::MAX_VALUE_DEPTH,
			self::MAX_VALUE_NODES
		);
		if ( ! $decoded['safe'] ) {
			throw new MigrationReadException( 'unsafe_internal_option' );
		}

		return array(
			'exists'    => true,
			'value'     => $decoded['value'],
			'raw_value' => $row['raw_value'],
			'autoload'  => $row['autoload'],
		);
	}

	/**
	 * Acquire or safely steal an expired owner-token lock.
	 *
	 * @throws MigrationReadException When exact lock persistence is unavailable.
	 */
	private function acquire_lock(): MigrationOutcome {
		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			$row = $this->store->read( self::LOCK_OPTION );
			if ( ! $row['success'] ) {
				throw new MigrationReadException( 'database_read_failed_migration_lock' );
			}

			$lock = array(
				'schema_version' => self::SCHEMA_VERSION,
				'owner'          => $this->lock_owner,
				'generation'     => hash( 'sha256', $this->lock_owner . ':' . (string) ( $this->clock )() . ':' . (string) $attempt ),
				'expires_at'     => ( $this->clock )() + self::LOCK_TTL,
			);

			if ( ! $row['exists'] ) {
				$added = $this->store->add_immutable( self::LOCK_OPTION, $lock, false );
				if ( $added['success'] && $added['won'] ) {
					$this->held_lock_raw = $this->store->serialize_value( $lock );
					return MigrationOutcome::COMPLETE;
				}
				if ( ! $added['success'] ) {
					throw new MigrationReadException( 'migration_lock_write_failed' );
				}
				$row = $added['row'];
			}

			$decoded = ( new SafeSerializedReader() )->decode(
				$row['raw_value'],
				self::MAX_RAW_BYTES,
				self::MAX_VALUE_DEPTH,
				self::MAX_VALUE_NODES
			);
			$current = $decoded['safe'] && is_array( $decoded['value'] ) ? $decoded['value'] : null;
			$schema  = $this->durable_record_schema( $current );
			if ( $schema > self::SCHEMA_VERSION ) {
				return MigrationOutcome::FUTURE_VERSION;
			}
			if ( self::SCHEMA_VERSION !== $schema || ! $this->lock_record_is_valid( $current ) ) {
				$this->runtime_warnings[] = 'invalid_migration_lock';
				return MigrationOutcome::FAILED;
			}
			$expiry = CanonicalInteger::parse( $current['expires_at'], 0 );
			if ( null === $expiry ) {
				$this->runtime_warnings[] = 'invalid_migration_lock';
				return MigrationOutcome::FAILED;
			}
			if ( $expiry > ( $this->clock )() ) {
				$this->runtime_warnings[] = 'migration_lock_busy';
				return MigrationOutcome::BUSY;
			}

			if ( $this->store->compare_and_swap( self::LOCK_OPTION, $row['raw_value'], $lock, false ) ) {
				$this->held_lock_raw = $this->store->serialize_value( $lock );
				return MigrationOutcome::COMPLETE;
			}
		}

		$this->runtime_warnings[] = 'migration_lock_contended';
		return MigrationOutcome::BUSY;
	}

	/**
	 * Verify that no successor has replaced this request's fence token.
	 *
	 * @throws MigrationReadException When the lock row cannot be read exactly.
	 */
	private function fence_is_current(): bool {
		if ( '' === $this->held_lock_raw ) {
			return false;
		}
		$row = $this->store->read( self::LOCK_OPTION );
		if ( ! $row['success'] ) {
			throw new MigrationReadException( 'database_read_failed_migration_lock' );
		}
		return $row['exists'] && hash_equals( $this->held_lock_raw, $row['raw_value'] );
	}

	/** Conditionally release only this request's lock generation. */
	private function release_lock(): void {
		if ( '' !== $this->held_lock_raw ) {
			$this->store->delete_if_raw( self::LOCK_OPTION, $this->held_lock_raw );
			$this->held_lock_raw = '';
		}
	}

	/**
	 * Persist a bounded, value-free stop marker.
	 *
	 * @param string $reason Value-free maintenance reason.
	 */
	private function persist_maintenance_marker( string $reason ): bool {
		$record = array(
			'schema_version' => self::SCHEMA_VERSION,
			'reason'         => sanitize_key( $reason ),
		);
		$stored = $this->store->add_immutable( self::MAINTENANCE_OPTION, $record, false );
		if ( ! $stored['success'] ) {
			return false;
		}
		$decoded = ( new SafeSerializedReader() )->decode( $stored['row']['raw_value'], self::MAX_RAW_BYTES, 8, 32 );
		return $decoded['safe'] && $record === $decoded['value'];
	}

	/**
	 * Remove only the completion marker so a recovery tool can retry safely.
	 *
	 * Source options, the immutable snapshot, quarantine, recovery data, and
	 * current V2 settings are deliberately retained. Existing valid V2 values
	 * therefore continue to win on the next migration attempt.
	 */
	public function reset_for_retry(): bool {
		$this->runtime_warnings = array();
		try {
			$context = SiteUpgradeContext::capture();
			return $context->run(
				function () use ( $context ): bool {
					$this->bind_site_context( $context );
					return $this->reset_for_retry_bound();
				}
			);
		} catch ( \Throwable ) {
			$this->runtime_warnings[] = 'retry_site_context_drift';
			return false;
		}
	}

	/** Run the operator reset while the exact site fence is active. */
	private function reset_for_retry_bound(): bool {
		try {
			$control         = $this->read_boot_control_state();
			$control_outcome = $this->classify_site_control_state( $control['rows'] );
			if ( MigrationOutcome::FUTURE_VERSION === $control_outcome ) {
				$this->runtime_warnings[] = 'future_migration_record_preserved';
				return false;
			}
			if ( MigrationOutcome::FAILED === $control_outcome ) {
				$this->runtime_warnings[] = 'invalid_migration_record_preserved';
				return false;
			}
			if ( $this->pre_sdk_marker_requires_direct_proof( $control['rows'][ self::PRE_SDK_EVIDENCE_OPTION ] ) ) {
				$this->runtime_warnings[] = 'pre_sdk_legacy_identity_network_unavailable';
				return false;
			}

			$marker  = $control['rows'][ self::COMPLETION_OPTION ];
			$version = $marker['exists'] ? CanonicalInteger::parse( $marker['value'] ) : null;
			if ( $marker['exists'] && null === $version ) {
				$this->runtime_warnings[] = 'invalid_completion_marker_preserved';
				return false;
			}
			if ( null !== $version && $version > self::SCHEMA_VERSION ) {
				$this->runtime_warnings[] = 'future_completion_marker_preserved';
				return false;
			}
			if ( MigrationOutcome::COMPLETE !== $this->acquire_lock() ) {
				return false;
			}
			try {
				$marker  = $this->read_internal_option( self::COMPLETION_OPTION );
				$version = $marker['exists'] ? CanonicalInteger::parse( $marker['value'] ) : null;
				if ( $marker['exists'] && null === $version ) {
					$this->runtime_warnings[] = 'invalid_completion_marker_preserved';
					return false;
				}
				if ( null !== $version && $version > self::SCHEMA_VERSION ) {
					$this->runtime_warnings[] = 'future_completion_marker_preserved';
					return false;
				}

				$provenance = $this->read_internal_option( self::PROVENANCE_OPTION );
				if ( $provenance['exists'] ) {
					$provenance_schema = is_array( $provenance['value'] )
						? CanonicalInteger::parse( $provenance['value']['schema_version'] ?? null, 1 )
						: null;
					if ( null !== $provenance_schema && $provenance_schema > self::SCHEMA_VERSION ) {
						$this->runtime_warnings[] = 'future_install_provenance_preserved';
						return false;
					}
					if ( $marker['exists'] || ! $this->provenance_record_is_valid( $provenance['value'] ) ) {
						$this->runtime_warnings[] = 'invalid_install_provenance_preserved';
						return false;
					}

					$candidate = $this->create_snapshot();
					if (
						! $this->legacy_footprint_detected
						|| strlen( $this->store->serialize_value( $candidate ) ) > self::MAX_SNAPSHOT_BYTES
					) {
						$this->runtime_warnings[] = 'legacy_recovery_evidence_unavailable';
						return false;
					}
					if ( ! $this->store->delete_if_raw( self::PROVENANCE_OPTION, $provenance['raw_value'] ) ) {
						$this->runtime_warnings[] = 'provenance_reset_failed';
						return false;
					}
					return true;
				}

				$maintenance = $this->read_internal_option( self::MAINTENANCE_OPTION );
				if ( $maintenance['exists'] ) {
					$maintenance_schema = $this->durable_record_schema( $maintenance['value'] );
					if ( $maintenance_schema > self::SCHEMA_VERSION || ! $this->maintenance_marker_is_valid( $maintenance['value'] ) ) {
						$this->runtime_warnings[] = 'invalid_maintenance_marker_preserved';
						return false;
					}
				}

				$snapshot       = $this->read_internal_option( self::SNAPSHOT_OPTION );
				$valid_snapshot = $snapshot['exists']
					&& is_array( $snapshot['value'] )
					&& $this->snapshot_is_valid( $snapshot['value'] );
				if ( $marker['exists'] && ! $valid_snapshot ) {
					$this->runtime_warnings[] = 'retry_snapshot_unavailable';
					return false;
				}

				if ( $marker['exists'] && ! $this->store->delete_if_raw( self::COMPLETION_OPTION, $marker['raw_value'] ) ) {
					$this->runtime_warnings[] = 'completion_marker_reset_failed';
					return false;
				}
				if ( $maintenance['exists'] && ! $this->store->delete_if_raw( self::MAINTENANCE_OPTION, $maintenance['raw_value'] ) ) {
					$this->runtime_warnings[] = 'maintenance_marker_reset_failed';
					return false;
				}

				if ( $valid_snapshot ) {
					$this->warnings = array( 'manual_retry_requested' );
					return $this->write_journal( 'reset_pending', $snapshot['value'] );
				}
				if ( $maintenance['exists'] ) {
					return true;
				}
			} finally {
				$this->release_lock();
			}
		} catch ( MigrationReadException ) {
			$this->runtime_warnings[] = 'retry_database_read_failed';
		}

		$this->runtime_warnings[] = 'retry_snapshot_unavailable';
		return false;
	}

	/**
	 * Check every site-local control record before an operator-triggered reset.
	 *
	 * @param array<string, array{exists: bool, value: mixed, raw_value: string, autoload: string}> $rows Exact preflight rows.
	 * @throws MigrationReadException When an exact control-row read is uncertain.
	 */
	private function has_any_future_site_record( array $rows ): bool {
		$marker = $rows[ self::COMPLETION_OPTION ];
		if ( $marker['exists'] ) {
			$version = CanonicalInteger::parse( $marker['value'] );
			if ( null !== $version && $version > self::SCHEMA_VERSION ) {
				return true;
			}
		}

		$normalization = $rows[ self::NORMALIZATION_OPTION ];
		if ( $normalization['exists'] && $this->durable_record_schema( $normalization['value'] ) > self::NORMALIZATION_SCHEMA_VERSION ) {
			return true;
		}

		foreach (
			array(
				self::PROVENANCE_OPTION,
				self::SNAPSHOT_OPTION,
				self::JOURNAL_OPTION,
				self::MAINTENANCE_OPTION,
				self::LOCK_OPTION,
				self::RECOVERY_OPTION,
				self::QUARANTINE_OPTION,
				self::JS_REVIEW_OPTION,
				self::COMPATIBILITY_OPTION,
				self::RULES_RETIREMENT_OPTION,
				self::NORMALIZATION_RECOVERY_OPTION,
			) as $option
		) {
			$row = $rows[ $option ];
			if ( $row['exists'] && $this->durable_record_schema( $row['value'] ) > self::SCHEMA_VERSION ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Classify every site-local control row before any repair or lock write.
	 *
	 * Future schema wins over a malformed current sibling because this version
	 * must not reinterpret or repair any part of a successor's state. Once no
	 * future record exists, every current/older row must satisfy its closed
	 * schema except a current retirement record that can proceed only to the
	 * locked rule-evidence decision. Rejected repair candidates fail without
	 * migration mutation.
	 *
	 * @param array<string, array{exists: bool, value: mixed, raw_value: string, autoload: string}> $rows Exact preflight rows.
	 */
	private function classify_site_control_state( array $rows ): ?MigrationOutcome {
		if ( $this->has_any_future_site_record( $rows ) ) {
			return MigrationOutcome::FUTURE_VERSION;
		}

		foreach ( self::BOOT_CONTROL_OPTIONS as $option ) {
			$row = $rows[ $option ];
			if ( ! $row['exists'] ) {
				continue;
			}
			if ( ! in_array( strtolower( $row['autoload'] ), array( 'yes', 'no', 'on', 'off', 'auto', 'auto-on', 'auto-off' ), true ) ) {
				$this->runtime_warnings[] = 'invalid_durable_migration_record';
				return MigrationOutcome::FAILED;
			}
			if ( self::PRE_SDK_EVIDENCE_OPTION === $option ) {
				$authority = $this->classify_pre_sdk_evidence_row( $row );
				if ( PreSdkLegacyInstallEvidence::SITE_MARKER_FUTURE === $authority['kind'] ) {
					return MigrationOutcome::FUTURE_VERSION;
				}
				if (
					! in_array(
						$authority['kind'],
						array(
							PreSdkLegacyInstallEvidence::SITE_MARKER_CURRENT,
							PreSdkLegacyInstallEvidence::SITE_MARKER_PROMOTABLE_LEGACY,
							PreSdkLegacyInstallEvidence::SITE_MARKER_LEGACY_UNAVAILABLE,
						),
						true
					)
				) {
					$this->runtime_warnings[] = 'invalid_durable_migration_record';
					return MigrationOutcome::FAILED;
				}
				continue;
			}
			if ( ! $this->site_control_value_is_valid( $option, $row['value'] ) ) {
				if ( self::RULES_RETIREMENT_OPTION === $option && self::SCHEMA_VERSION === $this->durable_record_schema( $row['value'] ) ) {
					continue;
				}
				$this->runtime_warnings[] = 'invalid_durable_migration_record';
				return MigrationOutcome::FAILED;
			}
		}

		return null;
	}

	/**
	 * Validate one current/older site control value through its closed schema.
	 *
	 * @param string $option Internal option name.
	 * @param mixed  $value  Safely decoded value.
	 */
	private function site_control_value_is_valid( string $option, mixed $value ): bool {
		return match ( $option ) {
			self::COMPLETION_OPTION             => null !== CanonicalInteger::parse( $value, 0, self::SCHEMA_VERSION ),
			self::PROVENANCE_OPTION             => $this->provenance_record_is_valid( $value ),
			self::PRE_SDK_EVIDENCE_OPTION       => false,
			self::SNAPSHOT_OPTION               => $this->snapshot_is_valid( $value ),
			self::JOURNAL_OPTION                => $this->journal_is_valid( $value ),
			self::MAINTENANCE_OPTION            => $this->maintenance_marker_is_valid( $value ),
			self::LOCK_OPTION                   => $this->lock_record_is_valid( $value ),
			self::RECOVERY_OPTION               => $this->recovery_record_is_valid( $value, true ),
			self::QUARANTINE_OPTION             => $this->quarantine_record_is_valid( $value ),
			self::JS_REVIEW_OPTION              => $this->custom_js_review_record_is_valid( $value ),
			self::COMPATIBILITY_OPTION          => $this->compatibility_disposition_is_valid( $value ) || $this->legacy_paid_disposition_is_valid( $value ),
			self::RULES_RETIREMENT_OPTION       => $this->rules_retirement_record_is_valid( $value ),
			self::NORMALIZATION_OPTION          => $this->normalization_marker_is_valid( $value ),
			self::NORMALIZATION_RECOVERY_OPTION => $this->normalization_recovery_is_valid( $value ),
			default                             => false,
		};
	}

	/**
	 * Read every boot-critical site control row and legacy-rule existence once.
	 *
	 * The sentinel row makes the rule result explicit even when none of the
	 * option rows exists. Closed aliases, bounded result cardinality, duplicate
	 * rejection, and a fresh database error distinguish a real empty state from
	 * an uncertain read without copying any rule or option value to diagnostics.
	 *
	 * @return array{rows: array<string, array{exists: bool, value: mixed, raw_value: string, autoload: string}>, has_legacy_rules: bool}
	 * @throws MigrationReadException When the exact bounded read is uncertain.
	 */
	private function read_boot_control_state(): array {
		$wpdb = $this->site_context->database();

		if ( ! method_exists( $wpdb, 'get_results' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			throw new MigrationReadException( 'database_read_failed_boot_controls' );
		}
		/**
		 * Typed database connection.
		 *
		 * @var \wpdb $wpdb
		 */
		$options_table = $this->site_context->options_table();
		$posts_table   = $this->site_context->posts_table();
		$placeholders  = implode( ', ', array_fill( 0, count( self::BOOT_CONTROL_OPTIONS ), '%s' ) );
		$query         = "SELECT option_name, option_value, autoload, 0 AS legacy_rules FROM {$options_table} WHERE option_name IN ({$placeholders}) "
			. "UNION ALL SELECT %s AS option_name, '' AS option_value, 'no' AS autoload, "
			. "EXISTS(SELECT 1 FROM {$posts_table} WHERE post_type = %s LIMIT 1) AS legacy_rules";
		$arguments     = array_merge( self::BOOT_CONTROL_OPTIONS, array( self::LEGACY_RULE_PROBE_ROW, 'cartpops_rules' ) );

		$results = $this->site_context->guard(
			static function () use ( $wpdb, $query, $arguments ): mixed {
				$wpdb->last_error = '';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One bounded exact boot preflight is fully prepared above, avoids repeated hot-path reads, and must fail closed on ambiguity.
				return $wpdb->get_results( $wpdb->prepare( $query, ...$arguments ), ARRAY_A );
			}
		);
		if (
			'' !== MigrationDatabaseState::last_error( $wpdb )
			|| ! is_array( $results )
			|| count( $results ) > count( self::BOOT_CONTROL_OPTIONS ) + 1
		) {
			throw new MigrationReadException( 'database_read_failed_boot_controls' );
		}

		$rows  = array_fill_keys( self::BOOT_CONTROL_OPTIONS, $this->missing_internal_option() );
		$seen  = array();
		$probe = null;
		foreach ( $results as $result ) {
			if (
				! is_array( $result )
				|| ! $this->has_exact_keys( $result, array( 'option_name', 'option_value', 'autoload', 'legacy_rules' ) )
				|| ! is_string( $result['option_name'] )
			) {
				throw new MigrationReadException( 'invalid_boot_control_rows' );
			}
			$name = $result['option_name'];
			if ( isset( $seen[ $name ] ) ) {
				throw new MigrationReadException( 'ambiguous_boot_control_rows' );
			}
			$seen[ $name ] = true;
			if ( self::LEGACY_RULE_PROBE_ROW === $name ) {
				if ( '' !== $result['option_value'] || 'no' !== $result['autoload'] ) {
					throw new MigrationReadException( 'invalid_boot_control_rows' );
				}
				$probe = CanonicalInteger::parse( $result['legacy_rules'], 0, 1 );
				continue;
			}
			if (
				! array_key_exists( $name, $rows )
				|| ! is_string( $result['option_value'] )
				|| ! is_string( $result['autoload'] )
				|| 0 !== CanonicalInteger::parse( $result['legacy_rules'], 0, 0 )
			) {
				throw new MigrationReadException( 'invalid_boot_control_rows' );
			}
			$raw_value     = (string) $result['option_value'];
			$rows[ $name ] = array(
				'exists'    => true,
				'value'     => $this->decode_internal_raw( $raw_value ),
				'raw_value' => $raw_value,
				'autoload'  => $result['autoload'],
			);
		}

		if ( null === $probe || ! isset( $seen[ self::LEGACY_RULE_PROBE_ROW ] ) ) {
			throw new MigrationReadException( 'invalid_boot_control_rows' );
		}

		return array(
			'rows'             => $rows,
			'has_legacy_rules' => 1 === $probe,
		);
	}

	/**
	 * Return the canonical missing-row representation.
	 *
	 * @return array{exists: false, value: null, raw_value: '', autoload: ''}
	 */
	private function missing_internal_option(): array {
		return array(
			'exists'    => false,
			'value'     => null,
			'raw_value' => '',
			'autoload'  => '',
		);
	}

	/**
	 * Persist and verify an option value.
	 *
	 * WordPress returns false both for a failed write and for an unchanged
	 * value, so the durable readback is authoritative.
	 *
	 * @param string $option   Option name.
	 * @param mixed  $value    Exact intended value.
	 * @param bool   $autoload Autoload flag for new options.
	 */
	private function persist_verified( string $option, mixed $value, bool $autoload ): bool {
		return $this->fence_is_current() && $this->store->persist( $option, $value, $autoload );
	}

	/**
	 * Commit settings only if their exact pre-merge bytes are still current.
	 *
	 * A normal V2 settings save does not participate in the migration lock. An
	 * atomic compare-and-swap therefore prevents migration from overwriting a
	 * customer change made between validation and commit.
	 *
	 * @param array{success: bool, exists: bool, raw_value: string, settings: array<string, mixed>} $prepared Exact pre-merge read.
	 * @param array<string, mixed>                                                                  $settings Merged settings document.
	 */
	private function persist_settings_atomically( array $prepared, array $settings ): bool {
		if ( ! $this->fence_is_current() ) {
			return false;
		}

		if ( $prepared['exists'] ) {
			return $this->store->compare_and_swap( self::SETTINGS_OPTION, $prepared['raw_value'], $settings, true );
		}

		$added = $this->store->add_immutable( self::SETTINGS_OPTION, $settings, true );
		return $added['success'] && $added['won'];
	}

	/**
	 * Record a retryable failure when the journal remains writable.
	 *
	 * @param string               $warning  Value-free failure code.
	 * @param array<string, mixed> $snapshot Snapshot metadata.
	 */
	private function fail( string $warning, array $snapshot ): MigrationOutcome {
		$this->warnings[]         = $warning;
		$this->runtime_warnings[] = $warning;
		$this->write_journal( 'failed', $snapshot );

		return MigrationOutcome::FAILED;
	}

	/**
	 * Return a failure when even its journal phase cannot be persisted.
	 *
	 * @param string $warning Value-free failure code.
	 */
	private function fail_without_journal( string $warning ): MigrationOutcome {
		$this->runtime_warnings[] = $warning;
		return MigrationOutcome::FAILED;
	}

	/**
	 * Invoke the explicitly injected phase observer when present.
	 *
	 * @param string $phase Completed phase name.
	 */
	private function checkpoint( string $phase ): void {
		if ( null !== $this->checkpoint_observer ) {
			( $this->checkpoint_observer )( $phase );
		}
	}

	/**
	 * Return a value-free status suitable for diagnostics and admin notices.
	 *
	 * @return array{state: string, phase: string, schema_version: int, source_count: int, mapped_count: int, retained_count: int, warnings: string[], unmapped_keys: string[], snapshot_checksum: string}
	 */
	public function get_status(): array {
		try {
			$context = SiteUpgradeContext::capture();
			return $context->run( fn(): array => $this->get_status_bound( $context ) );
		} catch ( \Throwable ) {
			$this->runtime_warnings[] = 'status_site_context_drift';
			return array(
				'state'             => 'failed',
				'phase'             => 'failed',
				'schema_version'    => self::SCHEMA_VERSION,
				'source_count'      => 0,
				'mapped_count'      => 0,
				'retained_count'    => 0,
				'warnings'          => array_values( array_unique( $this->runtime_warnings ) ),
				'unmapped_keys'     => array(),
				'snapshot_checksum' => '',
			);
		}
	}

	/**
	 * Read diagnostics while the exact site fence is active.
	 *
	 * @param SiteUpgradeContext $context Exact whole-convergence binding.
	 * @return array{state: string, phase: string, schema_version: int, source_count: int, mapped_count: int, retained_count: int, warnings: string[], unmapped_keys: string[], snapshot_checksum: string}
	 */
	private function get_status_bound( SiteUpgradeContext $context ): array {
		$this->bind_site_context( $context );
		try {
			$journal_row    = $this->read_internal_option( self::JOURNAL_OPTION );
			$marker_row     = $this->read_internal_option( self::COMPLETION_OPTION );
			$maint_row      = $this->read_internal_option( self::MAINTENANCE_OPTION );
			$rules_row      = $this->read_internal_option( self::COMPATIBILITY_OPTION );
			$retirement_row = $this->read_internal_option( self::RULES_RETIREMENT_OPTION );
		} catch ( MigrationReadException ) {
			$journal_row              = array(
				'exists' => false,
				'value'  => null,
			);
			$marker_row               = array(
				'exists' => false,
				'value'  => null,
			);
			$maint_row                = array(
				'exists' => false,
				'value'  => null,
			);
			$rules_row                = array(
				'exists' => false,
				'value'  => null,
			);
			$retirement_row           = array(
				'exists' => false,
				'value'  => null,
			);
			$this->runtime_warnings[] = 'status_database_read_failed';
		}
		$journal_valid       = $journal_row['exists'] && $this->journal_is_valid( $journal_row['value'] );
		$journal             = $journal_valid && is_array( $journal_row['value'] ) ? $journal_row['value'] : array();
		$phase               = isset( $journal['phase'] ) && is_string( $journal['phase'] ) ? $journal['phase'] : 'not_started';
		$marker_version      = $marker_row['exists'] ? CanonicalInteger::parse( $marker_row['value'] ) : null;
		$journal_version     = $journal_row['exists'] ? $this->durable_record_schema( $journal_row['value'] ) : self::SCHEMA_VERSION;
		$maint_version       = $maint_row['exists'] ? $this->durable_record_schema( $maint_row['value'] ) : self::SCHEMA_VERSION;
		$rules_version       = $rules_row['exists'] ? $this->durable_record_schema( $rules_row['value'] ) : self::SCHEMA_VERSION;
		$retirement_version  = $retirement_row['exists'] ? $this->durable_record_schema( $retirement_row['value'] ) : self::SCHEMA_VERSION;
		$paid_state_retained = $rules_row['exists'] && $this->compatibility_disposition_is_valid( $rules_row['value'] );
		$rules_retired       = $retirement_row['exists'] && $this->rules_retirement_record_is_valid( $retirement_row['value'] );
		$paid_diagnostics    = $paid_state_retained && is_array( $rules_row['value']['diagnostics'] ?? null )
			? $rules_row['value']['diagnostics']
			: array();
		$done                = self::SCHEMA_VERSION === $marker_version;
		$future              = ( null !== $marker_version && $marker_version > self::SCHEMA_VERSION )
			|| $journal_version > self::SCHEMA_VERSION
			|| $maint_version > self::SCHEMA_VERSION
			|| $rules_version > self::SCHEMA_VERSION
			|| $retirement_version > self::SCHEMA_VERSION;
		$maintenance_valid   = $maint_row['exists'] && $this->maintenance_marker_is_valid( $maint_row['value'] );
		$maintenance         = $maintenance_valid || $paid_state_retained;
		if (
			( $marker_row['exists'] && null === $marker_version )
			|| ( $journal_row['exists'] && ! $journal_valid && $journal_version <= self::SCHEMA_VERSION )
			|| ( $maint_row['exists'] && ! $maintenance_valid && $maint_version <= self::SCHEMA_VERSION )
			|| ( $rules_row['exists'] && ! $paid_state_retained && $rules_version <= self::SCHEMA_VERSION )
			|| ( $retirement_row['exists'] && ! $rules_retired && $retirement_version <= self::SCHEMA_VERSION )
		) {
			$this->runtime_warnings[] = 'invalid_durable_migration_record';
		}
		$stored_warnings     = isset( $journal['warnings'] ) && is_array( $journal['warnings'] )
			? array_values( array_filter( $journal['warnings'], 'is_string' ) )
			: array();
		$retirement_warnings = $rules_retired ? array( self::RULES_RETIREMENT_WARNING ) : array();
		$warnings            = array_values( array_unique( array_merge( $stored_warnings, $paid_diagnostics, $retirement_warnings, $this->runtime_warnings ) ) );
		$unmapped            = isset( $journal['unmapped_keys'] ) && is_array( $journal['unmapped_keys'] )
			? array_values( array_filter( $journal['unmapped_keys'], 'is_string' ) )
			: array();
		$retained_count      = $journal_valid ? ( CanonicalInteger::parse( $journal['retained_count'], 0, self::MAX_SOURCE_RECORDS ) ?? 0 ) : 0;
		if ( ! $done && array() !== $this->runtime_warnings ) {
			$phase = 'failed';
		}

		return array(
			'state'             => $future ? 'future_version' : ( $maintenance ? 'maintenance_required' : ( $done ? 'complete' : ( 'failed' === $phase ? 'failed' : ( 'not_started' === $phase ? 'not_started' : 'in_progress' ) ) ) ),
			'phase'             => $future ? 'future_version' : ( $maintenance ? 'maintenance_required' : ( $done ? 'complete' : $phase ) ),
			'schema_version'    => $journal_valid ? ( CanonicalInteger::parse( $journal['schema_version'] ) ?? self::SCHEMA_VERSION ) : self::SCHEMA_VERSION,
			'source_count'      => $journal_valid ? ( CanonicalInteger::parse( $journal['source_count'], 0, self::MAX_SOURCE_RECORDS ) ?? 0 ) : 0,
			'mapped_count'      => $journal_valid ? ( CanonicalInteger::parse( $journal['mapped_count'], 0, self::MAX_SOURCE_RECORDS ) ?? 0 ) : 0,
			'retained_count'    => $retained_count,
			'warnings'          => $warnings,
			'unmapped_keys'     => $unmapped,
			'snapshot_checksum' => isset( $journal['snapshot_checksum'] ) && is_string( $journal['snapshot_checksum'] ) ? $journal['snapshot_checksum'] : '',
		);
	}

	/**
	 * Bind all site-local collaborators to one immutable context.
	 *
	 * @param SiteUpgradeContext        $context Exact whole-convergence binding.
	 * @param MigrationOptionStore|null $store Exact caller-owned transaction cache boundary.
	 * @throws SiteUpgradeContextDrift When the context or supplied store is not exact.
	 */
	private function bind_site_context( SiteUpgradeContext $context, ?MigrationOptionStore $store = null ): void {
		$context->assert_current();
		if ( null !== $store && ! $store->is_bound_to( $context ) ) {
			throw new SiteUpgradeContextDrift( 'The migration option store belongs to a different site context.' );
		}
		$this->site_context = $context;
		$this->store        = $store ?? new MigrationOptionStore( $context );
	}

	/**
	 * Capture all pre-existing CartPops options without changing source data.
	 *
	 * @return array<string, mixed>
	 * @throws MigrationReadException        When a required exact read fails.
	 * @throws MigrationMaintenanceException When source bounds are exceeded.
	 */
	private function create_snapshot(): array {
		$options = $this->discover_cartpops_options();
		$this->load_source_values( $options );
		$this->build_mapped_settings( $options );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Snapshot rows contain strings only; serialize preserves arbitrary legacy bytes for a deterministic checksum.
		$checksum = hash( 'sha256', serialize( $options ) );

		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'site_id'        => $this->site_context->blog_id(),
			'options'        => $options,
			'checksum'       => $checksum,
			'dispositions'   => $this->dispositions,
		);
	}

	/**
	 * Discover raw database rows so the snapshot is sufficient for rollback.
	 *
	 * @return array<string, array{raw_value: string, autoload: string}>
	 * @throws MigrationReadException        When a required exact read fails.
	 * @throws MigrationMaintenanceException When source bounds are exceeded.
	 */
	private function discover_cartpops_options(): array {
		$wpdb          = $this->site_context->database();
		$options_table = $this->site_context->options_table();

		$like                   = $wpdb->esc_like( 'cartpops_' ) . '%';
		$analytics_private_like = $wpdb->esc_like( 'cartpops_analytics_' ) . '%';
		$backfill_private_like  = $wpdb->esc_like( 'cartpops_backfill_' ) . '%';
		$aggregate              = $this->site_context->guard(
			static function () use ( $wpdb, $options_table, $like, $analytics_private_like, $backfill_private_like ): mixed {
				$wpdb->last_error = '';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A bounded preflight prevents loading an unbounded recovery snapshot.
				return $wpdb->get_row(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table is the immutable validated site-context target.
						"SELECT COUNT(*) AS row_count, COALESCE(SUM(OCTET_LENGTH(option_value)), 0) AS total_bytes, COALESCE(MAX(OCTET_LENGTH(option_value)), 0) AS max_bytes FROM {$options_table} WHERE option_name LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s",
						$like,
						$analytics_private_like,
						$backfill_private_like
					),
					ARRAY_A
				);
			}
		);
		if ( '' !== MigrationDatabaseState::last_error( $wpdb ) || ! is_array( $aggregate ) ) {
			throw new MigrationReadException( 'database_read_failed_source_aggregate' );
		}
		$row_count   = CanonicalInteger::parse( $aggregate['row_count'] ?? null );
		$total_bytes = CanonicalInteger::parse( $aggregate['total_bytes'] ?? null );
		$max_bytes   = CanonicalInteger::parse( $aggregate['max_bytes'] ?? null );
		if ( null === $row_count || null === $total_bytes || null === $max_bytes ) {
			throw new MigrationReadException( 'database_read_failed_source_aggregate' );
		}
		if ( $row_count > self::MAX_SOURCE_RECORDS || $total_bytes > self::MAX_SNAPSHOT_BYTES || $max_bytes > self::MAX_RAW_BYTES ) {
			throw new MigrationMaintenanceException( 'legacy_snapshot_bounds_exceeded' );
		}

		// A raw, uncached read is required to preserve option bytes and autoload
		// metadata for rollback; all dynamic input uses a placeholder.
		$rows = $this->site_context->guard(
			static function () use ( $wpdb, $options_table, $like, $analytics_private_like, $backfill_private_like ): mixed {
				$wpdb->last_error = '';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Immutable migration snapshot requires raw rows.
				return $wpdb->get_results(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table is the immutable validated site-context target.
						"SELECT option_name, CASE WHEN OCTET_LENGTH(option_value) <= %d THEN option_value ELSE NULL END AS option_value, OCTET_LENGTH(option_value) AS byte_length, autoload FROM {$options_table} WHERE option_name LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s ORDER BY option_name ASC LIMIT %d",
						self::MAX_RAW_BYTES,
						$like,
						$analytics_private_like,
						$backfill_private_like,
						self::MAX_SOURCE_RECORDS + 1
					),
					ARRAY_A
				);
			}
		);
		if ( '' !== MigrationDatabaseState::last_error( $wpdb ) || ! is_array( $rows ) ) {
			throw new MigrationReadException( 'database_read_failed_source_rows' );
		}

		$options  = array();
		$internal = array_flip(
			array(
				self::SNAPSHOT_OPTION,
				self::JOURNAL_OPTION,
				self::COMPLETION_OPTION,
				self::QUARANTINE_OPTION,
				self::JS_REVIEW_OPTION,
				self::RECOVERY_OPTION,
				self::LOCK_OPTION,
				self::MAINTENANCE_OPTION,
				self::PRE_SDK_EVIDENCE_OPTION,
				self::COMPATIBILITY_OPTION,
				self::RULES_RETIREMENT_OPTION,
			)
		);

		$actual_bytes = 0;
		foreach ( $rows as $row ) {
			if (
				! is_array( $row )
				|| ! $this->has_exact_keys( $row, array( 'option_name', 'option_value', 'byte_length', 'autoload' ) )
				|| ! is_string( $row['option_name'] ?? null )
				|| ! LegacyOptionKey::is_valid( $row['option_name'] )
				|| ! is_string( $row['autoload'] ?? null )
				|| ! in_array( $row['autoload'], array( 'yes', 'no', 'on', 'off', 'auto', 'auto-on', 'auto-off' ), true )
			) {
				throw new MigrationReadException( 'database_read_failed_source_rows' );
			}
			$byte_length = CanonicalInteger::parse( $row['byte_length'] ?? null );
			if ( null === $byte_length ) {
				throw new MigrationReadException( 'database_read_failed_source_rows' );
			}
			$actual_bytes += $byte_length;
			if ( $byte_length > self::MAX_RAW_BYTES || $actual_bytes > self::MAX_SNAPSHOT_BYTES ) {
				throw new MigrationMaintenanceException( 'legacy_snapshot_bounds_exceeded' );
			}
			if ( ! is_string( $row['option_value'] ?? null ) || strlen( $row['option_value'] ) !== $byte_length ) {
				throw new MigrationReadException( 'database_read_failed_source_rows' );
			}

			$name = $row['option_name'];
			if ( isset( $internal[ $name ] ) ) {
				continue;
			}

			$options[ $name ] = array(
				'raw_value' => $row['option_value'],
				'autoload'  => $row['autoload'],
			);
		}
		if ( count( $rows ) !== $row_count || $actual_bytes !== $total_bytes ) {
			throw new MigrationReadException( 'database_read_failed_source_rows' );
		}
		ksort( $options, SORT_STRING );

		return $options;
	}

	/**
	 * Verify that a persisted snapshot was not partially written or altered.
	 *
	 * @param mixed $snapshot Candidate snapshot.
	 */
	private function snapshot_is_valid( mixed $snapshot ): bool {
		if (
			! is_array( $snapshot )
			|| ! $this->has_exact_keys( $snapshot, array( 'schema_version', 'site_id', 'options', 'checksum', 'dispositions' ) )
			|| self::SCHEMA_VERSION !== CanonicalInteger::parse( $snapshot['schema_version'] ?? null )
			|| strlen( $this->store->serialize_value( $snapshot ) ) > self::MAX_SNAPSHOT_BYTES
		) {
			return false;
		}
		$site_id = CanonicalInteger::parse( $snapshot['site_id'] ?? null, 1 );
		if ( $this->site_context->blog_id() !== $site_id ) {
			return false;
		}

		$options      = $snapshot['options'] ?? null;
		$checksum     = $snapshot['checksum'] ?? null;
		$dispositions = $snapshot['dispositions'] ?? null;
		if (
			! is_array( $options )
			|| ! is_array( $dispositions )
			|| count( $options ) > self::MAX_SOURCE_RECORDS
			|| count( $dispositions ) !== count( $options )
			|| ! is_string( $checksum )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $checksum )
		) {
			return false;
		}

		$option_names      = array_keys( $options );
		$disposition_names = array_keys( $dispositions );
		sort( $option_names, SORT_STRING );
		sort( $disposition_names, SORT_STRING );
		if ( $option_names !== $disposition_names ) {
			return false;
		}

		$total_bytes = 0;
		foreach ( $options as $name => $row ) {
			if (
				! is_string( $name )
				|| ! LegacyOptionKey::is_valid( $name )
				|| ! is_array( $row )
				|| ! $this->has_exact_keys( $row, array( 'raw_value', 'autoload' ) )
				|| ! is_string( $row['raw_value'] ?? null )
				|| ! is_string( $row['autoload'] ?? null )
				|| ! in_array( $row['autoload'], array( 'yes', 'no', 'on', 'off', 'auto', 'auto-on', 'auto-off' ), true )
			) {
				return false;
			}

			$bytes        = strlen( $row['raw_value'] );
			$total_bytes += $bytes;
			if ( $bytes > self::MAX_RAW_BYTES || $total_bytes > self::MAX_SNAPSHOT_BYTES ) {
				return false;
			}
			if ( ! $this->snapshot_disposition_is_valid( $name, $dispositions[ $name ] ) ) {
				return false;
			}
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Validating the scalar-only snapshot with the same byte-stable representation used at capture.
		$current_checksum = hash( 'sha256', serialize( $options ) );

		return hash_equals( $checksum, $current_checksum );
	}

	/**
	 * Validate one value-free snapshot disposition with a closed schema.
	 *
	 * @param string $source      Legacy source option name.
	 * @param mixed  $disposition Candidate disposition.
	 */
	private function snapshot_disposition_is_valid( string $source, mixed $disposition ): bool {
		if ( ! is_array( $disposition ) || ! $this->has_exact_keys( $disposition, array( 'status', 'targets' ) ) ) {
			return false;
		}
		$status  = $disposition['status'] ?? null;
		$targets = $disposition['targets'] ?? null;
		if (
			! is_string( $status )
			|| ! in_array( $status, array( 'mapped', 'retired', 'retained', 'quarantined', 'protected' ), true )
			|| ! is_array( $targets )
			|| ! array_is_list( $targets )
			|| count( $targets ) > 32
			|| count( $targets ) !== count( array_unique( $targets, SORT_REGULAR ) )
		) {
			return false;
		}
		foreach ( $targets as $target ) {
			if (
				! is_string( $target )
				|| strlen( $target ) > 191
				|| 1 !== preg_match( '/^(?:cartpops_[a-z0-9_]+|[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+)$/D', $target )
			) {
				return false;
			}
		}

		if ( 'mapped' === $status ) {
			return array() !== $targets;
		}
		if ( 'quarantined' === $status ) {
			return 'cartpops_custom_js' === $source && array( self::QUARANTINE_OPTION ) === $targets;
		}

		return array() === $targets;
	}

	/**
	 * Check a persisted associative array against one exact closed key set.
	 *
	 * @param array<mixed> $record Record to inspect.
	 * @param string[]     $expected Expected keys.
	 */
	private function has_exact_keys( array $record, array $expected ): bool {
		$keys = array_keys( $record );
		sort( $keys, SORT_STRING );
		sort( $expected, SORT_STRING );

		return $expected === $keys;
	}

	/**
	 * Return a strict schema number or -1 for malformed persisted state.
	 *
	 * @param mixed $record Candidate durable record.
	 */
	private function durable_record_schema( mixed $record ): int {
		if ( ! is_array( $record ) ) {
			return -1;
		}

		return CanonicalInteger::parse( $record['schema_version'] ?? null ) ?? -1;
	}

	/**
	 * Validate the value-free journal before it can be replaced on retry.
	 *
	 * @param mixed $journal Candidate journal.
	 */
	private function journal_is_valid( mixed $journal ): bool {
		if (
			! is_array( $journal )
			|| ! $this->has_exact_keys(
				$journal,
				array( 'schema_version', 'phase', 'source_count', 'mapped_count', 'retained_count', 'warnings', 'unmapped_keys', 'snapshot_checksum' )
			)
			|| self::SCHEMA_VERSION !== $this->durable_record_schema( $journal )
		) {
			return false;
		}

		$phase = $journal['phase'] ?? null;
		if ( ! is_string( $phase ) || ! in_array( $phase, array( 'snapshot', 'settings_written', 'complete', 'failed', 'reset_pending' ), true ) ) {
			return false;
		}

		$source_count   = CanonicalInteger::parse( $journal['source_count'] ?? null, 0, self::MAX_SOURCE_RECORDS );
		$mapped_count   = CanonicalInteger::parse( $journal['mapped_count'] ?? null, 0, self::MAX_SOURCE_RECORDS );
		$retained_count = CanonicalInteger::parse( $journal['retained_count'] ?? null, 0, self::MAX_SOURCE_RECORDS );
		if ( null === $source_count || null === $mapped_count || null === $retained_count || $mapped_count + $retained_count > $source_count ) {
			return false;
		}

		$warnings = $journal['warnings'] ?? null;
		$unmapped = $journal['unmapped_keys'] ?? null;
		$checksum = $journal['snapshot_checksum'] ?? null;
		if ( ! is_array( $warnings ) || ! array_is_list( $warnings ) || count( $warnings ) > self::MAX_SOURCE_RECORDS ) {
			return false;
		}
		foreach ( $warnings as $warning ) {
			if ( ! is_string( $warning ) || 1 !== preg_match( '/^[a-z0-9_]{1,191}$/D', $warning ) ) {
				return false;
			}
		}
		if ( ! is_array( $unmapped ) || ! array_is_list( $unmapped ) || count( $unmapped ) > self::MAX_SOURCE_RECORDS ) {
			return false;
		}
		foreach ( $unmapped as $option ) {
			if ( ! LegacyOptionKey::is_valid( $option ) ) {
				return false;
			}
		}

		return is_string( $checksum ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $checksum );
	}

	/**
	 * Validate a bounded value-free maintenance stop marker.
	 *
	 * @param mixed $marker Candidate marker.
	 */
	private function maintenance_marker_is_valid( mixed $marker ): bool {
		return is_array( $marker )
			&& $this->has_exact_keys( $marker, array( 'schema_version', 'reason' ) )
			&& self::SCHEMA_VERSION === $this->durable_record_schema( $marker )
			&& isset( $marker['reason'] )
			&& is_string( $marker['reason'] )
			&& 1 === preg_match( '/^[a-z0-9_]{1,96}$/D', $marker['reason'] );
	}

	/**
	 * Validate an owner-token lock before deciding whether it may be stolen.
	 *
	 * @param mixed $lock Candidate lock.
	 */
	private function lock_record_is_valid( mixed $lock ): bool {
		if (
			! is_array( $lock )
			|| ! $this->has_exact_keys( $lock, array( 'schema_version', 'owner', 'generation', 'expires_at' ) )
			|| self::SCHEMA_VERSION !== $this->durable_record_schema( $lock )
		) {
			return false;
		}

		$owner      = $lock['owner'] ?? null;
		$generation = $lock['generation'] ?? null;
		$expiry     = CanonicalInteger::parse( $lock['expires_at'] ?? null, 0 );

		return is_string( $owner )
			&& 1 === preg_match( '/^[A-Za-z0-9_-]{1,128}$/D', $owner )
			&& is_string( $generation )
			&& 1 === preg_match( '/^[A-Za-z0-9_-]{1,128}$/D', $generation )
			&& null !== $expiry;
	}

	/**
	 * Validate the exact bounded V2 recovery schema.
	 *
	 * @param mixed $record           Candidate recovery record.
	 * @param bool  $allow_additional Whether this is the first-writer envelope.
	 */
	private function recovery_record_is_valid( mixed $record, bool $allow_additional ): bool {
		if ( ! is_array( $record ) || strlen( $this->store->serialize_value( $record ) ) > self::MAX_RECOVERY_BYTES ) {
			return false;
		}

		$required = array( 'schema_version', 'source_option', 'raw_value', 'checksum', 'invalid_paths', 'unknown_paths' );
		$expected = $required;
		if ( $allow_additional && array_key_exists( 'additional_records', $record ) ) {
			$expected[] = 'additional_records';
		}
		if (
			! $this->has_exact_keys( $record, $expected )
			|| self::SCHEMA_VERSION !== $this->durable_record_schema( $record )
			|| self::SETTINGS_OPTION !== ( $record['source_option'] ?? null )
			|| ! is_string( $record['raw_value'] ?? null )
			|| strlen( $record['raw_value'] ) > self::MAX_RAW_BYTES
			|| ! is_string( $record['checksum'] ?? null )
			|| ! hash_equals( hash( 'sha256', $record['raw_value'] ), $record['checksum'] )
			|| ! $this->recovery_paths_are_valid( $record['invalid_paths'] ?? null )
			|| ! $this->recovery_paths_are_valid( $record['unknown_paths'] ?? null )
		) {
			return false;
		}

		if ( ! array_key_exists( 'additional_records', $record ) ) {
			return true;
		}
		$additional = $record['additional_records'];
		if ( ! is_array( $additional ) || array_is_list( $additional ) || count( $additional ) + 1 > self::MAX_RECOVERY_RECORDS ) {
			return false;
		}
		foreach ( $additional as $checksum => $nested ) {
			if (
				! is_string( $checksum )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $checksum )
				|| ! is_array( $nested )
				|| ( $nested['checksum'] ?? null ) !== $checksum
				|| ! $this->recovery_record_is_valid( $nested, false )
			) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate a bounded list of value-free V2 setting paths.
	 *
	 * @param mixed $paths Candidate path list.
	 */
	private function recovery_paths_are_valid( mixed $paths ): bool {
		if ( ! is_array( $paths ) || ! array_is_list( $paths ) || count( $paths ) > self::MAX_SOURCE_RECORDS ) {
			return false;
		}
		foreach ( $paths as $path ) {
			if ( ! is_string( $path ) || strlen( $path ) > 191 || 1 !== preg_match( '/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*$/D', $path ) ) {
				return false;
			}
		}

		return count( $paths ) === count( array_unique( $paths, SORT_STRING ) );
	}

	/**
	 * Validate exact bytes retained for non-executed V1 custom JavaScript.
	 *
	 * @param mixed $record Candidate quarantine record.
	 */
	private function quarantine_record_is_valid( mixed $record ): bool {
		return is_array( $record )
			&& $this->has_exact_keys( $record, array( 'schema_version', 'source_option', 'raw_value', 'checksum' ) )
			&& self::SCHEMA_VERSION === $this->durable_record_schema( $record )
			&& 'cartpops_custom_js' === ( $record['source_option'] ?? null )
			&& is_string( $record['raw_value'] ?? null )
			&& strlen( $record['raw_value'] ) <= self::MAX_RAW_BYTES
			&& is_string( $record['checksum'] ?? null )
			&& hash_equals( hash( 'sha256', $record['raw_value'] ), $record['checksum'] );
	}

	/**
	 * Validate Upgrader's exact settings-normalization marker.
	 *
	 * @param mixed $record Candidate normalization marker.
	 */
	private function normalization_marker_is_valid( mixed $record ): bool {
		return is_array( $record )
			&& $this->has_exact_keys( $record, array( 'schema_version', 'settings_exist', 'settings_sha' ) )
			&& in_array( $this->durable_record_schema( $record ), array( 1, 2, self::NORMALIZATION_SCHEMA_VERSION ), true )
			&& is_bool( $record['settings_exist'] ?? null )
			&& is_string( $record['settings_sha'] ?? null )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/D', $record['settings_sha'] );
	}

	/**
	 * Validate exact bytes retained before settings normalization.
	 *
	 * @param mixed $record Candidate normalization recovery record.
	 */
	private function normalization_recovery_is_valid( mixed $record ): bool {
		if ( ! is_array( $record ) || strlen( $this->store->serialize_value( $record ) ) > self::MAX_RECOVERY_BYTES ) {
			return false;
		}
		$expected = array( 'schema_version', 'raw_value', 'checksum' );
		if ( array_key_exists( 'additional_records', $record ) ) {
			$expected[] = 'additional_records';
		}
		if (
			! $this->has_exact_keys( $record, $expected )
			|| self::SCHEMA_VERSION !== $this->durable_record_schema( $record )
			|| ! is_string( $record['raw_value'] ?? null )
			|| strlen( $record['raw_value'] ) > self::MAX_RECOVERY_BYTES
			|| ! is_string( $record['checksum'] ?? null )
			|| ! hash_equals( hash( 'sha256', $record['raw_value'] ), $record['checksum'] )
		) {
			return false;
		}
		if ( ! array_key_exists( 'additional_records', $record ) ) {
			return true;
		}
		$additional = $record['additional_records'];
		if ( ! is_array( $additional ) || array_is_list( $additional ) || count( $additional ) + 1 > self::MAX_RECOVERY_RECORDS ) {
			return false;
		}
		foreach ( $additional as $checksum => $nested ) {
			if (
				! is_string( $checksum )
				|| ! is_array( $nested )
				|| ( $nested['checksum'] ?? null ) !== $checksum
				|| array_key_exists( 'additional_records', $nested )
				|| ! $this->normalization_recovery_is_valid( $nested )
			) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Decode raw option values from the snapshot without allowing objects.
	 *
	 * @param array<string, mixed> $options Raw snapshot rows.
	 */
	private function load_source_values( array $options ): void {
		$this->source_values       = array();
		$this->unsafe_sources      = array();
		$this->source_option_names = array();
		foreach ( $options as $name => $row ) {
			if ( ! is_string( $name ) || ! is_array( $row ) || ! isset( $row['raw_value'] ) || ! is_string( $row['raw_value'] ) ) {
				continue;
			}
			$this->source_option_names[ $name ] = true;

			$decoded = $this->decode_source_value( $row['raw_value'] );
			if ( ! $decoded['safe'] ) {
				$this->unsafe_sources[ $name ] = $decoded['warning'];
				continue;
			}

			$this->source_values[ $name ] = $decoded['value'];
		}

		$this->legacy_footprint_detected = $this->has_legacy_footprint( $options );
		if ( $this->legacy_footprint_detected ) {
			$this->materialize_legacy_effective_defaults();
		}
	}

	/**
	 * Detect evidence that V1 actually ran on this site.
	 *
	 * A bounded, read-only check of Freemius' product map plus site-local plugin
	 * identity distinguishes many zero-settings V1 installs. User, license,
	 * credential, and entitlement values are neither returned nor retained. The
	 * pre-SDK capture covers the otherwise indistinguishable default-only V1 case.
	 *
	 * @param array<string, mixed> $options Raw CartPops option rows.
	 * @throws MigrationReadException When exact footprint evidence is unavailable.
	 */
	private function has_legacy_footprint( array $options ): bool {
		if ( true === $this->legacy_footprint_override ) {
			return true;
		}

		if ( null !== $this->historical_pre_sdk_basename() ) {
			return true;
		}

		if ( $this->legacy_rules_retirement_detected ) {
			return true;
		}

		$legacy_names = array_fill_keys(
			array_merge(
				array_keys( $this->legacy_defaults() ),
				array_keys( self::CUSTOMER_TEXT_MAPPINGS ),
				array(
					'cartpops_checkout_button_text',
					'cartpops_custom_css',
					'cartpops_custom_js',
					'cartpops_floating_cart_launcher_hide_pages',
					'cartpops_menu_cart_launcher_subtotal',
					'cartpops_free_shipping_meter_custom_global',
					'cartpops_product_recommendation_engine_custom_global',
					'cartpops_drawer_footer_secondary_button_custom_url',
					'cartpops_drawer_footer_secondary_button_custom_text',
				)
			),
			true
		);

		foreach ( array_keys( $options ) as $name ) {
			if ( is_string( $name ) && ( isset( $legacy_names[ $name ] ) || str_starts_with( $name, 'cartpops_settings_' ) ) ) {
				return true;
			}
		}

		$capture_unavailable = null !== $this->pre_sdk_legacy_evidence
			&& ! $this->pre_sdk_legacy_evidence->capture_was_available();

		// The nested settings document and DB-version option were introduced by
		// V2. Once either exists without any V1 option above, it is durable proof
		// that a fresh V2 request ran before the SDK could later write the same
		// product identity used by V1. A failed pre-SDK read cannot rely on this
		// shortcut until recoverable independent V1 evidence has also been checked.
		if (
			! $capture_unavailable
			&& ( isset( $options[ self::SETTINGS_OPTION ] ) || isset( $options['cartpops_db_version'] ) )
		) {
			return false;
		}

		if ( $this->has_cartpops_freemius_footprint() || $this->has_legacy_content_posts() ) {
			return true;
		}

		if ( $capture_unavailable ) {
			throw new MigrationReadException( 'pre_sdk_legacy_evidence_unavailable' );
		}

		return false;
	}

	/**
	 * Inspect bounded Freemius identity for CartPops product 7061.
	 *
	 * Product slug/type/path, historical plugin version, and current-site
	 * blog/plugin identity are the only scalar fields interpreted. Raw records
	 * are neither snapshotted nor returned; user, license, credential, and
	 * entitlement payloads are ignored.
	 *
	 * @throws MigrationReadException When site or network evidence is uncertain.
	 */
	private function has_cartpops_freemius_footprint(): bool {
		$wpdb          = $this->site_context->database();
		$options_table = $this->site_context->options_table();
		/**
		 * WordPress database connection.
		 *
		 * @var \wpdb $wpdb
		 */

		if ( ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name; the key uses a placeholder below.
		$query = $wpdb->prepare( "SELECT option_value FROM {$options_table} WHERE option_name = %s LIMIT 1", 'fs_accounts' );
		$raw   = $this->site_context->guard(
			static function () use ( $wpdb, $query ): mixed {
				$wpdb->last_error = '';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query was prepared above; pre-SDK data is never persisted by CartPops.
				return $wpdb->get_var( $query );
			}
		);
		if ( '' !== MigrationDatabaseState::last_error( $wpdb ) ) {
			throw new MigrationReadException( 'database_read_failed_freemius_site' );
		}
		if ( is_string( $raw ) && '' !== $raw && strlen( $raw ) <= self::MAX_RAW_BYTES ) {
			$evidence = $this->decode_freemius_accounts( $raw );
			if ( null !== $evidence && $evidence['product_match'] && $evidence['historical_version'] ) {
				if ( $evidence['site_present'] ) {
					return 7061 === $evidence['site_plugin_id']
						&& $this->site_context->blog_id() === $evidence['site_blog_id'];
				}

				// A site option row is already scoped to the current blog. This
				// preserves historical anonymous/opted-out V1 installs that never
				// received a Freemius site object, while a V2 2.x identity cannot
				// satisfy the independent version proof.
				if ( $evidence['plugin_data_present'] ) {
					return true;
				}
			}
		}

		$high_water = $this->get_network_legacy_high_water();
		return null !== $high_water && $this->site_context->blog_id() <= $high_water;
	}

	/**
	 * Return the immutable high-water site ID for a proven V1 network install.
	 *
	 * A Freemius product map by itself is insufficient. The cohort is created
	 * only when WordPress' independent active_sitewide_plugins record contains
	 * an evidence-backed historical CartPops basename. Once captured it remains
	 * valid if the old plugin is deactivated between migration batches.
	 *
	 * @throws MigrationReadException When network evidence cannot be read exactly.
	 */
	public function get_network_legacy_high_water(): ?int {
		if ( ! is_multisite() ) {
			return null;
		}

		$stored = $this->network_store->read( self::NETWORK_COHORT_OPTION );
		if ( ! $stored['success'] ) {
			throw new MigrationReadException( 'database_read_failed_network_cohort' );
		}
		if ( $stored['exists'] ) {
			$maximum = $this->validate_network_cohort_raw( $stored['raw_value'] );
			if (
				! $this->network_store->is_non_autoloaded( $stored['autoload'] )
				&& ! $this->network_store->repair_autoload( self::NETWORK_COHORT_OPTION, $stored['raw_value'] )
			) {
				throw new MigrationReadException( 'network_cohort_autoload_repair_failed' );
			}
			return $maximum;
		}

		$accounts = $this->read_network_option( 'fs_accounts' );
		if ( ! $accounts['exists'] || ! is_string( $accounts['raw_value'] ) ) {
			return null;
		}
		$evidence = $this->decode_freemius_accounts( $accounts['raw_value'] );
		if ( null === $evidence || ! $evidence['product_match'] || ! $evidence['historical_version'] ) {
			return null;
		}

		$active = $this->read_network_option( 'active_sitewide_plugins' );
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
		$matched = $evidence['path'];
		if ( ! array_key_exists( $matched, $decoded['value'] ) ) {
			return null;
		}

		global $wpdb;
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A frozen network cohort requires one exact high-water read.
		$maximum = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(blog_id) FROM {$wpdb->blogs} WHERE site_id = %d",
				get_current_network_id()
			)
		);
		$maximum = CanonicalInteger::parse( $maximum, 1 );
		if ( '' !== MigrationDatabaseState::last_error( $wpdb ) || null === $maximum ) {
			throw new MigrationReadException( 'database_read_failed_network_high_water' );
		}

		$cohort  = array(
			'schema_version' => self::SCHEMA_VERSION,
			'network_id'     => get_current_network_id(),
			'max_site_id'    => $maximum,
			'basename'       => $matched,
		);
		$created = $this->network_store->add_immutable( self::NETWORK_COHORT_OPTION, $cohort );
		if ( ! $created['success'] || ! $created['row']['exists'] ) {
			throw new MigrationReadException( 'network_cohort_write_failed' );
		}

		return $this->validate_network_cohort_raw( $created['row']['raw_value'] );
	}

	/**
	 * Exact raw network-option read with safe value decoding.
	 *
	 * @param string $option Network option name.
	 * @return array{exists: bool, value: mixed, raw_value: string}
	 * @throws MigrationReadException When the row is uncertain or unsafe.
	 */
	private function read_network_option( string $option ): array {
		global $wpdb;

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cohort proof must distinguish a missing network row from database failure.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = %s LIMIT 2",
				get_current_network_id(),
				$option
			),
			ARRAY_A
		);
		if ( '' !== MigrationDatabaseState::last_error( $wpdb ) || ! is_array( $rows ) || count( $rows ) > 1 ) {
			throw new MigrationReadException( 'database_read_failed_network_option' );
		}
		if ( array() === $rows ) {
			return array(
				'exists'    => false,
				'value'     => null,
				'raw_value' => '',
			);
		}
		$row = reset( $rows );
		if ( ! is_array( $row ) || ! isset( $row['meta_value'] ) || ! is_string( $row['meta_value'] ) ) {
			throw new MigrationReadException( 'database_read_failed_network_option' );
		}

		$decoded = ( new SafeSerializedReader() )->decode(
			$row['meta_value'],
			self::MAX_RAW_BYTES,
			self::MAX_VALUE_DEPTH,
			self::MAX_VALUE_NODES
		);
		if ( ! $decoded['safe'] && 'fs_accounts' !== $option ) {
			throw new MigrationReadException( 'unsafe_network_option' );
		}

		return array(
			'exists'    => true,
			'value'     => $decoded['safe'] ? $decoded['value'] : null,
			'raw_value' => $row['meta_value'],
		);
	}

	/**
	 * Validate a value-free immutable cohort record.
	 *
	 * @param string $raw Raw cohort bytes.
	 * @throws MigrationReadException When the cohort is malformed.
	 */
	private function validate_network_cohort_raw( string $raw ): int {
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
		if (
			$expected !== $keys
			|| self::SCHEMA_VERSION !== $schema
			|| get_current_network_id() !== CanonicalInteger::parse( $cohort['network_id'] ?? null, 1 )
			|| ! in_array( $cohort['basename'] ?? '', self::LEGACY_NETWORK_BASENAMES, true )
			|| null === CanonicalInteger::parse( $cohort['max_site_id'] ?? null, 1 )
		) {
			throw new MigrationReadException( 'invalid_network_legacy_cohort' );
		}

		return CanonicalInteger::parse( $cohort['max_site_id'], 1 ) ?? 0;
	}

	/**
	 * Decode only the bounded Freemius container needed for footprint matching.
	 *
	 * Older FS_Option_Manager versions accepted JSON strings, while newer rows
	 * are commonly WordPress-serialized arrays. Both paths disable object
	 * construction and apply the same graph limits.
	 *
	 * @param string $raw Raw fs_accounts bytes.
	 * @return array{product_match: bool, historical_version: bool, plugin_data_present: bool, network_activated: bool, site_present: bool, site_blog_id: int|null, site_plugin_id: int|null, site_uninstalled: bool|null, path: string}|null
	 */
	private function decode_freemius_accounts( string $raw ): ?array {
		$trimmed = ltrim( $raw );
		if ( '' === $trimmed || strlen( $raw ) > self::MAX_RAW_BYTES ) {
			return null;
		}

		$values = array();
		if ( '{' === $trimmed[0] || '[' === $trimmed[0] ) {
			try {
				$value = json_decode( $trimmed, true, self::MAX_VALUE_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING );
			} catch ( \JsonException ) {
				return null;
			}
			$nodes = 0;
			if ( ! is_array( $value ) || '' !== $this->inspect_decoded_graph( $value, 0, $nodes ) ) {
				return null;
			}
			$values = array(
				'id_slug_type_path_map.7061.slug'     => $value['id_slug_type_path_map'][7061]['slug'] ?? $value['id_slug_type_path_map']['7061']['slug'] ?? null,
				'id_slug_type_path_map.7061.type'     => $value['id_slug_type_path_map'][7061]['type'] ?? $value['id_slug_type_path_map']['7061']['type'] ?? null,
				'id_slug_type_path_map.7061.path'     => $value['id_slug_type_path_map'][7061]['path'] ?? $value['id_slug_type_path_map']['7061']['path'] ?? null,
				'plugin_data.cartpops'                => isset( $value['plugin_data'] ) && is_array( $value['plugin_data'] ) && array_key_exists( 'cartpops', $value['plugin_data'] ),
				'plugin_data.cartpops.plugin_version' => $value['plugin_data']['cartpops']['plugin_version'] ?? null,
				'plugin_data.cartpops.is_network_activated' => $value['plugin_data']['cartpops']['is_network_activated'] ?? null,
				'sites.cartpops'                      => isset( $value['sites'] ) && is_array( $value['sites'] ) && array_key_exists( 'cartpops', $value['sites'] ),
				'sites.cartpops.blog_id'              => $value['sites']['cartpops']['blog_id'] ?? null,
				'sites.cartpops.plugin_id'            => $value['sites']['cartpops']['plugin_id'] ?? null,
				'sites.cartpops.is_uninstalled'       => $value['sites']['cartpops']['is_uninstalled'] ?? null,
			);
		} else {
			$extracted = ( new SafeSerializedReader() )->extract_paths(
				$raw,
				array(
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
				),
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
			'historical_version'  => $this->is_historical_plugin_version( $version ),
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
	 * Accept only an unambiguous dotted V1 plugin version.
	 *
	 * @param mixed $version Candidate Freemius plugin version.
	 */
	private function is_historical_plugin_version( mixed $version ): bool {
		return is_string( $version )
			&& 1 === preg_match( '/^(?:0|1)(?:\.(?:0|[1-9][0-9]*)){1,4}(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?$/D', $version )
			&& version_compare( $version, '2.0.0', '<' );
	}

	/**
	 * Detect V1 rule/log post types without modifying them.
	 *
	 * @throws MigrationReadException When the post query is uncertain.
	 */
	private function has_legacy_content_posts(): bool {
		$wpdb        = $this->site_context->database();
		$posts_table = $this->site_context->posts_table();
		/**
		 * WordPress database connection.
		 *
		 * @var \wpdb $wpdb
		 */

		if ( ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}

		$found = $this->site_context->guard(
			static function () use ( $wpdb, $posts_table ): mixed {
				$wpdb->last_error = '';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only footprint check before preserving V1 effective defaults.
				return $wpdb->get_var(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table is the immutable validated site-context target.
						"SELECT ID FROM {$posts_table} WHERE post_type IN (%s, %s) LIMIT 1",
						'cartpops_rules',
						'cartpops_master_log'
					)
				);
			}
		);
		if ( '' !== MigrationDatabaseState::last_error( $wpdb ) ) {
			throw new MigrationReadException( 'database_read_failed_legacy_posts' );
		}

		return null !== $found;
	}

	/**
	 * Apply V1's effective defaults without obscuring invalid action evidence.
	 *
	 * V1 used an empty()-fallback for every option. The secondary action has a
	 * stricter migration contract: only a missing row or exact empty string uses
	 * the historical continue-shopping default. Other falsey values remain raw
	 * invalid evidence and fail closed during mapping.
	 */
	private function materialize_legacy_effective_defaults(): void {
		foreach ( $this->legacy_defaults() as $name => $default ) {
			if ( isset( $this->unsafe_sources[ $name ] ) ) {
				continue;
			}

			if ( ! array_key_exists( $name, $this->source_values ) ) {
				$this->source_values[ $name ] = $default;
				continue;
			}

			if ( 'cartpops_drawer_footer_secondary_button' === $name ) {
				if ( '' === $this->source_values[ $name ] ) {
					$this->source_values[ $name ] = $default;
				}
				continue;
			}

			if ( empty( $this->source_values[ $name ] ) ) {
				$this->source_values[ $name ] = $default;
			}
		}
	}

	/**
	 * V1 defaults that have a V2 mapping or compatibility disposition.
	 *
	 * Both V1 free and unified/Pro declared this identical array.
	 *
	 * @return array<string, mixed>
	 */
	private function legacy_defaults(): array {
		return array(
			'cartpops_plugin_enable'                       => 'on',
			'cartpops_support_chat_enable'                 => 'on',
			'cartpops_add_to_cart_trigger'                 => 'drawer',
			'cartpops_coupon_form_enable'                  => 'on',
			'cartpops_floating_cart_launcher_enable'       => 'on',
			'cartpops_floating_cart_launcher_position'     => 'bottom_right',
			'cartpops_floating_cart_launcher_hide_empty'   => '',
			'cartpops_floating_cart_launcher_hide_indicator_empty' => '',
			'cartpops_menu_cart_launcher_icon'             => 'cpops-icon-shopping-bag-line',
			'cartpops_menu_cart_launcher_indicator'        => 'bubble',
			'cartpops_menu_cart_launcher_indicator_empty'  => '',
			'cartpops_menu_cart_launcher_subtotal'         => '',
			'cartpops_product_recommendation_engine_enable' => '',
			'cartpops_product_recommendation_engine_type'  => 'upsells',
			'cartpops_product_recommendation_engine_text'  => 'You might also like these items!',
			'cartpops_product_recommendation_engine_fallback' => 'random_products',
			'cartpops_product_recommendation_engine_button_type' => 'icon',
			'cartpops_product_recommendation_engine_button_text' => 'Add',
			'cartpops_free_shipping_meter_enable'          => '',
			'cartpops_free_shipping_meter_text_base'       => '🎁  Only {{amount}} away from free shipping to {{country}} {{flag}}',
			'cartpops_free_shipping_meter_text_achieved'   => 'Congrats 🎉! You have earned free shipping to {{country}} {{flag}}',
			'cartpops_free_shipping_meter_type'            => 'woocommerce_settings',
			'cartpops_drawer_footer_display_subtotal'      => 'on',
			'cartpops_drawer_footer_display_total'         => 'on',
			'cartpops_drawer_footer_display_discount'      => 'on',
			'cartpops_drawer_footer_display_tax'           => 'on',
			'cartpops_drawer_footer_display_shipping'      => 'on',
			'cartpops_drawer_footer_display_shipping_calculator' => '',
			'cartpops_drawer_footer_secondary_button'      => 'continue_shopping',
			'cartpops_customize_white_space_text'          => 'break',
			'cartpops_animation_type'                      => 'simple',
			'cartpops_customize_animation_duration'        => '300',
			'cartpops_customize_width_drawer_desktop'      => '500',
			'cartpops_customize_width_drawer_mobile'       => '80',
			'cartpops_support_us_enable'                   => 'on',
			'cartpops_support_us_partner_code'             => '',
			'cartpops_force_fragments_refresh'             => '',
			'cartpops_border_radius_rounded'               => 'on',
			'cartpops_color_background_primary'            => '#ffffff',
			'cartpops_color_background_secondary'          => '#f7f3fb',
			'cartpops_color_typography_primary'            => '#26180a',
			'cartpops_color_typography_secondary'          => '#464646',
			'cartpops_color_typography_tertiary'           => '#7a7a7a',
			'cartpops_color_border'                        => '#eaeaec',
			'cartpops_color_accent'                        => '#6f23e1',
			'cartpops_color_overlay'                       => 'rgba(0, 0, 0, 0.887)',
			'cartpops_color_input_background'              => '#ffffff',
			'cartpops_color_input_text'                    => '#26180a',
			'cartpops_color_button_primary_background'     => '#6f23e1',
			'cartpops_color_button_primary_text'           => '#ffffff',
			'cartpops_color_button_secondary_background'   => '#f7f3fb',
			'cartpops_color_button_secondary_text'         => '#26180a',
			'cartpops_color_button_quantity_background'    => '#f7f3fb',
			'cartpops_color_button_quantity_text'          => '#26180a',
			'cartpops_color_input_quantity_background'     => '#ffffff',
			'cartpops_color_input_quantity_border'         => '#f7f3fb',
			'cartpops_color_input_quantity_text'           => '#26180a',
			'cartpops_color_recommendations_button_background' => '#e7e8ea',
			'cartpops_color_recommendations_button_text'   => '#000000',
			'cartpops_color_recommendations_drawer_background' => '#6f23e1',
			'cartpops_color_recommendations_drawer_border' => '#6f23e1',
			'cartpops_color_recommendations_drawer_text'   => '#6f23e1',
			'cartpops_color_slider_pagination_bullet_active' => '#705aef',
			'cartpops_color_slider_pagination_bullet'      => '#705aef',
			'cartpops_color_recommendations_popup_background' => '#f7f3fb',
			'cartpops_color_recommendations_popup_text'    => '#26180a',
			'cartpops_color_floating_cart_laucher_background' => '#000000',
			'cartpops_color_floating_cart_laucher_icon'    => '#ffffff',
			'cartpops_color_floating_cart_laucher_indicator_text' => '#ffffff',
			'cartpops_color_floating_cart_laucher_indicator_background' => '#6f23e1',
			'cartpops_color_cart_laucher_background'       => 'rgba(255, 255, 255, 0)',
			'cartpops_color_cart_laucher_text'             => '#000000',
			'cartpops_color_cart_laucher_bubble_background' => '#705aef',
			'cartpops_color_cart_laucher_bubble_text'      => '#ffffff',
			'cartpops_color_close_color'                   => '#3b3b3b',
			'cartpops_color_remove_color'                  => '#3b3b3b',
			'cartpops_color_state_success'                 => '#24a317',
			'cartpops_color_state_warning'                 => '#ffdd57',
			'cartpops_color_state_danger'                  => '#f14668',
			'cartpops_color_free_shipping_meter_background' => '#f7f3fb',
			'cartpops_color_free_shipping_meter_background_active' => '#25a418',
		);
	}

	/**
	 * Validate existing V2 data before it can override migrated V1 behavior.
	 *
	 * Invalid and unknown values remain byte-equivalent in the immutable V1
	 * snapshot and are additionally indexed in a dedicated recovery record.
	 *
	 * @param bool $presentation_only Preserve all unrelated settings during dormant mapping.
	 * @return array{success: bool, exists: bool, raw_value: string, settings: array<string, mixed>}
	 */
	private function prepare_existing_v2_settings( bool $presentation_only = false ): array {
		$row = $this->read_raw_option_row( self::SETTINGS_OPTION );
		if ( ! is_array( $row ) || ! isset( $row['raw_value'] ) || ! is_string( $row['raw_value'] ) ) {
			return array(
				'success'   => true,
				'exists'    => false,
				'raw_value' => '',
				'settings'  => array(),
			);
		}

		$raw_value = $row['raw_value'];
		$decoded   = $this->decode_source_value( $raw_value );
		$invalid   = array();
		$unknown   = array();
		$settings  = array();

		if ( ! $decoded['safe'] || ! is_array( $decoded['value'] ) || ( array() !== $decoded['value'] && array_is_list( $decoded['value'] ) ) ) {
			$invalid[]        = self::SETTINGS_OPTION;
			$this->warnings[] = 'malformed_v2_settings_retained';
		} else {
			$defaults  = ( new SettingsRepository() )->defaults();
			$candidate = $decoded['value'];
			$preserved = array();
			if ( $presentation_only ) {
				$preserved = $candidate;
				$candidate = array();
				$fields    = array(
					'recommendations'  => array( 'button_type', 'button_text' ),
					'secondary_action' => array( 'mode', 'custom_url', 'custom_text' ),
				);
				foreach ( $fields as $group => $keys ) {
					if ( ! array_key_exists( $group, $decoded['value'] ) ) {
						continue;
					}
					$value               = $decoded['value'][ $group ];
					$candidate[ $group ] = is_array( $value )
						? array_intersect_key( $value, array_fill_keys( $keys, true ) )
						: $value;
					if ( ! is_array( $value ) ) {
						unset( $preserved[ $group ] );
						continue;
					}
				}
			}
			$settings = $this->validate_existing_node( $candidate, $defaults, '', $invalid, $unknown );
			$settings = is_array( $settings ) ? $settings : array();
			if ( $presentation_only ) {
				foreach ( $fields as $group => $keys ) {
					foreach ( $keys as $key ) {
						if ( is_array( $preserved[ $group ] ?? null ) && ! array_key_exists( $key, $settings[ $group ] ?? array() ) ) {
							unset( $preserved[ $group ][ $key ] );
						}
					}
				}
			}
			$settings = array_replace_recursive( $preserved, $settings );
		}

		if ( array() === $invalid && array() === $unknown ) {
			return array(
				'success'   => true,
				'exists'    => true,
				'raw_value' => $raw_value,
				'settings'  => $settings,
			);
		}

		sort( $invalid, SORT_STRING );
		sort( $unknown, SORT_STRING );
		$this->warnings[] = 'existing_v2_values_retained_for_recovery';
		$record           = array(
			'schema_version' => self::SCHEMA_VERSION,
			'source_option'  => self::SETTINGS_OPTION,
			'raw_value'      => $raw_value,
			'checksum'       => hash( 'sha256', $raw_value ),
			'invalid_paths'  => array_values( array_unique( $invalid ) ),
			'unknown_paths'  => array_values( array_unique( $unknown ) ),
		);

		return array(
			'success'   => $this->persist_recovery_record( $record ),
			'exists'    => true,
			'raw_value' => $raw_value,
			'settings'  => $settings,
		);
	}

	/**
	 * Read the current raw option bytes rather than a possibly stale snapshot.
	 *
	 * This lets an explicit retry preserve deliberate V2 changes made after the
	 * original migration while still decoding without object construction.
	 *
	 * @param string $option Option name.
	 * @return array{raw_value: string, autoload: string}|null
	 * @throws MigrationReadException When the exact row cannot be read.
	 */
	private function read_raw_option_row( string $option ): ?array {
		$row = $this->store->read( $option );
		if ( ! $row['success'] ) {
			throw new MigrationReadException( 'database_read_failed_v2_settings' );
		}
		if ( ! $row['exists'] ) {
			return null;
		}

		return array(
			'raw_value' => $row['raw_value'],
			'autoload'  => $row['autoload'],
		);
	}

	/**
	 * Preserve the first recovery record and append distinct retry records.
	 *
	 * @param array<string, mixed> $record Recovery record.
	 * @throws MigrationMaintenanceException When recovery bounds are exceeded.
	 */
	private function persist_recovery_record( array $record ): bool {
		$current = $this->read_internal_option( self::RECOVERY_OPTION );
		if ( ! $current['exists'] ) {
			if ( strlen( $this->store->serialize_value( $record ) ) > self::MAX_RECOVERY_BYTES ) {
				throw new MigrationMaintenanceException( 'v2_recovery_bounds_exceeded' );
			}
			return $this->persist_verified( self::RECOVERY_OPTION, $record, false );
		}
		$stored = $current['value'];

		if ( ! is_array( $stored ) ) {
			return false;
		}

		if ( isset( $stored['checksum'] ) && $record['checksum'] === $stored['checksum'] ) {
			return $this->store->autoload_is( $current['autoload'], false )
				|| ( $this->fence_is_current() && $this->store->compare_and_swap( self::RECOVERY_OPTION, $current['raw_value'], $stored, false ) );
		}

		$checksum                                  = is_string( $record['checksum'] ?? null ) ? $record['checksum'] : '';
		$stored['additional_records']              = isset( $stored['additional_records'] ) && is_array( $stored['additional_records'] )
			? $stored['additional_records']
			: array();
		$stored['additional_records'][ $checksum ] = $record;
		if (
			count( $stored['additional_records'] ) + 1 > self::MAX_RECOVERY_RECORDS
			|| strlen( $this->store->serialize_value( $stored ) ) > self::MAX_RECOVERY_BYTES
		) {
			throw new MigrationMaintenanceException( 'v2_recovery_bounds_exceeded' );
		}

		return $this->fence_is_current()
			&& $this->store->compare_and_swap( self::RECOVERY_OPTION, $current['raw_value'], $stored, false );
	}

	/**
	 * Recursively retain only known, valid V2 values.
	 *
	 * @param mixed    $value         Candidate value.
	 * @param mixed    $default_value Matching V2 default.
	 * @param string   $path          Dot path.
	 * @param string[] $invalid_paths Invalid path accumulator.
	 * @param string[] $unknown_paths Unknown path accumulator.
	 * @return mixed Null means rejected.
	 */
	private function validate_existing_node( mixed $value, mixed $default_value, string $path, array &$invalid_paths, array &$unknown_paths ): mixed {
		if ( is_array( $default_value ) ) {
			if ( ! is_array( $value ) ) {
				$invalid_paths[] = $path;
				return null;
			}

			if ( array_is_list( $default_value ) ) {
				return $this->validate_existing_list( $value, $default_value, $path, $invalid_paths, $unknown_paths );
			}

			if ( array_is_list( $value ) && array() !== $value ) {
				$invalid_paths[] = $path;
				return null;
			}

			$clean = array();
			foreach ( $value as $key => $nested ) {
				if ( ! is_string( $key ) || ! array_key_exists( $key, $default_value ) ) {
					$unknown_paths[] = '' === $path ? (string) $key : $path . '.' . $key;
					continue;
				}

				$nested_path = '' === $path ? $key : $path . '.' . $key;
				$validated   = $this->validate_existing_node( $nested, $default_value[ $key ], $nested_path, $invalid_paths, $unknown_paths );
				if ( null !== $validated ) {
					$clean[ $key ] = $validated;
				}
			}

			return $clean;
		}

		if ( 'general.mini_cart_mode' === $path ) {
			$normalized = SettingsRepository::normalize_mini_cart_mode( $value );
			if ( $normalized !== $value ) {
				$invalid_paths[] = $path;
			}
			return $normalized;
		}
		if ( 'general.trigger' === $path ) {
			$normalized = SettingsRepository::normalize_trigger( $value );
			if ( $normalized !== $value ) {
				$invalid_paths[] = $path;
			}
			return $normalized;
		}
		if ( 'recommendations.button_type' === $path ) {
			$normalized = RecommendationButtonPresentation::normalize_mode( $value );
			if ( $normalized !== $value ) {
				$invalid_paths[] = $path;
				return null;
			}
			return $normalized;
		}
		if ( 'recommendations.button_text' === $path ) {
			$normalized = RecommendationButtonPresentation::sanitize_text_or_null( $value );
			if ( null === $normalized ) {
				$invalid_paths[] = $path;
				return null;
			}
			if ( $normalized !== $value ) {
				$invalid_paths[] = $path;
			}
			return $normalized;
		}
		if ( 'secondary_action.mode' === $path ) {
			$normalized = SecondaryActionSettings::normalize_mode( $value );
			if ( $normalized !== $value ) {
				$invalid_paths[] = $path;
				return null;
			}
			return $normalized;
		}
		if ( 'secondary_action.custom_url' === $path ) {
			if ( '' === $value ) {
				return '';
			}
			$normalized = SecondaryActionSettings::sanitize_custom_url_or_null( $value );
			if ( null === $normalized || $normalized !== $value ) {
				$invalid_paths[] = $path;
				return null;
			}
			return $normalized;
		}
		if ( 'secondary_action.custom_text' === $path ) {
			if ( '' === $value ) {
				return '';
			}
			$normalized = SecondaryActionSettings::sanitize_custom_text_or_null( $value );
			if ( null === $normalized ) {
				$invalid_paths[] = $path;
				return null;
			}
			if ( $normalized !== $value ) {
				$invalid_paths[] = $path;
			}
			return $normalized;
		}

		$type_matches = in_array( $path, array( 'design.overlay_opacity', 'shipping_meter.threshold' ), true )
			? is_int( $value ) || is_float( $value )
			: $this->scalar_type_matches( $value, $default_value );
		if ( ! $type_matches || ( $value !== $default_value && ! $this->scalar_value_allowed( $path, $value ) ) ) {
			$invalid_paths[] = $path;
			return null;
		}

		if ( is_string( $value ) ) {
			if ( 'advanced.custom_css' === $path ) {
				return ( new SettingsRepository() )->sanitize_custom_css( $value );
			}
			return sanitize_text_field( $value );
		}

		return $value;
	}

	/**
	 * Validate a sequential settings list without recursively merging it.
	 *
	 * @param array<mixed> $value         Candidate list.
	 * @param array<mixed> $default_value Matching default list.
	 * @param string       $path          Dot path.
	 * @param string[]     $invalid_paths Invalid path accumulator.
	 * @param string[]     $unknown_paths Unknown path accumulator.
	 * @return array<mixed>|null
	 */
	private function validate_existing_list( array $value, array $default_value, string $path, array &$invalid_paths, array &$unknown_paths ): ?array {
		if ( ! array_is_list( $value ) ) {
			$invalid_paths[] = $path;
			return null;
		}
		if ( 'recommendations.custom_product_ids' === $path ) {
			if ( count( $value ) > 4 || count( $value ) !== count( array_unique( $value, SORT_REGULAR ) ) ) {
				$invalid_paths[] = $path;
				return null;
			}
			foreach ( $value as $product_id ) {
				if ( ! is_int( $product_id ) || $product_id < 1 ) {
					$invalid_paths[] = $path;
					return null;
				}
			}
			return $value;
		}
		if ( 'launcher.hidden_page_ids' === $path ) {
			if (
				count( $value ) > SettingsRepository::MAX_HIDDEN_PAGE_IDS
				|| count( $value ) !== count( array_unique( $value, SORT_REGULAR ) )
			) {
				$invalid_paths[] = $path;
				return null;
			}
			foreach ( $value as $page_id ) {
				if ( ! is_int( $page_id ) || $page_id < 1 ) {
					$invalid_paths[] = $path;
					return null;
				}
			}
			return $value;
		}

		if ( in_array( $path, array( 'notifications.drawer_bar_items', 'bundle_builder.upsells' ), true ) ) {
			return $this->sanitize_free_form_list( $value, $path, $invalid_paths, $unknown_paths );
		}

		if ( array() === $default_value ) {
			foreach ( $value as $item ) {
				if ( ! is_array( $item ) ) {
					$invalid_paths[] = $path;
					return null;
				}
			}
			return $value;
		}

		$template = $default_value[0] ?? null;
		$clean    = array();
		foreach ( $value as $index => $item ) {
			$item_path = $path . '.' . $index;
			$validated = $this->validate_existing_node( $item, $template, $item_path, $invalid_paths, $unknown_paths );
			if ( null === $validated ) {
				$invalid_paths[] = $path;
				return null;
			}
			$clean[] = $validated;
		}

		return $clean;
	}

	/**
	 * Route free-form item lists through the production settings schema.
	 *
	 * Any canonicalization or rejected item is indexed in the recovery record;
	 * only the production sanitizer's safe result may override migrated data.
	 *
	 * @param array<mixed> $value         Candidate list.
	 * @param string       $path          Exact supported list path.
	 * @param string[]     $invalid_paths Invalid path accumulator.
	 * @param string[]     $unknown_paths Unknown path accumulator.
	 * @return array<mixed>|null
	 */
	private function sanitize_free_form_list( array $value, string $path, array &$invalid_paths, array &$unknown_paths ): ?array {
		$segments = explode( '.', $path, 2 );
		$group    = $segments[0] ?? '';
		$key      = $segments[1] ?? '';
		if ( '' === $group || '' === $key ) {
			$invalid_paths[] = $path;
			return null;
		}

		$sanitized = ( new SettingsRepository() )->sanitize(
			array(
				$group => array( $key => $value ),
			)
		);
		$clean     = $sanitized[ $group ][ $key ] ?? null;
		if ( ! is_array( $clean ) || ! array_is_list( $clean ) ) {
			$invalid_paths[] = $path;
			return null;
		}

		if ( $clean !== $value ) {
			$invalid_paths[] = $path;
			$this->record_removed_keys( $value, $clean, $path, $unknown_paths );
		}

		return $clean;
	}

	/**
	 * Index keys removed by production sanitation without retaining values.
	 *
	 * @param mixed    $original      Pre-sanitization node.
	 * @param mixed    $sanitized     Sanitized node.
	 * @param string   $path          Current dot path.
	 * @param string[] $unknown_paths Unknown path accumulator.
	 */
	private function record_removed_keys( mixed $original, mixed $sanitized, string $path, array &$unknown_paths ): void {
		if ( ! is_array( $original ) || ! is_array( $sanitized ) ) {
			return;
		}

		foreach ( $original as $key => $value ) {
			$child_path = $path . '.' . (string) $key;
			if ( ! array_key_exists( $key, $sanitized ) ) {
				if ( is_string( $key ) ) {
					$unknown_paths[] = $child_path;
				}
				continue;
			}
			$this->record_removed_keys( $value, $sanitized[ $key ], $child_path, $unknown_paths );
		}
	}

	/**
	 * Require the exact scalar type stored by V2's settings repository.
	 *
	 * @param mixed $value         Candidate value.
	 * @param mixed $default_value Matching V2 default.
	 */
	private function scalar_type_matches( mixed $value, mixed $default_value ): bool {
		return ( is_bool( $default_value ) && is_bool( $value ) )
			|| ( is_int( $default_value ) && is_int( $value ) )
			|| ( is_float( $default_value ) && ( is_int( $value ) || is_float( $value ) ) )
			|| ( is_string( $default_value ) && is_string( $value ) );
	}

	/**
	 * Enforce customer-facing enums, ranges, and color syntax.
	 *
	 * @param string $path  V2 dot path.
	 * @param mixed  $value Candidate value.
	 */
	private function scalar_value_allowed( string $path, mixed $value ): bool {
		$ranges = array(
			'drawer.width_desktop'        => array( 50, 1400 ),
			'drawer.width_mobile'         => array( 50, 100 ),
			'drawer.animation_duration'   => array( 50, 800 ),
			'launcher.size'               => array( 40, 80 ),
			'launcher.offset_x'           => array( 0, 80 ),
			'launcher.offset_y'           => array( 0, 80 ),
			'design.border_radius'        => array( 0, 32 ),
			'design.button_border_radius' => array( 0, 32 ),
			'design.overlay_opacity'      => array( 0, 100 ),
			'shipping_meter.threshold'    => array( 0, self::MAX_MONEY_VALUE ),
			'recommendations.limit'       => array( 1, 8 ),
		);
		if ( isset( $ranges[ $path ] ) ) {
			return is_int( $value ) || is_float( $value )
				? $value >= $ranges[ $path ][0] && $value <= $ranges[ $path ][1]
				: false;
		}

		$enums = array(
			'drawer.position'             => array( 'left', 'right' ),
			'drawer.animation'            => array( 'none', 'fade', 'slide' ),
			'drawer.totals_breakdown'     => array( 'hidden', 'body', 'footer' ),
			'drawer.product_name_display' => array( 'two_lines', 'single_line', 'full' ),
			'drawer.quantity_style'       => array( 'default', 'compact', 'none' ),
			'launcher.icon'               => array( 'cart', 'bag', 'basket' ),
			'launcher.menu.icon'          => array( 'cart', 'bag', 'basket' ),
			'launcher.menu.indicator'     => array( 'none', 'bubble', 'plain' ),
			'launcher.position'           => array( 'bottom_left', 'bottom_right' ),
			'design.dark_mode'            => array( 'auto', 'light', 'dark' ),
			'recommendations.strategy'    => array( 'upsell', 'cross_sell', 'custom' ),
			'recommendations.fallback'    => array( 'none', 'random', 'upsell', 'cross_sell' ),
			'recommendations.layout'      => array( 'horizontal', 'vertical' ),
			'recommendations.button_type' => array( 'icon', 'text', 'text_icon' ),
			'secondary_action.mode'       => array( 'none', 'continue_shopping', 'view_cart', 'custom_url' ),
		);
		if ( isset( $enums[ $path ] ) ) {
			return is_string( $value ) && in_array( $value, $enums[ $path ], true );
		}

		if ( in_array( $path, array( 'design.colors.overlay', 'design.colors_dark.overlay' ), true ) ) {
			return is_string( $value ) && null !== $this->normalize_css_color( $value );
		}
		if ( 'design.overlay_color' === $path ) {
			return is_string( $value ) && 1 === preg_match( '/^#[a-f0-9]{6}$/iD', $value );
		}
		if (
			in_array(
				$path,
				array(
					'launcher.inline_colors.background',
					'launcher.inline_colors.text',
					'launcher.inline_colors.badge_bg',
					'launcher.inline_colors.badge_text',
				),
				true
			)
		) {
			return is_string( $value ) && null !== $this->normalize_css_color( $value );
		}

		if ( str_contains( $path, '.colors.' ) || str_ends_with( $path, '_color' ) ) {
			return is_string( $value ) && null !== $this->normalize_css_color( $value );
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return is_finite( (float) $value );
		}

		return true;
	}

	/**
	 * Decode serialized arrays/scalars without ever constructing PHP objects.
	 *
	 * @param string $raw_value Raw database value.
	 * @return array{safe: bool, value: mixed, warning: string}
	 */
	private function decode_source_value( string $raw_value ): array {
		$decoded = ( new SafeSerializedReader() )->decode(
			$raw_value,
			self::MAX_RAW_BYTES,
			self::MAX_VALUE_DEPTH,
			self::MAX_VALUE_NODES
		);

		return array(
			'safe'    => $decoded['safe'],
			'value'   => $decoded['value'],
			'warning' => $decoded['warning'],
		);
	}

	/**
	 * Inspect a decoded graph after serialized references were rejected.
	 *
	 * @param mixed $value Decoded value.
	 * @param int   $depth Current array nesting depth.
	 * @param int   $nodes Running node count.
	 * @return string Empty string when safe, otherwise object/depth/nodes.
	 */
	private function inspect_decoded_graph( mixed $value, int $depth, int &$nodes ): string {
		++$nodes;
		if ( $nodes > self::MAX_VALUE_NODES ) {
			return 'nodes';
		}

		if ( is_object( $value ) ) {
			return 'object';
		}

		if ( ! is_array( $value ) ) {
			return '';
		}

		if ( $depth >= self::MAX_VALUE_DEPTH ) {
			return 'depth';
		}

		foreach ( $value as $nested_value ) {
			$result = $this->inspect_decoded_graph( $nested_value, $depth + 1, $nodes );
			if ( '' !== $result ) {
				return $result;
			}
		}

		return '';
	}

	/**
	 * Build mapped settings and a value-free disposition report.
	 *
	 * @param array<string, mixed> $options Raw snapshot rows.
	 * @return array<string, mixed>
	 */
	private function build_mapped_settings( array $options ): array {
		$mapped             = array();
		$this->dispositions = array();
		$this->warnings     = array();
		foreach ( $this->unsafe_sources as $source => $warning ) {
			$this->warnings[] = $this->source_warning( $warning, $source, 'unsafe_serialized_source_retained' );
		}

		$this->map_boolean( $mapped, 'cartpops_plugin_enable', 'general.enabled' );
		$this->map_boolean( $mapped, 'cartpops_support_us_enable', 'general.powered_by' );
		$this->map_partner_code( $mapped );
		$this->map_add_to_cart_trigger( $mapped );
		$this->map_boolean( $mapped, 'cartpops_coupon_form_enable', 'drawer.show_coupon' );
		$this->map_string( $mapped, 'cartpops_checkout_button_text', 'drawer.checkout_button_text' );
		$this->map_customer_texts( $mapped );
		$this->map_integer( $mapped, 'cartpops_customize_width_drawer_desktop', 'drawer.width_desktop' );
		$this->map_integer( $mapped, 'cartpops_customize_width_drawer_mobile', 'drawer.width_mobile' );
		$this->map_animation( $mapped );
		$this->map_integer( $mapped, 'cartpops_customize_animation_duration', 'drawer.animation_duration' );
		$this->map_product_name_display( $mapped );
		$this->set_path( $mapped, 'drawer.totals_breakdown', 'footer' );
		$this->map_boolean( $mapped, 'cartpops_drawer_footer_display_subtotal', 'drawer.show_subtotal' );
		$this->map_boolean( $mapped, 'cartpops_drawer_footer_display_discount', 'drawer.show_discount' );
		$this->map_boolean( $mapped, 'cartpops_drawer_footer_display_shipping', 'drawer.show_shipping' );
		$this->map_boolean( $mapped, 'cartpops_drawer_footer_display_tax', 'drawer.show_tax' );
		$this->map_boolean( $mapped, 'cartpops_drawer_footer_display_total', 'drawer.show_total' );
		$this->map_boolean( $mapped, 'cartpops_floating_cart_launcher_enable', 'launcher.enabled' );
		$this->map_launcher_position( $mapped );
		$this->map_launcher_hidden_pages( $mapped );
		$this->map_boolean( $mapped, 'cartpops_floating_cart_launcher_hide_empty', 'launcher.hide_empty' );
		$this->map_boolean( $mapped, 'cartpops_floating_cart_launcher_hide_indicator_empty', 'launcher.hide_indicator_empty' );
		$this->map_menu_launcher_icon( $mapped );
		$this->map_menu_launcher_indicator( $mapped );
		$this->map_boolean( $mapped, 'cartpops_menu_cart_launcher_indicator_empty', 'launcher.menu.hide_indicator_empty' );
		$this->map_boolean( $mapped, 'cartpops_menu_cart_launcher_subtotal', 'launcher.menu.show_total' );
		$this->map_launcher_colors( $mapped );
		$this->map_inline_launcher_colors( $mapped );
		$this->map_design_settings( $mapped );
		$this->map_shipping_meter( $mapped );
		$this->map_boolean( $mapped, 'cartpops_drawer_footer_display_shipping_calculator', 'shipping_calculator.enabled' );
		$this->map_secondary_action( $mapped );
		$this->map_boolean( $mapped, 'cartpops_product_recommendation_engine_enable', 'recommendations.enabled' );
		$this->map_recommendation_strategy( $mapped );
		$this->map_custom_recommendation_products( $mapped );
		$this->map_recommendation_fallback( $mapped );
		$this->map_string( $mapped, 'cartpops_product_recommendation_engine_text', 'recommendations.heading' );
		$this->map_recommendation_button_presentation( $mapped );
		$this->map_overlay( $mapped );
		$this->map_boolean( $mapped, 'cartpops_force_fragments_refresh', 'advanced.force_fragments_refresh' );
		$this->map_custom_css( $mapped );

		if ( array_key_exists( 'cartpops_custom_js', $this->source_values ) ) {
			$this->dispositions['cartpops_custom_js'] = array(
				'status'  => 'quarantined',
				'targets' => array( self::QUARANTINE_OPTION ),
			);
			$this->warnings[]                         = 'legacy_custom_js_quarantined';
		}

		$protected = array( self::SETTINGS_OPTION, 'cartpops_db_version' );
		foreach ( array_keys( $options ) as $name ) {
			if ( isset( $this->dispositions[ $name ] ) ) {
				continue;
			}

			$this->dispositions[ $name ] = array(
				'status'  => in_array( $name, $protected, true ) ? 'protected' : 'retained',
				'targets' => array(),
			);
		}

		if ( array_filter( $this->dispositions, static fn( array $item ): bool => 'retained' === $item['status'] ) ) {
			$this->warnings[] = 'legacy_unmapped_options_retained';
		}

		$this->warnings = array_values( array_unique( $this->warnings ) );

		return $mapped;
	}

	/**
	 * Store legacy custom JavaScript in a dedicated non-executed option.
	 *
	 * @param array<string, mixed> $options Raw snapshot rows.
	 */
	private function quarantine_custom_js( array $options ): bool {
		$row = $options['cartpops_custom_js'] ?? null;
		if ( ! is_array( $row ) || ! isset( $row['raw_value'] ) || ! is_string( $row['raw_value'] ) ) {
			return true;
		}

		$quarantine = array(
			'schema_version' => self::SCHEMA_VERSION,
			'source_option'  => 'cartpops_custom_js',
			'raw_value'      => $row['raw_value'],
			'checksum'       => hash( 'sha256', $row['raw_value'] ),
		);
		$stored     = $this->read_internal_option( self::QUARANTINE_OPTION );
		if ( $stored['exists'] ) {
			if ( $quarantine !== $stored['value'] ) {
				return false;
			}
			return $this->store->autoload_is( $stored['autoload'], false )
				|| ( $this->fence_is_current() && $this->store->compare_and_swap( self::QUARANTINE_OPTION, $stored['raw_value'], $quarantine, false ) );
		}

		return $this->persist_verified( self::QUARANTINE_OPTION, $quarantine, false );
	}

	/**
	 * Persist the current phase without including any customer values.
	 *
	 * @param string               $phase    Journal phase.
	 * @param array<string, mixed> $snapshot Migration snapshot metadata.
	 */
	private function write_journal( string $phase, array $snapshot ): bool {
		$journal = $this->build_journal_record( $phase, $snapshot );
		if ( null === $journal ) {
			return false;
		}

		return $this->persist_verified( self::JOURNAL_OPTION, $journal, false );
	}

	/**
	 * Construct and validate a value-free migration journal record.
	 *
	 * @param string               $phase    Journal phase.
	 * @param array<string, mixed> $snapshot Migration snapshot metadata.
	 * @return array<string, mixed>|null
	 */
	private function build_journal_record( string $phase, array $snapshot ): ?array {
		$unmapped = array_keys(
			array_filter(
				$this->dispositions,
				static fn( array $item ): bool => 'retained' === $item['status']
			)
		);
		$mapped   = array_filter(
			$this->dispositions,
			static fn( array $item ): bool => 'mapped' === $item['status']
		);

		$journal = array(
			'schema_version'    => self::SCHEMA_VERSION,
			'phase'             => $phase,
			'source_count'      => isset( $snapshot['options'] ) && is_array( $snapshot['options'] ) ? count( $snapshot['options'] ) : 0,
			'mapped_count'      => count( $mapped ),
			'retained_count'    => count( $unmapped ),
			'warnings'          => $this->warnings,
			'unmapped_keys'     => $unmapped,
			'snapshot_checksum' => isset( $snapshot['checksum'] ) && is_string( $snapshot['checksum'] ) ? $snapshot['checksum'] : '',
		);
		if ( ! $this->journal_is_valid( $journal ) ) {
			$this->runtime_warnings[] = 'constructed_journal_invalid';
			return null;
		}

		return $journal;
	}

	/**
	 * Map a checkbox-like legacy option.
	 *
	 * @param array<string, mixed> $mapped Settings being built.
	 * @param string               $source Legacy option name.
	 * @param string               $target V2 dot-path.
	 */
	private function map_boolean( array &$mapped, string $source, string $target ): void {
		if ( ! array_key_exists( $source, $this->source_values ) ) {
			return;
		}

		$normalized = $this->normalize_boolean( $this->source_values[ $source ] );
		if ( null !== $normalized ) {
			$this->set_path( $mapped, $target, $normalized );
			$this->mark_mapped( $source, $target );
		} else {
			$this->warn_invalid( $source );
		}
	}

	/**
	 * Map an integer legacy option.
	 *
	 * @param array<string, mixed> $mapped Settings being built.
	 * @param string               $source Legacy option name.
	 * @param string               $target V2 dot-path.
	 */
	private function map_integer( array &$mapped, string $source, string $target ): void {
		$value  = $this->source_values[ $source ] ?? null;
		$parsed = CanonicalInteger::parse( $value );
		if ( null !== $parsed && $this->scalar_value_allowed( $target, $parsed ) ) {
			$this->set_path( $mapped, $target, $parsed );
			$this->mark_mapped( $source, $target );
		} elseif ( array_key_exists( $source, $this->source_values ) ) {
			$this->warn_invalid( $source );
		}
	}

	/**
	 * Map a scalar legacy option as a string.
	 *
	 * @param array<string, mixed> $mapped Settings being built.
	 * @param string               $source Legacy option name.
	 * @param string               $target V2 dot-path.
	 */
	private function map_string( array &$mapped, string $source, string $target ): void {
		$value = $this->source_values[ $source ] ?? null;
		if ( null !== $value && is_scalar( $value ) ) {
			$this->set_path( $mapped, $target, sanitize_text_field( (string) $value ) );
			$this->mark_mapped( $source, $target );
		}
	}

	/**
	 * Map the bounded V1 partner identifier without repairing malformed input.
	 *
	 * @param array<string, mixed> $mapped Settings being built.
	 */
	private function map_partner_code( array &$mapped ): void {
		$source = 'cartpops_support_us_partner_code';
		if ( ! array_key_exists( $source, $this->source_values ) ) {
			return;
		}

		$code = LegacyPoweredByLink::normalize_partner_code( $this->source_values[ $source ] );
		if ( null === $code ) {
			$this->warn_invalid( $source );
			return;
		}

		$this->set_path( $mapped, 'general.powered_by_partner_code', $code );
		$this->mark_mapped( $source, 'general.powered_by_partner_code' );
	}

	/**
	 * Map the customer-visible V1 drawer strings to their shared V2 paths.
	 *
	 * @param array<string, mixed> $mapped Settings being built.
	 */
	private function map_customer_texts( array &$mapped ): void {
		foreach ( self::CUSTOMER_TEXT_MAPPINGS as $source => $target ) {
			$value = $this->source_values[ $source ] ?? null;
			if ( ! is_scalar( $value ) ) {
				if ( array_key_exists( $source, $this->source_values ) ) {
					$this->warn_invalid( $source );
				}
				continue;
			}

			$text = (string) $value;
			$text = strlen( $text ) > self::MAX_TEXT_LENGTH
				? ( function_exists( 'mb_strcut' ) ? mb_strcut( $text, 0, self::MAX_TEXT_LENGTH, 'UTF-8' ) : substr( $text, 0, self::MAX_TEXT_LENGTH ) )
				: $text;
			$text = sanitize_text_field( $text );
			if ( strlen( $text ) > self::MAX_TEXT_LENGTH ) {
				$text = function_exists( 'mb_strcut' ) ? mb_strcut( $text, 0, self::MAX_TEXT_LENGTH, 'UTF-8' ) : substr( $text, 0, self::MAX_TEXT_LENGTH );
			}
			$this->set_path( $mapped, $target, $text );
			$this->mark_mapped( $source, $target );
		}
	}

	/**
	 * Sanitize V1 custom CSS before placing it on V2's executable CSS path.
	 *
	 * The immutable snapshot retains the exact original bytes for rollback.
	 *
	 * @param array<string, mixed> $mapped Settings being built.
	 */
	private function map_custom_css( array &$mapped ): void {
		$source = 'cartpops_custom_css';
		$value  = $this->source_values[ $source ] ?? null;
		if ( ! is_string( $value ) ) {
			if ( array_key_exists( $source, $this->source_values ) ) {
				$this->warn_invalid( $source );
			}
			return;
		}

		$clean = ( new SettingsRepository() )->sanitize_custom_css( $value );
		$this->set_path( $mapped, 'advanced.custom_css', $clean );
		$this->mark_mapped( $source, 'advanced.custom_css' );
	}

	/**
	 * Map every recognized V1 add-to-cart trigger to the supported drawer.
	 *
	 * @param array<string, mixed> $mapped Settings being built.
	 */
	private function map_add_to_cart_trigger( array &$mapped ): void {
		$source = 'cartpops_add_to_cart_trigger';
		$value  = $this->source_values[ $source ] ?? null;
		if ( ! is_string( $value ) ) {
			return;
		}

		if ( in_array( $value, array( 'drawer', 'add_to_cart', 'popup', 'bar' ), true ) ) {
			$this->set_path( $mapped, 'general.trigger', 'add_to_cart' );
			$this->mark_mapped( $source, 'general.trigger' );
			if ( in_array( $value, array( 'popup', 'bar' ), true ) ) {
				$this->warnings[] = 'legacy_add_to_cart_trigger_' . $value . '_mapped_to_add_to_cart';
			}
			return;
		}

		$this->warn_invalid( $source );
	}

	/**
	 * Normalize V1 drawer animation names to V2's supported set.
	 *
	 * @param array<string, mixed> $mapped Settings being built.
	 */
	private function map_animation( array &$mapped ): void {
		$source = 'cartpops_animation_type';
		$value  = $this->source_values[ $source ] ?? null;
		if ( ! is_string( $value ) ) {
			return;
		}

		$animation = match ( $value ) {
			'none', 'fade'     => $value,
			'simple', 'slick'  => 'slide',
			default            => null,
		};

		if ( null === $animation ) {
			$this->warn_invalid( $source );
			return;
		}

		$this->set_path( $mapped, 'drawer.animation', $animation );
		$this->mark_mapped( $source, 'drawer.animation' );
		if ( 'slick' === $value ) {
			$this->warnings[] = 'legacy_animation_slick_mapped_to_slide';
		}
	}

	/**
	 * Preserve V1's cart-item product-name wrapping preference.
	 *
	 * @param array<string, mixed> $mapped Settings being built.
	 */
	private function map_product_name_display( array &$mapped ): void {
		$source = 'cartpops_customize_white_space_text';
		if ( ! array_key_exists( $source, $this->source_values ) ) {
			return;
		}

		$value = $this->source_values[ $source ];
		$mode  = match ( $value ) {
			'nowrap' => 'single_line',
			'break'  => 'full',
			default  => null,
		};

		if ( null === $mode ) {
			$this->warn_invalid( $source );
			return;
		}

		$this->set_path( $mapped, 'drawer.product_name_display', $mode );
		$this->mark_mapped( $source, 'drawer.product_name_display' );
	}

	/**
	 * Map the supported floating-launcher positions.
	 *
	 * @param array<string, mixed> $mapped Settings being built.
	 */
	private function map_launcher_position( array &$mapped ): void {
		$source = 'cartpops_floating_cart_launcher_position';
		$value  = $this->source_values[ $source ] ?? null;
		if ( is_string( $value ) && in_array( $value, array( 'bottom_left', 'bottom_right' ), true ) ) {
			$this->set_path( $mapped, 'launcher.position', $value );
			$this->mark_mapped( $source, 'launcher.position' );
		} elseif ( array_key_exists( $source, $this->source_values ) ) {
			$this->warn_invalid( $source );
		}
	}

	/**
	 * Preserve V1's floating-launcher page exclusions as ordered page IDs.
	 *
	 * Sparse keys and duplicates had no additional V1 meaning. Unknown values
	 * remain retained and keep the compatibility fence nonterminal.
	 *
	 * @param array<string, mixed> $mapped Settings being built.
	 */
	private function map_launcher_hidden_pages( array &$mapped ): void {
		$source = 'cartpops_floating_cart_launcher_hide_pages';
		if ( ! array_key_exists( $source, $this->source_values ) ) {
			return;
		}

		$value = $this->source_values[ $source ];
		if ( empty( $value ) ) {
			$this->set_path( $mapped, 'launcher.hidden_page_ids', array() );
			$this->mark_mapped( $source, 'launcher.hidden_page_ids' );
			return;
		}

		$candidates = is_array( $value ) ? array_values( $value ) : array( $value );
		if ( count( $candidates ) > SettingsRepository::MAX_HIDDEN_PAGE_IDS ) {
			$this->warn_invalid( $source );
			return;
		}

		$page_ids = array();
		$seen     = array();
		foreach ( $candidates as $candidate ) {
			$page_id = CanonicalInteger::parse( $candidate, 1 );
			if ( null === $page_id ) {
				$this->warn_invalid( $source );
				return;
			}
			if ( isset( $seen[ $page_id ] ) ) {
				continue;
			}
			$seen[ $page_id ] = true;
			$page_ids[]       = $page_id;
		}

		$this->set_path( $mapped, 'launcher.hidden_page_ids', $page_ids );
		$this->mark_mapped( $source, 'launcher.hidden_page_ids' );
	}

	/**
	 * Map the exact V1 menu-cart count presentation to the menu launcher.
	 *
	 * @param array<string, mixed> $mapped Settings being built.
	 */
	private function map_menu_launcher_indicator( array &$mapped ): void {
		$source    = 'cartpops_menu_cart_launcher_indicator';
		$indicator = $this->source_values[ $source ] ?? null;
		if ( is_string( $indicator ) && in_array( $indicator, array( 'none', 'bubble', 'plain' ), true ) ) {
			$this->set_path( $mapped, 'launcher.menu.indicator', $indicator );
			$this->mark_mapped( $source, 'launcher.menu.indicator' );
		} elseif ( array_key_exists( $source, $this->source_values ) ) {
			$this->warn_invalid( $source );
		}
	}

	/**
	 * Map the historical icon-font launcher choices to V2 SVG families.
	 *
	 * @param array<string, mixed> $mapped Settings being built.
	 */
	private function map_menu_launcher_icon( array &$mapped ): void {
		$source = 'cartpops_menu_cart_launcher_icon';
		$value  = $this->source_values[ $source ] ?? null;
		if ( ! is_string( $value ) ) {
			return;
		}

		$icons = array(
			'cpops-icon-shopping-cart-outline' => 'cart',
			'cpops-icon-shopping-cart-line'    => 'cart',
			'cpops-icon-shopping-cart-fill'    => 'cart',
			'cpops-icon-shopping-cart-2-line'  => 'cart',
			'cpops-icon-shopping-cart-2-fill'  => 'cart',
			'cpops-icon-shopping-bag-outline'  => 'bag',
			'cpops-icon-shopping-bag-line'     => 'bag',
			'cpops-icon-shopping-bag-fill'     => 'bag',
			'cpops-icon-shopping-bag-2-line'   => 'bag',
			'cpops-icon-shopping-bag-2-fill'   => 'bag',
			'cpops-icon-shopping-bag-3-fill'   => 'bag',
			'cpops-icon-handbag-line'          => 'bag',
			'cpops-icon-cpops-handbag-fill'    => 'bag',
		);
		if ( ! isset( $icons[ $value ] ) ) {
			$this->warn_invalid( $source );
			return;
		}

		$this->set_path( $mapped, 'launcher.menu.icon', $icons[ $value ] );
		$this->mark_mapped( $source, 'launcher.menu.icon' );
	}

	/**
	 * Map floating-launcher colors, including the historical laucher typo.
	 *
	 * @param array<string, mixed> $mapped Settings being built.
	 */
	private function map_launcher_colors( array &$mapped ): void {
		$colors = array(
			'background'           => 'background',
			'icon'                 => 'icon',
			'indicator_background' => 'badge_bg',
			'indicator_text'       => 'badge_text',
		);

		foreach ( $colors as $legacy_suffix => $target ) {
			$this->map_color( $mapped, 'cartpops_color_floating_cart_laucher_' . $legacy_suffix, 'launcher.colors.' . $target );
			$this->map_color( $mapped, 'cartpops_color_floating_cart_launcher_' . $legacy_suffix, 'launcher.colors.' . $target );
		}
	}

	/**
	 * Map the shared menu/shortcode launcher palette from V1's misspelled keys.
	 *
	 * Both shipped V1 repositories used only the historical `laucher` spelling;
	 * unlike the floating palette, no corrected launcher alias was active.
	 *
	 * @param array<string, mixed> $mapped Settings being built.
	 */
	private function map_inline_launcher_colors( array &$mapped ): void {
		foreach ( self::INLINE_COLOR_MAPPINGS as $source => $target ) {
			$this->map_color( $mapped, $source, 'launcher.inline_colors.' . $target );
		}
	}

	/** Whether an exact V1 inline palette source is present but cannot be mapped safely. */
	private function invalid_inline_launcher_color_requires_review(): bool {
		foreach ( array_keys( self::INLINE_COLOR_MAPPINGS ) as $source ) {
			if ( ! array_key_exists( $source, $this->source_values ) ) {
				continue;
			}

			$value = $this->source_values[ $source ];
			if ( ! is_string( $value ) || null === $this->normalize_css_color( $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Map V1 semantic design colors and the rounded/square radius toggle.
	 *
	 * @param array<string, mixed> $mapped Settings being built.
	 */
	private function map_design_settings( array &$mapped ): void {
		$radius_source = 'cartpops_border_radius_rounded';
		if ( array_key_exists( $radius_source, $this->source_values ) ) {
			$rounded = $this->normalize_boolean( $this->source_values[ $radius_source ] );
			if ( null !== $rounded ) {
				$radius = $rounded ? 6 : 0;
				$this->set_path( $mapped, 'design.border_radius', $radius );
				$this->set_path( $mapped, 'design.button_border_radius', $radius );
				$this->mark_mapped( $radius_source, 'design.border_radius' );
				$this->mark_mapped( $radius_source, 'design.button_border_radius' );
			}
		}

		$colors = array(
			'cartpops_color_background_primary'            => 'background',
			'cartpops_color_background_secondary'          => 'surface',
			'cartpops_color_typography_primary'            => 'text_primary',
			'cartpops_color_typography_secondary'          => 'text_secondary',
			'cartpops_color_typography_tertiary'           => 'text_tertiary',
			'cartpops_color_border'                        => 'border',
			'cartpops_color_accent'                        => 'primary',
			'cartpops_color_input_background'              => 'input_bg',
			'cartpops_color_input_text'                    => 'input_text',
			'cartpops_color_button_primary_background'     => 'button_primary_bg',
			'cartpops_color_button_primary_text'           => 'button_primary_text',
			'cartpops_color_button_secondary_background'   => 'button_secondary_bg',
			'cartpops_color_button_secondary_text'         => 'button_secondary_text',
			'cartpops_color_button_quantity_background'    => 'quantity_button_bg',
			'cartpops_color_button_quantity_text'          => 'quantity_button_text',
			'cartpops_color_input_quantity_background'     => 'quantity_input_bg',
			'cartpops_color_input_quantity_border'         => 'quantity_input_border',
			'cartpops_color_input_quantity_text'           => 'quantity_input_text',
			'cartpops_color_recommendations_button_background' => 'recs_button_bg',
			'cartpops_color_recommendations_button_text'   => 'recs_button_text',
			'cartpops_color_recommendations_drawer_background' => 'recs_background',
			'cartpops_color_recommendations_drawer_border' => 'recs_border',
			'cartpops_color_recommendations_drawer_text'   => 'recs_text',
			'cartpops_color_state_success'                 => 'success',
			'cartpops_color_state_danger'                  => 'danger',
		);

		foreach ( $colors as $source => $target ) {
			$this->map_color( $mapped, $source, 'design.colors.' . $target );
			if ( 'cartpops_color_input_background' === $source ) {
				// V1 coupon and shipping inputs shared the generic border color.
				$this->map_color( $mapped, 'cartpops_color_border', 'design.colors.input_border' );
			}
		}

		if ( array_key_exists( 'cartpops_color_state_warning', $this->source_option_names ) ) {
			// V1 emitted a hard-coded warning color, so this saved option never affected customers.
			$this->mark_retired( 'cartpops_color_state_warning' );
			$this->warnings[] = 'legacy_noop_warning_color_retired';
		}
	}

	/**
	 * Map the V1 shipping-meter mode to V2's automatic/custom threshold.
	 *
	 * @param array<string, mixed> $mapped Settings being built.
	 */
	private function map_shipping_meter( array &$mapped ): void {
		$this->map_boolean( $mapped, 'cartpops_free_shipping_meter_enable', 'shipping_meter.enabled' );
		$this->map_string( $mapped, 'cartpops_free_shipping_meter_text_base', 'shipping_meter.message_remaining' );
		$this->map_string( $mapped, 'cartpops_free_shipping_meter_text_achieved', 'shipping_meter.message_qualified' );
		$this->map_color( $mapped, 'cartpops_color_free_shipping_meter_background', 'shipping_meter.colors.background' );
		$this->map_color( $mapped, 'cartpops_color_free_shipping_meter_background_active', 'shipping_meter.colors.background_qualified' );

		$type_source      = 'cartpops_free_shipping_meter_type';
		$threshold_source = 'cartpops_free_shipping_meter_custom_global';
		$type             = $this->source_values[ $type_source ] ?? null;

		if ( 'woocommerce_settings' === $type ) {
			$this->set_path( $mapped, 'shipping_meter.threshold', 0.0 );
			$this->mark_mapped( $type_source, 'shipping_meter.threshold' );
			return;
		}

		if ( 'custom' !== $type ) {
			if ( null !== $type ) {
				$this->warn_invalid( $type_source );
			}
			return;
		}

		$threshold = CanonicalDecimal::parse(
			$this->source_values[ $threshold_source ] ?? null,
			0.0,
			self::MAX_MONEY_VALUE
		);
		if ( null !== $threshold ) {
			$this->set_path( $mapped, 'shipping_meter.threshold', $threshold );
			$this->mark_mapped( $type_source, 'shipping_meter.threshold' );
			$this->mark_mapped( $threshold_source, 'shipping_meter.threshold' );
		} else {
			$this->warn_invalid( $threshold_source );
		}
	}

	/**
	 * Preserve the V1 secondary drawer action as dormant edition-neutral data.
	 *
	 * Proven V1 installs materialize `continue_shopping` when the mode row is
	 * missing or exactly the empty string. Unsafe custom fields become empty dormant
	 * values; their exact source bytes remain in the immutable snapshot.
	 *
	 * @param array<string, mixed> $mapped Settings being built.
	 */
	private function map_secondary_action( array &$mapped ): void {
		$mode_source = 'cartpops_drawer_footer_secondary_button';
		$mode        = $this->source_values[ $mode_source ] ?? SecondaryActionSettings::DEFAULT_MODE;
		if ( SecondaryActionSettings::mode_is_valid( $mode ) ) {
			$this->set_path( $mapped, 'secondary_action.mode', $mode );
			$this->mark_mapped( $mode_source, 'secondary_action.mode' );
		} else {
			$this->set_path( $mapped, 'secondary_action.mode', SecondaryActionSettings::DEFAULT_MODE );
			if ( array_key_exists( $mode_source, $this->source_values ) ) {
				$this->warn_invalid( $mode_source );
			}
		}

		$url_source = 'cartpops_drawer_footer_secondary_button_custom_url';
		$url        = $this->source_values[ $url_source ] ?? '';
		$clean_url  = '' === $url ? '' : SecondaryActionSettings::sanitize_custom_url_or_null( $url );
		$this->set_path( $mapped, 'secondary_action.custom_url', $clean_url ?? '' );
		if ( null !== $clean_url ) {
			$this->mark_mapped( $url_source, 'secondary_action.custom_url' );
		} elseif ( array_key_exists( $url_source, $this->source_values ) ) {
			$this->warn_invalid( $url_source );
		}

		$text_source = 'cartpops_drawer_footer_secondary_button_custom_text';
		$text        = $this->source_values[ $text_source ] ?? '';
		$clean_text  = '' === $text ? '' : SecondaryActionSettings::sanitize_custom_text_or_null( $text );
		$this->set_path( $mapped, 'secondary_action.custom_text', $clean_text ?? '' );
		if ( null !== $clean_text ) {
			$this->mark_mapped( $text_source, 'secondary_action.custom_text' );
		} elseif ( array_key_exists( $text_source, $this->source_values ) ) {
			$this->warn_invalid( $text_source );
		}
	}

	/**
	 * Map the V1 recommendation identifiers to their V2 equivalents.
	 *
	 * @param array<string, mixed> $mapped Settings being built.
	 */
	private function map_recommendation_strategy( array &$mapped ): void {
		$source = 'cartpops_product_recommendation_engine_type';
		$value  = $this->source_values[ $source ] ?? null;
		if ( ! is_string( $value ) ) {
			return;
		}

		$strategy = match ( $value ) {
			'cross_sells' => 'cross_sell',
			'upsells'     => 'upsell',
			'custom'      => 'custom',
			default       => null,
		};

		if ( null !== $strategy ) {
			$this->set_path( $mapped, 'recommendations.strategy', $strategy );
			$this->mark_mapped( $source, 'recommendations.strategy' );
		}
	}

	/**
	 * Map the V1 global selection by value order; historical sparse keys do not
	 * carry semantics and the immutable snapshot retains their exact bytes.
	 *
	 * @param array<string, mixed> $mapped Settings being built.
	 */
	private function map_custom_recommendation_products( array &$mapped ): void {
		$source = 'cartpops_product_recommendation_engine_custom_global';
		if ( ! array_key_exists( $source, $this->source_values ) ) {
			return;
		}

		$value = $this->source_values[ $source ];
		if ( '' === $value ) {
			$value = array();
		}
		if ( ! is_array( $value ) || count( $value ) > 4 ) {
			$this->warn_invalid( $source );
			return;
		}

		$product_ids = array();
		$seen        = array();
		foreach ( $value as $candidate ) {
			$product_id = CanonicalInteger::parse( $candidate, 1 );
			if ( null === $product_id ) {
				$this->warn_invalid( $source );
				return;
			}
			if ( isset( $seen[ $product_id ] ) ) {
				continue;
			}
			$seen[ $product_id ] = true;
			$product_ids[]       = $product_id;
		}

		$this->set_path( $mapped, 'recommendations.custom_product_ids', $product_ids );
		$this->mark_mapped( $source, 'recommendations.custom_product_ids' );
	}

	/**
	 * Preserve V1's paid add-button presentation without treating migration data
	 * as current runtime entitlement.
	 *
	 * @param array<string, mixed> $mapped Settings being built.
	 */
	private function map_recommendation_button_presentation( array &$mapped ): void {
		$type_source = 'cartpops_product_recommendation_engine_button_type';
		$type        = $this->source_values[ $type_source ] ?? RecommendationButtonPresentation::DEFAULT_MODE;
		if ( RecommendationButtonPresentation::mode_is_valid( $type ) ) {
			$this->set_path( $mapped, 'recommendations.button_type', $type );
			$this->mark_mapped( $type_source, 'recommendations.button_type' );
		} else {
			$this->set_path( $mapped, 'recommendations.button_type', RecommendationButtonPresentation::DEFAULT_MODE );
			if ( array_key_exists( $type_source, $this->source_values ) ) {
				$this->warn_invalid( $type_source );
			}
		}

		$text_source = 'cartpops_product_recommendation_engine_button_text';
		$text        = RecommendationButtonPresentation::sanitize_text_or_null(
			$this->source_values[ $text_source ] ?? RecommendationButtonPresentation::DEFAULT_TEXT
		);
		if ( null !== $text ) {
			$this->set_path( $mapped, 'recommendations.button_text', $text );
			$this->mark_mapped( $text_source, 'recommendations.button_text' );
		} else {
			$this->set_path( $mapped, 'recommendations.button_text', RecommendationButtonPresentation::DEFAULT_TEXT );
			if ( array_key_exists( $text_source, $this->source_values ) ) {
				$this->warn_invalid( $text_source );
			}
		}
	}

	/** Whether retained V1 button bytes were unsafe or outside the closed mode set. */
	private function invalid_recommendation_button_requires_review(): bool {
		$type_source = 'cartpops_product_recommendation_engine_button_type';
		if (
			array_key_exists( $type_source, $this->source_values )
			&& ! RecommendationButtonPresentation::mode_is_valid( $this->source_values[ $type_source ] )
		) {
			return true;
		}

		$text_source = 'cartpops_product_recommendation_engine_button_text';
		return array_key_exists( $text_source, $this->source_values )
			&& null === RecommendationButtonPresentation::sanitize_text_or_null( $this->source_values[ $text_source ] );
	}

	/**
	 * Map V1 recommendation fallback identifiers to supported V2 strategies.
	 *
	 * @param array<string, mixed> $mapped Settings being built.
	 */
	private function map_recommendation_fallback( array &$mapped ): void {
		$source = 'cartpops_product_recommendation_engine_fallback';
		$value  = $this->source_values[ $source ] ?? null;
		if ( ! is_string( $value ) ) {
			return;
		}

		$fallback = match ( $value ) {
			'hide'            => 'none',
			'upsells'         => 'upsell',
			'cross_sells'     => 'cross_sell',
			'random_products' => 'random',
			default           => null,
		};

		if ( null === $fallback ) {
			$this->warn_invalid( $source );
			return;
		}

		$this->set_path( $mapped, 'recommendations.fallback', $fallback );
		$this->mark_mapped( $source, 'recommendations.fallback' );
	}

	/**
	 * Map a complete, inert legacy CSS color.
	 *
	 * @param array<string, mixed> $mapped Settings being built.
	 * @param string               $source Legacy option name.
	 * @param string               $target V2 dot-path.
	 */
	private function map_color( array &$mapped, string $source, string $target ): void {
		$value = $this->source_values[ $source ] ?? null;
		$value = is_string( $value ) ? $this->normalize_css_color( $value ) : null;
		if ( null === $value ) {
			if ( array_key_exists( $source, $this->source_values ) ) {
				$this->warn_invalid( $source );
			}
			return;
		}

		$this->set_path( $mapped, $target, $value );
		$this->mark_mapped( $source, $target );
	}

	/**
	 * Normalize the bounded color grammar accepted by the V2 settings boundary.
	 *
	 * @param string $value Candidate legacy color.
	 */
	private function normalize_css_color( string $value ): ?string {
		$value = trim( $value );
		if ( strlen( $value ) > 64 ) {
			return null;
		}
		if ( 1 === preg_match( '/^#(?:[a-f0-9]{3}|[a-f0-9]{6})$/iD', $value ) ) {
			return $value;
		}
		if ( 1 === preg_match( '/^rgb\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*\)$/iD', $value, $matches ) ) {
			$channels = array_map( 'intval', array_slice( $matches, 1, 3 ) );
			return max( $channels ) <= 255 ? sprintf( 'rgb(%d, %d, %d)', ...$channels ) : null;
		}
		if ( 1 !== preg_match( '/^rgba\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*((?:0(?:\.[0-9]+)?|1(?:\.0+)?|\.[0-9]+))\s*\)$/iD', $value, $matches ) ) {
			return null;
		}

		$channels = array_map( 'intval', array_slice( $matches, 1, 3 ) );
		$alpha    = (float) $matches[4];
		if ( max( $channels ) > 255 || ! is_finite( $alpha ) || $alpha < 0 || $alpha > 1 ) {
			return null;
		}

		$alpha_string = rtrim( rtrim( number_format( $alpha, 6, '.', '' ), '0' ), '.' );
		return sprintf( 'rgba(%d, %d, %d, %s)', $channels[0], $channels[1], $channels[2], $alpha_string );
	}

	/**
	 * Split the V1 rgba overlay into V2's semantic overlay fields.
	 *
	 * @param array<string, mixed> $mapped Settings being built.
	 */
	private function map_overlay( array &$mapped ): void {
		$source = 'cartpops_color_overlay';
		$value  = $this->source_values[ $source ] ?? null;
		if ( ! is_string( $value ) ) {
			return;
		}

		$normalized = $this->normalize_css_color( $value );
		if ( null === $normalized ) {
			$this->warn_invalid( $source );
			return;
		}

		if ( str_starts_with( $normalized, '#' ) ) {
			$hex = 4 === strlen( $normalized )
				? sprintf( '#%1$s%1$s%2$s%2$s%3$s%3$s', $normalized[1], $normalized[2], $normalized[3] )
				: $normalized;
			$this->set_path( $mapped, 'design.overlay_color', $hex );
			$this->set_path( $mapped, 'design.overlay_opacity', 100 );
			$this->set_path( $mapped, 'design.colors.overlay', $normalized );
			$this->mark_mapped( $source, 'design.overlay_color' );
			$this->mark_mapped( $source, 'design.overlay_opacity' );
			$this->mark_mapped( $source, 'design.colors.overlay' );
			return;
		}

		$parsed = $this->parse_overlay_color( $normalized );
		if ( null === $parsed ) {
			$this->warn_invalid( $source );
			return;
		}

		$this->set_path( $mapped, 'design.overlay_color', sprintf( '#%02x%02x%02x', $parsed['red'], $parsed['green'], $parsed['blue'] ) );
		$this->set_path( $mapped, 'design.overlay_opacity', $parsed['opacity'] );
		$this->set_path( $mapped, 'design.colors.overlay', $normalized );
		$this->mark_mapped( $source, 'design.overlay_color' );
		$this->mark_mapped( $source, 'design.overlay_opacity' );
		$this->mark_mapped( $source, 'design.colors.overlay' );
	}

	/**
	 * Parse an rgb/rgba color without clamping executable channel values.
	 *
	 * @param mixed $value Candidate CSS color.
	 * @return array{red: int, green: int, blue: int, opacity: int|float}|null
	 */
	private function parse_overlay_color( mixed $value ): ?array {
		if (
			! is_string( $value )
			|| 1 !== preg_match( '/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})(?:\s*,\s*(0(?:\.\d+)?|1(?:\.0+)?))?\s*\)$/iD', $value, $matches )
		) {
			return null;
		}

		$red   = (int) $matches[1];
		$green = (int) $matches[2];
		$blue  = (int) $matches[3];
		if ( $red > 255 || $green > 255 || $blue > 255 ) {
			return null;
		}

		$opacity = isset( $matches[4] ) && '' !== $matches[4]
			? round( (float) $matches[4] * 100, 3 )
			: 100;
		if ( floor( $opacity ) === $opacity ) {
			$opacity = (int) $opacity;
		}

		return array(
			'red'     => $red,
			'green'   => $green,
			'blue'    => $blue,
			'opacity' => $opacity,
		);
	}

	/**
	 * Record a source-to-target mapping without recording its value.
	 *
	 * @param string $source Legacy option name.
	 * @param string $target V2 dot-path.
	 */
	private function mark_mapped( string $source, string $target ): void {
		if ( ! isset( $this->source_option_names[ $source ] ) ) {
			return;
		}

		if ( ! isset( $this->dispositions[ $source ] ) ) {
			$this->dispositions[ $source ] = array(
				'status'  => 'mapped',
				'targets' => array(),
			);
		}

		$this->dispositions[ $source ]['targets'][] = $target;
	}

	/**
	 * Record one source whose behavior was deliberately retired for V2.
	 *
	 * @param string $source Legacy option name.
	 */
	private function mark_retired( string $source ): void {
		if ( ! isset( $this->source_option_names[ $source ] ) ) {
			return;
		}

		$this->dispositions[ $source ] = array(
			'status'  => 'retired',
			'targets' => array(),
		);
	}

	/**
	 * Record a value-free invalid-source warning.
	 *
	 * @param string $source Legacy option name.
	 */
	private function warn_invalid( string $source ): void {
		$this->warnings[] = $this->source_warning( 'invalid', $source, 'invalid_legacy_option_retained' );
	}

	/**
	 * Build a bounded warning without making a maximum-length source key render
	 * an otherwise valid value-free journal impossible to persist.
	 *
	 * @param string $prefix   Warning family.
	 * @param string $source   Canonical source option key.
	 * @param string $fallback Stable warning when the expanded form is too long.
	 */
	private function source_warning( string $prefix, string $source, string $fallback ): string {
		$warning = $prefix . '_' . $source;
		if ( 'invalid' !== $prefix ) {
			$warning .= '_retained';
		}

		return strlen( $warning ) <= LegacyOptionKey::MAX_BYTES ? $warning : $fallback;
	}

	/**
	 * Normalize the checkbox encodings emitted by historical CartPops versions.
	 *
	 * @param mixed $value Raw legacy value.
	 */
	private function normalize_boolean( mixed $value ): ?bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( 1 === $value || 0 === $value ) {
			return 1 === $value;
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		return match ( strtolower( trim( $value ) ) ) {
			'on', '1', 'yes', 'true'      => true,
			'', 'off', '0', 'no', 'false' => false,
			default                        => null,
		};
	}

	/**
	 * Merge mapped defaults with existing raw V2 settings taking precedence.
	 *
	 * Sequential arrays are atomic and are never recursively merged.
	 *
	 * @param array<string, mixed> $mapped   Mapped V1 settings.
	 * @param array<string, mixed> $existing Existing V2 settings.
	 * @return array<string, mixed>
	 */
	private function merge_existing_over_mapped( array $mapped, array $existing ): array {
		$merged = $mapped;

		foreach ( $existing as $key => $value ) {
			if (
				isset( $merged[ $key ] )
				&& is_array( $merged[ $key ] )
				&& is_array( $value )
				&& ! array_is_list( $merged[ $key ] )
				&& ( array() === $value || ! array_is_list( $value ) )
			) {
				$merged[ $key ] = $this->merge_existing_over_mapped( $merged[ $key ], $value );
				continue;
			}

			$merged[ $key ] = $value;
		}

		return $merged;
	}

	/**
	 * Set a nested array value using a dot-separated path.
	 *
	 * @param array<string, mixed> $settings Settings being built.
	 * @param string               $path     Dot-separated path.
	 * @param mixed                $value    Value to store.
	 */
	private function set_path( array &$settings, string $path, mixed $value ): void {
		$segments = explode( '.', $path );
		$current  = &$settings;

		foreach ( array_slice( $segments, 0, -1 ) as $segment ) {
			if ( ! isset( $current[ $segment ] ) || ! is_array( $current[ $segment ] ) ) {
				$current[ $segment ] = array();
			}
			$current = &$current[ $segment ];
		}

		$current[ end( $segments ) ] = $value;
	}
}
