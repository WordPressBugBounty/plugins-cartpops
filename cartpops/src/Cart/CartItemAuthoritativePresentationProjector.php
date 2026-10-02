<?php
/**
 * Closed cart-item authority collaborator.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Cart;

/**
 * Projects edition-owned fields without exposing another WordPress hook.
 */
final class CartItemAuthoritativePresentationProjector {
	/**
	 * Edition-owned presentation callback.
	 *
	 * @var \Closure(array<string, mixed>, array<string, mixed>, string): mixed
	 */
	private readonly \Closure $projector;

	/**
	 * Capture the edition-owned projector.
	 *
	 * @param callable $projector Projector callback.
	 * @phpstan-param callable(array<string, mixed>, array<string, mixed>, string): mixed $projector
	 */
	public function __construct( callable $projector ) {
		$this->projector = \Closure::fromCallable( $projector );
	}

	/**
	 * Project edition-owned presentation for one exact cart line.
	 *
	 * @param array<string, mixed> $item_data Sanitized public presentation.
	 * @param array<string, mixed> $cart_item Raw WooCommerce cart item.
	 * @param string               $cart_key  Exact cart item key.
	 * @return mixed
	 */
	public function project( array $item_data, array $cart_item, string $cart_key ): mixed {
		return ( $this->projector )( $item_data, $cart_item, $cart_key );
	}
}
