<?php
/**
 * REST API controller for CartPops settings.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Admin;

use CartPops\Licensing\EditionAuthority;
use CartPops\Recommendations\RecommendationButtonPresentation;
use CartPops\Settings\SecondaryActionSettings;

/**
 * REST API controller for CartPops settings.
 */
final class SettingsController extends \WP_REST_Controller {

	/**
	 * The REST API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'cartpops/v1';

	/**
	 * The REST base for this controller's routes.
	 *
	 * @var string
	 */
	protected $rest_base = 'settings';

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository    $repository       Settings repository.
	 * @param EditionAuthority|null $edition_authority Fresh current-blog edition authority.
	 */
	public function __construct(
		private readonly SettingsRepository $repository,
		private readonly ?EditionAuthority $edition_authority = null,
	) {}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'reset_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/export',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'export_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/import',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'import_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * Get all settings.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function get_settings( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( $this->repository->all(), 200 );
	}

	/**
	 * Update settings (partial merge).
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function update_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();

		if ( empty( $body ) || ! is_array( $body ) ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'No settings provided.', 'cartpops' ) ),
				400
			);
		}

		// Only allow known top-level setting groups.
		$allowed_keys = array_keys( $this->repository->defaults() );
		$body         = array_intersect_key( $body, array_flip( $allowed_keys ) );

		if ( empty( $body ) ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'No valid settings provided.', 'cartpops' ) ),
				400
			);
		}
		if ( $this->contains_custom_recommendation_change( $body ) && ! $this->can_edit_custom_recommendations() ) {
			return $this->custom_recommendations_forbidden_response();
		}
		if ( $this->contains_recommendation_button_change( $body ) && ! $this->can_edit_paid_recommendation_presentation() ) {
			return $this->recommendation_button_forbidden_response();
		}
		if ( $this->contains_secondary_action_change( $body ) && ! $this->can_edit_secondary_action() ) {
			return $this->secondary_action_forbidden_response();
		}
		if ( $this->contains_custom_css_change( $body ) && ! $this->can_edit_custom_css() ) {
			return $this->custom_css_forbidden_response();
		}

		try {
			$this->repository->update( $body );
		} catch ( \InvalidArgumentException ) {
			return $this->invalid_settings_shape_response();
		} catch ( \RuntimeException ) {
			return $this->persistence_failure_response();
		}

		return new \WP_REST_Response( $this->repository->all(), 200 );
	}

	/**
	 * Reset settings to defaults.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function reset_settings( \WP_REST_Request $request ): \WP_REST_Response {
		if ( $this->custom_recommendations_differ_from_defaults() && ! $this->can_edit_custom_recommendations() ) {
			return $this->custom_recommendations_forbidden_response();
		}
		if ( $this->recommendation_button_differs_from_defaults() && ! $this->can_edit_paid_recommendation_presentation() ) {
			return $this->recommendation_button_forbidden_response();
		}
		if ( $this->secondary_action_differs_from_defaults() && ! $this->can_edit_secondary_action() ) {
			return $this->secondary_action_forbidden_response();
		}

		$default_css = $this->repository->defaults()['advanced']['custom_css'] ?? '';
		if ( $this->custom_css_differs_from_current( $default_css ) && ! $this->can_edit_custom_css() ) {
			return $this->custom_css_forbidden_response();
		}
		try {
			$this->repository->reset();
		} catch ( \RuntimeException ) {
			return $this->persistence_failure_response();
		}

		return new \WP_REST_Response( $this->repository->all(), 200 );
	}

	/**
	 * Export settings as JSON.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function export_settings( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'version'  => CARTPOPS_VERSION,
				'exported' => gmdate( 'c' ),
				'settings' => $this->repository->all(),
			),
			200
		);
	}

	/**
	 * Import settings from JSON.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function import_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();

		if ( empty( $body['settings'] ) || ! is_array( $body['settings'] ) ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'Invalid import data. Expected "settings" key with object value.', 'cartpops' ) ),
				400
			);
		}

		// Only allow known top-level setting groups.
		$allowed_keys     = array_keys( $this->repository->defaults() );
		$body['settings'] = array_intersect_key( $body['settings'], array_flip( $allowed_keys ) );
		if ( array() === $body['settings'] ) {
			return $this->invalid_settings_shape_response();
		}
		if ( $this->contains_custom_recommendation_change( $body['settings'] ) && ! $this->can_edit_custom_recommendations() ) {
			return $this->custom_recommendations_forbidden_response();
		}
		if ( $this->contains_recommendation_button_change( $body['settings'] ) && ! $this->can_edit_paid_recommendation_presentation() ) {
			return $this->recommendation_button_forbidden_response();
		}
		if ( $this->contains_secondary_action_change( $body['settings'] ) && ! $this->can_edit_secondary_action() ) {
			return $this->secondary_action_forbidden_response();
		}
		if ( $this->contains_custom_css_change( $body['settings'] ) && ! $this->can_edit_custom_css() ) {
			return $this->custom_css_forbidden_response();
		}

		try {
			$this->repository->update( $body['settings'] );
		} catch ( \InvalidArgumentException ) {
			return $this->invalid_settings_shape_response();
		} catch ( \RuntimeException ) {
			return $this->persistence_failure_response();
		}

		return new \WP_REST_Response(
			array(
				'message'  => __( 'Settings imported successfully.', 'cartpops' ),
				'settings' => $this->repository->all(),
			),
			200
		);
	}

	/**
	 * Check if the current user can manage WooCommerce.
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Return a truthful non-success response when durable option storage fails.
	 */
	private function persistence_failure_response(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'code'    => 'settings_not_persisted',
				'message' => __( 'Settings could not be saved. Please try again.', 'cartpops' ),
			),
			500
		);
	}

	/**
	 * Return a clear client error for malformed nested settings containers.
	 */
	private function invalid_settings_shape_response(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'code'    => 'invalid_settings_shape',
				'message' => __( 'Settings contain an invalid nested value.', 'cartpops' ),
			),
			400
		);
	}

	/**
	 * Whether a request would change the canonical free-form CSS field.
	 *
	 * @param array<string, mixed> $settings Submitted settings.
	 */
	private function contains_custom_css_change( array $settings ): bool {
		$advanced = $settings['advanced'] ?? null;
		if ( ! is_array( $advanced ) || ! array_key_exists( 'custom_css', $advanced ) ) {
			return false;
		}

		return $this->custom_css_differs_from_current( $advanced['custom_css'] );
	}

	/**
	 * Compare a proposed CSS value with the current canonical stored value.
	 *
	 * Scalar non-strings use the repository's default-value behavior so they
	 * cannot bypass authorization by becoming an effective clear. Non-scalar
	 * values remain for the repository's normal invalid-shape response.
	 *
	 * @param mixed $proposed_css Submitted CSS candidate.
	 */
	private function custom_css_differs_from_current( mixed $proposed_css ): bool {
		$default_css = $this->repository->defaults()['advanced']['custom_css'] ?? '';
		$default_css = is_string( $default_css ) ? $this->repository->sanitize_custom_css( $default_css ) : '';
		if ( ! is_string( $proposed_css ) ) {
			if ( ! is_scalar( $proposed_css ) ) {
				return false;
			}
			$proposed_css = $default_css;
		}

		$current_css = $this->repository->get( 'advanced.custom_css', $default_css );
		if ( ! is_string( $current_css ) ) {
			return true;
		}

		return $this->repository->sanitize_custom_css( $proposed_css )
			!== $this->repository->sanitize_custom_css( $current_css );
	}

	/**
	 * Whether a patch attempts to select or modify the paid custom strategy.
	 *
	 * @param array<string, mixed> $settings Submitted settings.
	 */
	private function contains_custom_recommendation_change( array $settings ): bool {
		$recommendations = $settings['recommendations'] ?? null;
		if ( ! is_array( $recommendations ) ) {
			return false;
		}

		if (
			'custom' === ( $recommendations['strategy'] ?? null )
			&& 'custom' !== $this->repository->get( 'recommendations.strategy', 'upsell' )
		) {
			return true;
		}

		return array_key_exists( 'custom_product_ids', $recommendations )
			&& $recommendations['custom_product_ids'] !== $this->repository->get( 'recommendations.custom_product_ids', array() );
	}

	/** Whether a reset would erase paid recommendation configuration. */
	private function custom_recommendations_differ_from_defaults(): bool {
		$recommendation_defaults = $this->repository->defaults()['recommendations'] ?? array();
		$default_strategy        = $recommendation_defaults['strategy'] ?? 'upsell';
		$default_custom_ids      = $recommendation_defaults['custom_product_ids'] ?? array();

		return (
			'custom' === $this->repository->get( 'recommendations.strategy', $default_strategy )
			&& 'custom' !== $default_strategy
		) || $this->repository->get( 'recommendations.custom_product_ids', $default_custom_ids ) !== $default_custom_ids;
	}

	/**
	 * Whether a request would change either canonical paid button value.
	 *
	 * @param array<string, mixed> $settings Submitted settings.
	 */
	private function contains_recommendation_button_change( array $settings ): bool {
		$recommendations = $settings['recommendations'] ?? null;
		if ( ! is_array( $recommendations ) ) {
			return false;
		}

		if ( array_key_exists( 'button_type', $recommendations ) ) {
			$proposed = RecommendationButtonPresentation::normalize_mode( $recommendations['button_type'] );
			$current  = RecommendationButtonPresentation::normalize_mode(
				$this->repository->get( 'recommendations.button_type', RecommendationButtonPresentation::DEFAULT_MODE )
			);
			if ( $proposed !== $current ) {
				return true;
			}
		}

		if ( array_key_exists( 'button_text', $recommendations ) ) {
			$proposed = RecommendationButtonPresentation::sanitize_text( $recommendations['button_text'] );
			$current  = RecommendationButtonPresentation::sanitize_text(
				$this->repository->get( 'recommendations.button_text', RecommendationButtonPresentation::DEFAULT_TEXT )
			);
			if ( $proposed !== $current ) {
				return true;
			}
		}

		return false;
	}

	/** Whether a reset would erase dormant or active paid button presentation. */
	private function recommendation_button_differs_from_defaults(): bool {
		return RecommendationButtonPresentation::normalize_mode(
			$this->repository->get( 'recommendations.button_type', RecommendationButtonPresentation::DEFAULT_MODE )
		) !== RecommendationButtonPresentation::DEFAULT_MODE
			|| RecommendationButtonPresentation::sanitize_text(
				$this->repository->get( 'recommendations.button_text', RecommendationButtonPresentation::DEFAULT_TEXT )
			) !== RecommendationButtonPresentation::DEFAULT_TEXT;
	}

	/**
	 * Whether a request would change any canonical paid secondary-action value.
	 *
	 * @param array<string, mixed> $settings Submitted settings.
	 */
	private function contains_secondary_action_change( array $settings ): bool {
		$secondary = $settings['secondary_action'] ?? null;
		if ( ! is_array( $secondary ) ) {
			return false;
		}

		$comparisons = array(
			'mode'        => static fn( mixed $value ): string => SecondaryActionSettings::normalize_mode( $value ),
			'custom_url'  => static fn( mixed $value ): string => SecondaryActionSettings::sanitize_custom_url( $value ),
			'custom_text' => static fn( mixed $value ): string => SecondaryActionSettings::sanitize_custom_text( $value ),
		);
		foreach ( $comparisons as $key => $normalize ) {
			if ( ! array_key_exists( $key, $secondary ) ) {
				continue;
			}
			$current = $this->repository->get( 'secondary_action.' . $key, '' );
			if ( $normalize( $secondary[ $key ] ) !== $normalize( $current ) ) {
				return true;
			}
		}

		return false;
	}

	/** Whether reset would erase dormant or active paid secondary-action data. */
	private function secondary_action_differs_from_defaults(): bool {
		$defaults = $this->repository->defaults()['secondary_action'];

		return SecondaryActionSettings::normalize_mode(
			$this->repository->get( 'secondary_action.mode', $defaults['mode'] )
		) !== $defaults['mode']
			|| SecondaryActionSettings::sanitize_custom_url(
				$this->repository->get( 'secondary_action.custom_url', $defaults['custom_url'] )
			) !== $defaults['custom_url']
			|| SecondaryActionSettings::sanitize_custom_text(
				$this->repository->get( 'secondary_action.custom_text', $defaults['custom_text'] )
			) !== $defaults['custom_text'];
	}

	/** Resolve paid editing authority afresh for the current WordPress blog. */
	private function can_edit_custom_recommendations(): bool {
		return null !== $this->edition_authority && $this->edition_authority->allows_paid_code();
	}

	/** Resolve physical Pro and fresh current-blog entitlement for presentation edits. */
	private function can_edit_paid_recommendation_presentation(): bool {
		return null !== $this->edition_authority && $this->edition_authority->allows_paid_code();
	}

	/** Resolve physical Pro and fresh current-blog entitlement for secondary-action edits. */
	private function can_edit_secondary_action(): bool {
		return null !== $this->edition_authority && $this->edition_authority->allows_paid_code();
	}

	/** Return an atomic authorization failure for paid recommendation changes. */
	private function custom_recommendations_forbidden_response(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'code'    => 'custom_recommendations_forbidden',
				'message' => __( 'Custom product recommendations require an active CartPops Pro entitlement for this site.', 'cartpops' ),
			),
			403
		);
	}

	/** Return an atomic authorization failure for paid button-presentation changes. */
	private function recommendation_button_forbidden_response(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'code'    => 'recommendation_button_presentation_forbidden',
				'message' => __( 'Recommendation button text and presentation require an active CartPops Pro entitlement for this site.', 'cartpops' ),
			),
			403
		);
	}

	/** Return an atomic authorization failure for paid secondary-action changes. */
	private function secondary_action_forbidden_response(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'code'    => 'secondary_action_forbidden',
				'message' => __( 'Secondary drawer actions require an active CartPops Pro entitlement for this site.', 'cartpops' ),
			),
			403
		);
	}

	/**
	 * WordPress deliberately grants raw CSS/HTML to fewer users than the
	 * WooCommerce settings capability. Either native CSS authority is accepted.
	 */
	private function can_edit_custom_css(): bool {
		return current_user_can( 'edit_css' ) || current_user_can( 'unfiltered_html' );
	}

	/** Return an atomic authorization failure for custom CSS changes. */
	private function custom_css_forbidden_response(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'code'    => 'custom_css_forbidden',
				'message' => __( 'You are not allowed to change custom CSS.', 'cartpops' ),
			),
			403
		);
	}
}
