<?php
/**
 * Renders the cart drawer for classic themes via wp_footer.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Cart;

use CartPops\Admin\SettingsRepository;

/**
 * Renders the cart drawer for classic themes via wp_footer.
 */
final class DrawerRenderer {

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 */
	public function __construct(
		// @phpstan-ignore property.onlyWritten (Injected for API consistency with the other renderers and possible future use; intentionally retained.)
		private readonly SettingsRepository $settings,
	) {}

	/**
	 * Render the cart drawer by delegating to the block's render.php.
	 */
	public function render(): void {
		echo render_block( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'blockName' => 'cartpops/cart-drawer',
				'attrs'     => array(),
			)
		);
	}
}
