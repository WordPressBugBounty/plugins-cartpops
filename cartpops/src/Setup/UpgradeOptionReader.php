<?php
/**
 * Exact option reads for the bounded upgrade hot path.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Setup;

use CartPops\Migration\CanonicalInteger;
use CartPops\Migration\MigrationDatabaseState;

/**
 * Combines only fixed shared-upgrade option sets into exact uncached reads.
 */
final class UpgradeOptionReader {

	/**
	 * Exact request-local site/database binding.
	 *
	 * @var SiteUpgradeContext
	 */
	private readonly SiteUpgradeContext $context;

	private const VERSION_OPTION = 'cartpops_db_version';

	private const NORMALIZATION_PAIR = array(
		'cartpops_settings_normalization_version',
		'cartpops_settings',
	);

	/** Maximum exact settings bytes copied out of LONGTEXT for normalization. */
	private const MAX_NORMALIZATION_BYTES = 4194304;

	/**
	 * Bind every read to one captured current site.
	 *
	 * @param SiteUpgradeContext|null $context Existing whole-convergence binding.
	 */
	public function __construct( ?SiteUpgradeContext $context = null ) {
		$this->context = $context ?? SiteUpgradeContext::capture();
	}

	/**
	 * Read only the shared DB-version marker.
	 *
	 * @return array{success: bool, row: array{success: bool, exists: bool, raw_value: string, autoload: string}}
	 */
	public function read_version(): array {
		$result = $this->read_names( array( self::VERSION_OPTION ) );

		return array(
			'success' => $result['success'],
			'row'     => $result['rows'][ self::VERSION_OPTION ],
		);
	}

	/**
	 * Read the settings-normalization marker and settings bytes together.
	 *
	 * @return array{success: bool, rows: array<string, array{success: bool, exists: bool, raw_value: string, autoload: string}>}
	 */
	public function read_normalization_and_settings(): array {
		return $this->read_names( self::NORMALIZATION_PAIR );
	}

	/**
	 * Execute one closed option query.
	 *
	 * @param string[] $names Exact allowlisted option pair.
	 * @return array{success: bool, rows: array<string, array{success: bool, exists: bool, raw_value: string, autoload: string}>}
	 */
	private function read_names( array $names ): array {
		$wpdb  = $this->context->database();
		$table = $this->context->options_table();

		if (
			! in_array( $names, array( array( self::VERSION_OPTION ), self::NORMALIZATION_PAIR ), true )
			|| ! method_exists( $wpdb, 'get_results' )
			|| ! method_exists( $wpdb, 'prepare' )
		) {
			return $this->failed( $names );
		}
		/**
		 * WordPress database adapter.
		 *
		 * @var \wpdb $wpdb
		 */
		$bounded = self::NORMALIZATION_PAIR === $names;
		$results = $this->context->guard(
			static function () use ( $wpdb, $table, $names ): mixed {
				$wpdb->last_error = '';
				if ( array( self::VERSION_OPTION ) === $names ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The immutable context table is validated; the value uses a placeholder.
					$sql = $wpdb->prepare( "SELECT option_name, option_value, autoload, 0 AS cartpops_upgrade_option_probe FROM {$table} WHERE option_name = %s ORDER BY option_name ASC LIMIT 2", $names[0] );
				} else {
					// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The immutable context table is validated; the bound and both values use placeholders.
					$sql = $wpdb->prepare(
						"SELECT option_name, CASE WHEN OCTET_LENGTH(option_value) <= %d THEN option_value ELSE NULL END AS option_value, OCTET_LENGTH(option_value) AS cartpops_upgrade_option_bytes, autoload, 0 AS cartpops_upgrade_option_probe FROM {$table} WHERE option_name IN (%s, %s) ORDER BY option_name ASC LIMIT 3",
						self::MAX_NORMALIZATION_BYTES,
						$names[0],
						$names[1]
					);
					// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Exact uncached control bytes are required for fail-closed classification.
				return $wpdb->get_results( $sql, ARRAY_A );
			}
		);
		if ( '' !== MigrationDatabaseState::last_error( $wpdb ) || ! is_array( $results ) || count( $results ) > count( $names ) ) {
			return $this->failed( $names );
		}

		$rows    = array_fill_keys( $names, $this->missing() );
		$allowed = array_fill_keys( $names, true );
		$seen    = array();
		foreach ( $results as $result ) {
			$expected_keys = $bounded
				? array( 'autoload', 'cartpops_upgrade_option_bytes', 'cartpops_upgrade_option_probe', 'option_name', 'option_value' )
				: array( 'autoload', 'cartpops_upgrade_option_probe', 'option_name', 'option_value' );
			$byte_length   = $bounded && is_array( $result )
				? CanonicalInteger::parse( $result['cartpops_upgrade_option_bytes'] ?? null, 0, self::MAX_NORMALIZATION_BYTES )
				: null;
			if (
				! is_array( $result )
				|| $expected_keys !== $this->sorted_keys( $result )
				|| ! is_string( $result['option_name'] ?? null )
				|| ! isset( $allowed[ $result['option_name'] ] )
				|| isset( $seen[ $result['option_name'] ] )
				|| ! is_string( $result['option_value'] ?? null )
				|| ! is_string( $result['autoload'] ?? null )
				|| 0 !== CanonicalInteger::parse( $result['cartpops_upgrade_option_probe'] ?? null, 0, 0 )
				|| ( $bounded && ( null === $byte_length || strlen( $result['option_value'] ) !== $byte_length ) )
			) {
				return $this->failed( $names );
			}
			$seen[ $result['option_name'] ] = true;
			$rows[ $result['option_name'] ] = array(
				'success'   => true,
				'exists'    => true,
				'raw_value' => $result['option_value'],
				'autoload'  => $result['autoload'],
			);
		}

		return array(
			'success' => true,
			'rows'    => $rows,
		);
	}

	/**
	 * Return one successful missing-row representation.
	 *
	 * @return array{success: bool, exists: bool, raw_value: string, autoload: string}
	 */
	private function missing(): array {
		return array(
			'success'   => true,
			'exists'    => false,
			'raw_value' => '',
			'autoload'  => '',
		);
	}

	/**
	 * Return one fail-closed pair result.
	 *
	 * @param string[] $names Requested option pair.
	 * @return array{success: bool, rows: array<string, array{success: bool, exists: bool, raw_value: string, autoload: string}>}
	 */
	private function failed( array $names ): array {
		$row            = $this->missing();
		$row['success'] = false;
		return array(
			'success' => false,
			'rows'    => array_fill_keys( $names, $row ),
		);
	}

	/**
	 * Return stable keys for closed-schema validation.
	 *
	 * @param array<mixed> $value Candidate database row.
	 * @return array<int|string>
	 */
	private function sorted_keys( array $value ): array {
		$keys = array_keys( $value );
		sort( $keys, SORT_STRING );
		return $keys;
	}
}
