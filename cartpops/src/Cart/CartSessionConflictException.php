<?php
/**
 * Retry-safe cart session exclusion failure.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Cart;

/** Indicates that another request currently owns the Woo cart session. */
final class CartSessionConflictException extends \RuntimeException {}
