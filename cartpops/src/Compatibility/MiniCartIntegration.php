<?php
/**
 * Integrates CartPops with WooCommerce's Mini Cart block.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Compatibility;

use CartPops\Admin\SettingsRepository;
use CartPops\Frontend\FrontendRuntimePolicy;

/**
 * Integrates CartPops with WooCommerce's Mini Cart block.
 *
 * Supports two modes:
 * - **replace**: CartPops drawer replaces the WC Mini Cart entirely.
 * - **standalone**: Both operate independently with synced cart state.
 */
final class MiniCartIntegration {

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository    $settings        Settings repository.
	 * @param FrontendRuntimePolicy $frontend_policy Frontend runtime policy.
	 */
	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly FrontendRuntimePolicy $frontend_policy,
	) {}

	/**
	 * Register hooks based on integration mode.
	 */
	public function register(): void {
		if ( ! $this->frontend_policy->is_enabled() ) {
			return;
		}

		match ( $this->mode() ) {
			'standalone' => $this->register_standalone_mode(),
			default      => $this->register_replace_mode(),
		};
	}

	/**
	 * Resolve the only Mini Cart modes understood by the browser runtime.
	 */
	public function mode(): string {
		return SettingsRepository::normalize_mini_cart_mode(
			$this->settings->get( 'general.mini_cart_mode', 'replace' )
		);
	}

	/**
	 * Register the replace-mode presentation hook; the browser bridge owns clicks.
	 */
	private function register_replace_mode(): void {
		// Add body class for CSS targeting.
		add_filter( 'body_class', array( $this, 'add_replace_body_class' ) );
	}

	/**
	 * Register standalone presentation while both drawers stay independent.
	 */
	private function register_standalone_mode(): void {
		add_filter( 'body_class', array( $this, 'add_standalone_body_class' ) );
	}

	/**
	 * Add the replace-mode body class while frontend output remains enabled.
	 *
	 * @param mixed $classes Existing body classes.
	 * @return mixed Filtered classes, or an unchanged malformed prior value.
	 */
	public function add_replace_body_class( mixed $classes ): mixed {
		return $this->add_mode_body_class( $classes, 'cpops-mode-replace' );
	}

	/**
	 * Add the standalone-mode body class while frontend output remains enabled.
	 *
	 * @param mixed $classes Existing body classes.
	 * @return mixed Filtered classes, or an unchanged malformed prior value.
	 */
	public function add_standalone_body_class( mixed $classes ): mixed {
		return $this->add_mode_body_class( $classes, 'cpops-mode-standalone' );
	}

	/**
	 * Add one integration mode class without leaking it into disabled contexts.
	 *
	 * @param mixed  $classes Existing body classes.
	 * @param string $mode    Mode class.
	 * @return mixed Filtered classes, or an unchanged malformed prior value.
	 */
	private function add_mode_body_class( mixed $classes, string $mode ): mixed {
		if ( ! is_array( $classes ) ) {
			return $classes;
		}
		foreach ( $classes as $class ) {
			if ( ! is_string( $class ) ) {
				return $classes;
			}
		}

		if ( $this->frontend_policy->is_enabled() ) {
			$classes[] = $mode;
		}

		return $classes;
	}
}
