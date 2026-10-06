<?php
/**
 * CartPops section for Tools → Site Health → Info.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Setup;

/**
 * Build the Site Health "CartPops" section from codes and counts only.
 *
 * Site Health's "Copy site info to clipboard" includes this section, so it is
 * what store owners send when support asks for details. Every value is a
 * code-defined token, a version or a count: no setting values, custom
 * JavaScript, licence or account data ever reach it.
 */
final class SiteHealthSection {
	private const OPTION_DB_VERSION          = 'cartpops_db_version';
	private const OPTION_NORMALIZATION       = 'cartpops_settings_normalization_version';
	private const OPTION_MIGRATION_COMPLETED = 'cartpops_v1_migration_version';
	private const OPTION_MAINTENANCE         = 'cartpops_v1_migration_maintenance_v1';

	/** Update records whose presence, never contents, explains which path ran. */
	private const UPDATE_RECORDS = array(
		'cartpops_v1_migration_version'           => 'completion',
		'cartpops_v2_install_provenance_v1'       => 'provenance',
		'cartpops_v1_pre_sdk_evidence_v1'         => 'pre_sdk_evidence',
		'cartpops_v1_migration_snapshot_v1'       => 'snapshot',
		'cartpops_v1_migration_journal'           => 'journal',
		'cartpops_v1_migration_maintenance_v1'    => 'maintenance',
		'cartpops_v1_migration_lock_v1'           => 'lock',
		'cartpops_v2_migration_recovery_v1'       => 'recovery',
		'cartpops_v1_custom_js_quarantine_v1'     => 'custom_js_quarantine',
		'cartpops_v1_custom_js_review_v1'         => 'custom_js_review',
		'cartpops_v1_paid_rules_disposition_v1'   => 'compatibility',
		'cartpops_v1_rules_retirement_v1'         => 'rules_retirement',
		'cartpops_settings_normalization_version' => 'settings_format',
		'cartpops_settings_pre_normalization_v1'  => 'settings_format_recovery',
	);

	/**
	 * Build the section.
	 *
	 * @param array<string, mixed> $context Runtime state: version, edition, running, pause_code,
	 *                                       failure_step, migration (status array) and network
	 *                                       (status array or null).
	 * @return array{label: string, description: string, fields: array<string, array{label: string, value: string}>}
	 */
	public static function build( array $context ): array {
		$migration = $context['migration'];
		$fields    = array(
			'version'      => self::field( __( 'Version', 'cartpops' ), self::version( $context['version'] ) ),
			'edition'      => self::field( __( 'Edition', 'cartpops' ), self::code( $context['edition'] ) ),
			'status'       => self::field(
				__( 'Status', 'cartpops' ),
				$context['running'] ? __( 'Running', 'cartpops' ) : __( 'Paused', 'cartpops' )
			),
			'pause_code'   => self::field( __( 'Notice code', 'cartpops' ), self::code( $context['pause_code'] ) ),
			'failure_step' => self::field( __( 'Failed update step', 'cartpops' ), self::code( $context['failure_step'] ) ),
			'db_version'   => self::field( __( 'Recorded plugin version', 'cartpops' ), self::version( get_option( self::OPTION_DB_VERSION, '' ) ) ),
			'settings_fmt' => self::field( __( 'Settings format', 'cartpops' ), self::normalization_version() ),
			'v1_state'     => self::field(
				__( 'Update from version 1', 'cartpops' ),
				self::code( $migration['state'] ?? '' ) . ' / ' . self::code( $migration['phase'] ?? '' )
			),
			'v1_completed' => self::field(
				__( 'Update from version 1 completed', 'cartpops' ),
				false === get_option( self::OPTION_MIGRATION_COMPLETED, false ) ? __( 'No', 'cartpops' ) : __( 'Yes', 'cartpops' )
			),
			'v1_counts'    => self::field(
				__( 'Version 1 settings found / carried over / kept aside', 'cartpops' ),
				self::count( $migration['source_count'] ?? 0 ) . ' / ' . self::count( $migration['mapped_count'] ?? 0 ) . ' / ' . self::count( $migration['retained_count'] ?? 0 )
			),
			'v1_warnings'  => self::field( __( 'Update warnings', 'cartpops' ), self::codes( $migration['warnings'] ?? array() ) ),
			'v1_unmapped'  => self::field( __( 'Settings not carried over', 'cartpops' ), self::codes( $migration['unmapped_keys'] ?? array() ) ),
			'maintenance'  => self::field( __( 'Update paused for', 'cartpops' ), self::maintenance_reason() ),
			'records'      => self::field( __( 'Update records present', 'cartpops' ), self::update_records() ),
			'db_engines'   => self::field( __( 'Database engine (options / posts / cart sessions / user meta)', 'cartpops' ), self::table_engines() ),
			'object_cache' => self::field(
				__( 'Persistent object cache', 'cartpops' ),
				wp_using_ext_object_cache() ? __( 'Yes', 'cartpops' ) : __( 'No', 'cartpops' )
			),
		);
		if ( null !== $context['network'] ) {
			$fields['network'] = self::field(
				__( 'Network update', 'cartpops' ),
				self::code( $context['network']['status'] ?? '' ) . ' / ' . self::code( $context['network']['warning'] ?? '' )
			);
		}

		return array(
			'label'       => 'CartPops',
			'description' => __( 'Status of CartPops and its update from version 1. Contains codes and counts only, no settings or customer data.', 'cartpops' ),
			'fields'      => $fields,
		);
	}

