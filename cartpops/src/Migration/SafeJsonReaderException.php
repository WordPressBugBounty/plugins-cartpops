<?php
/**
 * Internal value-free JSON parser failure.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Migration;

/**
 * Marks malformed or overbound JSON without carrying customer data.
 */
final class SafeJsonReaderException extends \RuntimeException {}
