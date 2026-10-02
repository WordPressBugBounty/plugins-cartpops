<?php
/**
 * Resolves merchant-controlled cart totals visibility.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Blocks\CartDrawer;

/**
 * Immutable view policy for the shared Free and Pro cart drawer totals.
 */
final class DrawerTotalsVisibility {

	private const BREAKDOWN_LOCATIONS = array( 'body', 'footer', 'hidden' );

	/**
	 * Selected breakdown location.
	 *
	 * @var string
	 */
	private readonly string $breakdown_location;

	/**
	 * Whether to render the subtotal row.
	 *
	 * @var bool
	 */
	private readonly bool $show_subtotal;

	/**
	 * Whether to render the discount row.
	 *
	 * @var bool
	 */
	private readonly bool $show_discount;

	/**
	 * Whether to render the shipping row.
	 *
	 * @var bool
	 */
	private readonly bool $show_shipping;

	/**
	 * Whether to render tax details.
	 *
	 * @var bool
	 */
	private readonly bool $show_tax;

	/**
	 * Whether to render the grand-total row.
	 *
	 * @var bool
	 */
	private readonly bool $show_total;

	/**
	 * Build the policy from the sanitized drawer settings document.
	 *
	 * Missing values use the all-visible V2 defaults. Unexpected values fail
	 * closed instead of using PHP's non-empty-string boolean coercion.
	 *
	 * @param array<string, mixed> $drawer Drawer settings.
	 */
	public function __construct( array $drawer ) {
		$location                 = $drawer['totals_breakdown'] ?? 'footer';
		$this->breakdown_location = is_string( $location ) && in_array( $location, self::BREAKDOWN_LOCATIONS, true )
			? $location
			: 'footer';
		$this->show_subtotal      = true === ( $drawer['show_subtotal'] ?? true );
		$this->show_discount      = true === ( $drawer['show_discount'] ?? true );
		$this->show_shipping      = true === ( $drawer['show_shipping'] ?? true );
		$this->show_tax           = true === ( $drawer['show_tax'] ?? true );
		$this->show_total         = true === ( $drawer['show_total'] ?? true );
	}

	/**
	 * Whether the breakdown belongs at one exact supported location.
	 *
	 * @param string $location Candidate body or footer location.
	 */
	public function shows_breakdown_at( string $location ): bool {
		return in_array( $location, array( 'body', 'footer' ), true )
			&& $location === $this->breakdown_location;
	}

	/** Whether at least one merchant-controlled breakdown row is enabled. */
	public function shows_any_breakdown_row(): bool {
		return $this->show_subtotal
			|| $this->show_discount
			|| $this->show_shipping
			|| $this->show_tax;
	}

	/**
	 * Whether the footer needs its styled totals wrapper.
	 *
	 * A footer breakdown keeps the wrapper available for WooCommerce fee rows,
	 * even when every merchant-controlled breakdown row is disabled.
	 */
	public function shows_footer_totals(): bool {
		return $this->show_total || $this->shows_breakdown_at( 'footer' );
	}

	/**
	 * Whether a wrapper at one location has only dynamic WooCommerce fees.
	 *
	 * Fee-only wrappers remain in the DOM so later cart responses can add a
	 * genuine fee, but storefront markup hides them until that happens.
	 *
	 * @param string $location Candidate body or footer location.
	 */
	public function is_fee_only_at( string $location ): bool {
		if ( ! $this->shows_breakdown_at( $location ) || $this->shows_any_breakdown_row() ) {
			return false;
		}

		return 'body' === $location || ! $this->show_total;
	}

	/** Whether the subtotal row is enabled inside the selected breakdown. */
	public function shows_subtotal(): bool {
		return $this->show_subtotal;
	}

	/** Whether the discount row is enabled inside the selected breakdown. */
	public function shows_discount(): bool {
		return $this->show_discount;
	}

	/** Whether the shipping row is enabled inside the selected breakdown. */
	public function shows_shipping(): bool {
		return $this->show_shipping;
	}

	/** Whether tax presentation is enabled inside the selected breakdown. */
	public function shows_tax(): bool {
		return $this->show_tax;
	}

	/** Whether the independent grand-total row is enabled. */
	public function shows_total(): bool {
		return $this->show_total;
	}

	/**
	 * Whether an inclusive-tax note may accompany the grand total.
	 *
	 * WooCommerce still decides at runtime whether an applicable note exists.
	 */
	public function shows_tax_note(): bool {
		return $this->show_tax && $this->show_total;
	}
}
