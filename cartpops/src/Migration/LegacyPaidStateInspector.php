<?php
/**
 * Bounded, read-only detection of retained V1 paid customer state.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Migration;

use CartPops\Admin\SettingsRepository;
use CartPops\Setup\SiteUpgradeContext;

/**
 * Interprets only the minimum legacy identity and setting fields needed to
 * prevent a terminal migration from hiding non-runnable paid behavior.
 */
final class LegacyPaidStateInspector {

	/**
	 * Exact request-local site/database binding.
	 *
	 * @var SiteUpgradeContext
	 */
	private readonly SiteUpgradeContext $context;

	private const MAX_RAW_BYTES            = 1048576;
	private const MAX_TOTAL_BYTES          = 2097152;
	private const MAX_DEPTH                = 64;
	private const MAX_NODES                = 10000;
	private const PROBE_VALUE              = 1;
	private const PROBE_ALIAS              = 'cartpops_paid_state_probe';
	private const LENGTH_ALIAS             = 'cartpops_paid_state_length';
	private const FREEMIUS_OPTION          = 'fs_accounts';
	private const TOTAL_VISIBILITY_OPTIONS = array(
		'cartpops_drawer_footer_display_subtotal',
		'cartpops_drawer_footer_display_discount',
		'cartpops_drawer_footer_display_shipping',
		'cartpops_drawer_footer_display_tax',
		'cartpops_drawer_footer_display_total',
	);
	private const LEGACY_PATHS             = array(
		'cartpops/cartpops.php',
		'cartpops-pro/cartpops.php',
	);
	private const PREMIUM_OPTIONS          = array(
		'cartpops_add_to_cart_trigger',
		'cartpops_drawer_footer_display_shipping_calculator',
		'cartpops_drawer_footer_secondary_button',
		'cartpops_drawer_footer_secondary_button_custom_url',
		'cartpops_drawer_footer_secondary_button_custom_text',
		'cartpops_product_recommendation_engine_type',
		'cartpops_product_recommendation_engine_custom_global',
		'cartpops_free_shipping_meter_enable',
		'cartpops_free_shipping_meter_type',
		'cartpops_free_shipping_meter_custom_global',
	);
	private const BEHAVIOR_OPTIONS         = array(
		'cartpops_custom_js',
		'cartpops_customize_white_space_text',
		'cartpops_drawer_footer_display_subtotal',
		'cartpops_drawer_footer_display_discount',
		'cartpops_drawer_footer_display_shipping',
		'cartpops_drawer_footer_display_tax',
		'cartpops_drawer_footer_display_total',
		'cartpops_floating_cart_launcher_enable',
		'cartpops_floating_cart_launcher_hide_pages',
		'cartpops_floating_cart_launcher_hide_indicator_empty',
		'cartpops_menu_cart_launcher_indicator_empty',
		'cartpops_menu_cart_launcher_subtotal',
		'cartpops_force_fragments_refresh',
		'cartpops_product_recommendation_engine_enable',
		'cartpops_product_recommendation_engine_fallback',
		'cartpops_product_recommendation_engine_button_type',
		'cartpops_product_recommendation_engine_button_text',
	);

	/**
	 * Bind every evidence read to one captured current site.
	 *
	 * @param SiteUpgradeContext|null $context Existing whole-convergence binding.
	 */
	public function __construct( ?SiteUpgradeContext $context = null ) {
		$this->context = $context ?? SiteUpgradeContext::capture();
	}

	/**
	 * Return the exact bounded option names participating in paid-state proof.
	 *
	 * @return string[]
	 */
	public static function option_names(): array {
		return array_values( array_unique( array_merge( self::PREMIUM_OPTIONS, self::BEHAVIOR_OPTIONS, array( self::FREEMIUS_OPTION ) ) ) );
	}

