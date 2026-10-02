<?php
/**
 * Current-blog guard for paid drawer supplements.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Licensing;

use CartPops\REST\DrawerDataProvider;

/** Backward-compatible name delegating to the drift-safe paid provider guard. */
final class EntitledDrawerDataProvider implements DrawerDataProvider {
	/**
	 * Drift-safe implementation behind the historical class.
	 *
	 * @var GuardedDrawerDataProvider
	 */
	private readonly GuardedDrawerDataProvider $guarded_provider;

	/**
	 * Create a current-blog paid supplement boundary.
	 *
	 * @param PaidRuntimeGuard   $guard    Current-blog execution guard.
	 * @param DrawerDataProvider $provider Physically present paid provider.
	 */
	public function __construct(
		PaidRuntimeGuard $guard,
		DrawerDataProvider $provider,
	) {
		$this->guarded_provider = new GuardedDrawerDataProvider( $guard, $provider );
	}

	/**
	 * Return exact physically paid field ownership.
	 *
	 * @return string[] Exact paid fields.
	 */
	public function fields(): array {
		return $this->guarded_provider->fields();
	}

	/**
	 * Return no paid fields unless the current blog is entitled and ready.
	 *
	 * @param string[] $requested_fields Requested paid fields.
	 * @return array<string, mixed>|\WP_REST_Response
	 */
	public function provide( array $requested_fields ): array|\WP_REST_Response {
		return $this->guarded_provider->provide( $requested_fields );
	}
}
