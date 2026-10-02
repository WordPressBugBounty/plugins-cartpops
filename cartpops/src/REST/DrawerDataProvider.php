<?php
/**
 * Edition-safe provider contract for the shared drawer-data route.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\REST;

/** Keep shared routing independent from physically removable Pro services. */
interface DrawerDataProvider {
	/**
	 * Return exact response fields owned by this provider.
	 *
	 * @return string[] Exact response fields owned by this provider.
	 */
	public function fields(): array;

	/**
	 * Build only requested owned fields, or return a truthful provider failure.
	 *
	 * @param string[] $requested_fields Exact owned fields requested by the client.
	 * @return array<string, mixed>|\WP_REST_Response
	 */
	public function provide( array $requested_fields ): array|\WP_REST_Response;
}
