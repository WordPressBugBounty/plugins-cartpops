<?php
/**
 * Drift-safe current-blog guard for paid drawer data.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Licensing;

use CartPops\REST\DrawerDataProvider;

/** Delegate a fixed field ownership set only while current-blog authority holds. */
final class GuardedDrawerDataProvider implements DrawerDataProvider {
	/**
	 * Validated exact field ownership in provider order.
	 *
	 * @var string[]
	 */
	private readonly array $owned_fields;

	/**
	 * Create a guarded provider around physically present paid behavior.
	 *
	 * @param PaidRuntimeGuard   $guard    Current-blog entitlement and readiness guard.
	 * @param DrawerDataProvider $provider Physically present paid provider.
	 * @throws \LogicException When field ownership is malformed.
	 */
	public function __construct(
		private readonly PaidRuntimeGuard $guard,
		private readonly DrawerDataProvider $provider,
	) {
		$fields = $provider->fields();
		if ( ! $this->valid_fields( $fields ) ) {
			throw new \LogicException( 'CartPops guarded drawer field ownership is invalid.' );
		}
		$this->owned_fields = array_values( $fields );
	}

	/**
	 * Return exact paid fields owned when this adapter was created.
	 *
	 * @return string[]
	 */
	public function fields(): array {
		return $this->owned_fields;
	}

	/**
	 * Build requested owned fields only while the same current blog stays entitled.
	 *
	 * @param string[] $requested_fields Requested fields.
	 * @return array<string, mixed>|\WP_REST_Response
	 */
	public function provide( array $requested_fields ): array|\WP_REST_Response {
		$blog_id = $this->current_blog_id();
		if ( ! $this->authority_holds( $blog_id ) ) {
			return array();
		}
		$requested = array_values( array_unique( array_intersect( $requested_fields, $this->owned_fields ) ) );
		if ( array() === $requested ) {
			return array();
		}

		try {
			$current_fields = $this->provider->fields();
		} catch ( \Throwable ) {
			if ( ! $this->authority_holds( $blog_id ) ) {
				return array();
			}
			return $this->invalid_provider_response();
		}
		$fields_are_valid = $this->valid_fields( $current_fields ) && $current_fields === $this->owned_fields;
		if ( ! $this->authority_holds( $blog_id ) ) {
			return array();
		}
		if ( ! $fields_are_valid ) {
			return $this->invalid_provider_response();
		}

		try {
			$provided = $this->provider->provide( $requested );
		} catch ( \Throwable ) {
			return $this->invalid_provider_response();
		}

		if ( ! $this->authority_holds( $blog_id ) ) {
			return array();
		}
		$normalized = $this->normalize_provider_output( $provided, $requested );
		try {
			$current_fields = $this->provider->fields();
		} catch ( \Throwable ) {
			if ( ! $this->authority_holds( $blog_id ) ) {
				return array();
			}
			return $this->invalid_provider_response();
		}
		$fields_are_valid = $this->valid_fields( $current_fields ) && $current_fields === $this->owned_fields;
		if ( ! $this->authority_holds( $blog_id ) ) {
			return array();
		}
		if ( ! $fields_are_valid ) {
			return $this->invalid_provider_response();
		}

		return $normalized;
	}

	/**
	 * Replace malformed output and delegated errors with guard-owned responses.
	 *
	 * @param array<string, mixed>|\WP_REST_Response $provided Provider output.
	 * @param string[]                               $requested Exact delegated fields.
	 * @return array<string, mixed>|\WP_REST_Response
	 */
	private function normalize_provider_output( array|\WP_REST_Response $provided, array $requested ): array|\WP_REST_Response {
		if ( $provided instanceof \WP_REST_Response ) {
			return $this->invalid_provider_response( $provided->get_status() );
		}
		foreach ( $provided as $field => $value ) {
			unset( $value );
			if ( ! is_string( $field ) || ! in_array( $field, $requested, true ) ) {
				return $this->invalid_provider_response();
			}
		}

		return $provided;
	}

	/**
	 * Validate a provider's field declaration.
	 *
	 * @param mixed[] $fields Provider field declarations.
	 */
	private function valid_fields( array $fields ): bool {
		if ( array() === $fields || count( $fields ) > 16 || count( $fields ) !== count( array_unique( $fields ) ) ) {
			return false;
		}
		foreach ( $fields as $field ) {
			if ( ! is_string( $field ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $field ) ) {
				return false;
			}
		}

		return array_is_list( $fields );
	}

	/**
	 * Re-evaluate current-blog paid authority.
	 *
	 * @phpstan-impure Entitlement can change while the delegated provider runs.
	 */
	private function allows_execution(): bool {
		return $this->guard->allows_execution();
	}

	/**
	 * Prove paid authority stayed on one exact blog across the fresh decision.
	 *
	 * @param int $blog_id Original current blog.
	 * @phpstan-impure Blog identity and entitlement may change between decisions.
	 */
	private function authority_holds( int $blog_id ): bool {
		return $blog_id >= 1
			&& $blog_id === $this->current_blog_id()
			&& $this->allows_execution()
			&& $blog_id === $this->current_blog_id();
	}

	/**
	 * Current WordPress blog, or zero when the identity seam is unavailable.
	 *
	 * @phpstan-impure Plugins may switch blogs while the delegated provider runs.
	 */
	private function current_blog_id(): int {
		return function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0;
	}

	/**
	 * Return a value-free provider failure without exposing paid output.
	 *
	 * @param int $status Safe delegated error status, or a malformed status.
	 */
	private function invalid_provider_response( int $status = 503 ): \WP_REST_Response {
		$status = $status >= 400 && $status <= 599 ? $status : 503;

		return new \WP_REST_Response(
			array(
				'code'    => 'drawer_data_provider_invalid',
				'message' => __( 'Drawer data is temporarily unavailable.', 'cartpops' ),
			),
			$status
		);
	}
}
