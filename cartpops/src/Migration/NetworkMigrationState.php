<?php
/**
 * Strict value object validation for network migration progress.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Migration;

/**
 * Classifies persisted network state without permissive coercion.
 */
final class NetworkMigrationState {

	public const SCHEMA_VERSION = 1;

	/**
	 * Classify raw network-state bytes.
	 *
	 * @param string $raw        Exact database bytes.
	 * @param int    $network_id Expected network ID.
	 * @return array{kind: string, state: array<string, mixed>}
	 */
	public static function classify( string $raw, int $network_id ): array {
		$decoded = ( new SafeSerializedReader() )->decode( $raw, 65536, 16, 256 );
		if ( ! $decoded['safe'] || ! is_array( $decoded['value'] ) ) {
			return array(
				'kind'  => 'corrupt',
				'state' => array(),
			);
		}

		$state  = $decoded['value'];
		$schema = CanonicalInteger::parse( $state['schema_version'] ?? null, 1 );
		if ( null !== $schema && $schema > self::SCHEMA_VERSION ) {
			return array(
				'kind'  => 'future',
				'state' => array(),
			);
		}
		if ( self::SCHEMA_VERSION !== $schema || ! self::is_current_valid( $state, $network_id ) ) {
			return array(
				'kind'  => 'corrupt',
				'state' => array(),
			);
		}

		return array(
			'kind'  => 'current',
			'state' => $state,
		);
	}

	/**
	 * Create the first owned state for a frozen cohort.
	 *
	 * @param int    $network_id      Current network ID.
	 * @param int    $max_site_id     Frozen site high-water ID.
	 * @param string $owner           Value-free owner token.
	 * @param int    $lease_expires_at Lease expiry timestamp.
	 * @return array<string, mixed>
	 */
	public static function initial( int $network_id, int $max_site_id, string $owner, int $lease_expires_at ): array {
		return array(
			'schema_version'   => self::SCHEMA_VERSION,
			'network_id'       => $network_id,
			'max_site_id'      => $max_site_id,
			'last_site_id'     => 0,
			'status'           => 'in_progress',
			'failed_site_id'   => 0,
			'warning'          => '',
			'owner'            => $owner,
			'generation'       => 1,
			'lease_expires_at' => $lease_expires_at,
		);
	}

	/**
	 * Validate the exact current schema.
	 *
	 * @param array<string, mixed> $state      Candidate state.
	 * @param int                  $network_id Expected network ID.
	 */
	private static function is_current_valid( array $state, int $network_id ): bool {
		$expected_keys = array(
			'schema_version',
			'network_id',
			'max_site_id',
			'last_site_id',
			'status',
			'failed_site_id',
			'warning',
			'owner',
			'generation',
			'lease_expires_at',
		);
		$keys          = array_keys( $state );
		sort( $keys, SORT_STRING );
		sort( $expected_keys, SORT_STRING );
		if ( $keys !== $expected_keys ) {
			return false;
		}

		$stored_network = CanonicalInteger::parse( $state['network_id'], 1 );
		$maximum        = CanonicalInteger::parse( $state['max_site_id'], 1 );
		$cursor         = CanonicalInteger::parse( $state['last_site_id'] );
		$failed_site    = CanonicalInteger::parse( $state['failed_site_id'] );
		$generation     = CanonicalInteger::parse( $state['generation'], 1 );
		$lease          = CanonicalInteger::parse( $state['lease_expires_at'] );
		$status         = $state['status'];
		$warning        = $state['warning'];
		$owner          = $state['owner'];
		if (
			$network_id !== $stored_network
			|| null === $maximum
			|| null === $cursor
			|| null === $failed_site
			|| null === $generation
			|| null === $lease
			|| $cursor > $maximum
			|| $failed_site > $maximum
			|| ! is_string( $status )
			|| ! in_array( $status, array( 'in_progress', 'failed', 'complete' ), true )
			|| ! is_string( $warning )
			|| ! is_string( $owner )
			|| ( '' !== $owner && 1 !== preg_match( '/^[A-Za-z0-9_-]{1,128}$/D', $owner ) )
		) {
			return false;
		}

		if ( '' === $owner xor 0 === $lease ) {
			return false;
		}
		if ( 'failed' === $status ) {
			return '' === $owner
				&& in_array( $warning, array( 'site_upgrade_failed', 'network_site_query_failed', 'network_state_write_failed' ), true );
		}
		if ( 0 !== $failed_site || '' !== $warning ) {
			return false;
		}

		return 'complete' !== $status || '' === $owner;
	}
}
