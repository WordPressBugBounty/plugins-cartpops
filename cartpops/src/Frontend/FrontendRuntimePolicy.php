<?php
/**
 * Resolves the shared frontend enable and trigger policy.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Frontend;

use CartPops\Admin\SettingsRepository;

/**
 * Resolves the shared frontend enable and trigger policy.
 */
final class FrontendRuntimePolicy {
	private const FALLBACK_TRIGGER   = 'launcher';
	private const MAX_TRIGGER_LENGTH = 32;
	private const BLOCKS             = array(
		'cartpops/cart-drawer',
		'cartpops/cart-launcher',
		'cartpops/shipping-meter',
	);

	/**
	 * Settings source for frontend runtime decisions.
	 *
	 * @var SettingsRepository
	 */
	private readonly SettingsRepository $settings;

	/**
	 * Exact page-matching boundary, injectable for isolated tests.
	 *
	 * @var \Closure(int[]): bool
	 */
	private readonly \Closure $page_matcher;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository         $settings     Settings repository.
	 * @param \Closure(int[]): bool|null $page_matcher Exact WordPress page matcher.
	 */
	public function __construct(
		SettingsRepository $settings,
		?\Closure $page_matcher = null,
	) {
		$this->settings     = $settings;
		$this->page_matcher = $page_matcher ?? static fn( array $page_ids ): bool => is_page( $page_ids );
	}

	/** Whether CartPops may alter or render the customer-facing frontend. */
	public function is_enabled(): bool {
		return true === $this->settings->get( 'general.enabled', true );
	}

	/** Whether the current page permits a floating launcher. */
	public function floating_launcher_is_visible(): bool {
		$page_ids = $this->settings->get( 'launcher.hidden_page_ids', array() );
		if ( ! is_array( $page_ids ) || array() === $page_ids ) {
			return true;
		}

		return ! ( $this->page_matcher )( $page_ids );
	}

	/**
	 * Resolve one canonical trigger after the V1 filter.
	 *
	 * @return string A bounded trigger understood by the browser runtime.
	 */
	public function trigger(): string {
		$stored   = $this->settings->get( 'general.trigger', self::FALLBACK_TRIGGER );
		$filtered = apply_filters( 'cartpops_add_to_cart_trigger', $stored );

		if (
			! is_string( $filtered )
			|| strlen( $filtered ) > self::MAX_TRIGGER_LENGTH
		) {
			return self::FALLBACK_TRIGGER;
		}

		return SettingsRepository::normalize_trigger( $filtered );
	}

	/**
	 * Stop CartPops block rendering before dynamic callbacks and asset hooks run.
	 *
	 * @param mixed $pre_render   Existing short-circuit value.
	 * @param mixed $parsed_block Parsed block.
	 * @return mixed Existing value, or empty markup when CartPops is disabled.
	 */
	public function filter_pre_render_block( mixed $pre_render, mixed $parsed_block ): mixed {
		if ( ! is_array( $parsed_block ) ) {
			return $pre_render;
		}

		$block_name = $parsed_block['blockName'] ?? null;
		if ( ! $this->is_enabled() && in_array( $block_name, self::BLOCKS, true ) ) {
			return '';
		}

		if ( 'cartpops/cart-launcher' === $block_name ) {
			$attributes   = is_array( $parsed_block['attrs'] ?? null ) ? $parsed_block['attrs'] : array();
			$presentation = 'inline' === ( $attributes['presentation'] ?? null ) ? 'inline' : 'floating';
			if ( 'floating' === $presentation && ! $this->floating_launcher_is_visible() ) {
				return '';
			}
		}

		return $pre_render;
	}

	/**
	 * Whether the parsed name identifies customer-facing CartPops markup.
	 *
	 * @param mixed $block_name Parsed block name.
	 */
	public function is_frontend_block( mixed $block_name ): bool {
		return in_array( $block_name, self::BLOCKS, true );
	}
}
