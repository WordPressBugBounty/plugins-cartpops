<?php
/**
 * Closed HTML policy for internal Cart Drawer render slots.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Blocks\CartDrawer;

/** Preserve only markup needed by CartPops' trusted interactive slot renderers. */
final class DrawerRenderMarkupPolicy {
	/**
	 * Sanitize one internal slot fragment, failing closed outside WordPress.
	 *
	 * @param string $markup Candidate internal markup.
	 */
	public static function sanitize( string $markup ): string {
		return function_exists( 'wp_kses' )
			? wp_kses( $markup, self::allowed_html() )
			: '';
	}

	/**
	 * Context-specific elements and attributes used by approved internal renderers.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_html(): array {
		$global = array_fill_keys(
			array(
				'aria-atomic',
				'aria-busy',
				'aria-controls',
				'aria-describedby',
				'aria-expanded',
				'aria-hidden',
				'aria-label',
				'aria-live',
				'aria-modal',
				'aria-valuemax',
				'aria-valuemin',
				'aria-valuenow',
				'aria-valuetext',
				'class',
				'data-cartpops-dialog-initial-focus',
				'data-cpops-cart-mutation',
				'data-wp-bind--alt',
				'data-wp-bind--aria-busy',
				'data-wp-bind--aria-expanded',
				'data-wp-bind--aria-hidden',
				'data-wp-bind--aria-valuenow',
				'data-wp-bind--aria-valuetext',
				'data-wp-bind--checked',
				'data-wp-bind--data-attr-key',
				'data-wp-bind--data-icon',
				'data-wp-bind--disabled',
				'data-wp-bind--for',
				'data-wp-bind--hidden',
				'data-wp-bind--href',
				'data-wp-bind--inert',
				'data-wp-bind--name',
				'data-wp-bind--src',
				'data-wp-bind--value',
				'data-wp-class--cpops-addon--active',
				'data-wp-class--cpops-bundle-companion--selected',
				'data-wp-class--cpops-bundle-flyout--open',
				'data-wp-class--cpops-bundle-flyout-backdrop--visible',
				'data-wp-class--cpops-bundle-flyout__add-btn--loading',
				'data-wp-class--cpops-bundle-info--open',
				'data-wp-class--cpops-cart-item__bundle-trigger--loading',
				'data-wp-class--cpops-drawer-notif--dismissed',
				'data-wp-class--cpops-drawer-notif--urgent',
				'data-wp-class--cpops-drawer-notif__action--added',
				'data-wp-class--cpops-drawer-notif__action--loading',
				'data-wp-class--cpops-hidden',
				'data-wp-class--cpops-meter--celebrating',
				'data-wp-class--cpops-meter--qualified',
				'data-wp-class--cpops-meter__tier-marker--reached',
				'data-wp-class--cpops-shipping-calculator__rate--selected',
				'data-wp-class--cpops-shipping-calculator__status--error',
				'data-wp-each--addon',
				'data-wp-each--attr',
				'data-wp-each--companion',
				'data-wp-each--country',
				'data-wp-each--option',
				'data-wp-each--package',
				'data-wp-each--rate',
				'data-wp-each--region',
				'data-wp-each--tier',
				'data-wp-each-key',
				'data-wp-init',
				'data-wp-on--change',
				'data-wp-on--click',
				'data-wp-on--input',
				'data-wp-on--submit',
				'data-wp-style--left',
				'data-wp-style--width',
				'data-wp-text',
				'hidden',
				'id',
				'inert',
				'role',
				'style',
				'tabindex',
			),
			true
		);
		$svg    = array_merge(
			$global,
			array(
				'cx'              => true,
				'cy'              => true,
				'd'               => true,
				'fill'            => true,
				'focusable'       => true,
				'height'          => true,
				'points'          => true,
				'r'               => true,
				'rx'              => true,
				'stroke'          => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
				'stroke-width'    => true,
				'viewbox'         => true,
				'width'           => true,
				'x'               => true,
				'x1'              => true,
				'x2'              => true,
				'y'               => true,
				'y1'              => true,
				'y2'              => true,
			)
		);

		return array(
			'a'        => array_merge(
				$global,
				array(
					'href'   => true,
					'rel'    => true,
					'target' => true,
				)
			),
			'button'   => array_merge(
				$global,
				array(
					'disabled' => true,
					'name'     => true,
					'type'     => true,
					'value'    => true,
				)
			),
			'circle'   => $svg,
			'div'      => $global,
			'fieldset' => $global,
			'form'     => $global,
			'h3'       => $global,
			'h4'       => $global,
			'img'      => array_merge(
				$global,
				array(
					'alt'     => true,
					'height'  => true,
					'loading' => true,
					'src'     => true,
					'width'   => true,
				)
			),
			'input'    => array_merge(
				$global,
				array(
					'autocomplete' => true,
					'checked'      => true,
					'disabled'     => true,
					'max'          => true,
					'maxlength'    => true,
					'min'          => true,
					'name'         => true,
					'required'     => true,
					'step'         => true,
					'type'         => true,
					'value'        => true,
				)
			),
			'label'    => array_merge( $global, array( 'for' => true ) ),
			'legend'   => $global,
			'line'     => $svg,
			'option'   => array_merge(
				$global,
				array(
					'disabled' => true,
					'selected' => true,
					'value'    => true,
				)
			),
			'p'        => $global,
			'path'     => $svg,
			'polygon'  => $svg,
			'polyline' => $svg,
			'rect'     => $svg,
			'select'   => array_merge(
				$global,
				array(
					'autocomplete' => true,
					'disabled'     => true,
					'name'         => true,
					'required'     => true,
				)
			),
			'section'  => $global,
			'span'     => $global,
			'svg'      => $svg,
			'template' => $global,
		);
	}
}
