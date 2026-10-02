<?php
/**
 * Cart Drawer server-rendered cart-line state.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Blocks\CartDrawer;

use CartPops\Cart\CartLineAuthority;

/** Keep initial PHP state aligned with the fail-closed client projection. */
final class CartLineStateProjector {
	private const MAX_SAFE_INTEGER = 9007199254740991;

	/**
	 * Project every financial row and expose only explicitly visible rows for rendering.
	 *
	 * @param mixed $items Candidate authoritative cart rows.
	 * @return array{cartItems: array<int, array<string, mixed>>, renderCartItems: array<int, array<string, mixed>>}
	 */
	public static function project( mixed $items ): array {
		if ( ! is_array( $items ) || ! array_is_list( $items ) ) {
			return array(
				'cartItems'       => array(),
				'renderCartItems' => array(),
			);
		}

		$cart_items        = array();
		$render_cart_items = array();
		foreach ( $items as $candidate ) {
			$item         = self::project_item( $candidate );
			$cart_items[] = $item;
			if ( true === $item['visible'] ) {
				$render_cart_items[] = $item;
			}
		}

		return array(
			'cartItems'       => $cart_items,
			'renderCartItems' => $render_cart_items,
		);
	}

	/**
	 * Materialize one row's exact authority and derived directive flags.
	 *
	 * @param mixed $candidate Candidate cart item.
	 * @return array<string, mixed>
	 */
	private static function project_item( mixed $candidate ): array {
		$item            = is_array( $candidate ) ? $candidate : array();
		$safe_quantity   = self::positive_safe_integer( $item['quantity'] ?? null );
		$quantity        = $safe_quantity ?? 1;
		$authority       = null !== $safe_quantity && CartLineAuthority::valid_key( $item['key'] ?? null )
			? CartLineAuthority::sanitize_projection( $item )
			: null;
		$optional_locked = $item['optionalLocked'] ?? null;

		if (
			null === $authority
			|| ! is_bool( $optional_locked )
			|| ( ! $authority['visible'] && ( $authority['quantityEditable'] || $authority['removable'] || '' !== $authority['backorderNotice'] ) )
			|| ( $optional_locked && ( $authority['quantityEditable'] || $authority['removable'] ) )
		) {
			$authority       = self::locked_authority( $quantity );
			$optional_locked = false;
		}

		$limits           = $authority['quantityLimits'];
		$quantity_aligned = $quantity >= $limits['minimum']
			&& $quantity <= $limits['maximum']
			&& 0 === $quantity % $limits['multipleOf'];
		$can_remove       = $authority['visible'] && $authority['removable'] && ! $optional_locked;
		$can_edit         = $authority['visible']
			&& $authority['quantityEditable']
			&& ! $optional_locked
			&& $quantity_aligned;
		$can_increment    = $can_edit
			&& $quantity <= self::MAX_SAFE_INTEGER - $limits['multipleOf']
			&& $quantity + $limits['multipleOf'] <= $limits['maximum'];
		$previous         = $quantity - $limits['multipleOf'];

		return array_merge(
			$item,
			$authority,
			array(
				'optionalLocked'       => $optional_locked,
				'showQuantityControls' => $can_edit,
				'showRemoveControl'    => $can_remove,
				'canIncrementQuantity' => $can_increment,
				'canDecrementQuantity' => $can_edit && ( $previous >= $limits['minimum'] || $can_remove ),
				'hasBackorderNotice'   => $authority['visible'] && '' !== $authority['backorderNotice'],
			)
		);
	}

	/**
	 * Return one positive integer representable exactly by PHP and JavaScript.
	 *
	 * @param mixed $candidate Candidate quantity.
	 */
	private static function positive_safe_integer( mixed $candidate ): ?int {
		return is_int( $candidate ) && $candidate > 0 && $candidate <= self::MAX_SAFE_INTEGER
			? $candidate
			: null;
	}

	/**
	 * Preserve a malformed row financially while making it invisible and immutable.
	 *
	 * @param int $quantity Safe current quantity.
	 * @return array{visible: false, quantityEditable: false, removable: false, quantityLimits: array{minimum: int, maximum: int, multipleOf: int, unlimited: false}, backorderNotice: string}
	 */
	private static function locked_authority( int $quantity ): array {
		return array(
			'visible'          => false,
			'quantityEditable' => false,
			'removable'        => false,
			'quantityLimits'   => array(
				'minimum'    => $quantity,
				'maximum'    => $quantity,
				'multipleOf' => 1,
				'unlimited'  => false,
			),
			'backorderNotice'  => '',
		);
	}
}
