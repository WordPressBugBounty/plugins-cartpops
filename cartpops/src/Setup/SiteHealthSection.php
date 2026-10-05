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

	/** Why the update from version 1 is waiting, if a marker exists. */
	private static function maintenance_reason(): string {
		$marker = get_option( self::OPTION_MAINTENANCE, null );
		if ( null === $marker ) {
			return '';
		}
		return is_array( $marker ) ? self::code( $marker['reason'] ?? '' ) : 'unrecognised';
	}
}
