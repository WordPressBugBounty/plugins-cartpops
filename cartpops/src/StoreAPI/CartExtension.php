<?php
/**
 * Extends the WooCommerce Store API cart endpoint with CartPops data.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\StoreAPI;

use CartPops\Admin\SettingsRepository;
use CartPops\Cart\CartItemAuthoritativePresentationProjector;
use CartPops\Cart\CartItemPresentationExtension;
use CartPops\Cart\CartLineAuthority;
use CartPops\Cart\CartStateBuilder;
use CartPops\Cart\CouponSerializer;

/**
 * Extends the WooCommerce Store API cart endpoint with CartPops data.
 *
 * Keep this lightweight — runs on EVERY Store API /cart response (including
 * WC's own add-to-cart). Optional data is supplied through bounded filters or
 * the drawer-data endpoint rather than increasing every cart response.
 */
final class CartExtension {

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository                              $settings                Settings repository.
	 * @param CartItemAuthoritativePresentationProjector|null $authoritative_projector Closed edition projector.
	 */
	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly ?CartItemAuthoritativePresentationProjector $authoritative_projector = null,
	) {}

	/**
	 * Register the cart extension with the Store API.
	 */
	public function register(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
				'namespace'       => 'cartpops',
				'data_callback'   => array( $this, 'get_extension_data' ),
				'schema_callback' => array( $this, 'get_extension_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);

		// Extend cart items with parent product_id — the Store API's item `id`
		// is the variation_id for variable products, but bundle matching needs
		// the parent product_id.
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER,
				'namespace'       => 'cartpops',
				'data_callback'   => array( $this, 'get_item_extension_data' ),
				'schema_callback' => array( $this, 'get_item_extension_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/**
	 * Data to include in the Store API cart response.
	 *
	 * @return array<string, mixed>
	 */
	public function get_extension_data(): array {
		$design      = $this->settings->get( 'design', array() );
		$colors      = $design['colors'] ?? array();
		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		$cart        = is_object( $woocommerce ) ? ( $woocommerce->cart ?? null ) : null;

		$data = array(
			'drawer_config' => array(
				'position'           => $this->settings->get( 'drawer.position', 'right' ),
				'width_desktop'      => $this->settings->get( 'drawer.width_desktop', 480 ),
				'width_mobile'       => $this->settings->get( 'drawer.width_mobile', 100 ),
				'animation'          => $this->settings->get( 'drawer.animation', 'slide' ),
				'animation_duration' => $this->settings->get( 'drawer.animation_duration', 300 ),
				'border_radius'      => $design['border_radius'] ?? 8,
				'dark_mode'          => $design['dark_mode'] ?? 'auto',
				'colors'             => $colors,
			),
			'launcher'      => array(
				'enabled'    => (bool) $this->settings->get( 'launcher.enabled', true ),
				'position'   => $this->settings->get( 'launcher.position', 'bottom_right' ),
				'show_count' => (bool) $this->settings->get( 'launcher.show_count', true ),
				'show_total' => (bool) $this->settings->get( 'launcher.show_total', false ),
			),
		);
		try {
			$filtered = apply_filters( 'cartpops_store_api_cart_extension_data', $data );
		} catch ( \Throwable ) {
			$filtered = $data;
		}
		$result = ExtensionFilterResult::accept_or_baseline( $data, $filtered );

		// This shared authority must never be replaced by optional-edition data
		// or WooCommerce's raw coupon response, which can expose reward codes.
		$result['coupons']              = $cart instanceof \WC_Cart
			? CouponSerializer::serialize( $cart )
			: array();
		$result['item_presentations']   = array();
		$result['optional_locked_keys'] = array();
		if ( $cart instanceof \WC_Cart ) {
			try {
				$state                          = CartStateBuilder::build( $this->authoritative_projector );
				$state_items                    = is_array( $state['cartItems'] ?? null ) ? $state['cartItems'] : array();
				$result['item_presentations']   = CartItemPresentationExtension::for_store_api(
					$state_items
				);
				$result['optional_locked_keys'] = CartItemPresentationExtension::locked_keys_for_store_api(
					$state_items
				);
			} catch ( \Throwable ) {
				// Optional presentation must never make WooCommerce's cart unavailable.
				$result['item_presentations']   = array();
				$result['optional_locked_keys'] = array();
			}
		}

		return $result;
	}

	/**
	 * Data to include in each Store API cart item response.
	 *
	 * @param array<string, mixed> $cart_item WooCommerce cart item array.
	 * @return array<string, mixed>
	 */
	public function get_item_extension_data( $cart_item = array() ): array {
		$cart_item = is_array( $cart_item ) ? $cart_item : array();
		$cart_key  = is_string( $cart_item['key'] ?? null ) ? $cart_item['key'] : '';
		$authority = CartLineAuthority::for_cart_item( $cart_item, $cart_key );

		if ( null !== $this->authoritative_projector && CartLineAuthority::valid_key( $cart_key ) ) {
			$baseline = array( 'optionalLocked' => false );
			try {
				$candidate = $this->authoritative_projector->project( $baseline, $cart_item, $cart_key );
			} catch ( \Throwable ) {
				$candidate = $baseline;
			}
			$projected = CartItemPresentationExtension::sanitize_projector_result( $baseline, $candidate );
			if ( true === ( $projected['optionalLocked'] ?? false ) ) {
				$authority = CartLineAuthority::lock_optional_item( $authority );
			}
		}

		$result = array_merge(
			array( 'product_id' => CartLineAuthority::normalize_product_id( $cart_item['product_id'] ?? null ) ),
			$authority
		);
		if ( CartLineAuthority::valid_key( $cart_key ) ) {
			$result = array_merge( array( 'key' => $cart_key ), $result );
		}

		return $result;
	}

	/**
	 * JSON Schema for the cart item extension data.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_extension_schema(): array {
		return array_merge(
			array(
				'key'        => array(
					'description' => __( 'Exact WooCommerce cart item key.', 'cartpops' ),
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 128,
					'pattern'     => '^[A-Za-z0-9._:-]+$',
					'readonly'    => true,
				),
				'product_id' => array(
					'description' => __( 'Parent product ID (same as id for simple products).', 'cartpops' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
			),
			$this->line_authority_schema()
		);
	}

	/**
	 * JSON Schema for the extension data.
	 *
	 * @return array<string, mixed>
	 */
	public function get_extension_schema(): array {
		$schema = array(
			'drawer_config' => array(
				'description' => __( 'CartPops drawer configuration.', 'cartpops' ),
				'type'        => 'object',
				'readonly'    => true,
			),
			'launcher'      => array(
				'description' => __( 'Cart launcher configuration.', 'cartpops' ),
				'type'        => 'object',
				'readonly'    => true,
			),
		);
		try {
			$filtered = apply_filters( 'cartpops_store_api_cart_extension_schema', $schema );
		} catch ( \Throwable ) {
			$filtered = $schema;
		}
		$result = ExtensionFilterResult::accept_or_baseline( $schema, $filtered );

		$result['coupons']              = array(
			'description' => __( 'Safe CartPops coupon presentation and removal authority.', 'cartpops' ),
			'type'        => 'array',
			'readonly'    => true,
			'items'       => array(
				'type'       => 'object',
				'properties' => array(
					'key'       => array( 'type' => 'string' ),
					'code'      => array( 'type' => 'string' ),
					'label'     => array( 'type' => 'string' ),
					'is_system' => array( 'type' => 'boolean' ),
					'removable' => array( 'type' => 'boolean' ),
					'totals'    => array(
						'type'       => 'object',
						'properties' => array(
							'total_discount'              => array( 'type' => 'string' ),
							'total_discount_tax'          => array( 'type' => 'string' ),
							'currency_code'               => array( 'type' => 'string' ),
							'currency_symbol'             => array( 'type' => 'string' ),
							'currency_minor_unit'         => array( 'type' => 'integer' ),
							'currency_decimal_separator'  => array( 'type' => 'string' ),
							'currency_thousand_separator' => array( 'type' => 'string' ),
							'currency_prefix'             => array( 'type' => 'string' ),
							'currency_suffix'             => array( 'type' => 'string' ),
						),
					),
				),
			),
		);
		$item_presentation_properties   = array_merge(
			array(
				'key'                => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 128,
					'pattern'   => '^[A-Za-z0-9._:-]+$',
				),
				'name'               => array( 'type' => 'string' ),
				'short_description'  => array( 'type' => 'string' ),
				'variationSummary'   => array( 'type' => 'string' ),
				'extraLines'         => array(
					'type'     => 'array',
					'maxItems' => 20,
					'items'    => array(
						'type'       => 'object',
						'properties' => array(
							'label' => array( 'type' => 'string' ),
							'value' => array( 'type' => 'string' ),
						),
					),
				),
				'customPresentation' => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
			),
			$this->line_authority_schema()
		);
		$result['item_presentations']   = array(
			'description' => __( 'Bounded PHP-filtered cart item presentation.', 'cartpops' ),
			'type'        => 'array',
			'readonly'    => true,
			'maxItems'    => 100,
			'items'       => array(
				'type'       => 'object',
				'properties' => $item_presentation_properties,
			),
		);
		$result['optional_locked_keys'] = array(
			'description' => __( 'Exact cart item keys with an edition-owned locked presentation.', 'cartpops' ),
			'type'        => 'array',
			'readonly'    => true,
			'maxItems'    => 100,
			'uniqueItems' => true,
			'items'       => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 128,
				'pattern'   => '^[A-Za-z0-9._:-]+$',
			),
		);

		return $result;
	}

	/**
	 * Shared JSON schema for the exact cart-line authority carrier.
	 *
	 * @return array<string, mixed>
	 */
	private function line_authority_schema(): array {
		return array(
			'visible'          => array(
				'description' => __( 'Whether WooCommerce permits this line to be shown in a mini-cart.', 'cartpops' ),
				'type'        => 'boolean',
				'readonly'    => true,
			),
			'quantityEditable' => array(
				'description' => __( 'Whether CartPops may offer quantity mutation for this line.', 'cartpops' ),
				'type'        => 'boolean',
				'readonly'    => true,
			),
			'removable'        => array(
				'description' => __( 'Whether CartPops may offer removal for this line.', 'cartpops' ),
				'type'        => 'boolean',
				'readonly'    => true,
			),
			'quantityLimits'   => array(
				'description' => __( 'WooCommerce quantity minimum, transport maximum, step, and unlimited-product state.', 'cartpops' ),
				'type'        => 'object',
				'readonly'    => true,
				'properties'  => array(
					'minimum'    => array( 'type' => 'integer' ),
					'maximum'    => array( 'type' => 'integer' ),
					'multipleOf' => array( 'type' => 'integer' ),
					'unlimited'  => array( 'type' => 'boolean' ),
				),
			),
			'backorderNotice'  => array(
				'description' => __( 'Bounded plain-text WooCommerce backorder notice.', 'cartpops' ),
				'type'        => 'string',
				'maxLength'   => 500,
				'readonly'    => true,
			),
		);
	}
}