	/**
	 * One Site Health field.
	 *
	 * @param string $label Field label.
	 * @param string $value Field value.
	 * @return array{label: string, value: string}
	 */
	private static function field( string $label, string $value ): array {
		return array(
			'label' => $label,
			'value' => '' === $value ? '—' : $value,
		);
	}

	/**
	 * A code-defined token, or a placeholder for anything else.
	 *
	 * @param mixed $value Candidate code.
	 */
	private static function code( mixed $value ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}
		return 1 === preg_match( '/\A[a-z0-9_.\-]{1,96}\z/D', $value ) ? $value : 'unrecognised';
	}

	/**
	 * A list of code-defined tokens.
	 *
	 * @param mixed $values Candidate codes.
	 */
	private static function codes( mixed $values ): string {
		if ( ! is_array( $values ) ) {
			return '';
		}
		$codes = array();
		foreach ( array_slice( $values, 0, 50 ) as $value ) {
			$code = self::code( $value );
			if ( '' !== $code ) {
				$codes[] = $code;
			}
		}
		return implode( ', ', $codes );
	}

	/**
	 * A plugin version, or a placeholder for anything else.
	 *
	 * @param mixed $value Candidate version.
	 */
	private static function version( mixed $value ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}
		return 1 === preg_match( '/\A[0-9A-Za-z.\-+]{1,64}\z/D', $value ) ? $value : 'unrecognised';
	}

	/**
	 * A non-negative count.
	 *
	 * @param mixed $value Candidate count.
	 */
	private static function count( mixed $value ): string {
		return is_int( $value ) && $value >= 0 ? (string) $value : '0';
	}

	/** The completed settings-format schema, if recorded. */
	private static function normalization_version(): string {
		$marker = get_option( self::OPTION_NORMALIZATION, null );
		if ( null === $marker ) {
			return '';
		}
		return is_array( $marker ) && is_int( $marker['schema_version'] ?? null )
			? (string) $marker['schema_version']
			: 'unrecognised';
	}

	/** Storage engines of the tables the update and the cart lock; MyISAM cannot do either. */
	private static function table_engines(): string {
		$engines = array_map( array( self::class, 'code' ), DatabaseEngines::read() );
		return array() === array_filter( $engines ) ? '' : implode( ' / ', $engines );
	}

	/** Which update records exist, by fixed name only. */
	private static function update_records(): string {
		$present = array();
		foreach ( self::UPDATE_RECORDS as $option => $name ) {
			if ( false !== get_option( $option, false ) ) {
				$present[] = $name;
			}
		}
		return implode( ', ', $present );
	}

	/** Why the update from version 1 is waiting, if a marker exists. */
	private static function maintenance_reason(): string {
		$marker = get_option( self::OPTION_MAINTENANCE, null );
		if ( null === $marker ) {
			return '';
		}
		return is_array( $marker ) ? self::code( $marker['reason'] ?? '' ) : 'unrecognised';
	}
}
