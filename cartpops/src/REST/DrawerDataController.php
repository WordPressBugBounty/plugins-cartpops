<?php
/**
 * Batch endpoint for drawer supplementary data.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\REST;

/**
 * Batch endpoint for drawer supplementary data.
 *
 * Combines shipping meter, recommendations, smart add-ons, notification bar,
 * and bundle builder data into a single REST response — replacing 4+ separate
 * round-trips with one request.
 */
final class DrawerDataController {
	/**
	 * Edition-appropriate providers.
	 *
	 * @var DrawerDataProvider[]
	 */
	private array $providers = array();

	/**
	 * Closed field ownership map.
	 *
	 * @var array<string, true>
	 */
	private array $owned_fields = array();

	/**
	 * Construct the shared route from edition-appropriate providers.
	 *
	 * @param DrawerDataProvider[] $providers Shared provider plus any Pro supplement.
	 * @throws \LogicException When providers or field ownership are invalid.
	 */
	public function __construct( array $providers ) {
		if ( array() === $providers || count( $providers ) > 16 ) {
			throw new \LogicException( 'CartPops drawer-data providers are invalid.' );
		}
		foreach ( $providers as $provider ) {
			if ( ! $provider instanceof DrawerDataProvider ) {
				throw new \LogicException( 'CartPops drawer-data provider is invalid.' );
			}
			$this->attach_provider( $provider, false );
		}
	}

	/**
	 * Add one provider without duplicating an already-attached provider shape.
	 *
	 * @param DrawerDataProvider $provider Provider to append in response order.
	 * @throws \LogicException When the provider or field ownership is invalid.
	 */
	public function add_provider( DrawerDataProvider $provider ): void {
		$this->attach_provider( $provider, true );
	}

	/**
	 * Validate and atomically append one provider.
	 *
	 * @param DrawerDataProvider $provider       Provider to append.
	 * @param bool               $allow_existing Whether an exact late attachment is idempotent.
	 * @throws \LogicException When provider bounds, fields, or ownership are invalid.
	 */
	private function attach_provider( DrawerDataProvider $provider, bool $allow_existing ): void {
		$fields = $provider->fields();
		if ( array() === $fields || count( $fields ) > 16 || count( $fields ) !== count( array_unique( $fields ) ) ) {
			throw new \LogicException( 'CartPops drawer-data provider fields are invalid.' );
		}

		foreach ( $this->providers as $registered ) {
			if ( $allow_existing && ( $registered === $provider || ( $registered::class === $provider::class && $registered->fields() === $fields ) ) ) {
				return;
			}
		}
		if ( count( $this->providers ) >= 16 ) {
			throw new \LogicException( 'CartPops drawer-data providers are invalid.' );
		}

		foreach ( $fields as $field ) {
			if ( ! is_string( $field ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $field ) || isset( $this->owned_fields[ $field ] ) ) {
				throw new \LogicException( 'CartPops drawer-data provider ownership overlaps.' );
			}
		}
		foreach ( $fields as $field ) {
			$this->owned_fields[ $field ] = true;
		}
		$this->providers[] = $provider;
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'cartpops/v1',
			'/drawer-data',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_drawer_data' ),
				'permission_callback' => array( PublicEndpointGuard::class, 'verify_session' ),
				'args'                => array(
					'fields' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => 'shipping_meter,recommendations,smart_addons,notifications,bundle_configs',
					),
				),
			)
		);
	}

	/**
	 * Return requested drawer data fields in a single response.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function get_drawer_data( \WP_REST_Request $request ): \WP_REST_Response {
		// Ensure WC cart/session is available — REST requests don't load it by default.
		wc_load_cart();

		$raw_fields = $request->get_param( 'fields' );
		$raw_fields = is_string( $raw_fields )
			? $raw_fields
			: 'shipping_meter,recommendations,smart_addons,notifications,bundle_configs';
		$fields     = array_values( array_unique( array_filter( array_map( 'trim', explode( ',', $raw_fields ) ) ) ) );
		$data       = array();

		foreach ( $this->providers as $provider ) {
			$requested = array_values( array_intersect( $fields, $provider->fields() ) );
			if ( array() === $requested ) {
				continue;
			}
			$provided = $provider->provide( $requested );
			if ( $provided instanceof \WP_REST_Response ) {
				return $provided;
			}
			foreach ( $provided as $field => $value ) {
				if ( ! is_string( $field ) || ! in_array( $field, $requested, true ) || array_key_exists( $field, $data ) ) {
					return new \WP_REST_Response(
						array(
							'code'    => 'drawer_data_provider_invalid',
							'message' => __( 'Drawer data is temporarily unavailable.', 'cartpops' ),
						),
						503
					);
				}
				$data[ $field ] = $value;
			}
		}

		return new \WP_REST_Response( $data, 200 );
	}
}