	/**
	 * Read and classify the current site's paid compatibility evidence.
	 *
	 * @return array{diagnostics: string[], raw_rows: array<string, string>}
	 * @throws MigrationReadException When the bounded exact read is uncertain.
	 */
	public function read(): array {
		$wpdb  = $this->context->database();
		$table = $this->context->options_table();

		if ( ! method_exists( $wpdb, 'get_results' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			throw new MigrationReadException( 'database_read_failed_legacy_paid_state' );
		}
		/**
		 * WordPress database adapter.
		 *
		 * @var \wpdb $wpdb
		 */
		$names          = self::option_names();
		$placeholders   = implode( ', ', array_fill( 0, count( $names ), '%s' ) );
		$length_query   = 'SELECT option_name, ' . MigrationDatabaseState::received_octet_length_sql( $wpdb, 'option_value' ) . ' AS byte_length, ' . self::PROBE_VALUE . ' AS ' . self::LENGTH_ALIAS . " FROM {$table} WHERE option_name IN ({$placeholders}) ORDER BY option_name ASC LIMIT " . ( count( $names ) + 1 );
		$length_results = $this->context->guard(
			static function () use ( $wpdb, $length_query, $names ): mixed {
				$wpdb->last_error = '';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Lengths are bounded before any Freemius/account bytes are copied into PHP memory.
				return $wpdb->get_results( $wpdb->prepare( $length_query, ...$names ), ARRAY_A );
			}
		);
		if ( '' !== MigrationDatabaseState::last_error( $wpdb ) || ! is_array( $length_results ) || count( $length_results ) > count( $names ) ) {
			throw new MigrationReadException( 'database_read_failed_legacy_paid_state' );
		}

		$allowed     = array_fill_keys( $names, true );
		$lengths     = array();
		$total_bytes = 0;
		foreach ( $length_results as $result ) {
			if (
				! is_array( $result )
				|| array( 'byte_length', self::LENGTH_ALIAS, 'option_name' ) !== $this->sorted_keys( $result )
				|| ! is_string( $result['option_name'] ?? null )
				|| self::PROBE_VALUE !== CanonicalInteger::parse( $result[ self::LENGTH_ALIAS ] ?? null, self::PROBE_VALUE, self::PROBE_VALUE )
				|| ! isset( $allowed[ $result['option_name'] ] )
				|| isset( $lengths[ $result['option_name'] ] )
			) {
				throw new MigrationReadException( 'invalid_legacy_paid_state_rows' );
			}
			$bytes = CanonicalInteger::parse( $result['byte_length'] ?? null, 0 );
			if ( null === $bytes ) {
				throw new MigrationReadException( 'invalid_legacy_paid_state_rows' );
			}
			if ( $bytes > self::MAX_RAW_BYTES || $total_bytes > self::MAX_TOTAL_BYTES - $bytes ) {
				return array(
					'diagnostics' => array( 'legacy_paid_state_uncertain' ),
					'raw_rows'    => array(),
				);
			}
			$total_bytes                      += $bytes;
			$lengths[ $result['option_name'] ] = $bytes;
		}
		if ( array() === $lengths ) {
			return array(
				'diagnostics' => array(),
				'raw_rows'    => array(),
			);
		}

		$bounded_names        = array_keys( $lengths );
		$bounded_placeholders = implode( ', ', array_fill( 0, count( $bounded_names ), '%s' ) );
		$query                = 'SELECT option_name, option_value, ' . self::PROBE_VALUE . ' AS ' . self::PROBE_ALIAS . " FROM {$table} WHERE option_name IN ({$bounded_placeholders}) ORDER BY option_name ASC LIMIT " . ( count( $bounded_names ) + 1 );
		$results              = $this->context->guard(
			static function () use ( $wpdb, $query, $bounded_names ): mixed {
				$wpdb->last_error = '';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Only rows whose exact lengths passed the bounded preflight are fetched.
				return $wpdb->get_results( $wpdb->prepare( $query, ...$bounded_names ), ARRAY_A );
			}
		);
		if ( '' !== MigrationDatabaseState::last_error( $wpdb ) || ! is_array( $results ) || count( $results ) !== count( $bounded_names ) ) {
			throw new MigrationReadException( 'database_read_failed_legacy_paid_state' );
		}
		$rows = array();
		foreach ( $results as $result ) {
			if (
				! is_array( $result )
				|| array( self::PROBE_ALIAS, 'option_name', 'option_value' ) !== $this->sorted_keys( $result )
				|| ! is_string( $result['option_name'] ?? null )
				|| ! is_string( $result['option_value'] ?? null )
				|| self::PROBE_VALUE !== CanonicalInteger::parse( $result[ self::PROBE_ALIAS ] ?? null, self::PROBE_VALUE, self::PROBE_VALUE )
				|| ! isset( $lengths[ $result['option_name'] ] )
				|| isset( $rows[ $result['option_name'] ] )
				|| strlen( $result['option_value'] ) !== $lengths[ $result['option_name'] ]
			) {
				throw new MigrationReadException( 'invalid_legacy_paid_state_rows' );
			}
			$rows[ $result['option_name'] ] = $result['option_value'];
		}

		return array(
			'diagnostics' => $this->inspect_raw_rows( $rows ),
			'raw_rows'    => $rows,
		);
	}

	/**
	 * Classify already exact raw option rows without returning any values.
	 *
	 * @param array<string, string> $rows Raw option bytes keyed by exact name.
	 * @return string[]
	 * @throws MigrationReadException When an option row is outside the closed schema.
	 * @throws MigrationMaintenanceException When aggregate retained data exceeds bounds.
	 */
	public function inspect_raw_rows( array $rows ): array {
		$diagnostics = array();
		$values      = array();
		$allowed     = array_fill_keys( self::option_names(), true );
		$total_bytes = 0;
		foreach ( $rows as $option => $raw ) {
			if ( ! is_string( $option ) || ! is_string( $raw ) || ! isset( $allowed[ $option ] ) ) {
				throw new MigrationReadException( 'invalid_legacy_paid_state_rows' );
			}
			$total_bytes += strlen( $raw );
			if ( strlen( $raw ) > self::MAX_RAW_BYTES || $total_bytes > self::MAX_TOTAL_BYTES ) {
				throw new MigrationMaintenanceException( 'legacy_paid_state_bounds_exceeded' );
			}
		}
		foreach ( array_merge( self::PREMIUM_OPTIONS, self::BEHAVIOR_OPTIONS ) as $option ) {
			if ( ! array_key_exists( $option, $rows ) ) {
				continue;
			}
			$decoded = $this->decode_option_value( $rows[ $option ] );
			if ( ! $decoded['safe'] ) {
				$diagnostics[] = 'legacy_retained_behavior_requires_review';
				if ( in_array( $option, self::PREMIUM_OPTIONS, true ) ) {
					$diagnostics[] = 'legacy_paid_state_uncertain';
				}
				continue;
			}
			$values[ $option ] = $decoded['value'];
		}

		$diagnostics = array_merge( $diagnostics, $this->paid_feature_diagnostics( $values ) );
		if ( $this->has_active_retained_behavior( $values ) ) {
			$diagnostics[] = 'legacy_retained_behavior_requires_review';
		}
		if ( $this->active( $values['cartpops_custom_js'] ?? null ) ) {
			$diagnostics[] = 'legacy_custom_js_requires_review';
		}

		if ( isset( $rows[ self::FREEMIUS_OPTION ] ) ) {
			$paid = $this->freemius_paid_identity( $rows[ self::FREEMIUS_OPTION ] );
			if ( true === $paid ) {
				$diagnostics[] = 'legacy_paid_entitlement_requires_adapter';
			} elseif ( null === $paid && str_contains( strtolower( $rows[ self::FREEMIUS_OPTION ] ), 'cartpops' ) ) {
				$diagnostics[] = 'legacy_paid_state_uncertain';
			}
		}

		$diagnostics = array_values( array_unique( $diagnostics ) );
		sort( $diagnostics, SORT_STRING );
		return $diagnostics;
	}

	/**
	 * Whether one diagnostic must stop before settings/quarantine preparation.
	 *
	 * @param string $diagnostic Value-free compatibility diagnostic.
	 */
	public static function is_hard_diagnostic( string $diagnostic ): bool {
		return in_array(
			$diagnostic,
			array(
				'legacy_paid_entitlement_requires_adapter',
				'legacy_paid_state_uncertain',
			),
			true
		);
	}

	/**
	 * Decode one exact option value without constructing object instances.
	 *
	 * @param string $raw Exact option bytes.
	 * @return array{safe: bool, value: mixed}
	 */
	private function decode_option_value( string $raw ): array {
		$decoded = ( new SafeSerializedReader() )->decode( $raw, self::MAX_RAW_BYTES, self::MAX_DEPTH, self::MAX_NODES );
		return array(
			'safe'  => $decoded['safe'],
			'value' => $decoded['value'],
		);
	}

	/**
	 * Classify paid settings that are not already mapped or deliberately retired.
	 *
	 * @param array<string, mixed> $values Decoded legacy option values.
	 * @return string[]
	 */
	private function paid_feature_diagnostics( array $values ): array {
		$diagnostics = array();
		if ( ! $this->paid_settings_are_recognized( $values ) ) {
			$diagnostics[] = 'legacy_paid_state_uncertain';
		}

		return $diagnostics;
	}

	/**
	 * Validate the closed set of mapped, retired, and retained paid values.
	 *
	 * @param array<string, mixed> $values Decoded legacy option values.
	 */
	private function paid_settings_are_recognized( array $values ): bool {
		if (
			! $this->select_is_recognized( $values, 'cartpops_add_to_cart_trigger', array( '', 'drawer', 'add_to_cart', 'popup', 'bar' ) )
			|| ! $this->boolean_is_recognized( $values, 'cartpops_drawer_footer_display_shipping_calculator' )
			|| ! $this->select_is_recognized( $values, 'cartpops_drawer_footer_secondary_button', array( '', 'none', 'continue_shopping', 'view_cart', 'custom_url' ) )
			|| ! $this->string_is_recognized( $values, 'cartpops_drawer_footer_secondary_button_custom_url' )
			|| ! $this->string_is_recognized( $values, 'cartpops_drawer_footer_secondary_button_custom_text' )
			|| ! $this->boolean_is_recognized( $values, 'cartpops_product_recommendation_engine_enable' )
			|| ! $this->select_is_recognized( $values, 'cartpops_product_recommendation_engine_type', array( '', 'upsells', 'cross_sells', 'custom' ) )
			|| ! $this->custom_recommendation_selection_is_recognized( $values )
			|| ! $this->boolean_is_recognized( $values, 'cartpops_free_shipping_meter_enable' )
			|| ! $this->select_is_recognized( $values, 'cartpops_free_shipping_meter_type', array( '', 'woocommerce_settings', 'custom' ) )
			|| ! $this->shipping_threshold_is_recognized( $values )
		) {
			return false;
		}

		return true;
	}

	/**
	 * Whether one exact select value is absent or belongs to its historical enum.
	 *
	 * @param array<string, mixed> $values  Decoded legacy option values.
	 * @param string               $option  Exact option name.
	 * @param string[]             $allowed Closed historical enum.
	 */
	private function select_is_recognized( array $values, string $option, array $allowed ): bool {
		return ! array_key_exists( $option, $values )
			|| ( is_string( $values[ $option ] ) && in_array( $values[ $option ], $allowed, true ) );
	}

	/**
	 * Whether one retired text field is absent or still a string.
	 *
	 * @param array<string, mixed> $values Decoded legacy option values.
	 * @param string               $option Exact option name.
	 */
	private function string_is_recognized( array $values, string $option ): bool {
		return ! array_key_exists( $option, $values ) || is_string( $values[ $option ] );
	}

	/**
	 * Validate historical checkbox encodings without coercing unknown values.
	 *
	 * @param array<string, mixed> $values Decoded legacy option values.
	 * @param string               $option Exact option name.
	 */
	private function boolean_is_recognized( array $values, string $option ): bool {
		if ( ! array_key_exists( $option, $values ) ) {
			return true;
		}
		$value = $values[ $option ];
		if ( is_bool( $value ) || 1 === $value || 0 === $value ) {
			return true;
		}

		return is_string( $value )
			&& in_array( strtolower( trim( $value ) ), array( '', 'on', 'off', '0', '1', 'yes', 'no', 'true', 'false' ), true );
	}

	/**
	 * Validate the bounded V1 custom-product selection without exposing IDs.
	 * V1 preserved array_filter() keys while its runtime consumed only values.
	 *
	 * @param array<string, mixed> $values Decoded legacy option values.
	 */
	private function custom_recommendation_selection_is_recognized( array $values ): bool {
		$option = 'cartpops_product_recommendation_engine_custom_global';
		if ( ! array_key_exists( $option, $values ) || '' === $values[ $option ] || array() === $values[ $option ] ) {
			return true;
		}
		$selection = $values[ $option ];
		if ( ! is_array( $selection ) || count( $selection ) > 4 ) {
			return false;
		}
		foreach ( $selection as $product_id ) {
			if ( null === CanonicalInteger::parse( $product_id, 1 ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate a custom threshold whenever the custom mode would consume it.
	 *
	 * @param array<string, mixed> $values Decoded legacy option values.
	 */
	private function shipping_threshold_is_recognized( array $values ): bool {
		$type      = $this->effective( $values, 'cartpops_free_shipping_meter_type', 'woocommerce_settings' );
		$option    = 'cartpops_free_shipping_meter_custom_global';
		$available = array_key_exists( $option, $values );
		if ( ! $available ) {
			return 'custom' !== $type;
		}
		$value = $values[ $option ];
		if ( '' === $value ) {
			return 'custom' !== $type;
		}

		return null !== CanonicalDecimal::parse( $value, 0.0, 1000000000.0 );
	}

	/**
	 * Detect active behavior that is preserved but not yet runnable in V2.
	 *
	 * @param array<string, mixed> $values Decoded legacy option values.
	 */
	private function has_active_retained_behavior( array $values ): bool {
		if (
			array_key_exists( 'cartpops_customize_white_space_text', $values )
			&& ! in_array(
				$this->effective( $values, 'cartpops_customize_white_space_text', 'break' ),
				array( 'nowrap', 'break' ),
				true
			)
		) {
			return true;
		}
		foreach ( self::TOTAL_VISIBILITY_OPTIONS as $option ) {
			if ( array_key_exists( $option, $values ) && ! $this->boolean_is_recognized( $values, $option ) ) {
				return true;
			}
		}
		if (
			$this->active( $values['cartpops_floating_cart_launcher_hide_pages'] ?? null )
			&& ! $this->launcher_hidden_pages_are_runnable( $values['cartpops_floating_cart_launcher_hide_pages'] )
		) {
			return true;
		}
		if (
			$this->active( $values['cartpops_force_fragments_refresh'] ?? null )
			&& ! $this->boolean_is_recognized( $values, 'cartpops_force_fragments_refresh' )
		) {
			return true;
		}
		return false;
	}

	/**
	 * Whether V1 stored its own settings-field definition as the page selector.
	 *
	 * Some V1 installs saved `{id, type, label, ...}` here instead of page IDs.
	 * V1 passed it to is_page(), which matched no page, so it hid nothing.
	 *
	 * @param mixed $value Decoded legacy option value.
	 */
	public static function is_hidden_pages_field_definition( mixed $value ): bool {
		return is_array( $value )
			&& ! array_is_list( $value )
			&& 'floating_cart_launcher_hide_pages' === ( $value['id'] ?? null )
			&& is_string( $value['type'] ?? null );
	}

	/**
	 * Whether the V1 page selector can be represented by V2 without loss.
	 *
	 * @param mixed $value Legacy option value.
	 */
	private function launcher_hidden_pages_are_runnable( mixed $value ): bool {
		if ( $this->legacy_empty( $value ) || self::is_hidden_pages_field_definition( $value ) ) {
			return true;
		}

		$candidates = is_array( $value ) ? array_values( $value ) : array( $value );
		if ( count( $candidates ) > SettingsRepository::MAX_HIDDEN_PAGE_IDS ) {
			return false;
		}
		foreach ( $candidates as $candidate ) {
			if ( null === CanonicalInteger::parse( $candidate, 1 ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Apply V1's empty-value fallback semantics to one option.
	 *
	 * @param array<string, mixed> $values   Decoded legacy option values.
	 * @param string               $option   Exact legacy option name.
	 * @param mixed                $fallback Historical fallback value.
	 */
	private function effective( array $values, string $option, mixed $fallback ): mixed {
		$value = $values[ $option ] ?? null;
		return $this->legacy_empty( $value ) ? $fallback : $value;
	}

	/**
	 * Determine whether a legacy flag or payload is behaviorally active.
	 *
	 * @param mixed $value Decoded legacy value.
	 */
	private function active( mixed $value ): bool {
		return ! $this->legacy_empty( $value ) && ! in_array( $value, array( 'off', 'no', 'false' ), true );
	}

	/**
	 * Match the `empty()` fallback contract in V1's Options::has().
	 *
	 * @param mixed $value Decoded legacy value.
	 */
	private function legacy_empty( mixed $value ): bool {
		return null === $value || false === $value || 0 === $value || 0.0 === $value || '' === $value || '0' === $value || array() === $value;
	}

	/**
	 * Return true for site-bound historical paid identity, false for a safely
	 * classified non-paid/unrelated row, and null for malformed evidence.
	 *
	 * @param string $raw Exact Freemius option bytes.
	 */
	private function freemius_paid_identity( string $raw ): ?bool {
		if ( '' === trim( $raw ) || strlen( $raw ) > self::MAX_RAW_BYTES ) {
			return null;
		}
		$paths = array(
			'id_slug_type_path_map.7061.slug',
			'id_slug_type_path_map.7061.type',
			'id_slug_type_path_map.7061.path',
			'plugin_data.cartpops.plugin_version',
			'plugin_data.cartpops.is_anonymous',
			'sites.cartpops',
			'sites.cartpops.blog_id',
			'sites.cartpops.plugin_id',
			'sites.cartpops.plan_id',
			'sites.cartpops.license_id',
			'sites.cartpops.trial_plan_id',
			'sites.cartpops.is_premium',
			'sites.cartpops.is_uninstalled',
			'all_licenses.cartpops',
		);
		for ( $index = 0; $index < 32; ++$index ) {
			$paths[] = 'all_licenses.cartpops.' . $index . '.plugin_id';
		}
		$paths[] = 'all_licenses.cartpops.32';
		$values  = $this->extract_freemius_paths( $raw, $paths );
		if ( null === $values ) {
			return null;
		}

		$slug = $values['id_slug_type_path_map.7061.slug'] ?? null;
		$type = $values['id_slug_type_path_map.7061.type'] ?? null;
		$path = $values['id_slug_type_path_map.7061.path'] ?? null;
		if ( 'cartpops' !== $slug || ( null !== $type && 'plugin' !== $type ) ) {
			return false;
		}
		if ( ! is_string( $path ) || ! in_array( $path, self::LEGACY_PATHS, true ) ) {
			return null;
		}
		$version       = $values['plugin_data.cartpops.plugin_version'] ?? null;
		$version_state = $this->version_state( $version );
		if ( 'v2' === $version_state ) {
			return false;
		}
		if ( 'cartpops-pro/cartpops.php' === $path ) {
			return true;
		}
		if ( 'historical' !== $version_state ) {
			return null;
		}
		if ( true !== ( $values['sites.cartpops'] ?? false ) ) {
			if ( true === ( $values['plugin_data.cartpops.is_anonymous'] ?? false ) ) {
				return false;
			}
			return null;
		}
		$site_plugin = CanonicalInteger::parse( $values['sites.cartpops.plugin_id'] ?? null, 1 );
		$site_blog   = CanonicalInteger::parse( $values['sites.cartpops.blog_id'] ?? null, 1 );
		if ( null === $site_plugin || null === $site_blog ) {
			return null;
		}
		if (
			7061 !== $site_plugin
			|| get_current_blog_id() !== $site_blog
		) {
			return false;
		}
		for ( $index = 0; $index < 32; ++$index ) {
			if ( 7061 === CanonicalInteger::parse( $values[ 'all_licenses.cartpops.' . $index . '.plugin_id' ] ?? null, 1 ) ) {
				return true;
			}
		}
		if ( array_key_exists( 'all_licenses.cartpops.32', $values ) ) {
			return null;
		}
		if ( true === ( $values['sites.cartpops.is_uninstalled'] ?? false ) ) {
			return false;
		}

		return true === ( $values['sites.cartpops.is_premium'] ?? false )
			|| null !== CanonicalInteger::parse( $values['sites.cartpops.license_id'] ?? null, 1 )
			|| null !== CanonicalInteger::parse( $values['sites.cartpops.trial_plan_id'] ?? null, 1 );
	}

	/**
	 * Extract only bounded identity paths from JSON or serialized Freemius state.
	 *
	 * @param string   $raw   Exact Freemius option bytes.
	 * @param string[] $paths Closed identity path allowlist.
	 * @return array<string, mixed>|null
	 */
	private function extract_freemius_paths( string $raw, array $paths ): ?array {
		$trimmed = ltrim( $raw );
		if ( '' !== $trimmed && in_array( $trimmed[0], array( '{', '[' ), true ) ) {
			$decoded = ( new SafeJsonReader() )->decode( $trimmed, self::MAX_RAW_BYTES, self::MAX_DEPTH, self::MAX_NODES );
			$value   = $decoded['value'];
			if ( ! $decoded['success'] || ! is_array( $value ) ) {
				return null;
			}
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

		$extracted = ( new SafeSerializedReader() )->extract_paths( $raw, $paths, self::MAX_RAW_BYTES, self::MAX_DEPTH, self::MAX_NODES );
		return $extracted['success'] ? $extracted['values'] : null;
	}

	/**
	 * Return historical, v2, or invalid for one exact dotted plugin version.
	 *
	 * @param mixed $version Candidate historical plugin version.
	 */
	private function version_state( mixed $version ): string {
		if ( ! is_string( $version ) || 1 !== preg_match( '/^(0|[1-9][0-9]*)(?:\.(?:0|[1-9][0-9]*)){1,4}(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?$/D', $version, $matches ) ) {
			return 'invalid';
		}
		$major = $matches[1];
		if ( 1 === strlen( $major ) ) {
			return '0' === $major || '1' === $major ? 'historical' : 'v2';
		}

		return 'v2';
	}

	/**
	 * Return stable keys for closed-schema validation.
	 *
	 * @param array<mixed> $value Candidate record.
	 * @return array<int|string>
	 */
	private function sorted_keys( array $value ): array {
		$keys = array_keys( $value );
		sort( $keys, SORT_STRING );
		return $keys;
	}
}
