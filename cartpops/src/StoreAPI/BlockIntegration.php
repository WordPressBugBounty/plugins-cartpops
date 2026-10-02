<?php
/**
 * Integrates CartPops with WooCommerce blocks (Mini Cart, Cart, Checkout).
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\StoreAPI;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

/**
 * Integrates CartPops with WooCommerce blocks (Mini Cart, Cart, Checkout).
 */
final class BlockIntegration implements IntegrationInterface {

	/**
	 * Integration name.
	 */
	public function get_name(): string {
		return 'cartpops';
	}

	/**
	 * Initialize the integration.
	 */
	public function initialize(): void {
		// Assets are loaded via block.json viewScriptModule.
	}

	/**
	 * Frontend script handles.
	 *
	 * @return string[]
	 */
	public function get_script_handles(): array {
		return array();
	}

	/**
	 * Editor script handles.
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles(): array {
		return array();
	}

	/**
	 * Data available to frontend scripts.
	 *
	 * @return array<string, mixed>
	 */
	public function get_script_data(): array {
		return array(
			'version' => CARTPOPS_VERSION,
		);
	}
}
